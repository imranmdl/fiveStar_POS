-- ============================================================================
--  Rollback for migration 016 - Pricing Strategy and Price-Change Audit Trail
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TRIGGER IF EXISTS `trg_price_change_log_immutable`;

DROP TABLE IF EXISTS `price_change_log`;
DROP TABLE IF EXISTS `pricing_rules`;

DELETE FROM `settings` WHERE `setting_key` = 'inventory_price_change_mode';

DELETE FROM `schema_migrations` WHERE `migration` = '016_pricing_strategy';

SET FOREIGN_KEY_CHECKS = 1;
