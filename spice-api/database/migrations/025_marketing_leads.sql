-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 025 - Marketing leads + recurring promotional broadcast
--
--  Captures a website VISITOR's contact details — not a registered account,
--  and not the same thing as `users` (which requires a password and a
--  mobile at signup). Someone who leaves an email and phone number just to
--  hear about offers should never be forced through account creation.
--
--  CONSENT IS A COLUMN, NOT AN ASSUMPTION. `consent_marketing` defaults to
--  0 and is only set to 1 by an explicit, unchecked-by-default checkbox on
--  the capture form — see `MarketingService::captureLead()`. Sending a
--  promotional message to a number that never opted in is a TRAI offence,
--  the same reasoning `notification_templates.category` already documents.
--
--  Reuses the existing notification/scheduler machinery rather than
--  building new ones: NotificationService::queue() already accepts a raw
--  `recipient` with no `user_id` (notification_queue.user_id is "NULL for
--  a guest recipient" per its own comment in 008_notifications_scheduler.sql),
--  and SchedulerService + scheduled_tasks already do "run this every N
--  minutes" — a 5-day cadence is just interval_minutes = 7200 on one row.
--  Both seeded below, the scheduled task DISABLED by default: an admin
--  must consciously turn it on after reviewing, not on migration alone.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `leads` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `full_name`          VARCHAR(120)    NULL,
    `email`              VARCHAR(190)    NOT NULL,
    `mobile`             VARCHAR(15)     NOT NULL,
    `source`             VARCHAR(60)     NOT NULL DEFAULT 'website_popup',
    `consent_marketing`  TINYINT(1)      NOT NULL DEFAULT 0,
    `consent_date`       DATETIME        NULL,
    `unsubscribed_date`  DATETIME        NULL,
    `last_messaged_date` DATETIME        NULL,
    `message_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_leads_uuid` (`uuid`),
    UNIQUE KEY `uq_leads_mobile` (`mobile`),
    KEY `idx_leads_consent` (`consent_marketing`, `unsubscribed_date`, `is_deleted`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `notification_templates`
    (`uuid`, `code`, `channel`, `name`, `category`, `body`, `required_variables`, `is_active`, `created_date`)
VALUES (UUID(), 'marketing.broadcast', 'sms', 'Recurring lead broadcast', 'promotional',
        '{{headline}} {{detail}} Shop: 5staronline.in Reply STOP to opt out.',
        '["headline","detail"]', 1, NOW())
ON DUPLICATE KEY UPDATE `updated_date` = `updated_date`;

INSERT INTO `scheduled_tasks`
    (`uuid`, `code`, `name`, `description`, `interval_minutes`, `is_enabled`, `created_date`)
VALUES (UUID(), 'marketing.recurring_broadcast', 'Recurring lead broadcast',
        'Sends one promotional SMS to consenting leads every 5 days, picking the best live offer, else the newest product, else a best-seller.',
        7200, 0, NOW())
ON DUPLICATE KEY UPDATE `updated_date` = `updated_date`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('025_marketing_leads', 25, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
