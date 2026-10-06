-- ===========================================================================
-- 054 — Banner layout: text + picture, or picture only
--
-- Banner artwork that already carries its own headline (a designed poster)
-- was cut to fit the half-width picture panel, and on phones squeezed into a
-- short strip. "image" banners show the artwork whole, at its own shape, on
-- every screen (with the phone artwork on small screens when one is set).
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `banners`
    ADD COLUMN `layout` ENUM('split', 'image') NOT NULL DEFAULT 'split'
        COMMENT 'split = text panel + picture; image = picture only (artwork has its own text)'
        AFTER `text_color`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('054_banner_layout', 54, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
