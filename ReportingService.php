<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;

/**
 * Dashboards and reports.
 *
 * Read-only aggregation over the views the earlier phases already established,
 * so a figure shown on a dashboard is derived the same way as the equivalent
 * figure in an export. Two definitions of "revenue" in one system is how a
 * board meeting ends in an argument about the software rather than the numbers.
 *
 * REVENUE MEANS CONFIRMED, NON-CANCELLED ORDERS. Placed-but-unpaid orders are
 * not revenue, and counting them would flatter every chart on the busiest day
 * of the year — which is exactly when someone acts on the number.
 *
 * Date ranges are bounded and validated. An unbounded aggregate over `orders`
 * is fine at ten thousand rows and a problem at ten million, and the report
 * that quietly takes ninety seconds is the one nobody notices until it takes
 * the site down with it.
 */
final class ReportingService
{
    private const MAX_RANGE_DAYS = 400;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The operational dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $today = $this->db->selectOne(
            "SELECT
                COUNT(*)                                                   AS `orders_today`,
                COALESCE(SUM(`grand_total`), 0)                            AS `revenue_today`,
                COALESCE(SUM(`status` = 'delivered'), 0)                   AS `delivered_today`,
                COALESCE(SUM(`status` = 'cancelled'), 0)                   AS `cancelled_today`
               FROM `orders`
              WHERE DATE(`confirmed_date`) = CURDATE()
                AND `is_deleted` = 0
                AND `status` <> 'cancelled'"
        );

        $pipeline = $this->db->select(
            "SELECT `status`, COUNT(*) AS `count`, COALESCE(SUM(`grand_total`), 0) AS `value`
               FROM `orders`
              WHERE `is_deleted` = 0
                AND `payment_status` IN ('paid','partially_refunded')
                AND `status` NOT IN ('delivered','cancelled','refunded','returned')
              GROUP BY `status`"
        );

        $attention = $this->db->selectOne(
            "SELECT
                (SELECT COUNT(*) FROM `orders`
                  WHERE `status` IN ('created','awaiting_payment')
                    AND `expires_date` < NOW() AND `is_deleted` = 0)             AS `expired_unpaid`,
                (SELECT COUNT(*) FROM `orders` o
                  WHERE o.`status` IN ('confirmed','packed')
                    AND o.`payment_status` IN ('paid','partially_refunded')
                    AND o.`is_deleted` = 0
                    AND NOT EXISTS (SELECT 1 FROM `order_assignments` a
                                     WHERE a.`order_id` = o.`id`
                                       AND a.`status` IN ('assigned','accepted')
                                       AND a.`is_deleted` = 0))                  AS `unassigned_orders`,
                (SELECT COUNT(*) FROM `order_assignments`
                  WHERE `status` IN ('assigned','accepted')
                    AND `due_date` < NOW() AND `is_deleted` = 0)                 AS `overdue_assignments`,
                (SELECT COUNT(*) FROM `shipments`
                  WHERE `status` IN ('failed_delivery','rto_initiated')
                    AND `is_deleted` = 0)                                        AS `delivery_problems`,
                (SELECT COUNT(*) FROM `commission_entries`
                  WHERE `status` = 'pending' AND `is_deleted` = 0)               AS `commission_awaiting_approval`,
                (SELECT COUNT(*) FROM `bulk_order_enquiries`
                  WHERE `status` IN ('new','under_review') AND `is_deleted` = 0) AS `bulk_enquiries_waiting`"
        );

