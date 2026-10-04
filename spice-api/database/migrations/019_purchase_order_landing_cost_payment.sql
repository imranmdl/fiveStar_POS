-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 019 - Purchase order landing cost and vendor payment tracking
--
--  Two additions to the migration 015 purchase-order tables, both additive
--  (no column is renamed or repurposed, no existing row's meaning changes):
--
--  1. LANDING COST. purchase_orders gains a transportation charge that is
--     either already folded into each line's unit_cost (a checkbox), or a
--     flat rupee amount converted into one percentage
--     (transport_charge / items_subtotal) and applied to every line to
--     produce purchase_order_items.landing_cost. landing_cost — never
--     unit_cost — is what PurchaseOrderService now passes to
--     InventoryService::recordMovement(), so the weighted-average cost this
--     platform tracks reflects the true landed cost of stock, not just the
--     vendor's per-item price. unit_cost is untouched: it remains the actual
--     vendor price for the line, same as migration 015 documented, because
--     it is still what determines what the business owes the vendor.
--
--  2. VENDOR PAYMENT TRACKING. purchase_orders gains amount_paid and a
--     payment_status derived from it (0 => unpaid, >= grand_total => paid,
--     otherwise partial) — the one column on an otherwise immutable purchase
--     order that is intentionally updated in place after creation, the same
--     precedent migration 018 set for pos_sale_items.refunded_quantity on an
--     otherwise-immutable sale line.
--
--  Same audit contract as every table since migration 001. MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `purchase_orders`
    ADD COLUMN `transport_charge` DECIMAL(12, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Flat rupee transportation charge for this purchase, before percentage allocation' AFTER `other_charges`,
    ADD COLUMN `transport_included_in_cost` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'When 1, unit_cost already includes transport and transport_charge/transport_percent are not applied' AFTER `transport_charge`,
    ADD COLUMN `transport_percent` DECIMAL(7, 4) NULL
        COMMENT 'transport_charge / items_subtotal * 100 at creation time, stored so a later view does not recompute from data that may have since changed' AFTER `transport_included_in_cost`,
    ADD COLUMN `payment_status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid'
        COMMENT 'Derived from amount_paid vs grand_total, never set independently' AFTER `grand_total`,
    ADD COLUMN `amount_paid` DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER `payment_status`,
    ADD CONSTRAINT `chk_purchase_orders_amount_paid_range`
        CHECK (`amount_paid` >= 0 AND `amount_paid` <= `grand_total`);

ALTER TABLE `purchase_order_items`
    ADD COLUMN `landing_cost` DECIMAL(12, 4) NOT NULL DEFAULT 0.00
        COMMENT 'unit_cost with the purchase order transport percentage applied; the figure InventoryService::recordMovement() actually receives' AFTER `unit_cost`,
    ADD CONSTRAINT `chk_purchase_order_items_landing_cost_not_negative`
        CHECK (`landing_cost` >= 0);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('019_purchase_order_landing_cost_payment', 19, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
