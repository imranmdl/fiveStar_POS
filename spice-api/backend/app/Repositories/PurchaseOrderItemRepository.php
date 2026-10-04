<?php

declare(strict_types=1);

namespace App\Repositories;

final class PurchaseOrderItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'purchase_order_items';
    }

    protected function fillable(): array
    {
        return [
            'purchase_order_id', 'product_variant_id', 'quantity', 'invoiced_quantity', 'unit_cost', 'landing_cost',
            'mrp', 'selling_price', 'gst_rate', 'gst_amount', 'discount_amount',
            'line_subtotal', 'batch_no', 'expiry_date',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $purchaseOrderId): array
    {
        return $this->db->select(
            'SELECT poi.*, pv.`sku`, pv.`variant_name`, pv.`barcode`, pv.`uuid` AS `variant_uuid`,
                    p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`status` AS `product_status`
               FROM `purchase_order_items` poi
               INNER JOIN `product_variants` pv ON pv.`id` = poi.`product_variant_id`
               INNER JOIN `products` p ON p.`id` = pv.`product_id`
              WHERE poi.`purchase_order_id` = :po_id AND poi.`is_deleted` = 0
              ORDER BY poi.`id` ASC',
            ['po_id' => $purchaseOrderId]
        );
    }

    /**
     * Every inward line for one variant, newest first — vendor, PO, date,
     * cost. This is the "new vs old" trace that works regardless of whether
     * batch_no was ever filled in: unlike inventory_batches (opportunistic,
     * only written when a movement carries a batch), a purchase order line
     * always exists once the purchase was recorded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function purchaseHistoryForVariant(int $variantId): array
    {
        return $this->db->select(
            'SELECT poi.`quantity`, poi.`invoiced_quantity`, poi.`unit_cost`, poi.`landing_cost`, poi.`batch_no`, poi.`expiry_date`,
                    po.`uuid` AS `purchase_order_uuid`, po.`po_number`, po.`purchase_date`, po.`invoice_reference`,
                    v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`
               FROM `purchase_order_items` poi
               INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id`
               INNER JOIN `vendors` v ON v.`id` = po.`vendor_id`
              WHERE poi.`product_variant_id` = :variant_id AND poi.`is_deleted` = 0
              ORDER BY po.`purchase_date` DESC, poi.`id` DESC',
            ['variant_id' => $variantId]
        );
    }

    /**
     * Resolves which vendor/PO a batch of stock came from, for the
     * damage/loss traceability views — matched on (product_variant_id,
     * batch_no), the same pair inventory_batches itself keys on. batch_no is
     * a free-text column, not a foreign key, so this is a best-effort string
     * match, not a guarantee; a variant with several distinct variantId
     * lookups is done in one query rather than one per row.
     *
     * @param array<int, int> $variantIds
     *
     * @return array<int, array<string, mixed>> Every batch-carrying line for these variants; caller matches by (product_variant_id, batch_no).
     */
    public function findBatchSourcesForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach (array_values($variantIds) as $index => $variantId) {
            $key = 'variant_id_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $variantId;
        }

        return $this->db->select(
            sprintf(
                'SELECT poi.`product_variant_id`, poi.`batch_no`,
                        po.`uuid` AS `purchase_order_uuid`, po.`po_number`, po.`purchase_date`,
                        v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`
                   FROM `purchase_order_items` poi
                   INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id`
                   INNER JOIN `vendors` v ON v.`id` = po.`vendor_id`
                  WHERE poi.`product_variant_id` IN (%s)
                    AND poi.`batch_no` IS NOT NULL AND poi.`is_deleted` = 0
                  ORDER BY po.`purchase_date` DESC',
                implode(', ', $placeholders)
            ),
            $bindings
        );
    }

    /**
     * Lines where the vendor invoiced more than what was actually received —
     * real money paid for stock that never showed up, distinct from damage
     * or theft of stock that DID arrive. Same filter-array convention as
     * InventoryMovementRepository::search() (warehouse_uuid, sku, from, to)
     * so it can feed the same Damage & Loss report those filters already
     * drive.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function invoiceShortfalls(array $filters): array
    {
        $where = [
            'poi.`is_deleted` = 0',
            'poi.`invoiced_quantity` IS NOT NULL',
            'poi.`invoiced_quantity` > poi.`quantity`',
        ];
        $bindings = [];

        if (!empty($filters['warehouse_uuid'])) {
            $where[] = 'w.`uuid` = :warehouse_uuid';
            $bindings['warehouse_uuid'] = $filters['warehouse_uuid'];
        }

        if (!empty($filters['sku'])) {
            $where[] = 'v.`sku` LIKE :sku';
            $bindings['sku'] = '%' . $filters['sku'] . '%';
        }

        if (!empty($filters['from'])) {
            $where[] = 'po.`purchase_date` >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            // purchase_date is a DATETIME — see InventoryMovementRepository::search()
            // for why a bare 'to' date needs DATE() rather than a direct compare.
            $where[] = 'DATE(po.`purchase_date`) <= :to';
            $bindings['to'] = $filters['to'];
        }

        return $this->db->select(
            sprintf(
                'SELECT poi.`invoiced_quantity`, poi.`quantity`, poi.`unit_cost`, poi.`batch_no`,
                        po.`purchase_date`, po.`po_number`, po.`invoice_reference`, po.`uuid` AS `purchase_order_uuid`,
                        v.`sku`, v.`variant_name`, w.`name` AS `warehouse_name`, ve.`name` AS `vendor_name`
                   FROM `purchase_order_items` poi
                   INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id`
                   INNER JOIN `product_variants` v ON v.`id` = poi.`product_variant_id`
                   INNER JOIN `warehouses` w ON w.`id` = po.`warehouse_id`
                   INNER JOIN `vendors` ve ON ve.`id` = po.`vendor_id`
                  WHERE %s
                  ORDER BY po.`purchase_date` DESC',
                implode(' AND ', $where)
            ),
            $bindings
        );
    }
}
