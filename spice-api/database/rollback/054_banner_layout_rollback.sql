-- Rollback 054 — drop the banner layout column.
ALTER TABLE `banners` DROP COLUMN `layout`;
DELETE FROM `schema_migrations` WHERE `migration` = '054_banner_layout';
