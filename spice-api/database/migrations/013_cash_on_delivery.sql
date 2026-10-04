-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 013 - Cash on Delivery (COD)
--
--  WHY THIS EXISTS.
--
--  BR-004 in the original SRS says "only prepaid UPI orders are accepted".
--  COD is a deliberate, admin-toggleable exception to that rule, not a bypass
--  of BR-005. The distinction matters: BR-005 says an order cannot reach
--  `confirmed` without SETTLED payment (see PaymentStatus::SETTLED and
--  OrderStateMachine::evaluate). A COD order is never marked `paid` at
--  placement — no money has moved — so it cannot be allowed through that
--  check. Instead OrderStateMachine gets a second, explicit, narrow condition:
--  `confirmed` is also reachable when payment_method = 'cod' AND an
--  administrator has approved it (cod_approved_date is set). Nothing about
--  the UPI path changes; this is an additional named door, not a hole in the
--  existing one.
--
--  `payment_method` defaults to 'upi' so every existing and future prepaid
--  order is unaffected. `cod_approved_by` / `cod_approved_date` mirror the
--  audit shape already used elsewhere on this table (confirmed_date,
--  cancelled_by, cancelled_date) rather than inventing a new pattern.
--
--  MySQL 8.0.16+ / MariaDB 10.11+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE `orders`
    ADD COLUMN `payment_method` ENUM('upi', 'cod') NOT NULL DEFAULT 'upi'
        COMMENT 'upi = prepaid via a PaymentGatewayInterface driver; cod = collected on delivery, needs admin approval'
        AFTER `payment_status`,
    ADD COLUMN `cod_approved_by` BIGINT UNSIGNED NULL AFTER `payment_method`,
    ADD COLUMN `cod_approved_date` DATETIME NULL AFTER `cod_approved_by`,
    ADD COLUMN `cod_declined_reason` VARCHAR(255) NULL AFTER `cod_approved_date`,
    ADD KEY `idx_orders_cod_pending` (`payment_method`, `status`, `cod_approved_date`);

INSERT INTO `settings`
    (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`, `created_date`)
VALUES
    (UUID(), 'commerce', 'cod_enabled', '0', 'bool',
     'Whether Cash on Delivery is offered at checkout, alongside the QR payment option. '
     'Independent of payment_driver. Editable from /admin/settings.',
     1, NOW())
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('013_cash_on_delivery', 13, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
