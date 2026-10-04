-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 008 - Notifications and Scheduling
--
--  notification_templates, notification_queue, notification_preferences,
--  scheduled_tasks, scheduled_task_runs.
--
--  Three things drive this design.
--
--  NOTIFICATIONS ARE QUEUED, NEVER SENT INLINE. An SMS gateway that takes four
--  seconds must not add four seconds to a customer's checkout, and a gateway
--  that is down must not fail an order that has already been paid for. The
--  request enqueues; a worker sends.
--
--  TRANSACTIONAL AND PROMOTIONAL ARE LEGALLY DIFFERENT IN INDIA. Under TRAI
--  rules a promotional message to a number on the Do Not Disturb register is an
--  offence, and promotional messages may not be sent between 9pm and 9am.
--  Transactional messages — an OTP, a payment receipt, a dispatch notice — are
--  exempt from both. Collapsing the two into one "notification" concept means
--  either spamming people illegally or withholding the delivery updates they
--  actually want, so `category` is a required field on every template and the
--  distinction is enforced in code.
--
--  A DEDUPE KEY IS MANDATORY. A retried webhook, a double-clicked button and a
--  re-run scheduler all try to send the same message again. The customer should
--  receive it once.
--
--  MySQL 8.0.16+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- notification_templates
--
-- Copy lives in the database so it can be corrected without a deployment.
-- A typo in a dispatch SMS should be a five-minute fix, not a release.
-- ---------------------------------------------------------------------------
CREATE TABLE `notification_templates` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `code`             VARCHAR(60)     NOT NULL COMMENT 'e.g. order.confirmed',
    `channel`          ENUM('sms','email','whatsapp','push') NOT NULL,
    `name`             VARCHAR(120)    NOT NULL,
    -- The legal distinction, not a label. Promotional messages are subject to
    -- DND checks and quiet hours; transactional ones are not.
    `category`         ENUM('transactional','promotional') NOT NULL DEFAULT 'transactional',
    `subject`          VARCHAR(255)    NULL COMMENT 'Email only',
    `body`             TEXT            NOT NULL COMMENT 'Uses {{variable}} placeholders',
    `required_variables` JSON          NULL
                       COMMENT 'Names that must be supplied, so a broken template fails at send rather than reaching a customer with {{name}} in it',
    -- Indian SMS gateways require every template to be pre-registered with the
    -- operator and referenced by id. Sending unregistered content is silently
    -- dropped by the DLT platform, which is maddening to debug.
    `provider_template_id` VARCHAR(80) NULL,
    `sender_id`        VARCHAR(20)     NULL,
    `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `created_by`       BIGINT UNSIGNED NULL,
    `created_date`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`       BIGINT UNSIGNED NULL,
    `updated_date`     DATETIME        NULL,
    `deleted_by`       BIGINT UNSIGNED NULL,
    `deleted_date`     DATETIME        NULL,
    `is_deleted`       TINYINT(1)      NOT NULL DEFAULT 0,
    `version`          INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_templates_uuid` (`uuid`),
    UNIQUE KEY `uq_notification_template_channel` (`code`, `channel`),
    KEY `idx_notification_templates_active` (`is_active`, `is_deleted`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notification_queue
--
-- Both the outbox and the audit trail: a sent row is kept, not deleted, because
-- "did we tell the customer?" is asked far more often than it is anticipated.
-- ---------------------------------------------------------------------------
CREATE TABLE `notification_queue` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `template_id`       BIGINT UNSIGNED NULL,
    `template_code`     VARCHAR(60)     NOT NULL COMMENT 'Snapshot; the template may be edited later',
    `channel`           ENUM('sms','email','whatsapp','push') NOT NULL,
    `category`          ENUM('transactional','promotional') NOT NULL DEFAULT 'transactional',
    `user_id`           BIGINT UNSIGNED NULL COMMENT 'NULL for a guest recipient',
    `recipient`         VARCHAR(255)    NOT NULL COMMENT 'Mobile, email address or device token',
    `subject`           VARCHAR(255)    NULL,
    `body`              TEXT            NOT NULL COMMENT 'Rendered at queue time',
    `variables`         JSON            NULL,
    `status`            ENUM('pending','sending','sent','failed','cancelled','suppressed')
                        NOT NULL DEFAULT 'pending',
    `attempts`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `scheduled_for`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                        COMMENT 'Promotional messages are deferred out of quiet hours',
    `sent_date`         DATETIME        NULL,
    `failed_date`       DATETIME        NULL,
    `last_error`        VARCHAR(500)    NULL,
    `suppression_reason` VARCHAR(255)   NULL
                        COMMENT 'Why it was never sent: opted out, DND, no address',
    `provider_message_id` VARCHAR(160)  NULL,
    `provider_response` JSON            NULL,
    `reference_type`    VARCHAR(60)     NULL,
    `reference_id`      VARCHAR(60)     NULL,
    -- The same event must not notify twice, however many times it fires.
    `dedupe_key`        VARCHAR(190)    NOT NULL,
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
    UNIQUE KEY `uq_notification_queue_uuid` (`uuid`),
    UNIQUE KEY `uq_notification_dedupe` (`dedupe_key`),
    KEY `idx_notification_queue_due` (`status`, `scheduled_for`),
    KEY `idx_notification_queue_user` (`user_id`, `created_date`),
    KEY `idx_notification_queue_reference` (`reference_type`, `reference_id`),
    CONSTRAINT `chk_notification_attempts_within_max`
        CHECK (`attempts` <= `max_attempts`),
    CONSTRAINT `fk_notification_queue_template`
        FOREIGN KEY (`template_id`) REFERENCES `notification_templates` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_notification_queue_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notification_preferences
--
-- Opt-out applies to PROMOTIONAL messages only. A customer cannot switch off
-- their own OTP or their dispatch notice, and offering to would be a support
-- burden dressed up as a feature.
-- ---------------------------------------------------------------------------
CREATE TABLE `notification_preferences` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `channel`        ENUM('sms','email','whatsapp','push') NOT NULL,
    `is_enabled`     TINYINT(1)      NOT NULL DEFAULT 1,
    `opted_out_date` DATETIME        NULL,
    `opt_out_source` VARCHAR(60)     NULL COMMENT 'settings, unsubscribe link, support request',
    `created_by`     BIGINT UNSIGNED NULL,
    `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`     BIGINT UNSIGNED NULL,
    `updated_date`   DATETIME        NULL,
    `deleted_by`     BIGINT UNSIGNED NULL,
    `deleted_date`   DATETIME        NULL,
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`     TINYINT(1)      NOT NULL DEFAULT 0,
    `version`        INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_preferences_uuid` (`uuid`),
    UNIQUE KEY `uq_notification_preference` (`user_id`, `channel`),
    CONSTRAINT `fk_notification_preferences_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- scheduled_tasks
