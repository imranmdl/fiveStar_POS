-- Rollback 053 — vw_daily_sales back to online orders only (as in 035).
CREATE OR REPLACE VIEW `vw_daily_sales` AS
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
    SUM(o.`amount_refunded`)                       AS `refunded`
FROM `orders` o
WHERE o.`is_deleted` = 0
  AND COALESCE(o.`confirmed_date`, o.`placed_date`) IS NOT NULL
  AND o.`status` <> 'cancelled'
GROUP BY DATE(COALESCE(o.`confirmed_date`, o.`placed_date`));

DELETE FROM `schema_migrations` WHERE `migration` = '053_daily_sales_both_channels';
