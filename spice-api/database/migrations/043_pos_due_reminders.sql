-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 043 - POS due-payment reminders
--
--  When a POS credit sale is left partially paid (pos_sales.payment_status =
--  'partial' or 'unpaid', see 040_pos_customer_dues), the customer should be
--  reminded to pay the remaining balance. Whether that reminder goes out at
--  all, and how soon/how often, is an admin decision, not a hard-coded
--  behaviour:
--
--    * ON/OFF is the existing `scheduled_tasks.is_enabled` switch this
--      codebase already uses for every other background job — toggled via
--      the existing PATCH /api/v1/admin/scheduler/tasks/{code} endpoint
--      (SettingsController's neighbour NotificationController::setTaskEnabled,
--      already administrator-only). The new task is seeded DISABLED, so
--      nothing starts sending until an admin explicitly turns it on.
--    * TIMING is two new `settings` rows: how long to wait after the balance
--      was last touched before the first reminder, and how long to wait
--      between repeat reminders so the same customer isn't messaged hourly.
--
--  Additive only.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Dedupe/cadence bookkeeping. NULL means "never reminded" — the delay is
-- measured from pos_sales.updated_date (bumped by every payment posted
-- against the sale, see PosDuePaymentService::recomputeSaleTotals), not
-- from this column, which only tracks the SchedulerService side.
-- ---------------------------------------------------------------------------
ALTER TABLE `pos_sales`
    ADD COLUMN `last_due_reminder_date` DATETIME NULL
        COMMENT 'Last time SchedulerService queued a due-payment reminder for this sale' AFTER `payment_status`;

INSERT INTO `scheduled_tasks` (`uuid`, `code`, `name`, `description`, `interval_minutes`, `is_enabled`, `next_run_date`)
VALUES
    (UUID(), 'pos.due_reminders', 'POS due-payment reminders',
     'Reminds a customer with an unpaid/partially-paid POS credit sale to pay the remaining balance. Off by default — an admin must enable it, and controls the delay/repeat cadence from Settings.',
     60, 0, NULL)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `scheduled_tasks`.`version` + 1;

-- Transactional, not promotional: this is about the customer's own existing
-- balance with the store, not marketing, so NotificationPolicy lets it
-- bypass DND/quiet hours exactly like an order or dispatch notice does.
INSERT INTO `notification_templates` (`uuid`, `code`, `channel`, `name`, `category`, `subject`, `body`, `required_variables`)
VALUES
    (UUID(), 'pos.due_reminder', 'sms', 'POS due-payment reminder', 'transactional', NULL,
     'Hi {{customer_name}}, you have a pending balance of {{due_amount}} on bill {{sale_number}} at {{store_name}}. Please pay at your earliest convenience.',
     '["customer_name","due_amount","sale_number","store_name"]')
ON DUPLICATE KEY UPDATE
    `body`    = VALUES(`body`),
    `version` = `notification_templates`.`version` + 1;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'pos', 'pos_due_reminder_delay_hours', '24', 'int',
     'Hours after a POS credit sale''s balance last changed before the first due-payment reminder is sent', 0),
    (UUID(), 'pos', 'pos_due_reminder_repeat_hours', '72', 'int',
     'Minimum hours between repeat due-payment reminders for the same sale', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('043_pos_due_reminders', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
