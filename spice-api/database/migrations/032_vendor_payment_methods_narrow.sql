-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 032 - vendor_payments: drop 'phonepe' and 'scanner' as methods
--
--  Narrows `vendor_payments.payment_method` from migration 030's
--  ('cash','upi','pos','phonepe','scanner') down to just ('cash','upi','pos')
--  — a deliberate business decision, not a correction. Any existing row
--  already recorded against the two removed values is reassigned to 'upi'
--  first (the closest equivalent — both are electronic, reference-bearing
--  payments), so the column narrowing below never hits a value outside its
--  new allowed set.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

UPDATE `vendor_payments` SET `payment_method` = 'upi' WHERE `payment_method` IN ('phonepe', 'scanner');

ALTER TABLE `vendor_payments`
    MODIFY COLUMN `payment_method` ENUM('cash', 'upi', 'pos') NOT NULL;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('032_vendor_payment_methods_narrow', 32, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
