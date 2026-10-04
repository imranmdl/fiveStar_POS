-- ============================================================================
--  Rollback for migration 015 - Vendors and Purchase Inward
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `purchase_order_items`;
DROP TABLE IF EXISTS `purchase_orders`;
DROP TABLE IF EXISTS `vendors`;

DELETE FROM `schema_migrations` WHERE `migration` = '015_vendors_purchases';

SET FOREIGN_KEY_CHECKS = 1;
