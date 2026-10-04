<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\CategoryRepository;
use App\Repositories\CouponRedemptionRepository;
use App\Repositories\CouponRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Services\Pricing\PriceAdjustment;
use App\Services\Promotions\DiscountCalculator;

/**
 * Coupon eligibility and redemption.
 *
 * The division of labour is deliberate: this class decides *whether* a coupon
 * applies and to which lines; DiscountCalculator decides *how much* comes off;
 * PricingEngine decides how that interacts with tax. None of the three knows
 * the others' rules, which is why each stays readable.
 *
 * Every rejection returns a specific, quotable reason. "Invalid coupon" is the
 * single most infuriating message in Indian e-commerce, and it is also a support
 * ticket that costs more than the discount.
 */
final class CouponService
{
    public function __construct
    (
        private readonly CouponRepository $coupons,
        private readonly CouponRedemptionRepository $redemptions,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /**
     * Validates a coupon against a specific cart.
     *
     * @param array<int, array<string, mixed>> $cartLines Rows from vw_cart_lines
     *
     * @return array{coupon:array<string, mixed>, adjustment:PriceAdjustment, eligible_subtotal:Money}
     *
     * @throws HttpException with a reason the client can show verbatim
     */
    public function validateForCart(
        string $code,
        int $userId,
        array $cartLines,
        Money $itemsSubtotal,
        Money $deliveryCharge,
    ): array {
        $coupon = $this->coupons->findByCode($code);

        if ($coupon === null) {
            throw new HttpException(
                sprintf('The code %s does not exist.', strtoupper(trim($code))),
                404,
                ['coupon_code' => ['Check the spelling and try again.']]
            );
        }

        $this->assertUsable($coupon, $userId);

        $eligibleSubtotal = $this->eligibleSubtotal($coupon, $cartLines);

        if (!$eligibleSubtotal->isPositive()) {
            throw new HttpException(
                $this->scopeMessage($coupon),
                422,
                ['coupon_code' => ['No items in your cart qualify for this code.']]
            );
        }

        // The minimum is tested against the whole cart, not the eligible slice:
        // "spend ₹499 to get ₹50 off spices" is the customer's reading of it.
        $minimum = $coupon['min_order_value'] === null
            ? null
            : Money::fromDecimal((string) $coupon['min_order_value']);

        if ($minimum !== null && $itemsSubtotal->lessThan($minimum)) {
            throw new HttpException(
                sprintf(
                    'This code needs a minimum order of %s. Add %s more to use it.',
                    $minimum->format(),
                    $minimum->subtract($itemsSubtotal)->format()
                ),
                422,
                ['coupon_code' => ['Minimum order value not reached.']]
            );
        }

        if ($coupon['discount_type'] === 'free_delivery' && !$deliveryCharge->isPositive()) {
            throw new HttpException(
                'Delivery is already free on this order, so this code would not save you anything.',
                422,
                ['coupon_code' => ['No delivery charge to waive.']]
            );
        }

        $computed = DiscountCalculator::compute(
            (string) $coupon['discount_type'],
            (float) $coupon['discount_value'],
            $coupon['max_discount_amount'] === null
                ? null
                : Money::fromDecimal((string) $coupon['max_discount_amount']),
            $eligibleSubtotal,
            $deliveryCharge
        );

        if (!$computed['amount']->isPositive()) {
            throw new HttpException(
                'This code would not reduce your total.',
                422,
                ['coupon_code' => ['No discount applies to this cart.']]
            );
        }

        return [
            'coupon' => $coupon,
            'eligible_subtotal' => $eligibleSubtotal,
            'adjustment' => new PriceAdjustment(
                code: (string) $coupon['code'],
                label: (string) $coupon['title'],
                amount: $computed['amount'],
                type: PriceAdjustment::TYPE_DISCOUNT,
                scope: $computed['scope'],
            ),
        ];
    }

    /**
     * Coupons this customer could use, each annotated with whether it currently
     * applies to their cart and what it would save.
     *
     * Showing a coupon the customer cannot yet use, with the reason, converts
     * better than hiding it: "add ₹120 more for ₹50 off" is a nudge.
     *
     * @param array<int, array<string, mixed>> $cartLines
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableForCart(
        int $userId,
        array $cartLines,
        Money $itemsSubtotal,
        Money $deliveryCharge,
    ): array {
        $isNewCustomer = !$this->hasCompletedOrder($userId);
        $available = [];

        foreach ($this->coupons->availableForUser($userId, $isNewCustomer) as $coupon) {
            $entry = [
                'code' => $coupon['code'],
                'title' => $coupon['title'],
                'description' => $coupon['description'],
                'terms' => $coupon['terms'],
                'summary' => DiscountCalculator::describe(
                    (string) $coupon['discount_type'],
                    (float) $coupon['discount_value'],
                    $coupon['max_discount_amount'] === null
                        ? null
                        : Money::fromDecimal((string) $coupon['max_discount_amount'])
                ),
                'min_order_value' => $coupon['min_order_value'] === null
                    ? null
                    : (float) $coupon['min_order_value'],
                'valid_to' => $coupon['valid_to'],
                'is_applicable' => false,
                'estimated_saving' => 0.0,
                'reason' => null,
            ];

            try {
                $result = $this->validateForCart(
                    (string) $coupon['code'],
                    $userId,
                    $cartLines,
                    $itemsSubtotal,
                    $deliveryCharge
                );

                $entry['is_applicable'] = true;
                $entry['estimated_saving'] = $result['adjustment']->amount->toDecimal();
            } catch (HttpException $exception) {
                $entry['reason'] = $exception->getMessage();
            }

            $available[] = $entry;
        }

        // Applicable first, then by what they would actually save.
        usort($available, static function (array $left, array $right): int {
            if ($left['is_applicable'] !== $right['is_applicable']) {
                return $left['is_applicable'] ? -1 : 1;
            }

            return $right['estimated_saving'] <=> $left['estimated_saving'];
        });

        return $available;
    }

    /**
     * Consumes one use of a coupon. Called at order placement (Phase 5), never
     * when a code is merely typed into a cart — otherwise an abandoned cart
     * would burn a limited-use coupon.
     *
     * @return array<string, mixed> The redemption row
     */
    public function redeem(
        int $couponId,
        int $userId,
        string $orderReference,
        Money $discountAmount,
        Money $orderValue,
        ?int $cartId = null,
        ?Request $request = null,
    ): array {
        $existing = $this->redemptions->findForOrder($couponId, $orderReference);

        if ($existing !== null) {
            // Idempotent: a retried order-placement call must not consume twice.
            return $existing;
        }

        $coupon = $this->coupons->findById($couponId);

        if ($coupon === null) {
            throw new NotFoundException('That coupon no longer exists.');
        }

        return $this->db->transaction(function () use ($coupon, $couponId, $userId, $orderReference, $discountAmount, $orderValue, $cartId, $request): array {
            // Re-check the per-customer limit inside the transaction; the cart
            // may have sat for hours since validation.
            $used = $this->coupons->countConfirmedRedemptionsByUser($couponId, $userId);

            if ($used >= (int) $coupon['per_customer_limit']) {
                throw new HttpException(
                    'You have already used this code the maximum number of times.',
                    409
                );
            }

            // Atomic: the limit test and the increment are one statement, so two
            // customers claiming the last use cannot both win.
            if (!$this->coupons->claimUsage($couponId)) {
                throw new HttpException(
                    'This code has just reached its usage limit.',
                    409,
                    ['coupon_code' => ['All uses of this code have been claimed.']]
                );
            }

            $redemptionId = $this->redemptions->create([
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'cart_id' => $cartId,
                'order_reference' => $orderReference,
                'discount_amount' => (string) $discountAmount,
                'order_value' => (string) $orderValue,
                'status' => 'confirmed',
            ], $userId);

            $this->audit->log(
                entityName: 'coupon_redemptions',
                entityId: $redemptionId,
                action: 'redeem',
                newValues: [
                    'coupon_code' => $coupon['code'],
                    'order_reference' => $orderReference,
                    'discount_amount' => $discountAmount->toDecimal(),
                ],
                request: $request
            );

            return (array) $this->redemptions->findById($redemptionId);
        });
    }

    /**
     * Returns a claimed use when an order is cancelled before fulfilment, so a
     * cancellation does not silently consume the customer's one-per-person code.
     */
    public function release(int $couponId, string $orderReference, string $reason, ?Request $request = null): void
    {
        $redemption = $this->redemptions->findForOrder($couponId, $orderReference);

        if ($redemption === null || $redemption['status'] === 'released') {
            return;
        }

        $this->db->transaction(function () use ($redemption, $couponId, $reason, $request): void {
            $this->redemptions->release((int) $redemption['id'], $reason, $request?->authUserId());
            $this->coupons->releaseUsage($couponId);
        });

        $this->audit->log(
            entityName: 'coupon_redemptions',
            entityId: (int) $redemption['id'],
            action: 'release',
            newValues: ['reason' => $reason],
            request: $request,
            entityUuid: (string) $redemption['uuid']
        );
    }

    // -----------------------------------------------------------------------
    // Eligibility
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $coupon */
    private function assertUsable(array $coupon, int $userId): void
    {
        if ((int) $coupon['is_active'] !== 1 || (int) $coupon['is_deleted'] === 1) {
            throw new HttpException('This code is no longer available.', 422);
        }

        if ($coupon['status'] !== 'active') {
            throw new HttpException(
                match ($coupon['status']) {
                    'expired' => 'This code has expired.',
                    'paused' => 'This code is temporarily unavailable.',
                    default => 'This code is not currently active.',
                },
                422,
                ['coupon_code' => ['Not available right now.']]
            );
        }

        if ($coupon['valid_from'] !== null && strtotime((string) $coupon['valid_from']) > time()) {
            throw new HttpException(
                sprintf('This code is valid from %s.', date('j M Y', strtotime((string) $coupon['valid_from']))),
                422
            );
        }

        if ($coupon['valid_to'] !== null && strtotime((string) $coupon['valid_to']) < time()) {
            throw new HttpException('This code has expired.', 422);
        }

        if ($coupon['total_usage_limit'] !== null
            && (int) $coupon['total_redeemed'] >= (int) $coupon['total_usage_limit']) {
            throw new HttpException('This code has reached its usage limit.', 422);
        }

        $used = $this->coupons->countConfirmedRedemptionsByUser((int) $coupon['id'], $userId);

        if ($used >= (int) $coupon['per_customer_limit']) {
            throw new HttpException(
                (int) $coupon['per_customer_limit'] === 1
                    ? 'You have already used this code.'
                    : sprintf('You have already used this code %d times.', $used),
                422,
                ['coupon_code' => ['Usage limit reached for your account.']]
            );
        }

        if ($coupon['audience'] === 'specific_customer'
            && (int) $coupon['specific_user_id'] !== $userId) {
            // Same message as a non-existent code: revealing that a private code
            // exists but belongs to someone else invites probing.
            throw new HttpException(
                sprintf('The code %s does not exist.', $coupon['code']),
                404,
                ['coupon_code' => ['Check the spelling and try again.']]
            );
        }

        if ($coupon['audience'] === 'new_customers' && $this->hasCompletedOrder($userId)) {
            throw new HttpException(
                'This code is only valid on a first order.',
                422,
                ['coupon_code' => ['First-order offer.']]
            );
        }
    }

    /**
     * Value of the cart lines this coupon covers.
     *
     * A category-scoped coupon covers the category and its subcategories, so
     * "₹50 off Spices" works on a product filed under Ground Spices.
     *
     * @param array<string, mixed>            $coupon
     * @param array<int, array<string, mixed>> $cartLines
     */
    private function eligibleSubtotal(array $coupon, array $cartLines): Money
    {
        if ($coupon['applies_to'] === 'all') {
            $total = Money::zero();

            foreach ($cartLines as $line) {
                $total = $total->add($this->lineValue($line));
            }

            return $total;
        }

        $targets = $this->coupons->targetsFor((int) $coupon['id']);

        if ($targets === []) {
            // A scoped coupon with no targets covers nothing. Failing closed is
            // the safe direction: the alternative discounts the whole catalogue.
            return Money::zero();
        }

        $categoryIds = [];
        $productIds = [];

        foreach ($targets as $target) {
            if ($target['target_type'] === 'category') {
                $categoryIds[(int) $target['category_id']] = true;
            } else {
                $productIds[(int) $target['product_id']] = true;
            }
        }

        $total = Money::zero();

        foreach ($cartLines as $line) {
            $matches = isset($productIds[(int) $line['product_id']])
                || isset($categoryIds[(int) $line['category_id']])
                || ($line['category_parent_id'] !== null
                    && isset($categoryIds[(int) $line['category_parent_id']]));

            if ($matches) {
                $total = $total->add($this->lineValue($line));
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $line */
    private function lineValue(array $line): Money
    {
        return Money::fromDecimal((string) $line['unit_price_snapshot'])
            ->multiply((int) $line['quantity']);
    }

    /** @param array<string, mixed> $coupon */
    private function scopeMessage(array $coupon): string
    {
        if ($coupon['applies_to'] === 'categories') {
            $names = [];

            foreach ($this->coupons->targetsFor((int) $coupon['id']) as $target) {
                if ($target['target_type'] !== 'category') {
                    continue;
                }

                $category = $this->categories->findById((int) $target['category_id']);

                if ($category !== null) {
                    $names[] = (string) $category['name'];
                }
            }

            return $names === []
                ? 'This code does not apply to anything in your cart.'
                : sprintf('This code applies to %s only.', implode(', ', $names));
        }

        if ($coupon['applies_to'] === 'products') {
            return 'This code applies to selected products only, none of which are in your cart.';
        }

        return 'This code does not apply to anything in your cart.';
    }

    /**
     * Whether the customer has ever completed an order.
     *
     * Orders arrive in Phase 5. Until then a confirmed coupon redemption is the
     * best available signal, and it is the right one afterwards too: a customer
     * who has redeemed a coupon on a real order is not a first-time buyer.
     */
    private function hasCompletedOrder(int $userId): bool
    {
        $orderTableExists = $this->db->scalar(
            "SELECT 1 FROM `information_schema`.`tables`
              WHERE `table_schema` = DATABASE() AND `table_name` = 'orders' LIMIT 1"
        ) !== null;

        if ($orderTableExists) {
            return (int) $this->db->scalar(
                "SELECT COUNT(*) FROM `orders`
                  WHERE `user_id` = :user_id
                    AND `status` NOT IN ('created','cancelled')
                    AND `is_deleted` = 0",
                ['user_id' => $userId]
            ) > 0;
        }

        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `coupon_redemptions`
              WHERE `user_id` = :user_id AND `status` = 'confirmed' AND `is_deleted` = 0",
            ['user_id' => $userId]
        ) > 0;
    }
}