        return [
            'today' => [
                'orders' => (int) ($today['orders_today'] ?? 0),
                'revenue' => (float) ($today['revenue_today'] ?? 0),
                'delivered' => (int) ($today['delivered_today'] ?? 0),
                'cancelled' => (int) ($today['cancelled_today'] ?? 0),
            ],
            'pipeline' => array_map(static fn (array $row): array => [
                'status' => $row['status'],
                'count' => (int) $row['count'],
                'value' => (float) $row['value'],
            ], $pipeline),
            // What a supervisor should act on before anything else.
            'needs_attention' => array_map('intval', $attention ?? []),
            'last_7_days' => $this->salesSeries(date('Y-m-d', strtotime('-6 days')), date('Y-m-d')),
        ];
    }

    /**
     * Daily sales over a range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesSeries(string $from, string $to): array
    {
        $this->assertRange($from, $to);

        return array_map(static fn (array $row): array => [
            'date' => $row['sales_date'],
            'orders' => (int) $row['order_count'],
            'gross_sales' => (float) $row['gross_sales'],
            'taxable_value' => (float) $row['taxable_value'],
            'tax_collected' => (float) $row['tax_collected'],
            'delivery_collected' => (float) $row['delivery_collected'],
            'discount_given' => (float) $row['discount_given'],
            'wallet_redeemed' => (float) $row['wallet_redeemed'],
            'collected_online' => (float) $row['collected_online'],
            'refunded' => (float) $row['refunded'],
        ], $this->db->select(
            'SELECT * FROM `vw_daily_sales`
              WHERE `sales_date` BETWEEN :from AND :to
              ORDER BY `sales_date`',
            ['from' => $from, 'to' => $to]
        ));
    }

    /**
     * Best-selling products by units and by value.
     *
     * Both, deliberately: the product that moves most units and the product
     * that earns most money are usually not the same one, and merchandising
     * decisions made on units alone favour the cheapest line in the catalogue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(string $from, string $to, int $limit = 20): array
    {
        $this->assertRange($from, $to);

        return array_map(static fn (array $row): array => [
            'product_name' => $row['product_name'],
            'sku' => $row['sku'],
            'units_sold' => (int) $row['units_sold'],
            'order_count' => (int) $row['order_count'],
            'revenue' => (float) $row['revenue'],
        ], $this->db->select(
            sprintf(
                "SELECT oi.`product_name`, oi.`sku`,
                        SUM(oi.`quantity`)              AS `units_sold`,
                        COUNT(DISTINCT oi.`order_id`)   AS `order_count`,
                        ROUND(SUM(oi.`line_payable`), 2) AS `revenue`
                   FROM `order_items` oi
                   INNER JOIN `orders` o ON o.`id` = oi.`order_id`
                  WHERE DATE(o.`confirmed_date`) BETWEEN :from AND :to
                    AND o.`status` <> 'cancelled'
                    AND o.`is_deleted` = 0 AND oi.`is_deleted` = 0
                  GROUP BY oi.`product_name`, oi.`sku`
                  ORDER BY `revenue` DESC
                  LIMIT %d",
                max(1, min($limit, 100))
            ),
            ['from' => $from, 'to' => $to]
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(string $from, string $to, int $limit = 20): array
    {
        $this->assertRange($from, $to);

        return array_map(static fn (array $row): array => [
            'customer_name' => $row['full_name'],
            // Masked. A report that circulates by email should not carry a
            // column of customer phone numbers.
            'mobile' => substr((string) $row['mobile'], 0, 2)
                . str_repeat('X', max(0, strlen((string) $row['mobile']) - 4))
                . substr((string) $row['mobile'], -2),
            'order_count' => (int) $row['order_count'],
            'total_spent' => (float) $row['total_spent'],
            'last_order_date' => $row['last_order_date'],
        ], $this->db->select(
            sprintf(
                "SELECT u.`full_name`, u.`mobile`,
                        COUNT(o.`id`)                    AS `order_count`,
                        ROUND(SUM(o.`grand_total`), 2)   AS `total_spent`,
                        MAX(o.`confirmed_date`)          AS `last_order_date`
                   FROM `orders` o
                   INNER JOIN `users` u ON u.`id` = o.`user_id`
                  WHERE DATE(o.`confirmed_date`) BETWEEN :from AND :to
                    AND o.`status` <> 'cancelled'
                    AND o.`is_deleted` = 0
                  GROUP BY u.`id`, u.`full_name`, u.`mobile`
                  ORDER BY `total_spent` DESC
                  LIMIT %d",
                max(1, min($limit, 100))
            ),
            ['from' => $from, 'to' => $to]
        ));
    }

    /** @return array<string, mixed> */
    public function customerGrowth(string $from, string $to): array
    {
        $this->assertRange($from, $to);

        $signups = $this->db->select(
            "SELECT DATE(u.`created_date`) AS `date`, COUNT(*) AS `signups`
               FROM `users` u
               INNER JOIN `roles` r ON r.`id` = u.`role_id`
              WHERE r.`code` = 'customer'
                AND DATE(u.`created_date`) BETWEEN :from AND :to
                AND u.`is_deleted` = 0
              GROUP BY DATE(u.`created_date`)
              ORDER BY `date`",
            ['from' => $from, 'to' => $to]
        );

        // Repeat rate is the number worth watching for a spice retailer:
        // acquisition is expensive and the category is naturally repeat-purchase.
        $repeat = $this->db->selectOne(
            "SELECT
                COUNT(*)                              AS `buyers`,
                SUM(`order_count` > 1)                AS `repeat_buyers`
               FROM (
                   SELECT `user_id`, COUNT(*) AS `order_count`
                     FROM `orders`
                    WHERE `status` <> 'cancelled' AND `is_deleted` = 0
                      AND DATE(`confirmed_date`) BETWEEN :from AND :to
                    GROUP BY `user_id`
               ) t",
            ['from' => $from, 'to' => $to]
        );

        $buyers = (int) ($repeat['buyers'] ?? 0);
        $repeatBuyers = (int) ($repeat['repeat_buyers'] ?? 0);

        return [
            'signups' => array_map(static fn (array $row): array => [
                'date' => $row['date'],
                'signups' => (int) $row['signups'],
            ], $signups),
            'total_signups' => array_sum(array_map(static fn (array $r): int => (int) $r['signups'], $signups)),
            'buyers' => $buyers,
            'repeat_buyers' => $repeatBuyers,
            'repeat_rate_percent' => $buyers === 0 ? 0.0 : round(($repeatBuyers / $buyers) * 100, 2),
        ];
    }

    /**
     * Promotion effectiveness.
     *
     * @return array<string, mixed>
     */
    public function promotions(): array
    {
        return [
            'coupons' => $this->db->select('SELECT * FROM `vw_coupon_performance` ORDER BY `total_redeemed` DESC LIMIT 50'),
            'referrals' => $this->db->select('SELECT * FROM `vw_referral_summary` ORDER BY `total_earned` DESC, `total_invited` DESC LIMIT 50'),
        ];
    }

    /** @return array<string, mixed> */
    public function operations(): array
    {
        return [
            'couriers' => $this->db->select('SELECT * FROM `vw_courier_performance` ORDER BY `total_shipments` DESC'),
            'executives' => $this->db->select('SELECT * FROM `vw_executive_workload` ORDER BY `completed_assignments` DESC'),
            'commission' => $this->db->select('SELECT * FROM `vw_commission_summary` ORDER BY `total_accrued` DESC'),
        ];
    }

    /**
     * Cancellations and refunds, with reasons.
     *
     * Grouped by reason because the aggregate number is not actionable — "42
     * cancellations" tells nobody anything, while "31 of them said the delivery
     * estimate was too long" is a decision.
     *
     * @return array<string, mixed>
     */
    public function cancellations(string $from, string $to): array
    {
        $this->assertRange($from, $to);

        return [
            'by_reason' => $this->db->select(
                "SELECT COALESCE(NULLIF(TRIM(`cancellation_reason`), ''), 'No reason given') AS `reason`,
                        COUNT(*) AS `count`,
                        ROUND(SUM(`grand_total`), 2) AS `value`
                   FROM `orders`
                  WHERE `status` = 'cancelled'
                    AND DATE(`cancelled_date`) BETWEEN :from AND :to
                    AND `is_deleted` = 0
                  GROUP BY `reason`
                  ORDER BY `count` DESC
                  LIMIT 50",
                ['from' => $from, 'to' => $to]
            ),
            'refunds' => $this->db->selectOne(
                "SELECT COUNT(*) AS `count`,
                        ROUND(COALESCE(SUM(`total_amount`), 0), 2)   AS `total`,
                        ROUND(COALESCE(SUM(`gateway_amount`), 0), 2) AS `to_gateway`,
                        ROUND(COALESCE(SUM(`wallet_amount`), 0), 2)  AS `to_wallet`,
                        SUM(`status` = 'failed')                     AS `failed`
                   FROM `refunds`
                  WHERE DATE(`created_date`) BETWEEN :from AND :to AND `is_deleted` = 0",
                ['from' => $from, 'to' => $to]
            ),
        ];
    }

    /**
     * Range validation.
     *
     * Rejecting an over-wide range with a clear message is better than letting
     * a report run for two minutes and time out behind a proxy with no
     * explanation at all.
     */
    private function assertRange(string $from, string $to): void
    {
        $start = strtotime($from);
        $end = strtotime($to);

        if ($start === false || $end === false) {
            throw new HttpException('Those dates could not be read. Use YYYY-MM-DD.', 422);
        }

        if ($end < $start) {
            throw new HttpException('The end date cannot be before the start date.', 422);
        }

        $days = (int) (($end - $start) / 86400);

        if ($days > self::MAX_RANGE_DAYS) {
            throw new HttpException(
                sprintf(
                    'That range covers %d days. Reports are limited to %d days at a time; '
                    . 'request several ranges instead.',
                    $days,
                    self::MAX_RANGE_DAYS
                ),
                422
            );
        }
    }
}
