-- ===========================================================================
-- 056 — MRP on till sale lines
--
-- The printed receipt shows each item's MRP next to the price charged, and
-- the bill's total saving against MRP. The MRP is copied onto the line when
-- the sale is made, so a reprint shows the MRP of that day even if the pack's
-- MRP changes later. Older lines keep NULL and the receipt falls back to the
-- pack's current MRP. Nothing here takes part in any bill calculation.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `pos_sale_items`
    ADD COLUMN `mrp` DECIMAL(12,2) NULL
        COMMENT 'Pack MRP at the time of sale (display only; NULL on lines before 056)'
        AFTER `unit_price`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('056_pos_sale_item_mrp', 56, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
