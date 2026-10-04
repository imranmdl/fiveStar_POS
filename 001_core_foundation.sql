-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 001 - Core Foundation
--
--  Roles, permissions, users, addresses, OTP, sessions, audit trail,
--  activity log, settings, rate limiting.
--
--  Conventions applied to every table in this system:
--    * Surrogate key `id` BIGINT UNSIGNED AUTO_INCREMENT
--    * Public identifier `uuid` CHAR(36), unique  (never expose `id` in APIs)
--    * Audit columns: created_by/created_date, updated_by/updated_date,
--      deleted_by/deleted_date
--    * Flags: is_active, is_deleted (soft delete only, no hard deletes)
--    * `version` for optimistic locking
--    * InnoDB + utf8mb4_unicode_ci, 3NF, explicit foreign keys and indexes
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Schema version ledger. Every migration records itself here so deployments
-- are idempotent and auditable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration`     VARCHAR(191)    NOT NULL,
    `batch`         INT UNSIGNED    NOT NULL DEFAULT 1,
    `checksum`      CHAR(64)        NULL,
    `applied_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `applied_by`    VARCHAR(100)    NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- roles
-- ---------------------------------------------------------------------------
CREATE TABLE `roles` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `code`          VARCHAR(50)     NOT NULL COMMENT 'Machine name used in code and JWT claims',
    `name`          VARCHAR(100)    NOT NULL,
    `description`   VARCHAR(255)    NULL,
    `is_system`     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'System roles cannot be deleted',
    `hierarchy`     SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Lower value = higher privilege',
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
    UNIQUE KEY `uq_roles_uuid` (`uuid`),
    UNIQUE KEY `uq_roles_code` (`code`),
    KEY `idx_roles_state` (`is_deleted`, `is_active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- permissions  (module + action, e.g. orders.assign)
-- ---------------------------------------------------------------------------
CREATE TABLE `permissions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `code`          VARCHAR(100)    NOT NULL,
    `module`        VARCHAR(50)     NOT NULL,
    `action`        VARCHAR(50)     NOT NULL,
    `name`          VARCHAR(150)    NOT NULL,
    `description`   VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_permissions_uuid` (`uuid`),
    UNIQUE KEY `uq_permissions_code` (`code`),
    KEY `idx_permissions_module` (`module`, `action`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- role_permissions  (the permission matrix)
-- ---------------------------------------------------------------------------
CREATE TABLE `role_permissions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `role_id`       BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_role_permissions_uuid` (`uuid`),
    UNIQUE KEY `uq_role_permission` (`role_id`, `permission_id`),
    KEY `idx_role_permissions_permission` (`permission_id`),
    CONSTRAINT `fk_role_permissions_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_role_permissions_permission`
        FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users  (customers, executives, supervisors, administrators)
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `role_id`                BIGINT UNSIGNED NOT NULL,
    `full_name`              VARCHAR(120)    NOT NULL,
    `mobile`                 VARCHAR(15)     NOT NULL COMMENT 'Normalised 10-digit national number',
    `email`                  VARCHAR(150)    NULL,
    `password_hash`          VARCHAR(255)    NOT NULL COMMENT 'bcrypt',
    `status`                 ENUM('pending_verification','active','suspended','blocked')
                                             NOT NULL DEFAULT 'pending_verification',
    `mobile_verified_date`   DATETIME        NULL,
    `email_verified_date`    DATETIME        NULL,
    `gender`                 ENUM('male','female','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
    `date_of_birth`          DATE            NULL,
    `profile_image_path`     VARCHAR(255)    NULL,
    `referral_code`          VARCHAR(20)     NOT NULL COMMENT 'Own code, shared with friends',
    `referred_by_user_id`    BIGINT UNSIGNED NULL,
    `last_login_date`        DATETIME        NULL,
    `last_login_ip`          VARCHAR(45)     NULL,
    `failed_login_attempts`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until_date`      DATETIME        NULL,
    `tokens_valid_from`      DATETIME        NULL COMMENT 'Access tokens issued before this are rejected',
    `created_by`             BIGINT UNSIGNED NULL,
    `created_date`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`             BIGINT UNSIGNED NULL,
    `updated_date`           DATETIME        NULL,
    `deleted_by`             BIGINT UNSIGNED NULL,
    `deleted_date`           DATETIME        NULL,
    `is_active`              TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`             TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_uuid` (`uuid`),
    -- BR: duplicate mobile registration is impossible at the storage layer,
    -- not merely in application code.
    UNIQUE KEY `uq_users_mobile` (`mobile`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_referral_code` (`referral_code`),
    KEY `idx_users_role` (`role_id`),
    KEY `idx_users_status` (`status`, `is_deleted`),
    KEY `idx_users_referred_by` (`referred_by_user_id`),
    KEY `idx_users_created` (`created_date`),
    CONSTRAINT `fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_users_referrer`
        FOREIGN KEY (`referred_by_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- user_addresses  (multiple delivery addresses per customer)
-- ---------------------------------------------------------------------------
CREATE TABLE `user_addresses` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `label`            VARCHAR(50)     NOT NULL DEFAULT 'Home',
    `contact_name`     VARCHAR(120)    NOT NULL,
    `contact_mobile`   VARCHAR(15)     NOT NULL,
    `address_line1`    VARCHAR(255)    NOT NULL,
    `address_line2`    VARCHAR(255)    NULL,
    `landmark`         VARCHAR(150)    NULL,
    `city`             VARCHAR(100)    NOT NULL,
    `district`         VARCHAR(100)    NULL,
    `state`            VARCHAR(100)    NOT NULL,
    `pincode`          VARCHAR(10)     NOT NULL,
    `country`          VARCHAR(60)     NOT NULL DEFAULT 'India',
    `latitude`         DECIMAL(10, 7)  NULL,
    `longitude`        DECIMAL(10, 7)  NULL,
    `address_type`     ENUM('home','work','other') NOT NULL DEFAULT 'home',
    `is_default`       TINYINT(1)      NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_user_addresses_uuid` (`uuid`),
    KEY `idx_user_addresses_user` (`user_id`, `is_deleted`),
    KEY `idx_user_addresses_pincode` (`pincode`),
    CONSTRAINT `fk_user_addresses_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- otp_requests
-- Codes are stored as HMAC-SHA256 digests with a pepper held in the
-- environment, so a database dump alone cannot reveal or brute-force them.
-- ---------------------------------------------------------------------------
CREATE TABLE `otp_requests` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `user_id`          BIGINT UNSIGNED NULL COMMENT 'Null for pre-registration flows',
    `mobile`           VARCHAR(15)     NOT NULL,
    `email`            VARCHAR(150)    NULL,
    `purpose`          ENUM('registration','login','password_reset','order_confirmation','mobile_change')
                                       NOT NULL,
    `otp_hash`         CHAR(64)        NOT NULL,
    `reference_token`  VARCHAR(64)     NOT NULL COMMENT 'Opaque handle returned to the client',
    `channel`          ENUM('sms','whatsapp','email','voice') NOT NULL DEFAULT 'sms',
    `expires_date`     DATETIME        NOT NULL,
    `attempt_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`     SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `resend_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `consumed_date`    DATETIME        NULL,
    `ip_address`       VARCHAR(45)     NULL,
    `user_agent`       VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_otp_requests_uuid` (`uuid`),
    UNIQUE KEY `uq_otp_requests_reference` (`reference_token`),
    KEY `idx_otp_lookup` (`mobile`, `purpose`, `consumed_date`, `expires_date`),
    KEY `idx_otp_user` (`user_id`),
    KEY `idx_otp_created` (`created_date`),
    CONSTRAINT `fk_otp_requests_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- refresh_tokens  (one row per device session)
-- ---------------------------------------------------------------------------
CREATE TABLE `refresh_tokens` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `user_id`               BIGINT UNSIGNED NOT NULL,
    `token_hash`            CHAR(64)        NOT NULL COMMENT 'SHA-256 of the opaque token',
    `device_id`             VARCHAR(100)    NULL,
    `device_name`           VARCHAR(120)    NULL,
    `platform`              ENUM('web','android','ios','other') NOT NULL DEFAULT 'web',
    `ip_address`            VARCHAR(45)     NULL,
    `user_agent`            VARCHAR(255)    NULL,
    `expires_date`          DATETIME        NOT NULL,
    `revoked_date`          DATETIME        NULL,
    `revoked_reason`        VARCHAR(50)     NULL COMMENT 'logout | rotated | reuse_detected | password_reset | admin',
    `replaced_by_token_id`  BIGINT UNSIGNED NULL,
    `created_by`            BIGINT UNSIGNED NULL,
    `created_date`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`            BIGINT UNSIGNED NULL,
    `updated_date`          DATETIME        NULL,
    `deleted_by`            BIGINT UNSIGNED NULL,
    `deleted_date`          DATETIME        NULL,
    `is_active`             TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`            TINYINT(1)      NOT NULL DEFAULT 0,
    `version`               INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_refresh_tokens_uuid` (`uuid`),
    UNIQUE KEY `uq_refresh_tokens_hash` (`token_hash`),
    KEY `idx_refresh_tokens_user` (`user_id`, `revoked_date`, `expires_date`),
    KEY `idx_refresh_tokens_replaced` (`replaced_by_token_id`),
    CONSTRAINT `fk_refresh_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_refresh_tokens_replacement`
        FOREIGN KEY (`replaced_by_token_id`) REFERENCES `refresh_tokens` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- login_attempts  (forensics and abuse detection)
-- ---------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `identifier`     VARCHAR(150)    NOT NULL COMMENT 'Mobile or email as submitted',
    `user_id`        BIGINT UNSIGNED NULL,
    `auth_method`    ENUM('password','otp','refresh') NOT NULL DEFAULT 'password',
    `was_successful` TINYINT(1)      NOT NULL DEFAULT 0,
    `failure_reason` VARCHAR(100)    NULL,
    `ip_address`     VARCHAR(45)     NULL,
    `user_agent`     VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_login_attempts_uuid` (`uuid`),
    KEY `idx_login_attempts_identifier` (`identifier`, `created_date`),
    KEY `idx_login_attempts_ip` (`ip_address`, `created_date`),
    KEY `idx_login_attempts_user` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_logs  (BR-009: before/after snapshots of business entities)
-- ---------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `entity_name`           VARCHAR(100)    NOT NULL COMMENT 'Table or aggregate name',
    `entity_id`             BIGINT UNSIGNED NULL,
    `entity_uuid`           CHAR(36)        NULL,
    `action`                VARCHAR(60)     NOT NULL,
    `old_values`            JSON            NULL,
    `new_values`            JSON            NULL,
    `performed_by_user_id`  BIGINT UNSIGNED NULL,
    `performed_by_role`     VARCHAR(50)     NULL,
    `ip_address`            VARCHAR(45)     NULL,
    `user_agent`            VARCHAR(255)    NULL,
    `request_id`            VARCHAR(40)     NULL,
    `notes`                 VARCHAR(500)    NULL,
    `created_by`            BIGINT UNSIGNED NULL,
    `created_date`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`            BIGINT UNSIGNED NULL,
    `updated_date`          DATETIME        NULL,
    `deleted_by`            BIGINT UNSIGNED NULL,
    `deleted_date`          DATETIME        NULL,
    `is_active`             TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`            TINYINT(1)      NOT NULL DEFAULT 0,
    `version`               INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_audit_logs_uuid` (`uuid`),
    KEY `idx_audit_entity` (`entity_name`, `entity_id`, `created_date`),
    KEY `idx_audit_actor` (`performed_by_user_id`, `created_date`),
    KEY `idx_audit_request` (`request_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- activity_logs  (one row per API request)
-- ---------------------------------------------------------------------------
CREATE TABLE `activity_logs` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `user_id`        BIGINT UNSIGNED NULL,
    `user_role`      VARCHAR(50)     NULL,
    `module`         VARCHAR(60)     NOT NULL,
    `action`         VARCHAR(255)    NOT NULL,
    `http_method`    VARCHAR(10)     NOT NULL,
    `endpoint`       VARCHAR(255)    NOT NULL,
    `status_code`    SMALLINT UNSIGNED NOT NULL,
    `duration_ms`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `ip_address`     VARCHAR(45)     NULL,
    `user_agent`     VARCHAR(255)    NULL,
    `request_id`     VARCHAR(40)     NULL,
    `error_message`  VARCHAR(500)    NULL,
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
    UNIQUE KEY `uq_activity_logs_uuid` (`uuid`),
    KEY `idx_activity_user` (`user_id`, `created_date`),
    KEY `idx_activity_module` (`module`, `created_date`),
    KEY `idx_activity_status` (`status_code`, `created_date`),
    KEY `idx_activity_request` (`request_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings  (runtime configuration editable by administrators)
-- ---------------------------------------------------------------------------
CREATE TABLE `settings` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `group_code`     VARCHAR(50)     NOT NULL DEFAULT 'general',
    `setting_key`    VARCHAR(100)    NOT NULL,
    `setting_value`  TEXT            NULL,
    `data_type`      ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
    `description`    VARCHAR(255)    NULL,
    `is_public`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Exposed to unauthenticated clients',
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
    UNIQUE KEY `uq_settings_uuid` (`uuid`),
    UNIQUE KEY `uq_settings_key` (`setting_key`),
    KEY `idx_settings_group` (`group_code`, `is_public`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- rate_limits  (fixed-window counters; swap for Redis at scale without any
-- application change beyond the RateLimitRepository binding)
-- ---------------------------------------------------------------------------
CREATE TABLE `rate_limits` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `limit_key`            CHAR(64)        NOT NULL COMMENT 'SHA-256 of scope|identifier',
    `hit_count`            INT UNSIGNED    NOT NULL DEFAULT 0,
    `window_started_date`  DATETIME        NOT NULL,
    `window_expires_date`  DATETIME        NOT NULL,
    `created_by`           BIGINT UNSIGNED NULL,
    `created_date`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`           BIGINT UNSIGNED NULL,
    `updated_date`         DATETIME        NULL,
    `deleted_by`           BIGINT UNSIGNED NULL,
    `deleted_date`         DATETIME        NULL,
    `is_active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`           TINYINT(1)      NOT NULL DEFAULT 0,
    `version`              INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rate_limits_key` (`limit_key`),
    KEY `idx_rate_limits_expiry` (`window_expires_date`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reporting view: active staff with their role. Views keep dashboard queries
-- out of application code.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_active_staff` AS
SELECT
    u.`id`,
    u.`uuid`,
    u.`full_name`,
    u.`mobile`,
    u.`email`,
    r.`code`  AS `role_code`,
    r.`name`  AS `role_name`,
    u.`status`,
    u.`last_login_date`,
    u.`created_date`
FROM `users` u
INNER JOIN `roles` r ON r.`id` = u.`role_id`
WHERE u.`is_deleted` = 0
  AND u.`is_active` = 1
  AND r.`code` <> 'customer';

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('001_core_foundation', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
