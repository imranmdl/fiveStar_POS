<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CouponRedemptionRepository;
use App\Repositories\CouponRepository;
use App\Services\CartService;
use App\Services\OfferRecommendationService;
use App\Services\OfferService;
use App\Services\Promotions\DiscountCalculator;
use App\Helpers\Money;

/**
 * Coupons and offers: customer-facing discovery plus administrator management.
 */
final class PromotionController extends BaseController
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly CartService $cart,
        private readonly CouponRepository $coupons,
        private readonly CouponRedemptionRepository $redemptions,
        private readonly OfferRecommendationService $recommendations,
    ) {
    }

    /** GET /api/v1/admin/offers/recommendations */
    public function recommendations(Request $request): Response
    {
        return Response::success(
            ['recommendations' => $this->recommendations->recommendations()],
            'Recommendations loaded'
        );
    }

    // -----------------------------------------------------------------------
    // Public
    // -----------------------------------------------------------------------

    /** GET /api/v1/offers?type=deal_of_day */
    public function offers(Request $request): Response
    {
        $type = $request->query('type');

        if (is_string($type) && $type !== '' && !in_array($type, [
            'festival', 'flash_sale', 'deal_of_day', 'category', 'combo', 'free_shipping',
        ], true)) {
            throw new HttpException('Unknown offer type: ' . $type, 422);
        }

        return Response::success(
            ['offers' => $this->offers->liveOffers(is_string($type) && $type !== '' ? $type : null)],
            'Offers loaded'
        );
    }

    /** GET /api/v1/offers/{code} */
    public function offer(Request $request): Response
    {
        return Response::success(
            ['offer' => $this->offers->findLiveByCode((string) $request->routeParam('code'))],
            'Offer loaded'
        );
    }

    /** GET /api/v1/offers/{code}/products */
    public function offerProducts(Request $request): Response
    {
        $params = $this->paginationParams($request, 'display_order', 48);
        $result = $this->offers->productsFor((string) $request->routeParam('code'), $params);

        return Response::success(
            ['offer' => $result['offer'], 'products' => $result['items']],
            'Offer products loaded',
            200,
            [
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'total' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $params['per_page']),
            ]
        );
    }

    /** GET /api/v1/cart/coupons (authenticated) */
    public function availableCoupons(Request $request): Response
    {
        return Response::success(
            ['coupons' => $this->cart->availableCoupons($request)],
            'Available coupons loaded'
        );
    }

    // -----------------------------------------------------------------------
    // Administration — coupons
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/coupons */
    public function adminCoupons(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $status = $request->query('status');

        if (is_string($status) && $status !== ''
            && !in_array($status, ['draft', 'active', 'paused', 'expired'], true)) {
            throw new HttpException('Unknown coupon status: ' . $status, 422);
        }

        $result = $this->coupons->paginateForAdmin(
            $params,
            is_string($status) && $status !== '' ? $status : null
        );

        $items = array_map([$this, 'presentCouponForAdmin'], $result['items']);

        return $this->paginated($items, $result['total'], $params, 'Coupons loaded');
    }

    /** POST /api/v1/admin/coupons */
    public function storeCoupon(Request $request): Response
    {
        $data = $this->validateCoupon($request, required: true);

        $code = strtoupper(trim((string) $data['code']));

        if ($this->coupons->codeExists($code)) {
            throw new HttpException('That coupon code is already in use.', 409, [
                'code' => ['Choose a different code.'],
            ]);
        }

        $this->assertCouponCoherent($data);

        // Only pass what the caller actually sent. The validator returns every
        // nullable key as null when absent, and writing null into a NOT NULL
        // column with a default is an integrity error rather than a default.
        $supplied = array_intersect_key($data, $request->all());

        $couponId = $this->coupons->create(array_merge([
            'applies_to' => 'all',
            'audience' => 'all',
            'discount_value' => 0,
            'per_customer_limit' => 1,
            'stackable_with_offer' => 0,
        ], $supplied, [
            'code' => $code,
            // New coupons start as drafts. A live discount appearing the instant
            // it is saved is how margin disappears by accident.
            'status' => 'draft',
        ]), $request->authUserId());

        $this->applyCouponTargets($couponId, $data, $request);

        return Response::created(
            ['coupon' => $this->presentCouponForAdmin((array) $this->coupons->findById($couponId))],
            'Coupon created as a draft. Activate it when you are ready.'
        );
    }

    /** PATCH /api/v1/admin/coupons/{uuid} */
    public function updateCoupon(Request $request): Response
    {
        $coupon = $this->requireCoupon((string) $request->routeParam('uuid'));
        $data = $this->validateCoupon($request, required: false);
        $supplied = array_intersect_key($data, $request->all());

        unset($supplied['code'], $supplied['category_slugs'], $supplied['product_slugs']);

        if ($supplied === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $this->assertCouponCoherent(array_merge($coupon, $supplied));

        $this->coupons->update((int) $coupon['id'], $supplied, $request->authUserId());
        $this->applyCouponTargets((int) $coupon['id'], $data, $request);

        return Response::success(
            ['coupon' => $this->presentCouponForAdmin((array) $this->coupons->findById((int) $coupon['id']))],
            'Coupon updated'
        );
    }

    /** POST /api/v1/admin/coupons/{uuid}/status */
    public function setCouponStatus(Request $request): Response
    {
        $coupon = $this->requireCoupon((string) $request->routeParam('uuid'));

        $data = Validator::make($request->all(), [
            'status' => 'required|in:draft,active,paused,expired',
        ]);

        // Activation readiness: the same reasoning as publishing a product.
        // Refusing an unbounded discount here is far cheaper than discovering it
        // in a month's margin report.
        if ($data['status'] === 'active') {
            $problems = [];

            if ($coupon['valid_to'] === null) {
                $problems[] = 'Set an expiry date. A coupon with no end date runs forever.';
            }

            if ($coupon['discount_type'] === 'percentage' && $coupon['max_discount_amount'] === null) {
                $problems[] = 'Set a maximum discount. An uncapped percentage on a large order is unbounded.';
            }

            if ($coupon['applies_to'] !== 'all' && $this->coupons->targetsFor((int) $coupon['id']) === []) {
                $problems[] = 'This coupon is scoped but has no categories or products selected.';
            }

            if ($problems !== []) {
                throw new HttpException('This coupon is not ready to activate.', 422, [
                    'activation' => $problems,
                ]);
            }
        }

        $this->coupons->update((int) $coupon['id'], ['status' => $data['status']], $request->authUserId());

        return Response::success(
            ['coupon' => $this->presentCouponForAdmin((array) $this->coupons->findById((int) $coupon['id']))],
            'Coupon status updated'
        );
    }

    /** DELETE /api/v1/admin/coupons/{uuid} */
    public function destroyCoupon(Request $request): Response
    {
        $coupon = $this->requireCoupon((string) $request->routeParam('uuid'));

        $this->coupons->softDelete((int) $coupon['id'], $request->authUserId());

        return Response::success([], 'Coupon deleted');
    }

    /** GET /api/v1/admin/coupons/{uuid}/redemptions */
    public function couponRedemptions(Request $request): Response
    {
        $coupon = $this->requireCoupon((string) $request->routeParam('uuid'));
        $params = $this->paginationParams($request, 'created_date', 200);

        $result = $this->redemptions->paginateForCoupon((int) $coupon['id'], $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Redemptions loaded');
    }

    // -----------------------------------------------------------------------
    // Administration — offers
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/offers */
    public function adminOffers(Request $request): Response
    {
        $params = $this->paginationParams($request, 'display_order', 100);
        $status = $request->query('status');

        $result = $this->offers->paginateForAdmin(
            $params,
            is_string($status) && $status !== '' ? $status : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Offers loaded');
    }

    /** GET /api/v1/admin/offers/product-lookup?product_uuids=a,b,c */
    public function offersForProducts(Request $request): Response
    {
        $raw = (string) $request->query('product_uuids', '');
        $uuids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));

        if ($uuids === []) {
            throw new HttpException('Send product_uuids as a comma-separated list.', 422);
        }

        if (count($uuids) > 100) {
            throw new HttpException('Send at most 100 product_uuids per request.', 422);
        }

        return Response::success(
            ['offers' => $this->offers->activeOffersForProductUuids($uuids)],
            'Offers loaded'
        );
    }

    /** POST /api/v1/admin/offers */
    public function storeOffer(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'code' => 'required|string|min:3|max:40',
            'title' => 'required|string|min:3|max:160',
            'subtitle' => 'nullable|string|max:240',
            'description' => 'nullable|string|max:1000',
            'offer_type' => 'nullable|in:festival,flash_sale,deal_of_day,category,combo,free_shipping,bogo',
            'buy_quantity' => 'nullable|int|min:1|max:100',
            'get_quantity' => 'nullable|int|min:1|max:100',
            'free_item_scope' => 'nullable|in:same_variant,cheapest_eligible',
            'max_free_items_per_order' => 'nullable|int|min:1|max:1000',
            'discount_type' => 'nullable|in:none,percentage,flat,free_delivery,free_items',
            'discount_value' => 'nullable|numeric|min:0|max:100000',
            'max_discount_amount' => 'nullable|numeric|min:1',
            'min_order_value' => 'nullable|numeric|min:0',
            'min_quantity' => 'nullable|int|min:1|max:10000',
            'audience' => 'nullable|in:all,new_customers,specific_customer',
            'customer_identifier' => 'nullable|string|max:150',
            'channel' => 'nullable|in:all,pos,online',
            'stackable_with_coupon' => 'nullable|boolean',
            'priority' => 'nullable|int|min:1|max:9999',
            'usage_limit' => 'nullable|int|min:1|max:1000000',
            'starts_date' => 'nullable|date',
            'ends_date' => 'nullable|date',
            'display_order' => 'nullable|int|min:1|max:9999',
            'is_featured' => 'nullable|boolean',
        ]);

        return Response::created(
            ['offer' => $this->offers->create($data, $request)],
            'Offer created as a draft. Activate it when you are ready.'
        );
    }

    /** PATCH /api/v1/admin/offers/{uuid} */
    public function updateOffer(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'nullable|string|min:3|max:160',
            'subtitle' => 'nullable|string|max:240',
            'description' => 'nullable|string|max:1000',
            'offer_type' => 'nullable|in:festival,flash_sale,deal_of_day,category,combo,free_shipping,bogo',
            'discount_type' => 'nullable|in:none,percentage,flat,free_delivery,free_items',
            'discount_value' => 'nullable|numeric|min:0|max:100000',
            'max_discount_amount' => 'nullable|numeric|min:1',
            'min_order_value' => 'nullable|numeric|min:0',
            'min_quantity' => 'nullable|int|min:1|max:10000',
            'buy_quantity' => 'nullable|int|min:1|max:100',
            'get_quantity' => 'nullable|int|min:1|max:100',
            'free_item_scope' => 'nullable|in:same_variant,cheapest_eligible',
            'max_free_items_per_order' => 'nullable|int|min:1|max:1000',
            'audience' => 'nullable|in:all,new_customers,specific_customer',
            'customer_identifier' => 'nullable|string|max:150',
            'channel' => 'nullable|in:all,pos,online',
            'stackable_with_coupon' => 'nullable|boolean',
            'priority' => 'nullable|int|min:1|max:9999',
            'usage_limit' => 'nullable|int|min:1|max:1000000',
            'starts_date' => 'nullable|date',
            'ends_date' => 'nullable|date',
            'display_order' => 'nullable|int|min:1|max:9999',
            'is_featured' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $supplied = array_intersect_key($data, $request->all());

        return Response::success(
            ['offer' => $this->offers->update((string) $request->routeParam('uuid'), $supplied, $request)],
            'Offer updated'
        );
    }

    /** POST /api/v1/admin/offers/{uuid}/status */
    public function setOfferStatus(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'status' => 'required|in:draft,active,paused,expired',
        ]);

        return Response::success(
            ['offer' => $this->offers->setStatus((string) $request->routeParam('uuid'), $data['status'], $request)],
            'Offer status updated'
        );
    }

    /** GET /api/v1/admin/offers/{uuid}/targets */
    public function offerTargets(Request $request): Response
    {
        return Response::success(
            $this->offers->currentTargets((string) $request->routeParam('uuid')),
            'Offer scope loaded'
        );
    }

    /** PUT /api/v1/admin/offers/{uuid}/targets */
    public function setOfferTargets(Request $request): Response
    {
        $categories = $request->input('category_slugs', []);
        $products = $request->input('product_slugs', []);

        if (!is_array($categories) || !is_array($products)) {
            throw new HttpException('Send category_slugs and product_slugs as arrays.', 422);
        }

        return Response::success(
            [
                'offer' => $this->offers->setTargets(
                    (string) $request->routeParam('uuid'),
                    array_map('strval', $categories),
                    array_map('strval', $products),
                    $request
                ),
            ],
            'Offer scope updated'
        );
    }

    /** POST /api/v1/admin/offers/{uuid}/banner */
    public function setOfferBanner(Request $request): Response
    {
        if (!isset($request->files['image'])) {
            throw new HttpException('No image was received.', 422, [
                'image' => ['Attach the file as a multipart field named "image".'],
            ]);
        }

        return Response::success(
            [
                'offer' => $this->offers->setBanner(
                    (string) $request->routeParam('uuid'),
                    $request->files['image'],
                    $request
                ),
            ],
            'Offer banner updated'
        );
    }

    /** DELETE /api/v1/admin/offers/{uuid} */
    public function destroyOffer(Request $request): Response
    {
        $this->offers->delete((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Offer deleted');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Rejects discount values the database would refuse anyway.
     *
     * Without this, a 150% coupon reaches the CHECK constraint and surfaces as an
     * opaque 500. The rules belong in the application so the caller gets a 422
     * naming the field.
     *
     * @param array<string, mixed> $data
     */
    private function assertCouponCoherent(array $data): void
    {
        $type = (string) ($data['discount_type'] ?? '');
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

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && strtotime((string) $data['valid_to']) <= strtotime((string) $data['valid_from'])) {
            throw new HttpException('The coupon must expire after it becomes valid.', 422, [
                'valid_to' => ['Choose an expiry after the start date.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validateCoupon(Request $request, bool $required): array
    {
        $prefix = $required ? 'required' : 'nullable';

        return Validator::make($request->all(), [
            'code' => $prefix . '|string|min:3|max:30',
            'title' => $prefix . '|string|min:3|max:160',
            'description' => 'nullable|string|max:500',
            'terms' => 'nullable|string|max:1000',
            'discount_type' => $prefix . '|in:percentage,flat,free_delivery',
            'discount_value' => 'nullable|numeric|min:0|max:100000',
            'max_discount_amount' => 'nullable|numeric|min:1',
            'min_order_value' => 'nullable|numeric|min:0',
            'applies_to' => 'nullable|in:all,categories,products',
            'audience' => 'nullable|in:all,new_customers,specific_customer,referral',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
            'total_usage_limit' => 'nullable|int|min:1|max:1000000',
            'per_customer_limit' => 'nullable|int|min:1|max:100',
            'stackable_with_offer' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function applyCouponTargets(int $couponId, array $data, Request $request): void
    {
        $categories = $request->input('category_slugs');
        $products = $request->input('product_slugs');

        if (!is_array($categories) && !is_array($products)) {
            return;
        }

        if (is_array($categories) && is_array($products) && $categories !== [] && $products !== []) {
            throw new HttpException(
                'Scope a coupon by categories or by products, not both.',
                422,
                ['targets' => ['Mixing the two makes the discount scope ambiguous.']]
            );
        }

        if (is_array($categories) && $categories !== []) {
            $ids = [];

            foreach ($categories as $slug) {
                $row = $this->coupons->categoryIdBySlug((string) $slug);

                if ($row === null) {
                    throw new HttpException('Unknown category: ' . $slug, 422);
                }

                $ids[] = $row;
            }

            $this->coupons->replaceTargets($couponId, 'category', $ids, $request->authUserId());
            $this->coupons->update($couponId, ['applies_to' => 'categories'], $request->authUserId());

            return;
        }

        if (is_array($products) && $products !== []) {
            $ids = [];

            foreach ($products as $slug) {
                $row = $this->coupons->productIdBySlug((string) $slug);

                if ($row === null) {
                    throw new HttpException('Unknown product: ' . $slug, 422);
                }

                $ids[] = $row;
            }

            $this->coupons->replaceTargets($couponId, 'product', $ids, $request->authUserId());
            $this->coupons->update($couponId, ['applies_to' => 'products'], $request->authUserId());

            return;
        }

        $this->coupons->replaceTargets($couponId, 'category', [], $request->authUserId());
        $this->coupons->update($couponId, ['applies_to' => 'all'], $request->authUserId());
    }

    /** @return array<string, mixed> */
    private function requireCoupon(string $uuid): array
    {
        $coupon = $this->coupons->findByUuid($uuid);

        if ($coupon === null) {
            throw new \App\Core\Exceptions\NotFoundException('That coupon does not exist.');
        }

        return $coupon;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentCouponForAdmin(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'code' => $row['code'],
            'title' => $row['title'],
            'description' => $row['description'],
            'terms' => $row['terms'],
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
            'audience' => $row['audience'],
            'validity' => [
                'from' => $row['valid_from'],
                'to' => $row['valid_to'],
            ],
            'limits' => [
                'total' => $row['total_usage_limit'] === null ? null : (int) $row['total_usage_limit'],
                'per_customer' => (int) $row['per_customer_limit'],
                'total_redeemed' => (int) $row['total_redeemed'],
                'remaining' => $row['total_usage_limit'] === null
                    ? null
                    : max(0, (int) $row['total_usage_limit'] - (int) $row['total_redeemed']),
            ],
            'stackable_with_offer' => (bool) $row['stackable_with_offer'],
            'status' => $row['status'],
            'performance' => [
                'redemptions' => (int) ($row['redemption_rows'] ?? 0),
                'total_discount_given' => (float) ($row['total_discount_given'] ?? 0),
                'total_order_value' => (float) ($row['total_order_value'] ?? 0),
                'unique_customers' => (int) ($row['unique_customers'] ?? 0),
            ],
            'is_active' => (bool) $row['is_active'],
            'created_date' => $row['created_date'],
        ];
    }
}
