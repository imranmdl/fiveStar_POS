-- ===========================================================================
-- 057 — SKU optional until a barcode is generated
--
-- Products can now be imported without a SKU. The SKU is filled in only when
-- staff press "Generate Barcode" on the inventory screen: the generated
-- barcode value is saved as the SKU (or, if the pack already has a SKU, that
-- SKU becomes its barcode). Uniqueness is unchanged: the unique keys on `sku`
-- and `barcode` stay, and MySQL/MariaDB allow any number of NULLs under a
-- unique key, so packs still waiting for a barcode don't clash.
--
-- Order and till-sale lines snapshot the SKU as text; a pack sold before it
-- has one records an empty SKU there.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `product_variants`
    MODIFY COLUMN `sku` VARCHAR(50) NULL
        COMMENT 'Unique when set; NULL until "Generate Barcode" assigns one';

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('057_variant_sku_optional', 57, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
