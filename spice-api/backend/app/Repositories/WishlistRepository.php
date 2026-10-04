<?php

declare(strict_types=1);

namespace App\Repositories;

final class WishlistRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'wishlist_items';
    }

    protected function fillable(): array
    {
        return [
            'user_id', 'product_id', 'preferred_variant_id', 'notify_on_offer',
            'notify_on_price_drop', 'price_at_add', 'notes',
        ];
    }

    /**
     * Wishlist with live pricing, so a price-drop badge can be rendered without
     * a second round trip.
     *
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForUser(int $userId, array $params): array
    {
        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `wishlist_items`
              WHERE `user_id` = :user_id AND `is_deleted` = 0',
            ['user_id' => $userId]
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT w.`uuid`, w.`notify_on_offer`, w.`notify_on_price_drop`,
                        w.`price_at_add`, w.`notes`, w.`created_date`,
                        p.`uuid` AS `product_uuid`, p.`slug` AS `product_slug`,
                        p.`name` AS `product_name`, p.`brand`, p.`status` AS `product_status`,
                        p.`rating_average`, p.`rating_count`,
                        pr.`min_price`, pr.`max_price`, pr.`min_mrp`,
                        pr.`max_discount_percentage`, pr.`has_live_offer`,
                        v.`uuid` AS `preferred_variant_uuid`, v.`variant_name` AS `preferred_variant_name`,
                        (SELECT m.`file_path` FROM `product_media` m
                          WHERE m.`product_id` = p.`id` AND m.`media_type` = \'image\'
                            AND m.`is_deleted` = 0 AND m.`is_active` = 1
                          ORDER BY m.`is_primary` DESC, m.`display_order` ASC
                          LIMIT 1) AS `primary_image_path`
                 FROM `wishlist_items` w
                 INNER JOIN `products` p ON p.`id` = w.`product_id`
                 LEFT  JOIN `vw_product_price_range` pr ON pr.`product_id` = p.`id`
                 LEFT  JOIN `product_variants` v ON v.`id` = w.`preferred_variant_id`
                 WHERE w.`user_id` = :user_id AND w.`is_deleted` = 0
                 ORDER BY w.`created_date` DESC
                 LIMIT %d OFFSET %d',
                $params['per_page'],
                $params['offset']
            ),
            ['user_id' => $userId]
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Includes soft-deleted rows: UNIQUE (user_id, product_id) means a removed
     * entry still holds the slot and must be revived rather than re-inserted.
     *
     * @return array<string, mixed>|null
     */
    public function findForUserAndProduct(int $userId, int $productId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `wishlist_items`
              WHERE `user_id` = :user_id AND `product_id` = :product_id
              LIMIT 1',
            ['user_id' => $userId, 'product_id' => $productId]
        );
    }

    public function revive(int $itemId, ?int $preferredVariantId, ?string $priceAtAdd, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `wishlist_items`
                SET `preferred_variant_id` = :variant_id,
                    `price_at_add` = :price_at_add,
                    `is_deleted` = 0, `deleted_by` = NULL, `deleted_date` = NULL,
                    `is_active` = 1,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'variant_id' => $preferredVariantId,
                'price_at_add' => $priceAtAdd,
                'actor' => $actorId,
                'id' => $itemId,
            ]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `wishlist_items`
              WHERE `user_id` = :user_id AND `is_deleted` = 0',
            ['user_id' => $userId]
        );
    }

    public function existsForUserAndProduct(int $userId, int $productId): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM `wishlist_items`
              WHERE `user_id` = :user_id AND `product_id` = :product_id AND `is_deleted` = 0
              LIMIT 1',
            ['user_id' => $userId, 'product_id' => $productId]
        ) !== null;
    }
}
