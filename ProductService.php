<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Str;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductAttributeRepository;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductNutritionRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;

/**
 * All catalog business rules.
 *
 * The rules worth stating explicitly, because they are enforced here and not in
 * any controller or UI:
 *
 *  - A product cannot be published without at least one variant. A variant is
 *    the only thing that carries a weight and a price, and without those the
 *    product cannot be costed (BR-006) or shipped (BR-007).
 *  - A product cannot be published without at least one image. A storefront
 *    tile with no picture is a defect, so the API refuses to create one.
 *  - Exactly one variant is the default and exactly one image is primary; both
 *    invariants are repaired automatically rather than trusted.
 *  - There is no stock anywhere (BR-001/BR-002). Availability is `status`.
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly ProductMediaRepository $media,
        private readonly ProductNutritionRepository $nutrition,
        private readonly ProductAttributeRepository $attributes,
        private readonly CategoryRepository $categories,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    // -----------------------------------------------------------------------
    // Storefront reads
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, array $params): array
    {
        if (!empty($filters['category_slug'])
            && $this->categories->findBySlug((string) $filters['category_slug']) === null) {
            throw new NotFoundException('That category does not exist.');
        }

        if (isset($filters['min_price'], $filters['max_price'])
            && (float) $filters['min_price'] > (float) $filters['max_price']) {
            throw new HttpException('The minimum price cannot be greater than the maximum price.', 422);
        }

        $result = $this->products->search($filters, $params);
        $result['items'] = array_map([$this, 'presentListItem'], $result['items']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function detail(string $identifier, bool $includeUnpublished = false, bool $countView = true): array
    {
        $product = $this->products->findDetailBySlugOrUuid($identifier, $includeUnpublished);

        if ($product === null) {
            throw new NotFoundException('That product does not exist or is no longer available.');
        }

        if ($countView) {
            $this->products->incrementViewCount((int) $product['id']);
        }

        $related = $this->products->relatedProducts(
            (int) $product['id'],
            (int) $this->categoryIdFor($product),
            8
        );

        $presented = $this->presentDetail($product);
        $presented['related_products'] = array_map([$this, 'presentListItem'], $related);

        return $presented;
    }

    /** @return array<string, mixed> */
    public function filterOptions(): array
    {
        $bounds = $this->products->priceBounds();

        return [
            'price' => [
                'min' => $bounds['min_price'],
                'max' => $bounds['max_price'],
            ],
            'weight_grams' => [
                'min' => 0,
                'max' => $bounds['max_weight_grams'],
            ],
            'brands' => $this->products->distinctBrands(),
            'sort_options' => [
                ['value' => 'relevance', 'label' => 'Relevance'],
                ['value' => 'popularity', 'label' => 'Most popular'],
                ['value' => 'newest', 'label' => 'Newest first'],
                ['value' => 'price_low', 'label' => 'Price: low to high'],
                ['value' => 'price_high', 'label' => 'Price: high to low'],
                ['value' => 'discount', 'label' => 'Biggest discount'],
                ['value' => 'rating', 'label' => 'Highest rated'],
                ['value' => 'name_asc', 'label' => 'Name: A to Z'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>       $data
     * @param array<int, array<string, mixed>> $variantRows
     *
     * @return array<string, mixed>
     */
    public function create(array $data, array $variantRows, Request $request): array
    {
        $category = $this->resolveCategory((string) $data['category_slug']);
        $actorId = $request->authUserId();

        if ($variantRows === []) {
            throw new HttpException(
                'A product needs at least one pack size.',
                422,
                ['variants' => ['Add at least one variant with a weight and a price.']]
            );
        }

        $slug = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $productCode = strtoupper(trim((string) $data['product_code']));

        if ($this->products->productCodeExists($productCode)) {
            throw new HttpException(
                'That product code is already in use.',
                409,
                ['product_code' => ['Choose a different internal code.']]
            );
        }

        $productId = $this->db->transaction(function () use ($data, $category, $slug, $productCode, $variantRows, $actorId): int {
            $productId = $this->products->create([
                'category_id' => (int) $category['id'],
                'product_code' => $productCode,
                'slug' => $slug,
                'name' => $data['name'],
                'brand' => $data['brand'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'ingredients' => $data['ingredients'] ?? null,
                'usage_instructions' => $data['usage_instructions'] ?? null,
                'storage_instructions' => $data['storage_instructions'] ?? null,
                'shelf_life_days' => $data['shelf_life_days'] ?? null,
                'origin_country' => $data['origin_country'] ?? 'India',
                'origin_region' => $data['origin_region'] ?? null,
                'hsn_code' => $data['hsn_code'] ?? null,
                'gst_rate' => $data['gst_rate'] ?? 5.00,
                'fssai_license_no' => $data['fssai_license_no'] ?? null,
                'is_organic' => (int) ($data['is_organic'] ?? 0),
                'is_vegetarian' => (int) ($data['is_vegetarian'] ?? 1),
                'is_gift_packable' => (int) ($data['is_gift_packable'] ?? 1),
                // New products start as drafts. Publishing is a separate,
                // deliberate action that runs the readiness checks.
                'status' => 'draft',
                'is_featured' => (int) ($data['is_featured'] ?? 0),
                'display_order' => $data['display_order'] ?? 100,
                'search_keywords' => $data['search_keywords'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
            ], $actorId);

            foreach ($variantRows as $index => $variant) {
                $this->insertVariant($productId, $variant, $index === 0 && $this->noDefaultRequested($variantRows), $actorId);
            }

            $this->variants->ensureDefaultExists($productId);

            return $productId;
        });

        $this->audit->log(
            entityName: 'products',
            entityId: $productId,
            action: 'create',
            newValues: ['name' => $data['name'], 'product_code' => $productCode, 'slug' => $slug],
            request: $request,
            notes: sprintf('%d variant(s) created', count($variantRows))
        );

        return $this->detail($slug, includeUnpublished: true, countView: false);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $product = $this->requireProduct($uuid);
        $actorId = $request->authUserId();
        $changes = [];

        if (!empty($data['category_slug'])) {
            $category = $this->resolveCategory((string) $data['category_slug']);
            $changes['category_id'] = (int) $category['id'];
        }

        foreach ([
            'name', 'brand', 'short_description', 'description', 'ingredients',
            'usage_instructions', 'storage_instructions', 'shelf_life_days',
            'origin_country', 'origin_region', 'hsn_code', 'gst_rate',
            'fssai_license_no', 'is_organic', 'is_vegetarian', 'is_gift_packable',
            'is_featured', 'display_order', 'search_keywords', 'meta_title',
            'meta_description',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if (!empty($data['slug']) && $data['slug'] !== $product['slug']) {
            $changes['slug'] = $this->uniqueSlug((string) $data['slug'], (int) $product['id']);
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $before = array_intersect_key($product, $changes);
        $this->products->update((int) $product['id'], $changes, $actorId);

        $this->audit->log(
            entityName: 'products',
            entityId: (int) $product['id'],
            action: 'update',
            oldValues: $before,
            newValues: $changes,
            request: $request,
            entityUuid: $uuid
        );

        $fresh = $this->products->findById((int) $product['id']);

        return $this->detail((string) $fresh['slug'], includeUnpublished: true, countView: false);
    }

    /**
     * Publishing is where the readiness rules bite. Refusing here is much
     * cheaper than discovering an unshippable product at checkout.
     *
     * @return array<string, mixed>
     */
    public function publish(string $uuid, Request $request): array
    {
        $product = $this->requireProduct($uuid);
        $productId = (int) $product['id'];

        $problems = [];

        if ($this->variants->countForProduct($productId) === 0) {
            $problems[] = 'Add at least one pack size with a weight and a price.';
        }

        if ($this->media->countImagesForProduct($productId) === 0) {
            $problems[] = 'Add at least one product image.';
        }

        if (($product['short_description'] ?? '') === '' || $product['short_description'] === null) {
            $problems[] = 'Add a short description for the listing tile.';
        }

        if ($problems !== []) {
            throw new HttpException(
                'This product is not ready to publish.',
                422,
                ['publish' => $problems]
            );
        }

        $this->db->transaction(function () use ($productId, $request): void {
            $this->variants->ensureDefaultExists($productId);
            $this->media->ensurePrimaryExists($productId);
            $this->products->publish($productId, $request->authUserId());
        });

        $this->audit->log(
            entityName: 'products',
            entityId: $productId,
            action: 'publish',
            oldValues: ['status' => $product['status']],
            newValues: ['status' => 'published'],
            request: $request,
            entityUuid: $uuid
        );

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    public function archive(string $uuid, Request $request): void
    {
        $product = $this->requireProduct($uuid);

        $this->products->archive((int) $product['id'], $request->authUserId());

        $this->audit->log(
            entityName: 'products',
            entityId: (int) $product['id'],
            action: 'archive',
            oldValues: ['status' => $product['status']],
            newValues: ['status' => 'archived'],
            request: $request,
            entityUuid: $uuid,
            notes: 'Withdrawn from sale; historical orders are unaffected'
        );
    }

    public function delete(string $uuid, Request $request): void
    {
        $product = $this->requireProduct($uuid);

        // Soft delete only. Past orders reference this product, so the row must
        // survive for reporting and invoice reprints.
        $this->products->softDelete((int) $product['id'], $request->authUserId());

        $this->audit->log(
            entityName: 'products',
            entityId: (int) $product['id'],
            action: 'delete',
            oldValues: ['name' => $product['name'], 'status' => $product['status']],
            request: $request,
            entityUuid: $uuid
        );
    }

    // -----------------------------------------------------------------------
    // Variants
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function addVariant(string $productUuid, array $data, Request $request): array
    {
        $product = $this->requireProduct($productUuid);
        $actorId = $request->authUserId();

        $variantId = $this->db->transaction(
            fn (): int => $this->insertVariant((int) $product['id'], $data, false, $actorId)
        );

        $this->audit->log(
            entityName: 'product_variants',
            entityId: $variantId,
            action: 'create',
            newValues: ['sku' => $data['sku'], 'weight_grams' => $data['weight_grams']],
            request: $request,
            notes: 'Product ' . $product['product_code']
        );

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateVariant(string $variantUuid, array $data, Request $request): array
    {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $productId = (int) $variant['product_id'];
        $actorId = $request->authUserId();

        if (!empty($data['sku']) && $this->variants->skuExists((string) $data['sku'], (int) $variant['id'])) {
            throw new HttpException('That SKU is already in use.', 409, ['sku' => ['Choose a unique SKU.']]);
        }

        $this->assertVariantPricingIsCoherent(array_merge($variant, $data));

        $this->db->transaction(function () use ($variant, $data, $productId, $actorId): void {
            $this->variants->update((int) $variant['id'], $data, $actorId);

            if (($data['is_default'] ?? 0) == 1) {
                $this->variants->clearDefaultFlag($productId, (int) $variant['id']);
            }

            $this->variants->ensureDefaultExists($productId);
        });

        $this->audit->log(
            entityName: 'product_variants',
            entityId: (int) $variant['id'],
            action: 'update',
            oldValues: array_intersect_key($variant, $data),
            newValues: $data,
            request: $request,
            entityUuid: $variantUuid
        );

        $product = $this->products->findById($productId);

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    public function deleteVariant(string $variantUuid, Request $request): void
    {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $productId = (int) $variant['product_id'];
        $product = $this->products->findById($productId);

        // A published product with no pack size is unbuyable, so removing the
        // last one is refused rather than silently unpublishing the product.
        if ($this->variants->countForProduct($productId) === 1 && ($product['status'] ?? '') === 'published') {
            throw new HttpException(
                'A published product must keep at least one pack size. Archive the product instead.',
                409
            );
        }

        $this->db->transaction(function () use ($variant, $productId, $request): void {
            $this->variants->softDelete((int) $variant['id'], $request->authUserId());
            $this->variants->ensureDefaultExists($productId);
        });

        $this->audit->log(
            entityName: 'product_variants',
            entityId: (int) $variant['id'],
            action: 'delete',
            oldValues: ['sku' => $variant['sku'], 'weight_grams' => $variant['weight_grams']],
            request: $request,
            entityUuid: $variantUuid
        );
    }

    // -----------------------------------------------------------------------
    // Media, nutrition, attributes
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $file  A $_FILES entry
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function addImage(string $productUuid, array $file, array $data, Request $request): array
    {
        $product = $this->requireProduct($productUuid);
        $productId = (int) $product['id'];

        $maxImages = (int) $this->config->get('uploads.max_images_per_product', 10);

        if ($this->media->countImagesForProduct($productId) >= $maxImages) {
            throw new HttpException(
                sprintf('A product can have at most %d images. Remove one first.', $maxImages),
                409
            );
        }

        $stored = $this->uploads->storeImage($file, 'products');
        $makePrimary = (bool) ($data['is_primary'] ?? false)
            || $this->media->countImagesForProduct($productId) === 0;

        try {
            $mediaId = $this->db->transaction(function () use ($productId, $stored, $data, $makePrimary, $request): int {
                if ($makePrimary) {
                    $this->media->clearPrimaryFlag($productId);
                }

                return $this->media->create([
                    'product_id' => $productId,
                    'media_type' => 'image',
                    'file_path' => $stored['file_path'],
                    'alt_text' => $data['alt_text'] ?? $data['fallback_alt_text'] ?? null,
                    'caption' => $data['caption'] ?? null,
                    'width_px' => $stored['width_px'],
                    'height_px' => $stored['height_px'],
                    'file_size_bytes' => $stored['file_size_bytes'],
                    'mime_type' => $stored['mime_type'],
                    'is_primary' => $makePrimary ? 1 : 0,
                    'display_order' => (int) ($data['display_order'] ?? 100),
                ], $request->authUserId());
            });
        } catch (\Throwable $exception) {
            // Do not leave an orphaned file on disk if the row fails to insert.
            $this->uploads->delete($stored['file_path']);

            throw $exception;
        }

        $this->audit->log(
            entityName: 'product_media',
            entityId: $mediaId,
            action: 'create',
            newValues: ['file_path' => $stored['file_path'], 'is_primary' => $makePrimary],
            request: $request,
            notes: 'Product ' . $product['product_code']
        );

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function addVideo(string $productUuid, array $data, Request $request): array
    {
        $product = $this->requireProduct($productUuid);

        $mediaId = $this->media->create([
            'product_id' => (int) $product['id'],
            'media_type' => 'video',
            'external_url' => $data['external_url'],
            'thumbnail_path' => null,
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'is_primary' => 0,
            'display_order' => (int) ($data['display_order'] ?? 200),
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'product_media',
            entityId: $mediaId,
            action: 'create',
            newValues: ['external_url' => $data['external_url'], 'media_type' => 'video'],
            request: $request
        );

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    public function deleteMedia(string $mediaUuid, Request $request): void
    {
        $media = $this->media->findByUuid($mediaUuid);

        if ($media === null) {
            throw new NotFoundException('That media item does not exist.');
        }

        $productId = (int) $media['product_id'];

        $this->db->transaction(function () use ($media, $productId, $request): void {
            $this->media->softDelete((int) $media['id'], $request->authUserId());
            $this->media->ensurePrimaryExists($productId);
        });

        // The row is soft-deleted but the file is removed: keeping orphaned
        // binaries forever is a storage leak, and the audit entry records what
        // was there.
        $this->uploads->delete($media['file_path'] ?? null);

        $this->audit->log(
            entityName: 'product_media',
            entityId: (int) $media['id'],
            action: 'delete',
            oldValues: ['file_path' => $media['file_path'], 'is_primary' => $media['is_primary']],
            request: $request,
            entityUuid: $mediaUuid
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function saveNutrition(string $productUuid, array $data, Request $request): array
    {
        $product = $this->requireProduct($productUuid);

        $this->nutrition->upsertForProduct((int) $product['id'], $data, $request->authUserId());

        $this->audit->log(
            entityName: 'product_nutrition',
            entityId: (int) $product['id'],
            action: 'upsert',
            newValues: $data,
            request: $request,
            notes: 'Product ' . $product['product_code']
        );

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    /**
     * @param array<int, array{attribute_name:string, attribute_value:string}> $attributes
     *
     * @return array<string, mixed>
     */
    public function saveAttributes(string $productUuid, array $attributes, Request $request): array
    {
        $product = $this->requireProduct($productUuid);

        $this->db->transaction(function () use ($product, $attributes, $request): void {
            $this->attributes->replaceForProduct(
                (int) $product['id'],
                $attributes,
                $request->authUserId()
            );
        });

        $this->audit->log(
            entityName: 'product_attributes',
            entityId: (int) $product['id'],
            action: 'replace',
            newValues: ['count' => count($attributes)],
            request: $request
        );

        return $this->detail((string) $product['slug'], includeUnpublished: true, countView: false);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $variant */
    private function insertVariant(int $productId, array $variant, bool $forceDefault, ?int $actorId): int
    {
        $sku = strtoupper(trim((string) $variant['sku']));

        if ($this->variants->skuExists($sku)) {
            throw new HttpException(
                'SKU ' . $sku . ' is already in use.',
                409,
                ['sku' => ['Every pack size needs a unique SKU.']]
            );
        }

        $this->assertVariantPricingIsCoherent($variant);

        $isDefault = $forceDefault || (int) ($variant['is_default'] ?? 0) === 1;

        if ($isDefault) {
            $this->variants->clearDefaultFlag($productId);
        }

        return $this->variants->create([
            'product_id' => $productId,
            'sku' => $sku,
            'variant_name' => $variant['variant_name'],
            'weight_grams' => (int) $variant['weight_grams'],
            'packed_weight_grams' => isset($variant['packed_weight_grams'])
                ? (int) $variant['packed_weight_grams']
                : null,
            'pack_type' => $variant['pack_type'] ?? 'pouch',
            'mrp' => $variant['mrp'],
            'selling_price' => $variant['selling_price'],
            'offer_price' => $variant['offer_price'] ?? null,
            'offer_start_date' => $variant['offer_start_date'] ?? null,
            'offer_end_date' => $variant['offer_end_date'] ?? null,
            'max_order_quantity' => (int) ($variant['max_order_quantity'] ?? 20),
            'is_default' => $isDefault ? 1 : 0,
            'display_order' => (int) ($variant['display_order'] ?? 100),
        ], $actorId);
    }

    /**
     * The database has CHECK constraints for these, but a 422 with a readable
     * message beats a driver-level constraint violation reaching the client.
     *
     * @param array<string, mixed> $variant
     */
    private function assertVariantPricingIsCoherent(array $variant): void
    {
        $errors = [];

        $mrp = isset($variant['mrp']) ? (float) $variant['mrp'] : null;
        $selling = isset($variant['selling_price']) ? (float) $variant['selling_price'] : null;
        $offer = isset($variant['offer_price']) && $variant['offer_price'] !== null
            ? (float) $variant['offer_price']
            : null;

        if ($mrp !== null && $selling !== null && $selling > $mrp) {
            $errors['selling_price'][] = 'The selling price cannot exceed the MRP.';
        }

        if ($offer !== null && $selling !== null && $offer >= $selling) {
            $errors['offer_price'][] = 'The offer price must be below the selling price.';
        }

        if ($offer !== null
            && !empty($variant['offer_start_date'])
            && !empty($variant['offer_end_date'])
            && strtotime((string) $variant['offer_end_date']) <= strtotime((string) $variant['offer_start_date'])) {
            $errors['offer_end_date'][] = 'The offer must end after it starts.';
        }

        if (isset($variant['weight_grams']) && (int) $variant['weight_grams'] <= 0) {
            $errors['weight_grams'][] = 'Weight must be greater than zero.';
        }

        if ($errors !== []) {
            throw new HttpException('The pricing for this pack size is not valid.', 422, $errors);
        }
    }

    /** @param array<int, array<string, mixed>> $variantRows */
    private function noDefaultRequested(array $variantRows): bool
    {
        foreach ($variantRows as $variant) {
            if ((int) ($variant['is_default'] ?? 0) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function requireProduct(string $uuid): array
    {
        $product = $this->products->findByUuid($uuid);

        if ($product === null) {
            throw new NotFoundException('That product does not exist.');
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function resolveCategory(string $slug): array
    {
        $category = $this->categories->findBySlug($slug);

        if ($category === null) {
            throw new HttpException(
                'That category does not exist.',
                422,
                ['category_slug' => ['Unknown category: ' . $slug]]
            );
        }

        return $category;
    }

    private function uniqueSlug(string $source, ?int $exceptId = null): string
    {
        $base = Str::slug($source);
        $candidate = $base;
        $suffix = 2;

        while ($this->products->slugExists($candidate, $exceptId)) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;

            if ($suffix > 100) {
                $candidate = $base . '-' . bin2hex(random_bytes(3));

                break;
            }
        }

        return $candidate;
    }

    /** @param array<string, mixed> $product */
    private function categoryIdFor(array $product): int
    {
        $row = $this->products->findById((int) $product['id']);

        return (int) ($row['category_id'] ?? 0);
    }

    /**
     * Presentation is deliberately explicit rather than returning raw rows:
     * internal ids never leak, numbers are typed, and image paths become URLs.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentListItem(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'brand' => $row['brand'],
            'short_description' => $row['short_description'],
            'category' => [
                'uuid' => $row['category_uuid'] ?? null,
                'slug' => $row['category_slug'] ?? null,
                'name' => $row['category_name'] ?? null,
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
            'rating' => [
                'average' => (float) $row['rating_average'],
                'count' => (int) $row['rating_count'],
                'review_count' => (int) $row['review_count'],
            ],
            'flags' => [
                'is_organic' => (bool) $row['is_organic'],
                'is_vegetarian' => (bool) $row['is_vegetarian'],
                'is_featured' => (bool) $row['is_featured'],
            ],
            'primary_image' => isset($row['primary_image']) && $row['primary_image'] !== null
                ? [
                    'url' => $this->uploads->publicUrl($row['primary_image']['file_path'] ?? null),
                    'alt_text' => $row['primary_image']['alt_text'] ?? $row['name'],
                ]
                : null,
            'status' => $row['status'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentDetail(array $row): array
    {
        $detail = $this->presentListItem($row);

        $detail['description'] = $row['description'] ?? null;
        $detail['ingredients'] = $row['ingredients'] ?? null;
        $detail['usage_instructions'] = $row['usage_instructions'] ?? null;
        $detail['storage_instructions'] = $row['storage_instructions'] ?? null;
        $detail['shelf_life_days'] = $row['shelf_life_days'] === null ? null : (int) $row['shelf_life_days'];
        $detail['origin'] = [
            'country' => $row['origin_country'] ?? null,
            'region' => $row['origin_region'] ?? null,
        ];
        $detail['compliance'] = [
            'hsn_code' => $row['hsn_code'] ?? null,
            'gst_rate' => (float) ($row['gst_rate'] ?? 0),
            'fssai_license_no' => $row['fssai_license_no'] ?? null,
        ];

        $detail['variants'] = array_map(static fn (array $variant): array => [
            'uuid' => $variant['uuid'],
            'sku' => $variant['sku'],
            'variant_name' => $variant['variant_name'],
            'weight_grams' => (int) $variant['weight_grams'],
            'shipping_weight_grams' => (int) $variant['shipping_weight_grams'],
            'pack_type' => $variant['pack_type'],
            'mrp' => (float) $variant['mrp'],
            'selling_price' => (float) $variant['selling_price'],
            'effective_price' => (float) $variant['effective_price'],
            'discount_percentage' => (int) $variant['discount_percentage'],
            'price_per_kg' => (float) $variant['price_per_kg'],
            'offer_is_live' => (bool) $variant['offer_is_live'],
            'max_order_quantity' => (int) $variant['max_order_quantity'],
            'is_default' => (bool) $variant['is_default'],
        ], $row['variants'] ?? []);

        $detail['media'] = array_map(fn (array $media): array => [
            'uuid' => $media['uuid'],
            'media_type' => $media['media_type'],
            'url' => $media['media_type'] === 'video'
                ? $media['external_url']
                : $this->uploads->publicUrl($media['file_path']),
            'thumbnail_url' => $this->uploads->publicUrl($media['thumbnail_path'] ?? null),
            'alt_text' => $media['alt_text'],
            'caption' => $media['caption'],
            'is_primary' => (bool) $media['is_primary'],
        ], $row['media'] ?? []);

        // The detail query returns the full media collection rather than the
        // listing's pre-joined primary image, so derive it here instead of
        // leaving the field null.
        foreach ($detail['media'] as $media) {
            if ($media['media_type'] === 'image' && $media['is_primary']) {
                $detail['primary_image'] = [
                    'url' => $media['url'],
                    'alt_text' => $media['alt_text'] ?? $row['name'],
                ];

                break;
            }
        }

        $detail['nutrition'] = $row['nutrition'] ?? null;
        $detail['attributes'] = $row['attributes'] ?? [];
        $detail['is_gift_packable'] = (bool) ($row['is_gift_packable'] ?? false);
        $detail['meta'] = [
            'title' => $row['meta_title'] ?? $row['name'],
            'description' => $row['meta_description'] ?? $row['short_description'],
        ];

        return $detail;
    }
}
