<?php

declare(strict_types=1);

namespace App\Repositories;

final class VendorPaymentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'vendor_payments';
    }

    protected function fillable(): array
    {
        return [
            'purchase_order_id', 'vendor_id', 'amount', 'payment_method',
            'payment_date', 'reference_number', 'status', 'notes',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'payment_date', 'amount', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $purchaseOrderId): array
    {
        return $this->db->select(
            'SELECT * FROM `vendor_payments`
              WHERE `purchase_order_id` = :po_id AND `is_deleted` = 0
              ORDER BY `payment_date` DESC, `id` DESC',
            ['po_id' => $purchaseOrderId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forVendor(int $vendorId): array
    {
        return $this->db->select(
            'SELECT vp.*, po.`po_number`, po.`uuid` AS `purchase_order_uuid`
               FROM `vendor_payments` vp
               INNER JOIN `purchase_orders` po ON po.`id` = vp.`purchase_order_id`
              WHERE vp.`vendor_id` = :vendor_id AND vp.`is_deleted` = 0
              ORDER BY vp.`payment_date` DESC, vp.`id` DESC',
            ['vendor_id' => $vendorId]
        );
    }

    /** Sum of completed payments against one purchase order — the figure purchase_orders.amount_paid is derived from. */
    public function totalPaidForOrder(int $purchaseOrderId): float
    {
        return (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `vendor_payments`
              WHERE `purchase_order_id` = :po_id AND `status` = 'completed' AND `is_deleted` = 0",
            ['po_id' => $purchaseOrderId]
        ) ?? 0);
    }
}
