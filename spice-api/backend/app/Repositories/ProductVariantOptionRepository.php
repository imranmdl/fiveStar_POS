<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductVariantOptionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_variant_options';
    }

    protected function fillable(): array
    {
        return ['product_variant_id', 'option_type_id', 'option_value_id'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forVariant(int $variantId): array
    {
        return $this->db->select(
            'SELECT pvo.*, t.`code` AS `option_type_code`, t.`name` AS `option_type_name`,
                    v.`value` AS `option_value`
               FROM `product_variant_options` pvo
               INNER JOIN `variant_option_types` t ON t.`id` = pvo.`option_type_id`
               INNER JOIN `variant_option_values` v ON v.`id` = pvo.`option_value_id`
              WHERE pvo.`product_variant_id` = :variant_id AND pvo.`is_deleted` = 0
              ORDER BY t.`display_order` ASC',
            ['variant_id' => $variantId]
        );
    }

    /**
     * Which of these products have at least one variant carrying a "size"
     * dimension value — the signal a catalog/collection card uses to skip
     * the weight pill and per-kg price, which make no sense for a sized
     * item. One batched query rather than one per product.
     *
     * @param array<int, int> $productIds
     *
     * @return array<int, int>
     */
    public function productIdsWithSizeOptions(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $productIds = array_values($productIds);
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':id' . $i, array_keys($productIds)));
        $bindings = [];

        foreach ($productIds as $index => $id) {
            $bindings['id' . $index] = $id;
        }

        $rows = $this->db->select(
            "SELECT DISTINCT pv.`product_id`
               FROM `product_variant_options` pvo
               INNER JOIN `product_variants` pv ON pv.`id` = pvo.`product_variant_id`
               INNER JOIN `variant_option_types` t ON t.`id` = pvo.`option_type_id` AND t.`code` = 'size'
              WHERE pv.`product_id` IN ({$placeholders}) AND pvo.`is_deleted` = 0",
            $bindings
        );

        return array_map(static fn (array $row): int => (int) $row['product_id'], $rows);
    }

    /**
     * Replaces the whole dimension set for a variant (e.g. Size=L, Colour=Red).
     * Soft-deleting first keeps the audit trail intact rather than silently
     * mutating rows — same technique as ProductAttributeRepository.
     *
     * @param array<int, array{option_type_id:int, option_value_id:int}> $options
     */
    public function replaceForVariant(int $variantId, array $options, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `product_variant_options`
                SET `is_deleted` = 1, `deleted_by` = :actor, `deleted_date` = NOW(),
                    `version` = `version` + 1
              WHERE `product_variant_id` = :variant_id AND `is_deleted` = 0',
            ['actor' => $actorId, 'variant_id' => $variantId]
        );

        foreach ($options as $option) {
            // A prior soft-deleted row holds the unique (variant, type) and
            // (variant, value) slots, so clear it out before re-inserting.
            $this->db->execute(
                'DELETE FROM `product_variant_options`
                  WHERE `product_variant_id` = :variant_id
                    AND (`option_type_id` = :type_id OR `option_value_id` = :value_id)
                    AND `is_deleted` = 1',
                [
                    'variant_id' => $variantId,
                    'type_id' => $option['option_type_id'],
                    'value_id' => $option['option_value_id'],
                ]
            );

            $this->create([
                'product_variant_id' => $variantId,
                'option_type_id' => $option['option_type_id'],
                'option_value_id' => $option['option_value_id'],
            ], $actorId);
        }
    }
}
