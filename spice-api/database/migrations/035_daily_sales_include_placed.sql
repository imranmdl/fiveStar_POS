-- vw_daily_sales counted only orders with a confirmed_date, so a freshly placed
-- order (UPI awaiting payment, COD awaiting approval) was invisible on the
-- dashboard and Reports until confirmed. Attribute an unconfirmed order to the
-- day it was placed; cancelled orders remain excluded.
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

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('035_daily_sales_include_placed', 35, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
