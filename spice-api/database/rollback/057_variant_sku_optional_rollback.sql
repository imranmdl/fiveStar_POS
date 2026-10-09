-- Rollback 057 — make the SKU required again. Packs still without a SKU get
-- a placeholder (NOSKU-<id>) first so the NOT NULL change can apply.
UPDATE `product_variants` SET `sku` = CONCAT('NOSKU-', `id`) WHERE `sku` IS NULL;

ALTER TABLE `product_variants`
    MODIFY COLUMN `sku` VARCHAR(50) NOT NULL;

DELETE FROM `schema_migrations` WHERE `migration` = '057_variant_sku_optional';
