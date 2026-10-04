-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 044 - Invoice Tracking & Communication Center
--
--  Adds ONE new table. Everything else the Invoice Tracking module shows
--  (invoice number, customer, amount, paid/remaining, payment method, status,
--  due date, offers, refunds) is read from tables that already exist:
--  `pos_sales`, `pos_sale_payments`, `pos_refunds`, `users`, `offers`. No
--  invoice or payment record is duplicated — this module is a read/communication
--  layer over the existing POS credit-sale data (see InvoiceService's own
--  doc comment for why "invoice" maps to a POS till sale rather than an
--  online store order).
--
--  communication_log records every WhatsApp send this module initiates, so
--  "do not send duplicate reminders accidentally" (a dedupe window per
--  template) and "maintain a communication history" both have somewhere
--  real to read from. It records that the WhatsApp composer was opened with
--  a given message — there is no WhatsApp Business API credential in this
--  project, so "sent" here means "handed to WhatsApp", not a delivery
--  receipt; see InvoiceService's doc comment.
--
--  Additive only.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS `communication_log` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `pos_sale_id`       BIGINT UNSIGNED NOT NULL,
    `channel`           ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    `template_code`     VARCHAR(60)     NOT NULL
        COMMENT 'invoice_created | payment_received | partial_reminder | payment_due_reminder | overdue | offer_available | refund_processed | order_delivered | payment_completed',
    `recipient_mobile`  VARCHAR(15)     NOT NULL,
    `message_preview`   VARCHAR(1000)   NOT NULL,
    `status`            ENUM('opened', 'failed') NOT NULL DEFAULT 'opened',
    `sent_by_user_id`   BIGINT UNSIGNED NOT NULL,
    `created_by`        BIGINT UNSIGNED NULL,
    `created_date`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`        BIGINT UNSIGNED NULL,
    `updated_date`      DATETIME        NULL,
    `deleted_by`        BIGINT UNSIGNED NULL,
    `deleted_date`      DATETIME        NULL,
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`        TINYINT(1)      NOT NULL DEFAULT 0,
    `version`           INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_communication_log_uuid` (`uuid`),
    KEY `idx_communication_log_sale` (`pos_sale_id`, `template_code`, `created_date`),
    CONSTRAINT `fk_communication_log_sale`
        FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_communication_log_sent_by`
        FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('044_invoice_communication_log', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
