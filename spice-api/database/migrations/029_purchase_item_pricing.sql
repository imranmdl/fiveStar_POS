-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 029 - purchase line pricing, batch-wise pricing, return tracking
--
--  Three additive extensions:
--
--  1. purchase_order_items gains mrp/selling_price/gst_rate/gst_amount/
--     discount_amount — captured per line, alongside the existing unit_cost/
--     landing_cost (migration 015/019), which remain what the business owes
--     the vendor and what feeds average_cost. These new columns are what the
--     line is WORTH to sell, not what it cost to buy — a different figure,
--     never conflated with unit_cost the same way migration 019's own doc
--     comment already insists on for landing_cost vs unit_cost.
--
--  2. inventory_batches gains mrp/selling_price — so a batch carries its own
--     selling price the way it already carries its own unit_cost, per batch.
--
--  3. purchase_orders gains amount_returned — maintained by the new purchase
--     returns feature, the same "one field intentionally updated in place on
--     an otherwise immutable order" precedent amount_paid already set in
--     migration 019.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `purchase_order_items`
    ADD COLUMN `mrp`              DECIMAL(10, 2) NULL AFTER `landing_cost`,
    ADD COLUMN `selling_price`    DECIMAL(10, 2) NULL AFTER `mrp`,
    ADD COLUMN `gst_rate`         DECIMAL(5, 2)  NULL COMMENT 'Percent' AFTER `selling_price`,
    ADD COLUMN `gst_amount`       DECIMAL(10, 2) NULL AFTER `gst_rate`,
    ADD COLUMN `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `gst_amount`;

ALTER TABLE `inventory_batches`
    ADD COLUMN `mrp`           DECIMAL(10, 2) NULL AFTER `unit_cost`,
    ADD COLUMN `selling_price` DECIMAL(10, 2) NULL AFTER `mrp`;

ALTER TABLE `purchase_orders`
    ADD COLUMN `amount_returned` DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER `amount_paid`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('029_purchase_item_pricing', 29, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
