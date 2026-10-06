<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\InventoryBatchRepository;
use App\Repositories\PurchaseOrderItemRepository;

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

    public function __construct(
        private readonly Database $db,
        private readonly InventoryService $inventory,
        private readonly InventoryBatchRepository $batches,
        private readonly PurchaseOrderItemRepository $purchaseOrderItems,
    ) {
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
              WHERE DATE(COALESCE(`confirmed_date`, `placed_date`)) = CURDATE()
                AND `is_deleted` = 0
                AND `status` <> 'cancelled'"
        );

        // Counter (POS) sales rung up today. Voided ones never happened, so they
        // are left out; "delivered" is the till's own hand-over flag.
        $pos = $this->db->selectOne(
            "SELECT COUNT(*)                                        AS `sales_today`,
                    COALESCE(SUM(`grand_total`), 0)                 AS `revenue_today`,
                    COALESCE(SUM(`delivery_status` = 'delivered'), 0) AS `delivered_today`,
                    (SELECT COUNT(*) FROM `pos_sales`
                      WHERE DATE(`created_date`) = CURDATE() AND `status` = 'voided' AND `is_deleted` = 0) AS `voided_today`
               FROM `pos_sales`
              WHERE DATE(`created_date`) = CURDATE() AND `status` = 'completed' AND `is_deleted` = 0"
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
                  WHERE `status` IN ('new','under_review') AND `is_deleted` = 0) AS `bulk_enquiries_waiting`,
                (SELECT COUNT(*) FROM `inventory_batches`
                  WHERE `is_deleted` = 0 AND `expiry_date` IS NOT NULL
                    AND `expiry_date` <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
                    AND `quantity` > 0)                                         AS `expiring_soon_stock`,
                -- Same base as InventoryStockRepository::search(): every
                -- variant against the default warehouse, an untracked one
                -- (no inventory_stock row yet) reading as zero stock — so this
                -- count and what the Low Stock Alerts page shows with no
                -- warehouse chosen (its own default) never disagree.
                (SELECT COUNT(*)
                   FROM `product_variants` v
                   INNER JOIN `warehouses` w ON w.`is_default` = 1 AND w.`is_deleted` = 0
                   LEFT JOIN `inventory_stock` s ON s.`product_variant_id` = v.`id`
                        AND s.`warehouse_id` = w.`id` AND s.`is_deleted` = 0
                  WHERE v.`is_deleted` = 0
                    AND s.`reorder_threshold` IS NOT NULL
                    AND COALESCE(s.`quantity`, 0) <= s.`reorder_threshold`)      AS `low_stock_count`,
                (SELECT COUNT(*)
                   FROM `product_variants` v
                   INNER JOIN `warehouses` w ON w.`is_default` = 1 AND w.`is_deleted` = 0
                   LEFT JOIN `inventory_stock` s ON s.`product_variant_id` = v.`id`
                        AND s.`warehouse_id` = w.`id` AND s.`is_deleted` = 0
                  WHERE v.`is_deleted` = 0
                    AND COALESCE(s.`quantity`, 0) <= 0)                          AS `out_of_stock_count`"
        );

        $customerDues = $this->customerDuesSnapshot();

        return [
            // Everything sold today: online orders plus counter (POS) sales.
            'today' => [
                'orders' => (int) ($today['orders_today'] ?? 0) + (int) ($pos['sales_today'] ?? 0),
                'revenue' => round((float) ($today['revenue_today'] ?? 0) + (float) ($pos['revenue_today'] ?? 0), 2),
                'delivered' => (int) ($today['delivered_today'] ?? 0) + (int) ($pos['delivered_today'] ?? 0),
                'cancelled' => (int) ($today['cancelled_today'] ?? 0) + (int) ($pos['voided_today'] ?? 0),
                'online_orders' => (int) ($today['orders_today'] ?? 0),
                'pos_orders' => (int) ($pos['sales_today'] ?? 0),
                'online_revenue' => round((float) ($today['revenue_today'] ?? 0), 2),
                'pos_revenue' => round((float) ($pos['revenue_today'] ?? 0), 2),
            ],
            'pipeline' => array_map(static fn (array $row): array => [
                'status' => $row['status'],
                'count' => (int) $row['count'],
                'value' => (float) $row['value'],
            ], $pipeline),
            // What a supervisor should act on before anything else. Reuses
            // customerDuesSnapshot()'s own overdue_count rather than a second
            // copy of the same SQL.
            'needs_attention' => array_map('intval', $attention ?? [])
                + ['overdue_dues_count' => $customerDues['overdue_count']],
            'last_7_days' => $this->salesSeries(date('Y-m-d', strtotime('-6 days')), date('Y-m-d')),
            'profit_loss' => $this->todaysProfitLoss(),
            'collections' => $this->todaysCollections(),
            'wallet' => $this->walletSnapshot(),
            'customer_dues' => $customerDues,
        ];
    }

    /**
     * POS customer dues / partial payment (brief: Total Due, Partially Paid,
     * Fully Paid, Overdue, Collected Today) — scoped to `is_credit_sale = 1`
     * so an ordinary walk-in sale that was always paid in full at the
     * register never counts toward "Fully Paid" here; that would make the
     * figure meaningless (nearly every sale ever rung up). "Overdue" reuses
     * PosDuePaymentService's own threshold rather than a second copy of it.
     *
     * @return array<string, mixed>
     */
    public function customerDuesSnapshot(): array
    {
        $overdueDays = PosDuePaymentService::overdueAfterDays();

        // Real prepared statements (PDO::ATTR_EMULATE_PREPARES = false) reject
        // a repeated named placeholder, hence :days1/:days2 rather than :days
        // reused — both are bound to the identical value.
        $summary = $this->db->selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN `payment_status` IN ('unpaid', 'partial')
                                   THEN `grand_total` - `amount_paid` ELSE 0 END), 0)        AS `total_due`,
                COALESCE(SUM(`payment_status` = 'partial'), 0)                              AS `partial_count`,
                COALESCE(SUM(`payment_status` = 'paid'), 0)                                 AS `fully_paid_count`,
                COALESCE(SUM(`payment_status` IN ('unpaid', 'partial')
                             AND `created_date` <= DATE_SUB(NOW(), INTERVAL :days1 DAY)), 0) AS `overdue_count`,
                COALESCE(SUM(CASE WHEN `payment_status` IN ('unpaid', 'partial')
                                   AND `created_date` <= DATE_SUB(NOW(), INTERVAL :days2 DAY)
                                   THEN `grand_total` - `amount_paid` ELSE 0 END), 0)         AS `overdue_amount`
               FROM `pos_sales`
              WHERE `is_credit_sale` = 1 AND `status` = 'completed' AND `is_deleted` = 0",
            ['days1' => $overdueDays, 'days2' => $overdueDays]
        );

        $collectedToday = $this->db->scalar(
            "SELECT COALESCE(SUM(`amount`), 0) FROM `pos_sale_payments`
              WHERE `payment_date` = CURDATE() AND `status` = 'completed' AND `is_deleted` = 0"
        );

        return [
            'total_due' => round((float) ($summary['total_due'] ?? 0), 2),
            'partially_paid_count' => (int) ($summary['partial_count'] ?? 0),
            'fully_paid_count' => (int) ($summary['fully_paid_count'] ?? 0),
            'overdue_count' => (int) ($summary['overdue_count'] ?? 0),
            'overdue_amount' => round((float) ($summary['overdue_amount'] ?? 0), 2),
            'collected_today' => round((float) ($collectedToday ?? 0), 2),
            'overdue_after_days' => $overdueDays,
        ];
    }

    /**
     * The wallet totals a supervisor's dashboard needs at a glance — every
     * figure here is a plain aggregate over the wallet ledger and the refund
     * tables WalletService/OrderService already write to; nothing new is
     * tracked to produce this.
     *
     * @return array<string, mixed>
     */
    public function walletSnapshot(): array
    {
        $accounts = $this->db->selectOne(
            "SELECT COUNT(*) AS `account_count`, COALESCE(SUM(`balance_amount`), 0) AS `total_balance`
               FROM `wallet_accounts` WHERE `is_deleted` = 0"
        );

        $movement = $this->db->selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN `direction` = 'credit' THEN `amount` ELSE 0 END), 0) AS `total_credits`,
                COALESCE(SUM(CASE WHEN `direction` = 'debit'  THEN `amount` ELSE 0 END), 0) AS `total_debits`,
                COALESCE(SUM(CASE WHEN `direction` = 'credit' AND `source` = 'order_refund'
                                   THEN `amount` ELSE 0 END), 0)                            AS `total_refund_credits`
               FROM `wallet_transactions` WHERE `is_deleted` = 0"
        );

        // Pending refunds: online orders only — a POS refund (cash or wallet)
        // settles the moment it's recorded, so it has no pending state to sit in.
        $pending = $this->db->selectOne(
            "SELECT COUNT(*) AS `count`, COALESCE(SUM(`total_amount`), 0) AS `amount`
               FROM `refunds` WHERE `status` IN ('pending', 'processing') AND `is_deleted` = 0"
        );

        return [
            'account_count' => (int) ($accounts['account_count'] ?? 0),
            'total_balance' => round((float) ($accounts['total_balance'] ?? 0), 2),
            'total_credits' => round((float) ($movement['total_credits'] ?? 0), 2),
            'total_debits' => round((float) ($movement['total_debits'] ?? 0), 2),
            'total_refund_credits' => round((float) ($movement['total_refund_credits'] ?? 0), 2),
            'pending_refunds' => [
                'count' => (int) ($pending['count'] ?? 0),
                'amount' => round((float) ($pending['amount'] ?? 0), 2),
            ],
        ];
    }

    /**
     * Today's collections broken down into the five buckets the dashboard
     * shows, plus an 'other' catch-all so no collected rupee is silently
     * dropped. Online orders are only ever 'upi' or 'cod' (013_cash_on_delivery.sql)
     * — cod folds into the Cash bucket, since it's cash in hand either way,
     * just collected at the door instead of the till. POS's 'card' is the
     * "POS" (card-machine) bucket.
     *
     * @return array<string, array{count:int, amount:float}>
     */
    public function todaysCollections(): array
    {
        $today = date('Y-m-d');

        $buckets = [
            'cash' => ['count' => 0, 'amount' => 0.0],
            'upi' => ['count' => 0, 'amount' => 0.0],
            'pos' => ['count' => 0, 'amount' => 0.0],
            'other' => ['count' => 0, 'amount' => 0.0],
        ];

        $posRows = $this->db->select(
            "SELECT `payment_method`, COUNT(*) AS `count`, COALESCE(SUM(`grand_total`), 0) AS `amount`
               FROM `pos_sales`
              WHERE DATE(`created_date`) = :today AND `status` = 'completed' AND `is_deleted` = 0
              GROUP BY `payment_method`",
            ['today' => $today]
        );

        foreach ($posRows as $row) {
            $key = match ($row['payment_method']) {
                'cash' => 'cash',
                'upi' => 'upi',
                'card' => 'pos',
                default => 'other',
            };
            $buckets[$key]['count'] += (int) $row['count'];
            $buckets[$key]['amount'] += (float) $row['amount'];
        }

        $orderRows = $this->db->select(
            "SELECT `payment_method`, COUNT(*) AS `count`, COALESCE(SUM(`grand_total`), 0) AS `amount`
               FROM `orders`
              WHERE DATE(`confirmed_date`) = :today AND `payment_status` = 'paid' AND `is_deleted` = 0
              GROUP BY `payment_method`",
            ['today' => $today]
        );

        foreach ($orderRows as $row) {
            $key = $row['payment_method'] === 'cod' ? 'cash' : 'upi';
            $buckets[$key]['count'] += (int) $row['count'];
            $buckets[$key]['amount'] += (float) $row['amount'];
        }

        foreach ($buckets as &$bucket) {
            $bucket['amount'] = round($bucket['amount'], 2);
        }

        return $buckets;
    }

    /**
     * One bucket's individual transactions for a given day — the drill-down
     * behind clicking a collections tile. Mirrors todaysCollections()'s own
     * bucket-to-payment_method mapping exactly, so the total on the tile and
     * the sum of what this returns always agree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectionHistory(string $bucket, string $date): array
    {
        $this->assertRange($date, $date);

        if (!in_array($bucket, ['cash', 'upi', 'pos', 'other'], true)) {
            throw new HttpException('Unknown collection type: ' . $bucket, 422);
        }

        $transactions = [];

        if ($bucket === 'other') {
            $posRows = $this->db->select(
                "SELECT s.`sale_number` AS `reference`, s.`created_date`, s.`grand_total` AS `amount`, s.`payment_method`, 'pos' AS `channel`,
                        u.`full_name` AS `cashier_name`
                   FROM `pos_sales` s
                   LEFT JOIN `users` u ON u.`id` = s.`cashier_id`
                  WHERE DATE(s.`created_date`) = :date AND s.`status` = 'completed' AND s.`is_deleted` = 0
                    AND s.`payment_method` NOT IN ('cash', 'upi', 'card')
                  ORDER BY s.`created_date` DESC",
                ['date' => $date]
            );
        } else {
            $posMethod = $bucket === 'pos' ? 'card' : $bucket;
            $posRows = $this->db->select(
                "SELECT s.`sale_number` AS `reference`, s.`created_date`, s.`grand_total` AS `amount`, s.`payment_method`, 'pos' AS `channel`,
                        u.`full_name` AS `cashier_name`
                   FROM `pos_sales` s
                   LEFT JOIN `users` u ON u.`id` = s.`cashier_id`
                  WHERE DATE(s.`created_date`) = :date AND s.`status` = 'completed' AND s.`is_deleted` = 0
                    AND s.`payment_method` = :method
                  ORDER BY s.`created_date` DESC",
                ['date' => $date, 'method' => $posMethod]
            );
        }

        $transactions = array_merge($transactions, $posRows);

        // Only cash (cod) and upi have an online-order counterpart —
        // 'pos'/'other' are POS-only concepts.
        if ($bucket === 'cash' || $bucket === 'upi') {
            $orderMethod = $bucket === 'cash' ? 'cod' : 'upi';
            $orderRows = $this->db->select(
                "SELECT `order_number` AS `reference`, `confirmed_date` AS `created_date`, `grand_total` AS `amount`,
                        `payment_method`, 'order' AS `channel`
                   FROM `orders`
                  WHERE DATE(`confirmed_date`) = :date AND `payment_status` = 'paid' AND `is_deleted` = 0
                    AND `payment_method` = :method
                  ORDER BY `confirmed_date` DESC",
                ['date' => $date, 'method' => $orderMethod]
            );
            $transactions = array_merge($transactions, $orderRows);
        }

        usort($transactions, static fn (array $a, array $b): int => strcmp((string) $b['created_date'], (string) $a['created_date']));

        return array_map(static fn (array $row): array => [
            'reference' => $row['reference'],
            'date' => $row['created_date'],
            'amount' => (float) $row['amount'],
            'payment_method' => $row['payment_method'],
            'channel' => $row['channel'],
            // Who rang it up — only counter sales have a cashier.
            'cashier_name' => $row['cashier_name'] ?? null,
        ], $transactions);
    }

    /**
     * Every online order and POS sale counted in dashboard()'s 'today'
     * figures — the same WHERE clauses that produce orders/revenue today,
     * row by row rather than summed. Also what "Today's sales" drills into
     * (todaysProfitLoss()'s 'sales' is the same online+POS total, just a
     * confirmed order counts the moment it's confirmed whether or not it's
     * been paid — see that method's own doc comment), so one list serves
     * three dashboard tiles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesToday(): array
    {
        return $this->unionOrdersAndPos(
            "DATE(COALESCE(o.`confirmed_date`, o.`placed_date`)) = CURDATE() AND o.`status` <> 'cancelled' AND o.`is_deleted` = 0",
            "DATE(s.`created_date`) = CURDATE() AND s.`status` = 'completed' AND s.`is_deleted` = 0"
        );
    }

    /** What "Delivered today" drills into. @return array<int, array<string, mixed>> */
    public function deliveredToday(): array
    {
        return $this->unionOrdersAndPos(
            "DATE(o.`confirmed_date`) = CURDATE() AND o.`status` = 'delivered' AND o.`is_deleted` = 0",
            "DATE(s.`created_date`) = CURDATE() AND s.`delivery_status` = 'delivered' AND s.`status` = 'completed' AND s.`is_deleted` = 0"
        );
    }

    /**
     * What "Cancelled today" drills into. A voided POS sale is included even
     * though it is excluded from every other "today" list — it is the one
     * place this figure needs it, the same way dashboard()'s own
     * cancelled_today counts voided_today alongside cancelled orders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cancelledToday(): array
    {
        return $this->unionOrdersAndPos(
            "DATE(COALESCE(o.`confirmed_date`, o.`placed_date`)) = CURDATE() AND o.`status` = 'cancelled' AND o.`is_deleted` = 0",
            "DATE(s.`created_date`) = CURDATE() AND s.`status` = 'voided' AND s.`is_deleted` = 0"
        );
    }

    /**
     * What "Total collection" drills into — money actually in hand today:
     * paid online orders plus completed POS sales (a COD order not yet paid
     * is excluded here even though it already counts toward Sales today).
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectionToday(): array
    {
        return $this->unionOrdersAndPos(
            "DATE(o.`confirmed_date`) = CURDATE() AND o.`payment_status` = 'paid' AND o.`is_deleted` = 0",
            "DATE(s.`created_date`) = CURDATE() AND s.`status` = 'completed' AND s.`is_deleted` = 0"
        );
    }

    /**
     * Shared shape for every dashboard drill-down above: one row per online
     * order or POS sale, merged and sorted newest first. Each caller only
     * varies which rows qualify.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unionOrdersAndPos(string $orderWhere, string $posWhere): array
    {
        $orders = $this->db->select(
            "SELECT o.`uuid`, o.`order_number` AS `reference`, 'online' AS `channel`,
                    COALESCE(o.`confirmed_date`, o.`placed_date`) AS `date`,
                    o.`grand_total` AS `amount`, o.`status`, o.`payment_status`,
                    u.`full_name` AS `customer_name`, u.`mobile` AS `customer_mobile`
               FROM `orders` o
               INNER JOIN `users` u ON u.`id` = o.`user_id`
              WHERE {$orderWhere}"
        );

        $pos = $this->db->select(
            "SELECT s.`uuid`, s.`sale_number` AS `reference`, 'pos' AS `channel`,
                    s.`created_date` AS `date`, s.`grand_total` AS `amount`,
                    s.`status`, s.`payment_status`,
                    COALESCE(c.`full_name`, s.`walk_in_name`, 'Walk-in customer') AS `customer_name`,
                    COALESCE(c.`mobile`, s.`walk_in_mobile`) AS `customer_mobile`
               FROM `pos_sales` s
               LEFT JOIN `users` c ON c.`id` = s.`customer_id`
              WHERE {$posWhere}"
        );

        $rows = array_merge($orders, $pos);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return array_map(static fn (array $row): array => [
            'uuid' => $row['uuid'],
            'reference' => $row['reference'],
            'channel' => $row['channel'],
            'date' => $row['date'],
            'amount' => (float) $row['amount'],
            'status' => $row['status'],
            'payment_status' => $row['payment_status'],
            'customer_name' => $row['customer_name'],
            'customer_mobile' => $row['customer_mobile'],
        ], $rows);
    }

    /**
     * What the "Loss" dashboard tile drills into — today's damage/lost
     * write-offs (InventoryBatchRepository::withExpiry() naming aside, this
     * reuses InventoryService::damageLossReport() row shape) plus batches
     * whose expiry lands today, as one merged list. Mirrors
     * todaysProfitLoss()'s own 'loss' = damage/lost value + expired-today
     * value, itemised instead of summed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lossToday(): array
    {
        $today = date('Y-m-d');

        $damageLoss = $this->inventory->damageLossReport(['from' => $today, 'to' => $today]);

        // damageLossReport()'s rows come from InventoryMovementRepository::search(),
        // which joins product_variants but not products — sku/variant_name
        // are what's actually on the row, no product_name.
        $writeOffs = array_map(static fn (array $row): array => [
            'type' => $row['movement_type'],
            'sku' => $row['sku'] ?? null,
            'variant_name' => $row['variant_name'] ?? null,
            'quantity' => (float) $row['quantity'],
            'value' => $row['line_value'],
            'date' => $row['created_date'],
        ], $damageLoss['rows']);

        $expiring = $this->db->select(
            "SELECT p.`name` AS `product_name`, v.`variant_name`, b.`quantity`,
                    (b.`quantity` * b.`unit_cost`) AS `value`, b.`expiry_date` AS `date`
               FROM `inventory_batches` b
               INNER JOIN `product_variants` v ON v.`id` = b.`product_variant_id`
               INNER JOIN `products` p ON p.`id` = v.`product_id`
              WHERE b.`is_deleted` = 0 AND b.`expiry_date` = :today
                AND b.`quantity` > 0 AND b.`unit_cost` IS NOT NULL",
            ['today' => $today]
        );

        $expiring = array_map(static fn (array $row): array => [
            'type' => 'expired',
            'product_name' => $row['product_name'],
            'variant_name' => $row['variant_name'],
            'quantity' => (float) $row['quantity'],
            'value' => (float) $row['value'],
            'date' => $row['date'],
        ], $expiring);

        return array_merge($writeOffs, $expiring);
    }

    /**
     * Today's Sales, Profit, Loss and Total Collection.
     *
     * Profit is an ESTIMATE: each line's revenue (net of tax) minus the
     * variant's current average cost — this system snapshots cost at inward,
     * not per sale, so a precise historical margin per transaction isn't
     * available. When a variant is stocked across more than one warehouse,
     * its average cost is averaged across them for this same-day figure.
     *
     * Sales counts a confirmed order the moment it's confirmed, whether or
     * not it's been paid yet (a COD order is a sale today even though the
     * cash arrives at the door later) — Total Collection is the narrower,
     * "money actually in hand today" figure: paid online orders plus
     * completed POS sales.
     *
     * Loss = today's damage/lost write-offs (InventoryService::damageLossReport(),
     * unchanged) plus the value of batches whose expiry lands today.
     *
     * @return array<string, float>
     */
    public function todaysProfitLoss(): array
    {
        $today = date('Y-m-d');
        $avgCostJoin = $this->avgCostJoinSql();

        $orderProfit = (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(oi.`taxable_value` - COALESCE(ac.`avg_cost`, 0) * oi.`quantity`), 0)
               FROM `order_items` oi
               INNER JOIN `orders` o ON o.`id` = oi.`order_id`
               {$avgCostJoin} ON ac.`product_variant_id` = oi.`variant_id`
              WHERE DATE(o.`confirmed_date`) = :today
                AND o.`status` <> 'cancelled' AND o.`is_deleted` = 0 AND oi.`is_deleted` = 0",
            ['today' => $today]
        ) ?? 0);

        $posProfit = (float) ($this->db->scalar(
            "SELECT COALESCE(SUM((psi.`line_total` - psi.`tax_amount`) - COALESCE(ac.`avg_cost`, 0) * psi.`quantity`), 0)
               FROM `pos_sale_items` psi
               INNER JOIN `pos_sales` s ON s.`id` = psi.`pos_sale_id`
               {$avgCostJoin} ON ac.`product_variant_id` = psi.`product_variant_id`
              WHERE DATE(s.`created_date`) = :today
                AND s.`status` = 'completed' AND s.`is_deleted` = 0 AND psi.`is_deleted` = 0",
            ['today' => $today]
        ) ?? 0);

        // Same basis as $orderProfit above: an online order is a sale once it
        // is confirmed (paid, or COD approved) — not while it waits for payment.
        $onlineSalesToday = (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(`grand_total`), 0) FROM `orders`
              WHERE DATE(`confirmed_date`) = :today AND `status` <> 'cancelled' AND `is_deleted` = 0",
            ['today' => $today]
        ) ?? 0);

        $posSalesToday = (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(`grand_total`), 0) FROM `pos_sales`
              WHERE DATE(`created_date`) = :today AND `status` = 'completed' AND `is_deleted` = 0",
            ['today' => $today]
        ) ?? 0);

        $onlineCollectedToday = (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(`grand_total`), 0) FROM `orders`
              WHERE DATE(`confirmed_date`) = :today AND `payment_status` = 'paid' AND `is_deleted` = 0",
            ['today' => $today]
        ) ?? 0);

        $damageLoss = $this->inventory->damageLossReport(['from' => $today, 'to' => $today]);
        $expiredTodayValue = $this->batches->valueExpiringOn($today);

        return [
            'sales' => round($onlineSalesToday + $posSalesToday, 2),
            'total_collection' => round($onlineCollectedToday + $posSalesToday, 2),
            'profit' => round($orderProfit + $posProfit, 2),
            'loss' => round($damageLoss['summary']['total_value'] + $expiredTodayValue, 2),
        ];
    }

    /**
     * The Profit & Loss page: item-wise margins plus invoice loss, over a
     * date range rather than just today — see itemProfitLoss() and
     * invoiceLossReport() for what each half actually computes and why.
     *
     * @return array<string, mixed>
     */
    public function profitLoss(string $from, string $to): array
    {
        $items = $this->itemProfitLoss($from, $to);
        $invoiceLoss = $this->invoiceLossReport($from, $to);
        $damageLoss = $this->inventory->damageLossReport(['from' => $from, 'to' => $to]);
        $vendorReliability = $this->vendorReliability($from, $to, $invoiceLoss, $damageLoss);

        $totalRevenue = array_sum(array_column($items, 'revenue'));
        $totalCost = array_sum(array_column($items, 'cost'));
        $totalProfit = $totalRevenue - $totalCost;

        // "As of" stock values reconstruct quantity from the movement
        // ledger (the only place history actually lives) but price it at
        // TODAY's average cost — this system never snapshotted average cost
        // itself day by day, only quantity movements, so a true historical
        // valuation isn't available. Same estimate convention as Profit.
        $openingStock = $this->stockValueAsOf($from, inclusive: false);
        $closingStock = $this->stockValueAsOf($to, inclusive: true);

        return [
            'items' => $items,
            'invoice_loss' => $invoiceLoss,
            'damage_loss' => $damageLoss,
            'vendor_reliability' => $vendorReliability,
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_cost' => round($totalCost, 2),
                'total_profit' => round($totalProfit, 2),
                'margin_percent' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0.0,
                'opening_stock_value' => round($openingStock, 2),
                'closing_stock_value' => round($closingStock, 2),
                'net_after_invoice_loss' => round($totalProfit - $invoiceLoss['summary']['total_value'], 2),
            ],
        ];
    }

    /**
     * Total inventory value reconstructed as of a date: every variant's
     * quantity-so-far (summed from inventory_movements up to that date,
     * since that ledger — unlike inventory_stock, which only holds the
     * CURRENT balance — is the one place history actually lives) priced at
     * its CURRENT average cost. Historical average cost was never
     * snapshotted day by day, so this is an estimate: "what this quantity
     * would be worth today," not "what it was actually worth on that date."
     *
     * @param bool $inclusive true = up to and including the date (closing
     *                        stock); false = strictly before it (opening
     *                        stock — the position at the moment the period began)
     */
    private function stockValueAsOf(string $date, bool $inclusive): float
    {
        $comparison = $inclusive ? '<=' : '<';

        return (float) ($this->db->scalar(
            "SELECT COALESCE(SUM(m.`running_qty` * COALESCE(s.`average_cost`, 0)), 0)
               FROM (
                    SELECT `product_variant_id`, `warehouse_id`, SUM(`quantity_delta`) AS `running_qty`
                      FROM `inventory_movements`
                     WHERE DATE(`created_date`) {$comparison} :date
                     GROUP BY `product_variant_id`, `warehouse_id`
               ) m
               LEFT JOIN `inventory_stock` s
                      ON s.`product_variant_id` = m.`product_variant_id` AND s.`warehouse_id` = m.`warehouse_id`",
            ['date' => $date]
        ) ?? 0);
    }

    /**
     * Per-item profit and loss: each variant's revenue (net of tax) across
     * both online orders and POS sales, minus an ESTIMATED cost (current
     * average cost x units sold — this system snapshots cost at inward, not
     * per sale, so a precise historical cost-of-goods-sold per transaction
     * isn't available; same approximation todaysProfitLoss() makes).
     * Ordered worst-margin first — the items actually losing money are the
     * ones worth seeing without scrolling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemProfitLoss(string $from, string $to, int $limit = 200): array
    {
        $this->assertRange($from, $to);
        $avgCostJoin = $this->avgCostJoinSql();

        $rows = $this->db->select(
            sprintf(
                'SELECT v.`uuid` AS `variant_uuid`, v.`sku`, v.`variant_name`, p.`name` AS `product_name`,
                        SUM(combined.`qty`)     AS `units_sold`,
                        SUM(combined.`revenue`) AS `revenue`,
                        SUM(combined.`cost`)    AS `cost`
                   FROM (
                        SELECT oi.`variant_id` AS `variant_id`, oi.`quantity` AS `qty`,
                               oi.`taxable_value` AS `revenue`,
                               COALESCE(ac.`avg_cost`, 0) * oi.`quantity` AS `cost`
                          FROM `order_items` oi
                          INNER JOIN `orders` o ON o.`id` = oi.`order_id`
                          %1$s ON ac.`product_variant_id` = oi.`variant_id`
                         WHERE DATE(o.`confirmed_date`) BETWEEN :from1 AND :to1
                           AND o.`status` <> \'cancelled\' AND o.`is_deleted` = 0 AND oi.`is_deleted` = 0

                        UNION ALL

                        SELECT psi.`product_variant_id` AS `variant_id`, psi.`quantity` AS `qty`,
                               (psi.`line_total` - psi.`tax_amount`) AS `revenue`,
                               COALESCE(ac.`avg_cost`, 0) * psi.`quantity` AS `cost`
                          FROM `pos_sale_items` psi
                          INNER JOIN `pos_sales` s ON s.`id` = psi.`pos_sale_id`
                          %1$s ON ac.`product_variant_id` = psi.`product_variant_id`
                         WHERE DATE(s.`created_date`) BETWEEN :from2 AND :to2
                           AND s.`status` = \'completed\' AND s.`is_deleted` = 0 AND psi.`is_deleted` = 0
                   ) combined
                   INNER JOIN `product_variants` v ON v.`id` = combined.`variant_id`
                   INNER JOIN `products` p ON p.`id` = v.`product_id`
                  GROUP BY v.`id`, v.`uuid`, v.`sku`, v.`variant_name`, p.`name`
                  ORDER BY (SUM(combined.`revenue`) - SUM(combined.`cost`)) ASC
                  LIMIT %2$d',
                $avgCostJoin,
                max(1, min($limit, 500))
            ),
            ['from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to]
        );

        return array_map(static function (array $row): array {
            $revenue = (float) $row['revenue'];
            $cost = (float) $row['cost'];
            $profit = $revenue - $cost;

            return [
                'variant_uuid' => $row['variant_uuid'],
                'sku' => $row['sku'],
                'product_name' => $row['product_name'],
                'variant_name' => $row['variant_name'],
                'units_sold' => (float) $row['units_sold'],
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'profit' => round($profit, 2),
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Invoice loss: purchase order lines where the vendor's invoice claimed
     * more than what was actually received — real money paid for stock that
     * never arrived, distinct from damage/theft of stock that DID arrive
     * (that's InventoryService::damageLossReport()). Sourced from
     * purchase_order_items.invoiced_quantity, not a stock movement, since
     * the inventory ledger already correctly reflects what was received.
     *
     * @return array<string, mixed>
     */
    public function invoiceLossReport(string $from, string $to): array
    {
        $this->assertRange($from, $to);

        $shortfalls = $this->purchaseOrderItems->invoiceShortfalls(['from' => $from, 'to' => $to]);
        $totalValue = 0.0;

        $rows = array_map(function (array $row) use (&$totalValue): array {
            $shortQuantity = (float) $row['invoiced_quantity'] - (float) $row['quantity'];
            $unitCost = $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null;
            $lineValue = $unitCost === null ? null : $unitCost * $shortQuantity;
            $totalValue += $lineValue ?? 0.0;

            return [
                'date' => $row['purchase_date'],
                'sku' => $row['sku'],
                'variant_name' => $row['variant_name'],
                'warehouse_name' => $row['warehouse_name'],
                'vendor_name' => $row['vendor_name'],
                'po_number' => $row['po_number'],
                'purchase_order_uuid' => $row['purchase_order_uuid'],
                'invoice_reference' => $row['invoice_reference'],
                'invoiced_quantity' => (float) $row['invoiced_quantity'],
                'received_quantity' => (float) $row['quantity'],
                'short_quantity' => $shortQuantity,
                'unit_cost' => $unitCost,
                'value' => $lineValue,
            ];
        }, $shortfalls);

        return [
            'rows' => $rows,
            'summary' => [
                'incident_count' => count($rows),
                'total_value' => round($totalValue, 2),
            ],
        ];
    }

    /**
     * How much of what you paid each vendor turned out to be a loss — the
     * same invoice loss and damage/loss figures this page already computes,
     * grouped by vendor rather than by item. Damage/loss is only
     * attributable to a vendor when the batch it happened to could be
     * traced back to a purchase order (see resolveBatchSources() /
     * damageLossReport() — a best-effort batch_no match, not a guarantee),
     * so a vendor with untraceable damage may show a lower loss rate than
     * is actually the case. Worst loss-rate first.
     *
     * @param array<string, mixed> $invoiceLoss the same shape invoiceLossReport() returns
     * @param array<string, mixed> $damageLoss  the same shape InventoryService::damageLossReport() returns
     *
     * @return array<int, array<string, mixed>>
     */
    public function vendorReliability(string $from, string $to, array $invoiceLoss, array $damageLoss): array
    {
        $this->assertRange($from, $to);

        $purchases = $this->db->select(
            'SELECT ve.`uuid` AS `vendor_uuid`, ve.`name` AS `vendor_name`,
                    COUNT(DISTINCT po.`id`) AS `po_count`,
                    COALESCE(SUM(po.`grand_total`), 0) AS `total_value`
               FROM `purchase_orders` po
               INNER JOIN `vendors` ve ON ve.`id` = po.`vendor_id`
              WHERE DATE(po.`purchase_date`) BETWEEN :from AND :to AND po.`is_deleted` = 0
              GROUP BY ve.`id`, ve.`uuid`, ve.`name`',
            ['from' => $from, 'to' => $to]
        );

        if ($purchases === []) {
            return [];
        }

        $invoiceLossByVendor = [];

        foreach ($invoiceLoss['rows'] as $row) {
            $vendor = $row['vendor_name'];
            $invoiceLossByVendor[$vendor]['count'] = ($invoiceLossByVendor[$vendor]['count'] ?? 0) + 1;
            $invoiceLossByVendor[$vendor]['value'] = ($invoiceLossByVendor[$vendor]['value'] ?? 0.0) + ($row['value'] ?? 0.0);
        }

        $damageByVendor = [];

        foreach ($damageLoss['rows'] as $row) {
            if ($row['vendor_name'] === null) {
                continue;
            }

            $vendor = $row['vendor_name'];
            $damageByVendor[$vendor]['count'] = ($damageByVendor[$vendor]['count'] ?? 0) + 1;
            $damageByVendor[$vendor]['value'] = ($damageByVendor[$vendor]['value'] ?? 0.0) + ($row['line_value'] ?? 0.0);
        }

        $result = array_map(static function (array $purchase) use ($invoiceLossByVendor, $damageByVendor): array {
            $name = $purchase['vendor_name'];
            $invoiceLoss = $invoiceLossByVendor[$name] ?? ['count' => 0, 'value' => 0.0];
            $damageLoss = $damageByVendor[$name] ?? ['count' => 0, 'value' => 0.0];
            $totalLoss = $invoiceLoss['value'] + $damageLoss['value'];
            $totalValue = (float) $purchase['total_value'];

            return [
                'vendor_uuid' => $purchase['vendor_uuid'],
                'vendor_name' => $name,
                'po_count' => (int) $purchase['po_count'],
                'total_value' => round($totalValue, 2),
                'invoice_loss_count' => $invoiceLoss['count'],
                'invoice_loss_value' => round($invoiceLoss['value'], 2),
                'damage_loss_count' => $damageLoss['count'],
                'damage_loss_value' => round($damageLoss['value'], 2),
                'total_loss_value' => round($totalLoss, 2),
                'loss_rate_percent' => $totalValue > 0 ? round(($totalLoss / $totalValue) * 100, 2) : 0.0,
            ];
        }, $purchases);

        usort($result, static fn (array $a, array $b): int => $b['loss_rate_percent'] <=> $a['loss_rate_percent']);

        return $result;
    }

    /** The weighted average cost per variant, across whichever warehouse(s) it's stocked in — shared by todaysProfitLoss() and itemProfitLoss(). */
    private function avgCostJoinSql(): string
    {
        return 'LEFT JOIN (
                SELECT `product_variant_id`, AVG(`average_cost`) AS `avg_cost`
                  FROM `inventory_stock`
                 WHERE `average_cost` IS NOT NULL
                 GROUP BY `product_variant_id`
            ) ac';
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
            // Channel split (053): online orders vs till (shop) sales.
            'online_orders' => (int) ($row['online_orders'] ?? $row['order_count']),
            'online_sales' => (float) ($row['online_sales'] ?? $row['gross_sales']),
            'pos_orders' => (int) ($row['pos_orders'] ?? 0),
            'pos_sales' => (float) ($row['pos_sales'] ?? 0),
            'pos_collected' => (float) ($row['pos_collected'] ?? 0),
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
            'online_units' => (int) $row['online_units'],
            'pos_units' => (int) $row['pos_units'],
        ], $this->db->select(
            sprintf(
                "SELECT combined.`product_name`, combined.`sku`,
                        SUM(combined.`qty`)                         AS `units_sold`,
                        SUM(combined.`orders`)                      AS `order_count`,
                        ROUND(SUM(combined.`revenue`), 2)           AS `revenue`,
                        SUM(CASE WHEN combined.`channel` = 'online' THEN combined.`qty` ELSE 0 END) AS `online_units`,
                        SUM(CASE WHEN combined.`channel` = 'pos' THEN combined.`qty` ELSE 0 END)    AS `pos_units`
                   FROM (
                        SELECT 'online' AS `channel`, oi.`product_name`, oi.`sku`,
                               SUM(oi.`quantity`) AS `qty`, COUNT(DISTINCT oi.`order_id`) AS `orders`,
                               SUM(oi.`line_payable`) AS `revenue`
                          FROM `order_items` oi
                          INNER JOIN `orders` o ON o.`id` = oi.`order_id`
                         WHERE DATE(o.`confirmed_date`) BETWEEN :from1 AND :to1
                           AND o.`status` <> 'cancelled'
                           AND o.`is_deleted` = 0 AND oi.`is_deleted` = 0
                         GROUP BY oi.`product_name`, oi.`sku`

                        UNION ALL

                        SELECT 'pos', psi.`product_name`, psi.`sku`,
                               SUM(psi.`quantity`), COUNT(DISTINCT psi.`pos_sale_id`),
                               SUM(psi.`line_total`)
                          FROM `pos_sale_items` psi
                          INNER JOIN `pos_sales` ps ON ps.`id` = psi.`pos_sale_id`
                         WHERE DATE(ps.`created_date`) BETWEEN :from2 AND :to2
                           AND ps.`status` = 'completed'
                           AND ps.`is_deleted` = 0 AND psi.`is_deleted` = 0
                         GROUP BY psi.`product_name`, psi.`sku`
                   ) combined
                  GROUP BY combined.`product_name`, combined.`sku`
                  ORDER BY `revenue` DESC
                  LIMIT %d",
                max(1, min($limit, 100))
            ),
            ['from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to]
        ));
    }

    public function slowMovingProducts(int $days = 30, int $limit = 20): array
    {
        $days = max(1, min($days, 365));

        return $this->db->select(
            sprintf(
                "SELECT
                     v.`uuid` AS `variant_uuid`, v.`variant_name`, v.`sku`,
                     p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`category_id`,
                     COALESCE(sold.`units_sold`, 0) AS `units_sold`,
                     COALESCE(stock.`qty_on_hand`, 0) AS `qty_on_hand`
                   FROM `product_variants` v
                   INNER JOIN `products` p ON p.`id` = v.`product_id`
                   INNER JOIN (
                       SELECT `product_variant_id`, SUM(`quantity`) AS `qty_on_hand`
                         FROM `inventory_stock`
                        WHERE `is_deleted` = 0
                        GROUP BY `product_variant_id`
                       HAVING SUM(`quantity`) > 0
                   ) stock ON stock.`product_variant_id` = v.`id`
                   LEFT JOIN (
                       SELECT `variant_id`, SUM(`qty`) AS `units_sold` FROM (
                           SELECT oi.`variant_id`, oi.`quantity` AS `qty`
                             FROM `order_items` oi
                             INNER JOIN `orders` o ON o.`id` = oi.`order_id`
                            WHERE o.`confirmed_date` >= DATE_SUB(NOW(), INTERVAL %d DAY)
                              AND o.`status` <> 'cancelled' AND o.`is_deleted` = 0 AND oi.`is_deleted` = 0
                           UNION ALL
                           SELECT psi.`product_variant_id` AS `variant_id`, psi.`quantity` AS `qty`
                             FROM `pos_sale_items` psi
                             INNER JOIN `pos_sales` ps ON ps.`id` = psi.`pos_sale_id`
                            WHERE ps.`created_date` >= DATE_SUB(NOW(), INTERVAL %d DAY)
                              AND ps.`status` <> 'voided' AND ps.`is_deleted` = 0 AND psi.`is_deleted` = 0
                       ) combined
                        GROUP BY `variant_id`
                   ) sold ON sold.`variant_id` = v.`id`
                  WHERE v.`is_deleted` = 0 AND v.`is_active` = 1
                    AND p.`is_deleted` = 0 AND p.`is_active` = 1 AND p.`status` = 'published'
                  ORDER BY `units_sold` ASC, `qty_on_hand` DESC
                  LIMIT %d",
                $days,
                $days,
                max(1, min($limit, 100))
            )
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(string $from, string $to, int $limit = 20): array
    {
        $this->assertRange($from, $to);

        return array_map(static fn (array $row): array => [
            'uuid' => $row['uuid'],
            'customer_name' => $row['full_name'],
            // Masked. A report that circulates by email should not carry a
            // column of customer phone numbers.
            'mobile' => substr((string) $row['mobile'], 0, 2)
                . str_repeat('X', max(0, strlen((string) $row['mobile']) - 4))
                . substr((string) $row['mobile'], -2),
            'order_count' => (int) $row['order_count'],
            'total_spent' => (float) $row['total_spent'],
            'last_order_date' => $row['last_order_date'],
            // Balance is not masked — an amount, not an identifier, and the
            // whole reason it's here is so a supervisor can act on it.
            'wallet_balance' => round((float) $row['wallet_balance'], 2),
            'wallet_frozen' => (bool) $row['wallet_frozen'],
        ], $this->db->select(
            sprintf(
                "SELECT u.`uuid`, u.`full_name`, u.`mobile`,
                        SUM(sales.`n`)                       AS `order_count`,
                        ROUND(SUM(sales.`spent`), 2)         AS `total_spent`,
                        MAX(sales.`last_date`)               AS `last_order_date`,
                        COALESCE(w.`balance_amount`, 0)      AS `wallet_balance`,
                        COALESCE(w.`is_frozen`, 0)           AS `wallet_frozen`
                   FROM (
                        SELECT o.`user_id` AS `user_id`, COUNT(*) AS `n`, SUM(o.`grand_total`) AS `spent`,
                               MAX(o.`confirmed_date`) AS `last_date`
                          FROM `orders` o
                         WHERE DATE(o.`confirmed_date`) BETWEEN :from1 AND :to1
                           AND o.`status` <> 'cancelled' AND o.`is_deleted` = 0
                         GROUP BY o.`user_id`

                        UNION ALL

                        SELECT s.`customer_id`, COUNT(*), SUM(s.`grand_total`), MAX(s.`created_date`)
                          FROM `pos_sales` s
                         WHERE DATE(s.`created_date`) BETWEEN :from2 AND :to2
                           AND s.`status` = 'completed' AND s.`is_deleted` = 0 AND s.`customer_id` IS NOT NULL
                         GROUP BY s.`customer_id`
                   ) sales
                   INNER JOIN `users` u ON u.`id` = sales.`user_id`
                   LEFT JOIN `wallet_accounts` w ON w.`user_id` = u.`id` AND w.`is_deleted` = 0
                  GROUP BY u.`id`, u.`uuid`, u.`full_name`, u.`mobile`, w.`balance_amount`, w.`is_frozen`
                  ORDER BY `total_spent` DESC
                  LIMIT %d",
                max(1, min($limit, 100))
            ),
            ['from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to]
        ));
    }

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
     * Cashier-wise POS totals (brief §12/§14). Voided sales are excluded from
     * revenue the same way a cancelled online order is excluded from
     * `vw_daily_sales` — a void means nothing was actually sold.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cashierDailyReport(string $from, string $to): array
    {
        $this->assertRange($from, $to);

        return array_map(static fn (array $row): array => [
            'cashier_name' => $row['cashier_name'],
            'sale_count' => (int) $row['sale_count'],
            'gross_sales' => (float) $row['gross_sales'],
            'discount_given' => (float) $row['discount_given'],
            'tax_collected' => (float) $row['tax_collected'],
        ], $this->db->select(
            "SELECT u.`full_name` AS `cashier_name`,
                    COUNT(*)                              AS `sale_count`,
                    ROUND(COALESCE(SUM(s.`grand_total`), 0), 2)     AS `gross_sales`,
                    ROUND(COALESCE(SUM(s.`discount_amount`), 0), 2) AS `discount_given`,
                    ROUND(COALESCE(SUM(s.`tax_amount`), 0), 2)      AS `tax_collected`
               FROM `pos_sales` s
               INNER JOIN `users` u ON u.`id` = s.`cashier_id`
              WHERE DATE(s.`created_date`) BETWEEN :from AND :to
                AND s.`status` = 'completed'
                AND s.`is_deleted` = 0
              GROUP BY u.`id`, u.`full_name`
              ORDER BY `gross_sales` DESC",
            ['from' => $from, 'to' => $to]
        ));
    }

    /**
     * Payment-method-wise POS totals (brief §12/§14).
     *
     * @return array<int, array<string, mixed>>
     */
    public function posPaymentMethodDailyReport(string $from, string $to): array
    {
        $this->assertRange($from, $to);

        // `payment_method` covers only the REMAINDER after wallet credit —
        // grand_total - wallet_applied — so "Cash" here means cash that
        // actually changed hands, not the full bill on a sale wallet credit
        // partly covered. Wallet's own share gets its own synthetic row from
        // the same query, so the two together still add up to gross sales.
        return array_map(static fn (array $row): array => [
            'payment_method' => $row['payment_method'],
            'sale_count' => (int) $row['sale_count'],
            'gross_sales' => (float) $row['gross_sales'],
        ], $this->db->select(
            "SELECT `payment_method`,
                    COUNT(*)                                                       AS `sale_count`,
                    ROUND(COALESCE(SUM(`grand_total` - `wallet_applied`), 0), 2)    AS `gross_sales`
               FROM `pos_sales`
              WHERE DATE(`created_date`) BETWEEN :from AND :to
                AND `status` = 'completed'
                AND `is_deleted` = 0
              GROUP BY `payment_method`

              UNION ALL

              SELECT 'wallet'                                     AS `payment_method`,
                     COUNT(*)                                      AS `sale_count`,
                     ROUND(COALESCE(SUM(`wallet_applied`), 0), 2)   AS `gross_sales`
               FROM `pos_sales`
              WHERE DATE(`created_date`) BETWEEN :from2 AND :to2
                AND `status` = 'completed'
                AND `is_deleted` = 0
                AND `wallet_applied` > 0

              ORDER BY `gross_sales` DESC",
            ['from' => $from, 'to' => $to, 'from2' => $from, 'to2' => $to]
        ));
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
