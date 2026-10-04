<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The real ledger behind pos_sales.amount_paid/payment_status, the same
 * relationship vendor_payments has to purchase_orders.amount_paid — one row
 * per payment (including the portion collected at the register when a sale
 * is opened as a credit sale), never a running total written to directly by
 * more than one place.
 */
final class PosSalePaymentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'pos_sale_payments';
    }

    protected function fillable(): array
    {
        return [
            'pos_sale_id', 'customer_id', 'amount', 'payment_method',
            'payment_date', 'reference_number', 'status', 'notes',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'payment_date', 'amount', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forSale(int $posSaleId): array
    {
        return $this->db->select(
            'SELECT * FROM `pos_sale_payments`
              WHERE `pos_sale_id` = :sale_id AND `is_deleted` = 0
              ORDER BY `payment_date` DESC, `id` DESC',
            ['sale_id' => $posSaleId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forCustomer(int $customerId): array
    {
        return $this->db->select(
            'SELECT p.*, s.`sale_number`, s.`uuid` AS `pos_sale_uuid`
               FROM `pos_sale_payments` p
               INNER JOIN `pos_sales` s ON s.`id` = p.`pos_sale_id`
              WHERE p.`customer_id` = :customer_id AND p.`is_deleted` = 0
              ORDER BY p.`payment_date` DESC, p.`id` DESC',
            ['customer_id' => $customerId]
        );
    }

    /** Sum of completed payments against one sale — the figure pos_sales.amount_paid is derived from. */
    public function totalPaidForSale(int $posSaleId): float
    {
        return (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `pos_sale_payments`
              WHERE `pos_sale_id` = :sale_id AND `status` = 'completed' AND `is_deleted` = 0",
            ['sale_id' => $posSaleId]
        ) ?? 0);
    }

    /** True if this exact reference number is already recorded against this sale — the duplicate-entry guard. */
    public function referenceExistsForSale(int $posSaleId, string $referenceNumber): bool
    {
        return (bool) $this->db->scalar(
            'SELECT 1 FROM `pos_sale_payments`
              WHERE `pos_sale_id` = :sale_id AND `reference_number` = :reference
                AND `status` = \'completed\' AND `is_deleted` = 0
              LIMIT 1',
            ['sale_id' => $posSaleId, 'reference' => $referenceNumber]
        );
    }
}
