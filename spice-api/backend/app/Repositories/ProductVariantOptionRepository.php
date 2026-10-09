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

    /**
     * Options for many variants at once, keyed by variant id:
     * [variantId => [code => ['type' => 'Size', 'value' => 'M']]], dimensions
     * in their display order.
     *
     * @param array<int, int> $variantIds
     *
     * @return array<int, array<string, array{type: string, value: string, type_order: int, value_order: int}>>
     */
    public function forVariants(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));

        if ($variantIds === []) {
            return [];
        }

        $bindings = [];
        $placeholders = [];

        foreach ($variantIds as $index => $id) {
            $placeholders[] = ':v' . $index;
            $bindings['v' . $index] = $id;
        }

        $rows = $this->db->select(
            'SELECT pvo.`product_variant_id`, t.`code`, t.`name` AS `type_name`, t.`display_order` AS `type_order`,
                    v.`value`, v.`display_order` AS `value_order`
               FROM `product_variant_options` pvo
               INNER JOIN `variant_option_types` t ON t.`id` = pvo.`option_type_id`
               INNER JOIN `variant_option_values` v ON v.`id` = pvo.`option_value_id`
              WHERE pvo.`product_variant_id` IN (' . implode(', ', $placeholders) . ') AND pvo.`is_deleted` = 0
              ORDER BY t.`display_order` ASC, v.`display_order` ASC',
            $bindings
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['product_variant_id']][(string) $row['code']] = [
                'type' => (string) $row['type_name'],
                'value' => (string) $row['value'],
                'type_order' => (int) $row['type_order'],
                'value_order' => (int) $row['value_order'],
            ];
        }

        return $map;
    }

    /**
     * Products a shopper must pick a variant for before buying, rather than
     * having one added for them: more than one active variant, and either a
     * size / colour on some variant, or a clothing / footwear category
     * (item_type is set on the top-level category and inherited).
     *
     * @param array<int, int> $productIds
     *
     * @return array<int, int>
     */
    public function productIdsRequiringChoice(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $bindings = [];
        $placeholders = [];

        foreach ($productIds as $index => $id) {
            $placeholders[] = ':p' . $index;
            $bindings['p' . $index] = $id;
        }

        $rows = $this->db->select(
            "SELECT p.`id`
               FROM `products` p
               LEFT JOIN `categories` c  ON c.`id`  = p.`category_id`
               LEFT JOIN `categories` c1 ON c1.`id` = c.`parent_id`
               LEFT JOIN `categories` c2 ON c2.`id` = c1.`parent_id`
              WHERE p.`id` IN (" . implode(', ', $placeholders) . ")
                AND (SELECT COUNT(*) FROM `product_variants` pv
                      WHERE pv.`product_id` = p.`id` AND pv.`is_deleted` = 0 AND pv.`is_active` = 1) > 1
                AND (
                    COALESCE(c.`item_type`, c1.`item_type`, c2.`item_type`) IN ('clothing', 'footwear')
                    OR EXISTS (
                        SELECT 1 FROM `product_variant_options` pvo
                          JOIN `product_variants` pv2 ON pv2.`id` = pvo.`product_variant_id`
                          JOIN `variant_option_types` t ON t.`id` = pvo.`option_type_id`
                         WHERE pv2.`product_id` = p.`id` AND pvo.`is_deleted` = 0
                           AND t.`code` IN ('size', 'color')
                    )
                )",
            $bindings
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
