-- ============================================================================
--  Seed 016 - Price-change mode setting
--  Idempotent: safe to re-run.
--
--  Defaults to 'never' — the safe, no-behavior-change default. Nothing about
--  selling prices changes automatically until an administrator deliberately
--  picks a different mode from the Pricing screen.
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'inventory', 'inventory_price_change_mode', 'never', 'string',
     'always_ask | ask_on_increase | ask_on_decrease | auto_apply | never', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`);
