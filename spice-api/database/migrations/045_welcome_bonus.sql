-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 045 - Welcome bonus (wallet credit for a new customer)
--
--  When a new customer verifies their mobile number for the first time
--  (registration, or the rarer auto-verify-on-OTP-login path), they may be
--  credited a one-time wallet bonus — an admin-configured amount, admin-
--  toggled on/off, editable only from the Admin Privilege panel.
--
--  Reuses the existing wallet ledger (wallet_accounts/wallet_transactions,
--  WalletService::credit()) exactly the way a referral reward already does —
--  no new wallet/ledger table. Only the three settings rows below are new.
--  Off by default: an admin must explicitly turn it on and set an amount.
--
--  Additive only.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'wallet', 'welcome_bonus_enabled', '0', 'bool',
     'Credit a one-time wallet bonus to a customer the first time their mobile number is verified', 0),
    (UUID(), 'wallet', 'welcome_bonus_amount', '50.00', 'decimal',
     'Amount credited by the welcome bonus, in INR', 0),
    (UUID(), 'wallet', 'welcome_bonus_expiry_days', '0', 'int',
     'Days until the welcome bonus credit expires; 0 means it never expires', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('045_welcome_bonus', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
