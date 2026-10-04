<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The append-only movement ledger. Only `create()` (inherited from
 * BaseRepository) is ever used to write — `update()`/`softDelete()` would
 * fail anyway, because `trg_inventory_movements_immutable` blocks every
 * UPDATE at the database layer regardless of what application code does.
 */
final class InventoryMovementRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'inventory_movements';
    }

    protected function fillable(): array
    {
        return [
            'product_variant_id', 'warehouse_id', 'movement_type', 'reference_type',
            'reference_id', 'reference_uuid', 'quantity_delta', 'quantity_after',
            'unit_cost', 'batch_no', 'expiry_date', 'reason', 'performed_by',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'created_date'];
    }

    /**
     * Whether a 'sale' movement already exists for this order — the
     * idempotency guard OrderService::cancel() uses to decide whether a
     * cancelled order needs restocking at all (an order that never reached
     * `confirmed` was never deducted, and cancelling it twice must not
     * restock twice).
     */
    public function hasSaleForOrder(int $orderId): bool
    {
        return $this->db->scalar(
            "SELECT 1 FROM `inventory_movements`
              WHERE `reference_type` = 'order' AND `reference_id` = :order_id
                AND `movement_type` = 'sale'
              LIMIT 1",
            ['order_id' => $orderId]
        ) !== null;
    }

    /**
     * Whether a 'return' movement (the restock counterpart) has already been
     * posted for this order, so a second cancel/refund attempt cannot restock
     * the same order twice.
     */
    public function hasReturnForOrder(int $orderId): bool
    {
        return $this->db->scalar(
            "SELECT 1 FROM `inventory_movements`
              WHERE `reference_type` = 'order' AND `reference_id` = :order_id
                AND `movement_type` = 'return'
              LIMIT 1",
            ['order_id' => $orderId]
        ) !== null;
    }

    /**
     * The filterable, paginated ledger view the Inventory Admin UI reads.
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['variant_uuid'])) {
            $where[] = 'v.`uuid` = :variant_uuid';
            $bindings['variant_uuid'] = $filters['variant_uuid'];
        }

        if (!empty($filters['warehouse_uuid'])) {
            $where[] = 'w.`uuid` = :warehouse_uuid';
            $bindings['warehouse_uuid'] = $filters['warehouse_uuid'];
        }

        if (!empty($filters['movement_type'])) {
            // A single value keeps the existing '=' comparison (the Movement
            // ledger tab); an array (the Damage & Loss report, which needs
            // both 'damage' and 'lost' together) builds an IN (...) — PDO has
            // no native array-to-one-placeholder binding, so each value gets
            // its own named placeholder.
            if (is_array($filters['movement_type'])) {
                $placeholders = [];

                foreach (array_values($filters['movement_type']) as $index => $type) {
                    $placeholder = 'movement_type_' . $index;
                    $placeholders[] = ':' . $placeholder;
                    $bindings[$placeholder] = $type;
                }

                $where[] = 'm.`movement_type` IN (' . implode(', ', $placeholders) . ')';
            } else {
                $where[] = 'm.`movement_type` = :movement_type';
                $bindings['movement_type'] = $filters['movement_type'];
            }
        }

        if (!empty($filters['batch_no'])) {
            $where[] = 'm.`batch_no` = :batch_no';
            $bindings['batch_no'] = $filters['batch_no'];
        }

        if (!empty($filters['sku'])) {
            $where[] = 'v.`sku` LIKE :sku';
            $bindings['sku'] = '%' . $filters['sku'] . '%';
        }

        if (!empty($filters['from'])) {
            $where[] = 'm.`created_date` >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            // created_date is a DATETIME; comparing it directly against a
            // bare 'YYYY-MM-DD' string silently excludes everything after
            // midnight on that day. DATE() strips the time so "to today"
            // actually includes today.
            $where[] = 'DATE(m.`created_date`) <= :to';
            $bindings['to'] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);
        // Customer is only meaningful for a 'sale' (online order) or
        // 'pos_sale' movement — everything else (purchase, damage, transfer,
        // manual adjustment) has no customer at all, and the joins below
        // simply contribute nothing for those rows rather than being
        // conditional on movement_type, which would be the same result with
        // more branching.
        $from = 'FROM `inventory_movements` m
                    INNER JOIN `product_variants` v ON v.`id` = m.`product_variant_id`
                    INNER JOIN `warehouses` w ON w.`id` = m.`warehouse_id`
                    LEFT JOIN `orders` mo ON m.`reference_type` = \'order\' AND mo.`id` = m.`reference_id`
                    LEFT JOIN `users` ou ON ou.`id` = mo.`user_id`
                    LEFT JOIN `pos_sales` ps ON m.`reference_type` = \'pos_sale\' AND ps.`id` = m.`reference_id`
                    LEFT JOIN `users` pu ON pu.`id` = ps.`customer_id`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT m.*, v.`uuid` AS `variant_uuid`, v.`sku`, v.`variant_name`,
                        w.`uuid` AS `warehouse_uuid`, w.`code` AS `warehouse_code`, w.`name` AS `warehouse_name`,
                        COALESCE(ou.`full_name`, pu.`full_name`, ps.`walk_in_name`) AS `customer_name`
                   %s WHERE %s
                  ORDER BY m.`created_date` %s, m.`id` %s
                  LIMIT %d OFFSET %d',
                $from,
                $whereSql,
                $params['direction'],
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Every movement of the given type(s) against one exact batch — the
     * "was any of this purchase order's stock later damaged or lost" lookup
     * used by PurchaseOrderService::detail(). Keyed by id, not uuid, since
     * the caller already has internal ids on hand (a purchase order's own
     * lines) and resolving to uuid first would be a wasted round trip.
     *
     * @param array<int, string> $movementTypes
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBatch(int $variantId, int $warehouseId, string $batchNo, array $movementTypes): array
    {
        $placeholders = [];
        $bindings = ['variant_id' => $variantId, 'warehouse_id' => $warehouseId, 'batch_no' => $batchNo];

        foreach (array_values($movementTypes) as $index => $type) {
            $key = 'movement_type_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $type;
        }

        return $this->db->select(
            sprintf(
                'SELECT * FROM `inventory_movements`
                  WHERE `product_variant_id` = :variant_id AND `warehouse_id` = :warehouse_id
                    AND `batch_no` = :batch_no AND `movement_type` IN (%s)
                  ORDER BY `created_date` DESC',
                implode(', ', $placeholders)
            ),
            $bindings
        );
    }
}
