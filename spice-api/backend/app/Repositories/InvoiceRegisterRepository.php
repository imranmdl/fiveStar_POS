<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * One invoice register across both sales channels: till (POS) sales and
 * online orders. Each row carries `channel` ('pos' | 'online') and the same
 * column names the POS-only invoice list always returned (sale_number,
 * grand_total, amount_paid, balance_due, discount_amount, payment_method,
 * payment_status, created_date, customer_*), so the Invoice Tracking screen
 * and its CSV export read both kinds of row the same way.
 *
 * An online order is on the register once it is confirmed — paid by UPI, or a
 * cash-on-delivery order approved by staff — because that is when it becomes
 * a sale. Orders still waiting for payment are not invoices yet.
 */
final class InvoiceRegisterRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The union of both channels, as a derived table. Online payment statuses
     * are mapped onto the till's paid / unpaid / refunded words.
     */
    private function registerSql(): string
    {
        return "(
            SELECT 'pos'                                         AS `channel`,
                   s.`uuid`                                      AS `uuid`,
                   s.`sale_number`                               AS `sale_number`,
                   NULL                                          AS `order_number`,
                   NULL                                          AS `order_status`,
                   s.`created_date`                              AS `created_date`,
                   COALESCE(c.`full_name`, s.`walk_in_name`, 'Walk-in customer') AS `customer_name`,
                   COALESCE(c.`mobile`, s.`walk_in_mobile`)      AS `customer_mobile`,
                   c.`uuid`                                      AS `customer_uuid`,
                   s.`grand_total`                               AS `grand_total`,
                   s.`amount_paid`                               AS `amount_paid`,
                   (s.`grand_total` - s.`amount_paid`)           AS `balance_due`,
                   s.`discount_amount`                           AS `discount_amount`,
                   s.`tax_amount`                                AS `tax_amount`,
                   s.`payment_method`                            AS `payment_method`,
                   s.`payment_status`                            AS `payment_status`
              FROM `pos_sales` s
              LEFT JOIN `users` c ON c.`id` = s.`customer_id`
             WHERE s.`status` = 'completed' AND s.`is_deleted` = 0

            UNION ALL

            SELECT 'online',
                   o.`uuid`,
                   COALESCE(o.`invoice_number`, o.`order_number`),
                   o.`order_number`,
                   o.`status`,
                   COALESCE(o.`invoice_date`, o.`confirmed_date`),
                   COALESCE(u.`full_name`, o.`ship_name`),
                   COALESCE(u.`mobile`, o.`ship_mobile`),
                   u.`uuid`,
                   o.`grand_total`,
                   (o.`amount_paid` + o.`wallet_applied`),
                   GREATEST(o.`grand_total` - o.`amount_paid` - o.`wallet_applied`, 0),
                   (o.`product_discount` + o.`order_discount`),
                   o.`tax_total`,
                   o.`payment_method`,
                   CASE
                       WHEN o.`payment_status` IN ('refunded', 'partially_refunded') THEN 'refunded'
                       WHEN o.`payment_status` = 'paid' THEN 'paid'
                       ELSE 'unpaid'
                   END
              FROM `orders` o
              LEFT JOIN `users` u ON u.`id` = o.`user_id`
             WHERE o.`is_deleted` = 0 AND o.`confirmed_date` IS NOT NULL
        ) inv";
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        [$whereSql, $bindings] = $this->where($filters);
        $register = $this->registerSql();

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM {$register} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sortable = ['created_date', 'grand_total', 'sale_number', 'balance_due'];
        $sort = in_array($params['sort'] ?? '', $sortable, true) ? $params['sort'] : 'created_date';
        $direction = strtoupper((string) ($params['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $items = $this->db->select(
            sprintf(
                'SELECT * FROM %s WHERE %s ORDER BY inv.`%s` %s LIMIT %d OFFSET %d',
                $register,
                $whereSql,
                $sort,
                $direction,
                (int) $params['per_page'],
                (int) $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Online counts for the summary cards (the till's own counts come from
     * PosSaleRepository::invoiceSummary()).
     *
     * @return array{total:int, paid:int, unpaid:int, refunded:int, cancelled:int, todays_revenue:float}
     */
    public function onlineSummary(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS `total`,
                    SUM(`payment_status` = 'paid') AS `paid`,
                    SUM(`payment_status` IN ('pending', 'processing', 'failed') AND `status` <> 'cancelled') AS `unpaid`,
                    SUM(`payment_status` IN ('refunded', 'partially_refunded')) AS `refunded`,
                    SUM(`status` = 'cancelled') AS `cancelled`,
                    COALESCE(SUM(CASE WHEN DATE(COALESCE(`invoice_date`, `confirmed_date`)) = CURDATE()
                                       AND `status` <> 'cancelled' THEN `grand_total` END), 0) AS `todays_revenue`
               FROM `orders`
              WHERE `is_deleted` = 0 AND `confirmed_date` IS NOT NULL"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'paid' => (int) ($row['paid'] ?? 0),
            'unpaid' => (int) ($row['unpaid'] ?? 0),
            'refunded' => (int) ($row['refunded'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'todays_revenue' => round((float) ($row['todays_revenue'] ?? 0), 2),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (in_array($filters['channel'] ?? '', ['pos', 'online'], true)) {
            $where[] = 'inv.`channel` = :channel';
            $bindings['channel'] = $filters['channel'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(inv.`sale_number` LIKE :search1 OR inv.`order_number` LIKE :search2
                         OR inv.`customer_name` LIKE :search3 OR inv.`customer_mobile` LIKE :search4)';
            $needle = '%' . $filters['search'] . '%';
            foreach (['search1', 'search2', 'search3', 'search4'] as $key) {
                $bindings[$key] = $needle;
            }
        }

        if (!empty($filters['payment_status'])) {
            $where[] = 'inv.`payment_status` = :payment_status';
            $bindings['payment_status'] = $filters['payment_status'];
        }

        if (!empty($filters['payment_method'])) {
            $where[] = 'inv.`payment_method` = :payment_method';
            $bindings['payment_method'] = $filters['payment_method'];
        }

        if (!empty($filters['customer_uuid'])) {
            $where[] = 'inv.`customer_uuid` = :customer_uuid';
            $bindings['customer_uuid'] = $filters['customer_uuid'];
        }

        // Overdue balances only exist on till credit sales.
        if (!empty($filters['overdue_only'])) {
            $where[] = "inv.`channel` = 'pos' AND inv.`payment_status` IN ('unpaid', 'partial')
                        AND inv.`created_date` <= DATE_SUB(NOW(), INTERVAL :overdue_days DAY)";
            $bindings['overdue_days'] = (int) ($filters['overdue_days'] ?? 7);
        }

        if (!empty($filters['from'])) {
            $where[] = 'inv.`created_date` >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'inv.`created_date` < DATE_ADD(DATE(:to), INTERVAL 1 DAY)';
            $bindings['to'] = $filters['to'];
        }

        if (isset($filters['amount_min']) && $filters['amount_min'] !== '' && $filters['amount_min'] !== null) {
            $where[] = 'inv.`grand_total` >= :amount_min';
            $bindings['amount_min'] = (float) $filters['amount_min'];
        }

        if (isset($filters['amount_max']) && $filters['amount_max'] !== '' && $filters['amount_max'] !== null) {
            $where[] = 'inv.`grand_total` <= :amount_max';
            $bindings['amount_max'] = (float) $filters['amount_max'];
        }

        return [implode(' AND ', $where), $bindings];
    }
}
