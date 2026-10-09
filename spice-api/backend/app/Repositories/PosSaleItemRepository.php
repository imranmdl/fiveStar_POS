<?php

declare(strict_types=1);

namespace App\Repositories;

final class PosSaleItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'pos_sale_items';
    }

    protected function fillable(): array
    {
        return [
            'pos_sale_id', 'product_variant_id', 'sku', 'product_name', 'variant_name',
            'quantity', 'unit_price', 'mrp', 'discount_amount', 'applied_offer_code', 'gst_rate', 'tax_amount', 'line_total',
            'refunded_quantity',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forSale(int $saleId): array
    {
        return $this->db->select(
            // MRP falls back to the pack's current MRP for lines sold before
            // migration 056 recorded it on the line.
            'SELECT i.*, COALESCE(i.`mrp`, v.`mrp`) AS `mrp`
               FROM `pos_sale_items` i
               LEFT JOIN `product_variants` v ON v.`id` = i.`product_variant_id`
              WHERE i.`pos_sale_id` = :sale_id
              ORDER BY i.`id` ASC',
            ['sale_id' => $saleId]
        );
    }

    /** Adds to the running refunded_quantity — never decreases it, matching the append-only spirit of every other ledger figure in this system. */
    public function addRefundedQuantity(int $itemId, float $quantity, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `pos_sale_items`
                SET `refunded_quantity` = `refunded_quantity` + :qty,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['qty' => $quantity, 'actor' => $actorId, 'id' => $itemId]
        );
    }
}
