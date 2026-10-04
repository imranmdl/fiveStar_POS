<?php

declare(strict_types=1);

namespace App\Repositories;

final class OfferRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'offers';
    }

    protected function fillable(): array
    {
        return [
            'code', 'title', 'subtitle', 'description', 'offer_type', 'banner_image_path',
            'discount_type', 'discount_value', 'max_discount_amount', 'min_order_value', 'min_quantity',
            'buy_quantity', 'get_quantity', 'free_item_scope', 'max_free_items_per_order',
            'applies_to', 'audience', 'specific_user_id', 'channel', 'stackable_with_coupon', 'priority',
            'usage_limit', 'starts_date', 'ends_date', 'display_order', 'is_featured', 'status',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'code', 'title', 'offer_type', 'status', 'display_order', 'ends_date', 'created_date'];
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->findOneBy('code', strtoupper(trim($code)));
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->existsWhere('code', strtoupper(trim($code)), $exceptId);
    }

    /**
     * Live campaigns. The schedule window is evaluated in SQL so an expired
     * offer can never be served or applied, whatever the caller does.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liveOffers(?string $offerType = null): array
    {
        $sql = "SELECT * FROM `offers`
                 WHERE `status` = 'active' AND `is_deleted` = 0 AND `is_active` = 1
                   AND (`starts_date` IS NULL OR `starts_date` <= NOW())
                   AND (`ends_date`   IS NULL OR `ends_date`   >= NOW())";
        $bindings = [];

        if ($offerType !== null) {
            $sql .= ' AND `offer_type` = :offer_type';
            $bindings['offer_type'] = $offerType;
        }

        $sql .= ' ORDER BY `display_order` ASC, `priority` ASC';

        return $this->db->select($sql, $bindings);
    }

    /** Live campaigns that actually carry a discount, for the cart resolver. */
    public function liveDiscountingOffers(): array
    {
        return $this->db->select(
            "SELECT * FROM `offers`
              WHERE `status` = 'active' AND `is_deleted` = 0 AND `is_active` = 1
                AND `discount_type` <> 'none'
                AND (`starts_date` IS NULL OR `starts_date` <= NOW())
                AND (`ends_date`   IS NULL OR `ends_date`   >= NOW())
              ORDER BY `priority` ASC"
        );
    }

    /**
     * Live campaign offers directly targeting each of these products, one row
     * per product (highest priority wins when more than one offer targets the
     * same product). Category-wide offers are out of scope here — this only
     * answers "did someone explicitly attach an offer to this item."
     *
     * @param array<int, string> $productUuids
     *
     * @return array<string, array<string, mixed>> keyed by product uuid
     */
    public function activeOffersForProductUuids(array $productUuids): array
    {
        if ($productUuids === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach (array_values($productUuids) as $index => $uuid) {
            $key = "uuid{$index}";
            $placeholders[] = ":{$key}";
            $bindings[$key] = $uuid;
        }

        $rows = $this->db->select(
            sprintf(
                "SELECT p.`uuid` AS `product_uuid`, o.`code`, o.`title`,
                        o.`discount_type`, o.`discount_value`, o.`ends_date`,
                        o.`buy_quantity`, o.`get_quantity`
                   FROM `offer_targets` ot
                   INNER JOIN `offers` o ON o.`id` = ot.`offer_id`
                   INNER JOIN `products` p ON p.`id` = ot.`product_id`
                  WHERE ot.`target_type` = 'product' AND ot.`is_deleted` = 0 AND ot.`is_active` = 1
                    AND o.`status` = 'active' AND o.`is_deleted` = 0 AND o.`is_active` = 1
                    AND (o.`starts_date` IS NULL OR o.`starts_date` <= NOW())
                    AND (o.`ends_date`   IS NULL OR o.`ends_date`   >= NOW())
                    AND p.`uuid` IN (%s)
                  ORDER BY o.`priority` ASC",
                implode(', ', $placeholders)
            ),
            $bindings
        );

        $byProduct = [];

        foreach ($rows as $row) {
            $byProduct[$row['product_uuid']] ??= $row;
        }

        return $byProduct;
    }

    /**
     * Every other live, discounting campaign offer already targeting this one
     * product — the margin guard's "what's already stacked on this item"
     * side. Excludes the offer currently being saved, so re-saving an offer's
     * own existing target never counts it against itself.
     *
     * @return array<int, array<string, mixed>> discount_type, discount_value, buy_quantity, get_quantity
     */
    public function activeDiscountingOffersForProduct(int $productId, ?int $excludeOfferId): array
    {
        $bindings = ['product_id' => $productId];
        $excludeSql = '';

        if ($excludeOfferId !== null) {
            $excludeSql = ' AND o.`id` <> :exclude_offer_id';
            $bindings['exclude_offer_id'] = $excludeOfferId;
        }

        return $this->db->select(
            "SELECT o.`discount_type`, o.`discount_value`, o.`buy_quantity`, o.`get_quantity`
               FROM `offer_targets` ot
               INNER JOIN `offers` o ON o.`id` = ot.`offer_id`
              WHERE ot.`target_type` = 'product' AND ot.`product_id` = :product_id
                AND ot.`is_deleted` = 0 AND ot.`is_active` = 1
                AND o.`status` = 'active' AND o.`is_deleted` = 0 AND o.`is_active` = 1
                AND o.`discount_type` <> 'none'
                AND (o.`starts_date` IS NULL OR o.`starts_date` <= NOW())
                AND (o.`ends_date`   IS NULL OR o.`ends_date`   >= NOW())
                {$excludeSql}",
            $bindings
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function targetsFor(int $offerId): array
    {
        return $this->db->select(
            'SELECT `target_type`, `category_id`, `product_id`
               FROM `offer_targets`
              WHERE `offer_id` = :offer_id AND `is_deleted` = 0 AND `is_active` = 1',
            ['offer_id' => $offerId]
        );
    }

    /**
     * Same as targetsFor(), resolved to slug/name for admin display — the
     * edit UI needs something to show and pre-check, not raw ids.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolvedTargetsFor(int $offerId): array
    {
        return $this->db->select(
            "SELECT ot.`target_type`,
                    c.`slug` AS `category_slug`, c.`name` AS `category_name`,
                    p.`slug` AS `product_slug`, p.`name` AS `product_name`
               FROM `offer_targets` ot
               LEFT JOIN `categories` c ON c.`id` = ot.`category_id`
               LEFT JOIN `products` p ON p.`id` = ot.`product_id`
              WHERE ot.`offer_id` = :offer_id AND ot.`is_deleted` = 0 AND ot.`is_active` = 1",
            ['offer_id' => $offerId]
        );
    }

    /**
     * Products carried by a campaign, for a "Today's Deals" listing page.
     *
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function productsForOffer(int $offerId, string $appliesTo, array $params): array
    {
        if ($appliesTo === 'all') {
            $where = "p.`status` = 'published' AND p.`is_deleted` = 0 AND p.`is_active` = 1
                      AND pr.`has_live_offer` = 1";
            $bindings = [];
        } elseif ($appliesTo === 'categories') {
            $where = "p.`status` = 'published' AND p.`is_deleted` = 0 AND p.`is_active` = 1
                      AND (c.`id` IN (SELECT `category_id` FROM `offer_targets`
                                       WHERE `offer_id` = :offer_id AND `target_type` = 'category'
                                         AND `is_deleted` = 0)
                        OR c.`parent_id` IN (SELECT `category_id` FROM `offer_targets`
                                              WHERE `offer_id` = :offer_id_parent AND `target_type` = 'category'
                                                AND `is_deleted` = 0))";
            $bindings = ['offer_id' => $offerId, 'offer_id_parent' => $offerId];
        } else {
            $where = "p.`status` = 'published' AND p.`is_deleted` = 0 AND p.`is_active` = 1
                      AND p.`id` IN (SELECT `product_id` FROM `offer_targets`
                                      WHERE `offer_id` = :offer_id AND `target_type` = 'product'
                                        AND `is_deleted` = 0)";
            $bindings = ['offer_id' => $offerId];
        }

        $from = 'FROM `products` p
                 INNER JOIN `categories` c ON c.`id` = p.`category_id`
                 INNER JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`';

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) {$from} WHERE {$where}",
            $bindings
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT p.`id`, p.`uuid`, p.`slug`, p.`name`, p.`brand`, p.`short_description`,
                        p.`rating_average`, p.`rating_count`, p.`is_organic`, p.`is_vegetarian`, p.`is_featured`,
                        c.`slug` AS `category_slug`, c.`name` AS `category_name`,
                        pr.`min_price`, pr.`max_price`, pr.`min_mrp`, pr.`min_weight_grams`, pr.`max_weight_grams`,
                        pr.`max_discount_percentage`, pr.`has_live_offer`, pr.`variant_count`,
                        (SELECT m.`file_path` FROM `product_media` m
                          WHERE m.`product_id` = p.`id` AND m.`media_type` = \'image\'
                            AND m.`is_deleted` = 0 AND m.`is_active` = 1
                          ORDER BY m.`is_primary` DESC, m.`display_order` ASC
                          LIMIT 1) AS `primary_image_path`
                 %s WHERE %s
                 ORDER BY pr.`max_discount_percentage` DESC, p.`display_order` ASC
                 LIMIT %d OFFSET %d',
                $from,
                $where,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    public function expirePassedOffers(): int
    {
        return $this->db->execute(
            "UPDATE `offers`
                SET `status` = 'expired', `updated_date` = NOW(), `version` = `version` + 1
              WHERE `status` = 'active' AND `is_deleted` = 0
                AND `ends_date` IS NOT NULL AND `ends_date` < NOW()"
        );
    }

    public function replaceTargets(int $offerId, string $targetType, array $ids, ?int $actorId): void
    {
        $this->db->execute(
            'DELETE FROM `offer_targets` WHERE `offer_id` = :offer_id',
            ['offer_id' => $offerId]
        );

        foreach ($ids as $id) {
            $this->db->insert(
                'INSERT INTO `offer_targets`
                     (`uuid`, `offer_id`, `target_type`, `category_id`, `product_id`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :offer_id, :target_type, :category_id, :product_id,
                      :actor, NOW(), 1, 0, 1)',
                [
                    'uuid' => \App\Helpers\Uuid::v4(),
                    'offer_id' => $offerId,
                    'target_type' => $targetType,
                    'category_id' => $targetType === 'category' ? (int) $id : null,
                    'product_id' => $targetType === 'product' ? (int) $id : null,
                    'actor' => $actorId,
                ]
            );
        }
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $status = null): array
    {
        $conditions = [];

        if ($status !== null) {
            $conditions['status'] = $status;
        }

        return $this->paginateWhere($conditions, $params);
    }

    /**
     * How many times this offer has already been used, online + POS combined.
     *
     * There is no single "offer redemption" event table the way
     * coupon_redemptions exists for coupons — an offer is applied silently,
     * not entered as a code — so this is a live count across the two places
     * an application actually leaves a record: `orders.offer_id` for the
     * website/app, and `pos_sale_items.applied_offer_code` for the till (one
     * sale can carry several lines against the same offer, so sales are
     * counted DISTINCT, not lines). Cancelled orders and voided sales don't
     * count — the offer's benefit was reversed along with the sale.
     */
    public function usageCount(int $offerId, string $offerCode): int
    {
        $online = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `orders`
              WHERE `offer_id` = :offer_id AND `status` <> 'cancelled' AND `is_deleted` = 0",
            ['offer_id' => $offerId]
        );

        $pos = (int) $this->db->scalar(
            "SELECT COUNT(DISTINCT psi.`pos_sale_id`)
                 FROM `pos_sale_items` psi
                 INNER JOIN `pos_sales` ps ON ps.`id` = psi.`pos_sale_id`
                WHERE psi.`applied_offer_code` = :code
                  AND ps.`status` <> 'voided' AND ps.`is_deleted` = 0 AND psi.`is_deleted` = 0",
            ['code' => $offerCode]
        );

        return $online + $pos;
    }
}
