-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 022 - product_variants: an admin-declared expiry date
--
--  Expiry is already tracked per received batch (inventory_batches,
--  expiry_date, migration 014) via Purchase Inward and Inventory's
--  Adjust-stock action. That covers real, quantity-bearing stock, but a
--  merchant who isn't doing formal batch tracking has nowhere to declare
--  "this pack typically expires around X" at all. This column is that
--  simple, informational fallback — purely additive, no existing column
--  touched.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `product_variants`
    ADD COLUMN `expiry_date` DATE NULL AFTER `barcode`,
    ADD INDEX `idx_product_variants_expiry` (`expiry_date`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('022_variant_expiry', 22, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
