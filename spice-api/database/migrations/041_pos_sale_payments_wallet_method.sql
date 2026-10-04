-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 041 - pos_sale_payments: add 'wallet' as a payment method
--
--  pos_sales.amount_paid (migration 040) must stay exactly equal to
--  SUM(pos_sale_payments.amount) for a credit sale, the same derived-from-
--  the-ledger invariant vendor_payments already guarantees for purchase
--  orders — otherwise a later due payment's recompute would under-count
--  whatever a customer's wallet already covered at the register. That means
--  the wallet portion of a credit sale's opening payment needs its own
--  ledger row alongside the cash/upi/card one, so 'wallet' joins the method
--  list here. This is a distinct, sale-scoped record of "money applied to
--  this sale's balance" — not a duplicate of wallet_transactions, which
--  already ledgers the debit itself from the wallet's own point of view.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `pos_sale_payments`
    MODIFY COLUMN `payment_method` ENUM('cash', 'upi', 'card', 'other', 'wallet') NOT NULL;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('041_pos_sale_payments_wallet_method', 41, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
