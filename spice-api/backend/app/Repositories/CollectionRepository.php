<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

/**
 * Campaign collections and the products curated into them.
 */
final class CollectionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'collections';
    }

    /**
     * @return array<int, string>
     */
    protected function fillable(): array
    {
        return [
            'uuid', 'slug', 'title', 'subtitle', 'intro', 'template',
            'hero_image_path', 'hero_alt_text', 'cta_label', 'status',
            'starts_date', 'ends_date', 'display_order', 'view_count',
            'meta_title', 'meta_description',
            'created_by', 'updated_by', 'deleted_by', 'deleted_date',
            'is_active', 'is_deleted',
        ];
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `collections` WHERE `slug` = :slug AND `is_deleted` = 0 LIMIT 1',
            ['slug' => $slug]
        );
    }

    /**
     * A collection is live when it is published AND inside its dates.
     *
     * The date check is here rather than in the service so that every caller
     * gets it — a campaign page that outlives its season is the failure this
     * feature is most likely to produce.
     */
    public function findLiveBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM `collections`
              WHERE `slug` = :slug
                AND `status` = 'published'
                AND `is_active` = 1
                AND `is_deleted` = 0
                AND (`starts_date` IS NULL OR `starts_date` <= NOW())
                AND (`ends_date` IS NULL OR `ends_date` >= NOW())
              LIMIT 1",
            ['slug' => $slug]
        );
    }

    /**
     * Every live campaign page, for a home-page strip — festival gift
     * collections and the like. Same "live" definition findLiveBySlug() uses,
     * ordered so a merchant can put the current festival first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLive(): array
    {
        return $this->db->select(
            "SELECT * FROM `collections`
              WHERE `status` = 'published'
                AND `is_active` = 1
                AND `is_deleted` = 0
                AND (`starts_date` IS NULL OR `starts_date` <= NOW())
                AND (`ends_date` IS NULL OR `ends_date` >= NOW())
              ORDER BY `display_order` ASC, `created_date` DESC
              LIMIT 12"
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(?string $status = null): array
    {
        $sql = 'SELECT * FROM `vw_collection_summary`';
        $bindings = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE `status` = :status';
            $bindings['status'] = $status;
        }

        return $this->db->select($sql . ' ORDER BY `display_order`, `id` DESC', $bindings);
    }

    /**
     * The products on a page, in the order the merchant put them.
     *
     * Unpublished products are excluded for shoppers but kept for staff, so the
     * console can show "3 of 12 are not on sale" rather than silently dropping
     * them and leaving the merchant wondering where they went.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(int $collectionId, bool $publishedOnly = true): array
    {
        $condition = $publishedOnly ? "AND p.`status` = 'published'" : '';

        return $this->db->select(
            "SELECT ci.`uuid` AS `item_uuid`, ci.`headline`, ci.`display_order`,
                    p.`id` AS `product_id`, p.`uuid` AS `product_uuid`, p.`slug`,
                    p.`name`, p.`short_description`, p.`status`,
                    p.`rating_average`, p.`rating_count`, p.`is_organic`
               FROM `collection_items` ci
               INNER JOIN `products` p ON p.`id` = ci.`product_id` AND p.`is_deleted` = 0
              WHERE ci.`collection_id` = :id AND ci.`is_deleted` = 0 {$condition}
              ORDER BY ci.`display_order`, ci.`id`",
            ['id' => $collectionId]
        );
    }

    public function addItem(int $collectionId, int $productId, ?string $headline, int $order, ?int $staffId): string
    {
        $uuid = Uuid::v4();

        // A product added twice is always a mistake; the unique index refuses it
        // and this turns the refusal into a harmless no-op that also updates the
        // position, which is what someone dragging a row actually meant.
        $this->db->execute(
            'INSERT INTO `collection_items`
                 (`uuid`, `collection_id`, `product_id`, `headline`, `display_order`, `created_by`)
             VALUES (:uuid, :collection, :product, :headline, :order, :staff)
             ON DUPLICATE KEY UPDATE
                 `headline` = VALUES(`headline`),
                 `display_order` = VALUES(`display_order`),
                 `updated_by` = VALUES(`created_by`),
                 `is_deleted` = 0',
            [
                'uuid' => $uuid,
                'collection' => $collectionId,
                'product' => $productId,
                'headline' => $headline,
                'order' => $order,
                'staff' => $staffId,
            ]
        );

        return $uuid;
    }

    public function removeItem(string $itemUuid, ?int $staffId): bool
    {
        return $this->db->execute(
            'UPDATE `collection_items`
                SET `is_deleted` = 1, `deleted_by` = :staff, `deleted_date` = NOW()
              WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $itemUuid, 'staff' => $staffId]
        ) > 0;
    }

    public function recordView(int $collectionId): void
    {
        // Fire and forget; a view counter is never worth failing a page for.
        try {
            $this->db->execute(
                'UPDATE `collections` SET `view_count` = `view_count` + 1 WHERE `id` = :id',
                ['id' => $collectionId]
            );
        } catch (\Throwable) {
            // Ignored deliberately.
        }
    }
}
