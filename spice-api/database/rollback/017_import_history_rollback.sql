-- ============================================================================
--  Rollback for migration 017 - CSV / Excel Import History
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `import_batch_items`;
DROP TABLE IF EXISTS `import_batches`;

DELETE FROM `schema_migrations` WHERE `migration` = '017_import_history';

SET FOREIGN_KEY_CHECKS = 1;
