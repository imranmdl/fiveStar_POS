<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The price-change audit trail (brief §7). Only `create()` (inherited from
 * BaseRepository) is ever used to write — `trg_price_change_log_immutable`
 * blocks every UPDATE at the database layer regardless of what application
 * code does, the same guarantee InventoryMovementRepository relies on.
 */
final class PriceChangeLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'price_change_log';
    }

    protected function fillable(): array
    {
        return [
            'product_variant_id', 'reference_type', 'reference_id',
            'old_selling_price', 'new_selling_price', 'purchase_price', 'average_cost',
            'decision', 'pricing_rule_id', 'reason', 'performed_by',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forVariant(int $variantId): array
    {
        return $this->db->select(
            'SELECT * FROM `price_change_log` WHERE `product_variant_id` = :variant_id
              ORDER BY `created_date` DESC, `id` DESC',
            ['variant_id' => $variantId]
        );
    }
}
