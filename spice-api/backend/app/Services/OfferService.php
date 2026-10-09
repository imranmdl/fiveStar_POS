<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\CategoryRepository;
use App\Repositories\InventoryStockRepository;
use App\Repositories\OfferRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantOptionRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\UserRepository;
use App\Services\Pricing\PriceAdjustment;
use App\Services\Promotions\BogoCalculator;
use App\Services\Promotions\DiscountCalculator;

/**
 * Merchandising campaigns, and the automatic discounts some of them carry.
 *
 * Distinct from the per-variant `offer_price` set in the catalog: that is a
 * price on one pack size, already resolved by vw_variant_pricing. An offer here
 * is a named, dated campaign that groups products for listing pages and can
 * optionally discount a whole cart without the customer typing anything.
 *
 * Automatic discounts are applied silently, which makes them dangerous: an
 * offer nobody remembers configuring will quietly erode margin for as long as
 * its window lasts. So they are always dated, always attributed by name in the
 * cart response, and only ever one at a time.
 */
final class OfferService
{
    /** No single automatic offer may discount more than this. */
    private const MAX_PERCENTAGE_DISCOUNT = 15.0;

    /**
     * Margin left on a variant, after every currently-active offer targeting
     * its product PLUS the one being added, must stay above this — an offer
     * that would leave a product this thin or thinner is refused outright.
     */
    private const MIN_MARGIN_PERCENT = 5.0;

