-- ===========================================================================
-- 064 — Liquids: packs sold by volume (ml / litre)
--
-- Oils, rose water, attar and other liquids are labelled by volume, not by
-- weight. A pack can now be sold by weight (g / kg), by volume (ml / L) or by
-- count (pcs, box …). volume_ml holds the pack's content for volume packs
-- ("500 ml", "1 L"); weight_grams stays required because couriers charge by
-- weight.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `product_variants`
    MODIFY COLUMN `stock_unit_type` ENUM('weight', 'quantity', 'volume') NOT NULL DEFAULT 'weight',
    ADD COLUMN `volume_ml` DECIMAL(10,2) NULL
        COMMENT 'Content of a volume pack in millilitres (500 = 500 ml, 1000 = 1 L)' AFTER `unit_label`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('064_volume_units', 64, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
