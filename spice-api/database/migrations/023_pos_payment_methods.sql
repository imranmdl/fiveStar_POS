-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 023 - POS: PhonePe/Scanner payment methods, applied-offer trace
--
--  Two additive changes, both to the POS tables:
--
--  1. pos_sales.payment_method widened from ('cash','upi','card','other') to
--     add 'phonepe' and 'scanner' — the dashboard's collections-by-type
--     breakdown needs to tell these apart from generic 'upi', and this is
--     the same "MODIFY an ENUM only ever widens it" pattern migration 021
--     already used. Existing rows are untouched.
--
--  2. pos_sale_items.applied_offer_code (nullable) — purely informational
--     record of which live offer, if any, the cashier applied to that line
--     at the till. Not required by PosSaleService::create()'s validation,
--     so it has no effect on any existing caller.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `pos_sales`
    MODIFY COLUMN `payment_method` ENUM('cash', 'upi', 'card', 'other', 'phonepe', 'scanner')
                                    NOT NULL;

ALTER TABLE `pos_sale_items`
    ADD COLUMN `applied_offer_code` VARCHAR(40) NULL AFTER `discount_amount`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('023_pos_payment_methods', 23, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
