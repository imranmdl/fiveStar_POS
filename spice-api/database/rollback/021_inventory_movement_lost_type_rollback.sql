-- ============================================================================
--  Rollback for migration 021 - inventory_movements 'lost' movement type
--
--  Only safe to run if no row has been written with movement_type = 'lost'
--  yet (MySQL truncates unrepresentable ENUM values to '' on a narrowing
--  MODIFY, which would corrupt those rows) — this is an append-only ledger,
--  so any 'lost' row is real history, not a state you undo lightly.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `inventory_movements`
    MODIFY COLUMN `movement_type` ENUM('inward', 'sale', 'return', 'damage', 'adjustment', 'transfer_in', 'transfer_out')
                                   NOT NULL;

DELETE FROM `schema_migrations` WHERE `migration` = '021_inventory_movement_lost_type';

SET FOREIGN_KEY_CHECKS = 1;
