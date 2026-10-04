-- Wallet system already exists in full (wallet_accounts, wallet_transactions,
-- wallet_credit_expiries, WalletService's credit()/debit() with row locks,
-- idempotency and an append-only ledger). This migration only adds the two
-- columns needed to connect it to counter (POS) sales, which is the one
-- channel the wallet never reached:
--
--   * pos_sales.wallet_applied   — a customer paying (fully or partly) from
--     their wallet at the till, the same wallet_applied convention
--     `orders` already uses for online checkout.
--   * pos_refunds.refund_method  — whether a POS refund was handed back at
--     the counter (unchanged default) or credited to the customer's wallet.
--
-- Online-order refunds need no schema change at all: `refunds.wallet_amount`
-- has existed since migration 005 and was simply never populated — the gap
-- was in the service layer (OrderService always refunded 100% to the
-- gateway), not the data model.
ALTER TABLE `pos_sales`
    ADD COLUMN `wallet_applied` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `grand_total`;

ALTER TABLE `pos_refunds`
    ADD COLUMN `refund_method` ENUM('original','wallet') NOT NULL DEFAULT 'original' AFTER `refund_amount`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('039_wallet_pos_integration', 39, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
