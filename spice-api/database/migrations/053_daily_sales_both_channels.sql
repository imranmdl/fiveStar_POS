-- ===========================================================================
-- 053 — Daily sales from both channels
--
-- vw_daily_sales (Reports → cash flow, sales report, dashboard "last 7 days")
-- read only online orders, so till (POS) sales never reached the reports.
-- The view now adds completed till sales, and splits every day into online
-- and shop figures. Existing columns keep their names; the online rules are
-- unchanged from 035 (an unconfirmed order counts on the day it was placed,
-- cancelled ones are left out). Till refunds are counted on the refund day.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

CREATE OR REPLACE VIEW `vw_daily_sales` AS
SELECT
    d.`sales_date`                     AS `sales_date`,
    SUM(d.`order_count`)               AS `order_count`,
    SUM(d.`gross_sales`)               AS `gross_sales`,
    SUM(d.`taxable_value`)             AS `taxable_value`,
    SUM(d.`tax_collected`)             AS `tax_collected`,
    SUM(d.`delivery_collected`)        AS `delivery_collected`,
    SUM(d.`discount_given`)            AS `discount_given`,
    SUM(d.`wallet_redeemed`)           AS `wallet_redeemed`,
    SUM(d.`collected_online`)          AS `collected_online`,
    SUM(d.`refunded`)                  AS `refunded`,
    SUM(d.`online_orders`)             AS `online_orders`,
    SUM(d.`online_sales`)              AS `online_sales`,
    SUM(d.`pos_orders`)                AS `pos_orders`,
    SUM(d.`pos_sales`)                 AS `pos_sales`,
    SUM(d.`pos_collected`)             AS `pos_collected`
FROM (
    SELECT
        DATE(COALESCE(o.`confirmed_date`, o.`placed_date`)) AS `sales_date`,
        COUNT(*)                                       AS `order_count`,
        SUM(o.`grand_total`)                           AS `gross_sales`,
        SUM(o.`taxable_value`)                         AS `taxable_value`,
        SUM(o.`tax_total`)                             AS `tax_collected`,
        SUM(o.`delivery_charge`)                       AS `delivery_collected`,
        SUM(o.`order_discount` + o.`product_discount`) AS `discount_given`,
        SUM(o.`wallet_applied`)                        AS `wallet_redeemed`,
        SUM(o.`amount_payable`)                        AS `collected_online`,
        SUM(o.`amount_refunded`)                       AS `refunded`,
        COUNT(*)                                       AS `online_orders`,
        SUM(o.`grand_total`)                           AS `online_sales`,
        0                                              AS `pos_orders`,
        0                                              AS `pos_sales`,
        0                                              AS `pos_collected`
    FROM `orders` o
    WHERE o.`is_deleted` = 0
      AND COALESCE(o.`confirmed_date`, o.`placed_date`) IS NOT NULL
      AND o.`status` <> 'cancelled'
    GROUP BY DATE(COALESCE(o.`confirmed_date`, o.`placed_date`))

    UNION ALL

    SELECT
        DATE(s.`created_date`),
        COUNT(*),
        SUM(s.`grand_total`),
        SUM(s.`grand_total` - s.`tax_amount`),
        SUM(s.`tax_amount`),
        0,
        SUM(s.`discount_amount`),
        SUM(s.`wallet_applied`),
        0,
        0,
        0,
        0,
        COUNT(*),
        SUM(s.`grand_total`),
        SUM(s.`amount_paid`)
    FROM `pos_sales` s
    WHERE s.`is_deleted` = 0 AND s.`status` = 'completed'
    GROUP BY DATE(s.`created_date`)

    UNION ALL

    SELECT
        DATE(r.`created_date`), 0, 0, 0, 0, 0, 0, 0, 0,
        SUM(r.`refund_amount`),
        0, 0, 0, 0, 0
    FROM `pos_refunds` r
    WHERE r.`is_deleted` = 0
    GROUP BY DATE(r.`created_date`)
) d
GROUP BY d.`sales_date`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('053_daily_sales_both_channels', 53, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
