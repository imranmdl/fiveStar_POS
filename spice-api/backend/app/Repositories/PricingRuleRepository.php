<?php

declare(strict_types=1);

namespace App\Repositories;

final class PricingRuleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'pricing_rules';
    }

    protected function fillable(): array
    {
        return [
            'name', 'scope', 'category_id', 'product_id', 'product_variant_id',
            'calculation', 'rate', 'tax_mode', 'priority', 'status',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'scope', 'priority', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT r.*, c.`uuid` AS `category_uuid`, c.`name` AS `category_name`,
                    p.`uuid` AS `product_uuid`, p.`name` AS `product_name`,
                    v.`uuid` AS `variant_uuid`, v.`sku` AS `variant_sku`, v.`variant_name`
               FROM `pricing_rules` r
               LEFT JOIN `categories` c ON c.`id` = r.`category_id`
               LEFT JOIN `products` p ON p.`id` = r.`product_id`
               LEFT JOIN `product_variants` v ON v.`id` = r.`product_variant_id`
              WHERE r.`is_deleted` = 0
              ORDER BY r.`scope` DESC, r.`priority` ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public function detailByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT r.*, c.`uuid` AS `category_uuid`, c.`name` AS `category_name`,
                    p.`uuid` AS `product_uuid`, p.`name` AS `product_name`,
                    v.`uuid` AS `variant_uuid`, v.`sku` AS `variant_sku`, v.`variant_name`
               FROM `pricing_rules` r
               LEFT JOIN `categories` c ON c.`id` = r.`category_id`
               LEFT JOIN `products` p ON p.`id` = r.`product_id`
               LEFT JOIN `product_variants` v ON v.`id` = r.`product_variant_id`
              WHERE r.`uuid` = :uuid AND r.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * Most-specific-wins resolution: a variant-scoped rule beats a
     * product-scoped rule, which beats a category-scoped rule, which beats
     * the global default. `priority` (lowest wins) breaks ties within the
     * same specificity — the same two-step resolution
     * commission_rules-driven commission calculation uses.
     *
     * @return array<string, mixed>|null
     */
    public function resolveForVariant(int $variantId, int $productId, int $categoryId): ?array
    {
        return $this->db->selectOne(
            "SELECT *,
                    CASE `scope`
                        WHEN 'variant'  THEN 4
                        WHEN 'product'  THEN 3
                        WHEN 'category' THEN 2
                        ELSE 1
                    END AS `specificity`
               FROM `pricing_rules`
              WHERE `status` = 'active' AND `is_deleted` = 0
                AND (
                    (`scope` = 'variant'  AND `product_variant_id` = :variant_id) OR
                    (`scope` = 'product'  AND `product_id` = :product_id) OR
                    (`scope` = 'category' AND `category_id` = :category_id) OR
                    (`scope` = 'global')
                )
              ORDER BY `specificity` DESC, `priority` ASC
              LIMIT 1",
            ['variant_id' => $variantId, 'product_id' => $productId, 'category_id' => $categoryId]
        );
    }
}
