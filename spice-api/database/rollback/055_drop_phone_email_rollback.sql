-- Rollback 055 — recreate the phone.email verification table (as in 051).
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

DELETE FROM `schema_migrations` WHERE `migration` = '055_drop_phone_email';
