-- ============================================================================
--  Rollback for migration 005 - Checkout, Orders and UPI Payment
--
--  WARNING: this destroys every order, payment and GST invoice record. Under
--  Indian tax law those must be retained for years. Export before running:
--    mysqldump <db> orders order_items order_tax_lines payments refunds > orders.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS `vw_daily_sales`;
DROP VIEW  IF EXISTS `vw_order_summary`;

ALTER TABLE `carts` DROP FOREIGN KEY `fk_carts_converted_order`;

-- Clear the orphaned references before the orders table goes.
--
-- Without this the rollback appears to succeed and then migration 005 cannot be
-- re-applied: carts still hold order ids that no longer exist, so adding
-- fk_carts_converted_order back fails with error 1452. A rollback that cannot
-- be followed by a re-apply is not a rollback.
--
-- `status` is deliberately left as 'converted'. Setting it back to 'active'
-- would collide with the one-active-cart-per-customer unique index wherever the
-- customer has since started a new cart.
UPDATE `carts` SET `converted_order_id` = NULL WHERE `converted_order_id` IS NOT NULL;

DROP TABLE IF EXISTS `refunds`;
DROP TABLE IF EXISTS `payment_events`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_tax_lines`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `numbering_sequences`;

DELETE FROM `schema_migrations` WHERE `migration` = '005_orders_payments';

SET FOREIGN_KEY_CHECKS = 1;
