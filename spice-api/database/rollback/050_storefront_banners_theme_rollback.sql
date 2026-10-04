-- Rollback for 050_storefront_banners_theme.
-- Text-only banners (no artwork) cannot satisfy the restored NOT NULL, so they
-- are soft-deleted first and their image_path set to an empty marker.

SET NAMES utf8mb4;

UPDATE `banners`
   SET `is_deleted` = 1, `is_active` = 0, `deleted_date` = NOW(), `image_path` = ''
 WHERE `image_path` IS NULL;

ALTER TABLE `banners`
    DROP COLUMN `text_color`,
    DROP COLUMN `bg_color`,
    DROP COLUMN `promo_code`,
    DROP COLUMN `eyebrow`,
    MODIFY COLUMN `image_path` VARCHAR(255) NOT NULL COMMENT 'Desktop / wide artwork';

DELETE FROM `settings` WHERE `group_code` = 'storefront' AND `setting_key` LIKE 'storefront\_%';

DELETE FROM `schema_migrations` WHERE `migration` = '050_storefront_banners_theme';
