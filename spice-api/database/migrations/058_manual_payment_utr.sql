-- ===========================================================================
-- 058 — Manual UPI payment confirmation: UTR lookup + review window
--
-- Staff confirm a manual UPI QR payment by entering the UTR / UPI reference
-- of the transfer they found on the bank statement. The UTR is stored in
-- payments.upi_transaction_id, and one UTR may only ever pay for one order,
-- so every confirmation looks it up. This index keeps that lookup cheap.
--
-- manual_payment_review_hours: how long an order whose manual UPI payment is
-- waiting for staff review stays open past its online payment window before
-- the scheduler cancels it. Previously such orders were cancelled after the
-- normal window (30 minutes by default), so staff could no longer confirm a
-- payment that had genuinely arrived.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `payments`
    ADD KEY `idx_payments_upi_txn` (`upi_transaction_id`);

INSERT INTO `settings`
    (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'order', 'manual_payment_review_hours', '72', 'int',
     'Hours an order with a manual UPI payment awaiting staff review is kept open before it is cancelled', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('058_manual_payment_utr', 58, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
