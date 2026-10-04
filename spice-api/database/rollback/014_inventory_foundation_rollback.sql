-- ============================================================================
--  Rollback for migration 014 - Inventory Foundation
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TRIGGER IF EXISTS `trg_inventory_movements_immutable`;

DROP TABLE IF EXISTS `inventory_batches`;
DROP TABLE IF EXISTS `inventory_movements`;
DROP TABLE IF EXISTS `inventory_stock`;

ALTER TABLE `products` DROP COLUMN `inventory_tracking`;
ALTER TABLE `categories` DROP COLUMN `inventory_tracking`;

ALTER TABLE `product_variants`
    DROP KEY `uq_product_variants_barcode`,
    DROP COLUMN `unit_label`,
    DROP COLUMN `stock_unit_type`,
    DROP COLUMN `barcode`;

DROP TABLE IF EXISTS `product_variant_options`;
DROP TABLE IF EXISTS `category_option_types`;
DROP TABLE IF EXISTS `variant_option_values`;
DROP TABLE IF EXISTS `variant_option_types`;
DROP TABLE IF EXISTS `warehouses`;

DELETE FROM `schema_migrations` WHERE `migration` = '014_inventory_foundation';

SET FOREIGN_KEY_CHECKS = 1;
