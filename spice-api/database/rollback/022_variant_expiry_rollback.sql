-- ============================================================================
--  Rollback for migration 022 - product_variants.expiry_date
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `product_variants`
    DROP INDEX `idx_product_variants_expiry`,
    DROP COLUMN `expiry_date`;

DELETE FROM `schema_migrations` WHERE `migration` = '022_variant_expiry';

SET FOREIGN_KEY_CHECKS = 1;
