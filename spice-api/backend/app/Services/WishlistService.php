<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\WishlistRepository;

/**
 * Wishlists are per product with an optional preferred pack size.
 *
 * `price_at_add` is captured so the price-drop notification in Phase 9 has a
 * baseline to compare against; without it, "price dropped" would mean nothing.
 */
final class WishlistService
{
    public function __construct(
        private readonly WishlistRepository $wishlist,
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly FileUploadService $uploads,
        private readonly CartService $cart,
        private readonly AuditService $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(Request $request, array $params): array
    {
        $result = $this->wishlist->paginateForUser((int) $request->authUserId(), $params);
        $result['items'] = array_map([$this, 'present'], $result['items']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function add(Request $request, string $productIdentifier, ?string $variantUuid): array
    {
        $userId = (int) $request->authUserId();
        $product = $this->products->findDetailBySlugOrUuid($productIdentifier);

        if ($product === null) {
            throw new NotFoundException('That product does not exist or is no longer available.');
        }

        $variantId = null;
        $priceAtAdd = null;

        if ($variantUuid !== null) {
            $variant = $this->variants->findPricedByUuid($variantUuid);

            if ($variant === null || (int) $variant['product_id'] !== (int) $product['id']) {
                throw new HttpException(
                    'That pack size does not belong to this product.',
                    422,
                    ['variant_uuid' => ['Choose a pack size listed on the product.']]
                );
            }

            $variantId = (int) $variant['id'];
            $priceAtAdd = (string) $variant['effective_price'];
        } else {
            $priceAtAdd = $product['min_price'] === null ? null : (string) $product['min_price'];
        }

        $existing = $this->wishlist->findForUserAndProduct($userId, (int) $product['id']);

        if ($existing !== null && (int) $existing['is_deleted'] === 0) {
            throw new HttpException('This product is already on your wishlist.', 409);
        }

        $maximum = (int) $this->config->get('commerce.wishlist_max_items', 200);

        if ($this->wishlist->countForUser($userId) >= $maximum) {
            throw new HttpException(
                sprintf('Your wishlist is full (%d items). Remove something first.', $maximum),
                409
            );
        }

        if ($existing !== null) {
            // UNIQUE (user_id, product_id) means the removed row still holds the
            // slot, so revive it rather than inserting a duplicate.
            $this->wishlist->revive((int) $existing['id'], $variantId, $priceAtAdd, $userId);
            $itemId = (int) $existing['id'];
        } else {
            $itemId = $this->wishlist->create([
                'user_id' => $userId,
                'product_id' => (int) $product['id'],
                'preferred_variant_id' => $variantId,
                'price_at_add' => $priceAtAdd,
                'notify_on_offer' => 1,
                'notify_on_price_drop' => 1,
            ], $userId);
        }

        $this->audit->log(
            entityName: 'wishlist_items',
            entityId: $itemId,
            action: 'add',
            newValues: ['product_slug' => $product['slug'], 'price_at_add' => $priceAtAdd],
            request: $request
        );

        return ['product_slug' => $product['slug'], 'wishlist_count' => $this->wishlist->countForUser($userId)];
    }

    public function remove(Request $request, string $itemUuid): int
    {
        $userId = (int) $request->authUserId();
        $item = $this->requireOwnedItem($itemUuid, $userId);

        $this->wishlist->softDelete((int) $item['id'], $userId);

        $this->audit->log(
            entityName: 'wishlist_items',
            entityId: (int) $item['id'],
            action: 'remove',
            request: $request,
            entityUuid: $itemUuid
        );

        return $this->wishlist->countForUser($userId);
    }

    /** @return array<string, mixed> */
    public function updatePreferences(Request $request, string $itemUuid, array $data): array
    {
        $userId = (int) $request->authUserId();
        $item = $this->requireOwnedItem($itemUuid, $userId);

        $changes = [];

        foreach (['notify_on_offer', 'notify_on_price_drop', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if (!empty($data['variant_uuid'])) {
            $variant = $this->variants->findPricedByUuid((string) $data['variant_uuid']);

            if ($variant === null || (int) $variant['product_id'] !== (int) $item['product_id']) {
                throw new HttpException(
                    'That pack size does not belong to this product.',
                    422,
                    ['variant_uuid' => ['Choose a pack size listed on the product.']]
                );
            }

            $changes['preferred_variant_id'] = (int) $variant['id'];
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $this->wishlist->update((int) $item['id'], $changes, $userId);

        return ['updated' => array_keys($changes)];
    }

    /**
     * Moves a wishlist entry into the cart. Needs a pack size, since a wishlist
     * entry may not have chosen one; if there is no preference, the caller must
     * pick.
     *
     * @return array<string, mixed>
     */
    public function moveToCart(Request $request, string $itemUuid, ?string $variantUuid, int $quantity): array
    {
        $userId = (int) $request->authUserId();
        $item = $this->requireOwnedItem($itemUuid, $userId);

        $resolvedVariantUuid = $variantUuid;

        if ($resolvedVariantUuid === null) {
            if ($item['preferred_variant_id'] === null) {
                throw new HttpException(
                    'Choose a pack size before moving this to your cart.',
                    422,
                    ['variant_uuid' => ['This wishlist entry has no preferred pack size.']]
                );
            }

            $variant = $this->variants->findById((int) $item['preferred_variant_id']);

            if ($variant === null) {
                throw new HttpException(
                    'The saved pack size is no longer available. Choose another.',
                    409
                );
            }

            $resolvedVariantUuid = (string) $variant['uuid'];
        }

        $cartView = $this->cart->addItem($request, $resolvedVariantUuid, $quantity, false, null);

        $this->wishlist->softDelete((int) $item['id'], $userId);

        $this->audit->log(
            entityName: 'wishlist_items',
            entityId: (int) $item['id'],
            action: 'move_to_cart',
            newValues: ['variant_uuid' => $resolvedVariantUuid, 'quantity' => $quantity],
            request: $request,
            entityUuid: $itemUuid
        );

        $cartView['wishlist_count'] = $this->wishlist->countForUser($userId);

        return $cartView;
    }

    public function contains(Request $request, string $productIdentifier): bool
    {
        $product = $this->products->findDetailBySlugOrUuid($productIdentifier);

        if ($product === null) {
            return false;
        }

        return $this->wishlist->existsForUserAndProduct(
            (int) $request->authUserId(),
            (int) $product['id']
        );
    }

    /** @return array<string, mixed> */
    private function requireOwnedItem(string $itemUuid, int $userId): array
    {
        $item = $this->wishlist->findByUuid($itemUuid);

        // Ownership, not just existence: otherwise a guessed uuid would edit
        // someone else's wishlist.
        if ($item === null || (int) $item['user_id'] !== $userId) {
            throw new NotFoundException('That wishlist item does not exist.');
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $priceAtAdd = $row['price_at_add'] === null ? null : Money::fromDecimal((string) $row['price_at_add']);
        $currentPrice = $row['min_price'] === null ? null : Money::fromDecimal((string) $row['min_price']);

        $drop = null;

        if ($priceAtAdd !== null && $currentPrice !== null && $priceAtAdd->greaterThan($currentPrice)) {
            $drop = $priceAtAdd->subtract($currentPrice);
        }

        return [
            'uuid' => $row['uuid'],
            'product' => [
                'uuid' => $row['product_uuid'],
                'slug' => $row['product_slug'],
                'name' => $row['product_name'],
                'brand' => $row['brand'],
                'status' => $row['product_status'],
                'is_available' => $row['product_status'] === 'published',
                'rating' => [
                    'average' => (float) $row['rating_average'],
                    'count' => (int) $row['rating_count'],
                ],
                'primary_image_url' => $this->uploads->publicUrl($row['primary_image_path'] ?? null),
            ],
            'pricing' => [
                'current_min_price' => $currentPrice?->toDecimal(),
                'current_max_price' => $row['max_price'] === null ? null : (float) $row['max_price'],
                'min_mrp' => $row['min_mrp'] === null ? null : (float) $row['min_mrp'],
                'max_discount_percentage' => (int) ($row['max_discount_percentage'] ?? 0),
                'has_live_offer' => (bool) ($row['has_live_offer'] ?? false),
                'price_at_add' => $priceAtAdd?->toDecimal(),
                'price_drop_since_added' => $drop?->toDecimal(),
            ],
            'preferred_variant' => $row['preferred_variant_uuid'] === null ? null : [
                'uuid' => $row['preferred_variant_uuid'],
                'name' => $row['preferred_variant_name'],
            ],
            'notify_on_offer' => (bool) $row['notify_on_offer'],
            'notify_on_price_drop' => (bool) $row['notify_on_price_drop'],
            'notes' => $row['notes'],
            'created_date' => $row['created_date'],
        ];
    }
}
