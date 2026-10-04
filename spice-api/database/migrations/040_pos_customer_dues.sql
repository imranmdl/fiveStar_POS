-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 040 - POS customer dues / partial payment
--
--  Mirrors migrations 019 + 030 (vendor accounts-payable) on the opposite
--  side of the ledger: a POS sale gains the same payment_status/amount_paid
--  shape purchase_orders already has, and pos_sale_payments is the same
--  "real ledger, one row per payment" vendor_payments already is — a sale's
--  payment_status/amount_paid stay derived from SUM(completed payments)
--  rather than being written to directly by more than one place.
--
--  Every existing sale was, by construction, paid in full at the register
--  the moment it was created (PosSaleService::create() has always required
--  amount_tendered + wallet_applied to cover the grand total) — so every
--  existing row backfills to payment_status='paid', amount_paid=grand_total,
--  is_credit_sale=0. Nothing about today's checkout behaviour changes; the
--  new "let a registered customer owe the balance" path is opt-in
--  (PosSaleService gains an $acceptPartial flag gated on customer_id being
--  set — a walk-in cannot be chased for money later).
--
--  is_credit_sale is set once, at creation, and never cleared even after the
--  balance is fully collected — it is what lets the Customer Dues dashboard/
--  report scope to "sales that were ever a due", rather than every ordinary
--  fully-paid-at-checkout sale (which would make a "Fully Paid" count
--  meaningless: nearly every sale ever rung up).
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `pos_sales`
    ADD COLUMN `payment_status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'paid'
        COMMENT 'Derived from amount_paid vs grand_total, never set independently' AFTER `wallet_applied`,
    ADD COLUMN `amount_paid` DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER `payment_status`,
    ADD COLUMN `is_credit_sale` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Set once at creation when a balance was knowingly left due; never cleared by later payment' AFTER `amount_paid`;

-- Backfill: every pre-existing sale was fully settled at the register.
UPDATE `pos_sales` SET `amount_paid` = `grand_total`, `payment_status` = 'paid';

ALTER TABLE `pos_sales`
    ADD CONSTRAINT `chk_pos_sales_amount_paid_range`
        CHECK (`amount_paid` >= 0 AND `amount_paid` <= `grand_total`);

CREATE TABLE `pos_sale_payments` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `pos_sale_id`      BIGINT UNSIGNED NOT NULL,
    `customer_id`      BIGINT UNSIGNED NOT NULL,
    `amount`           DECIMAL(12, 2)  NOT NULL,
    `payment_method`   ENUM('cash', 'upi', 'card', 'other') NOT NULL,
    `payment_date`     DATE            NOT NULL,
    `reference_number` VARCHAR(100)    NULL,
    `status`           ENUM('completed', 'pending', 'failed') NOT NULL DEFAULT 'completed',
    `notes`            VARCHAR(255)    NULL,
    `created_by`       BIGINT UNSIGNED NULL,
    `created_date`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`       BIGINT UNSIGNED NULL,
    `updated_date`     DATETIME        NULL,
    `deleted_by`       BIGINT UNSIGNED NULL,
    `deleted_date`     DATETIME        NULL,
    `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`       TINYINT(1)      NOT NULL DEFAULT 0,
    `version`          INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pos_sale_payments_uuid` (`uuid`),
    KEY `idx_pos_sale_payments_sale` (`pos_sale_id`, `status`),
    KEY `idx_pos_sale_payments_customer` (`customer_id`, `payment_date`),
    CONSTRAINT `chk_pos_sale_payments_amount_positive`
        CHECK (`amount` > 0),
    CONSTRAINT `fk_pos_sale_payments_sale`
        FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_pos_sale_payments_customer`
        FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('040_pos_customer_dues', 40, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
