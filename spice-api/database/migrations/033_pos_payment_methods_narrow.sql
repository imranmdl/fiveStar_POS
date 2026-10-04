-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 033 - pos_sales: drop 'phonepe' and 'scanner' as methods
--
--  Narrows `pos_sales.payment_method` from migration 023's
--  ('cash','upi','card','other','phonepe','scanner') down to
--  ('cash','upi','card','other') — a deliberate business decision, not a
--  correction, mirroring migration 032's identical narrowing of
--  vendor_payments.payment_method. Any existing sale already recorded
--  against the two removed values is reassigned to 'upi' first (the closest
--  equivalent — both are electronic, reference-bearing payments), so the
--  column narrowing below never hits a value outside its new allowed set.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

UPDATE `pos_sales` SET `payment_method` = 'upi' WHERE `payment_method` IN ('phonepe', 'scanner');

ALTER TABLE `pos_sales`
    MODIFY COLUMN `payment_method` ENUM('cash', 'upi', 'card', 'other') NOT NULL;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('033_pos_payment_methods_narrow', 33, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