    public function __construct(
        private readonly OfferRepository $offers,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly ProductVariantOptionRepository $variantOptions,
        private readonly UserRepository $users,
        private readonly ProductVariantRepository $variants,
        private readonly InventoryStockRepository $inventoryStock,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function liveOffers(?string $offerType = null): array
    {
        return array_map([$this, 'present'], $this->offers->liveOffers($offerType));
    }

    /** @return array<string, mixed> */
    public function findLiveByCode(string $code): array
    {
        $offer = $this->offers->findByCode($code);

        if ($offer === null || $offer['status'] !== 'active') {
            throw new NotFoundException('That offer does not exist or has ended.');
        }

        if ($offer['ends_date'] !== null && strtotime((string) $offer['ends_date']) < time()) {
            throw new NotFoundException('That offer has ended.');
        }

        return $this->present($offer);
    }

    /**
     * Products carried by a campaign — the "Today's Deals" listing.
     *
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function productsFor(string $code, array $params): array
    {
        $offer = $this->offers->findByCode($code);

        if ($offer === null) {
            throw new NotFoundException('That offer does not exist.');
        }

        $result = $this->offers->productsForOffer(
            (int) $offer['id'],
            (string) $offer['applies_to'],
            $params
        );

        // Same shape ProductService::presentListItem() produces, so the
        // storefront's one catalog card renderer works unchanged here too —
        // an offer's product listing is a filtered view of the catalog, not
        // a different kind of thing.
        $sizedIds = $this->variantOptions->productIdsWithSizeOptions(array_column($result['items'], 'id'));
        $choiceIds = $this->variantOptions->productIdsRequiringChoice(array_column($result['items'], 'id'));

        $result['items'] = array_map(fn (array $row): array => [
            'uuid' => $row['uuid'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'brand' => $row['brand'],
            'short_description' => $row['short_description'],
            'category' => [
                'slug' => $row['category_slug'],
                'name' => $row['category_name'],
            ],
            'pricing' => [
                'min_price' => (float) $row['min_price'],
                'max_price' => (float) $row['max_price'],
                'min_mrp' => (float) $row['min_mrp'],
                'max_discount_percentage' => (int) $row['max_discount_percentage'],
                'has_live_offer' => (bool) $row['has_live_offer'],
                'variant_count' => (int) $row['variant_count'],
            ],
            'weight_grams' => [
                'min' => (int) $row['min_weight_grams'],
                'max' => (int) $row['max_weight_grams'],
            ],
            'has_size_options' => in_array((int) $row['id'], $sizedIds, true),
            'requires_choice' => in_array((int) $row['id'], $choiceIds, true),
            'rating' => [
                'average' => (float) $row['rating_average'],
                'count' => (int) $row['rating_count'],
            ],
            'flags' => [
                'is_organic' => (bool) $row['is_organic'],
                'is_vegetarian' => (bool) $row['is_vegetarian'],
                'is_featured' => (bool) $row['is_featured'],
            ],
            'primary_image' => [
                'url' => $this->uploads->publicUrl($row['primary_image_path'] ?? null),
                'alt_text' => $row['name'],
            ],
        ], $result['items']);

        $result['offer'] = $this->present($offer);

        return $result;
    }

    /**
     * Every live automatic discount that applies to this cart, with the amount
     * each would save. The resolver picks between them; this only reports.
     *
     * @param array<int, array<string, mixed>> $cartLines
     *
     * @return array<int, array{offer:array<string, mixed>, adjustment:PriceAdjustment}>
     */
    public function applicableAutoDiscounts(
        array $cartLines,
        Money $itemsSubtotal,
        Money $deliveryCharge,
        ?int $userId = null,
        ?string $channel = null,
    ): array {
        $applicable = [];

        foreach ($this->offers->liveDiscountingOffers() as $offer) {
            // A first-order-only offer needs someone to check that against.
            // Same fail-closed rule PromotionResolver already applies to
            // guest coupon use: unknown means not eligible, not "assume yes".
            if (($offer['audience'] ?? 'all') === 'new_customers'
                && ($userId === null || $this->hasPlacedOrder($userId))) {
                continue;
            }

            // Mirrors CouponService::validateForCart()'s specific_customer check —
            // fails closed the same way: no signed-in match means not eligible.
            if (($offer['audience'] ?? 'all') === 'specific_customer'
                && ($userId === null || (int) $offer['specific_user_id'] !== $userId)) {
                continue;
            }

            // Fail-closed the same way audience does: a channel-restricted offer
            // needs the caller to say which channel this is, not assume "yes".
            if (($offer['channel'] ?? 'all') !== 'all' && $offer['channel'] !== $channel) {
                continue;
            }

            $minimum = $offer['min_order_value'] === null
                ? null
                : Money::fromDecimal((string) $offer['min_order_value']);

            if ($minimum !== null && $itemsSubtotal->lessThan($minimum)) {
                continue;
            }

            if (($offer['min_quantity'] ?? null) !== null
                && $this->eligibleQuantity($offer, $cartLines) < (int) $offer['min_quantity']) {
                continue;
            }

            if (!$this->withinUsageLimit($offer)) {
                continue;
            }

            if ($offer['discount_type'] === 'free_delivery' && !$deliveryCharge->isPositive()) {
                continue;
            }

            $eligibleSubtotal = $this->eligibleSubtotal($offer, $cartLines);

            if (!$eligibleSubtotal->isPositive() && $offer['discount_type'] !== 'free_delivery') {
                continue;
            }

            // Buy X get Y is worked out from quantities rather than a
            // percentage, so it takes its own path. The result is still an
            // amount off, which is what keeps GST correct and lets the existing
            // stacking and apportionment handle it unchanged.
            if ((string) $offer['discount_type'] === 'free_items') {
                $bogo = (new BogoCalculator())->calculate(
                    $this->eligibleLinesForBogo($offer, $cartLines),
                    (int) $offer['buy_quantity'],
                    (int) $offer['get_quantity'],
                    (string) ($offer['free_item_scope'] ?? 'cheapest_eligible'),
                    $offer['max_free_items_per_order'] === null
                        ? null
                        : (int) $offer['max_free_items_per_order'],
                );

                if (!$bogo['amount']->isPositive()) {
                    continue;
                }

                $applicable[] = [
                    'offer' => $offer,
                    'adjustment' => new PriceAdjustment(
                        code: (string) $offer['code'],
                        label: (string) $offer['title'] . ' — ' . $bogo['note'],
                        amount: $bogo['amount'],
                        type: PriceAdjustment::TYPE_DISCOUNT,
                        scope: PriceAdjustment::SCOPE_ORDER,
                    ),
                ];

                continue;
            }

            $computed = DiscountCalculator::compute(
                (string) $offer['discount_type'],
                (float) $offer['discount_value'],
                $offer['max_discount_amount'] === null
                    ? null
                    : Money::fromDecimal((string) $offer['max_discount_amount']),
                $eligibleSubtotal,
                $deliveryCharge
            );

            if (!$computed['amount']->isPositive()) {
                continue;
            }

            $applicable[] = [
                'offer' => $offer,
                'adjustment' => new PriceAdjustment(
                    code: (string) $offer['code'],
                    label: (string) $offer['title'],
                    amount: $computed['amount'],
                    type: PriceAdjustment::TYPE_DISCOUNT,
                    scope: $computed['scope'],
                ),
            ];
        }

        return $applicable;
    }

    /**
     * Offers applicable to ONE scanned POS line — percentage/flat via
     * applicableAutoDiscounts(), the same matching logic the online cart
     * uses, built around a synthetic single-line "cart"; free_items (BOGO)
     * via applicableBogoForPosLine() below, using $existingQuantity to know
     * how many of this same variant are already in the till's cart.
     * free_delivery is excluded for free (its own eligibility check
     * requires a positive delivery charge, and POS passes zero) — it has no
     * meaning at a till and stays online-only.
     *
     * @param array<string, mixed> $line product_id, category_id, category_parent_id, unit_price, quantity
     * @param int $existingQuantity how many of this same variant are already on the bill, before this scan
     * @param int|null $userId a registered customer linked to this sale, if any — POS is walk-in-only
     *                         today, so callers pass null and a `new_customers`-audience offer simply
     *                         never surfaces here until POS gains a customer picker
     *
     * @return array<int, array<string, mixed>> up to 3 candidates, best (largest discount) first
     */
    public function applicableOffersForPosLine(
        array $line,
        Money $cartSubtotalSoFar,
        int $existingQuantity = 0,
        ?int $userId = null,
        array $cartLines = [],
    ): array {
        $unitPrice = Money::fromDecimal((string) $line['unit_price']);

        $newLine = [
            'variant_uuid' => $line['variant_uuid'] ?? null,
            'unit_price_snapshot' => $line['unit_price'],
            'quantity' => 1,
            'product_id' => $line['product_id'],
            'category_id' => $line['category_id'],
            'category_parent_id' => $line['category_parent_id'],
        ];

        // The bill as it stands, and as it will be once this unit is added.
        // Every offer is judged on the whole bill, then credited with only what
        // this one unit ADDS to it — so a minimum-spend offer that this item
        // tips over the line, or a "cheapest one free" across several items,
        // shows up on the scan that earns it, and nothing already discounted
        // is counted twice.
        $before = array_values($cartLines);
        $after = $before;
        $merged = false;

        foreach ($after as $i => $existing) {
            if ($newLine['variant_uuid'] !== null && ($existing['variant_uuid'] ?? null) === $newLine['variant_uuid']) {
                $after[$i]['quantity'] = (int) $existing['quantity'] + 1;
                $merged = true;
                break;
            }
        }

        if (!$merged) {
            $after[] = $newLine;
        }

        $afterDiscounts = $this->applicableAutoDiscounts($after, $cartSubtotalSoFar->add($unitPrice), Money::zero(), $userId, 'pos');
        $beforeDiscounts = $before === []
            ? []
            : $this->applicableAutoDiscounts($before, $cartSubtotalSoFar, Money::zero(), $userId, 'pos');

        $beforeByCode = [];

        foreach ($beforeDiscounts as $c) {
            $beforeByCode[$c['offer']['code']] = (float) $c['adjustment']->amount->toDecimal();
        }

        $mapped = [];

        foreach ($afterDiscounts as $c) {
            $offer = $c['offer'];
            $type = (string) $offer['discount_type'];

            // free_delivery has no meaning at a till. Same-item buy-X-get-Y is
            // handled below because it can also say "add one more to earn it".
            if (!in_array($type, ['percentage', 'flat', 'free_items'], true)
                || ($type === 'free_items' && ($offer['free_item_scope'] ?? 'cheapest_eligible') === 'same_variant')) {
                continue;
            }

            $extra = round((float) $c['adjustment']->amount->toDecimal() - ($beforeByCode[$offer['code']] ?? 0.0), 2);

            if ($extra <= 0) {
                continue;
            }

            $mapped[] = [
                'offer_uuid' => $offer['uuid'],
                'code' => $offer['code'],
                'title' => $offer['title'],
                'discount_amount' => $extra,
                'summary' => $c['adjustment']->label,
                'discount_type' => $type,
                'extra_quantity' => 0,
                'replace_discount' => false,
            ];
        }

        foreach ($this->applicableBogoForPosLine($line, $existingQuantity, $userId, $cartSubtotalSoFar->add($unitPrice)) as $bogo) {
            $mapped[] = $bogo;
        }

        usort($mapped, static fn (array $a, array $b): int => $b['discount_amount'] <=> $a['discount_amount']);

        return array_slice($mapped, 0, 5);
    }

    /**
     * Buy-X-get-Y for a single scanned POS line — scoped to
     * `free_item_scope = 'same_variant'` only (the one BOGO shape this
     * makes sense for at a till: "buy 1 of this, get 1 more of the same
     * thing free"). `cheapest_eligible` scope needs to compare prices
     * across every OTHER line in the cart to pick which item is free — real
     * cart-wide logic BogoCalculator already does for online checkout, but
     * that's a bigger change than one scanned line can answer on its own,
     * so it stays online-only for now.
     *
     * Discount is recomputed from the variant's TOTAL quantity in the cart
     * after this scan (not just the +1 this scan adds), since BOGO earns in
     * whole (buy+get) blocks — the caller replaces the line's discount with
     * this figure rather than adding to it.
     *
     * @param array<string, mixed> $line product_id, category_id, category_parent_id, unit_price
     *
     * @return array<int, array<string, mixed>> every same-variant buy-X-get-Y offer that applies
     */
    private function applicableBogoForPosLine(array $line, int $existingQuantity, ?int $userId, Money $subtotalWithThisUnit): array
    {
        $found = [];

        foreach ($this->offers->liveDiscountingOffers() as $offer) {
            if ($offer['discount_type'] !== 'free_items') {
                continue;
            }

            if (($offer['audience'] ?? 'all') === 'new_customers'
                && ($userId === null || $this->hasPlacedOrder($userId))) {
                continue;
            }

            if (($offer['audience'] ?? 'all') === 'specific_customer'
                && ($userId === null || (int) $offer['specific_user_id'] !== $userId)) {
                continue;
            }

            // Same channel and minimum-spend rules applicableAutoDiscounts() enforces,
            // so an online-only offer never appears at the till.
            if (($offer['channel'] ?? 'all') !== 'all' && $offer['channel'] !== 'pos') {
                continue;
            }

            if ($offer['min_order_value'] !== null
                && $subtotalWithThisUnit->lessThan(Money::fromDecimal((string) $offer['min_order_value']))) {
                continue;
            }

            if (!$this->withinUsageLimit($offer)) {
                continue;
            }

            if (($offer['free_item_scope'] ?? 'cheapest_eligible') !== 'same_variant') {
                continue;
            }

            if ($offer['applies_to'] !== 'all') {
                $targets = $this->offers->targetsFor((int) $offer['id']);
                $categoryIds = [];
                $productIds = [];

                foreach ($targets as $target) {
                    if ($target['target_type'] === 'category') {
                        $categoryIds[(int) $target['category_id']] = true;
                    } else {
                        $productIds[(int) $target['product_id']] = true;
                    }
                }

                $matches = isset($productIds[(int) $line['product_id']])
                    || isset($categoryIds[(int) $line['category_id']])
                    || ($line['category_parent_id'] !== null
                        && isset($categoryIds[(int) $line['category_parent_id']]));

                if (!$matches) {
                    continue;
                }
            }

            $buyQuantity = (int) $offer['buy_quantity'];
            $getQuantity = (int) $offer['get_quantity'];
            $block = $buyQuantity + $getQuantity;
            $totalQuantity = $existingQuantity + 1;

            if ($block <= 0) {
                continue;
            }

            // Not yet a full buy+get block (the first scan of "buy 1 get 1"):
            // still offer the deal, and say how many more units complete it, so
            // the cashier can take it there and then instead of the offer only
            // appearing on a second scan.
            $extraQuantity = 0;

            if ($totalQuantity < $block) {
                $extraQuantity = $block - $totalQuantity;
                $totalQuantity = $block;
            }

            $freeUnits = intdiv($totalQuantity, $block) * $getQuantity;
            $cap = $offer['max_free_items_per_order'] === null ? null : (int) $offer['max_free_items_per_order'];

            if ($cap !== null) {
                $freeUnits = min($freeUnits, $cap);
            }

            if ($freeUnits <= 0) {
                continue;
            }

            $found[] = [
                'offer_uuid' => $offer['uuid'],
                'code' => $offer['code'],
                'title' => $offer['title'],
                'discount_amount' => round($freeUnits * (float) $line['unit_price'], 2),
                'summary' => $extraQuantity > 0
                    ? sprintf(
                        'Buy %d get %d free — add %d more, %d free',
                        $buyQuantity,
                        $getQuantity,
                        $extraQuantity,
                        $freeUnits
                    )
                    : sprintf(
                        'Buy %d get %d free — %d free unit(s) at %d in cart',
                        $buyQuantity,
                        $getQuantity,
                        $freeUnits,
                        $totalQuantity
                    ),
                'discount_type' => 'free_items',
                // Units to add on top of the one being scanned to complete the block.
                'extra_quantity' => $extraQuantity,
                // Worked out from this variant's TOTAL quantity, so it replaces
                // the line's discount instead of adding to it.
                'replace_discount' => true,
            ];

            // The customer wants the deal but not the extra unit: give the units
            // actually being bought their share of it — half price each on
            // "buy 1 get 1", a third off on "buy 2 get 1". That is exactly what
            // they would pay per unit had they taken the full block.
            if ($extraQuantity > 0) {
                $unitsBought = $totalQuantity - $extraQuantity;
                $sharePercent = round(($getQuantity / $block) * 100, 2);

                $found[] = [
                    'offer_uuid' => $offer['uuid'],
                    'code' => $offer['code'],
                    'title' => $offer['title'] . ' (take only ' . $unitsBought . ')',
                    'discount_amount' => round($unitsBought * (float) $line['unit_price'] * $getQuantity / $block, 2),
                    'summary' => sprintf(
                        'Without the extra unit — %s%% off %d unit(s), the same price each as buying the full offer',
                        rtrim(rtrim(number_format($sharePercent, 2), '0'), '.'),
                        $unitsBought
                    ),
                    'discount_type' => 'free_items',
                    'extra_quantity' => 0,
                    'replace_discount' => true,
                ];
            }
        }

        return $found;
    }

    /**
     * The cart lines a buy-X-get-Y offer applies to, in the shape the
     * calculator expects.
     *
     * Targeting is reused from `eligibleSubtotal` rather than reimplemented: an
     * offer that discounts one set of products but counts a different set
     * towards the threshold would be indefensible to a customer.
     *
     * @param array<string, mixed> $offer
     * @param array<int, array<string, mixed>> $cartLines
     *
     * @return array<int, array{reference:string, quantity:int, unit_price:Money}>
     */
    private function eligibleLinesForBogo(array $offer, array $cartLines): array
    {
        $matching = $cartLines;

        if ($offer['applies_to'] !== 'all') {
            $targets = $this->offers->targetsFor((int) $offer['id']);

            if ($targets === []) {
                // Fail closed, exactly as eligibleSubtotal does: a scoped
                // promotion with no targets gives nothing away rather than
                // everything.
                return [];
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

            $matching = array_filter($cartLines, static function (array $line) use ($categoryIds, $productIds): bool {
                return isset($productIds[(int) ($line['product_id'] ?? 0)])
                    || isset($categoryIds[(int) ($line['category_id'] ?? 0)]);
            });
        }

        $eligible = [];

        foreach ($matching as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $eligible[] = [
                'reference' => (string) ($line['variant_uuid'] ?? $line['variant_id'] ?? ''),
                'quantity' => $quantity,
                // The same snapshot price eligibleSubtotal uses, so the value of
                // the free items matches what the customer is being charged.
                'unit_price' => Money::fromDecimal((string) $line['unit_price_snapshot']),
            ];
        }

        return $eligible;
    }

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        $code = strtoupper(trim((string) $data['code']));

        if ($this->offers->codeExists($code)) {
            throw new HttpException('That offer code is already in use.', 409, [
                'code' => ['Choose a different code.'],
            ]);
        }

        $this->assertDiscountCoherent($data);

        $audience = $data['audience'] ?? 'all';
        $specificUserId = $audience === 'specific_customer'
            ? $this->resolveCustomerId((string) ($data['customer_identifier'] ?? ''))
            : null;

        $offerId = $this->offers->create([
            'code' => $code,
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'description' => $data['description'] ?? null,
            'offer_type' => $data['offer_type'] ?? 'festival',
            'discount_type' => $data['discount_type'] ?? 'none',
            'discount_value' => $data['discount_value'] ?? 0,
            'max_discount_amount' => $data['max_discount_amount'] ?? null,
            'min_order_value' => $data['min_order_value'] ?? null,
            'min_quantity' => isset($data['min_quantity']) ? (int) $data['min_quantity'] : null,
            // Buy-X-get-Y quantities. The database CHECK refuses a free_items
            // offer without both, and refuses them on any other type — so they
            // are passed through only when they belong, rather than defaulting
            // to something that would quietly change what the offer does.
            'buy_quantity' => ($data['discount_type'] ?? 'none') === 'free_items'
                ? (int) ($data['buy_quantity'] ?? 1)
                : null,
            'get_quantity' => ($data['discount_type'] ?? 'none') === 'free_items'
                ? (int) ($data['get_quantity'] ?? 1)
                : null,
            'free_item_scope' => ($data['discount_type'] ?? 'none') === 'free_items'
                ? ($data['free_item_scope'] ?? 'cheapest_eligible')
                : null,
            'max_free_items_per_order' => ($data['discount_type'] ?? 'none') === 'free_items'
                ? ($data['max_free_items_per_order'] ?? null)
                : null,
            'applies_to' => $data['applies_to'] ?? 'all',
            'audience' => $audience,
            'specific_user_id' => $specificUserId,
            'channel' => $data['channel'] ?? 'all',
            'stackable_with_coupon' => (int) ($data['stackable_with_coupon'] ?? 0),
            'priority' => (int) ($data['priority'] ?? 100),
            'usage_limit' => isset($data['usage_limit']) && $data['usage_limit'] !== ''
                ? (int) $data['usage_limit']
                : null,
            'starts_date' => $data['starts_date'] ?? null,
            'ends_date' => $data['ends_date'] ?? null,
            'display_order' => (int) ($data['display_order'] ?? 100),
            'is_featured' => (int) ($data['is_featured'] ?? 0),
            // New offers start paused. An automatic discount going live the
            // instant it is saved is how margin disappears by accident.
            'status' => 'draft',
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'offers',
            entityId: $offerId,
            action: 'create',
            newValues: ['code' => $code, 'title' => $data['title'], 'status' => 'draft'],
            request: $request
        );

        return $this->present((array) $this->offers->findById($offerId));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $offer = $this->requireOffer($uuid);

        if (array_key_exists('discount_type', $data) || array_key_exists('discount_value', $data)) {
            $this->assertDiscountCoherent(array_merge($offer, $data));
        }

        $changes = array_intersect_key($data, array_flip([
            'title', 'subtitle', 'description', 'offer_type', 'discount_type',
            'discount_value', 'max_discount_amount', 'min_order_value', 'min_quantity', 'applies_to', 'audience', 'channel',
            'stackable_with_coupon', 'priority', 'usage_limit', 'starts_date', 'ends_date',
            'display_order', 'is_featured', 'is_active',
        ]));

        // specific_user_id is derived, not sent directly — mirrors the
        // buy_quantity/get_quantity recompute just below: whenever the
        // audience actually changes, resolve or clear it accordingly rather
        // than leaving a stale id on a row that just stopped being
        // customer-specific (which the CHECK would reject anyway).
        if (array_key_exists('audience', $data) && $data['audience'] !== $offer['audience']) {
            $changes['specific_user_id'] = $data['audience'] === 'specific_customer'
                ? $this->resolveCustomerId((string) ($data['customer_identifier'] ?? ''))
                : null;
        }

        // buy_quantity/get_quantity/free_item_scope/max_free_items_per_order
        // are otherwise immutable after creation (the admin UI locks those
        // inputs once editing an existing offer) — but the database CHECK
        // constraints validate the *whole* row on every UPDATE, not just the
        // changed columns. Changing discount_type without touching these
        // would leave a free_items offer's old buy/get quantities on a row
        // that just became percentage/flat/etc (or vice versa), which the
        // CHECK rejects. Whenever discount_type actually changes, these are
        // recomputed the same way create() derives them, rather than left
        // stale.
        if (array_key_exists('discount_type', $data) && $data['discount_type'] !== $offer['discount_type']) {
            $isBogo = $data['discount_type'] === 'free_items';
            $changes['buy_quantity'] = $isBogo ? (int) ($data['buy_quantity'] ?? $offer['buy_quantity'] ?? 1) : null;
            $changes['get_quantity'] = $isBogo ? (int) ($data['get_quantity'] ?? $offer['get_quantity'] ?? 1) : null;
            $changes['free_item_scope'] = $isBogo
                ? ($data['free_item_scope'] ?? $offer['free_item_scope'] ?? 'cheapest_eligible')
                : null;
            $changes['max_free_items_per_order'] = $isBogo
                ? ($data['max_free_items_per_order'] ?? $offer['max_free_items_per_order'] ?? null)
                : null;
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $this->offers->update((int) $offer['id'], $changes, $request->authUserId());

        $this->audit->log(
            entityName: 'offers',
            entityId: (int) $offer['id'],
            action: 'update',
            oldValues: array_intersect_key($offer, $changes),
            newValues: $changes,
            request: $request,
            entityUuid: $uuid
        );

        return $this->present((array) $this->offers->findById((int) $offer['id']));
    }

    /** @return array<string, mixed> */
    public function setStatus(string $uuid, string $status, Request $request): array
    {
        $offer = $this->requireOffer($uuid);

        if (!in_array($status, ['draft', 'active', 'paused', 'expired'], true)) {
            throw new HttpException('Unknown offer status: ' . $status, 422);
        }

        // Activating an automatic discount is a margin decision, so it gets the
        // same readiness check a product publish gets.
        if ($status === 'active' && $offer['discount_type'] !== 'none') {
            $problems = [];

            if ($offer['ends_date'] === null) {
                $problems[] = 'Set an end date. An automatic discount with no end date runs forever.';
            }

            if ($offer['applies_to'] !== 'all'
                && $this->offers->targetsFor((int) $offer['id']) === []) {
                $problems[] = 'This offer is scoped but has no categories or products selected.';
            }

            if ($offer['discount_type'] === 'percentage' && $offer['max_discount_amount'] === null) {
                $problems[] = 'Set a maximum discount. An uncapped percentage on a large order is unbounded.';
            }

            if ($problems !== []) {
                throw new HttpException(
                    'This offer is not ready to activate.',
                    422,
                    ['activation' => $problems]
                );
            }
        }

        $this->offers->update((int) $offer['id'], ['status' => $status], $request->authUserId());

        $this->audit->log(
            entityName: 'offers',
            entityId: (int) $offer['id'],
            action: 'set_status',
            oldValues: ['status' => $offer['status']],
            newValues: ['status' => $status],
            request: $request,
            entityUuid: $uuid
        );

        return $this->present((array) $this->offers->findById((int) $offer['id']));
    }

    /**
     * The offer's current scope, resolved to slugs and names — for
     * pre-filling the admin edit UI's picker, not for cart matching (that
     * stays on OfferRepository::targetsFor()'s raw ids).
     *
     * @return array{applies_to:string, categories:array<int, array<string, mixed>>, products:array<int, array<string, mixed>>}
     */
    public function currentTargets(string $uuid): array
    {
        $offer = $this->requireOffer($uuid);
        $rows = $this->offers->resolvedTargetsFor((int) $offer['id']);

        $categories = [];
        $products = [];

        foreach ($rows as $row) {
            if ($row['target_type'] === 'category') {
                $categories[] = ['slug' => $row['category_slug'], 'name' => $row['category_name']];
            } else {
                $products[] = ['slug' => $row['product_slug'], 'name' => $row['product_name']];
            }
        }

        return [
            'applies_to' => $offer['applies_to'],
            'categories' => $categories,
            'products' => $products,
        ];
    }

    /**
     * The live campaign offer directly targeting each product, if any —
     * for the catalogue list's offer badge. Keyed by product uuid; products
     * with no directly-targeted offer are simply absent from the result.
     *
     * @param array<int, string> $productUuids
     *
     * @return array<string, array<string, mixed>>
     */
    public function activeOffersForProductUuids(array $productUuids): array
    {
        $rows = $this->offers->activeOffersForProductUuids($productUuids);

        return array_map(static fn (array $row): array => [
            'code' => $row['code'],
            'title' => $row['title'],
            'discount_type' => $row['discount_type'],
            'discount_value' => (float) $row['discount_value'],
            'ends_date' => $row['ends_date'],
            'summary' => DiscountCalculator::describe(
                (string) $row['discount_type'],
                (float) $row['discount_value'],
                null,
                $row['buy_quantity'] === null ? null : (int) $row['buy_quantity'],
                $row['get_quantity'] === null ? null : (int) $row['get_quantity'],
            ),
        ], $rows);
    }

    /**
     * @param array<int, string> $categorySlugs
     * @param array<int, string> $productSlugs
     *
     * @return array<string, mixed>
     */
    public function setTargets(string $uuid, array $categorySlugs, array $productSlugs, Request $request): array
    {
        $offer = $this->requireOffer($uuid);

        if ($categorySlugs !== [] && $productSlugs !== []) {
            throw new HttpException(
                'Scope an offer by categories or by products, not both.',
                422,
                ['targets' => ['Mixing the two makes the discount scope ambiguous.']]
            );
        }

        if ($categorySlugs !== []) {
            $ids = $this->resolveCategoryIds($categorySlugs);
            $this->offers->replaceTargets((int) $offer['id'], 'category', $ids, $request->authUserId());
            $this->offers->update((int) $offer['id'], ['applies_to' => 'categories'], $request->authUserId());
        } elseif ($productSlugs !== []) {
            $ids = $this->resolveProductIds($productSlugs);

            foreach ($ids as $productId) {
                $this->assertMarginSafe($offer, $productId);
            }

            $this->offers->replaceTargets((int) $offer['id'], 'product', $ids, $request->authUserId());
            $this->offers->update((int) $offer['id'], ['applies_to' => 'products'], $request->authUserId());
        } else {
            $this->offers->replaceTargets((int) $offer['id'], 'category', [], $request->authUserId());
            $this->offers->update((int) $offer['id'], ['applies_to' => 'all'], $request->authUserId());
        }

        $this->audit->log(
            entityName: 'offers',
            entityId: (int) $offer['id'],
            action: 'set_targets',
            newValues: ['categories' => $categorySlugs, 'products' => $productSlugs],
            request: $request,
            entityUuid: $uuid
        );

        return $this->present((array) $this->offers->findById((int) $offer['id']));
    }

    /**
     * @param array<string, mixed> $file A $_FILES entry
     *
     * @return array<string, mixed>
     */
    public function setBanner(string $uuid, array $file, Request $request): array
    {
        $offer = $this->requireOffer($uuid);
        $previous = $offer['banner_image_path'];

        $stored = $this->uploads->storeImage($file, 'offers');

        $this->offers->update(
            (int) $offer['id'],
            ['banner_image_path' => $stored['file_path']],
            $request->authUserId()
        );

        if ($previous !== null) {
            $this->uploads->delete($previous);
        }

        return $this->present((array) $this->offers->findById((int) $offer['id']));
    }

    public function delete(string $uuid, Request $request): void
    {
        $offer = $this->requireOffer($uuid);

        $this->offers->softDelete((int) $offer['id'], $request->authUserId());
        $this->uploads->delete($offer['banner_image_path']);

        $this->audit->log(
            entityName: 'offers',
            entityId: (int) $offer['id'],
            action: 'delete',
            oldValues: ['code' => $offer['code'], 'title' => $offer['title']],
            request: $request,
            entityUuid: $uuid
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $status = null): array
    {
        $result = $this->offers->paginateForAdmin($params, $status);
        $result['items'] = array_map([$this, 'present'], $result['items']);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>            $offer
     * @param array<int, array<string, mixed>> $cartLines
     */
    private function eligibleSubtotal(array $offer, array $cartLines): Money
    {
        if ($offer['applies_to'] === 'all') {
            $total = Money::zero();

            foreach ($cartLines as $line) {
                $total = $total->add(
                    Money::fromDecimal((string) $line['unit_price_snapshot'])
                        ->multiply((int) $line['quantity'])
                );
            }

            return $total;
        }

        $targets = $this->offers->targetsFor((int) $offer['id']);

        if ($targets === []) {
            // Fail closed, as with coupons: a scoped promotion with no targets
            // must discount nothing rather than everything.
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
                $total = $total->add(
                    Money::fromDecimal((string) $line['unit_price_snapshot'])
                        ->multiply((int) $line['quantity'])
                );
            }
        }

        return $total;
    }

    /**
     * Same matching rule as eligibleSubtotal(), summed as a quantity instead
     * of a rupee amount — what min_quantity is checked against.
     *
     * @param array<string, mixed>            $offer
     * @param array<int, array<string, mixed>> $cartLines
     */
    private function eligibleQuantity(array $offer, array $cartLines): int
    {
        if ($offer['applies_to'] === 'all') {
            $total = 0;

            foreach ($cartLines as $line) {
                $total += (int) $line['quantity'];
            }

            return $total;
        }

        $targets = $this->offers->targetsFor((int) $offer['id']);

        if ($targets === []) {
            return 0;
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

        $total = 0;

        foreach ($cartLines as $line) {
            $matches = isset($productIds[(int) $line['product_id']])
                || isset($categoryIds[(int) $line['category_id']])
                || ($line['category_parent_id'] !== null
                    && isset($categoryIds[(int) $line['category_parent_id']]));

            if ($matches) {
                $total += (int) $line['quantity'];
            }
        }

        return $total;
    }

    /**
     * Whether this offer still has room under its usage_limit. NULL means
     * unlimited. See OfferRepository::usageCount() for why this is a live
     * count rather than a maintained counter column.
     */
    private function withinUsageLimit(array $offer): bool
    {
        if (($offer['usage_limit'] ?? null) === null) {
            return true;
        }

        return $this->offers->usageCount((int) $offer['id'], (string) $offer['code']) < (int) $offer['usage_limit'];
    }

    /** @param array<string, mixed> $data */
    private function assertDiscountCoherent(array $data): void
    {
        $type = (string) ($data['discount_type'] ?? 'none');
        $value = (float) ($data['discount_value'] ?? 0);

        // Capped well below 100: an automatic, no-code-required discount is
        // the easiest one to apply too generously, and 15% is the ceiling the
        // business has set for how deep any single campaign offer may cut.
        if ($type === 'percentage' && ($value <= 0 || $value > self::MAX_PERCENTAGE_DISCOUNT)) {
            throw new HttpException(
                sprintf('A percentage discount cannot exceed %d%%.', self::MAX_PERCENTAGE_DISCOUNT),
                422,
                ['discount_value' => [sprintf('Enter a percentage between 0 and %d.', self::MAX_PERCENTAGE_DISCOUNT)]]
            );
        }

        if ($type === 'flat' && $value <= 0) {
            throw new HttpException('A flat discount must be greater than zero.', 422, [
                'discount_value' => ['Enter an amount greater than zero.'],
            ]);
        }

        if (!empty($data['starts_date']) && !empty($data['ends_date'])
            && strtotime((string) $data['ends_date']) <= strtotime((string) $data['starts_date'])) {
            throw new HttpException('The offer must end after it starts.', 422, [
                'ends_date' => ['Choose an end date after the start date.'],
            ]);
        }
    }

    /**
     * @param array<int, string> $slugs
     *
     * @return array<int, int>
     */
    private function resolveCategoryIds(array $slugs): array
    {
        $ids = [];

        foreach ($slugs as $slug) {
            $category = $this->categories->findBySlug($slug);

            if ($category === null) {
                throw new HttpException('Unknown category: ' . $slug, 422, [
                    'category_slugs' => ['No category with slug ' . $slug],
                ]);
            }

            $ids[] = (int) $category['id'];
        }

        return $ids;
    }

    /**
     * @param array<int, string> $slugs
     *
     * @return array<int, int>
     */
    private function resolveProductIds(array $slugs): array
    {
        $ids = [];

        foreach ($slugs as $slug) {
            $product = $this->products->findDetailBySlugOrUuid($slug, includeUnpublished: true);

            if ($product === null) {
                throw new HttpException('Unknown product: ' . $slug, 422, [
                    'product_slugs' => ['No product with slug ' . $slug],
                ]);
            }

            $ids[] = (int) $product['id'];
        }

        return $ids;
    }

    /**
     * Refuses to attach `$offer` to `$productId` if doing so — on top of
     * every OTHER live offer already targeting that same product — would
     * leave any of its pack sizes at or below MIN_MARGIN_PERCENT margin.
     *
     * Deliberately conservative: it checks the product's worst-margin
     * variant, not an average, because a campaign offer's percentage applies
     * to every pack size alike — if the thinnest one cannot absorb the cut,
     * the offer is not safe to attach to the product at all.
     *
     * A pack size with no purchase history yet (no `average_cost` recorded)
     * is skipped rather than treated as a violation — there is no cost basis
     * to protect yet, and refusing every offer on a brand-new product until
     * its first purchase is recorded would be a worse outcome than the risk
     * being guarded against.
     */
    private function assertMarginSafe(array $offer, int $productId): void
    {
        if ((string) $offer['discount_type'] === 'none') {
            return;
        }

        $existing = $this->offers->activeDiscountingOffersForProduct(
            $productId,
            isset($offer['id']) ? (int) $offer['id'] : null
        );

        $variants = $this->variants->forProduct($productId);

        foreach ($variants as $variant) {
            $sellingPrice = (float) $variant['selling_price'];

            if ($sellingPrice <= 0) {
                continue;
            }

            $avgCost = $this->averageCostFor((int) $variant['id']);

            if ($avgCost === null) {
                continue;
            }

            $currentMargin = ($sellingPrice - $avgCost) / $sellingPrice * 100;

            $combinedDiscountPercent = $this->discountPercentOf($offer, $sellingPrice);
            foreach ($existing as $other) {
                $combinedDiscountPercent += $this->discountPercentOf($other, $sellingPrice);
            }

            $resultingMargin = $currentMargin - $combinedDiscountPercent;

            if ($resultingMargin <= self::MIN_MARGIN_PERCENT) {
                throw new HttpException(
                    sprintf(
                        '"%s" (%s) would be left at %.1f%% margin — %d%% combined discount on a '
                            . 'product that currently runs %.1f%% margin. The minimum allowed is %d%%.',
                        $variant['variant_name'],
                        $variant['sku'],
                        $resultingMargin,
                        (int) round($combinedDiscountPercent),
                        $currentMargin,
                        (int) self::MIN_MARGIN_PERCENT
                    ),
                    422,
                    ['product_slugs' => ['That product cannot absorb this offer without going below a safe margin.']]
                );
            }
        }
    }

    /** Average of a variant's current cost across warehouses, or null with no purchase history yet. */
    private function averageCostFor(int $variantId): ?float
    {
        $costs = array_values(array_filter(
            array_map(
                static fn (array $row): ?float => $row['average_cost'] === null ? null : (float) $row['average_cost'],
                $this->inventoryStock->forVariant($variantId)
            ),
            static fn (?float $cost): bool => $cost !== null
        ));

        return $costs === [] ? null : array_sum($costs) / count($costs);
    }

    /**
     * One offer's discount, expressed as a percentage of `$sellingPrice` —
     * the common unit the margin guard adds up across every offer already
     * stacked on a product, whatever type each one is. free_items (BOGO)
     * has no percentage of its own; it's converted from what fraction of the
     * items in a qualifying purchase end up free.
     *
     * @param array<string, mixed> $offer
     */
    private function discountPercentOf(array $offer, float $sellingPrice): float
    {
        return match ((string) $offer['discount_type']) {
            'percentage' => (float) $offer['discount_value'],
            'flat' => $sellingPrice > 0 ? min(100.0, (float) $offer['discount_value'] / $sellingPrice * 100) : 0.0,
            'free_items' => ((float) ($offer['buy_quantity'] ?? 0) + (float) ($offer['get_quantity'] ?? 0)) > 0
                ? (float) ($offer['get_quantity'] ?? 0)
                    / ((float) ($offer['buy_quantity'] ?? 0) + (float) ($offer['get_quantity'] ?? 0)) * 100
                : 0.0,
            default => 0.0,
        };
    }

    /**
     * A single named customer, by mobile or email — the same identifier shape
     * as staff/customer sign-in, so whoever is filling in the offer form can
     * type whichever one they have on hand.
     */
    private function resolveCustomerId(string $identifier): int
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new HttpException(
                'A specific-customer offer needs that customer\'s mobile or email.',
                422,
                ['customer_identifier' => ['Enter the customer\'s mobile number or email address.']]
            );
        }

        $user = $this->users->findByIdentifier($identifier);

        if ($user === null) {
            throw new HttpException('No customer found with that mobile or email.', 422, [
                'customer_identifier' => ['Check the spelling and try again.'],
            ]);
        }

        return (int) $user['id'];
    }

    /** @return array<string, mixed> */
    private function summarizeCustomer(int $userId): array
    {
        $user = $this->users->findById($userId);

        return [
            'full_name' => $user['full_name'] ?? null,
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
        ];
    }

    /**
     * Mirrors CouponService::hasCompletedOrder() — duplicated rather than
     * shared, since it's one query and not worth a cross-service dependency.
     */
    private function hasPlacedOrder(int $userId): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `orders`
              WHERE `user_id` = :user_id
                AND `status` NOT IN ('created','cancelled')
                AND `is_deleted` = 0",
            ['user_id' => $userId]
        ) > 0;
    }

    /** @return array<string, mixed> */
    private function requireOffer(string $uuid): array
    {
        $offer = $this->offers->findByUuid($uuid);

        if ($offer === null) {
            throw new NotFoundException('That offer does not exist.');
        }

        return $offer;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'code' => $row['code'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'description' => $row['description'],
            'offer_type' => $row['offer_type'],
            'banner_image_url' => $this->uploads->publicUrl($row['banner_image_path'] ?? null),
            'discount' => [
                'type' => $row['discount_type'],
                'value' => (float) $row['discount_value'],
                'max_amount' => $row['max_discount_amount'] === null
                    ? null
                    : (float) $row['max_discount_amount'],
                'min_order_value' => $row['min_order_value'] === null
                    ? null
                    : (float) $row['min_order_value'],
                'min_quantity' => ($row['min_quantity'] ?? null) === null ? null : (int) $row['min_quantity'],
                'summary' => DiscountCalculator::describe(
                    (string) $row['discount_type'],
                    (float) $row['discount_value'],
                    $row['max_discount_amount'] === null
                        ? null
                        : Money::fromDecimal((string) $row['max_discount_amount']),
                    $row['buy_quantity'] === null ? null : (int) $row['buy_quantity'],
                    $row['get_quantity'] === null ? null : (int) $row['get_quantity'],
                ),
            ],
            'buy_quantity' => $row['buy_quantity'] === null ? null : (int) $row['buy_quantity'],
            'get_quantity' => $row['get_quantity'] === null ? null : (int) $row['get_quantity'],
            'free_item_scope' => $row['free_item_scope'],
            'max_free_items_per_order' => $row['max_free_items_per_order'] === null
                ? null
                : (int) $row['max_free_items_per_order'],
            'applies_to' => $row['applies_to'],
            'audience' => $row['audience'],
            'specific_customer' => $row['specific_user_id'] === null
                ? null
                : $this->summarizeCustomer((int) $row['specific_user_id']),
            'channel' => $row['channel'],
            'stackable_with_coupon' => (bool) $row['stackable_with_coupon'],
            'priority' => (int) $row['priority'],
            'usage' => [
                'limit' => ($row['usage_limit'] ?? null) === null ? null : (int) $row['usage_limit'],
                'used' => ($row['usage_limit'] ?? null) === null
                    ? null
                    : $this->offers->usageCount((int) $row['id'], (string) $row['code']),
            ],
            'schedule' => [
                'starts_date' => $row['starts_date'],
                'ends_date' => $row['ends_date'],
            ],
            'display_order' => (int) $row['display_order'],
            'is_featured' => (bool) $row['is_featured'],
            'status' => $row['status'],
            'created_date' => $row['created_date'],
        ];
    }
}
