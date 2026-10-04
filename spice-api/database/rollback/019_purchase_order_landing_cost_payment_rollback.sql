-- ============================================================================
--  Rollback for migration 019 - Purchase order landing cost and vendor
--  payment tracking
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `purchase_order_items`
    DROP CONSTRAINT `chk_purchase_order_items_landing_cost_not_negative`,
    DROP COLUMN `landing_cost`;

ALTER TABLE `purchase_orders`
    DROP CONSTRAINT `chk_purchase_orders_amount_paid_range`,
    DROP COLUMN `amount_paid`,
    DROP COLUMN `payment_status`,
    DROP COLUMN `transport_percent`,
    DROP COLUMN `transport_included_in_cost`,
    DROP COLUMN `transport_charge`;

DELETE FROM `schema_migrations` WHERE `migration` = '019_purchase_order_landing_cost_payment';

SET FOREIGN_KEY_CHECKS = 1;
