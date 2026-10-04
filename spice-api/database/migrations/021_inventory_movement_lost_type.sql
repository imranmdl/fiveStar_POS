-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 021 - inventory_movements: a distinct 'lost' movement type
--
--  'damage' already existed. Staff need to record loss (missing/stolen/
--  written off with no physical damaged item to point at) as a distinct,
--  reportable category rather than folding it into 'damage' via free-text
--  reason — the same first-class-enum-value convention every other movement
--  distinction in this table already follows (sale vs return vs transfer).
--
--  Purely additive: MODIFY on an ENUM widens the allowed set without
--  touching any existing row's stored value.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `inventory_movements`
    MODIFY COLUMN `movement_type` ENUM('inward', 'sale', 'return', 'damage', 'lost', 'adjustment', 'transfer_in', 'transfer_out')
                                   NOT NULL;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('021_inventory_movement_lost_type', 21, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
