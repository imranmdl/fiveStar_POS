-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 024 - purchase_order_items: invoice loss (short receipt)
--
--  What the vendor's invoice says was shipped and what was actually counted
--  in at the warehouse can differ — invoice says 100kg, only 95kg arrives.
--  That's real money lost (paid for stock that never showed up), distinct
--  from damage or theft of stock that WAS received. `quantity` already
--  means "what we actually received and stocked" everywhere in this system
--  (it's what recordMovement() posts to inventory) — this column holds what
--  the invoice claimed, so the gap is knowable without redefining `quantity`
--  and corrupting every existing inventory calculation that reads it.
--
--  Nullable and purely additive: NULL (or equal to quantity) means no
--  invoice loss on that line — the common case, unchanged.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `purchase_order_items`
    ADD COLUMN `invoiced_quantity` DECIMAL(12, 3) NULL AFTER `quantity`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('024_invoice_loss', 24, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
