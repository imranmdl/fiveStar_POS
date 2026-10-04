<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductAttributeRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_attributes';
    }

    protected function fillable(): array
    {
        return ['product_id', 'attribute_name', 'attribute_value', 'display_order'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forProduct(int $productId): array
    {
        return $this->db->select(
            'SELECT * FROM `product_attributes`
              WHERE `product_id` = :product_id AND `is_deleted` = 0
              ORDER BY `display_order` ASC',
            ['product_id' => $productId]
        );
    }

    /**
     * Replaces the whole attribute set for a product. Soft-deleting first keeps
     * the audit trail intact rather than silently mutating rows.
     *
     * @param array<int, array{attribute_name:string, attribute_value:string}> $attributes
     */
    public function replaceForProduct(int $productId, array $attributes, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `product_attributes`
                SET `is_deleted` = 1, `deleted_by` = :actor, `deleted_date` = NOW(),
                    `version` = `version` + 1
              WHERE `product_id` = :product_id AND `is_deleted` = 0',
            ['actor' => $actorId, 'product_id' => $productId]
        );

        $order = 10;

        foreach ($attributes as $attribute) {
            // A prior soft-deleted row holds the unique (product, name) slot, so
            // clear it out before re-inserting.
            $this->db->execute(
                'DELETE FROM `product_attributes`
                  WHERE `product_id` = :product_id AND `attribute_name` = :name AND `is_deleted` = 1',
                ['product_id' => $productId, 'name' => $attribute['attribute_name']]
            );

            $this->create([
                'product_id' => $productId,
                'attribute_name' => $attribute['attribute_name'],
                'attribute_value' => $attribute['attribute_value'],
                'display_order' => $order,
            ], $actorId);

            $order += 10;
        }
    }
}
