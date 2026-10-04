-- ============================================================================
--  Rollback for migration 018 - Point of Sale
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `pos_refund_items`;
DROP TABLE IF EXISTS `pos_refunds`;
DROP TABLE IF EXISTS `pos_sale_items`;
DROP TABLE IF EXISTS `pos_sales`;

DELETE FROM `schema_migrations` WHERE `migration` = '018_pos';

SET FOREIGN_KEY_CHECKS = 1;
