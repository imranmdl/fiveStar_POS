<?php

declare(strict_types=1);

namespace App\Repositories;

final class PurchaseOrderRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'purchase_orders';
    }

    protected function fillable(): array
    {
        return [
            'po_number', 'vendor_id', 'warehouse_id', 'purchase_date', 'invoice_reference',
            'items_subtotal', 'discount_amount', 'other_charges',
            'transport_charge', 'transport_included_in_cost', 'transport_percent',
            'tax_amount', 'grand_total', 'payment_status', 'amount_paid', 'notes',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'po_number', 'purchase_date', 'grand_total', 'created_date'];
    }

    /**
     * Vendor purchase history, filterable by vendor, warehouse and date range
     * — the report the brief asks for (§11, §14).
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        $where = ['po.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['vendor_uuid'])) {
            $where[] = 'v.`uuid` = :vendor_uuid';
            $bindings['vendor_uuid'] = $filters['vendor_uuid'];
        }

        if (!empty($filters['warehouse_uuid'])) {
            $where[] = 'w.`uuid` = :warehouse_uuid';
            $bindings['warehouse_uuid'] = $filters['warehouse_uuid'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'po.`purchase_date` >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'po.`purchase_date` <= :to';
            $bindings['to'] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);
        $from = 'FROM `purchase_orders` po
                    INNER JOIN `vendors` v ON v.`id` = po.`vendor_id`
                    INNER JOIN `warehouses` w ON w.`id` = po.`warehouse_id`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'purchase_date';

        $items = $this->db->select(
            sprintf(
                'SELECT po.*, v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`,
                        w.`uuid` AS `warehouse_uuid`, w.`name` AS `warehouse_name`
                   %s WHERE %s
                  ORDER BY po.`%s` %s
                  LIMIT %d OFFSET %d',
                $from,
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The one field on an otherwise create-only purchase order that is
     * intentionally updated in place after the fact — same precedent as
     * PosSaleItemRepository::addRefundedQuantity() on an otherwise-immutable
     * sale line. Never touches unit_cost, landing_cost or any inventory
     * figure.
     */
    public function updatePayment(int $id, string $paymentStatus, string $amountPaid, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `purchase_orders`
                SET `payment_status` = :status, `amount_paid` = :amount,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['status' => $paymentStatus, 'amount' => $amountPaid, 'actor' => $actorId, 'id' => $id]
        );
    }

    /** Every purchase order for one vendor, newest first — the vendor history view's purchases list. */
    public function forVendor(int $vendorId): array
    {
        return $this->db->select(
            'SELECT po.*, w.`uuid` AS `warehouse_uuid`, w.`name` AS `warehouse_name`
               FROM `purchase_orders` po
               INNER JOIN `warehouses` w ON w.`id` = po.`warehouse_id`
              WHERE po.`vendor_id` = :vendor_id AND po.`is_deleted` = 0
              ORDER BY po.`purchase_date` DESC, po.`id` DESC',
            ['vendor_id' => $vendorId]
        );
    }

    /**
     * Every purchase order across every vendor, newest first — the Vendors
     * dashboard's raw material for total-purchases/paid/pending/top-vendors/
     * recent-purchases, computed in PHP from one query rather than five.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forVendorSpendSummary(): array
    {
        return $this->db->select(
            'SELECT po.`id`, po.`uuid`, po.`po_number`, po.`purchase_date`, po.`grand_total`,
                    po.`amount_paid`, po.`amount_returned`, po.`payment_status`,
                    v.`id` AS `vendor_id`, v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`
               FROM `purchase_orders` po
               INNER JOIN `vendors` v ON v.`id` = po.`vendor_id`
              WHERE po.`is_deleted` = 0
              ORDER BY po.`purchase_date` DESC, po.`id` DESC'
        );
    }

    /** Same "one field intentionally updated in place" precedent as updatePayment() — maintained by PurchaseReturnService, never touches any inventory figure. */
    public function incrementAmountReturned(int $id, float $delta, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `purchase_orders`
                SET `amount_returned` = `amount_returned` + :delta,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['delta' => number_format($delta, 2, '.', ''), 'actor' => $actorId, 'id' => $id]
        );
    }

    /** @return array<string, mixed>|null */
    public function detailByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT po.*, v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`,
                    w.`uuid` AS `warehouse_uuid`, w.`name` AS `warehouse_name`
               FROM `purchase_orders` po
               INNER JOIN `vendors` v ON v.`id` = po.`vendor_id`
               INNER JOIN `warehouses` w ON w.`id` = po.`warehouse_id`
              WHERE po.`uuid` = :uuid AND po.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }
}
