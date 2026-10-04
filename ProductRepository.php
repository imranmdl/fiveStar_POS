<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Catalog read/write access.
 *
 * All list queries read prices from `vw_product_price_range`, which resolves
 * live offers in one place. Nothing in this class decides whether an offer is
 * active — that rule lives in the view, so listing, detail, cart and checkout
 * can never disagree about a price.
 */
final class ProductRepository extends BaseRepository
{
    /** Columns a client may sort by, mapped to safe SQL expressions. */
    private const SORT_MAP = [
        'relevance' => 'p.`display_order` ASC, p.`is_featured` DESC, p.`id` DESC',
        'newest' => 'p.`published_date` DESC, p.`id` DESC',
        'price_low' => 'pr.`min_price` ASC',
        'price_high' => 'pr.`min_price` DESC',
        'rating' => 'p.`rating_average` DESC, p.`rating_count` DESC',
        'popularity' => 'p.`sold_count` DESC, p.`view_count` DESC',
        'discount' => 'pr.`max_discount_percentage` DESC',
        'name_asc' => 'p.`name` ASC',
        'name_desc' => 'p.`name` DESC',
    ];

    private const LIST_COLUMNS = 'p.`id`, p.`uuid`, p.`product_code`, p.`slug`, p.`name`, p.`brand`,
            p.`short_description`, p.`is_organic`, p.`is_vegetarian`, p.`is_featured`,
            p.`status`, p.`origin_region`, p.`shelf_life_days`, p.`gst_rate`,
            p.`rating_average`, p.`rating_count`, p.`review_count`, p.`published_date`,
            p.`created_date`,
            c.`uuid` AS `category_uuid`, c.`slug` AS `category_slug`, c.`name` AS `category_name`,
            pr.`variant_count`, pr.`min_price`, pr.`max_price`, pr.`min_mrp`, pr.`max_mrp`,
            pr.`min_weight_grams`, pr.`max_weight_grams`,
            pr.`max_discount_percentage`, pr.`has_live_offer`';

    protected function table(): string
    {
        return 'products';
    }

    protected function fillable(): array
    {
        return [
            'category_id', 'product_code', 'slug', 'name', 'brand',
            'short_description', 'description', 'ingredients', 'usage_instructions',
            'storage_instructions', 'shelf_life_days', 'origin_country', 'origin_region',
            'hsn_code', 'gst_rate', 'fssai_license_no', 'is_organic', 'is_vegetarian',
            'is_gift_packable', 'status', 'published_date', 'is_featured', 'display_order',
            'search_keywords', 'meta_title', 'meta_description',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'status', 'display_order', 'created_date', 'published_date', 'rating_average'];
    }

