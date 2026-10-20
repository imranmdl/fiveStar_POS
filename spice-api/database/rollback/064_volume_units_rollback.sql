-- Rollback for 064_volume_units: volume packs fall back to "by count".
UPDATE `product_variants` SET `stock_unit_type` = 'quantity' WHERE `stock_unit_type` = 'volume';

ALTER TABLE `product_variants`
    DROP COLUMN `volume_ml`,
    MODIFY COLUMN `stock_unit_type` ENUM('weight', 'quantity') NOT NULL DEFAULT 'weight';

DELETE FROM `schema_migrations` WHERE `migration` = '064_volume_units';
