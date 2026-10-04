-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 027 - offers: a specific-customer audience, and a sales channel
--
--  Two additive, independent extensions to the `offers` campaign system:
--
--  1. `audience = 'specific_customer'` + `specific_user_id`. Mirrors
--     `coupons.audience`/`coupons.specific_user_id` exactly (including the
--     CHECK and the ON UPDATE RESTRICT reasoning — see 004_promotions_wallet.sql)
--     so a named customer can get an automatic offer the same way they could
--     always be issued a private coupon, in principle; that path just had no
--     admin UI wired to it yet.
--
--  2. `channel`. An offer can be restricted to the till ('pos') or the
--     website/app ('online') instead of both ('all', the default). Purely
--     additive: every existing offer keeps applying everywhere.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `offers`
    MODIFY COLUMN `audience` ENUM('all', 'new_customers', 'specific_customer') NOT NULL DEFAULT 'all',
    ADD COLUMN `specific_user_id` BIGINT UNSIGNED NULL COMMENT 'Required when audience = specific_customer'
        AFTER `audience`,
    ADD COLUMN `channel` ENUM('all', 'pos', 'online') NOT NULL DEFAULT 'all' AFTER `specific_user_id`;

ALTER TABLE `offers`
    ADD KEY `idx_offers_audience` (`audience`, `specific_user_id`),
    ADD CONSTRAINT `chk_offers_specific_user`
        CHECK (`audience` <> 'specific_customer' OR `specific_user_id` IS NOT NULL),
    -- ON UPDATE RESTRICT: specific_user_id appears in chk_offers_specific_user,
    -- and MySQL will not allow a referential action to modify a checked column.
    ADD CONSTRAINT `fk_offers_specific_user`
        FOREIGN KEY (`specific_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('027_offer_customer_and_channel', 27, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