    /**
     * Storefront listing.
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        $where = ['p.`is_deleted` = 0', 'p.`is_active` = 1'];
        $bindings = [];

        // Staff may request drafts; the storefront never sees them.
        if (($filters['include_unpublished'] ?? false) === true) {
            if (!empty($filters['status'])) {
                $where[] = 'p.`status` = :status';
                $bindings['status'] = $filters['status'];
            }
        } else {
            $where[] = "p.`status` = 'published'";
        }

        // Category filter includes descendants, so /spices returns products
        // filed under Ground Spices too.
        if (!empty($filters['category_slug'])) {
            $where[] = '(c.`slug` = :category_slug OR parent.`slug` = :category_slug_parent)';
            $bindings['category_slug'] = $filters['category_slug'];
            $bindings['category_slug_parent'] = $filters['category_slug'];
        }

        if (isset($filters['min_price'])) {
            $where[] = 'pr.`min_price` >= :min_price';
            $bindings['min_price'] = $filters['min_price'];
        }

        if (isset($filters['max_price'])) {
            $where[] = 'pr.`min_price` <= :max_price';
            $bindings['max_price'] = $filters['max_price'];
        }

        if (isset($filters['min_weight_grams'])) {
            $where[] = 'pr.`max_weight_grams` >= :min_weight';
            $bindings['min_weight'] = $filters['min_weight_grams'];
        }

        if (isset($filters['max_weight_grams'])) {
            $where[] = 'pr.`min_weight_grams` <= :max_weight';
            $bindings['max_weight'] = $filters['max_weight_grams'];
        }

        if (isset($filters['min_rating'])) {
            $where[] = 'p.`rating_average` >= :min_rating';
            $bindings['min_rating'] = $filters['min_rating'];
        }

        if (($filters['has_offer'] ?? false) === true) {
            $where[] = 'pr.`has_live_offer` = 1';
        }

        if (($filters['is_organic'] ?? false) === true) {
            $where[] = 'p.`is_organic` = 1';
        }

        if (($filters['is_featured'] ?? false) === true) {
            $where[] = 'p.`is_featured` = 1';
        }

        if (!empty($filters['brand'])) {
            $where[] = 'p.`brand` = :brand';
            $bindings['brand'] = $filters['brand'];
        }

        // Full-text relevance when searching, falling back to LIKE for very
        // short terms that FULLTEXT would discard.
        $relevanceSelect = '';
        $selectOnlyBindings = [];
        $orderBy = self::SORT_MAP[$filters['sort'] ?? 'relevance'] ?? self::SORT_MAP['relevance'];

        if ($params['search'] !== null) {
            $term = $this->normaliseSearchTerm($params['search']);

            if ($term['mode'] === 'fulltext') {
                $where[] = 'MATCH (p.`name`, p.`short_description`, p.`search_keywords`)
                            AGAINST (:search_where IN BOOLEAN MODE)';
                $bindings['search_where'] = $term['value'];

                $relevanceSelect = ', MATCH (p.`name`, p.`short_description`, p.`search_keywords`)
                            AGAINST (:search_select IN BOOLEAN MODE) AS `relevance_score`';
                // Bound to the SELECT statement only. The COUNT statement does
                // not contain this placeholder, and binding an absent parameter
                // is a hard error when prepares are not emulated.
                $selectOnlyBindings['search_select'] = $term['value'];

                if (($filters['sort'] ?? 'relevance') === 'relevance') {
                    $orderBy = '`relevance_score` DESC, p.`rating_average` DESC';
                }
            } else {
                $where[] = '(p.`name` LIKE :search_like
                             OR p.`search_keywords` LIKE :search_like_keywords
                             OR p.`product_code` LIKE :search_like_code)';
                $bindings['search_like'] = '%' . $term['value'] . '%';
                $bindings['search_like_keywords'] = '%' . $term['value'] . '%';
                $bindings['search_like_code'] = '%' . $term['value'] . '%';
            }
        }

        $from = 'FROM `products` p
                 INNER JOIN `categories` c ON c.`id` = p.`category_id`
                 LEFT  JOIN `categories` parent ON parent.`id` = c.`parent_id`
                 INNER JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`';

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            "SELECT COUNT(DISTINCT p.`id`) {$from} WHERE {$whereSql}",
            $bindings
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT %s%s %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
                self::LIST_COLUMNS,
                $relevanceSelect,
                $from,
                $whereSql,
                $orderBy,
                $params['per_page'],
                $params['offset']
            ),
            array_merge($bindings, $selectOnlyBindings)
        );

        return ['items' => $this->attachPrimaryMedia($items), 'total' => $total];
    }

    /**
     * Full product detail: variants, media, nutrition and attributes.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailBySlugOrUuid(string $identifier, bool $includeUnpublished = false): ?array
    {
        $statusClause = $includeUnpublished ? '' : "AND p.`status` = 'published'";

        $product = $this->db->selectOne(
            sprintf(
                'SELECT %s, p.`description`, p.`ingredients`, p.`usage_instructions`,
                        p.`storage_instructions`, p.`origin_country`, p.`hsn_code`,
                        p.`fssai_license_no`, p.`is_gift_packable`, p.`search_keywords`,
                        p.`meta_title`, p.`meta_description`, p.`sold_count`, p.`view_count`,
                        p.`display_order`
                 FROM `products` p
                 INNER JOIN `categories` c ON c.`id` = p.`category_id`
                 INNER JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`
                 WHERE (p.`slug` = :identifier OR p.`uuid` = :identifier_uuid)
                   AND p.`is_deleted` = 0 %s
                 LIMIT 1',
                self::LIST_COLUMNS,
                $statusClause
            ),
            ['identifier' => $identifier, 'identifier_uuid' => $identifier]
        );

        if ($product === null) {
            return null;
        }

        $productId = (int) $product['id'];

        $product['variants'] = $this->db->select(
            'SELECT `uuid`, `sku`, `variant_name`, `weight_grams`, `shipping_weight_grams`,
                    `pack_type`, `mrp`, `selling_price`, `offer_price`, `effective_price`,
                    `discount_percentage`, `price_per_kg`, `offer_is_live`,
                    `max_order_quantity`, `is_default`
               FROM `vw_variant_pricing`
              WHERE `product_id` = :product_id
              ORDER BY `display_order` ASC, `weight_grams` ASC',
            ['product_id' => $productId]
        );

        $product['media'] = $this->db->select(
            'SELECT `uuid`, `media_type`, `file_path`, `thumbnail_path`, `external_url`,
                    `alt_text`, `caption`, `width_px`, `height_px`, `is_primary`
               FROM `product_media`
              WHERE `product_id` = :product_id AND `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `is_primary` DESC, `display_order` ASC',
            ['product_id' => $productId]
        );

        $product['nutrition'] = $this->db->selectOne(
            'SELECT `serving_size_g`, `energy_kcal`, `protein_g`, `total_fat_g`,
                    `saturated_fat_g`, `trans_fat_g`, `carbohydrate_g`, `total_sugar_g`,
                    `added_sugar_g`, `dietary_fibre_g`, `sodium_mg`, `iron_mg`,
                    `calcium_mg`, `allergen_info`
               FROM `product_nutrition`
              WHERE `product_id` = :product_id AND `is_deleted` = 0
              LIMIT 1',
            ['product_id' => $productId]
        );

        $product['attributes'] = $this->db->select(
            'SELECT `attribute_name`, `attribute_value`
               FROM `product_attributes`
              WHERE `product_id` = :product_id AND `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `display_order` ASC, `attribute_name` ASC',
            ['product_id' => $productId]
        );

        return $product;
    }

    /** @return array<int, array<string, mixed>> */
    public function relatedProducts(int $productId, int $categoryId, int $limit = 8): array
    {
        return $this->attachPrimaryMedia($this->db->select(
            sprintf(
                'SELECT %s
                 FROM `products` p
                 INNER JOIN `categories` c ON c.`id` = p.`category_id`
                 LEFT  JOIN `categories` parent ON parent.`id` = c.`parent_id`
                 INNER JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`
                 WHERE p.`category_id` = :category_id
                   AND p.`id` <> :product_id
                   AND p.`status` = \'published\'
                   AND p.`is_deleted` = 0 AND p.`is_active` = 1
                 ORDER BY p.`sold_count` DESC, p.`rating_average` DESC
                 LIMIT %d',
                self::LIST_COLUMNS,
                max(1, min($limit, 24))
            ),
            ['category_id' => $categoryId, 'product_id' => $productId]
        ));
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->existsWhere('slug', $slug, $exceptId);
    }

    public function productCodeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->existsWhere('product_code', $code, $exceptId);
    }

    /** @return array<int, string> */
    public function distinctBrands(): array
    {
        $rows = $this->db->select(
            "SELECT DISTINCT `brand` FROM `products`
              WHERE `brand` IS NOT NULL AND `brand` <> ''
                AND `status` = 'published' AND `is_deleted` = 0
              ORDER BY `brand`"
        );

        return array_column($rows, 'brand');
    }

    /**
     * Price envelope of the whole published catalog, so the UI can render a
     * filter slider without guessing its bounds.
     *
     * @return array{min_price:float, max_price:float, max_weight_grams:int}
     */
    public function priceBounds(): array
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(MIN(pr.`min_price`), 0) AS `min_price`,
                    COALESCE(MAX(pr.`max_price`), 0) AS `max_price`,
                    COALESCE(MAX(pr.`max_weight_grams`), 0) AS `max_weight_grams`
               FROM `products` p
               INNER JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`
              WHERE p.`status` = 'published' AND p.`is_deleted` = 0 AND p.`is_active` = 1"
        );

        return [
            'min_price' => (float) ($row['min_price'] ?? 0),
            'max_price' => (float) ($row['max_price'] ?? 0),
            'max_weight_grams' => (int) ($row['max_weight_grams'] ?? 0),
        ];
    }

    public function incrementViewCount(int $productId): void
    {
        // Deliberately not wrapped in the audit trail: a view is not a business
        // event, and logging every page view would swamp audit_logs.
        $this->db->execute(
            'UPDATE `products` SET `view_count` = `view_count` + 1 WHERE `id` = :id',
            ['id' => $productId]
        );
    }

    public function publish(int $productId, ?int $actorId): bool
    {
        return $this->db->execute(
            "UPDATE `products`
                SET `status` = 'published',
                    `published_date` = COALESCE(`published_date`, NOW()),
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id AND `is_deleted` = 0",
            ['actor' => $actorId, 'id' => $productId]
        ) > 0;
    }

    public function archive(int $productId, ?int $actorId): bool
    {
        return $this->db->execute(
            "UPDATE `products`
                SET `status` = 'archived',
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id AND `is_deleted` = 0",
            ['actor' => $actorId, 'id' => $productId]
        ) > 0;
    }

    /**
     * One extra query for the whole page rather than one per row.
     *
     * @param array<int, array<string, mixed>> $products
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachPrimaryMedia(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $products);
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':id' . $i, array_keys($ids)));
        $bindings = [];

        foreach ($ids as $index => $id) {
            $bindings['id' . $index] = $id;
        }

        $media = $this->db->select(
            "SELECT `product_id`, `file_path`, `thumbnail_path`, `external_url`, `alt_text`
               FROM `product_media`
              WHERE `product_id` IN ({$placeholders})
                AND `media_type` = 'image' AND `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `is_primary` DESC, `display_order` ASC",
            $bindings
        );

        $byProduct = [];

        foreach ($media as $row) {
            $byProduct[(int) $row['product_id']] ??= $row;
        }

        foreach ($products as $index => $product) {
            $products[$index]['primary_image'] = $byProduct[(int) $product['id']] ?? null;
        }

        return $products;
    }

    /**
     * FULLTEXT in boolean mode ignores tokens below MySQL's minimum word
     * length, so short terms fall back to LIKE instead of silently returning
     * nothing.
     *
     * @return array{mode:string, value:string}
     */
    private function normaliseSearchTerm(string $search): array
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $search));
        $tokens = array_values(array_filter(explode(' ', $clean), static fn (string $t): bool => $t !== ''));

        if ($tokens === []) {
            return ['mode' => 'like', 'value' => $search];
        }

        $longEnough = array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 3);

        if ($longEnough === []) {
            return ['mode' => 'like', 'value' => implode(' ', $tokens)];
        }

        // "+turmeric* +powder*" — all terms required, prefix-matched.
        $expression = implode(' ', array_map(
            static fn (string $t): string => '+' . $t . '*',
            $longEnough
        ));

        return ['mode' => 'fulltext', 'value' => $expression];
    }
}
