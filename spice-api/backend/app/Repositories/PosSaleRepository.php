<?php

declare(strict_types=1);

namespace App\Repositories;

final class PosSaleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'pos_sales';
    }

    protected function fillable(): array
    {
        return [
            'sale_number', 'cashier_id', 'warehouse_id', 'shop_label', 'customer_id', 'walk_in_name', 'walk_in_mobile',
            'payment_method', 'subtotal', 'discount_amount', 'tax_amount', 'grand_total', 'wallet_applied',
            'payment_status', 'amount_paid', 'is_credit_sale',
            'amount_tendered', 'change_due', 'status', 'voided_by', 'voided_date', 'void_reason', 'notes',
            'delivery_status', 'delivered_date',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'sale_number', 'grand_total', 'created_date'];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        $where = ['s.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['cashier_uuid'])) {
            $where[] = 'u.`uuid` = :cashier_uuid';
            $bindings['cashier_uuid'] = $filters['cashier_uuid'];
        }

        if (!empty($filters['payment_method'])) {
            $where[] = 's.`payment_method` = :payment_method';
            $bindings['payment_method'] = $filters['payment_method'];
        }

        if (!empty($filters['status'])) {
            $where[] = 's.`status` = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['from'])) {
            $where[] = 's.`created_date` >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            // A bare date means "through the end of that day", not midnight at its start.
            $where[] = 's.`created_date` < DATE_ADD(DATE(:to), INTERVAL 1 DAY)';
            $bindings['to'] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);
        $from = 'FROM `pos_sales` s
                    INNER JOIN `users` u ON u.`id` = s.`cashier_id`
                    INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $items = $this->db->select(
            sprintf(
                'SELECT s.*, u.`full_name` AS `cashier_name`, w.`name` AS `warehouse_name`
                   %s WHERE %s
                  ORDER BY s.`%s` %s
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
            'SELECT s.*, u.`full_name` AS `cashier_name`, w.`name` AS `warehouse_name`
               FROM `pos_sales` s
               INNER JOIN `users` u ON u.`id` = s.`cashier_id`
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`uuid` = :uuid AND s.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * The one field intentionally updated in place after a credit sale is
     * created — same precedent as PurchaseOrderRepository::updatePayment().
     * payment_status/amount_paid are always recomputed together, from
     * PosSalePaymentRepository's SUM, never written independently.
     */
    public function updatePayment(int $id, string $paymentStatus, string $amountPaid, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `pos_sales`
                SET `payment_status` = :status, `amount_paid` = :amount,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['status' => $paymentStatus, 'amount' => $amountPaid, 'actor' => $actorId, 'id' => $id]
        );
    }

    /**
     * A customer's own credit sales — every sale ever opened with a balance
     * knowingly left due, oldest first (so the oldest debt is naturally the
     * one a cashier sees first when choosing which invoice to allocate a
     * payment against).
     *
     * @return array<int, array<string, mixed>>
     */
    public function creditSalesForCustomer(int $customerId): array
    {
        return $this->db->select(
            "SELECT s.*, w.`name` AS `warehouse_name`
               FROM `pos_sales` s
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`customer_id` = :customer_id AND s.`is_credit_sale` = 1
                AND s.`status` = 'completed' AND s.`is_deleted` = 0
              ORDER BY s.`created_date` ASC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * The admin-wide Customer Dues list: every credit sale, optionally
     * narrowed to open balances / a status / a customer / overdue-by-days —
     * the same filter shape every other admin list in this app uses.
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function searchDues(array $filters, array $params): array
    {
        $where = ['s.`is_credit_sale` = 1', "s.`status` = 'completed'", 's.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['customer_uuid'])) {
            $where[] = 'u.`uuid` = :customer_uuid';
            $bindings['customer_uuid'] = $filters['customer_uuid'];
        }

        if (!empty($filters['payment_status'])) {
            $where[] = 's.`payment_status` = :payment_status';
            $bindings['payment_status'] = $filters['payment_status'];
        } elseif (!empty($filters['open_only'])) {
            $where[] = "s.`payment_status` IN ('unpaid', 'partial')";
        }

        if (!empty($filters['overdue_days'])) {
            $where[] = "s.`payment_status` IN ('unpaid', 'partial')
                        AND s.`created_date` <= DATE_SUB(NOW(), INTERVAL :overdue_days DAY)";
            $bindings['overdue_days'] = (int) $filters['overdue_days'];
        }

        $whereSql = implode(' AND ', $where);
        $from = 'FROM `pos_sales` s
                    INNER JOIN `users` u ON u.`id` = s.`customer_id`
                    INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $items = $this->db->select(
            sprintf(
                'SELECT s.*, u.`full_name` AS `customer_name`, u.`mobile` AS `customer_mobile`,
                        w.`name` AS `warehouse_name`, (s.`grand_total` - s.`amount_paid`) AS `balance_due`
                   %s WHERE %s
                  ORDER BY s.`%s` %s
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
     * Every completed sale treated as an "invoice" for the Invoice Tracking
     * Center — walk-in and registered-customer, cash-in-full and credit
     * alike — with the filters that screen's toolbar offers. LEFT JOIN on
     * the customer because a walk-in sale has none; walk_in_name/
     * walk_in_mobile fill in for `customer_name`/`customer_mobile` in that
     * case (see the COALESCE below).
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function searchInvoices(array $filters, array $params): array
    {
        $where = ["s.`status` = 'completed'", 's.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['search'])) {
            // Real (non-emulated) prepared statements reject the same named
            // placeholder used more than once in a query, so each OR branch
            // gets its own — all bound to the same value.
            $where[] = '(s.`sale_number` LIKE :search1
                         OR c.`full_name` LIKE :search2 OR c.`mobile` LIKE :search3
                         OR s.`walk_in_name` LIKE :search4 OR s.`walk_in_mobile` LIKE :search5)';
            $needle = '%' . $filters['search'] . '%';
            $bindings['search1'] = $needle;
            $bindings['search2'] = $needle;
            $bindings['search3'] = $needle;
            $bindings['search4'] = $needle;
            $bindings['search5'] = $needle;
        }

        if (!empty($filters['payment_status'])) {
            $where[] = 's.`payment_status` = :payment_status';
            $bindings['payment_status'] = $filters['payment_status'];
        }

        if (!empty($filters['payment_method'])) {
            $where[] = 's.`payment_method` = :payment_method';
            $bindings['payment_method'] = $filters['payment_method'];
        }

        if (!empty($filters['customer_uuid'])) {
            $where[] = 'c.`uuid` = :customer_uuid';
            $bindings['customer_uuid'] = $filters['customer_uuid'];
        }

        if (array_key_exists('overdue_only', $filters) && $filters['overdue_only']) {
            $where[] = "s.`payment_status` IN ('unpaid', 'partial')
                        AND s.`created_date` <= DATE_SUB(NOW(), INTERVAL :overdue_days DAY)";
            $bindings['overdue_days'] = (int) ($filters['overdue_days'] ?? 7);
        }

        if (!empty($filters['from'])) {
            $where[] = 's.`created_date` >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 's.`created_date` < DATE_ADD(DATE(:to), INTERVAL 1 DAY)';
            $bindings['to'] = $filters['to'];
        }

        if (isset($filters['amount_min']) && $filters['amount_min'] !== '') {
            $where[] = 's.`grand_total` >= :amount_min';
            $bindings['amount_min'] = (float) $filters['amount_min'];
        }

        if (isset($filters['amount_max']) && $filters['amount_max'] !== '') {
            $where[] = 's.`grand_total` <= :amount_max';
            $bindings['amount_max'] = (float) $filters['amount_max'];
        }

        $whereSql = implode(' AND ', $where);
        $from = 'FROM `pos_sales` s
             LEFT JOIN `users` c ON c.`id` = s.`customer_id`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $items = $this->db->select(
            sprintf(
                'SELECT s.*,
                        COALESCE(c.`full_name`, s.`walk_in_name`, \'Walk-in customer\') AS `customer_name`,
                        COALESCE(c.`mobile`, s.`walk_in_mobile`) AS `customer_mobile`,
                        c.`uuid` AS `customer_uuid`,
                        (s.`grand_total` - s.`amount_paid`) AS `balance_due`
                   %s WHERE %s
                  ORDER BY s.`%s` %s
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
     * Dashboard counts for the Invoice Tracking Center. One query per figure
     * rather than one GROUP BY, because "today's revenue" and "overdue" cut
     * across payment_status/status/date in ways a single grouped result
     * cannot express cleanly.
     *
     * @return array<string, int|string>
     */
    public function invoiceSummary(int $overdueAfterDays): array
    {
        $counts = $this->db->selectOne(
            "SELECT
                COUNT(*) AS `total`,
                SUM(`payment_status` = 'paid') AS `paid`,
                SUM(`payment_status` = 'partial') AS `partial`,
                SUM(`payment_status` = 'unpaid') AS `unpaid`,
                SUM(`payment_status` IN ('unpaid','partial')
                    AND `created_date` <= DATE_SUB(NOW(), INTERVAL :days DAY)) AS `overdue`
              FROM `pos_sales`
             WHERE `status` = 'completed' AND `is_deleted` = 0",
            ['days' => $overdueAfterDays]
        ) ?? [];

        $refundedCancelled = $this->db->selectOne(
            "SELECT
                (SELECT COUNT(DISTINCT `pos_sale_id`) FROM `pos_refunds` WHERE `is_deleted` = 0) AS `refunded`,
                (SELECT COUNT(*) FROM `pos_sales` WHERE `status` = 'voided' AND `is_deleted` = 0) AS `cancelled`"
        ) ?? [];

        $todaysRevenue = $this->db->scalar(
            "SELECT COALESCE(SUM(`grand_total`), 0) FROM `pos_sales`
              WHERE `status` = 'completed' AND `is_deleted` = 0 AND DATE(`created_date`) = CURDATE()"
        );

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'paid' => (int) ($counts['paid'] ?? 0),
            'partial' => (int) ($counts['partial'] ?? 0),
            'unpaid' => (int) ($counts['unpaid'] ?? 0),
            'overdue' => (int) ($counts['overdue'] ?? 0),
            'refunded' => (int) ($refundedCancelled['refunded'] ?? 0),
            'cancelled' => (int) ($refundedCancelled['cancelled'] ?? 0),
            'todays_revenue' => (string) ($todaysRevenue ?? '0.00'),
        ];
    }

    /**
     * A registered customer's lifetime figures across every till sale —
     * "Customer History" on the Invoice Tracking Center.
     *
     * @return array<string, mixed>
     */
    public function customerInvoiceSummary(int $customerId): array
    {
        return $this->db->selectOne(
            "SELECT
                COUNT(*) AS `invoice_count`,
                COALESCE(SUM(`grand_total`), 0) AS `total_purchases`,
                COALESCE(SUM(`amount_paid`), 0) AS `total_paid`,
                COALESCE(SUM(`grand_total` - `amount_paid`), 0) AS `total_due`,
                SUM(`payment_status` = 'partial') AS `partial_count`
              FROM `pos_sales`
             WHERE `customer_id` = :customer_id AND `status` = 'completed' AND `is_deleted` = 0",
            ['customer_id' => $customerId]
        ) ?? [
            'invoice_count' => 0, 'total_purchases' => '0.00', 'total_paid' => '0.00',
            'total_due' => '0.00', 'partial_count' => 0,
        ];
    }
}
