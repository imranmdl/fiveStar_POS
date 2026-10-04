-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 026 - offers: customer audience (all vs. new customers only)
--
--  Mirrors `coupons.audience`'s 'all'/'new_customers' values — the only two
--  the admin console actually exposes for coupons — so an automatic offer
--  can be scoped the same way a coupon already can. Purely additive: every
--  existing offer keeps its current behaviour under the 'all' default.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `offers`
    ADD COLUMN `audience` ENUM('all', 'new_customers') NOT NULL DEFAULT 'all' AFTER `applies_to`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('026_offer_audience', 26, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
