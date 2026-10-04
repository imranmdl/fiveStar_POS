<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductMediaRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_media';
    }

    protected function fillable(): array
    {
        return [
            'product_id', 'variant_id', 'media_type', 'file_path', 'thumbnail_path',
            'external_url', 'alt_text', 'caption', 'width_px', 'height_px',
            'file_size_bytes', 'mime_type', 'is_primary', 'display_order',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forProduct(int $productId): array
    {
        return $this->db->select(
            'SELECT * FROM `product_media`
              WHERE `product_id` = :product_id AND `is_deleted` = 0
              ORDER BY `is_primary` DESC, `display_order` ASC',
            ['product_id' => $productId]
        );
    }

    public function clearPrimaryFlag(int $productId, ?int $exceptMediaId = null): void
    {
        $sql = 'UPDATE `product_media`
                   SET `is_primary` = 0, `updated_date` = NOW(), `version` = `version` + 1
                 WHERE `product_id` = :product_id AND `is_deleted` = 0';
        $bindings = ['product_id' => $productId];

        if ($exceptMediaId !== null) {
            $sql .= ' AND `id` <> :except_id';
            $bindings['except_id'] = $exceptMediaId;
        }

        $this->db->execute($sql, $bindings);
    }

    public function ensurePrimaryExists(int $productId): void
    {
        $hasPrimary = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `product_media`
              WHERE `product_id` = :product_id AND `is_primary` = 1
                AND `media_type` = 'image' AND `is_deleted` = 0",
            ['product_id' => $productId]
        );

        if ($hasPrimary > 0) {
            return;
        }

        $this->db->execute(
            "UPDATE `product_media`
                SET `is_primary` = 1, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `product_id` = :product_id AND `media_type` = 'image' AND `is_deleted` = 0
              ORDER BY `display_order` ASC
              LIMIT 1",
            ['product_id' => $productId]
        );
    }

    public function countImagesForProduct(int $productId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `product_media`
              WHERE `product_id` = :product_id AND `media_type` = 'image' AND `is_deleted` = 0",
            ['product_id' => $productId]
        );
    }
}
