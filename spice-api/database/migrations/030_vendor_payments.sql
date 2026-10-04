-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 030 - vendor_payments: a real ledger, one row per payment
--
--  purchase_orders.amount_paid/payment_status (migration 019) stay exactly as
--  they are — every existing read of them keeps working — but they become
--  DERIVED from this table (SUM of completed payments) rather than the one
--  place a caller writes to directly. This table is the source of truth for
--  "when was it paid, how, and with what reference" — none of which the
--  single running total could ever answer.
--
--  vendor_id is denormalised from purchase_orders.vendor_id (available via
--  the FK either way) purely so a vendor's full payment history can be
--  queried directly, without joining through every one of their purchase
--  orders first — the same reasoning offer_targets/coupon_targets already
--  apply to their own denormalised lookup columns.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `vendor_payments` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `purchase_order_id`   BIGINT UNSIGNED NOT NULL,
    `vendor_id`           BIGINT UNSIGNED NOT NULL,
    `amount`              DECIMAL(12, 2)  NOT NULL,
    `payment_method`      ENUM('cash', 'upi', 'pos', 'phonepe', 'scanner') NOT NULL,
    `payment_date`        DATE            NOT NULL,
    `reference_number`    VARCHAR(100)    NULL,
    `status`              ENUM('completed', 'pending', 'failed') NOT NULL DEFAULT 'completed',
    `notes`               VARCHAR(255)    NULL,
    `created_by`          BIGINT UNSIGNED NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`          BIGINT UNSIGNED NULL,
    `updated_date`        DATETIME        NULL,
    `deleted_by`          BIGINT UNSIGNED NULL,
    `deleted_date`        DATETIME        NULL,
    `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`          TINYINT(1)      NOT NULL DEFAULT 0,
    `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vendor_payments_uuid` (`uuid`),
    KEY `idx_vendor_payments_order` (`purchase_order_id`, `status`),
    KEY `idx_vendor_payments_vendor` (`vendor_id`, `payment_date`),
    CONSTRAINT `chk_vendor_payments_amount_positive`
        CHECK (`amount` > 0),
    CONSTRAINT `fk_vendor_payments_order`
        FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_vendor_payments_vendor`
        FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('030_vendor_payments', 30, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
