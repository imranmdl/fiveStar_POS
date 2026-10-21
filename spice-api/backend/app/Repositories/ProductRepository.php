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
            pr.`max_discount_percentage`, pr.`has_live_offer`,
            (SELECT MIN(lv.`volume_ml`) FROM `product_variants` lv
              WHERE lv.`product_id` = p.`id` AND lv.`is_deleted` = 0 AND lv.`is_active` = 1
                AND lv.`stock_unit_type` = \'volume\' AND lv.`volume_ml` > 0) AS `min_volume_ml`';

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
            'admin_reviewed', 'review_reason', 'review_flagged_date', 'reviewed_by', 'reviewed_date',
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
        $result = $this->searchAt($filters, $params, 0);

        if ($result['total'] > 0 || $params['search'] === null || trim((string) $params['search']) === '') {
            return $result;
        }

        // Nothing for the exact words. A shopper typing in the search box
        // means "find me this", so loosen step by step: every word, matched
        // anywhere (singular/plural alike) → any of the words → a close
        // spelling ("cardamon" → Cardamom).
        foreach ([1, 2, 3] as $level) {
            $result = $this->searchAt($filters, $params, $level);

            if ($result['total'] > 0) {
                return $result + ['search_fallback' => $level];
            }
        }

        return $result;
    }

    /** Words a search box carries that say nothing about the product. */
    private const SEARCH_STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'for', 'with', 'in', 'on', 'to', 'buy', 'online', 'best', 'pure',
        'fresh', 'premium', 'price', 'pack', 'packet', 'gm', 'gms', 'g', 'kg', 'grams', 'gram', 'ml', 'ltr', 'litre',
    ];

    /**
     * Search words, cleaned: punctuation and numbers-with-units removed,
     * filler words dropped, plurals reduced ("almonds" → "almond").
     *
     * @return array<int, string>
     */
    private function searchWords(string $search): array
    {
        $clean = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $search)));
        $words = [];

        foreach (preg_split('/\s+/u', $clean) ?: [] as $word) {
            if ($word === '' || in_array($word, self::SEARCH_STOPWORDS, true) || preg_match('/^\d+[a-z]*$/u', $word) === 1) {
                continue;
            }

            if (mb_strlen($word) > 4 && str_ends_with($word, 'es') && !str_ends_with($word, 'ses')) {
                $word = mb_substr($word, 0, -2);
            } elseif (mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
                $word = mb_substr($word, 0, -1);
            }

            $words[] = $word;
        }

        return array_values(array_unique($words));
    }

    /**
     * Published products whose name is a close spelling of the search words
     * (each word within a couple of letters of a word in the name).
     *
     * @param array<int, string> $words
     *
     * @return array<int, int> product ids, best first
     */
    private function fuzzyMatches(array $words, bool $includeUnpublished = false): array
    {
        if ($words === []) {
            return [];
        }

        $rows = $this->db->select(
            "SELECT p.`id`, p.`name`, c.`name` AS `category_name`
               FROM `products` p INNER JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE p.`is_deleted` = 0 AND p.`is_active` = 1" . ($includeUnpublished ? '' : " AND p.`status` = 'published'") . "
              LIMIT 5000"
        );
        $scores = [];

        foreach ($rows as $row) {
            $nameWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($row['name'] . ' ' . $row['category_name'])) ?: [];
            $total = 0;
            $matched = 0;

            foreach ($words as $word) {
                $best = PHP_INT_MAX;
                foreach ($nameWords as $candidate) {
                    if ($candidate === '' || mb_strlen($candidate) < 3) {
                        continue;
                    }
                    // Compare against the start of longer words too ("cardam" ~ "cardamom").
                    $slice = mb_substr($candidate, 0, max(mb_strlen($word), 3));
                    $best = min($best, levenshtein($word, $candidate), levenshtein($word, $slice));
                }
                $allowed = mb_strlen($word) <= 4 ? 1 : 2;
                if ($best <= $allowed) {
                    ++$matched;
                    $total += $best;
                }
            }

            if ($matched > 0) {
                $scores[(int) $row['id']] = [$matched, -$total];
            }
        }

        uasort($scores, static fn (array $a, array $b): int => $b <=> $a);

        return array_slice(array_keys($scores), 0, 60);
    }

    /**
     * @param int $level 0 exact (full-text) · 1 every word anywhere · 2 any word · 3 close spelling
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function searchAt(array $filters, array $params, int $level): array
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

        if ($params['search'] !== null && $level > 0) {
            $words = $this->searchWords((string) $params['search']);

            if ($level === 3) {
                $ids = $this->fuzzyMatches($words, ($filters['include_unpublished'] ?? false) === true);
                if ($ids === []) {
                    return ['items' => [], 'total' => 0];
                }
                $where[] = 'p.`id` IN (' . implode(',', $ids) . ')';
                if (($filters['sort'] ?? 'relevance') === 'relevance') {
                    $orderBy = 'FIELD(p.`id`, ' . implode(',', $ids) . ')';
                }
            } else {
                if ($words === []) {
                    return ['items' => [], 'total' => 0];
                }
                $parts = [];
                foreach ($words as $i => $word) {
                    $like = '%' . $word . '%';
                    $parts[] = "(p.`name` LIKE :w{$i}a OR p.`search_keywords` LIKE :w{$i}b OR p.`short_description` LIKE :w{$i}c
                                 OR p.`brand` LIKE :w{$i}d OR c.`name` LIKE :w{$i}e OR parent.`name` LIKE :w{$i}f
                                 OR EXISTS (SELECT 1 FROM `product_variants` wv
                                             WHERE wv.`product_id` = p.`id` AND wv.`is_deleted` = 0
                                               AND (wv.`variant_name` LIKE :w{$i}g OR wv.`sku` LIKE :w{$i}h OR wv.`barcode` LIKE :w{$i}i)))";
                    foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'] as $suffix) {
                        $bindings["w{$i}{$suffix}"] = $like;
                    }
                }
                $where[] = '(' . implode($level === 1 ? ' AND ' : ' OR ', $parts) . ')';
            }
        } elseif ($params['search'] !== null) {
            $term = $this->normaliseSearchTerm($params['search']);
            $rawLike = '%' . trim((string) $params['search']) . '%';

            // A search box in this catalog reasonably means "find me this
            // thing, however I typed it" — the category it's filed under, or
            // the exact pack (SKU/pack name), not just the product's own
            // name/description. Added as extra OR terms alongside whichever
            // primary match (fulltext or LIKE) the term qualifies for below.
            $sideMatch = 'OR c.`name` LIKE :search_category
                          OR parent.`name` LIKE :search_category_parent
                          OR EXISTS (SELECT 1 FROM `product_variants` v
                                      WHERE v.`product_id` = p.`id` AND v.`is_deleted` = 0
                                        AND (v.`sku` LIKE :search_variant_sku
                                             OR v.`variant_name` LIKE :search_variant_name))';
            $bindings['search_category'] = $rawLike;
            $bindings['search_category_parent'] = $rawLike;
            $bindings['search_variant_sku'] = $rawLike;
            $bindings['search_variant_name'] = $rawLike;

            if ($term['mode'] === 'fulltext') {
                $where[] = "(MATCH (p.`name`, p.`short_description`, p.`search_keywords`)
                            AGAINST (:search_where IN BOOLEAN MODE) {$sideMatch})";
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
                $where[] = "(p.`name` LIKE :search_like
                             OR p.`search_keywords` LIKE :search_like_keywords
                             OR p.`product_code` LIKE :search_like_code
                             {$sideMatch})";
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
            'SELECT vp.`id`, vp.`uuid`, vp.`sku`, v.`barcode`, v.`expiry_date`, vp.`variant_name`,
                    vp.`weight_grams`, vp.`shipping_weight_grams`,
                    vp.`pack_type`, vp.`mrp`, vp.`selling_price`, vp.`offer_price`, vp.`effective_price`,
                    vp.`discount_percentage`, vp.`price_per_kg`, vp.`offer_is_live`,
                    vp.`max_order_quantity`, vp.`is_default`,
                    v.`stock_unit_type`, v.`unit_label`, v.`volume_ml`
               FROM `vw_variant_pricing` vp
               INNER JOIN `product_variants` v ON v.`id` = vp.`id`
              WHERE vp.`product_id` = :product_id
              ORDER BY vp.`display_order` ASC, vp.`weight_grams` ASC',
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

    /**
     * Published products across a whole category tree (e.g. "Spices" and
     * its "Whole Spices"/"Ground Spices" children) — the candidate pool for
     * the product page's "same variety" / "other varieties" split.
     * Carries `search_keywords` alongside the usual list columns; that's
     * the "what variety is this" signal ProductService matches on, since
     * there's no dedicated variety field. Never exposed as-is — only
     * `presentListItem()`'s explicit whitelist reaches the API.
     *
     * @param array<int, int> $categoryIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function productsInCategoryTree(array $categoryIds, int $excludeProductId, int $limit = 200): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $categoryIds = array_values($categoryIds);
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':cat' . $i, array_keys($categoryIds)));
        $bindings = ['exclude_id' => $excludeProductId];

        foreach ($categoryIds as $index => $id) {
            $bindings['cat' . $index] = $id;
        }

        return $this->attachPrimaryMedia($this->db->select(
            sprintf(
                'SELECT %s, p.`search_keywords`
                 FROM `products` p
                 INNER JOIN `categories` c ON c.`id` = p.`category_id`
                 INNER JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`
                 WHERE p.`category_id` IN (%s)
                   AND p.`id` <> :exclude_id
                   AND p.`status` = \'published\'
                   AND p.`is_deleted` = 0 AND p.`is_active` = 1
                 ORDER BY p.`sold_count` DESC, p.`rating_average` DESC
                 LIMIT %d',
                self::LIST_COLUMNS,
                $placeholders,
                max(1, min($limit, 500))
            ),
            $bindings
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
