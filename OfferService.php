<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\CategoryRepository;
use App\Repositories\OfferRepository;
use App\Repositories\ProductRepository;
use App\Services\Pricing\PriceAdjustment;
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
    public function __construct(
        private readonly OfferRepository $offers,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
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
            ],
            'rating' => [
                'average' => (float) $row['rating_average'],
                'count' => (int) $row['rating_count'],
            ],
            'is_organic' => (bool) $row['is_organic'],
            'primary_image_url' => $this->uploads->publicUrl($row['primary_image_path'] ?? null),
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
    ): array {
        $applicable = [];

        foreach ($this->offers->liveDiscountingOffers() as $offer) {
            $minimum = $offer['min_order_value'] === null
                ? null
                : Money::fromDecimal((string) $offer['min_order_value']);

            if ($minimum !== null && $itemsSubtotal->lessThan($minimum)) {
                continue;
            }

            if ($offer['discount_type'] === 'free_delivery' && !$deliveryCharge->isPositive()) {
                continue;
            }

            $eligibleSubtotal = $this->eligibleSubtotal($offer, $cartLines);

            if (!$eligibleSubtotal->isPositive() && $offer['discount_type'] !== 'free_delivery') {
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
            'applies_to' => $data['applies_to'] ?? 'all',
            'stackable_with_coupon' => (int) ($data['stackable_with_coupon'] ?? 0),
            'priority' => (int) ($data['priority'] ?? 100),
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
            'discount_value', 'max_discount_amount', 'min_order_value', 'applies_to',
            'stackable_with_coupon', 'priority', 'starts_date', 'ends_date',
            'display_order', 'is_featured', 'is_active',
        ]));

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

    /** @param array<string, mixed> $data */
    private function assertDiscountCoherent(array $data): void
    {
        $type = (string) ($data['discount_type'] ?? 'none');
        $value = (float) ($data['discount_value'] ?? 0);

        if ($type === 'percentage' && ($value <= 0 || $value > 100)) {
            throw new HttpException('A percentage discount must be between 0 and 100.', 422, [
                'discount_value' => ['Enter a percentage between 0 and 100.'],
            ]);
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
                'summary' => DiscountCalculator::describe(
                    (string) $row['discount_type'],
                    (float) $row['discount_value'],
                    $row['max_discount_amount'] === null
                        ? null
                        : Money::fromDecimal((string) $row['max_discount_amount'])
                ),
            ],
            'applies_to' => $row['applies_to'],
            'stackable_with_coupon' => (bool) $row['stackable_with_coupon'],
            'priority' => (int) $row['priority'],
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
