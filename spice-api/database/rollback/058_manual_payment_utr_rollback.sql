-- Rollback 058 — drop the UTR lookup index and the review-window setting.
-- Orders then fall back to being cancelled after the normal payment window
-- (the code uses 72 hours if the setting row is missing; roll the code back
-- together with this script).
ALTER TABLE `payments` DROP KEY `idx_payments_upi_txn`;

DELETE FROM `settings` WHERE `setting_key` = 'manual_payment_review_hours';

DELETE FROM `schema_migrations` WHERE `migration` = '058_manual_payment_utr';
