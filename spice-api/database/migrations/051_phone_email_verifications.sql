-- ===========================================================================
-- 051 — Phone verification via phone.email ("Sign in with Phone")
--
-- The phone.email button verifies a customer's number on its own service and
-- hands the browser a one-off JSON URL on user.phone.email holding the
-- verified country code and number. Our server fetches that URL itself (never
-- trusting what the browser says) and records each URL here, so one
-- verification can be used once only — a copied URL can't be replayed to sign
-- in or confirm another order.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `phone_email_verifications` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `url_hash`      CHAR(64)        NOT NULL COMMENT 'SHA-256 of the user_json_url — single use',
    `mobile`        VARCHAR(15)     NOT NULL COMMENT 'Verified number, 10 digits',
    `country_code`  VARCHAR(6)      NOT NULL,
    `purpose`       ENUM('login', 'order_confirmation') NOT NULL,
    `user_id`       BIGINT UNSIGNED NULL,
    `reference_id`  BIGINT UNSIGNED NULL COMMENT 'orders.id for order_confirmation',
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
    UNIQUE KEY `uq_phone_email_verifications_uuid` (`uuid`),
    UNIQUE KEY `uq_phone_email_verifications_url` (`url_hash`),
    KEY `idx_phone_email_verifications_mobile` (`mobile`, `created_date`),
    KEY `idx_phone_email_verifications_user` (`user_id`),
    CONSTRAINT `fk_phone_email_verifications_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('051_phone_email_verifications', 51, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