--
-- Cron calls one entry point; this table decides what is actually due.
--
-- `locked_until` is what makes that safe on more than one application server.
-- Two machines running the same crontab would otherwise both expire the same
-- unpaid orders and both refund the same wallet credit.
-- ---------------------------------------------------------------------------
CREATE TABLE `scheduled_tasks` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `code`               VARCHAR(60)     NOT NULL,
    `name`               VARCHAR(120)    NOT NULL,
    `description`        VARCHAR(500)    NULL,
    `interval_minutes`   SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `is_enabled`         TINYINT(1)      NOT NULL DEFAULT 1,
    `last_run_date`      DATETIME        NULL,
    `last_run_status`    ENUM('success','failed','skipped') NULL,
    `last_run_summary`   VARCHAR(500)    NULL,
    `last_duration_ms`   INT UNSIGNED    NULL,
    `next_run_date`      DATETIME        NULL,
    `consecutive_failures` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`       DATETIME        NULL
                         COMMENT 'Held while running, so a second worker skips it',
    `locked_by`          VARCHAR(80)     NULL COMMENT 'Hostname and pid, for debugging a stuck lock',
    `created_by`         BIGINT UNSIGNED NULL,
    `created_date`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`         BIGINT UNSIGNED NULL,
    `updated_date`       DATETIME        NULL,
    `deleted_by`         BIGINT UNSIGNED NULL,
    `deleted_date`       DATETIME        NULL,
    `is_active`          TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`         TINYINT(1)      NOT NULL DEFAULT 0,
    `version`            INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scheduled_tasks_uuid` (`uuid`),
    UNIQUE KEY `uq_scheduled_tasks_code` (`code`),
    KEY `idx_scheduled_tasks_due` (`is_enabled`, `next_run_date`),
    CONSTRAINT `chk_scheduled_interval_positive`
        CHECK (`interval_minutes` > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- scheduled_task_runs
--
-- History. A task that has quietly failed for three days is invisible without
-- it, and the tasks here move money — expiring an unpaid order returns wallet
-- credit and releases a coupon.
-- ---------------------------------------------------------------------------
CREATE TABLE `scheduled_task_runs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `task_id`       BIGINT UNSIGNED NOT NULL,
    `started_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_date` DATETIME        NULL,
    `duration_ms`   INT UNSIGNED    NULL,
    `status`        ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    `summary`       VARCHAR(500)    NULL,
    `error`         TEXT            NULL,
    `runner`        VARCHAR(80)     NULL,
    `created_by`    BIGINT UNSIGNED NULL,
    `created_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`    BIGINT UNSIGNED NULL,
    `updated_date`  DATETIME        NULL,
    `deleted_by`    BIGINT UNSIGNED NULL,
    `deleted_date`  DATETIME        NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`    TINYINT(1)      NOT NULL DEFAULT 0,
    `version`       INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scheduled_task_runs_uuid` (`uuid`),
    KEY `idx_scheduled_task_runs_task` (`task_id`, `started_date`),
    CONSTRAINT `fk_scheduled_task_runs_task`
        FOREIGN KEY (`task_id`) REFERENCES `scheduled_tasks` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_notification_health` AS
SELECT
    `channel`,
    `category`,
    COUNT(*)                                             AS `total`,
    SUM(`status` = 'pending')                            AS `pending`,
    SUM(`status` = 'sent')                               AS `sent`,
    SUM(`status` = 'failed')                             AS `failed`,
    SUM(`status` = 'suppressed')                         AS `suppressed`,
    ROUND(
        (SUM(`status` = 'sent') / NULLIF(SUM(`status` IN ('sent','failed')), 0)) * 100,
        2
    )                                                    AS `delivery_rate_percent`,
    MIN(CASE WHEN `status` = 'pending' THEN `scheduled_for` END) AS `oldest_pending`
FROM `notification_queue`
WHERE `is_deleted` = 0
  AND `created_date` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY `channel`, `category`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('008_notifications_scheduler', 8, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
