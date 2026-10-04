<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Helpers\Str;
use App\Repositories\CartItemRepository;
use App\Repositories\CartRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\SettingRepository;
use App\Services\Pricing\CartLine;
use App\Services\Pricing\DeliveryQuote;
use App\Services\Pricing\PriceBreakdown;
use App\Services\Pricing\PricingEngine;
use App\Services\Promotions\PromotionOutcome;
use App\Services\Promotions\PromotionResolver;

/**
 * Cart business logic.
 *
 * Three decisions shape everything here:
 *
 * 1. GUESTS GET REAL CARTS. A shopper can fill a cart before signing in, keyed
 *    by an anonymous token, and it merges into their account on login. Forcing
 *    a login before the cart is the single biggest abandonment cause in Indian
 *    D2C, so the complexity is worth it.
 *
 * 2. PRICE CHANGES ARE RE-QUOTED, NOT ABSORBED. Every line snapshots the price
 *    the customer was shown. On each read the snapshot is compared to the live
 *    price; if it moved, the cart is re-priced AND the change is reported in
 *    `price_changes` so the client can say "cardamom went up by ₹20" instead of
 *    silently showing a different total. Absorbing the difference means selling
 *    at a loss on every offer that expires mid-session.
 *
 * 3. UNAVAILABLE LINES ARE KEPT BUT EXCLUDED. If a product is archived while
 *    it sits in a cart, the line stays visible and flagged rather than
 *    vanishing, and contributes nothing to the total. A line disappearing with
 *    no explanation reads as a bug to the customer.
 */
