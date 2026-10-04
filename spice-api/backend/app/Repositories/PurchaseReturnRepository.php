<?php

declare(strict_types=1);

namespace App\Repositories;

final class PurchaseReturnRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'purchase_returns';
    }

    protected function fillable(): array
    {
        return ['return_number', 'purchase_order_id', 'vendor_id', 'return_date', 'reason', 'total_amount'];
    }

    protected function sortable(): array
    {
        return ['id', 'return_date', 'total_amount', 'created_date'];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        $where = ['pr.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['vendor_uuid'])) {
            $where[] = 'v.`uuid` = :vendor_uuid';
            $bindings['vendor_uuid'] = $filters['vendor_uuid'];
        }

        if (!empty($filters['purchase_order_uuid'])) {
            $where[] = 'po.`uuid` = :po_uuid';
            $bindings['po_uuid'] = $filters['purchase_order_uuid'];
        }

        $whereSql = implode(' AND ', $where);
        $from = 'FROM `purchase_returns` pr
                    INNER JOIN `vendors` v ON v.`id` = pr.`vendor_id`
                    INNER JOIN `purchase_orders` po ON po.`id` = pr.`purchase_order_id`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'return_date';

        $items = $this->db->select(
            sprintf(
                'SELECT pr.*, v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`,
                        po.`uuid` AS `purchase_order_uuid`, po.`po_number`
                   %s WHERE %s
                  ORDER BY pr.`%s` %s
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

    /** @return array<string, mixed>|null */
    public function detailByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT pr.*, v.`uuid` AS `vendor_uuid`, v.`name` AS `vendor_name`,
                    po.`uuid` AS `purchase_order_uuid`, po.`po_number`
               FROM `purchase_returns` pr
               INNER JOIN `vendors` v ON v.`id` = pr.`vendor_id`
               INNER JOIN `purchase_orders` po ON po.`id` = pr.`purchase_order_id`
              WHERE pr.`uuid` = :uuid AND pr.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /** Every return across every vendor — the Vendors dashboard's return count/value. */
    public function forDashboard(): array
    {
        return $this->db->select(
            'SELECT `total_amount` FROM `purchase_returns` WHERE `is_deleted` = 0'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forVendor(int $vendorId): array
    {
        return $this->db->select(
            'SELECT pr.*, po.`po_number`, po.`uuid` AS `purchase_order_uuid`
               FROM `purchase_returns` pr
               INNER JOIN `purchase_orders` po ON po.`id` = pr.`purchase_order_id`
              WHERE pr.`vendor_id` = :vendor_id AND pr.`is_deleted` = 0
              ORDER BY pr.`return_date` DESC, pr.`id` DESC',
            ['vendor_id' => $vendorId]
        );
    }

    /** Total already returned for one purchase-order line, across every return — what caps a further return request. */
    public function returnedQuantityForOrderItem(int $purchaseOrderItemId): float
    {
        return (float) ($this->db->scalar(
            'SELECT COALESCE(SUM(ri.`quantity`), 0)
               FROM `purchase_return_items` ri
               INNER JOIN `purchase_returns` pr ON pr.`id` = ri.`purchase_return_id`
              WHERE ri.`purchase_order_item_id` = :item_id AND pr.`is_deleted` = 0 AND ri.`is_deleted` = 0',
            ['item_id' => $purchaseOrderItemId]
        ) ?? 0);
    }
}
