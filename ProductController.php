<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ProductService;

final class ProductController extends BaseController
{
    public function __construct(private readonly ProductService $products)
    {
    }

    // -----------------------------------------------------------------------
    // Public
    // -----------------------------------------------------------------------

    /** GET /api/v1/products */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'display_order', 48);
        $filters = $this->readFilters($request);

        $result = $this->products->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Products loaded');
    }

    /** GET /api/v1/products/filters */
    public function filters(Request $request): Response
    {
        return Response::success($this->products->filterOptions(), 'Filter options loaded');
    }

    /** GET /api/v1/products/{identifier} — slug or uuid */
    public function show(Request $request): Response
    {
        $identifier = (string) $request->routeParam('identifier');

        return Response::success(
            ['product' => $this->products->detail($identifier)],
            'Product loaded'
        );
    }

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/products */
    public function adminIndex(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $filters = $this->readFilters($request);
        $filters['include_unpublished'] = true;

        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                throw new HttpException('Unknown status filter: ' . $status, 422);
            }

            $filters['status'] = $status;
        }

        $result = $this->products->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Products loaded');
    }

    /** GET /api/v1/admin/products/{identifier} */
    public function adminShow(Request $request): Response
    {
        $identifier = (string) $request->routeParam('identifier');

        return Response::success(
            ['product' => $this->products->detail($identifier, includeUnpublished: true, countView: false)],
            'Product loaded'
        );
    }

    /** POST /api/v1/admin/products */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:3|max:180',
            'product_code' => 'required|string|min:3|max:40',
            'category_slug' => 'required|string|max:140',
            'slug' => 'nullable|slug|max:180',
            'brand' => 'nullable|string|max:120',
            'short_description' => 'nullable|string|max:320',
            'description' => 'nullable|string|max:20000',
            'ingredients' => 'nullable|string|max:2000',
            'usage_instructions' => 'nullable|string|max:2000',
            'storage_instructions' => 'nullable|string|max:320',
            'shelf_life_days' => 'nullable|int|min:1|max:3650',
            'origin_country' => 'nullable|string|max:80',
            'origin_region' => 'nullable|string|max:120',
            'hsn_code' => 'nullable|string|max:15',
            'gst_rate' => 'nullable|numeric|min:0|max:28',
            'fssai_license_no' => 'nullable|string|max:30',
            'is_organic' => 'nullable|boolean',
            'is_vegetarian' => 'nullable|boolean',
            'is_gift_packable' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'display_order' => 'nullable|int|min:1|max:9999',
            'search_keywords' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:180',
            'meta_description' => 'nullable|string|max:320',
        ]);

        $variants = $this->readVariantRows($request);

        return Response::created(
            ['product' => $this->products->create($data, $variants, $request)],
            'Product created as a draft. Add images, then publish it.'
        );
    }

    /** PATCH /api/v1/admin/products/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|min:3|max:180',
            'category_slug' => 'nullable|string|max:140',
            'slug' => 'nullable|slug|max:180',
            'brand' => 'nullable|string|max:120',
            'short_description' => 'nullable|string|max:320',
            'description' => 'nullable|string|max:20000',
            'ingredients' => 'nullable|string|max:2000',
            'usage_instructions' => 'nullable|string|max:2000',
            'storage_instructions' => 'nullable|string|max:320',
            'shelf_life_days' => 'nullable|int|min:1|max:3650',
            'origin_country' => 'nullable|string|max:80',
            'origin_region' => 'nullable|string|max:120',
            'hsn_code' => 'nullable|string|max:15',
            'gst_rate' => 'nullable|numeric|min:0|max:28',
            'fssai_license_no' => 'nullable|string|max:30',
            'is_organic' => 'nullable|boolean',
            'is_vegetarian' => 'nullable|boolean',
            'is_gift_packable' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'display_order' => 'nullable|int|min:1|max:9999',
            'search_keywords' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:180',
            'meta_description' => 'nullable|string|max:320',
        ]);

        // Only keys the caller actually sent are applied; nullable rules would
        // otherwise blank out fields that were merely omitted.
        $supplied = array_intersect_key($data, $request->all());

        return Response::success(
            ['product' => $this->products->update((string) $request->routeParam('uuid'), $supplied, $request)],
            'Product updated'
        );
    }

    /** POST /api/v1/admin/products/{uuid}/publish */
    public function publish(Request $request): Response
    {
        return Response::success(
            ['product' => $this->products->publish((string) $request->routeParam('uuid'), $request)],
            'Product published'
        );
    }

    /** POST /api/v1/admin/products/{uuid}/archive */
    public function archive(Request $request): Response
    {
        $this->products->archive((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Product archived and withdrawn from sale');
    }

    /** DELETE /api/v1/admin/products/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->products->delete((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Product deleted');
    }

    // --- Variants ----------------------------------------------------------

    /** POST /api/v1/admin/products/{uuid}/variants */
    public function storeVariant(Request $request): Response
    {
        $data = $this->validateVariant($request->all());

        return Response::created(
            ['product' => $this->products->addVariant((string) $request->routeParam('uuid'), $data, $request)],
            'Pack size added'
        );
    }

    /** PATCH /api/v1/admin/variants/{uuid} */
    public function updateVariant(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'sku' => 'nullable|string|min:3|max:50',
            'variant_name' => 'nullable|string|min:2|max:80',
            'weight_grams' => 'nullable|int|min:1|max:100000',
            'packed_weight_grams' => 'nullable|int|min:1|max:120000',
            'pack_type' => 'nullable|in:pouch,jar,box,tin,gift_box,refill,other',
            'mrp' => 'nullable|numeric|min:1',
            'selling_price' => 'nullable|numeric|min:1',
            'offer_price' => 'nullable|numeric|min:1',
            'offer_start_date' => 'nullable|date',
            'offer_end_date' => 'nullable|date',
            'max_order_quantity' => 'nullable|int|min:1|max:500',
            'is_default' => 'nullable|boolean',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);

        $supplied = array_intersect_key($data, $request->all());

        if ($supplied === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        return Response::success(
            ['product' => $this->products->updateVariant((string) $request->routeParam('uuid'), $supplied, $request)],
            'Pack size updated'
        );
    }

    /** DELETE /api/v1/admin/variants/{uuid} */
    public function destroyVariant(Request $request): Response
    {
        $this->products->deleteVariant((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Pack size removed');
    }

    // --- Media -------------------------------------------------------------

    /**
     * POST /api/v1/admin/products/{uuid}/images
     * multipart/form-data with an `image` file part.
     */
    public function storeImage(Request $request): Response
    {
        if (!isset($request->files['image'])) {
            throw new HttpException('No image was received.', 422, [
                'image' => ['Attach the file as a multipart field named "image".'],
            ]);
        }

        $data = Validator::make($request->all(), [
            'alt_text' => 'nullable|string|max:180',
            'caption' => 'nullable|string|max:180',
            'is_primary' => 'nullable|boolean',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);

        return Response::created(
            [
                'product' => $this->products->addImage(
                    (string) $request->routeParam('uuid'),
                    $request->files['image'],
                    $data,
                    $request
                ),
            ],
            'Image uploaded'
        );
    }

    /** POST /api/v1/admin/products/{uuid}/videos */
    public function storeVideo(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'external_url' => 'required|string|max:500',
            'alt_text' => 'nullable|string|max:180',
            'caption' => 'nullable|string|max:180',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);

        if (filter_var($data['external_url'], FILTER_VALIDATE_URL) === false
            || !str_starts_with(strtolower($data['external_url']), 'https://')) {
            throw new HttpException('The video URL must be a full HTTPS URL.', 422, [
                'external_url' => ['Only https:// video URLs are accepted.'],
            ]);
        }

        return Response::created(
            ['product' => $this->products->addVideo((string) $request->routeParam('uuid'), $data, $request)],
            'Video linked'
        );
    }

    /** DELETE /api/v1/admin/media/{uuid} */
    public function destroyMedia(Request $request): Response
    {
        $this->products->deleteMedia((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Media removed');
    }

    // --- Nutrition and attributes -----------------------------------------

    /** PUT /api/v1/admin/products/{uuid}/nutrition */
    public function saveNutrition(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'serving_size_g' => 'nullable|int|min:1|max:1000',
            'energy_kcal' => 'nullable|numeric|min:0|max:2000',
            'protein_g' => 'nullable|numeric|min:0|max:100',
            'total_fat_g' => 'nullable|numeric|min:0|max:100',
            'saturated_fat_g' => 'nullable|numeric|min:0|max:100',
            'trans_fat_g' => 'nullable|numeric|min:0|max:100',
            'carbohydrate_g' => 'nullable|numeric|min:0|max:100',
            'total_sugar_g' => 'nullable|numeric|min:0|max:100',
            'added_sugar_g' => 'nullable|numeric|min:0|max:100',
            'dietary_fibre_g' => 'nullable|numeric|min:0|max:100',
            'sodium_mg' => 'nullable|numeric|min:0|max:50000',
            'iron_mg' => 'nullable|numeric|min:0|max:1000',
            'calcium_mg' => 'nullable|numeric|min:0|max:50000',
            'allergen_info' => 'nullable|string|max:320',
        ]);

        return Response::success(
            ['product' => $this->products->saveNutrition((string) $request->routeParam('uuid'), $data, $request)],
            'Nutrition information saved'
        );
    }

    /** PUT /api/v1/admin/products/{uuid}/attributes */
    public function saveAttributes(Request $request): Response
    {
        $rows = $request->input('attributes');

        if (!is_array($rows)) {
            throw new HttpException('Send an `attributes` array.', 422, [
                'attributes' => ['Expected a list of {attribute_name, attribute_value} objects.'],
            ]);
        }

        if (count($rows) > 30) {
            throw new HttpException('A product can have at most 30 extra specifications.', 422);
        }

        $validated = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new HttpException(sprintf('Attribute %d is not an object.', $index + 1), 422);
            }

            $clean = Validator::make($row, [
                'attribute_name' => 'required|string|min:2|max:80',
                'attribute_value' => 'required|string|min:1|max:255',
            ]);

            $key = strtolower($clean['attribute_name']);

            if (isset($seen[$key])) {
                throw new HttpException(
                    'Duplicate specification name: ' . $clean['attribute_name'],
                    422,
                    ['attributes' => ['Each specification name may appear only once.']]
                );
            }

            $seen[$key] = true;
            $validated[] = $clean;
        }

        return Response::success(
            ['product' => $this->products->saveAttributes((string) $request->routeParam('uuid'), $validated, $request)],
            'Specifications saved'
        );
    }

    // -----------------------------------------------------------------------
    // Input helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function readFilters(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'category' => 'nullable|string|max:140',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'min_weight_grams' => 'nullable|int|min:0',
            'max_weight_grams' => 'nullable|int|min:0',
            'min_rating' => 'nullable|numeric|min:0|max:5',
            'has_offer' => 'nullable|boolean',
            'is_organic' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'brand' => 'nullable|string|max:120',
            'sort' => 'nullable|in:relevance,newest,price_low,price_high,rating,popularity,discount,name_asc,name_desc',
        ]);

        $filters = [];

        if (!empty($data['category'])) {
            $filters['category_slug'] = $data['category'];
        }

        foreach (['min_price', 'max_price', 'min_weight_grams', 'max_weight_grams', 'min_rating', 'brand'] as $key) {
            if (isset($data[$key])) {
                $filters[$key] = $data[$key];
            }
        }

        foreach (['has_offer', 'is_organic', 'is_featured'] as $flag) {
            if (!empty($data[$flag])) {
                $filters[$flag] = true;
            }
        }

        $filters['sort'] = $data['sort'] ?? 'relevance';

        return $filters;
    }

    /** @return array<int, array<string, mixed>> */
    private function readVariantRows(Request $request): array
    {
        $rows = $request->input('variants');

        if (!is_array($rows) || $rows === []) {
            throw new HttpException('A product needs at least one pack size.', 422, [
                'variants' => ['Send a `variants` array with at least one entry.'],
            ]);
        }

        if (count($rows) > 20) {
            throw new HttpException('A product can have at most 20 pack sizes.', 422);
        }

        $validated = [];
        $seenSkus = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new HttpException(sprintf('Variant %d is not an object.', $index + 1), 422);
            }

            $clean = $this->validateVariant($row);
            $sku = strtoupper($clean['sku']);

            if (isset($seenSkus[$sku])) {
                throw new HttpException(
                    'Duplicate SKU in the request: ' . $sku,
                    422,
                    ['variants' => ['Each pack size needs its own SKU.']]
                );
            }

            $seenSkus[$sku] = true;
            $validated[] = $clean;
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function validateVariant(array $input): array
    {
        return Validator::make($input, [
            'sku' => 'required|string|min:3|max:50',
            'variant_name' => 'required|string|min:2|max:80',
            'weight_grams' => 'required|int|min:1|max:100000',
            'packed_weight_grams' => 'nullable|int|min:1|max:120000',
            'pack_type' => 'nullable|in:pouch,jar,box,tin,gift_box,refill,other',
            'mrp' => 'required|numeric|min:1',
            'selling_price' => 'required|numeric|min:1',
            'offer_price' => 'nullable|numeric|min:1',
            'offer_start_date' => 'nullable|date',
            'offer_end_date' => 'nullable|date',
            'max_order_quantity' => 'nullable|int|min:1|max:500',
            'is_default' => 'nullable|boolean',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);
    }
}