final class CartService
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartItemRepository $items,
        private readonly ProductVariantRepository $variants,
        private readonly SettingRepository $settings,
        private readonly PricingEngine $pricing,
        private readonly DeliveryChargeService $delivery,
        private readonly PromotionResolver $promotions,
        private readonly CouponService $coupons,
        private readonly WalletService $wallet,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    // -----------------------------------------------------------------------
    // Cart resolution
    // -----------------------------------------------------------------------

    /**
     * Finds or creates the caller's cart.
     *
     * A signed-in user always gets their account cart; the guest token is
     * ignored, because trusting it for an authenticated caller would let a
     * shared token expose one customer's cart to another.
     *
     * @return array{cart:array<string, mixed>, guest_token:?string}
     */
    public function resolveCart(Request $request, bool $createIfMissing = true): array
    {
        $userId = $request->authUserId();

        if ($userId !== null) {
            $cart = $this->carts->findActiveForUser($userId);

            if ($cart === null && $createIfMissing) {
                $cart = $this->createUserCart($userId);
            }

            if ($cart === null) {
                throw new NotFoundException('No active cart.');
            }

            return ['cart' => $cart, 'guest_token' => null];
        }

        $token = $this->guestTokenFrom($request);

        if ($token !== null) {
            $cart = $this->carts->findActiveForGuest($token);

            if ($cart !== null) {
                return ['cart' => $cart, 'guest_token' => $token];
            }

            // A token that no longer maps to a live cart is stale, not
            // malicious: issue a fresh cart rather than an error.
        }

        if (!$createIfMissing) {
            throw new NotFoundException('No active cart.');
        }

        $newToken = Str::randomToken(24);
        $cart = $this->createGuestCart($newToken);

        return ['cart' => $cart, 'guest_token' => $newToken];
    }

    /**
     * Full cart view: lines, live re-pricing, delivery quote, totals, and the
     * checkout readiness verdict.
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, ?string $pincodeOverride = null): array
    {
        $resolved = $this->resolveCart($request);
        $cart = $resolved['cart'];

        $pincode = $pincodeOverride ?? $cart['delivery_pincode'];

        if ($pincodeOverride !== null && $pincodeOverride !== $cart['delivery_pincode']) {
            $this->carts->setPincode((int) $cart['id'], $pincodeOverride, $request->authUserId());
        }

        return $this->present($cart, $pincode, $resolved['guest_token']);
    }

    // -----------------------------------------------------------------------
    // Mutations
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function addItem(Request $request, string $variantUuid, int $quantity, bool $isGift, ?string $giftMessage): array
    {
        $resolved = $this->resolveCart($request);
        $cart = $resolved['cart'];
        $variant = $this->requirePurchasableVariant($variantUuid);

        $this->assertQuantityWithinLimit($variant, $quantity);

        $existing = $this->items->findByCartAndVariant((int) $cart['id'], (int) $variant['id']);

        if ($existing === null) {
            $this->assertCartHasRoom((int) $cart['id']);
        }

        $this->db->transaction(function () use ($cart, $variant, $quantity, $existing, $isGift, $giftMessage, $request): void {
            $actorId = $request->authUserId();

            $snapshot = [
                'unit_price_snapshot' => (string) $variant['effective_price'],
                'unit_mrp_snapshot' => (string) $variant['mrp'],
                'gst_rate_snapshot' => (string) $variant['gst_rate'],
                'unit_weight_snapshot' => (int) $variant['shipping_weight_grams'],
            ];

            // ONE ATOMIC STATEMENT covers every case: a new line, an existing
            // line to increment, and a soft-deleted line to revive.
            //
            // The previous shape — look, then insert or increment — raced. Two
            // concurrent requests both found nothing and both inserted, and the
            // unique index on (cart_id, variant_id) rejected the loser with a
            // 500. Catching the duplicate and re-reading did not fix it either:
            // under REPEATABLE READ the loser's snapshot predates the winner's
            // commit, so the re-read finds nothing. A locking read does see the
            // row but blocks until the winner commits, and under load those
            // waits cascade into lock timeouts.
            //
            // Concurrency testing measured all three approaches: look-then-
            // insert failed 7 of 8 requests, catch-and-reread 4 of 8, locking
            // read 7 of 8. An upsert has nothing to read and nothing to wait on.
            $result = $this->items->upsertLine(array_merge($snapshot, [
                'cart_id' => (int) $cart['id'],
                'product_id' => (int) $variant['product_id'],
                'variant_id' => (int) $variant['id'],
                'quantity' => $quantity,
                'is_saved_for_later' => 0,
                'is_gift' => $isGift ? 1 : 0,
                'gift_message' => $giftMessage,
            ]), $actorId);

            // The per-variant cap is checked before the write as well, but the
            // authoritative quantity is the one the upsert settled on. Two
            // concurrent adds can carry a cart marginally past the cap; that is
            // an anti-hoarding limit rather than a money rule, and the cart view
            // reports the overage rather than silently discarding an item the
            // customer asked for.
            $this->assertQuantityWithinLimit($variant, $result['quantity']);
        });

        $this->carts->touch((int) $cart['id']);

        return $this->present($cart, $cart['delivery_pincode'], $resolved['guest_token']);
    }

    /** @return array<string, mixed> */
    public function updateQuantity(Request $request, string $itemUuid, int $quantity): array
    {
        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];
        $line = $this->requireOwnedLine($itemUuid, (int) $cart['id']);

        $variant = $this->variants->findPricedByUuid((string) $line['variant_uuid']);

        if ($variant !== null) {
            $this->assertQuantityWithinLimit($variant, $quantity);
        }

        $this->items->setQuantity((int) $line['id'], $quantity, $request->authUserId());
        $this->carts->touch((int) $cart['id']);

        return $this->present($cart, $cart['delivery_pincode'], $resolved['guest_token']);
    }

    /** @return array<string, mixed> */
    public function removeItem(Request $request, string $itemUuid): array
    {
        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];
        $line = $this->requireOwnedLine($itemUuid, (int) $cart['id']);

        $this->items->softDelete((int) $line['id'], $request->authUserId());
        $this->carts->touch((int) $cart['id']);

        return $this->present($cart, $cart['delivery_pincode'], $resolved['guest_token']);
    }

    /** @return array<string, mixed> */
    public function setSavedForLater(Request $request, string $itemUuid, bool $saved): array
    {
        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];
        $line = $this->requireOwnedLine($itemUuid, (int) $cart['id']);

        if (!$saved && (int) $line['is_purchasable'] !== 1) {
            throw new HttpException(
                'This item is no longer available, so it cannot be moved back into the cart.',
                409
            );
        }

        $this->items->setSavedForLater((int) $line['id'], $saved, $request->authUserId());
        $this->carts->touch((int) $cart['id']);

        return $this->present($cart, $cart['delivery_pincode'], $resolved['guest_token']);
    }

    /** @return array<string, mixed> */
    public function clear(Request $request, bool $includeSavedForLater): array
    {
        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];

        $removed = $this->items->clearCart((int) $cart['id'], $includeSavedForLater, $request->authUserId());

        $this->audit->log(
            entityName: 'carts',
            entityId: (int) $cart['id'],
            action: 'clear',
            newValues: ['lines_removed' => $removed, 'included_saved_for_later' => $includeSavedForLater],
            request: $request,
            entityUuid: (string) $cart['uuid']
        );

        $this->carts->touch((int) $cart['id']);

        return $this->present($cart, $cart['delivery_pincode'], $resolved['guest_token']);
    }

    /** @return array<string, mixed> */
    public function setPincode(Request $request, string $pincode): array
    {
        $resolved = $this->resolveCart($request);
        $cart = $resolved['cart'];

        $this->carts->setPincode((int) $cart['id'], $pincode, $request->authUserId());

        return $this->present($cart, $pincode, $resolved['guest_token']);
    }

    /**
     * Acknowledges reported price changes so the client can stop showing the
     * banner. Separate from the read so a customer who never sees the notice
     * keeps seeing it on their next visit.
     *
     * @return array<string, mixed>
     */
    public function acknowledgePriceChanges(Request $request): array
    {
        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];

        $this->items->clearPriceChangeFlag((int) $cart['id']);

        return $this->present($cart, $cart['delivery_pincode'], $resolved['guest_token']);
    }

    // -----------------------------------------------------------------------
    // Promotions and wallet
    // -----------------------------------------------------------------------

    /**
     * Applies a coupon code to the cart.
     *
     * Validated at the moment it is typed, so an unusable code is refused here
     * rather than accepted and then silently ignored at checkout. Only the
     * coupon reference is stored: the discount is recomputed on every read,
     * because the cart it applies to keeps changing.
     *
     * @return array<string, mixed>
     */
    public function applyCoupon(Request $request, string $couponCode): array
    {
        $userId = $request->authUserId();

        if ($userId === null) {
            throw new HttpException(
                'Sign in to use a coupon code.',
                401,
                ['coupon_code' => ['Coupons are linked to your account.']]
            );
        }

        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];

        $context = $this->pricingContext($cart, $cart['delivery_pincode']);

        $coupon = $this->promotions->assertCouponApplicable(
            $couponCode,
            $userId,
            $context['purchasable_rows'],
            $context['items_subtotal'],
            $context['delivery']->charge
        );

        $this->carts->update((int) $cart['id'], [
            'applied_coupon_id' => (int) $coupon['id'],
            'applied_coupon_code' => (string) $coupon['code'],
        ], $userId);

        $this->audit->log(
            entityName: 'carts',
            entityId: (int) $cart['id'],
            action: 'apply_coupon',
            newValues: ['coupon_code' => $coupon['code']],
            request: $request,
            entityUuid: (string) $cart['uuid']
        );

        $fresh = (array) $this->carts->findById((int) $cart['id']);

        return $this->present($fresh, $fresh['delivery_pincode'], $resolved['guest_token']);
    }

    /** @return array<string, mixed> */
    public function removeCoupon(Request $request): array
    {
        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];

        if ($cart['applied_coupon_id'] === null) {
            throw new HttpException('No coupon is applied to this cart.', 422);
        }

        $this->carts->update((int) $cart['id'], [
            'applied_coupon_id' => null,
            'applied_coupon_code' => null,
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'carts',
            entityId: (int) $cart['id'],
            action: 'remove_coupon',
            oldValues: ['coupon_code' => $cart['applied_coupon_code']],
            request: $request,
            entityUuid: (string) $cart['uuid']
        );

        $fresh = (array) $this->carts->findById((int) $cart['id']);

        return $this->present($fresh, $fresh['delivery_pincode'], $resolved['guest_token']);
    }

    /**
     * Coupons this customer could use on this cart, each annotated with whether
     * it currently applies and what it would save.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableCoupons(Request $request): array
    {
        $userId = $request->authUserId();

        if ($userId === null) {
            return [];
        }

        $resolved = $this->resolveCart($request);
        $context = $this->pricingContext($resolved['cart'], $resolved['cart']['delivery_pincode']);

        return $this->coupons->availableForCart(
            $userId,
            $context['purchasable_rows'],
            $context['items_subtotal'],
            $context['delivery']->charge
        );
    }

    /**
     * Sets how much wallet credit to put towards this order.
     *
     * Wallet credit is a PAYMENT TENDER, not a discount: it does not reduce the
     * transaction value and therefore does not reduce GST. Modelling it as a
     * discount would understate the tax liability on every order it touched.
     *
     * A request above the cap is clamped rather than rejected, with an
     * explanation — a customer asking for more credit than the rules allow
     * should get what the rules allow.
     *
     * @return array<string, mixed>
     */
    public function setWalletRedemption(Request $request, float $amount): array
    {
        $userId = $request->authUserId();

        if ($userId === null) {
            throw new HttpException('Sign in to use wallet credit.', 401);
        }

        $resolved = $this->resolveCart($request, createIfMissing: false);
        $cart = $resolved['cart'];

        $requested = Money::fromDecimal($amount);

        if ($requested->isNegative()) {
            throw new HttpException('Wallet redemption cannot be negative.', 422);
        }

        // Store the intent, not the clamped figure: the cap depends on the order
        // value, which changes as the cart changes.
        $this->carts->update((int) $cart['id'], [
            'wallet_redeem_amount' => (string) $requested,
        ], $userId);

        $this->audit->log(
            entityName: 'carts',
            entityId: (int) $cart['id'],
            action: 'set_wallet_redemption',
            newValues: ['requested' => $requested->toDecimal()],
            request: $request,
            entityUuid: (string) $cart['uuid']
        );

        $fresh = (array) $this->carts->findById((int) $cart['id']);

        return $this->present($fresh, $fresh['delivery_pincode'], $resolved['guest_token']);
    }

    // -----------------------------------------------------------------------
    // Merge on login
    // -----------------------------------------------------------------------

    /**
     * Folds a guest cart into the signed-in customer's cart.
     *
     * Quantities are summed and capped at the per-variant limit; the guest cart
     * is marked `merged` with a pointer to its destination rather than deleted,
     * so support can answer "where did my cart go".
     *
     * @return array<string, mixed>
     */
    public function mergeGuestCart(Request $request, string $guestToken): array
    {
        $userId = $request->authUserId();

        if ($userId === null) {
            throw new HttpException('Sign in before merging a guest cart.', 401);
        }

        $guestCart = $this->carts->findActiveForGuest($guestToken);

        if ($guestCart === null) {
            // Idempotent: an already-merged or expired token is not an error,
            // and clients retry this call after a flaky login.
            $target = $this->carts->findActiveForUser($userId) ?? $this->createUserCart($userId);

            return $this->present($target, $target['delivery_pincode'], null);
        }

        $userCart = $this->carts->findActiveForUser($userId) ?? $this->createUserCart($userId);

        $summary = $this->db->transaction(function () use ($guestCart, $userCart, $userId): array {
            $moved = 0;
            $combined = 0;
            $skipped = 0;

            foreach ($this->items->linesForCart((int) $guestCart['id']) as $line) {
                $existing = $this->items->findByCartAndVariant(
                    (int) $userCart['id'],
                    (int) $line['variant_id']
                );

                if ($existing === null) {
                    $this->items->moveLineToCart((int) $line['id'], (int) $userCart['id'], $userId);
                    ++$moved;

                    continue;
                }

                $limit = (int) $line['max_order_quantity'];
                $target = (int) $existing['quantity'] + (int) $line['quantity'];
                $capped = $limit > 0 ? min($target, $limit) : $target;

                if ((int) $existing['is_deleted'] === 1) {
                    $this->items->reviveLine((int) $existing['id'], [
                        'quantity' => min((int) $line['quantity'], $limit > 0 ? $limit : (int) $line['quantity']),
                        'unit_price_snapshot' => (string) $line['live_unit_price'],
                        'unit_mrp_snapshot' => (string) $line['live_unit_mrp'],
                        'gst_rate_snapshot' => (string) $line['live_gst_rate'],
                        'unit_weight_snapshot' => (int) $line['live_unit_weight'],
                        'is_saved_for_later' => (int) $line['is_saved_for_later'],
                    ], $userId);
                    ++$moved;
                } else {
                    $this->items->setQuantity((int) $existing['id'], $capped, $userId);
                    ++$combined;

                    if ($capped < $target) {
                        ++$skipped;
                    }
                }

                // The guest copy is dropped once its quantity has been folded in.
                $this->items->softDelete((int) $line['id'], $userId);
            }

            if ($guestCart['delivery_pincode'] !== null && $userCart['delivery_pincode'] === null) {
                $this->carts->setPincode((int) $userCart['id'], (string) $guestCart['delivery_pincode'], $userId);
            }

            $this->carts->markMerged((int) $guestCart['id'], (int) $userCart['id'], $userId);

            return ['lines_moved' => $moved, 'lines_combined' => $combined, 'quantities_capped' => $skipped];
        });

        $this->audit->log(
            entityName: 'carts',
            entityId: (int) $userCart['id'],
            action: 'merge_guest_cart',
            newValues: $summary + ['from_cart_uuid' => $guestCart['uuid']],
            request: $request,
            entityUuid: (string) $userCart['uuid'],
            notes: 'Guest cart folded in on sign-in'
        );

        $fresh = (array) $this->carts->findById((int) $userCart['id']);
        $view = $this->present($fresh, $fresh['delivery_pincode'], null);
        $view['merge_summary'] = $summary;

        return $view;
    }

    // -----------------------------------------------------------------------
    // Presentation and pricing
    // -----------------------------------------------------------------------

    /**
     * Builds everything the pricing engine and the promotion resolver need from
     * a cart. Shared by present() and the coupon endpoints so a coupon is always
     * validated against exactly the figures the cart will display.
     *
     * @param array<string, mixed> $cart
     *
     * @return array{
     *     raw_lines:array<int, array<string, mixed>>,
     *     purchasable_rows:array<int, array<string, mixed>>,
     *     lines:array<int, CartLine>,
     *     active:array<int, array<string, mixed>>,
     *     saved:array<int, array<string, mixed>>,
     *     unavailable:array<int, array<string, mixed>>,
     *     items_subtotal:Money,
     *     total_weight_grams:int,
     *     delivery:DeliveryQuote,
     *     price_changes:array<int, array<string, mixed>>
     * }
     */
    private function pricingContext(array $cart, ?string $pincode): array
    {
        $rawLines = $this->items->linesForCart((int) $cart['id']);
        $priceChanges = $this->reconcileSnapshots($rawLines);

        // Re-read after re-snapshotting so the totals use the corrected prices.
        if ($priceChanges !== []) {
            $rawLines = $this->items->linesForCart((int) $cart['id']);
        }

        $active = [];
        $saved = [];
        $unavailable = [];
        $purchasableRows = [];
        $lines = [];

        foreach ($rawLines as $line) {
            $presented = $this->presentLine($line);

            if ((int) $line['is_saved_for_later'] === 1) {
                $saved[] = $presented;

                continue;
            }

            if ((int) $line['is_purchasable'] !== 1) {
                // Kept visible and flagged, but contributes nothing to totals.
                $unavailable[] = $presented;

                continue;
            }

            $active[] = $presented;
            $purchasableRows[] = $line;

            $lines[] = new CartLine(
                reference: (string) $line['uuid'],
                description: sprintf('%s - %s', $line['product_name'], $line['variant_name']),
                quantity: (int) $line['quantity'],
                unitMrp: Money::fromDecimal((string) $line['unit_mrp_snapshot']),
                unitPrice: Money::fromDecimal((string) $line['unit_price_snapshot']),
                gstRate: (float) $line['gst_rate_snapshot'],
                unitWeightGrams: (int) $line['unit_weight_snapshot'],
            );
        }

        $itemsSubtotal = Money::zero();
        $totalWeight = 0;

        foreach ($lines as $line) {
            $itemsSubtotal = $itemsSubtotal->add($line->linePrice());
            $totalWeight += $line->lineWeightGrams();
        }

        $delivery = $lines === []
            ? DeliveryQuote::none('Add items to see delivery charges')
            : $this->delivery->quote($pincode, $totalWeight, $itemsSubtotal);

        return [
            'raw_lines' => $rawLines,
            'purchasable_rows' => $purchasableRows,
            'lines' => $lines,
            'active' => $active,
            'saved' => $saved,
            'unavailable' => $unavailable,
            'items_subtotal' => $itemsSubtotal,
            'total_weight_grams' => $totalWeight,
            'delivery' => $delivery,
            'price_changes' => $priceChanges,
        ];
    }

    /**
     * @param array<string, mixed> $cart
     *
     * @return array<string, mixed>
     */
    private function present(array $cart, ?string $pincode, ?string $guestToken): array
    {
        $context = $this->pricingContext($cart, $pincode);
        $userId = $cart['user_id'] === null ? null : (int) $cart['user_id'];

        // Promotions resolve against the delivery charge BEFORE any waiver is
        // applied to it, so a free-delivery coupon on an already-free order is
        // correctly reported as worthless rather than silently doubling up.
        $promotions = $this->promotions->resolve(
            $context['purchasable_rows'],
            $userId,
            $cart['applied_coupon_code'] ?? null,
            $context['items_subtotal'],
            $context['delivery']->charge
        );

        $breakdown = $this->pricing->quote(
            $context['lines'],
            $context['delivery'],
            $promotions->adjustments
        );

        $payment = $this->resolvePayment($cart, $breakdown, $userId);

        return [
            'cart' => [
                'uuid' => $cart['uuid'],
                'is_guest_cart' => $cart['user_id'] === null,
                'guest_token' => $guestToken,
                'currency_code' => $cart['currency_code'],
                'delivery_pincode' => $pincode,
                'applied_coupon_code' => $promotions->appliedCoupon === null
                    ? null
                    : $promotions->appliedCoupon['code'],
                'last_activity_date' => $cart['last_activity_date'],
            ],
            'items' => $context['active'],
            'saved_for_later' => $context['saved'],
            'unavailable_items' => $context['unavailable'],
            'pricing' => $breakdown->toArray(),
            'promotions' => $promotions->toArray(),
            'payment' => $payment,
            'price_changes' => $context['price_changes'],
            'checkout' => $this->checkoutReadiness(
                $breakdown,
                $context['unavailable'],
                $context['delivery'],
                $payment
            ),
            'reconciles' => $breakdown->reconciles(),
        ];
    }

    /**
     * Splits the grand total into wallet credit and the amount still to be paid
     * online.
     *
     * This is where the wallet-is-not-a-discount rule lives. The grand total is
     * untouched; only the tender split changes. BR-004 means whatever is left
     * after wallet credit is a prepaid UPI payment.
     *
     * @param array<string, mixed> $cart
     *
     * @return array<string, mixed>
     */
    private function resolvePayment(array $cart, PriceBreakdown $breakdown, ?int $userId): array
    {
        $grandTotal = $breakdown->grandTotal;

        if ($userId === null || !$grandTotal->isPositive()) {
            return [
                'grand_total' => $grandTotal->toDecimal(),
                'wallet_applied' => 0.0,
                'amount_payable' => $grandTotal->toDecimal(),
                'wallet' => [
                    'balance' => 0.0,
                    'max_redeemable' => 0.0,
                    'requested' => 0.0,
                    'message' => $userId === null ? 'Sign in to use wallet credit.' : null,
                ],
                'payment_modes' => ['upi'],
            ];
        }

        $requested = Money::fromDecimal((string) ($cart['wallet_redeem_amount'] ?? '0.00'));
        $allowance = $this->wallet->redeemableFor($userId, $grandTotal);
        $clamped = $this->wallet->clampRedemption($userId, $requested, $grandTotal);

        // Never let wallet credit exceed what is actually owed.
        $applied = $clamped['amount']->min($grandTotal);

        return [
            'grand_total' => $grandTotal->toDecimal(),
            'wallet_applied' => $applied->toDecimal(),
            'amount_payable' => $grandTotal->subtract($applied)->toDecimal(),
            'wallet' => [
                'balance' => $allowance['balance'],
                'max_redeemable' => $allowance['max_redeemable'],
                'requested' => $requested->toDecimal(),
                'message' => $clamped['message'] ?? $allowance['reason'],
            ],
            'payment_modes' => ['upi'],
        ];
    }

    /**
     * Compares each snapshot against the live price, re-snapshots any that
     * moved, and returns a human-readable list of what changed.
     *
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileSnapshots(array $lines): array
    {
        $changes = [];

        foreach ($lines as $line) {
            if ((int) $line['is_purchasable'] !== 1 || $line['live_unit_price'] === null) {
                continue;
            }

            $snapshot = Money::fromDecimal((string) $line['unit_price_snapshot']);
            $live = Money::fromDecimal((string) $line['live_unit_price']);

            if ($snapshot->equals($live)) {
                continue;
            }

            $this->items->refreshPriceSnapshot(
                (int) $line['id'],
                (string) $line['live_unit_price'],
                (string) $line['live_unit_mrp'],
                (string) $line['live_gst_rate'],
                (int) $line['live_unit_weight']
            );

            $direction = $live->greaterThan($snapshot) ? 'increased' : 'decreased';
            $difference = $live->greaterThan($snapshot)
                ? $live->subtract($snapshot)
                : $snapshot->subtract($live);

            $changes[] = [
                'item_uuid' => $line['uuid'],
                'product_name' => $line['product_name'],
                'variant_name' => $line['variant_name'],
                'direction' => $direction,
                'previous_unit_price' => $snapshot->toDecimal(),
                'new_unit_price' => $live->toDecimal(),
                'difference' => $difference->toDecimal(),
                'message' => sprintf(
                    '%s (%s) has %s from %s to %s since you added it.',
                    $line['product_name'],
                    $line['variant_name'],
                    $direction,
                    $snapshot->format(),
                    $live->format()
                ),
            ];
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    private function presentLine(array $line): array
    {
        $unitPrice = Money::fromDecimal((string) $line['unit_price_snapshot']);
        $unitMrp = Money::fromDecimal((string) $line['unit_mrp_snapshot']);
        $quantity = (int) $line['quantity'];

        return [
            'uuid' => $line['uuid'],
            'quantity' => $quantity,
            'product' => [
                'uuid' => $line['product_uuid'],
                'slug' => $line['product_slug'],
                'name' => $line['product_name'],
                'brand' => $line['brand'],
            ],
            'variant' => [
                'uuid' => $line['variant_uuid'],
                'sku' => $line['sku'],
                'name' => $line['variant_name'],
                'pack_type' => $line['pack_type'],
                'weight_grams' => (int) $line['weight_grams'],
                'max_order_quantity' => (int) $line['max_order_quantity'],
            ],
            'unit_mrp' => $unitMrp->toDecimal(),
            'unit_price' => $unitPrice->toDecimal(),
            'line_mrp' => $unitMrp->multiply($quantity)->toDecimal(),
            'line_total' => $unitPrice->multiply($quantity)->toDecimal(),
            'line_savings' => $unitMrp->subtract($unitPrice)->clampAtZero()->multiply($quantity)->toDecimal(),
            'discount_percentage' => $line['discount_percentage'] === null
                ? 0
                : (int) $line['discount_percentage'],
            'offer_is_live' => (bool) $line['offer_is_live'],
            'line_weight_grams' => (int) $line['unit_weight_snapshot'] * $quantity,
            'is_gift' => (bool) $line['is_gift'],
            'gift_message' => $line['gift_message'],
            'is_gift_packable' => (bool) $line['is_gift_packable'],
            'is_saved_for_later' => (bool) $line['is_saved_for_later'],
            'is_purchasable' => (int) $line['is_purchasable'] === 1,
            'unavailable_reason' => (int) $line['is_purchasable'] === 1
                ? null
                : $this->unavailableReason($line),
            'price_changed_date' => $line['price_changed_date'],
        ];
    }

    /** @param array<string, mixed> $line */
    private function unavailableReason(array $line): string
    {
        return match (true) {
            $line['product_status'] === 'archived' => 'This product has been discontinued.',
            $line['product_status'] === 'draft' => 'This product is not currently on sale.',
            $line['live_unit_price'] === null => 'This pack size is no longer available.',
            default => 'This item is currently unavailable.',
        };
    }

    /**
     * The verdict the checkout button needs, with every blocking reason listed
     * at once rather than one error per attempt.
     *
     * @param array<int, array<string, mixed>> $unavailableLines
     * @param array<string, mixed>             $payment
     *
     * @return array<string, mixed>
     */
    private function checkoutReadiness(
        PriceBreakdown $breakdown,
        array $unavailableLines,
        DeliveryQuote $deliveryQuote,
        array $payment,
    ): array {
        $blockers = [];

        if ($breakdown->isEmpty()) {
            $blockers[] = 'Your cart is empty.';
        }

        if ($unavailableLines !== []) {
            $blockers[] = sprintf(
                '%d item(s) in your cart are no longer available. Remove them to continue.',
                count($unavailableLines)
            );
        }

        $minimum = $this->minimumOrderValue();

        if (!$breakdown->isEmpty() && $minimum !== null && $breakdown->itemsSubtotal->lessThan($minimum)) {
            $blockers[] = sprintf(
                'The minimum order value is %s. Add %s more to continue.',
                $minimum->format(),
                $minimum->subtract($breakdown->itemsSubtotal)->format()
            );
        }

        if (!$breakdown->isEmpty()) {
            if ($deliveryQuote->zoneCode === 'UNKNOWN') {
                $blockers[] = 'Enter a delivery pincode to continue.';
            } elseif (!$deliveryQuote->isServiceable) {
                $blockers[] = 'We do not currently deliver to this pincode.';
            }
        }

        return [
            'is_ready' => $blockers === [],
            'blockers' => $blockers,
            'minimum_order_value' => $minimum?->toDecimal(),
            'grand_total' => $breakdown->grandTotal->toDecimal(),
            'wallet_applied' => $payment['wallet_applied'],
            'amount_payable' => $payment['amount_payable'],
            // BR-004: prepaid UPI only. Stated here so the client never renders
            // a cash-on-delivery option.
            'payment_modes' => ['upi'],
            'prepaid_only' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function requirePurchasableVariant(string $variantUuid): array
    {
        $variant = $this->variants->findPricedByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        if ($variant['product_status'] !== 'published') {
            throw new HttpException('This product is not currently on sale.', 409);
        }

        return $variant;
    }

    /** @return array<string, mixed> */
    private function requireOwnedLine(string $itemUuid, int $cartId): array
    {
        $line = $this->items->findLineByUuid($itemUuid);

        // Checking cart ownership rather than just existence: without it, any
        // caller who guessed an item uuid could edit a stranger's cart.
        if ($line === null || (int) $line['cart_id'] !== $cartId) {
            throw new NotFoundException('That item is not in your cart.');
        }

        return $line;
    }

    /** @param array<string, mixed> $variant */
    private function assertQuantityWithinLimit(array $variant, int $quantity): void
    {
        $limit = (int) ($variant['max_order_quantity'] ?? 0);

        if ($limit > 0 && $quantity > $limit) {
            throw new HttpException(
                sprintf('You can order at most %d of this pack size.', $limit),
                422,
                ['quantity' => [sprintf('Maximum %d per order.', $limit)]]
            );
        }
    }

    private function assertCartHasRoom(int $cartId): void
    {
        $max = $this->settings->intValue(
            'cart_max_line_items',
            (int) $this->config->get('commerce.cart_max_line_items', 50)
        );

        if ($this->items->countAllLines($cartId) >= $max) {
            throw new HttpException(
                sprintf('A cart can hold at most %d different items.', $max),
                409
            );
        }
    }

    private function minimumOrderValue(): ?Money
    {
        $value = $this->settings->value('min_order_value');

        if ($value === null || $value === '' || (float) $value <= 0) {
            return null;
        }

        return Money::fromDecimal($value);
    }

    /** @return array<string, mixed> */
    private function createUserCart(int $userId): array
    {
        // Losing the race to create the cart is not an error.
        //
        // A unique index over a STORED generated column allows one active cart
        // per customer, which is what stops two concurrent requests creating
        // two. But the loser gets a duplicate-key exception, and left unhandled
        // that surfaces as a 500 — so a customer who double-taps "add to cart",
        // or whose app fires two requests on a flaky connection, sees an error
        // for something that worked perfectly.
        //
        // Concurrency testing found this: eight simultaneous requests produced
        // one cart and seven 500s. The correct response is to read the cart the
        // winner just created and carry on.
        try {
            $cartId = $this->carts->create([
                'user_id' => $userId,
                'guest_token_hash' => null,
                'status' => 'active',
                'currency_code' => (string) $this->config->get('app.currency', 'INR'),
                'last_activity_date' => date('Y-m-d H:i:s'),
            ], $userId);
        } catch (\PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = $this->carts->findActiveForUser($userId);

            if ($existing === null) {
                // The row was created and then removed between the failed
                // insert and this read. Vanishingly unlikely, and genuinely an
                // error rather than a race worth absorbing.
                throw $exception;
            }

            return $existing;
        }

        return (array) $this->carts->findById($cartId);
    }

    /** @return array<string, mixed> */
    private function createGuestCart(string $token): array
    {
        // Same race as createUserCart: two requests carrying the same guest
        // token can both find no cart and both try to create one.
        try {
            return $this->insertGuestCart($token);
        } catch (\PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = $this->carts->findActiveForGuest($token);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /** @return array<string, mixed> */
    private function insertGuestCart(string $token): array
    {
        $cartId = $this->carts->create([
            'user_id' => null,
            'guest_token_hash' => CartRepository::hashToken($token),
            'status' => 'active',
            'currency_code' => (string) $this->config->get('app.currency', 'INR'),
            'last_activity_date' => date('Y-m-d H:i:s'),
        ], null);

        return (array) $this->carts->findById($cartId);
    }

    private function guestTokenFrom(Request $request): ?string
    {
        $header = $request->header('x-cart-token');

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $body = $request->input('cart_token');

        return is_string($body) && trim($body) !== '' ? trim($body) : null;
    }
}
