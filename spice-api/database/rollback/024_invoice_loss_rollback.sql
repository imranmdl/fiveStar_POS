-- ============================================================================
--  Rollback for migration 024 - purchase_order_items.invoiced_quantity
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `purchase_order_items`
    DROP COLUMN `invoiced_quantity`;

DELETE FROM `schema_migrations` WHERE `migration` = '024_invoice_loss';

SET FOREIGN_KEY_CHECKS = 1;
