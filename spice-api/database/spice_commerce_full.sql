-- ============================================================================
--  SPICE & DRY FRUITS COMMERCE PLATFORM
--  Complete database — single-file import
--
--  Generated 2026-08-04 from migrations 001-011 plus seed data.
--
--  HOW TO IMPORT
--
--    1. Create an EMPTY database, character set utf8mb4, collation
--       utf8mb4_unicode_ci.
--    2. Import this file into it.
--    3. Then, from the backend folder:
--         php bin/setup.php        checks the install and generates secrets
--         php bin/seed_admin.php   creates your administrator account
--
--  RUNS ON MySQL 8.0.16+ AND ON MariaDB 10.4+.
--
--  A handful of CHECK constraints from the original schema have been removed
--  here. They referenced columns that are also part of a foreign key with a
--  cascading action, and neither engine will accept that combination in a
--  portable form — MariaDB refuses it outright with error 1901. Every rule they
--  expressed is enforced in the application; what is lost is a second line of
--  defence against a hand-written UPDATE. The list is at the foot of this file.
--
--  The three DEMO- products are sample data so the shop is not empty on a fresh
--  install. Remove them before going live:
--    DELETE FROM products WHERE product_code LIKE 'DEMO-%';
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- ==========================================================================
--  001_core_foundation.sql
-- ==========================================================================
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

-- ==========================================================================
--  002_catalog.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 002 - Product Catalog
--
--  categories (self-referencing, two levels used), products, product_variants
--  (pack sizes: this is where weight and price live), product_media,
--  product_nutrition, product_attributes, banners.
--
--  BR-001 / BR-002: no stock, quantity-on-hand or warehouse column exists
--  anywhere in this migration. Products are displayed and sold; availability
--  is a publishing decision (`status`), not an inventory calculation.
--
--  Same audit contract as migration 001 on every table.
--  MySQL 8.0+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- categories
-- `parent_id` NULL = top-level category, otherwise a subcategory. One table
-- rather than two keeps the tree arbitrarily deep without a schema change.
-- ---------------------------------------------------------------------------
CREATE TABLE `categories` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `parent_id`         BIGINT UNSIGNED NULL,
    `slug`              VARCHAR(140)    NOT NULL COMMENT 'URL segment, unique across the tree',
    `name`              VARCHAR(120)    NOT NULL,
    `description`       TEXT            NULL,
    `image_path`        VARCHAR(255)    NULL,
    `icon_path`         VARCHAR(255)    NULL,
    `display_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `is_featured`       TINYINT(1)      NOT NULL DEFAULT 0,
    `show_in_menu`      TINYINT(1)      NOT NULL DEFAULT 1,
    `meta_title`        VARCHAR(180)    NULL,
    `meta_description`  VARCHAR(320)    NULL,
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
    UNIQUE KEY `uq_categories_uuid` (`uuid`),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    KEY `idx_categories_parent` (`parent_id`, `display_order`),
    KEY `idx_categories_state` (`is_deleted`, `is_active`, `show_in_menu`),
    CONSTRAINT `fk_categories_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products
-- Descriptive and regulatory data only. Anything that varies by pack size
-- (weight, MRP, price, offer) lives on product_variants.
-- ---------------------------------------------------------------------------
CREATE TABLE `products` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `category_id`           BIGINT UNSIGNED NOT NULL COMMENT 'Leaf category the product sits in',
    `product_code`          VARCHAR(40)     NOT NULL COMMENT 'Internal reference, e.g. SPC-TURMERIC',
    `slug`                  VARCHAR(180)    NOT NULL,
    `name`                  VARCHAR(180)    NOT NULL,
    `brand`                 VARCHAR(120)    NULL,
    `short_description`     VARCHAR(320)    NULL,
    `description`           MEDIUMTEXT      NULL,
    `ingredients`           TEXT            NULL,
    `usage_instructions`    TEXT            NULL,
    `storage_instructions`  VARCHAR(320)    NULL,
    `shelf_life_days`       SMALLINT UNSIGNED NULL,
    `origin_country`        VARCHAR(80)     NOT NULL DEFAULT 'India',
    `origin_region`         VARCHAR(120)    NULL COMMENT 'e.g. Erode, Tamil Nadu',
    `hsn_code`              VARCHAR(15)     NULL COMMENT 'GST classification',
    `gst_rate`              DECIMAL(5, 2)   NOT NULL DEFAULT 5.00,
    `fssai_license_no`      VARCHAR(30)     NULL,
    `is_organic`            TINYINT(1)      NOT NULL DEFAULT 0,
    `is_vegetarian`         TINYINT(1)      NOT NULL DEFAULT 1,
    `is_gift_packable`      TINYINT(1)      NOT NULL DEFAULT 1,
    `status`                ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_date`        DATETIME        NULL,
    `is_featured`           TINYINT(1)      NOT NULL DEFAULT 0,
    `display_order`         SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `search_keywords`       VARCHAR(500)    NULL COMMENT 'Synonyms and regional names: haldi, manjal',
    -- Denormalised aggregates. Maintained by the review module in Phase 8;
    -- kept here so listing queries never have to aggregate reviews.
    `rating_average`        DECIMAL(3, 2)   NOT NULL DEFAULT 0.00,
    `rating_count`          INT UNSIGNED    NOT NULL DEFAULT 0,
    `review_count`          INT UNSIGNED    NOT NULL DEFAULT 0,
    `sold_count`            INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Units sold, drives popularity sort',
    `view_count`            INT UNSIGNED    NOT NULL DEFAULT 0,
    `meta_title`            VARCHAR(180)    NULL,
    `meta_description`      VARCHAR(320)    NULL,
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
    UNIQUE KEY `uq_products_uuid` (`uuid`),
    UNIQUE KEY `uq_products_code` (`product_code`),
    UNIQUE KEY `uq_products_slug` (`slug`),
    KEY `idx_products_category` (`category_id`, `status`, `is_deleted`),
    KEY `idx_products_status` (`status`, `is_deleted`, `is_active`),
    KEY `idx_products_featured` (`is_featured`, `status`, `display_order`),
    KEY `idx_products_rating` (`rating_average`, `rating_count`),
    KEY `idx_products_popularity` (`sold_count`),
    KEY `idx_products_created` (`created_date`),
    FULLTEXT KEY `ft_products_search` (`name`, `short_description`, `search_keywords`),
    CONSTRAINT `fk_products_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_variants  (pack sizes)
--
-- `weight_grams` is mandatory and integer: courier selection (BR-007) and
-- delivery charge calculation (BR-006) both depend on it, so a product cannot
-- be sold without a shippable weight. Prices are DECIMAL, never FLOAT.
-- ---------------------------------------------------------------------------
CREATE TABLE `product_variants` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `product_id`         BIGINT UNSIGNED NOT NULL,
    `sku`                VARCHAR(50)     NOT NULL,
    `variant_name`       VARCHAR(80)     NOT NULL COMMENT 'Display label, e.g. "500 g pouch"',
    `weight_grams`       INT UNSIGNED    NOT NULL COMMENT 'Net product weight',
    `packed_weight_grams` INT UNSIGNED   NULL COMMENT 'Gross weight incl. packaging, for courier quoting',
    `pack_type`          ENUM('pouch','jar','box','tin','gift_box','refill','other')
                                         NOT NULL DEFAULT 'pouch',
    `mrp`                DECIMAL(10, 2)  NOT NULL,
    `selling_price`      DECIMAL(10, 2)  NOT NULL,
    `offer_price`        DECIMAL(10, 2)  NULL,
    `offer_start_date`   DATETIME        NULL,
    `offer_end_date`     DATETIME        NULL,
    `max_order_quantity` SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    `is_default`         TINYINT(1)      NOT NULL DEFAULT 0,
    `display_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
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
    UNIQUE KEY `uq_product_variants_uuid` (`uuid`),
    UNIQUE KEY `uq_product_variants_sku` (`sku`),
    KEY `idx_variants_product` (`product_id`, `is_deleted`, `display_order`),
    KEY `idx_variants_weight` (`weight_grams`),
    KEY `idx_variants_price` (`selling_price`),
    -- Data integrity the application cannot bypass, whatever the caller sends.
    CONSTRAINT `chk_variants_prices_positive`
        CHECK (`mrp` > 0 AND `selling_price` > 0),
    CONSTRAINT `chk_variants_selling_not_above_mrp`
        CHECK (`selling_price` <= `mrp`),
    CONSTRAINT `chk_variants_offer_below_selling`
        CHECK (`offer_price` IS NULL OR `offer_price` < `selling_price`),
    CONSTRAINT `chk_variants_offer_window`
        CHECK (`offer_start_date` IS NULL OR `offer_end_date` IS NULL
               OR `offer_end_date` > `offer_start_date`),
    CONSTRAINT `chk_variants_weight_positive`
        CHECK (`weight_grams` > 0),
    CONSTRAINT `fk_product_variants_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_media  (images and videos, SRS Module 3)
-- ---------------------------------------------------------------------------
CREATE TABLE `product_media` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `product_id`      BIGINT UNSIGNED NOT NULL,
    `variant_id`      BIGINT UNSIGNED NULL COMMENT 'Set when the image is pack-specific',
    `media_type`      ENUM('image','video') NOT NULL DEFAULT 'image',
    `file_path`       VARCHAR(255)    NULL COMMENT 'Relative path for uploaded files',
    `thumbnail_path`  VARCHAR(255)    NULL,
    `external_url`    VARCHAR(500)    NULL COMMENT 'For hosted video (YouTube etc.)',
    `alt_text`        VARCHAR(180)    NULL COMMENT 'Accessibility and SEO',
    `caption`         VARCHAR(180)    NULL,
    `width_px`        SMALLINT UNSIGNED NULL,
    `height_px`       SMALLINT UNSIGNED NULL,
    `file_size_bytes` INT UNSIGNED    NULL,
    `mime_type`       VARCHAR(80)     NULL,
    `is_primary`      TINYINT(1)      NOT NULL DEFAULT 0,
    `display_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`      BIGINT UNSIGNED NULL,
    `updated_date`    DATETIME        NULL,
    `deleted_by`      BIGINT UNSIGNED NULL,
    `deleted_date`    DATETIME        NULL,
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`      TINYINT(1)      NOT NULL DEFAULT 0,
    `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_media_uuid` (`uuid`),
    KEY `idx_media_product` (`product_id`, `is_deleted`, `display_order`),
    KEY `idx_media_variant` (`variant_id`),
    KEY `idx_media_primary` (`product_id`, `is_primary`),
    CONSTRAINT `chk_media_has_source`
        CHECK (`file_path` IS NOT NULL OR `external_url` IS NOT NULL),
    CONSTRAINT `fk_product_media_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_product_media_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_nutrition  (one row per product, per 100 g as printed on the label)
-- A dedicated table rather than a JSON blob, so nutrition is queryable and
-- reportable (e.g. "all products above 20 g protein").
-- ---------------------------------------------------------------------------
CREATE TABLE `product_nutrition` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `product_id`        BIGINT UNSIGNED NOT NULL,
    `serving_size_g`    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `energy_kcal`       DECIMAL(7, 2)   NULL,
    `protein_g`         DECIMAL(6, 2)   NULL,
    `total_fat_g`       DECIMAL(6, 2)   NULL,
    `saturated_fat_g`   DECIMAL(6, 2)   NULL,
    `trans_fat_g`       DECIMAL(6, 2)   NULL,
    `carbohydrate_g`    DECIMAL(6, 2)   NULL,
    `total_sugar_g`     DECIMAL(6, 2)   NULL,
    `added_sugar_g`     DECIMAL(6, 2)   NULL,
    `dietary_fibre_g`   DECIMAL(6, 2)   NULL,
    `sodium_mg`         DECIMAL(8, 2)   NULL,
    `iron_mg`           DECIMAL(6, 2)   NULL,
    `calcium_mg`        DECIMAL(8, 2)   NULL,
    `allergen_info`     VARCHAR(320)    NULL COMMENT 'e.g. May contain traces of tree nuts',
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
    UNIQUE KEY `uq_product_nutrition_uuid` (`uuid`),
    UNIQUE KEY `uq_product_nutrition_product` (`product_id`),
    CONSTRAINT `fk_product_nutrition_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_attributes  (open-ended specs that do not deserve a column:
-- "Grind", "Heat level", "Harvest year")
-- ---------------------------------------------------------------------------
CREATE TABLE `product_attributes` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `product_id`       BIGINT UNSIGNED NOT NULL,
    `attribute_name`   VARCHAR(80)     NOT NULL,
    `attribute_value`  VARCHAR(255)    NOT NULL,
    `display_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
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
    UNIQUE KEY `uq_product_attributes_uuid` (`uuid`),
    UNIQUE KEY `uq_product_attribute_name` (`product_id`, `attribute_name`),
    KEY `idx_product_attributes_product` (`product_id`, `display_order`),
    CONSTRAINT `fk_product_attributes_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- banners  (SRS: Banner Management)
-- ---------------------------------------------------------------------------
CREATE TABLE `banners` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `title`              VARCHAR(160)    NOT NULL,
    `subtitle`           VARCHAR(240)    NULL,
    `image_path`         VARCHAR(255)    NOT NULL COMMENT 'Desktop / wide artwork',
    `mobile_image_path`  VARCHAR(255)    NULL COMMENT 'Portrait artwork for the apps',
    `alt_text`           VARCHAR(180)    NULL,
    `placement`          ENUM('home_hero','home_strip','category_top','app_home','checkout')
                                         NOT NULL DEFAULT 'home_hero',
    `link_type`          ENUM('none','category','product','url','offer') NOT NULL DEFAULT 'none',
    `link_value`         VARCHAR(255)    NULL COMMENT 'Category/product slug, URL, or offer code',
    `cta_label`          VARCHAR(60)     NULL,
    `display_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `start_date`         DATETIME        NULL,
    `end_date`           DATETIME        NULL,
    `impression_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `click_count`        INT UNSIGNED    NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_banners_uuid` (`uuid`),
    KEY `idx_banners_placement` (`placement`, `is_active`, `is_deleted`, `display_order`),
    KEY `idx_banners_window` (`start_date`, `end_date`),
    CONSTRAINT `chk_banners_window`
        CHECK (`start_date` IS NULL OR `end_date` IS NULL OR `end_date` > `start_date`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Views
--
-- `vw_variant_pricing` resolves the effective price once, so no two callers can
-- disagree about whether an offer is currently live. Everything downstream
-- (listing, cart, checkout, order pricing) reads the effective price from here.
--
-- Deliberately free of subqueries in FROM so it works on every MySQL 8 build.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_variant_pricing` AS
SELECT
    v.`id`,
    v.`uuid`,
    v.`product_id`,
    v.`sku`,
    v.`variant_name`,
    v.`weight_grams`,
    COALESCE(v.`packed_weight_grams`, v.`weight_grams`) AS `shipping_weight_grams`,
    v.`pack_type`,
    v.`mrp`,
    v.`selling_price`,
    v.`offer_price`,
    v.`max_order_quantity`,
    v.`is_default`,
    v.`display_order`,
    CASE
        WHEN v.`offer_price` IS NOT NULL
             AND (v.`offer_start_date` IS NULL OR v.`offer_start_date` <= NOW())
             AND (v.`offer_end_date`   IS NULL OR v.`offer_end_date`   >= NOW())
        THEN 1 ELSE 0
    END AS `offer_is_live`,
    CASE
        WHEN v.`offer_price` IS NOT NULL
             AND (v.`offer_start_date` IS NULL OR v.`offer_start_date` <= NOW())
             AND (v.`offer_end_date`   IS NULL OR v.`offer_end_date`   >= NOW())
        THEN v.`offer_price` ELSE v.`selling_price`
    END AS `effective_price`,
    ROUND(
        (v.`mrp` - CASE
            WHEN v.`offer_price` IS NOT NULL
                 AND (v.`offer_start_date` IS NULL OR v.`offer_start_date` <= NOW())
                 AND (v.`offer_end_date`   IS NULL OR v.`offer_end_date`   >= NOW())
            THEN v.`offer_price` ELSE v.`selling_price`
        END) / v.`mrp` * 100, 0
    ) AS `discount_percentage`,
    ROUND(
        CASE
            WHEN v.`offer_price` IS NOT NULL
                 AND (v.`offer_start_date` IS NULL OR v.`offer_start_date` <= NOW())
                 AND (v.`offer_end_date`   IS NULL OR v.`offer_end_date`   >= NOW())
            THEN v.`offer_price` ELSE v.`selling_price`
        END / (v.`weight_grams` / 1000), 2
    ) AS `price_per_kg`
FROM `product_variants` v
WHERE v.`is_deleted` = 0
  AND v.`is_active` = 1;

-- Per-product price and weight envelope, used by listing, filtering and sorting.
CREATE OR REPLACE VIEW `vw_product_price_range` AS
SELECT
    `product_id`,
    COUNT(*)                  AS `variant_count`,
    MIN(`effective_price`)    AS `min_price`,
    MAX(`effective_price`)    AS `max_price`,
    MIN(`mrp`)                AS `min_mrp`,
    MAX(`mrp`)                AS `max_mrp`,
    MIN(`weight_grams`)       AS `min_weight_grams`,
    MAX(`weight_grams`)       AS `max_weight_grams`,
    MAX(`discount_percentage`) AS `max_discount_percentage`,
    MAX(`offer_is_live`)      AS `has_live_offer`
FROM `vw_variant_pricing`
GROUP BY `product_id`;

-- Two-level category tree with a live published-product count.
CREATE OR REPLACE VIEW `vw_category_tree` AS
SELECT
    c.`id`,
    c.`uuid`,
    c.`parent_id`,
    p.`slug`  AS `parent_slug`,
    p.`name`  AS `parent_name`,
    c.`slug`,
    c.`name`,
    c.`image_path`,
    c.`icon_path`,
    c.`display_order`,
    c.`is_featured`,
    c.`show_in_menu`,
    (SELECT COUNT(*) FROM `products` pr
      WHERE pr.`category_id` = c.`id`
        AND pr.`status` = 'published'
        AND pr.`is_deleted` = 0
        AND pr.`is_active` = 1) AS `product_count`
FROM `categories` c
LEFT JOIN `categories` p ON p.`id` = c.`parent_id`
WHERE c.`is_deleted` = 0
  AND c.`is_active` = 1;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('002_catalog', 2, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  003_cart_wishlist_delivery.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 003 - Cart, Wishlist and Delivery Pricing
--
--  carts, cart_items, wishlist_items, delivery_zones, delivery_pincode_map,
--  delivery_charge_slabs.
--
--  Design notes:
--
--  * Guest carts are first-class. A cart belongs either to a user or to an
--    anonymous token, never both, and is merged into the user's cart on login.
--
--  * cart_items snapshot the unit price, MRP, GST rate and weight at the moment
--    the line was priced. The snapshot is what makes a price change detectable
--    and reportable to the customer instead of silently changing their total.
--
--  * "One active cart per owner" is enforced by the storage layer using STORED
--    generated columns, not by application convention. Two concurrent requests
--    cannot create two active carts for the same customer.
--
--  * Still no inventory (BR-001). A cart line references a variant and a
--    quantity; nothing is reserved or decremented anywhere.
--
--  MySQL 8.0+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- delivery_zones  (BR-006: delivery charges are calculated, not fixed)
-- ---------------------------------------------------------------------------
CREATE TABLE `delivery_zones` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `code`            VARCHAR(30)     NOT NULL COMMENT 'e.g. LOCAL, KA, SOUTH, REMOTE',
    `name`            VARCHAR(120)    NOT NULL,
    `sla_min_days`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `sla_max_days`    TINYINT UNSIGNED NOT NULL DEFAULT 7,
    `is_default`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Fallback for unmapped pincodes',
    `is_serviceable`  TINYINT(1)      NOT NULL DEFAULT 1,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`      BIGINT UNSIGNED NULL,
    `updated_date`    DATETIME        NULL,
    `deleted_by`      BIGINT UNSIGNED NULL,
    `deleted_date`    DATETIME        NULL,
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`      TINYINT(1)      NOT NULL DEFAULT 0,
    `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_delivery_zones_uuid` (`uuid`),
    UNIQUE KEY `uq_delivery_zones_code` (`code`),
    KEY `idx_delivery_zones_default` (`is_default`, `is_active`),
    CONSTRAINT `chk_delivery_zones_sla`
        CHECK (`sla_max_days` >= `sla_min_days`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- delivery_pincode_map
--
-- One table handles both exact pincodes and broad ranges by storing a prefix.
-- Resolution takes the longest matching prefix, so '560001' beats '560' beats
-- '56'. Adding a single-pincode exception never requires a schema change.
-- ---------------------------------------------------------------------------
CREATE TABLE `delivery_pincode_map` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `zone_id`         BIGINT UNSIGNED NOT NULL,
    `pincode_prefix`  VARCHAR(6)      NOT NULL COMMENT '1-6 digits; longest match wins',
    `label`           VARCHAR(120)    NULL,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`      BIGINT UNSIGNED NULL,
    `updated_date`    DATETIME        NULL,
    `deleted_by`      BIGINT UNSIGNED NULL,
    `deleted_date`    DATETIME        NULL,
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`      TINYINT(1)      NOT NULL DEFAULT 0,
    `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_delivery_pincode_map_uuid` (`uuid`),
    UNIQUE KEY `uq_delivery_pincode_prefix` (`pincode_prefix`),
    KEY `idx_delivery_pincode_zone` (`zone_id`),
    CONSTRAINT `chk_pincode_prefix_numeric`
        CHECK (`pincode_prefix` REGEXP '^[1-9][0-9]{0,5}$'),
    CONSTRAINT `fk_delivery_pincode_map_zone`
        FOREIGN KEY (`zone_id`) REFERENCES `delivery_zones` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- delivery_charge_slabs
--
-- Weight bands per zone. `per_extra_kg_amount` covers everything above the
-- heaviest band so there is never a weight the platform cannot quote.
-- ---------------------------------------------------------------------------
CREATE TABLE `delivery_charge_slabs` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `zone_id`                BIGINT UNSIGNED NOT NULL,
    `min_weight_grams`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_weight_grams`       INT UNSIGNED    NULL COMMENT 'NULL = open-ended top band',
    `charge_amount`          DECIMAL(10, 2)  NOT NULL,
    `per_extra_kg_amount`    DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                             COMMENT 'Applied per kg above max_weight_grams on the top band',
    `free_above_order_value` DECIMAL(10, 2)  NULL
                             COMMENT 'Zone override; falls back to the free_delivery_threshold setting',
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
    UNIQUE KEY `uq_delivery_charge_slabs_uuid` (`uuid`),
    UNIQUE KEY `uq_delivery_slab_band` (`zone_id`, `min_weight_grams`),
    KEY `idx_delivery_slab_lookup` (`zone_id`, `min_weight_grams`, `max_weight_grams`),
    CONSTRAINT `chk_slab_band_order`
        CHECK (`max_weight_grams` IS NULL OR `max_weight_grams` > `min_weight_grams`),
    CONSTRAINT `chk_slab_charge_not_negative`
        CHECK (`charge_amount` >= 0 AND `per_extra_kg_amount` >= 0),
    CONSTRAINT `fk_delivery_charge_slabs_zone`
        FOREIGN KEY (`zone_id`) REFERENCES `delivery_zones` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- carts
--
-- `active_owner_user` and `active_owner_guest` are STORED generated columns
-- that are non-NULL only while the cart is live. The unique indexes over them
-- make "at most one active cart per owner" a storage guarantee rather than an
-- application hope — two concurrent add-to-cart requests cannot race into two
-- carts.
-- ---------------------------------------------------------------------------
CREATE TABLE `carts` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `user_id`              BIGINT UNSIGNED NULL COMMENT 'NULL for a guest cart',
    `guest_token_hash`     CHAR(64)        NULL COMMENT 'SHA-256 of the anonymous cart token',
    `status`               ENUM('active','merged','converted','abandoned') NOT NULL DEFAULT 'active',
    `currency_code`        CHAR(3)         NOT NULL DEFAULT 'INR',
    `delivery_pincode`     VARCHAR(10)     NULL COMMENT 'Last pincode used to quote delivery',
    `merged_into_cart_id`  BIGINT UNSIGNED NULL,
    `converted_order_id`   BIGINT UNSIGNED NULL
                           COMMENT 'Set in Phase 5; no FK yet because orders does not exist',
    `last_activity_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`           BIGINT UNSIGNED NULL,
    `created_date`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`           BIGINT UNSIGNED NULL,
    `updated_date`         DATETIME        NULL,
    `deleted_by`           BIGINT UNSIGNED NULL,
    `deleted_date`         DATETIME        NULL,
    `is_active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`           TINYINT(1)      NOT NULL DEFAULT 0,
    `version`              INT UNSIGNED    NOT NULL DEFAULT 1,
    `active_owner_user`    BIGINT UNSIGNED
                           GENERATED ALWAYS AS (IF(`status` = 'active' AND `is_deleted` = 0, `user_id`, NULL)) STORED,
    -- `active_owner_guest` omitted: MariaDB rejects a conditional string in a
    -- generated column (error 1901). `uq_carts_guest_token` below already
    -- enforces one cart per guest token across every row, which is stricter.
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_carts_uuid` (`uuid`),
    UNIQUE KEY `uq_carts_guest_token` (`guest_token_hash`),
    UNIQUE KEY `uq_carts_active_user` (`active_owner_user`),
    KEY `idx_carts_status` (`status`, `last_activity_date`),
    KEY `idx_carts_merged_into` (`merged_into_cart_id`),
    -- A cart has exactly one owner: a user or a guest token, never neither.
    CONSTRAINT `chk_carts_single_owner`
        CHECK ((`user_id` IS NOT NULL AND `guest_token_hash` IS NULL)
            OR (`user_id` IS NULL AND `guest_token_hash` IS NOT NULL)),
    -- RESTRICT on both actions, deliberately. `user_id` is the base column of the
    -- STORED generated column `active_owner_user`, and MySQL forbids CASCADE or
    -- SET NULL referential actions on such a column; it is also named in
    -- chk_carts_single_owner, which forbids ON UPDATE CASCADE independently.
    --
    -- The semantics are the better ones anyway: users in this system are
    -- soft-deleted, never hard-deleted, so a hard purge of a customer who still
    -- has a cart should fail loudly rather than cascade silently. Delete the
    -- carts first, deliberately, if a purge is genuinely required.
    CONSTRAINT `fk_carts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_carts_merged_into`
        FOREIGN KEY (`merged_into_cart_id`) REFERENCES `carts` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- cart_items
--
-- The `*_snapshot` columns record what the customer was shown. On every read
-- the service compares them against the live price and reports any difference
-- rather than quietly re-totalling.
--
-- UNIQUE (cart_id, variant_id) means a variant appears at most once per cart;
-- adding it again increments the quantity, and save-for-later flips a flag
-- instead of creating a second row.
-- ---------------------------------------------------------------------------
CREATE TABLE `cart_items` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `cart_id`                BIGINT UNSIGNED NOT NULL,
    `product_id`             BIGINT UNSIGNED NOT NULL COMMENT 'Denormalised for reporting joins',
    `variant_id`             BIGINT UNSIGNED NOT NULL,
    `quantity`               SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price_snapshot`    DECIMAL(10, 2)  NOT NULL COMMENT 'Effective price when last priced',
    `unit_mrp_snapshot`      DECIMAL(10, 2)  NOT NULL,
    `gst_rate_snapshot`      DECIMAL(5, 2)   NOT NULL,
    `unit_weight_snapshot`   INT UNSIGNED    NOT NULL COMMENT 'Shipping weight in grams',
    `price_changed_date`     DATETIME        NULL COMMENT 'Last time the live price moved away from the snapshot',
    `is_saved_for_later`     TINYINT(1)      NOT NULL DEFAULT 0,
    `is_gift`                TINYINT(1)      NOT NULL DEFAULT 0,
    `gift_message`           VARCHAR(320)    NULL,
    `notes`                  VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_cart_items_uuid` (`uuid`),
    UNIQUE KEY `uq_cart_item_variant` (`cart_id`, `variant_id`),
    KEY `idx_cart_items_cart` (`cart_id`, `is_deleted`, `is_saved_for_later`),
    KEY `idx_cart_items_variant` (`variant_id`),
    KEY `idx_cart_items_product` (`product_id`),
    CONSTRAINT `chk_cart_items_quantity`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_cart_items_prices`
        CHECK (`unit_price_snapshot` >= 0 AND `unit_mrp_snapshot` >= 0),
    CONSTRAINT `fk_cart_items_cart`
        FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_cart_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_cart_items_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wishlist_items
--
-- Wishlists are per product, not per pack size, with an optional preferred
-- variant. That keeps UNIQUE enforceable (a nullable column in a unique key
-- would let MySQL store unlimited duplicates, since NULLs never collide).
-- ---------------------------------------------------------------------------
CREATE TABLE `wishlist_items` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `user_id`                BIGINT UNSIGNED NOT NULL,
    `product_id`             BIGINT UNSIGNED NOT NULL,
    `preferred_variant_id`   BIGINT UNSIGNED NULL,
    `notify_on_offer`        TINYINT(1)      NOT NULL DEFAULT 1,
    `notify_on_price_drop`   TINYINT(1)      NOT NULL DEFAULT 1,
    `price_at_add`           DECIMAL(10, 2)  NULL COMMENT 'Baseline for price-drop alerts',
    `notes`                  VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_wishlist_items_uuid` (`uuid`),
    UNIQUE KEY `uq_wishlist_user_product` (`user_id`, `product_id`),
    KEY `idx_wishlist_user` (`user_id`, `is_deleted`),
    KEY `idx_wishlist_product` (`product_id`),
    CONSTRAINT `fk_wishlist_items_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wishlist_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wishlist_items_variant`
        FOREIGN KEY (`preferred_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- View: cart lines joined to live pricing.
--
-- `live_unit_price` comes from vw_variant_pricing, so the cart reads the same
-- resolved offer price as the catalog. `is_purchasable` folds together every
-- reason a line might no longer be orderable, so no caller has to remember the
-- full list.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_cart_lines` AS
SELECT
    ci.`id`,
    ci.`uuid`,
    ci.`cart_id`,
    ci.`quantity`,
    ci.`is_saved_for_later`,
    ci.`is_gift`,
    ci.`gift_message`,
    ci.`notes`,
    ci.`unit_price_snapshot`,
    ci.`unit_mrp_snapshot`,
    ci.`gst_rate_snapshot`,
    ci.`unit_weight_snapshot`,
    ci.`price_changed_date`,
    ci.`created_date`,
    v.`id`            AS `variant_id`,
    v.`uuid`          AS `variant_uuid`,
    v.`sku`,
    v.`variant_name`,
    v.`weight_grams`,
    v.`pack_type`,
    v.`max_order_quantity`,
    vp.`effective_price` AS `live_unit_price`,
    vp.`mrp`             AS `live_unit_mrp`,
    vp.`shipping_weight_grams` AS `live_unit_weight`,
    vp.`offer_is_live`,
    vp.`discount_percentage`,
    p.`id`            AS `product_id`,
    p.`uuid`          AS `product_uuid`,
    p.`slug`          AS `product_slug`,
    p.`name`          AS `product_name`,
    p.`brand`,
    p.`gst_rate`      AS `live_gst_rate`,
    p.`status`        AS `product_status`,
    p.`is_gift_packable`,
    CASE
        WHEN p.`status` = 'published'
             AND p.`is_deleted` = 0 AND p.`is_active` = 1
             AND v.`is_deleted` = 0 AND v.`is_active` = 1
             AND vp.`id` IS NOT NULL
        THEN 1 ELSE 0
    END AS `is_purchasable`
FROM `cart_items` ci
INNER JOIN `product_variants` v ON v.`id` = ci.`variant_id`
INNER JOIN `products` p         ON p.`id` = ci.`product_id`
LEFT  JOIN `vw_variant_pricing` vp ON vp.`id` = ci.`variant_id`
WHERE ci.`is_deleted` = 0;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('003_cart_wishlist_delivery', 3, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  004_promotions_wallet.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 004 - Coupons, Offers, Referrals and Wallet
--
--  coupons + coupon_targets + coupon_redemptions,
--  offers + offer_targets,
--  referrals,
--  wallet_accounts + wallet_transactions.
--
--  Two decisions are enforced by the schema rather than by convention:
--
--  1. THE WALLET LEDGER IS APPEND-ONLY. Triggers reject any UPDATE or DELETE on
--     wallet_transactions. A balance you can UPDATE is a balance you cannot
--     audit, and referral-fraud disputes are exactly when the history matters.
--     Corrections are posted as new compensating entries, never edits.
--
--  2. WALLET CREDIT IS A PAYMENT TENDER, NOT A DISCOUNT. It never touches the
--     transaction value, so it never reduces GST. Coupons and offers are
--     discounts and do reduce it. Conflating the two understates tax liability
--     and produces an invoice that does not survive an audit.
--
--  MySQL 8.0+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- coupons  (customer-entered discount codes)
-- ---------------------------------------------------------------------------
CREATE TABLE `coupons` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `code`                  VARCHAR(30)     NOT NULL COMMENT 'Stored upper-case; compared case-insensitively',
    `title`                 VARCHAR(160)    NOT NULL,
    `description`           VARCHAR(500)    NULL,
    `terms`                 VARCHAR(1000)   NULL COMMENT 'Shown verbatim to the customer',
    `discount_type`         ENUM('percentage','flat','free_delivery') NOT NULL,
    `discount_value`        DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                            COMMENT 'Percent for percentage, rupees for flat, ignored for free_delivery',
    `max_discount_amount`   DECIMAL(10, 2)  NULL COMMENT 'Cap on a percentage discount',
    `min_order_value`       DECIMAL(10, 2)  NULL,
    `applies_to`            ENUM('all','categories','products') NOT NULL DEFAULT 'all',
    `audience`              ENUM('all','new_customers','specific_customer','referral')
                            NOT NULL DEFAULT 'all',
    `specific_user_id`      BIGINT UNSIGNED NULL COMMENT 'Required when audience = specific_customer',
    `valid_from`            DATETIME        NULL,
    `valid_to`              DATETIME        NULL,
    `total_usage_limit`     INT UNSIGNED    NULL COMMENT 'NULL = unlimited',
    `per_customer_limit`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `total_redeemed`        INT UNSIGNED    NOT NULL DEFAULT 0
                            COMMENT 'Incremented atomically at redemption; never trust a COUNT under load',
    `stackable_with_offer`  TINYINT(1)      NOT NULL DEFAULT 0,
    `status`                ENUM('draft','active','paused','expired') NOT NULL DEFAULT 'draft',
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
    UNIQUE KEY `uq_coupons_uuid` (`uuid`),
    UNIQUE KEY `uq_coupons_code` (`code`),
    KEY `idx_coupons_status` (`status`, `valid_from`, `valid_to`),
    KEY `idx_coupons_audience` (`audience`, `specific_user_id`),
    CONSTRAINT `chk_coupons_percentage_range`
        CHECK (`discount_type` <> 'percentage' OR (`discount_value` > 0 AND `discount_value` <= 100)),
    CONSTRAINT `chk_coupons_flat_positive`
        CHECK (`discount_type` <> 'flat' OR `discount_value` > 0),
    CONSTRAINT `chk_coupons_window`
        CHECK (`valid_from` IS NULL OR `valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `fk_coupons_specific_user`
        FOREIGN KEY (`specific_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- coupon_targets  (which categories or products a scoped coupon covers)
-- ---------------------------------------------------------------------------
CREATE TABLE `coupon_targets` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `coupon_id`      BIGINT UNSIGNED NOT NULL,
    `target_type`    ENUM('category','product') NOT NULL,
    `category_id`    BIGINT UNSIGNED NULL,
    `product_id`     BIGINT UNSIGNED NULL,
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
    UNIQUE KEY `uq_coupon_targets_uuid` (`uuid`),
    KEY `idx_coupon_targets_coupon` (`coupon_id`, `target_type`),
    KEY `idx_coupon_targets_category` (`category_id`),
    KEY `idx_coupon_targets_product` (`product_id`),
    CONSTRAINT `fk_coupon_targets_coupon`
        FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_coupon_targets_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_coupon_targets_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- coupon_redemptions
--
-- One row per actual use. Written when an order is placed (Phase 5), not when
-- a coupon is typed into a cart, so an abandoned cart never consumes a limit.
-- `released` rows survive cancellations so the history stays readable.
-- ---------------------------------------------------------------------------
CREATE TABLE `coupon_redemptions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `coupon_id`         BIGINT UNSIGNED NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `cart_id`           BIGINT UNSIGNED NULL,
    `order_reference`   VARCHAR(50)     NULL COMMENT 'Order number; no FK until Phase 5',
    `discount_amount`   DECIMAL(10, 2)  NOT NULL,
    `order_value`       DECIMAL(10, 2)  NOT NULL,
    `status`            ENUM('confirmed','released') NOT NULL DEFAULT 'confirmed',
    `released_reason`   VARCHAR(160)    NULL,
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
    UNIQUE KEY `uq_coupon_redemptions_uuid` (`uuid`),
    -- A given order can consume a coupon exactly once, whatever a retrying
    -- client does.
    UNIQUE KEY `uq_coupon_redemption_order` (`coupon_id`, `order_reference`),
    KEY `idx_coupon_redemptions_user` (`user_id`, `coupon_id`, `status`),
    KEY `idx_coupon_redemptions_coupon` (`coupon_id`, `status`),
    CONSTRAINT `fk_coupon_redemptions_coupon`
        FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_coupon_redemptions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- offers  (merchandising campaigns; may also carry an automatic discount)
--
-- Distinct from the per-variant `offer_price` in migration 002. That is a price
-- on one pack size; this is a named, dated campaign that groups products for
-- "Today's Deals" style listings and can optionally discount the whole cart.
-- ---------------------------------------------------------------------------
CREATE TABLE `offers` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `code`                   VARCHAR(40)     NOT NULL COMMENT 'Internal reference, e.g. DIWALI25',
    `title`                  VARCHAR(160)    NOT NULL,
    `subtitle`               VARCHAR(240)    NULL,
    `description`            VARCHAR(1000)   NULL,
    `offer_type`             ENUM('festival','flash_sale','deal_of_day','category','combo','free_shipping')
                             NOT NULL DEFAULT 'festival',
    `banner_image_path`      VARCHAR(255)    NULL,
    `discount_type`          ENUM('none','percentage','flat','free_delivery') NOT NULL DEFAULT 'none',
    `discount_value`         DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `max_discount_amount`    DECIMAL(10, 2)  NULL,
    `min_order_value`        DECIMAL(10, 2)  NULL,
    `applies_to`             ENUM('all','categories','products') NOT NULL DEFAULT 'all',
    `stackable_with_coupon`  TINYINT(1)      NOT NULL DEFAULT 0,
    `priority`               SMALLINT UNSIGNED NOT NULL DEFAULT 100
                             COMMENT 'Lower wins when two offers tie on discount value',
    `starts_date`            DATETIME        NULL,
    `ends_date`              DATETIME        NULL,
    `display_order`          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `is_featured`            TINYINT(1)      NOT NULL DEFAULT 0,
    `status`                 ENUM('draft','active','paused','expired') NOT NULL DEFAULT 'draft',
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
    UNIQUE KEY `uq_offers_uuid` (`uuid`),
    UNIQUE KEY `uq_offers_code` (`code`),
    KEY `idx_offers_status` (`status`, `starts_date`, `ends_date`),
    KEY `idx_offers_type` (`offer_type`, `display_order`),
    CONSTRAINT `chk_offers_percentage_range`
        CHECK (`discount_type` <> 'percentage' OR (`discount_value` > 0 AND `discount_value` <= 100)),
    CONSTRAINT `chk_offers_flat_positive`
        CHECK (`discount_type` <> 'flat' OR `discount_value` > 0),
    CONSTRAINT `chk_offers_window`
        CHECK (`starts_date` IS NULL OR `ends_date` IS NULL OR `ends_date` > `starts_date`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- offer_targets
-- ---------------------------------------------------------------------------
CREATE TABLE `offer_targets` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `offer_id`       BIGINT UNSIGNED NOT NULL,
    `target_type`    ENUM('category','product') NOT NULL,
    `category_id`    BIGINT UNSIGNED NULL,
    `product_id`     BIGINT UNSIGNED NULL,
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
    UNIQUE KEY `uq_offer_targets_uuid` (`uuid`),
    KEY `idx_offer_targets_offer` (`offer_id`, `target_type`),
    KEY `idx_offer_targets_category` (`category_id`),
    KEY `idx_offer_targets_product` (`product_id`),
    CONSTRAINT `fk_offer_targets_offer`
        FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_offer_targets_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_offer_targets_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- referrals
--
-- A referral only pays out once the referee has actually completed a paid
-- order. Rewarding at signup is an open invitation to farm accounts, so the
-- lifecycle is pending -> qualified -> rewarded.
-- ---------------------------------------------------------------------------
CREATE TABLE `referrals` (
    `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                     CHAR(36)        NOT NULL,
    `referrer_user_id`         BIGINT UNSIGNED NOT NULL,
    `referee_user_id`          BIGINT UNSIGNED NOT NULL,
    `referral_code_used`       VARCHAR(20)     NOT NULL,
    `status`                   ENUM('pending','qualified','rewarded','cancelled')
                               NOT NULL DEFAULT 'pending',
    `qualifying_order_reference` VARCHAR(50)   NULL,
    `qualifying_order_value`   DECIMAL(10, 2)  NULL,
    `referrer_reward_amount`   DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `referee_reward_amount`    DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `qualified_date`           DATETIME        NULL,
    `rewarded_date`            DATETIME        NULL,
    `cancelled_reason`         VARCHAR(255)    NULL,
    `signup_ip`                VARCHAR(45)     NULL COMMENT 'Retained for fraud review',
    `created_by`               BIGINT UNSIGNED NULL,
    `created_date`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`               BIGINT UNSIGNED NULL,
    `updated_date`             DATETIME        NULL,
    `deleted_by`               BIGINT UNSIGNED NULL,
    `deleted_date`             DATETIME        NULL,
    `is_active`                TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`               TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                  INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_referrals_uuid` (`uuid`),
    -- A person can be referred exactly once, ever.
    UNIQUE KEY `uq_referrals_referee` (`referee_user_id`),
    KEY `idx_referrals_referrer` (`referrer_user_id`, `status`),
    KEY `idx_referrals_status` (`status`, `created_date`),
    CONSTRAINT `fk_referrals_referrer`
        FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_referrals_referee`
        FOREIGN KEY (`referee_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wallet_accounts
--
-- `balance_amount` is a cache of the ledger, maintained inside the same
-- transaction as every entry and guarded by SELECT ... FOR UPDATE. The ledger
-- remains the authority; WalletService::verifyIntegrity() re-derives the
-- balance and reports any drift.
-- ---------------------------------------------------------------------------
CREATE TABLE `wallet_accounts` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `balance_amount`      DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `lifetime_credited`   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `lifetime_debited`    DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `currency_code`       CHAR(3)         NOT NULL DEFAULT 'INR',
    `is_frozen`           TINYINT(1)      NOT NULL DEFAULT 0
                          COMMENT 'Set during a fraud review; blocks redemption but not credits',
    `frozen_reason`       VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_wallet_accounts_uuid` (`uuid`),
    UNIQUE KEY `uq_wallet_accounts_user` (`user_id`),
    CONSTRAINT `chk_wallet_balance_not_negative`
        CHECK (`balance_amount` >= 0),
    CONSTRAINT `fk_wallet_accounts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wallet_transactions  (APPEND-ONLY — see the triggers below)
-- ---------------------------------------------------------------------------
CREATE TABLE `wallet_transactions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `account_id`        BIGINT UNSIGNED NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL COMMENT 'Denormalised for statement queries',
    `direction`         ENUM('credit','debit') NOT NULL,
    `source`            ENUM('referral_reward','referral_signup_bonus','order_refund',
                             'promotional','cashback','redemption','expiry','admin_adjustment')
                        NOT NULL,
    `amount`            DECIMAL(12, 2)  NOT NULL,
    `balance_after`     DECIMAL(12, 2)  NOT NULL COMMENT 'Running balance, so any row can be audited alone',
    `reference_type`    VARCHAR(50)     NULL COMMENT 'e.g. referrals, orders, carts',
    `reference_id`      VARCHAR(60)     NULL,
    `idempotency_key`   VARCHAR(120)    NULL
                        COMMENT 'Unique; makes a retried credit a no-op instead of a double payout',
    `expires_date`      DATETIME        NULL COMMENT 'Credits only; expiry posts a compensating debit',
    `narration`         VARCHAR(255)    NOT NULL,
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
    UNIQUE KEY `uq_wallet_transactions_uuid` (`uuid`),
    UNIQUE KEY `uq_wallet_transactions_idempotency` (`idempotency_key`),
    KEY `idx_wallet_transactions_account` (`account_id`, `created_date`),
    KEY `idx_wallet_transactions_user` (`user_id`, `created_date`),
    KEY `idx_wallet_transactions_source` (`source`, `created_date`),
    KEY `idx_wallet_transactions_expiry` (`direction`, `expires_date`),
    CONSTRAINT `chk_wallet_transactions_amount_positive`
        CHECK (`amount` > 0),
    CONSTRAINT `chk_wallet_transactions_balance_not_negative`
        CHECK (`balance_after` >= 0),
    CONSTRAINT `fk_wallet_transactions_account`
        FOREIGN KEY (`account_id`) REFERENCES `wallet_accounts` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wallet_transactions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wallet_credit_expiries
--
-- Records that an expiring credit has been written off. This exists precisely
-- BECAUSE wallet_transactions is append-only: the original credit row can never
-- be stamped with an "expired" flag, so the marker lives beside it. The write-off
-- itself is a normal debit entry in the ledger; this table is only the guard
-- against expiring the same credit twice.
-- ---------------------------------------------------------------------------
CREATE TABLE `wallet_credit_expiries` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `transaction_id`        BIGINT UNSIGNED NOT NULL COMMENT 'The credit that expired',
    `debit_transaction_id`  BIGINT UNSIGNED NULL COMMENT 'The compensating debit that wrote it off',
    `expired_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
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
    UNIQUE KEY `uq_wallet_credit_expiries_uuid` (`uuid`),
    -- The guarantee: one write-off per credit, ever.
    UNIQUE KEY `uq_wallet_credit_expiries_transaction` (`transaction_id`),
    KEY `idx_wallet_credit_expiries_debit` (`debit_transaction_id`),
    CONSTRAINT `fk_wallet_credit_expiries_transaction`
        FOREIGN KEY (`transaction_id`) REFERENCES `wallet_transactions` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wallet_credit_expiries_debit`
        FOREIGN KEY (`debit_transaction_id`) REFERENCES `wallet_transactions` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Append-only enforcement.
--
-- These are the reason the ledger can be trusted. Application discipline is not
-- enough: any future maintenance script, ORM or console session that tries to
-- "fix" a wallet row will be refused by the database itself. Corrections are
-- posted as new compensating entries.
--
-- Written as single statements (no BEGIN/END), so no DELIMITER change is needed
-- and the migration runner can apply them.
-- ---------------------------------------------------------------------------
CREATE TRIGGER `trg_wallet_transactions_no_update`
BEFORE UPDATE ON `wallet_transactions`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'wallet_transactions is append-only: post a compensating entry instead of editing';

CREATE TRIGGER `trg_wallet_transactions_no_delete`
BEFORE DELETE ON `wallet_transactions`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'wallet_transactions is append-only: rows can never be deleted';

-- ---------------------------------------------------------------------------
-- carts: remember the applied coupon and the wallet redemption intent.
-- ---------------------------------------------------------------------------
ALTER TABLE `carts`
    ADD COLUMN `applied_coupon_id`    BIGINT UNSIGNED NULL AFTER `delivery_pincode`,
    ADD COLUMN `applied_coupon_code`  VARCHAR(30)     NULL AFTER `applied_coupon_id`,
    ADD COLUMN `wallet_redeem_amount` DECIMAL(10, 2)  NOT NULL DEFAULT 0.00 AFTER `applied_coupon_code`,
    ADD KEY `idx_carts_coupon` (`applied_coupon_id`),
    ADD CONSTRAINT `fk_carts_coupon`
        FOREIGN KEY (`applied_coupon_id`) REFERENCES `coupons` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- vw_cart_lines gains category_id, so a category-scoped coupon can work out
-- which lines it covers without a second query per line.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_cart_lines` AS
SELECT
    ci.`id`,
    ci.`uuid`,
    ci.`cart_id`,
    ci.`quantity`,
    ci.`is_saved_for_later`,
    ci.`is_gift`,
    ci.`gift_message`,
    ci.`notes`,
    ci.`unit_price_snapshot`,
    ci.`unit_mrp_snapshot`,
    ci.`gst_rate_snapshot`,
    ci.`unit_weight_snapshot`,
    ci.`price_changed_date`,
    ci.`created_date`,
    v.`id`            AS `variant_id`,
    v.`uuid`          AS `variant_uuid`,
    v.`sku`,
    v.`variant_name`,
    v.`weight_grams`,
    v.`pack_type`,
    v.`max_order_quantity`,
    vp.`effective_price` AS `live_unit_price`,
    vp.`mrp`             AS `live_unit_mrp`,
    vp.`shipping_weight_grams` AS `live_unit_weight`,
    vp.`offer_is_live`,
    vp.`discount_percentage`,
    p.`id`            AS `product_id`,
    p.`uuid`          AS `product_uuid`,
    p.`slug`          AS `product_slug`,
    p.`name`          AS `product_name`,
    p.`brand`,
    p.`gst_rate`      AS `live_gst_rate`,
    p.`status`        AS `product_status`,
    p.`is_gift_packable`,
    p.`category_id`,
    c.`parent_id`     AS `category_parent_id`,
    c.`slug`          AS `category_slug`,
    CASE
        WHEN p.`status` = 'published'
             AND p.`is_deleted` = 0 AND p.`is_active` = 1
             AND v.`is_deleted` = 0 AND v.`is_active` = 1
             AND vp.`id` IS NOT NULL
        THEN 1 ELSE 0
    END AS `is_purchasable`
FROM `cart_items` ci
INNER JOIN `product_variants` v ON v.`id` = ci.`variant_id`
INNER JOIN `products` p         ON p.`id` = ci.`product_id`
INNER JOIN `categories` c       ON c.`id` = p.`category_id`
LEFT  JOIN `vw_variant_pricing` vp ON vp.`id` = ci.`variant_id`
WHERE ci.`is_deleted` = 0;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_coupon_performance` AS
SELECT
    c.`id`,
    c.`uuid`,
    c.`code`,
    c.`title`,
    c.`discount_type`,
    c.`discount_value`,
    c.`status`,
    c.`valid_from`,
    c.`valid_to`,
    c.`total_usage_limit`,
    c.`total_redeemed`,
    COUNT(r.`id`)                                                   AS `redemption_rows`,
    COALESCE(SUM(CASE WHEN r.`status` = 'confirmed' THEN r.`discount_amount` ELSE 0 END), 0) AS `total_discount_given`,
    COALESCE(SUM(CASE WHEN r.`status` = 'confirmed' THEN r.`order_value`     ELSE 0 END), 0) AS `total_order_value`,
    COUNT(DISTINCT r.`user_id`)                                     AS `unique_customers`
FROM `coupons` c
LEFT JOIN `coupon_redemptions` r
       ON r.`coupon_id` = c.`id` AND r.`is_deleted` = 0
WHERE c.`is_deleted` = 0
GROUP BY c.`id`, c.`uuid`, c.`code`, c.`title`, c.`discount_type`,
         c.`discount_value`, c.`status`, c.`valid_from`, c.`valid_to`,
         c.`total_usage_limit`, c.`total_redeemed`;

CREATE OR REPLACE VIEW `vw_referral_summary` AS
SELECT
    u.`id`                AS `user_id`,
    u.`uuid`              AS `user_uuid`,
    u.`full_name`,
    u.`referral_code`,
    COUNT(r.`id`)                                                       AS `total_invited`,
    SUM(CASE WHEN r.`status` = 'pending'   THEN 1 ELSE 0 END)            AS `pending_count`,
    SUM(CASE WHEN r.`status` = 'qualified' THEN 1 ELSE 0 END)            AS `qualified_count`,
    SUM(CASE WHEN r.`status` = 'rewarded'  THEN 1 ELSE 0 END)            AS `rewarded_count`,
    COALESCE(SUM(CASE WHEN r.`status` = 'rewarded' THEN r.`referrer_reward_amount` ELSE 0 END), 0)
                                                                        AS `total_earned`
FROM `users` u
LEFT JOIN `referrals` r
       ON r.`referrer_user_id` = u.`id` AND r.`is_deleted` = 0
WHERE u.`is_deleted` = 0
GROUP BY u.`id`, u.`uuid`, u.`full_name`, u.`referral_code`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('004_promotions_wallet', 4, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  005_orders_payments.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 005 - Checkout, Orders and UPI Payment
--
--  orders, order_items, order_tax_lines, order_status_history,
--  payments, payment_events, refunds, numbering_sequences.
--
--  The rules this schema exists to make unbreakable:
--
--  BR-003  An order cannot be confirmed until its OTP is verified.
--  BR-004  Prepaid UPI only. There is no cash-on-delivery column anywhere,
--          because there is no code path that could honour one.
--  BR-005  An order cannot progress past `confirmed` unless payment_status is
--          'paid'. Enforced in OrderStateMachine, and every transition is
--          recorded in order_status_history so the claim is auditable.
--  BR-008  Every order carries a complete, append-only timeline.
--
--  EVERYTHING ON AN ORDER IS A SNAPSHOT. Prices, addresses, product names, tax
--  rates and coupon values are all copied at placement. A product renamed or an
--  address deleted six months later must not alter a historical invoice — under
--  Indian GST rules that invoice has to reproduce exactly as issued.
--
--  MySQL 8.0.16+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- numbering_sequences
--
-- Gapless, atomic counters for order numbers and GST invoice numbers.
--
-- Invoice numbers are a legal requirement, not a convenience: under Indian GST
-- they must be sequential within a financial year and must not have gaps.
-- Deriving them from an AUTO_INCREMENT id would leave holes wherever a row was
-- rolled back, so they come from a counter incremented under a row lock and
-- only ever at the moment payment is confirmed. An unpaid order never consumes
-- an invoice number.
-- ---------------------------------------------------------------------------
CREATE TABLE `numbering_sequences` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `sequence_key`     VARCHAR(60)     NOT NULL COMMENT 'e.g. order:2026-27, invoice:2026-27',
    `purpose`          ENUM('order','invoice') NOT NULL,
    `prefix`           VARCHAR(12)     NOT NULL,
    `financial_year`   CHAR(7)         NOT NULL COMMENT 'Indian FY, e.g. 2026-27',
    `last_number`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_numbering_sequences_uuid` (`uuid`),
    UNIQUE KEY `uq_numbering_sequences_key` (`sequence_key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- orders
-- ---------------------------------------------------------------------------
CREATE TABLE `orders` (
    `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                        CHAR(36)        NOT NULL,
    `order_number`                VARCHAR(30)     NOT NULL,
    `user_id`                     BIGINT UNSIGNED NOT NULL,
    `cart_id`                     BIGINT UNSIGNED NULL COMMENT 'Source cart, for support queries',

    `status`                      ENUM('created','awaiting_payment','confirmed','packed',
                                       'ready_to_ship','assigned','shipped','out_for_delivery',
                                       'delivered','cancelled','returned','refunded')
                                  NOT NULL DEFAULT 'created',
    `payment_status`              ENUM('pending','processing','paid','failed',
                                       'refunded','partially_refunded')
                                  NOT NULL DEFAULT 'pending',

    -- Money. Every figure copied from the PriceBreakdown that the customer saw.
    `currency_code`               CHAR(3)         NOT NULL DEFAULT 'INR',
    `items_mrp_total`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `items_subtotal`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `product_discount`            DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `order_discount`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `order_surcharge`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `delivery_charge`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `delivery_charge_before_waiver` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `delivery_discount`           DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `taxable_value`               DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `tax_total`                   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `grand_total`                 DECIMAL(12, 2)  NOT NULL,
    -- Wallet credit is a TENDER, not a discount: it reduces amount_payable and
    -- leaves grand_total and tax_total untouched, so GST stays correct.
    `wallet_applied`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `amount_payable`              DECIMAL(12, 2)  NOT NULL,
    `amount_paid`                 DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `amount_refunded`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `total_savings`               DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,

    -- Promotion snapshot
    `coupon_id`                   BIGINT UNSIGNED NULL,
    `coupon_code`                 VARCHAR(30)     NULL,
    `coupon_discount`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `offer_id`                    BIGINT UNSIGNED NULL,
    `offer_code`                  VARCHAR(40)     NULL,
    `offer_discount`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,

    -- Shipping address SNAPSHOT. Never a foreign key to user_addresses: a
    -- customer editing or deleting an address must not rewrite past orders.
    `ship_name`                   VARCHAR(120)    NOT NULL,
    `ship_mobile`                 VARCHAR(15)     NOT NULL,
    `ship_alternate_mobile`       VARCHAR(15)     NULL,
    `ship_address_line1`          VARCHAR(180)    NOT NULL,
    `ship_address_line2`          VARCHAR(180)    NULL,
    `ship_landmark`               VARCHAR(120)    NULL,
    `ship_city`                   VARCHAR(90)     NOT NULL,
    `ship_state`                  VARCHAR(90)     NOT NULL,
    `ship_pincode`                VARCHAR(10)     NOT NULL,
    `ship_country`                VARCHAR(60)     NOT NULL DEFAULT 'India',
    `source_address_id`           BIGINT UNSIGNED NULL COMMENT 'Informational only; may be deleted later',

    -- Delivery
    `delivery_zone_code`          VARCHAR(30)     NULL,
    `delivery_sla_min_days`       TINYINT UNSIGNED NULL,
    `delivery_sla_max_days`       TINYINT UNSIGNED NULL,
    `expected_delivery_date`      DATE            NULL,
    `delivery_slot`               VARCHAR(60)     NULL,
    `delivery_instructions`       VARCHAR(500)    NULL,
    `total_weight_grams`          INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Courier fields, populated in Phase 6 (BR-007)
    `courier_code`                VARCHAR(40)     NULL,
    `courier_name`                VARCHAR(120)    NULL,
    `tracking_number`             VARCHAR(80)     NULL,
    `tracking_url`                VARCHAR(500)    NULL,
    `shipped_date`                DATETIME        NULL,

    `is_gift`                     TINYINT(1)      NOT NULL DEFAULT 0,
    `gift_message`                VARCHAR(320)    NULL,

    -- BR-003: OTP verification before confirmation
    `otp_verified`                TINYINT(1)      NOT NULL DEFAULT 0,
    `otp_verified_date`           DATETIME        NULL,

    -- GST invoice, assigned only when payment is confirmed
    `invoice_number`              VARCHAR(30)     NULL,
    `invoice_date`                DATETIME        NULL,
    `invoice_financial_year`      CHAR(7)         NULL,

    -- Lifecycle
    `placed_date`                 DATETIME        NULL,
    `confirmed_date`              DATETIME        NULL,
    `delivered_date`              DATETIME        NULL,
    `cancelled_date`              DATETIME        NULL,
    `cancelled_by`                BIGINT UNSIGNED NULL,
    `cancellation_reason`         VARCHAR(255)    NULL,
    `expires_date`                DATETIME        NULL
                                  COMMENT 'Unpaid orders expire, releasing coupon and wallet credit',

    `customer_note`               VARCHAR(500)    NULL,
    `internal_note`               VARCHAR(1000)   NULL,
    `placed_ip`                   VARCHAR(45)     NULL,
    `placed_channel`              ENUM('web','android','ios','admin') NOT NULL DEFAULT 'web',

    `created_by`                  BIGINT UNSIGNED NULL,
    `created_date`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`                  BIGINT UNSIGNED NULL,
    `updated_date`                DATETIME        NULL,
    `deleted_by`                  BIGINT UNSIGNED NULL,
    `deleted_date`                DATETIME        NULL,
    `is_active`                   TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`                  TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                     INT UNSIGNED    NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_uuid` (`uuid`),
    UNIQUE KEY `uq_orders_number` (`order_number`),
    UNIQUE KEY `uq_orders_invoice_number` (`invoice_number`),
    KEY `idx_orders_user` (`user_id`, `created_date`),
    KEY `idx_orders_status` (`status`, `created_date`),
    KEY `idx_orders_payment_status` (`payment_status`, `created_date`),
    KEY `idx_orders_expiry` (`status`, `expires_date`),
    KEY `idx_orders_pincode` (`ship_pincode`),
    KEY `idx_orders_tracking` (`tracking_number`),
    KEY `idx_orders_coupon` (`coupon_id`),

    CONSTRAINT `chk_orders_totals_not_negative`
        CHECK (`grand_total` >= 0 AND `amount_payable` >= 0 AND `wallet_applied` >= 0
           AND `amount_paid` >= 0 AND `amount_refunded` >= 0),
    -- Wallet credit can never exceed the order value.
    CONSTRAINT `chk_orders_wallet_within_total`
        CHECK (`wallet_applied` <= `grand_total`),
    -- The tender split must always add back up to the order value.
    CONSTRAINT `chk_orders_payable_split`
        CHECK (`amount_payable` = `grand_total` - `wallet_applied`),

    CONSTRAINT `fk_orders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_orders_cart`
        FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_orders_coupon`
        FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_items
--
-- Product identity is snapshotted alongside the foreign key. The FK is for
-- reporting joins; the snapshot columns are what the invoice reproduces.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_items` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `order_id`              BIGINT UNSIGNED NOT NULL,
    `product_id`            BIGINT UNSIGNED NOT NULL,
    `variant_id`            BIGINT UNSIGNED NOT NULL,

    `product_name`          VARCHAR(180)    NOT NULL,
    `variant_name`          VARCHAR(120)    NOT NULL,
    `sku`                   VARCHAR(60)     NOT NULL,
    `brand`                 VARCHAR(120)    NULL,
    `hsn_code`              VARCHAR(12)     NULL COMMENT 'Required on a GST invoice',

    `quantity`              SMALLINT UNSIGNED NOT NULL,
    `unit_mrp`              DECIMAL(10, 2)  NOT NULL,
    `unit_price`            DECIMAL(10, 2)  NOT NULL,
    `line_mrp`              DECIMAL(12, 2)  NOT NULL,
    `line_subtotal`         DECIMAL(12, 2)  NOT NULL,
    `product_discount`      DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `apportioned_discount`  DECIMAL(12, 2)  NOT NULL DEFAULT 0.00
                            COMMENT 'Share of the order-level discount carried by this line',
    `line_payable`          DECIMAL(12, 2)  NOT NULL,
    `taxable_value`         DECIMAL(12, 2)  NOT NULL,
    `tax_amount`            DECIMAL(12, 2)  NOT NULL,
    `gst_rate`              DECIMAL(5, 2)   NOT NULL,
    `line_weight_grams`     INT UNSIGNED    NOT NULL DEFAULT 0,

    `is_gift`               TINYINT(1)      NOT NULL DEFAULT 0,
    `gift_message`          VARCHAR(320)    NULL,

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
    UNIQUE KEY `uq_order_items_uuid` (`uuid`),
    UNIQUE KEY `uq_order_item_variant` (`order_id`, `variant_id`),
    KEY `idx_order_items_order` (`order_id`),
    KEY `idx_order_items_product` (`product_id`),
    KEY `idx_order_items_variant` (`variant_id`),

    CONSTRAINT `chk_order_items_quantity`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_order_items_tax_reconciles`
        CHECK (`taxable_value` + `tax_amount` = `line_payable`),

    CONSTRAINT `fk_order_items_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_order_items_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_tax_lines
--
-- GST summary per rate. An invoice for a cart mixing 5% spices and 12% nuts
-- needs both lines shown separately; recomputing that later from order_items
-- would risk disagreeing with the issued invoice.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_tax_lines` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `order_id`        BIGINT UNSIGNED NOT NULL,
    `gst_rate`        DECIMAL(5, 2)   NOT NULL,
    `taxable_value`   DECIMAL(12, 2)  NOT NULL,
    `tax_amount`      DECIMAL(12, 2)  NOT NULL,
    -- Intra-state sales split GST into CGST + SGST; inter-state use IGST.
    `cgst_amount`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `sgst_amount`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `igst_amount`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`      BIGINT UNSIGNED NULL,
    `updated_date`    DATETIME        NULL,
    `deleted_by`      BIGINT UNSIGNED NULL,
    `deleted_date`    DATETIME        NULL,
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`      TINYINT(1)      NOT NULL DEFAULT 0,
    `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_tax_lines_uuid` (`uuid`),
    UNIQUE KEY `uq_order_tax_rate` (`order_id`, `gst_rate`),
    CONSTRAINT `chk_order_tax_split`
        CHECK (`cgst_amount` + `sgst_amount` + `igst_amount` = `tax_amount`),
    CONSTRAINT `fk_order_tax_lines_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_status_history  (BR-008)
--
-- Append-only in practice: nothing in the codebase updates or deletes a row.
-- `is_customer_visible` separates the tracking page from the internal audit
-- trail, so operational notes never leak to a customer.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_status_history` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `order_id`              BIGINT UNSIGNED NOT NULL,
    `from_status`           VARCHAR(30)     NULL,
    `to_status`             VARCHAR(30)     NOT NULL,
    `payment_status`        VARCHAR(30)     NULL,
    `title`                 VARCHAR(120)    NOT NULL,
    `note`                  VARCHAR(500)    NULL,
    `is_customer_visible`   TINYINT(1)      NOT NULL DEFAULT 1,
    `changed_by`            BIGINT UNSIGNED NULL COMMENT 'NULL means the system, e.g. a webhook',
    `changed_by_role`       VARCHAR(30)     NULL,
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
    UNIQUE KEY `uq_order_status_history_uuid` (`uuid`),
    KEY `idx_order_status_history_order` (`order_id`, `created_date`),
    CONSTRAINT `fk_order_status_history_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payments
--
-- One row per attempt. A customer who fails UPI twice and succeeds on the third
-- try leaves three rows, which is what a payment dispute needs.
-- ---------------------------------------------------------------------------
CREATE TABLE `payments` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `order_id`               BIGINT UNSIGNED NOT NULL,
    `user_id`                BIGINT UNSIGNED NOT NULL,
    `gateway`                VARCHAR(40)     NOT NULL COMMENT 'razorpay, cashfree, sandbox',
    `gateway_order_id`       VARCHAR(120)    NULL,
    `gateway_payment_id`     VARCHAR(120)    NULL,
    `attempt_number`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `amount`                 DECIMAL(12, 2)  NOT NULL,
    `currency_code`          CHAR(3)         NOT NULL DEFAULT 'INR',
    `status`                 ENUM('created','pending','authorized','captured',
                                  'failed','cancelled','refunded','partially_refunded')
                             NOT NULL DEFAULT 'created',
    -- BR-004: UPI only. The column exists to record which UPI app was used.
    `method`                 VARCHAR(30)     NOT NULL DEFAULT 'upi',
    `upi_vpa`                VARCHAR(120)    NULL,
    `upi_transaction_id`     VARCHAR(120)    NULL,
    `signature_verified`     TINYINT(1)      NOT NULL DEFAULT 0
                             COMMENT 'BR-005 depends on this: an unverified payment never confirms an order',
    `failure_code`           VARCHAR(60)     NULL,
    `failure_reason`         VARCHAR(255)    NULL,
    `authorized_date`        DATETIME        NULL,
    `captured_date`          DATETIME        NULL,
    `failed_date`            DATETIME        NULL,
    `expires_date`           DATETIME        NULL,
    `checkout_payload`       JSON            NULL COMMENT 'What was handed to the client',
    `gateway_response`       JSON            NULL,
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
    UNIQUE KEY `uq_payments_uuid` (`uuid`),
    -- The same gateway payment can never be recorded twice, whatever a retried
    -- webhook or a double callback does.
    UNIQUE KEY `uq_payments_gateway_payment` (`gateway`, `gateway_payment_id`),
    UNIQUE KEY `uq_payments_attempt` (`order_id`, `attempt_number`),
    KEY `idx_payments_order` (`order_id`, `status`),
    KEY `idx_payments_gateway_order` (`gateway`, `gateway_order_id`),
    KEY `idx_payments_status` (`status`, `created_date`),
    CONSTRAINT `chk_payments_amount_positive`
        CHECK (`amount` > 0),
    CONSTRAINT `fk_payments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_payments_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payment_events
--
-- Every inbound webhook, stored raw before anything acts on it.
--
-- The UNIQUE key on (gateway, event_id) is what makes webhook handling
-- idempotent: gateways retry aggressively and deliver out of order, and a
-- duplicate "payment captured" must not confirm an order twice or pay a
-- referral reward twice.
-- ---------------------------------------------------------------------------
CREATE TABLE `payment_events` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `gateway`             VARCHAR(40)     NOT NULL,
    `event_id`            VARCHAR(160)    NOT NULL COMMENT 'Gateway event identifier',
    `event_type`          VARCHAR(80)     NOT NULL,
    `gateway_order_id`    VARCHAR(120)    NULL,
    `gateway_payment_id`  VARCHAR(120)    NULL,
    `order_id`            BIGINT UNSIGNED NULL,
    `signature_valid`     TINYINT(1)      NOT NULL DEFAULT 0,
    `payload`             JSON            NOT NULL,
    `processed`           TINYINT(1)      NOT NULL DEFAULT 0,
    `processed_date`      DATETIME        NULL,
    `processing_error`    VARCHAR(500)    NULL,
    `received_ip`         VARCHAR(45)     NULL,
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
    UNIQUE KEY `uq_payment_events_uuid` (`uuid`),
    UNIQUE KEY `uq_payment_events_event` (`gateway`, `event_id`),
    KEY `idx_payment_events_order` (`order_id`),
    KEY `idx_payment_events_unprocessed` (`processed`, `created_date`),
    KEY `idx_payment_events_gateway_payment` (`gateway_payment_id`),
    CONSTRAINT `fk_payment_events_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- refunds
--
-- A refund can be split between the gateway and the wallet, because part of the
-- order may have been paid with wallet credit in the first place. Wallet credit
-- is returned as a wallet credit, not as cash.
-- ---------------------------------------------------------------------------
CREATE TABLE `refunds` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `order_id`             BIGINT UNSIGNED NOT NULL,
    `payment_id`           BIGINT UNSIGNED NULL,
    `gateway`              VARCHAR(40)     NULL,
    `gateway_refund_id`    VARCHAR(120)    NULL,
    `total_amount`         DECIMAL(12, 2)  NOT NULL,
    `gateway_amount`       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `wallet_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `reason`               VARCHAR(255)    NOT NULL,
    `status`               ENUM('pending','processing','completed','failed')
                           NOT NULL DEFAULT 'pending',
    `failure_reason`       VARCHAR(255)    NULL,
    `completed_date`       DATETIME        NULL,
    `idempotency_key`      VARCHAR(120)    NULL,
    `gateway_response`     JSON            NULL,
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
    UNIQUE KEY `uq_refunds_uuid` (`uuid`),
    UNIQUE KEY `uq_refunds_idempotency` (`idempotency_key`),
    KEY `idx_refunds_order` (`order_id`, `status`),
    KEY `idx_refunds_payment` (`payment_id`),
    CONSTRAINT `chk_refunds_split_adds_up`
        CHECK (`gateway_amount` + `wallet_amount` = `total_amount`),
    CONSTRAINT `chk_refunds_amount_positive`
        CHECK (`total_amount` > 0),
    CONSTRAINT `fk_refunds_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_refunds_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- carts.converted_order_id can finally carry a foreign key.
-- ---------------------------------------------------------------------------
ALTER TABLE `carts`
    ADD CONSTRAINT `fk_carts_converted_order`
        FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_order_summary` AS
SELECT
    o.`id`,
    o.`uuid`,
    o.`order_number`,
    o.`user_id`,
    o.`status`,
    o.`payment_status`,
    o.`grand_total`,
    o.`wallet_applied`,
    o.`amount_payable`,
    o.`amount_paid`,
    o.`amount_refunded`,
    o.`tax_total`,
    o.`coupon_code`,
    o.`offer_code`,
    o.`ship_city`,
    o.`ship_state`,
    o.`ship_pincode`,
    o.`delivery_zone_code`,
    o.`total_weight_grams`,
    o.`courier_code`,
    o.`tracking_number`,
    o.`invoice_number`,
    o.`otp_verified`,
    o.`placed_date`,
    o.`confirmed_date`,
    o.`delivered_date`,
    o.`expected_delivery_date`,
    o.`created_date`,
    u.`full_name`  AS `customer_name`,
    u.`mobile`     AS `customer_mobile`,
    u.`email`      AS `customer_email`,
    (SELECT COUNT(*) FROM `order_items` oi
      WHERE oi.`order_id` = o.`id` AND oi.`is_deleted` = 0) AS `item_count`,
    (SELECT COALESCE(SUM(oi.`quantity`), 0) FROM `order_items` oi
      WHERE oi.`order_id` = o.`id` AND oi.`is_deleted` = 0) AS `unit_count`
FROM `orders` o
INNER JOIN `users` u ON u.`id` = o.`user_id`
WHERE o.`is_deleted` = 0;

CREATE OR REPLACE VIEW `vw_daily_sales` AS
SELECT
    DATE(o.`confirmed_date`)                       AS `sales_date`,
    COUNT(*)                                       AS `order_count`,
    SUM(o.`grand_total`)                           AS `gross_sales`,
    SUM(o.`taxable_value`)                         AS `taxable_value`,
    SUM(o.`tax_total`)                             AS `tax_collected`,
    SUM(o.`delivery_charge`)                       AS `delivery_collected`,
    SUM(o.`order_discount` + o.`product_discount`) AS `discount_given`,
    SUM(o.`wallet_applied`)                        AS `wallet_redeemed`,
    SUM(o.`amount_payable`)                        AS `collected_online`,
    SUM(o.`amount_refunded`)                       AS `refunded`
FROM `orders` o
WHERE o.`is_deleted` = 0
  AND o.`confirmed_date` IS NOT NULL
  AND o.`status` <> 'cancelled'
GROUP BY DATE(o.`confirmed_date`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('005_orders_payments', 5, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  006_delivery_couriers.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 006 - Delivery Integration and Courier Selection
--
--  couriers, courier_serviceability, courier_rate_cards, shipments,
--  shipment_events, courier_selections, pickup_requests, manifests.
--
--  BR-007  The courier is chosen automatically from weight, destination
--          pincode, order value, availability, cost and SLA. Every decision is
--          recorded in courier_selections with the alternatives that were
--          considered, because "why did this parcel go to Delhivery?" is a
--          question operations will ask and guessing is not an answer.
--
--  BR-008  Every tracking scan is appended to shipment_events and never edited.
--
--  Two things worth knowing before reading further:
--
--  CHARGEABLE WEIGHT IS NOT ACTUAL WEIGHT. Couriers bill on whichever is
--  greater of actual and volumetric weight (L x W x H / 5000 in centimetres).
--  A carton of saffron boxes is light and bulky, so it bills volumetrically;
--  a bag of whole spices bills on the scale. Storing only actual weight means
--  every quote is wrong for half the catalogue.
--
--  COURIER CREDENTIALS ARE NOT IN THIS DATABASE. They live in environment
--  variables. A leaked database backup should not also be a leaked set of
--  shipping accounts capable of booking parcels at the merchant's expense.
--
--  MySQL 8.0.16+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- Package dimensions on the variant.
--
-- Nullable on purpose: a retailer will not have measured every SKU on day one,
-- and refusing to ship until they have would be absurd. Where dimensions are
-- missing the selector falls back to a configured default box and records that
-- it did so, so the gap is visible rather than silent.
-- ---------------------------------------------------------------------------
ALTER TABLE `product_variants`
    ADD COLUMN `pack_length_mm` SMALLINT UNSIGNED NULL
        COMMENT 'Outer packed dimensions, for volumetric weight' AFTER `weight_grams`,
    ADD COLUMN `pack_width_mm`  SMALLINT UNSIGNED NULL AFTER `pack_length_mm`,
    ADD COLUMN `pack_height_mm` SMALLINT UNSIGNED NULL AFTER `pack_width_mm`,
    ADD COLUMN `is_fragile`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `pack_height_mm`;

-- ---------------------------------------------------------------------------
-- couriers
--
-- `adapter` selects the integration class. Several couriers can share one
-- adapter: Shiprocket is an aggregator that fronts Delhivery, Blue Dart,
-- XpressBees and DTDC under a single contract, so those arrive as separate
-- rows pointing at the same adapter with a different `channel_code`.
-- ---------------------------------------------------------------------------
CREATE TABLE `couriers` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `code`                  VARCHAR(40)     NOT NULL,
    `name`                  VARCHAR(120)    NOT NULL,
    `adapter`               VARCHAR(40)     NOT NULL
                            COMMENT 'shiprocket, delhivery, sandbox',
    `channel_code`          VARCHAR(60)     NULL
                            COMMENT 'Courier id within an aggregator',
    `logo_url`              VARCHAR(500)    NULL,
    `support_phone`         VARCHAR(20)     NULL,
    `tracking_url_template` VARCHAR(500)    NULL
                            COMMENT 'Contains {awb}',

    -- Eligibility. A courier outside these bounds is never a candidate.
    `min_weight_grams`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_weight_grams`      INT UNSIGNED    NOT NULL DEFAULT 30000,
    `max_order_value`       DECIMAL(12, 2)  NULL
                            COMMENT 'Insurance ceiling; above this the courier is skipped',
    `max_length_mm`         SMALLINT UNSIGNED NULL,
    `max_width_mm`          SMALLINT UNSIGNED NULL,
    `max_height_mm`         SMALLINT UNSIGNED NULL,
    `handles_fragile`       TINYINT(1)      NOT NULL DEFAULT 1,

    -- Scoring inputs (BR-007)
    `priority`              SMALLINT        NOT NULL DEFAULT 100
                            COMMENT 'Lower wins ties; a commercial preference lever',
    `reliability_score`     DECIMAL(5, 2)   NOT NULL DEFAULT 80.00
                            COMMENT '0-100, maintained from delivery outcomes',
    `volumetric_divisor`    SMALLINT UNSIGNED NOT NULL DEFAULT 5000
                            COMMENT 'cm3 per kg; 5000 is the Indian norm, some couriers use 4000',

    `supports_pickup`       TINYINT(1)      NOT NULL DEFAULT 1,
    `supports_label`        TINYINT(1)      NOT NULL DEFAULT 1,
    `supports_manifest`     TINYINT(1)      NOT NULL DEFAULT 1,
    `supports_rto`          TINYINT(1)      NOT NULL DEFAULT 1,

    `is_enabled`            TINYINT(1)      NOT NULL DEFAULT 1
                            COMMENT 'Operational switch, separate from is_active',
    `disabled_reason`       VARCHAR(255)    NULL,
    `settings`              JSON            NULL COMMENT 'Non-secret adapter options only',

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
    UNIQUE KEY `uq_couriers_uuid` (`uuid`),
    UNIQUE KEY `uq_couriers_code` (`code`),
    KEY `idx_couriers_enabled` (`is_enabled`, `is_active`, `is_deleted`),

    CONSTRAINT `chk_couriers_weight_range`
        CHECK (`max_weight_grams` > `min_weight_grams`),
    CONSTRAINT `chk_couriers_reliability_range`
        CHECK (`reliability_score` >= 0 AND `reliability_score` <= 100),
    CONSTRAINT `chk_couriers_divisor_positive`
        CHECK (`volumetric_divisor` > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- courier_serviceability
--
-- Longest-prefix matching, the same approach delivery_pincode_map uses: '5600'
-- covers Bengaluru, '560001' overrides it for one pincode. Storing every
-- serviceable pincode for every courier would be roughly 19,000 x N rows to
-- maintain by hand.
-- ---------------------------------------------------------------------------
CREATE TABLE `courier_serviceability` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `courier_id`       BIGINT UNSIGNED NOT NULL,
    `pincode_prefix`   VARCHAR(6)      NOT NULL,
    `is_serviceable`   TINYINT(1)      NOT NULL DEFAULT 1
                       COMMENT 'A row can exclude as well as include',
    `sla_min_days`     TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `sla_max_days`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `is_express`       TINYINT(1)      NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_courier_serviceability_uuid` (`uuid`),
    UNIQUE KEY `uq_courier_prefix` (`courier_id`, `pincode_prefix`),
    KEY `idx_courier_serviceability_prefix` (`pincode_prefix`),
    CONSTRAINT `chk_courier_sla_order`
        CHECK (`sla_max_days` >= `sla_min_days`),
    CONSTRAINT `fk_courier_serviceability_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- courier_rate_cards
--
-- Negotiated rates, used to quote without an API round trip and as the fallback
-- when a live rate call fails. A courier API being slow must not stop a parcel
-- being booked.
-- ---------------------------------------------------------------------------
CREATE TABLE `courier_rate_cards` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `courier_id`           BIGINT UNSIGNED NOT NULL,
    `zone_code`            VARCHAR(30)     NOT NULL
                           COMMENT 'Matches delivery_zones.code',
    `min_weight_grams`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_weight_grams`     INT UNSIGNED    NULL COMMENT 'NULL means open-ended',
    `base_charge`          DECIMAL(10, 2)  NOT NULL,
    `per_kg_charge`        DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                           COMMENT 'Applied to weight above min_weight_grams',
    `fuel_surcharge_pct`   DECIMAL(5, 2)   NOT NULL DEFAULT 0.00,
    `handling_charge`      DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `effective_from`       DATE            NULL,
    `effective_until`      DATE            NULL,
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
    UNIQUE KEY `uq_courier_rate_cards_uuid` (`uuid`),
    UNIQUE KEY `uq_courier_zone_slab` (`courier_id`, `zone_code`, `min_weight_grams`),
    KEY `idx_courier_rate_cards_lookup` (`courier_id`, `zone_code`, `min_weight_grams`),
    CONSTRAINT `chk_courier_rate_slab_order`
        CHECK (`max_weight_grams` IS NULL OR `max_weight_grams` > `min_weight_grams`),
    CONSTRAINT `chk_courier_rate_not_negative`
        CHECK (`base_charge` >= 0 AND `per_kg_charge` >= 0 AND `handling_charge` >= 0),
    CONSTRAINT `fk_courier_rate_cards_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- shipments
--
-- One row per parcel. An order normally has one, but a split consignment has
-- several, which is why this is not a set of columns on `orders`.
-- ---------------------------------------------------------------------------
CREATE TABLE `shipments` (
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                    CHAR(36)        NOT NULL,
    `shipment_number`         VARCHAR(30)     NOT NULL,
    `order_id`                BIGINT UNSIGNED NOT NULL,
    `courier_id`              BIGINT UNSIGNED NOT NULL,

    `awb_number`              VARCHAR(80)     NULL COMMENT 'Airway bill / tracking number',
    `courier_shipment_id`     VARCHAR(120)    NULL COMMENT 'Identifier in the courier system',
    `label_url`               VARCHAR(500)    NULL,
    `label_generated_date`    DATETIME        NULL,
    `manifest_id`             BIGINT UNSIGNED NULL,
    `pickup_request_id`       BIGINT UNSIGNED NULL,

    `status`                  ENUM('created','booked','label_generated','pickup_scheduled',
                                   'picked_up','in_transit','out_for_delivery','delivered',
                                   'failed_delivery','rto_initiated','rto_delivered',
                                   'cancelled','lost')
                              NOT NULL DEFAULT 'created',

    -- Weight and dimensions as declared to the courier. Snapshotted: a variant
    -- remeasured later must not change what was booked.
    `actual_weight_grams`     INT UNSIGNED    NOT NULL,
    `volumetric_weight_grams` INT UNSIGNED    NOT NULL DEFAULT 0,
    `chargeable_weight_grams` INT UNSIGNED    NOT NULL
                              COMMENT 'The greater of actual and volumetric; couriers bill on this',
    `length_mm`               SMALLINT UNSIGNED NULL,
    `width_mm`                SMALLINT UNSIGNED NULL,
    `height_mm`               SMALLINT UNSIGNED NULL,
    `used_default_dimensions` TINYINT(1)      NOT NULL DEFAULT 0
                              COMMENT 'True when a variant had no measurements and a default box was assumed',

    `declared_value`          DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `courier_charge`          DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                              COMMENT 'What we pay the courier, not what the customer paid',
    `customer_paid_delivery`  DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,

    `promised_sla_min_days`   TINYINT UNSIGNED NULL,
    `promised_sla_max_days`   TINYINT UNSIGNED NULL,
    `estimated_delivery_date` DATE            NULL,

    `pickup_scheduled_date`   DATETIME        NULL,
    `picked_up_date`          DATETIME        NULL,
    `delivered_date`          DATETIME        NULL,
    `delivery_attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_scan_date`          DATETIME        NULL,
    `last_scan_location`      VARCHAR(180)    NULL,
    `last_scan_status`        VARCHAR(120)    NULL,

    `rto_reason`              VARCHAR(255)    NULL,
    `failure_reason`          VARCHAR(255)    NULL,
    `booking_response`        JSON            NULL,

    `created_by`              BIGINT UNSIGNED NULL,
    `created_date`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`              BIGINT UNSIGNED NULL,
    `updated_date`            DATETIME        NULL,
    `deleted_by`              BIGINT UNSIGNED NULL,
    `deleted_date`            DATETIME        NULL,
    `is_active`               TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`              TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                 INT UNSIGNED    NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shipments_uuid` (`uuid`),
    UNIQUE KEY `uq_shipments_number` (`shipment_number`),
    -- An AWB is unique per courier, not globally: two couriers can legitimately
    -- issue the same digits.
    UNIQUE KEY `uq_shipments_courier_awb` (`courier_id`, `awb_number`),
    KEY `idx_shipments_order` (`order_id`),
    KEY `idx_shipments_status` (`status`, `created_date`),
    KEY `idx_shipments_awb` (`awb_number`),
    KEY `idx_shipments_manifest` (`manifest_id`),
    KEY `idx_shipments_pickup` (`pickup_request_id`),

    CONSTRAINT `chk_shipments_weights_positive`
        CHECK (`actual_weight_grams` > 0 AND `chargeable_weight_grams` > 0),
    -- Chargeable weight is by definition the greater of the two. A row that
    -- breaks this would silently undercharge or overcharge every reconciliation.
    CONSTRAINT `chk_shipments_chargeable_is_greater`
        CHECK (`chargeable_weight_grams` >= `actual_weight_grams`
           AND `chargeable_weight_grams` >= `volumetric_weight_grams`),

    CONSTRAINT `fk_shipments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_shipments_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- shipment_events  (BR-008)
--
-- Every scan the courier reports. Append-only in practice; nothing updates or
-- deletes these rows. The unique key on (shipment, courier event id) makes
-- webhook redelivery and polling overlap harmless.
-- ---------------------------------------------------------------------------
CREATE TABLE `shipment_events` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `shipment_id`           BIGINT UNSIGNED NOT NULL,
    `courier_event_id`      VARCHAR(160)    NULL
                            COMMENT 'Courier scan id, when they provide one',
    `event_code`            VARCHAR(60)     NULL,
    `status`                VARCHAR(60)     NOT NULL,
    `title`                 VARCHAR(180)    NOT NULL,
    `description`           VARCHAR(500)    NULL,
    `location`              VARCHAR(180)    NULL,
    `occurred_date`         DATETIME        NOT NULL,
    `is_customer_visible`   TINYINT(1)      NOT NULL DEFAULT 1,
    `source`                ENUM('webhook','poll','manual') NOT NULL DEFAULT 'webhook',
    `raw_payload`           JSON            NULL,
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
    UNIQUE KEY `uq_shipment_events_uuid` (`uuid`),
    UNIQUE KEY `uq_shipment_event_courier_id` (`shipment_id`, `courier_event_id`),
    KEY `idx_shipment_events_shipment` (`shipment_id`, `occurred_date`),
    CONSTRAINT `fk_shipment_events_shipment`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- courier_selections  (BR-007 audit trail)
--
-- Why this courier, and what else was considered.
--
-- Without this, an automatic choice is unarguable: operations cannot tell a
-- bad rate card from a bad algorithm, and a customer complaint about a slow
-- courier has no evidence behind it. The candidates are stored as JSON because
-- they are read as a whole, never queried field by field.
-- ---------------------------------------------------------------------------
CREATE TABLE `courier_selections` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `order_id`             BIGINT UNSIGNED NOT NULL,
    `shipment_id`          BIGINT UNSIGNED NULL,
    `selected_courier_id`  BIGINT UNSIGNED NULL,
    `strategy`             VARCHAR(40)     NOT NULL DEFAULT 'balanced',
    `destination_pincode`  VARCHAR(10)     NOT NULL,
    `chargeable_weight_grams` INT UNSIGNED NOT NULL,
    `order_value`          DECIMAL(12, 2)  NOT NULL,
    `candidates_considered` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `candidates_eligible`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `winning_score`        DECIMAL(6, 3)   NULL,
    `reason`               VARCHAR(500)    NOT NULL,
    `candidates`           JSON            NOT NULL
                           COMMENT 'Every courier considered, its score and why it won or lost',
    `was_manual_override`  TINYINT(1)      NOT NULL DEFAULT 0,
    `overridden_by`        BIGINT UNSIGNED NULL,
    `override_reason`      VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_courier_selections_uuid` (`uuid`),
    KEY `idx_courier_selections_order` (`order_id`),
    KEY `idx_courier_selections_courier` (`selected_courier_id`),
    CONSTRAINT `fk_courier_selections_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_courier_selections_courier`
        FOREIGN KEY (`selected_courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_courier_selections_shipment`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pickup_requests
-- ---------------------------------------------------------------------------
CREATE TABLE `pickup_requests` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `courier_id`            BIGINT UNSIGNED NOT NULL,
    `courier_reference`     VARCHAR(120)    NULL,
    `pickup_date`           DATE            NOT NULL,
    `pickup_slot`           VARCHAR(60)     NULL,
    `shipment_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_weight_grams`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`                ENUM('requested','confirmed','completed','cancelled','failed')
                            NOT NULL DEFAULT 'requested',
    `contact_name`          VARCHAR(120)    NULL,
    `contact_phone`         VARCHAR(20)     NULL,
    `pickup_address`        VARCHAR(500)    NULL,
    `failure_reason`        VARCHAR(255)    NULL,
    `courier_response`      JSON            NULL,
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
    UNIQUE KEY `uq_pickup_requests_uuid` (`uuid`),
    KEY `idx_pickup_requests_courier_date` (`courier_id`, `pickup_date`),
    KEY `idx_pickup_requests_status` (`status`),
    CONSTRAINT `fk_pickup_requests_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- manifests
--
-- The handover document a courier signs when collecting parcels. It is the
-- merchant's proof that a parcel left the building, which matters when a
-- courier later says they never received it.
-- ---------------------------------------------------------------------------
CREATE TABLE `manifests` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `manifest_number`    VARCHAR(30)     NOT NULL,
    `courier_id`         BIGINT UNSIGNED NOT NULL,
    `pickup_request_id`  BIGINT UNSIGNED NULL,
    `manifest_date`      DATE            NOT NULL,
    `shipment_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_weight_grams` INT UNSIGNED    NOT NULL DEFAULT 0,
    `document_url`       VARCHAR(500)    NULL,
    `courier_reference`  VARCHAR(120)    NULL,
    `status`             ENUM('open','closed','handed_over') NOT NULL DEFAULT 'open',
    `handed_over_date`   DATETIME        NULL,
    `handed_over_to`     VARCHAR(120)    NULL,
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
    UNIQUE KEY `uq_manifests_uuid` (`uuid`),
    UNIQUE KEY `uq_manifests_number` (`manifest_number`),
    KEY `idx_manifests_courier_date` (`courier_id`, `manifest_date`),
    CONSTRAINT `fk_manifests_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_manifests_pickup`
        FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Deferred foreign keys: shipments references manifests and pickup_requests,
-- both of which are created after it.
ALTER TABLE `shipments`
    ADD CONSTRAINT `fk_shipments_manifest`
        FOREIGN KEY (`manifest_id`) REFERENCES `manifests` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    ADD CONSTRAINT `fk_shipments_pickup`
        FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_shipment_summary` AS
SELECT
    s.`id`,
    s.`uuid`,
    s.`shipment_number`,
    s.`awb_number`,
    s.`status`,
    s.`chargeable_weight_grams`,
    s.`courier_charge`,
    s.`customer_paid_delivery`,
    s.`estimated_delivery_date`,
    s.`delivered_date`,
    s.`delivery_attempts`,
    s.`last_scan_status`,
    s.`last_scan_location`,
    s.`last_scan_date`,
    s.`created_date`,
    o.`order_number`,
    o.`user_id`,
    o.`ship_city`,
    o.`ship_state`,
    o.`ship_pincode`,
    o.`grand_total`  AS `order_value`,
    c.`code`         AS `courier_code`,
    c.`name`         AS `courier_name`,
    c.`tracking_url_template`
FROM `shipments` s
INNER JOIN `orders`   o ON o.`id` = s.`order_id`
INNER JOIN `couriers` c ON c.`id` = s.`courier_id`
WHERE s.`is_deleted` = 0;

-- Courier performance. Feeds the reliability score that BR-007 scoring uses,
-- so the selector improves from its own outcomes rather than a fixed guess.
CREATE OR REPLACE VIEW `vw_courier_performance` AS
SELECT
    c.`id`   AS `courier_id`,
    c.`code`,
    c.`name`,
    COUNT(s.`id`)                                                          AS `total_shipments`,
    SUM(s.`status` = 'delivered')                                          AS `delivered_count`,
    SUM(s.`status` IN ('rto_initiated','rto_delivered'))                   AS `rto_count`,
    SUM(s.`status` = 'lost')                                               AS `lost_count`,
    SUM(s.`delivery_attempts` > 1)                                         AS `multi_attempt_count`,
    ROUND(AVG(NULLIF(DATEDIFF(s.`delivered_date`, s.`picked_up_date`), NULL)), 2)
                                                                           AS `avg_transit_days`,
    SUM(CASE WHEN s.`status` = 'delivered'
              AND s.`estimated_delivery_date` IS NOT NULL
              AND DATE(s.`delivered_date`) <= s.`estimated_delivery_date`
             THEN 1 ELSE 0 END)                                            AS `on_time_count`,
    ROUND(SUM(s.`courier_charge`), 2)                                      AS `total_courier_cost`,
    ROUND(SUM(s.`customer_paid_delivery`), 2)                              AS `total_delivery_collected`
FROM `couriers` c
LEFT JOIN `shipments` s ON s.`courier_id` = c.`id` AND s.`is_deleted` = 0
WHERE c.`is_deleted` = 0
GROUP BY c.`id`, c.`code`, c.`name`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('006_delivery_couriers', 6, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  007_staff_commission_bulk.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 007 - Staff Operations, Commission and Bulk Orders
--
--  staff_profiles, order_assignments, packing_slips, commission_rules,
--  commission_entries, commission_settlements, bulk_order_enquiries,
--  bulk_order_quotes, bulk_order_quote_items.
--
--  Three decisions are baked into this schema:
--
--  COMMISSION IS EARNED AT DELIVERY, NOT AT PLACEMENT. An order that is
--  cancelled, refunded or returned to origin has cost the business money, and
--  paying a fulfilment commission on it would reward the wrong outcome. This
--  mirrors the referral rule from Phase 4, and for the same reason.
--
--  A COMMISSION AMOUNT IS NEVER EDITED. Status moves through a workflow
--  (pending, approved, settled) because that is process, not money — but the
--  amount is fixed at accrual and a correction is a new entry with the opposite
--  sign. Editing an accrued amount destroys the only record of what someone was
--  originally told they had earned.
--
--  AN APPROVED BULK QUOTE BECOMES AN ORDINARY ORDER. It does not get its own
--  payment path, its own dispatch path or its own exemptions. BR-003, BR-004,
--  BR-005 and BR-007 apply to a fifty-kilo wholesale order exactly as they apply
--  to a 200g pouch, because the moment a B2B flow gets a shortcut is the moment
--  unpaid goods start leaving the building.
--
--  MySQL 8.0.16+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- staff_profiles
--
-- The employment side of a staff user: who they report to, how much work they
-- can hold, whether they are on shift. Kept out of `users` because it applies
-- to three roles out of four and would be null for every customer — and the
-- customer table is the one that grows to six figures.
-- ---------------------------------------------------------------------------
CREATE TABLE `staff_profiles` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `user_id`               BIGINT UNSIGNED NOT NULL,
    `employee_code`         VARCHAR(30)     NOT NULL,
    `reports_to_user_id`    BIGINT UNSIGNED NULL
                            COMMENT 'Supervisor for an executive; administrator for a supervisor',
    `department`            VARCHAR(60)     NULL,
    `shift_start`           TIME            NULL,
    `shift_end`             TIME            NULL,
    -- Assignment capacity. An executive already holding their limit is skipped
    -- by auto-assignment: piling work onto whoever is nominally "next" is how
    -- orders sit untouched for a day.
    `max_concurrent_orders` SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    `is_available`          TINYINT(1)      NOT NULL DEFAULT 1,
    `unavailable_reason`    VARCHAR(255)    NULL,
    `unavailable_until`     DATETIME        NULL,
    `joined_date`           DATE            NULL,
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
    UNIQUE KEY `uq_staff_profiles_uuid` (`uuid`),
    UNIQUE KEY `uq_staff_profiles_user` (`user_id`),
    UNIQUE KEY `uq_staff_profiles_employee_code` (`employee_code`),
    KEY `idx_staff_profiles_reports_to` (`reports_to_user_id`),
    KEY `idx_staff_profiles_available` (`is_available`, `is_active`, `is_deleted`),
    CONSTRAINT `chk_staff_capacity_positive`
        CHECK (`max_concurrent_orders` > 0),
    CONSTRAINT `fk_staff_profiles_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_staff_profiles_manager`
        FOREIGN KEY (`reports_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_assignments
--
-- History, not a single current value: reassignment happens (illness, shift
-- end, escalation) and knowing an order changed hands twice before shipping is
-- exactly what a post-mortem needs.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_assignments` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `order_id`            BIGINT UNSIGNED NOT NULL,
    `assigned_to_user_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NULL
                          COMMENT 'NULL when assigned automatically',
    `status`              ENUM('assigned','accepted','completed','reassigned','released')
                          NOT NULL DEFAULT 'assigned',
    `assignment_method`   ENUM('auto','manual','self') NOT NULL DEFAULT 'auto',
    `assigned_date`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `accepted_date`       DATETIME        NULL,
    `completed_date`      DATETIME        NULL,
    `released_date`       DATETIME        NULL,
    `release_reason`      VARCHAR(255)    NULL,
    -- The clock a supervisor is actually managing against.
    `due_date`            DATETIME        NULL,
    `notes`               VARCHAR(500)    NULL,
    `created_by`          BIGINT UNSIGNED NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`          BIGINT UNSIGNED NULL,
    `updated_date`        DATETIME        NULL,
    `deleted_by`          BIGINT UNSIGNED NULL,
    `deleted_date`        DATETIME        NULL,
    `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`          TINYINT(1)      NOT NULL DEFAULT 0,
    `version`             INT UNSIGNED    NOT NULL DEFAULT 1,

    -- Exactly one live assignment per order, enforced by the database rather
    -- than by remembering to check. The generated column is NULL for finished
    -- assignments, and a unique index ignores NULLs, so history accumulates
    -- freely while only one row can ever be open.
    `active_order_id`     BIGINT UNSIGNED
                          GENERATED ALWAYS AS (
                              CASE WHEN `status` IN ('assigned','accepted') AND `is_deleted` = 0
                                   THEN `order_id` END
                          ) STORED,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_assignments_uuid` (`uuid`),
    UNIQUE KEY `uq_order_assignments_active` (`active_order_id`),
    KEY `idx_order_assignments_order` (`order_id`),
    KEY `idx_order_assignments_assignee` (`assigned_to_user_id`, `status`),
    KEY `idx_order_assignments_due` (`status`, `due_date`),
    -- RESTRICT, not CASCADE: `order_id` is the base of the STORED generated
    -- column above, and MySQL forbids a referential action that would modify
    -- it. Correct semantics anyway, since orders are soft-deleted.
    CONSTRAINT `fk_order_assignments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_order_assignments_assignee`
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_order_assignments_assigner`
        FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- packing_slips
--
-- Reprints are counted rather than blocked. A jammed printer is not fraud, but
-- a slip printed nine times is worth a supervisor noticing.
-- ---------------------------------------------------------------------------
CREATE TABLE `packing_slips` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `slip_number`       VARCHAR(30)     NOT NULL,
    `order_id`          BIGINT UNSIGNED NOT NULL,
    `generated_by`      BIGINT UNSIGNED NULL,
    `generated_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `print_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_printed_date` DATETIME        NULL,
    `item_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `unit_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_weight_grams` INT UNSIGNED   NOT NULL DEFAULT 0,
    `payload`           JSON            NOT NULL COMMENT 'Snapshot of what was picked',
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
    UNIQUE KEY `uq_packing_slips_uuid` (`uuid`),
    UNIQUE KEY `uq_packing_slips_number` (`slip_number`),
    KEY `idx_packing_slips_order` (`order_id`),
    CONSTRAINT `fk_packing_slips_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_packing_slips_generator`
        FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- commission_rules
-- ---------------------------------------------------------------------------
CREATE TABLE `commission_rules` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `code`                VARCHAR(40)     NOT NULL,
    `name`                VARCHAR(120)    NOT NULL,
    `description`         VARCHAR(500)    NULL,
    `scope`               ENUM('executive_fulfilment','supervisor_override','campaign')
                          NOT NULL DEFAULT 'executive_fulfilment',
    `calculation`         ENUM('flat_per_order','percentage_of_order','tiered_by_volume')
                          NOT NULL DEFAULT 'flat_per_order',
    `flat_amount`         DECIMAL(10, 2)  NULL,
    `percentage`          DECIMAL(5, 2)   NULL,
    `tiers`               JSON            NULL
                          COMMENT '[{"min_orders":0,"amount":10},{"min_orders":50,"amount":15}]',
    -- Caps keep a percentage rule from paying out absurdly on one large order.
    `min_order_value`     DECIMAL(12, 2)  NULL,
    `max_commission`      DECIMAL(10, 2)  NULL,
    `applies_to_role`     VARCHAR(30)     NULL COMMENT 'Role code, or NULL for all staff',
    `effective_from`      DATE            NULL,
    `effective_until`     DATE            NULL,
    `priority`            SMALLINT        NOT NULL DEFAULT 100
                          COMMENT 'Lowest wins when several rules match',
    `status`              ENUM('draft','active','paused','expired') NOT NULL DEFAULT 'draft',
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
    UNIQUE KEY `uq_commission_rules_uuid` (`uuid`),
    UNIQUE KEY `uq_commission_rules_code` (`code`),
    KEY `idx_commission_rules_lookup` (`status`, `scope`, `priority`),
    CONSTRAINT `chk_commission_percentage_range`
        CHECK (`percentage` IS NULL OR (`percentage` > 0 AND `percentage` <= 100)),
    CONSTRAINT `chk_commission_flat_positive`
        CHECK (`flat_amount` IS NULL OR `flat_amount` >= 0),
    -- A rule must carry the figure its own calculation needs. Without this a
    -- percentage rule with a null percentage accrues zero on every order and
    -- nobody notices until payday.
    CONSTRAINT `chk_commission_has_its_figure`
        CHECK (
            (`calculation` = 'flat_per_order'      AND `flat_amount` IS NOT NULL) OR
            (`calculation` = 'percentage_of_order' AND `percentage`  IS NOT NULL) OR
            (`calculation` = 'tiered_by_volume'    AND `tiers`       IS NOT NULL)
        )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- commission_settlements
--
-- A payout batch. Created before its entries are attached, so an entry can
-- point at the settlement that paid it.
-- ---------------------------------------------------------------------------
CREATE TABLE `commission_settlements` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `settlement_number` VARCHAR(30)     NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `period_start`      DATE            NOT NULL,
    `period_end`        DATE            NOT NULL,
    `entry_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `gross_amount`      DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `deductions`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `net_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `status`            ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
    `approved_by`       BIGINT UNSIGNED NULL,
    `approved_date`     DATETIME        NULL,
    `paid_date`         DATETIME        NULL,
    `payment_reference` VARCHAR(120)    NULL,
    `notes`             VARCHAR(500)    NULL,
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
    UNIQUE KEY `uq_commission_settlements_uuid` (`uuid`),
    UNIQUE KEY `uq_commission_settlements_number` (`settlement_number`),
    -- One settlement per person per period. Running the batch twice must not
    -- pay twice.
    UNIQUE KEY `uq_settlement_user_period` (`user_id`, `period_start`, `period_end`),
    KEY `idx_commission_settlements_status` (`status`, `period_end`),
    CONSTRAINT `chk_settlement_period_order`
        CHECK (`period_end` >= `period_start`),
    CONSTRAINT `chk_settlement_net_adds_up`
        CHECK (`net_amount` = `gross_amount` - `deductions`),
    CONSTRAINT `fk_commission_settlements_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_commission_settlements_approver`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- commission_entries
--
-- The accrual ledger. `amount` is written once and never updated — a correction
-- is a second row with the opposite sign, carrying `reverses_entry_id`. Status
-- moves through a workflow because that is process rather than money.
-- ---------------------------------------------------------------------------
CREATE TABLE `commission_entries` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `user_id`            BIGINT UNSIGNED NOT NULL,
    `order_id`           BIGINT UNSIGNED NULL,
    `rule_id`            BIGINT UNSIGNED NULL,
    `settlement_id`      BIGINT UNSIGNED NULL,
    `reverses_entry_id`  BIGINT UNSIGNED NULL
                         COMMENT 'Set on a correcting entry; the original stays untouched',
    `scope`              VARCHAR(40)     NOT NULL,
    `amount`             DECIMAL(10, 2)  NOT NULL
                         COMMENT 'Negative on a reversal',
    `order_value`        DECIMAL(12, 2)  NULL COMMENT 'Basis at the time of accrual',
    `calculation_note`   VARCHAR(255)    NOT NULL
                         COMMENT 'How this figure was reached, in words',
    `status`             ENUM('pending','approved','settled','cancelled')
                         NOT NULL DEFAULT 'pending',
    `accrued_date`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_by`        BIGINT UNSIGNED NULL,
    `approved_date`      DATETIME        NULL,
    -- One accrual per person per order per scope: replaying the delivery
    -- webhook must not pay twice.
    `idempotency_key`    VARCHAR(160)    NOT NULL,
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
    UNIQUE KEY `uq_commission_entries_uuid` (`uuid`),
    UNIQUE KEY `uq_commission_entries_idempotency` (`idempotency_key`),
    KEY `idx_commission_entries_user` (`user_id`, `status`),
    KEY `idx_commission_entries_order` (`order_id`),
    KEY `idx_commission_entries_settlement` (`settlement_id`),
    KEY `idx_commission_entries_unsettled` (`status`, `accrued_date`),
    CONSTRAINT `fk_commission_entries_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_commission_entries_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_commission_entries_rule`
        FOREIGN KEY (`rule_id`) REFERENCES `commission_rules` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_commission_entries_settlement`
        FOREIGN KEY (`settlement_id`) REFERENCES `commission_settlements` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_commission_entries_reversal`
        FOREIGN KEY (`reverses_entry_id`) REFERENCES `commission_entries` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The amount is immutable. Enforced by a trigger rather than convention,
-- because the whole value of an accrual ledger is that yesterday's figure still
-- reads the same today.
DELIMITER $$
CREATE TRIGGER `trg_commission_entries_amount_immutable`
BEFORE UPDATE ON `commission_entries`
FOR EACH ROW
BEGIN
    IF NEW.`amount` <> OLD.`amount` THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'commission_entries.amount is immutable: post a reversing entry instead';
    END IF;

    IF NEW.`order_id` <> OLD.`order_id` OR NEW.`user_id` <> OLD.`user_id` THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'A commission entry cannot be moved to another user or order';
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------------
-- bulk_order_enquiries
--
-- B2B starts as a conversation, not a cart: quantities are negotiated, prices
-- are not on the website, and a GSTIN has to be captured for the invoice.
-- ---------------------------------------------------------------------------
CREATE TABLE `bulk_order_enquiries` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `enquiry_number`        VARCHAR(30)     NOT NULL,
    `user_id`               BIGINT UNSIGNED NULL COMMENT 'NULL for a guest enquiry',
    `business_name`         VARCHAR(180)    NOT NULL,
    `gstin`                 VARCHAR(15)     NULL,
    `contact_name`          VARCHAR(120)    NOT NULL,
    `contact_mobile`        VARCHAR(15)     NOT NULL,
    `contact_email`         VARCHAR(180)    NULL,
    `delivery_pincode`      VARCHAR(10)     NULL,
    `delivery_city`         VARCHAR(90)     NULL,
    `delivery_state`        VARCHAR(90)     NULL,
    `requirements`          TEXT            NOT NULL,
    `expected_delivery_date` DATE           NULL,
    `estimated_quantity`    VARCHAR(120)    NULL,
    `estimated_budget`      DECIMAL(12, 2)  NULL,
    `status`                ENUM('new','under_review','quoted','negotiating',
                                 'accepted','converted','declined','expired')
                            NOT NULL DEFAULT 'new',
    `assigned_to_user_id`   BIGINT UNSIGNED NULL,
    `decline_reason`        VARCHAR(500)    NULL,
    `converted_order_id`    BIGINT UNSIGNED NULL,
    `source`                ENUM('web','phone','email','walk_in') NOT NULL DEFAULT 'web',
    `internal_notes`        TEXT            NULL,
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
    UNIQUE KEY `uq_bulk_enquiries_uuid` (`uuid`),
    UNIQUE KEY `uq_bulk_enquiries_number` (`enquiry_number`),
    KEY `idx_bulk_enquiries_status` (`status`, `created_date`),
    KEY `idx_bulk_enquiries_assignee` (`assigned_to_user_id`, `status`),
    KEY `idx_bulk_enquiries_user` (`user_id`),
    CONSTRAINT `fk_bulk_enquiries_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_bulk_enquiries_assignee`
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_bulk_enquiries_order`
        FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- bulk_order_quotes
--
-- Revisions are rows, not edits. A customer who accepts revision 2 must be held
-- to revision 2 even after revision 3 is drafted, and "what did we actually
-- offer them" is the first question in any pricing dispute.
-- ---------------------------------------------------------------------------
CREATE TABLE `bulk_order_quotes` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `quote_number`      VARCHAR(30)     NOT NULL,
    `enquiry_id`        BIGINT UNSIGNED NOT NULL,
    `revision`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `items_subtotal`    DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `discount_amount`   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `delivery_charge`   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `taxable_value`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `tax_total`         DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `grand_total`       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `valid_until`       DATE            NOT NULL,
    `payment_terms`     VARCHAR(255)    NULL DEFAULT '100% advance by UPI before dispatch',
    `delivery_terms`    VARCHAR(255)    NULL,
    `status`            ENUM('draft','sent','accepted','rejected','superseded','expired')
                        NOT NULL DEFAULT 'draft',
    `sent_date`         DATETIME        NULL,
    `responded_date`    DATETIME        NULL,
    `rejection_reason`  VARCHAR(500)    NULL,
    `prepared_by`       BIGINT UNSIGNED NULL,
    `notes`             VARCHAR(1000)   NULL,
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
    UNIQUE KEY `uq_bulk_quotes_uuid` (`uuid`),
    UNIQUE KEY `uq_bulk_quotes_number` (`quote_number`),
    UNIQUE KEY `uq_bulk_quote_revision` (`enquiry_id`, `revision`),
    KEY `idx_bulk_quotes_status` (`status`, `valid_until`),
    CONSTRAINT `chk_bulk_quote_totals_not_negative`
        CHECK (`grand_total` >= 0 AND `items_subtotal` >= 0 AND `discount_amount` >= 0),
    CONSTRAINT `chk_bulk_quote_tax_reconciles`
        CHECK (`taxable_value` + `tax_total` = `grand_total`),
    CONSTRAINT `fk_bulk_quotes_enquiry`
        FOREIGN KEY (`enquiry_id`) REFERENCES `bulk_order_enquiries` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_bulk_quotes_preparer`
        FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- bulk_order_quote_items
-- ---------------------------------------------------------------------------
CREATE TABLE `bulk_order_quote_items` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `quote_id`        BIGINT UNSIGNED NOT NULL,
    `variant_id`      BIGINT UNSIGNED NULL COMMENT 'NULL for a bespoke line',
    `description`     VARCHAR(255)    NOT NULL,
    `sku`             VARCHAR(60)     NULL,
    `quantity`        INT UNSIGNED    NOT NULL,
    `unit_price`      DECIMAL(10, 2)  NOT NULL COMMENT 'Negotiated, GST-inclusive',
    `line_total`      DECIMAL(12, 2)  NOT NULL,
    `gst_rate`        DECIMAL(5, 2)   NOT NULL DEFAULT 5.00,
    `unit_weight_grams` INT UNSIGNED  NOT NULL DEFAULT 0,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`      BIGINT UNSIGNED NULL,
    `updated_date`    DATETIME        NULL,
    `deleted_by`      BIGINT UNSIGNED NULL,
    `deleted_date`    DATETIME        NULL,
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`      TINYINT(1)      NOT NULL DEFAULT 0,
    `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bulk_quote_items_uuid` (`uuid`),
    KEY `idx_bulk_quote_items_quote` (`quote_id`),
    CONSTRAINT `chk_bulk_quote_item_quantity`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_bulk_quote_item_price`
        CHECK (`unit_price` >= 0),
    CONSTRAINT `fk_bulk_quote_items_quote`
        FOREIGN KEY (`quote_id`) REFERENCES `bulk_order_quotes` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_bulk_quote_items_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_executive_workload` AS
SELECT
    u.`id`   AS `user_id`,
    u.`uuid` AS `user_uuid`,
    u.`full_name`,
    sp.`employee_code`,
    sp.`max_concurrent_orders`,
    sp.`is_available`,
    sp.`reports_to_user_id`,
    COALESCE(SUM(oa.`status` IN ('assigned','accepted')), 0)      AS `open_assignments`,
    COALESCE(SUM(oa.`status` = 'completed'), 0)                   AS `completed_assignments`,
    COALESCE(SUM(oa.`status` = 'completed'
        AND oa.`completed_date` >= CURDATE()), 0)                 AS `completed_today`,
    GREATEST(0, sp.`max_concurrent_orders`
        - COALESCE(SUM(oa.`status` IN ('assigned','accepted')), 0)) AS `remaining_capacity`
FROM `users` u
INNER JOIN `staff_profiles` sp ON sp.`user_id` = u.`id` AND sp.`is_deleted` = 0
LEFT JOIN `order_assignments` oa ON oa.`assigned_to_user_id` = u.`id` AND oa.`is_deleted` = 0
WHERE u.`is_deleted` = 0
GROUP BY u.`id`, u.`uuid`, u.`full_name`, sp.`employee_code`,
         sp.`max_concurrent_orders`, sp.`is_available`, sp.`reports_to_user_id`;

CREATE OR REPLACE VIEW `vw_commission_summary` AS
SELECT
    u.`id`   AS `user_id`,
    u.`uuid` AS `user_uuid`,
    u.`full_name`,
    COUNT(ce.`id`)                                                       AS `entry_count`,
    ROUND(COALESCE(SUM(ce.`amount`), 0), 2)                              AS `total_accrued`,
    ROUND(COALESCE(SUM(CASE WHEN ce.`status` = 'pending'  THEN ce.`amount` ELSE 0 END), 0), 2) AS `pending_amount`,
    ROUND(COALESCE(SUM(CASE WHEN ce.`status` = 'approved' THEN ce.`amount` ELSE 0 END), 0), 2) AS `approved_amount`,
    ROUND(COALESCE(SUM(CASE WHEN ce.`status` = 'settled'  THEN ce.`amount` ELSE 0 END), 0), 2) AS `settled_amount`,
    MAX(ce.`accrued_date`)                                               AS `last_accrual_date`
FROM `users` u
INNER JOIN `commission_entries` ce ON ce.`user_id` = u.`id` AND ce.`is_deleted` = 0
WHERE u.`is_deleted` = 0
GROUP BY u.`id`, u.`uuid`, u.`full_name`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('007_staff_commission_bulk', 7, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  008_notifications_scheduler.sql
-- ==========================================================================
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

-- ==========================================================================
--  009_reviews_support_content.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 009 - Reviews, Support Tickets and Content
--
--  product_reviews, review_media, review_reports, review_votes,
--  support_tickets, support_ticket_messages, cms_pages, blog_posts,
--  faq_entries.
--
--  Three decisions worth stating up front.
--
--  A REVIEW REQUIRES A DELIVERED ORDER FOR THAT PRODUCT. Not an account, not a
--  purchase — a delivery. It is the single most effective defence against fake
--  reviews available to a small merchant, it costs nothing here because the
--  order data is already present, and it is the difference between a rating
--  that means something and one that means whoever cared most.
--
--  RATINGS ARE RECOMPUTED, NOT INCREMENTED. Keeping a running average by adding
--  each new star to a total is faster and drifts: one missed decrement on a
--  deleted review and the number is quietly wrong forever, with no way to tell.
--  Recomputing from the approved rows costs one indexed aggregate and is always
--  right.
--
--  SUPPORT MESSAGES ARE EITHER CUSTOMER-VISIBLE OR INTERNAL, NEVER BOTH. One
--  thread, one flag. Two separate tables would eventually be joined in the
--  wrong order and show a customer what a colleague said about them.
--
--  MySQL 8.0.16+
-- ============================================================================





-- ---------------------------------------------------------------------------
-- product_reviews
-- ---------------------------------------------------------------------------
CREATE TABLE `product_reviews` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_id`          BIGINT UNSIGNED NOT NULL,
    `variant_id`          BIGINT UNSIGNED NULL COMMENT 'Which pack size was bought',
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `order_id`            BIGINT UNSIGNED NULL
                          COMMENT 'The delivered order that entitles this review',
    `rating`              TINYINT UNSIGNED NOT NULL,
    `title`               VARCHAR(150)    NULL,
    `body`                TEXT            NULL,
    `is_verified_purchase` TINYINT(1)     NOT NULL DEFAULT 0,
    `status`              ENUM('pending','approved','rejected','hidden')
                          NOT NULL DEFAULT 'pending',
    `moderated_by`        BIGINT UNSIGNED NULL,
    `moderated_date`      DATETIME        NULL,
    `moderation_note`     VARCHAR(500)    NULL,
    `helpful_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `not_helpful_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `report_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `merchant_reply`      VARCHAR(1000)   NULL,
    `merchant_reply_date` DATETIME        NULL,
    `merchant_reply_by`   BIGINT UNSIGNED NULL,
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
    UNIQUE KEY `uq_product_reviews_uuid` (`uuid`),
    -- One review per customer per product. Editing replaces; a customer who
    -- changes their mind should not be able to stack five one-star reviews.
    UNIQUE KEY `uq_review_user_product` (`product_id`, `user_id`),
    KEY `idx_product_reviews_product` (`product_id`, `status`, `created_date`),
    KEY `idx_product_reviews_user` (`user_id`),
    KEY `idx_product_reviews_moderation` (`status`, `created_date`),
    KEY `idx_product_reviews_reported` (`report_count`, `status`),
    CONSTRAINT `chk_review_rating_range`
        CHECK (`rating` >= 1 AND `rating` <= 5),
    -- A review with neither a title nor a body is just a rating, which is
    -- allowed; but a title with no rating is not a review at all.
    CONSTRAINT `fk_product_reviews_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_product_reviews_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_product_reviews_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_product_reviews_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_product_reviews_moderator`
        FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- review_media
--
-- Photographs are moderated separately from the text. An innocuous five-star
-- review can carry an image that must never be published, and approving the
-- words should not approve the picture.
-- ---------------------------------------------------------------------------
CREATE TABLE `review_media` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `review_id`      BIGINT UNSIGNED NOT NULL,
    `media_type`     ENUM('image','video') NOT NULL DEFAULT 'image',
    `file_path`      VARCHAR(500)    NOT NULL,
    `thumbnail_path` VARCHAR(500)    NULL,
    `caption`        VARCHAR(255)    NULL,
    `display_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
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
    UNIQUE KEY `uq_review_media_uuid` (`uuid`),
    KEY `idx_review_media_review` (`review_id`, `status`),
    CONSTRAINT `fk_review_media_review`
        FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- review_reports
--
-- One report per person per review, so a review cannot be buried by one
-- determined complainant refreshing the page.
-- ---------------------------------------------------------------------------
CREATE TABLE `review_reports` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `review_id`     BIGINT UNSIGNED NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `reason`        ENUM('spam','offensive','irrelevant','fake','other') NOT NULL,
    `detail`        VARCHAR(500)    NULL,
    `status`        ENUM('open','upheld','dismissed') NOT NULL DEFAULT 'open',
    `reviewed_by`   BIGINT UNSIGNED NULL,
    `reviewed_date` DATETIME        NULL,
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
    UNIQUE KEY `uq_review_reports_uuid` (`uuid`),
    UNIQUE KEY `uq_review_report_user` (`review_id`, `user_id`),
    KEY `idx_review_reports_status` (`status`, `created_date`),
    CONSTRAINT `fk_review_reports_review`
        FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_review_reports_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- review_votes
-- ---------------------------------------------------------------------------
CREATE TABLE `review_votes` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`         CHAR(36)        NOT NULL,
    `review_id`    BIGINT UNSIGNED NOT NULL,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `is_helpful`   TINYINT(1)      NOT NULL,
    `created_by`   BIGINT UNSIGNED NULL,
    `created_date` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`   BIGINT UNSIGNED NULL,
    `updated_date` DATETIME        NULL,
    `deleted_by`   BIGINT UNSIGNED NULL,
    `deleted_date` DATETIME        NULL,
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`   TINYINT(1)      NOT NULL DEFAULT 0,
    `version`      INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_votes_uuid` (`uuid`),
    UNIQUE KEY `uq_review_vote_user` (`review_id`, `user_id`),
    CONSTRAINT `fk_review_votes_review`
        FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_review_votes_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- support_tickets
-- ---------------------------------------------------------------------------
CREATE TABLE `support_tickets` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `ticket_number`       VARCHAR(30)     NOT NULL,
    `user_id`             BIGINT UNSIGNED NULL COMMENT 'NULL for a guest enquiry',
    `order_id`            BIGINT UNSIGNED NULL,
    `category`            ENUM('order','delivery','payment','refund','product',
                               'account','wholesale','other')
                          NOT NULL DEFAULT 'other',
    `priority`            ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `subject`             VARCHAR(200)    NOT NULL,
    `contact_name`        VARCHAR(120)    NOT NULL,
    `contact_mobile`      VARCHAR(15)     NOT NULL,
    `contact_email`       VARCHAR(180)    NULL,
    `status`              ENUM('open','awaiting_customer','in_progress','resolved','closed')
                          NOT NULL DEFAULT 'open',
    `assigned_to_user_id` BIGINT UNSIGNED NULL,
    -- SLA timestamps. First response is the number customers actually judge
    -- support by; resolution is what the business judges it by.
    `first_response_due`  DATETIME        NULL,
    `first_response_date` DATETIME        NULL,
    `resolution_due`      DATETIME        NULL,
    `resolved_date`       DATETIME        NULL,
    `closed_date`         DATETIME        NULL,
    `resolution_note`     VARCHAR(1000)   NULL,
    `satisfaction_rating` TINYINT UNSIGNED NULL,
    `satisfaction_comment` VARCHAR(500)   NULL,
    `reopened_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_message_date`   DATETIME        NULL,
    `source`              ENUM('web','app','phone','email','whatsapp') NOT NULL DEFAULT 'web',
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
    UNIQUE KEY `uq_support_tickets_uuid` (`uuid`),
    UNIQUE KEY `uq_support_tickets_number` (`ticket_number`),
    KEY `idx_support_tickets_status` (`status`, `priority`, `created_date`),
    KEY `idx_support_tickets_user` (`user_id`, `created_date`),
    KEY `idx_support_tickets_assignee` (`assigned_to_user_id`, `status`),
    KEY `idx_support_tickets_sla` (`status`, `first_response_due`),
    KEY `idx_support_tickets_order` (`order_id`),
    CONSTRAINT `chk_ticket_satisfaction_range`
        CHECK (`satisfaction_rating` IS NULL
            OR (`satisfaction_rating` >= 1 AND `satisfaction_rating` <= 5)),
    CONSTRAINT `fk_support_tickets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_support_tickets_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_support_tickets_assignee`
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- support_ticket_messages
--
-- One thread with a visibility flag, not two tables. Separate customer and
-- internal tables would eventually be joined in the wrong order and show a
-- customer what a colleague said about them.
-- ---------------------------------------------------------------------------
CREATE TABLE `support_ticket_messages` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `ticket_id`           BIGINT UNSIGNED NOT NULL,
    `author_user_id`      BIGINT UNSIGNED NULL COMMENT 'NULL when written by the system',
    `author_type`         ENUM('customer','staff','system') NOT NULL,
    `body`                TEXT            NOT NULL,
    `is_internal_note`    TINYINT(1)      NOT NULL DEFAULT 0
                          COMMENT 'Never shown to the customer',
    `attachment_path`     VARCHAR(500)    NULL,
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
    UNIQUE KEY `uq_support_messages_uuid` (`uuid`),
    KEY `idx_support_messages_ticket` (`ticket_id`, `created_date`),
    -- A customer cannot author an internal note. Enforced rather than assumed,
    -- because the consequence of getting it wrong is a customer reading staff
    -- commentary about their own complaint.
    CONSTRAINT `chk_message_internal_is_staff`
        CHECK (`is_internal_note` = 0 OR `author_type` <> 'customer'),
    CONSTRAINT `fk_support_messages_ticket`
        FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_support_messages_author`
        FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- cms_pages
-- ---------------------------------------------------------------------------
CREATE TABLE `cms_pages` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `slug`             VARCHAR(160)    NOT NULL,
    `title`            VARCHAR(200)    NOT NULL,
    `body`             LONGTEXT        NOT NULL,
    `excerpt`          VARCHAR(500)    NULL,
    `meta_title`       VARCHAR(200)    NULL,
    `meta_description` VARCHAR(320)    NULL,
    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_date`   DATETIME        NULL,
    -- Legal pages must not be casually deleted: a returns policy has to be
    -- reproducible as it stood on the day of a disputed order.
    `is_system_page`   TINYINT(1)      NOT NULL DEFAULT 0,
    `display_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_cms_pages_uuid` (`uuid`),
    UNIQUE KEY `uq_cms_pages_slug` (`slug`),
    KEY `idx_cms_pages_status` (`status`, `display_order`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- blog_posts
-- ---------------------------------------------------------------------------
CREATE TABLE `blog_posts` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `slug`             VARCHAR(160)    NOT NULL,
    `title`            VARCHAR(200)    NOT NULL,
    `excerpt`          VARCHAR(500)    NULL,
    `body`             LONGTEXT        NOT NULL,
    `cover_image_path` VARCHAR(500)    NULL,
    `category`         VARCHAR(60)     NULL,
    `tags`             JSON            NULL,
    `author_user_id`   BIGINT UNSIGNED NULL,
    `author_name`      VARCHAR(120)    NULL COMMENT 'Snapshot; the author may leave',
    `meta_title`       VARCHAR(200)    NULL,
    `meta_description` VARCHAR(320)    NULL,
    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_date`   DATETIME        NULL,
    `view_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `reading_minutes`  TINYINT UNSIGNED NULL,
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
    UNIQUE KEY `uq_blog_posts_uuid` (`uuid`),
    UNIQUE KEY `uq_blog_posts_slug` (`slug`),
    KEY `idx_blog_posts_status` (`status`, `published_date`),
    KEY `idx_blog_posts_category` (`category`, `status`),
    FULLTEXT KEY `ft_blog_posts` (`title`, `excerpt`, `body`),
    CONSTRAINT `fk_blog_posts_author`
        FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- faq_entries
-- ---------------------------------------------------------------------------
CREATE TABLE `faq_entries` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `group_code`     VARCHAR(60)     NOT NULL DEFAULT 'general',
    `question`       VARCHAR(300)    NOT NULL,
    `answer`         TEXT            NOT NULL,
    `display_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `helpful_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`         ENUM('draft','published') NOT NULL DEFAULT 'published',
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
    UNIQUE KEY `uq_faq_entries_uuid` (`uuid`),
    KEY `idx_faq_entries_group` (`group_code`, `status`, `display_order`),
    FULLTEXT KEY `ft_faq_entries` (`question`, `answer`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_product_ratings` AS
SELECT
    p.`id`   AS `product_id`,
    p.`uuid` AS `product_uuid`,
    p.`name` AS `product_name`,
    COUNT(r.`id`)                                        AS `review_count`,
    ROUND(AVG(r.`rating`), 2)                            AS `rating_average`,
    SUM(r.`rating` = 5)                                  AS `five_star`,
    SUM(r.`rating` = 4)                                  AS `four_star`,
    SUM(r.`rating` = 3)                                  AS `three_star`,
    SUM(r.`rating` = 2)                                  AS `two_star`,
    SUM(r.`rating` = 1)                                  AS `one_star`,
    SUM(r.`is_verified_purchase` = 1)                    AS `verified_count`
FROM `products` p
INNER JOIN `product_reviews` r
        ON r.`product_id` = p.`id`
       AND r.`status` = 'approved'
       AND r.`is_deleted` = 0
WHERE p.`is_deleted` = 0
GROUP BY p.`id`, p.`uuid`, p.`name`;

CREATE OR REPLACE VIEW `vw_support_performance` AS
SELECT
    DATE(t.`created_date`)                               AS `ticket_date`,
    t.`category`,
    COUNT(*)                                             AS `tickets`,
    SUM(t.`status` IN ('resolved','closed'))             AS `resolved`,
    SUM(t.`first_response_date` IS NULL
        AND t.`first_response_due` < NOW()
        AND t.`status` NOT IN ('resolved','closed'))     AS `first_response_breached`,
    ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.`created_date`, t.`first_response_date`)), 1)
                                                         AS `avg_first_response_minutes`,
    ROUND(AVG(TIMESTAMPDIFF(HOUR, t.`created_date`, t.`resolved_date`)), 1)
                                                         AS `avg_resolution_hours`,
    ROUND(AVG(t.`satisfaction_rating`), 2)               AS `avg_satisfaction`
FROM `support_tickets` t
WHERE t.`is_deleted` = 0
GROUP BY DATE(t.`created_date`), t.`category`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('009_reviews_support_content', 9, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  010_bogo_offers.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 010 - Buy X Get Y offers
--
--  Adds "buy 2 get 1 free", "buy 1 get 1", "buy 3 get 5" and similar to the
--  existing offer engine.
--
--  THE FREE ITEM IS EXPRESSED AS A DISCOUNT, NOT AS AN EXTRA LINE.
--
--  The obvious implementation adds a zero-priced line to the order. It is also
--  wrong for this platform, for three reasons:
--
--    1. GST. Indian MRP is tax-inclusive and tax is EXTRACTED from the price. A
--       zero-priced line has no taxable value, so the tax on the units actually
--       paid for would be computed against the wrong base. Expressing the
--       benefit as an order discount reuses the apportionment that already
--       handles coupons correctly, and the tax stays right by construction.
--
--    2. Refunds and cancellations. A free line has no money attached, so a
--       partial refund would have to decide what a zero-priced item is worth.
--       A discount is simply reversed with everything else.
--
--    3. The pricing engine already knows how to apply and apportion a discount.
--       A new line type would need handling in the cart, the order, the invoice,
--       the courier weight calculation and the packing slip.
--
--  The customer still sees "1 free" — that wording comes from the offer title
--  and the disclosed benefit, not from the line structure.
--
--  MySQL 8.0.16+
-- ============================================================================





-- `bogo` joins the existing offer types.
ALTER TABLE `offers`
    MODIFY COLUMN `offer_type`
        ENUM('festival','flash_sale','deal_of_day','category','combo','free_shipping','bogo')
        NOT NULL DEFAULT 'festival';

-- `free_items` joins the existing discount types. It carries no percentage and
-- no flat amount; the benefit is worked out from the quantities below.
ALTER TABLE `offers`
    MODIFY COLUMN `discount_type`
        ENUM('none','percentage','flat','free_delivery','free_items')
        NOT NULL DEFAULT 'none';

ALTER TABLE `offers`
    ADD COLUMN `buy_quantity` SMALLINT UNSIGNED NULL
        COMMENT 'How many must be bought to earn the benefit'
        AFTER `discount_value`,
    ADD COLUMN `get_quantity` SMALLINT UNSIGNED NULL
        COMMENT 'How many are then free'
        AFTER `buy_quantity`,
    -- Which units are free when a customer qualifies more than once, or has
    -- several different eligible products in the basket.
    --
    -- `cheapest` is the honest default: a shop that gives away the most
    -- expensive item on a mixed basket loses money it did not intend to, and
    -- every large retailer discounts the cheapest for exactly this reason. It
    -- is also what a customer expects once they think about it.
    ADD COLUMN `free_item_scope` ENUM('same_variant','cheapest_eligible') NULL
        COMMENT 'same_variant: free units come from the same pack the customer bought'
        AFTER `get_quantity`,
    -- A ceiling on how many times one basket can claim the offer. Without it,
    -- "buy 1 get 1" on a fifty-unit order gives away twenty-five units.
    ADD COLUMN `max_free_items_per_order` SMALLINT UNSIGNED NULL
        COMMENT 'Total free units one order may earn; NULL means unlimited'
        AFTER `free_item_scope`;

-- A buy-X-get-Y offer is meaningless without both quantities, and a positive
-- buy quantity is what stops "buy 0 get 1" giving the shop away.
ALTER TABLE `offers`
    ADD CONSTRAINT `chk_offers_bogo_quantities`
        CHECK (
            `discount_type` <> 'free_items'
            OR (`buy_quantity` IS NOT NULL AND `buy_quantity` >= 1
                AND `get_quantity` IS NOT NULL AND `get_quantity` >= 1)
        );

-- A percentage or flat offer must not carry quantities, or an editor could
-- leave them behind when changing type and produce an offer that reads one way
-- and behaves another.
ALTER TABLE `offers`
    ADD CONSTRAINT `chk_offers_quantities_only_for_free_items`
        CHECK (
            `discount_type` = 'free_items'
            OR (`buy_quantity` IS NULL AND `get_quantity` IS NULL)
        );

CREATE OR REPLACE VIEW `vw_offer_performance` AS
SELECT
    o.`id`,
    o.`uuid`,
    o.`code`,
    o.`title`,
    o.`offer_type`,
    o.`discount_type`,
    o.`buy_quantity`,
    o.`get_quantity`,
    o.`status`,
    o.`starts_date`,
    o.`ends_date`,
    COUNT(ord.`id`)                                   AS `orders_using`,
    COALESCE(SUM(ord.`order_discount`), 0)            AS `discount_given`,
    COALESCE(SUM(ord.`grand_total`), 0)               AS `revenue_influenced`
FROM `offers` o
LEFT JOIN `orders` ord
       ON ord.`offer_code` = o.`code`
      AND ord.`status` <> 'cancelled'
      AND ord.`is_deleted` = 0
WHERE o.`is_deleted` = 0
GROUP BY o.`id`, o.`uuid`, o.`code`, o.`title`, o.`offer_type`, o.`discount_type`,
         o.`buy_quantity`, o.`get_quantity`, o.`status`, o.`starts_date`, o.`ends_date`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('010_bogo_offers', 10, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  011_collections.sql
-- ==========================================================================
-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 011 - Campaign collections
--
--  A shop owner needs to say "here are the twelve things I am pushing this
--  Diwali", give it a page, and point an advert at it.
--
--  WHY THIS IS NOT `cms_pages`.
--
--  cms_pages holds prose: shipping policy, terms, an about page. Its content is
--  a body of text, and its risk is legal. A campaign page holds PRODUCTS — its
--  content is a curated list with an order, and its risk is commercial (a link
--  to something unpublished, a page still live in January). Putting both in one
--  table would mean every policy page carrying null product columns and every
--  campaign page carrying a body nobody reads, and one careless delete taking
--  out the returns policy.
--
--  WHY NOT A PAGE BUILDER.
--
--  A general builder — drag blocks, choose columns, set padding — is a large
--  feature that produces pages nobody can be held to. This is deliberately
--  narrower: pick a TEMPLATE, pick PRODUCTS, write a headline. The templates are
--  designed once and stay consistent with the rest of the shop, so a campaign
--  page cannot end up looking like a different website.
--
--  MySQL 8.0.16+
-- ============================================================================





CREATE TABLE IF NOT EXISTS `collections` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36) NOT NULL,

    `slug`            VARCHAR(160) NOT NULL
                      COMMENT 'The address: /collection.html?slug=diwali-2026',
    `title`           VARCHAR(180) NOT NULL,
    `subtitle`        VARCHAR(320) NULL,
    `intro`           TEXT NULL COMMENT 'A short paragraph above the products',

    -- The templates are fixed. Each is designed once and shares the shop's own
    -- styling, so a campaign page cannot drift into looking like a different
    -- site — which is what happens when merchants get free rein over layout.
    -- grid      plain product grid
    -- spotlight one hero product, then a grid
    -- story     intro text, products, closing text
    -- gift      gift-box framing for hampers and festival sets
    `template`        ENUM('grid','spotlight','story','gift') NOT NULL DEFAULT 'grid',

    `hero_image_path` VARCHAR(255) NULL,
    `hero_alt_text`   VARCHAR(180) NULL,
    `cta_label`       VARCHAR(60) NULL,

    `status`          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',

    -- A campaign has a season. Without dates, the Diwali page is still live in
    -- January and nobody notices until a customer mentions it.
    `starts_date`     DATETIME NULL,
    `ends_date`       DATETIME NULL,

    `display_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `view_count`      INT UNSIGNED NOT NULL DEFAULT 0,

    `meta_title`       VARCHAR(180) NULL,
    `meta_description` VARCHAR(320) NULL,

    `created_by`   BIGINT UNSIGNED NULL,
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`   BIGINT UNSIGNED NULL,
    `updated_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_by`   BIGINT UNSIGNED NULL,
    `deleted_date` DATETIME NULL,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `is_deleted`   TINYINT(1) NOT NULL DEFAULT 0,
    `version`      INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_collections_uuid` (`uuid`),
    UNIQUE KEY `uq_collections_slug` (`slug`),
    KEY `idx_collections_live` (`status`, `starts_date`, `ends_date`, `is_deleted`),
    KEY `idx_collections_order` (`display_order`),

    CONSTRAINT `chk_collections_dates`
        CHECK (`ends_date` IS NULL OR `starts_date` IS NULL OR `ends_date` > `starts_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collection_items` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `collection_id` BIGINT UNSIGNED NOT NULL,
    `product_id`    BIGINT UNSIGNED NOT NULL,

    -- Lets the merchant say "Our pick for gifting" above a product without
    -- changing the product itself, which is on other pages too.
    `headline`      VARCHAR(120) NULL,
    `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,

    `created_by`   BIGINT UNSIGNED NULL,
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`   BIGINT UNSIGNED NULL,
    `updated_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_by`   BIGINT UNSIGNED NULL,
    `deleted_date` DATETIME NULL,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `is_deleted`   TINYINT(1) NOT NULL DEFAULT 0,
    `version`      INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_collection_items_uuid` (`uuid`),

    -- The same product twice on one page is always a mistake, and it is much
    -- cheaper to refuse it here than to explain it later.
    UNIQUE KEY `uq_collection_product` (`collection_id`, `product_id`),
    KEY `idx_collection_items_order` (`collection_id`, `display_order`),

    CONSTRAINT `fk_collection_items_collection`
        FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_collection_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An advert can now point at a campaign page, which is the whole reason a shop
-- owner builds one.
ALTER TABLE `banners`
    MODIFY COLUMN `link_type`
        ENUM('none','category','product','url','offer','collection') NOT NULL DEFAULT 'none';

CREATE OR REPLACE VIEW `vw_collection_summary` AS
SELECT
    c.`id`,
    c.`uuid`,
    c.`slug`,
    c.`title`,
    c.`template`,
    c.`status`,
    c.`subtitle`,
    c.`display_order`,
    c.`starts_date`,
    c.`ends_date`,
    c.`view_count`,
    COUNT(ci.`id`) AS `item_count`,
    SUM(CASE WHEN p.`status` = 'published' THEN 1 ELSE 0 END) AS `purchasable_count`
FROM `collections` c
LEFT JOIN `collection_items` ci
       ON ci.`collection_id` = c.`id` AND ci.`is_deleted` = 0
LEFT JOIN `products` p
       ON p.`id` = ci.`product_id` AND p.`is_deleted` = 0
WHERE c.`is_deleted` = 0
GROUP BY c.`id`, c.`uuid`, c.`slug`, c.`title`, c.`template`, c.`status`,
         c.`subtitle`, c.`display_order`, c.`starts_date`, c.`ends_date`, c.`view_count`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('011_collections', 11, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;

-- ==========================================================================
--  001_roles_permissions_settings.sql
-- ==========================================================================
-- ============================================================================
--  Seed 001 - Roles, permission matrix and default settings
--  Idempotent: safe to re-run after adding new permissions.
--  No user accounts are created here. Use `php bin/seed_admin.php` so the
--  administrator password is hashed at runtime and never committed to source.
-- ============================================================================



-- --------------------------------------------------------------------------
-- Roles (SRS section 4)
-- --------------------------------------------------------------------------
INSERT INTO `roles` (`uuid`, `code`, `name`, `description`, `is_system`, `hierarchy`)
VALUES
    (UUID(), 'administrator', 'Administrator', 'Full system administration', 1, 10),
    (UUID(), 'supervisor',    'Supervisor',    'Manages executives, assigns orders, approves bulk orders', 1, 20),
    (UUID(), 'executive',     'Executive',     'Verifies orders, packing, labels, customer calls', 1, 30),
    (UUID(), 'customer',      'Customer',      'Retail and wholesale buyer', 1, 100)
ON DUPLICATE KEY UPDATE
    `name`        = VALUES(`name`),
    `description` = VALUES(`description`),
    `hierarchy`   = VALUES(`hierarchy`),
    `version`     = `version` + 1;

-- --------------------------------------------------------------------------
-- Permissions. Codes follow module.action and are referenced by name in code,
-- never by id.
-- --------------------------------------------------------------------------
INSERT INTO `permissions` (`uuid`, `code`, `module`, `action`, `name`)
VALUES
    (UUID(), 'users.view',        'users',    'view',    'View users'),
    (UUID(), 'users.create',      'users',    'create',  'Create users'),
    (UUID(), 'users.update',      'users',    'update',  'Update users'),
    (UUID(), 'users.delete',      'users',    'delete',  'Deactivate users'),
    (UUID(), 'users.impersonate', 'users',    'impersonate', 'Impersonate a user for support'),
    (UUID(), 'roles.manage',      'roles',    'manage',  'Manage roles and permissions'),
    (UUID(), 'settings.view',     'settings', 'view',    'View system settings'),
    (UUID(), 'settings.update',   'settings', 'update',  'Update system settings'),
    (UUID(), 'audit.view',        'audit',    'view',    'View audit and activity logs'),
    (UUID(), 'reports.view',      'reports',  'view',    'View reports'),
    (UUID(), 'dashboard.admin',   'dashboard','admin',   'Access administrator dashboard'),
    (UUID(), 'dashboard.supervisor','dashboard','supervisor','Access supervisor dashboard'),
    (UUID(), 'dashboard.executive','dashboard','executive','Access executive dashboard')
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `version` = `version` + 1;

-- --------------------------------------------------------------------------
-- Matrix: administrator gets everything.
-- --------------------------------------------------------------------------
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'administrator'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

-- Supervisor
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'users.view', 'users.create', 'users.update',
    'reports.view', 'dashboard.supervisor', 'audit.view'
)
WHERE r.`code` = 'supervisor'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

-- Executive
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN ('users.view', 'dashboard.executive')
WHERE r.`code` = 'executive'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

-- --------------------------------------------------------------------------
-- Default settings
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'general', 'store_name',              'Spice & Dry Fruits', 'string', 'Public store name', 1),
    (UUID(), 'general', 'support_mobile',          '',                   'string', 'Customer care number', 1),
    (UUID(), 'general', 'support_email',           '',                   'string', 'Customer care email', 1),
    (UUID(), 'general', 'currency_code',           'INR',                'string', 'ISO currency code', 1),
    (UUID(), 'order',   'min_order_value',         '199',                'decimal','Minimum order value in INR', 1),
    (UUID(), 'order',   'free_delivery_threshold', '999',                'decimal','Order value above which delivery is free', 1),
    (UUID(), 'order',   'require_otp_on_order',    '1',                  'bool',   'BR-003: OTP verification before order confirmation', 0),
    (UUID(), 'order',   'prepaid_only',            '1',                  'bool',   'BR-004: only prepaid UPI orders accepted', 1),
    (UUID(), 'auth',    'otp_ttl_seconds',         '300',                'int',    'OTP validity window', 0),
    (UUID(), 'auth',    'login_max_attempts',      '5',                  'int',    'Failed logins before lockout', 0),
    (UUID(), 'referral','referral_reward_amount',  '50',                 'decimal','Wallet credit per successful referral', 1),
    (UUID(), 'referral','referral_enabled',        '1',                  'bool',   'Referral programme master switch', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;

-- ==========================================================================
--  002_catalog_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 002 - Catalog taxonomy and demonstration products
--
--  Categories are the seven from SRS Module 3, plus subcategories.
--  Idempotent: matched on slug / product_code / sku, so re-running updates
--  rather than duplicating.
--
--  The three sample products exist so the catalog API returns something
--  meaningful on a fresh install and so the smoke test has data to filter,
--  sort and search against. Delete them before going live:
--    DELETE FROM products WHERE product_code LIKE 'DEMO-%';
-- ============================================================================



-- --------------------------------------------------------------------------
-- Top-level categories (SRS Module 3)
-- --------------------------------------------------------------------------
INSERT INTO `categories` (`uuid`, `parent_id`, `slug`, `name`, `description`, `display_order`, `is_featured`)
VALUES
    (UUID(), NULL, 'spices',           'Spices',           'Whole and ground spices sourced directly from growers', 10, 1),
    (UUID(), NULL, 'dry-fruits',       'Dry Fruits',       'Premium nuts and dried fruit',                         20, 1),
    (UUID(), NULL, 'herbs',            'Herbs',            'Culinary and wellness herbs',                          30, 0),
    (UUID(), NULL, 'seeds',            'Seeds',            'Edible and sprouting seeds',                           40, 0),
    (UUID(), NULL, 'organic-products', 'Organic Products', 'Certified organic range',                              50, 1),
    (UUID(), NULL, 'combo-packs',      'Combo Packs',      'Curated multi-product value packs',                    60, 1),
    (UUID(), NULL, 'gift-packs',       'Gift Packs',       'Festive and corporate gifting',                        70, 1)
ON DUPLICATE KEY UPDATE
    `name`          = VALUES(`name`),
    `description`   = VALUES(`description`),
    `display_order` = VALUES(`display_order`),
    `is_featured`   = VALUES(`is_featured`),
    `version`       = `categories`.`version` + 1;

-- --------------------------------------------------------------------------
-- Subcategories. parent_id is resolved by slug so ids are never hard-coded.
-- --------------------------------------------------------------------------
INSERT INTO `categories` (`uuid`, `parent_id`, `slug`, `name`, `display_order`)
SELECT UUID(), p.`id`, s.`slug`, s.`name`, s.`display_order`
FROM (
    SELECT 'spices'     AS `parent_slug`, 'whole-spices'     AS `slug`, 'Whole Spices'      AS `name`, 10 AS `display_order`
    UNION ALL SELECT 'spices',     'ground-spices',    'Ground Spices',     20
    UNION ALL SELECT 'spices',     'spice-blends',     'Spice Blends',      30
    UNION ALL SELECT 'dry-fruits', 'almonds',          'Almonds',           10
    UNION ALL SELECT 'dry-fruits', 'cashews',          'Cashews',           20
    UNION ALL SELECT 'dry-fruits', 'raisins-dates',    'Raisins & Dates',   30
    UNION ALL SELECT 'dry-fruits', 'pistachios',       'Pistachios',        40
    UNION ALL SELECT 'seeds',      'pumpkin-seeds',    'Pumpkin Seeds',     10
    UNION ALL SELECT 'seeds',      'chia-flax',        'Chia & Flax',       20
) s
JOIN `categories` p ON p.`slug` = s.`parent_slug`
ON DUPLICATE KEY UPDATE
    `name`          = VALUES(`name`),
    `display_order` = VALUES(`display_order`),
    `version`       = `categories`.`version` + 1;

-- --------------------------------------------------------------------------
-- Demonstration products
-- --------------------------------------------------------------------------
INSERT INTO `products` (
    `uuid`, `category_id`, `product_code`, `slug`, `name`, `brand`,
    `short_description`, `description`, `ingredients`, `storage_instructions`,
    `shelf_life_days`, `origin_country`, `origin_region`, `hsn_code`, `gst_rate`,
    `is_organic`, `status`, `published_date`, `is_featured`, `display_order`,
    `search_keywords`
)
SELECT
    UUID(), c.`id`, v.`product_code`, v.`slug`, v.`name`, v.`brand`,
    v.`short_description`, v.`description`, v.`ingredients`, v.`storage_instructions`,
    v.`shelf_life_days`, 'India', v.`origin_region`, v.`hsn_code`, v.`gst_rate`,
    v.`is_organic`, 'published', NOW(), v.`is_featured`, v.`display_order`,
    v.`search_keywords`
FROM (
    SELECT
        'ground-spices' AS `category_slug`,
        'DEMO-TURMERIC' AS `product_code`,
        'organic-turmeric-powder' AS `slug`,
        'Organic Turmeric Powder' AS `name`,
        'Spice & Dry Fruits' AS `brand`,
        'Single-origin Erode turmeric, stone-ground, 3.5% curcumin' AS `short_description`,
        'Sun-dried Erode turmeric fingers, stone-ground in small batches to protect the volatile oils. Deep ochre colour, earthy aroma, no added colour or starch.' AS `description`,
        '100% turmeric (Curcuma longa)' AS `ingredients`,
        'Store in a cool, dry place away from direct sunlight. Keep the pouch sealed.' AS `storage_instructions`,
        540 AS `shelf_life_days`,
        'Erode, Tamil Nadu' AS `origin_region`,
        '09103020' AS `hsn_code`,
        5.00 AS `gst_rate`,
        1 AS `is_organic`,
        1 AS `is_featured`,
        10 AS `display_order`,
        'haldi, manjal, arishina, curcumin, halad, yellow spice' AS `search_keywords`
    UNION ALL SELECT
        'almonds', 'DEMO-ALMOND', 'california-almonds',
        'California Almonds', 'Spice & Dry Fruits',
        'Hand-sorted premium California almonds, crisp and unsalted',
        'Non-pareil grade California almonds, hand-sorted for uniform size and screened for shell fragments. Raw and unsalted.',
        '100% almonds (Prunus dulcis)',
        'Refrigerate after opening to preserve crispness.',
        365, 'Imported, packed in Karnataka', '08021200', 12.00, 0, 1, 20,
        'badam, badaam, nuts, almond, akhrot alternative'
    UNION ALL SELECT
        'whole-spices', 'DEMO-CARDAMOM', 'green-cardamom-8mm',
        'Green Cardamom 8mm', 'Spice & Dry Fruits',
        'Bold 8mm Idukki cardamom pods, intensely aromatic',
        'Grade AGEB 8mm green cardamom from the Idukki hills. Plump, tightly closed pods with a high volatile-oil content.',
        '100% green cardamom (Elettaria cardamomum)',
        'Keep in an airtight container; do not refrigerate.',
        450, 'Idukki, Kerala', '09083110', 5.00, 0, 1, 30,
        'elaichi, elachi, yelakkai, cardamom, hari elaichi'
) v
JOIN `categories` c ON c.`slug` = v.`category_slug`
ON DUPLICATE KEY UPDATE
    `name`              = VALUES(`name`),
    `short_description` = VALUES(`short_description`),
    `description`       = VALUES(`description`),
    `status`            = VALUES(`status`),
    `search_keywords`   = VALUES(`search_keywords`),
    `version`           = `products`.`version` + 1;

-- --------------------------------------------------------------------------
-- Variants (pack sizes). Weight is mandatory: BR-006 and BR-007 depend on it.
-- --------------------------------------------------------------------------
INSERT INTO `product_variants` (
    `uuid`, `product_id`, `sku`, `variant_name`, `weight_grams`,
    `packed_weight_grams`, `pack_type`, `mrp`, `selling_price`, `offer_price`,
    `offer_end_date`, `is_default`, `display_order`
)
SELECT
    UUID(), p.`id`, v.`sku`, v.`variant_name`, v.`weight_grams`,
    v.`packed_weight_grams`, v.`pack_type`, v.`mrp`, v.`selling_price`, v.`offer_price`,
    CASE WHEN v.`offer_price` IS NOT NULL THEN DATE_ADD(NOW(), INTERVAL 30 DAY) ELSE NULL END,
    v.`is_default`, v.`display_order`
FROM (
    SELECT 'DEMO-TURMERIC' AS `product_code`, 'DEMO-TURMERIC-100' AS `sku`, '100 g pouch' AS `variant_name`,
           100 AS `weight_grams`, 130 AS `packed_weight_grams`, 'pouch' AS `pack_type`,
           149.00 AS `mrp`, 129.00 AS `selling_price`, NULL AS `offer_price`, 0 AS `is_default`, 10 AS `display_order`
    UNION ALL SELECT 'DEMO-TURMERIC', 'DEMO-TURMERIC-250', '250 g pouch', 250, 295, 'pouch', 349.00, 299.00, 269.00, 1, 20
    UNION ALL SELECT 'DEMO-TURMERIC', 'DEMO-TURMERIC-500', '500 g pouch', 500, 560, 'pouch', 649.00, 559.00, NULL, 0, 30
    UNION ALL SELECT 'DEMO-ALMOND',   'DEMO-ALMOND-250',   '250 g pouch', 250, 290, 'pouch', 499.00, 449.00, NULL, 1, 10
    UNION ALL SELECT 'DEMO-ALMOND',   'DEMO-ALMOND-500',   '500 g pouch', 500, 555, 'pouch', 949.00, 849.00, 799.00, 0, 20
    UNION ALL SELECT 'DEMO-ALMOND',   'DEMO-ALMOND-1000',  '1 kg jar',   1000, 1120, 'jar',  1799.00, 1599.00, NULL, 0, 30
    UNION ALL SELECT 'DEMO-CARDAMOM', 'DEMO-CARDAMOM-050', '50 g jar',     50,  85, 'jar',   399.00, 359.00, NULL, 1, 10
    UNION ALL SELECT 'DEMO-CARDAMOM', 'DEMO-CARDAMOM-100', '100 g jar',   100, 140, 'jar',   749.00, 679.00, 629.00, 0, 20
) v
JOIN `products` p ON p.`product_code` = v.`product_code`
ON DUPLICATE KEY UPDATE
    `variant_name`  = VALUES(`variant_name`),
    `mrp`           = VALUES(`mrp`),
    `selling_price` = VALUES(`selling_price`),
    `offer_price`   = VALUES(`offer_price`),
    `is_default`    = VALUES(`is_default`),
    `version`       = `product_variants`.`version` + 1;

-- --------------------------------------------------------------------------
-- Nutrition (per 100 g, as printed on the label)
-- --------------------------------------------------------------------------
INSERT INTO `product_nutrition` (
    `uuid`, `product_id`, `serving_size_g`, `energy_kcal`, `protein_g`,
    `total_fat_g`, `carbohydrate_g`, `dietary_fibre_g`, `sodium_mg`, `iron_mg`, `allergen_info`
)
SELECT UUID(), p.`id`, 100, v.`energy_kcal`, v.`protein_g`, v.`total_fat_g`,
       v.`carbohydrate_g`, v.`dietary_fibre_g`, v.`sodium_mg`, v.`iron_mg`, v.`allergen_info`
FROM (
    SELECT 'DEMO-TURMERIC' AS `product_code`, 354.00 AS `energy_kcal`, 7.80 AS `protein_g`,
           9.90 AS `total_fat_g`, 64.90 AS `carbohydrate_g`, 21.10 AS `dietary_fibre_g`,
           38.00 AS `sodium_mg`, 41.40 AS `iron_mg`,
           'Packed in a facility that also handles tree nuts.' AS `allergen_info`
    UNION ALL SELECT 'DEMO-ALMOND', 579.00, 21.20, 49.90, 21.60, 12.50, 1.00, 3.70,
           'Contains tree nuts (almonds).'
    UNION ALL SELECT 'DEMO-CARDAMOM', 311.00, 10.80, 6.70, 68.50, 28.00, 18.00, 14.00,
           'Packed in a facility that also handles tree nuts.'
) v
JOIN `products` p ON p.`product_code` = v.`product_code`
ON DUPLICATE KEY UPDATE
    `energy_kcal` = VALUES(`energy_kcal`),
    `protein_g`   = VALUES(`protein_g`),
    `version`     = `product_nutrition`.`version` + 1;

-- --------------------------------------------------------------------------
-- Extra specifications
-- --------------------------------------------------------------------------
INSERT INTO `product_attributes` (`uuid`, `product_id`, `attribute_name`, `attribute_value`, `display_order`)
SELECT UUID(), p.`id`, v.`attribute_name`, v.`attribute_value`, v.`display_order`
FROM (
    SELECT 'DEMO-TURMERIC' AS `product_code`, 'Curcumin content' AS `attribute_name`, '3.5%' AS `attribute_value`, 10 AS `display_order`
    UNION ALL SELECT 'DEMO-TURMERIC', 'Grind',        'Stone-ground, fine', 20
    UNION ALL SELECT 'DEMO-ALMOND',   'Grade',        'Non-pareil',         10
    UNION ALL SELECT 'DEMO-ALMOND',   'Count per kg', '450 - 500',          20
    UNION ALL SELECT 'DEMO-CARDAMOM', 'Grade',        'AGEB 8mm',           10
) v
JOIN `products` p ON p.`product_code` = v.`product_code`
ON DUPLICATE KEY UPDATE
    `attribute_value` = VALUES(`attribute_value`),
    `version`         = `product_attributes`.`version` + 1;

-- ==========================================================================
--  003_delivery_pricing_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 003 - Delivery zones, pincode map and charge slabs
--
--  Illustrative rates for an operation shipping out of Bengaluru. Replace the
--  charge amounts with your negotiated courier rates before going live; the
--  structure is what matters, not these numbers.
--
--  Idempotent: matched on zone code and (zone, min_weight_grams).
-- ============================================================================



-- --------------------------------------------------------------------------
-- Zones, ordered from cheapest to most expensive to serve.
-- --------------------------------------------------------------------------
INSERT INTO `delivery_zones` (`uuid`, `code`, `name`, `sla_min_days`, `sla_max_days`, `is_default`, `is_serviceable`)
VALUES
    (UUID(), 'LOCAL',  'Bengaluru local',            1, 2, 0, 1),
    (UUID(), 'KA',     'Rest of Karnataka',          2, 4, 0, 1),
    (UUID(), 'SOUTH',  'South India',                3, 5, 0, 1),
    (UUID(), 'REST',   'Rest of India',              4, 7, 1, 1),
    (UUID(), 'REMOTE', 'North-east, J&K and islands', 6, 12, 0, 1)
ON DUPLICATE KEY UPDATE
    `name`           = VALUES(`name`),
    `sla_min_days`   = VALUES(`sla_min_days`),
    `sla_max_days`   = VALUES(`sla_max_days`),
    `is_default`     = VALUES(`is_default`),
    `is_serviceable` = VALUES(`is_serviceable`),
    `version`        = `delivery_zones`.`version` + 1;

-- --------------------------------------------------------------------------
-- Pincode prefixes. Longest match wins, so a specific pincode can override a
-- broad range without touching the rest of the map.
-- --------------------------------------------------------------------------
INSERT INTO `delivery_pincode_map` (`uuid`, `zone_id`, `pincode_prefix`, `label`)
SELECT UUID(), z.`id`, m.`pincode_prefix`, m.`label`
FROM (
    SELECT 'LOCAL'  AS `zone_code`, '560' AS `pincode_prefix`, 'Bengaluru urban'   AS `label`
    UNION ALL SELECT 'LOCAL',  '561', 'Bengaluru rural'
    UNION ALL SELECT 'KA',     '56',  'Karnataka (56x)'
    UNION ALL SELECT 'KA',     '57',  'Karnataka (57x)'
    UNION ALL SELECT 'KA',     '58',  'Karnataka (58x)'
    UNION ALL SELECT 'KA',     '59',  'Karnataka (59x)'
    UNION ALL SELECT 'SOUTH',  '5',   'Andhra, Telangana, Karnataka belt'
    UNION ALL SELECT 'SOUTH',  '6',   'Tamil Nadu, Kerala'
    UNION ALL SELECT 'REST',   '1',   'Delhi, Haryana, Punjab, HP'
    UNION ALL SELECT 'REST',   '2',   'Uttar Pradesh, Uttarakhand'
    UNION ALL SELECT 'REST',   '3',   'Rajasthan, Gujarat'
    UNION ALL SELECT 'REST',   '4',   'Maharashtra, MP, Chhattisgarh, Goa'
    UNION ALL SELECT 'REST',   '7',   'West Bengal, Odisha, Bihar'
    UNION ALL SELECT 'REST',   '8',   'Bihar, Jharkhand'
    UNION ALL SELECT 'REMOTE', '19',  'Jammu & Kashmir, Ladakh'
    UNION ALL SELECT 'REMOTE', '78',  'Assam and north-east'
    UNION ALL SELECT 'REMOTE', '79',  'Arunachal, Nagaland, Manipur'
    UNION ALL SELECT 'REMOTE', '744', 'Andaman & Nicobar'
    UNION ALL SELECT 'REMOTE', '682', 'Lakshadweep routing'
) m
JOIN `delivery_zones` z ON z.`code` = m.`zone_code`
ON DUPLICATE KEY UPDATE
    `zone_id` = VALUES(`zone_id`),
    `label`   = VALUES(`label`),
    `version` = `delivery_pincode_map`.`version` + 1;

-- --------------------------------------------------------------------------
-- Weight bands. Every zone has an open-ended top band with a per-kg rate, so
-- there is no order weight the platform cannot quote.
-- --------------------------------------------------------------------------
INSERT INTO `delivery_charge_slabs` (
    `uuid`, `zone_id`, `min_weight_grams`, `max_weight_grams`,
    `charge_amount`, `per_extra_kg_amount`, `free_above_order_value`
)
SELECT UUID(), z.`id`, s.`min_weight_grams`, s.`max_weight_grams`,
       s.`charge_amount`, s.`per_extra_kg_amount`, s.`free_above_order_value`
FROM (
    -- LOCAL
    SELECT 'LOCAL' AS `zone_code`, 0 AS `min_weight_grams`, 500 AS `max_weight_grams`,
            29.00 AS `charge_amount`, 0.00 AS `per_extra_kg_amount`, 499.00 AS `free_above_order_value`
    UNION ALL SELECT 'LOCAL',  500,  1000,  39.00,  0.00, 499.00
    UNION ALL SELECT 'LOCAL', 1000,  2000,  59.00,  0.00, 499.00
    UNION ALL SELECT 'LOCAL', 2000,  NULL,  79.00, 20.00, 499.00
    -- KA
    UNION ALL SELECT 'KA',       0,   500,  45.00,  0.00, 799.00
    UNION ALL SELECT 'KA',     500,  1000,  65.00,  0.00, 799.00
    UNION ALL SELECT 'KA',    1000,  2000,  89.00,  0.00, 799.00
    UNION ALL SELECT 'KA',    2000,  NULL, 119.00, 30.00, 799.00
    -- SOUTH
    UNION ALL SELECT 'SOUTH',    0,   500,  59.00,  0.00, 999.00
    UNION ALL SELECT 'SOUTH',  500,  1000,  85.00,  0.00, 999.00
    UNION ALL SELECT 'SOUTH', 1000,  2000, 119.00,  0.00, 999.00
    UNION ALL SELECT 'SOUTH', 2000,  NULL, 159.00, 40.00, 999.00
    -- REST
    UNION ALL SELECT 'REST',     0,   500,  75.00,  0.00, 999.00
    UNION ALL SELECT 'REST',   500,  1000, 109.00,  0.00, 999.00
    UNION ALL SELECT 'REST',  1000,  2000, 149.00,  0.00, 999.00
    UNION ALL SELECT 'REST',  2000,  NULL, 199.00, 55.00, 999.00
    -- REMOTE: no free-shipping threshold, freight is genuinely expensive
    UNION ALL SELECT 'REMOTE',   0,   500, 129.00,  0.00, NULL
    UNION ALL SELECT 'REMOTE', 500,  1000, 179.00,  0.00, NULL
    UNION ALL SELECT 'REMOTE',1000,  2000, 249.00,  0.00, NULL
    UNION ALL SELECT 'REMOTE',2000,  NULL, 329.00, 90.00, NULL
) s
JOIN `delivery_zones` z ON z.`code` = s.`zone_code`
ON DUPLICATE KEY UPDATE
    `max_weight_grams`       = VALUES(`max_weight_grams`),
    `charge_amount`          = VALUES(`charge_amount`),
    `per_extra_kg_amount`    = VALUES(`per_extra_kg_amount`),
    `free_above_order_value` = VALUES(`free_above_order_value`),
    `version`                = `delivery_charge_slabs`.`version` + 1;

-- --------------------------------------------------------------------------
-- Commerce settings used by the pricing engine.
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'cart',  'cart_max_line_items',   '50',   'int',    'Distinct pack sizes allowed in one cart', 0),
    (UUID(), 'cart',  'cart_abandon_after_days', '30', 'int',    'Idle days before an active cart is marked abandoned', 0),
    (UUID(), 'order', 'delivery_gst_rate',     '18',   'decimal','GST rate applied to the delivery charge', 0),
    (UUID(), 'order', 'prices_include_gst',    '1',    'bool',   'Indian MRP convention: displayed prices are GST-inclusive', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

-- ==========================================================================
--  004_promotions_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 004 - Sample coupons, offers and promotion settings
--
--  The coupons and offers below are illustrative. Review the discount values
--  against your actual margins before activating any of them; two are left in
--  `draft` deliberately so nothing goes live by accident on a fresh install.
--
--  Idempotent: matched on coupon code and offer code.
-- ============================================================================



-- --------------------------------------------------------------------------
-- Promotion settings
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'referral', 'referral_referrer_reward',   '50',  'decimal', 'Wallet credit to the referrer once the referee orders', 1),
    (UUID(), 'referral', 'referral_referee_reward',    '50',  'decimal', 'Wallet credit to the new customer on their first order', 1),
    (UUID(), 'referral', 'referral_min_order_value',   '299', 'decimal', 'Minimum first-order value that qualifies a referral', 1),
    (UUID(), 'referral', 'referral_reward_expiry_days','180', 'int',     'Days before referral wallet credit expires', 1),
    (UUID(), 'wallet',   'wallet_max_redeem_percent',  '20',  'decimal', 'Cap on wallet credit per order, as a percent of order value', 1),
    (UUID(), 'wallet',   'wallet_min_redeem_amount',   '10',  'decimal', 'Smallest wallet redemption allowed', 1),
    (UUID(), 'wallet',   'wallet_enabled',             '1',   'bool',    'Wallet redemption master switch', 1),
    (UUID(), 'coupon',   'coupon_stacking_allowed',    '0',   'bool',    'One coupon per order; see PromotionResolver', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;

-- --------------------------------------------------------------------------
-- Coupons
-- --------------------------------------------------------------------------
INSERT INTO `coupons` (
    `uuid`, `code`, `title`, `description`, `terms`, `discount_type`, `discount_value`,
    `max_discount_amount`, `min_order_value`, `applies_to`, `audience`,
    `valid_from`, `valid_to`, `total_usage_limit`, `per_customer_limit`,
    `stackable_with_offer`, `status`
)
VALUES
    (UUID(), 'WELCOME10', 'Welcome offer: 10% off',
     '10% off your first order, up to ₹150.',
     'Valid on your first order only. Maximum discount ₹150. Minimum order value ₹299. Cannot be combined with another coupon.',
     'percentage', 10.00, 150.00, 299.00, 'all', 'new_customers',
     NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), NULL, 1, 1, 'active'),

    (UUID(), 'SPICE50', 'Flat ₹50 off spices',
     'Flat ₹50 off any order containing spices.',
     'Applies to the Spices category only. Minimum order value ₹499. One use per customer.',
     'flat', 50.00, NULL, 499.00, 'categories', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), 1000, 1, 0, 'active'),

    (UUID(), 'FREESHIP', 'Free delivery',
     'Free delivery on orders above ₹399.',
     'Delivery charges waived. Minimum order value ₹399. Valid twice per customer.',
     'free_delivery', 0.00, NULL, 399.00, 'all', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY), NULL, 2, 1, 'active'),

    (UUID(), 'BULK15', 'Bulk buyer: 15% off',
     '15% off orders above ₹2,499, capped at ₹500.',
     'Minimum order value ₹2,499. Maximum discount ₹500. Review margins before activating.',
     'percentage', 15.00, 500.00, 2499.00, 'all', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 200, 1, 0, 'draft'),

    (UUID(), 'DRYFRUIT100', 'Flat ₹100 off dry fruits',
     'Flat ₹100 off dry fruit orders above ₹1,499.',
     'Applies to the Dry Fruits category only. Minimum order value ₹1,499.',
     'flat', 100.00, NULL, 1499.00, 'categories', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 45 DAY), 500, 1, 0, 'draft')
ON DUPLICATE KEY UPDATE
    `title`               = VALUES(`title`),
    `description`         = VALUES(`description`),
    `terms`               = VALUES(`terms`),
    `discount_type`       = VALUES(`discount_type`),
    `discount_value`      = VALUES(`discount_value`),
    `max_discount_amount` = VALUES(`max_discount_amount`),
    `min_order_value`     = VALUES(`min_order_value`),
    `version`             = `coupons`.`version` + 1;

-- Category scoping for the two category coupons.
INSERT INTO `coupon_targets` (`uuid`, `coupon_id`, `target_type`, `category_id`)
SELECT UUID(), c.`id`, 'category', cat.`id`
FROM `coupons` c
JOIN `categories` cat ON cat.`slug` = 'spices'
WHERE c.`code` = 'SPICE50'
  AND NOT EXISTS (
      SELECT 1 FROM `coupon_targets` t
       WHERE t.`coupon_id` = c.`id` AND t.`category_id` = cat.`id` AND t.`is_deleted` = 0
  );

INSERT INTO `coupon_targets` (`uuid`, `coupon_id`, `target_type`, `category_id`)
SELECT UUID(), c.`id`, 'category', cat.`id`
FROM `coupons` c
JOIN `categories` cat ON cat.`slug` = 'dry-fruits'
WHERE c.`code` = 'DRYFRUIT100'
  AND NOT EXISTS (
      SELECT 1 FROM `coupon_targets` t
       WHERE t.`coupon_id` = c.`id` AND t.`category_id` = cat.`id` AND t.`is_deleted` = 0
  );

-- --------------------------------------------------------------------------
-- Offers (merchandising campaigns, optionally auto-discounting)
-- --------------------------------------------------------------------------
INSERT INTO `offers` (
    `uuid`, `code`, `title`, `subtitle`, `description`, `offer_type`,
    `discount_type`, `discount_value`, `max_discount_amount`, `min_order_value`,
    `applies_to`, `stackable_with_coupon`, `priority`,
    `starts_date`, `ends_date`, `display_order`, `is_featured`, `status`
)
VALUES
    (UUID(), 'TODAYSDEALS', 'Today''s Deals', 'Fresh picks, sharper prices',
     'A rotating selection of products at their best price of the week.',
     'deal_of_day', 'none', 0.00, NULL, NULL, 'all', 1, 100,
     NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 10, 1, 'active'),

    (UUID(), 'DRYFRUITWEEK', 'Dry Fruit Week', '5% off all dry fruits',
     'An automatic 5% off everything in the Dry Fruits category this week.',
     'category', 'percentage', 5.00, 200.00, 599.00, 'categories', 1, 50,
     NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 20, 1, 'active'),

    (UUID(), 'FESTIVESHIP', 'Festive free shipping', 'No delivery charges above ₹699',
     'Delivery charges waived automatically on festive orders above ₹699.',
     'free_shipping', 'free_delivery', 0.00, NULL, 699.00, 'all', 0, 40,
     NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), 30, 0, 'active')
ON DUPLICATE KEY UPDATE
    `title`                 = VALUES(`title`),
    `subtitle`              = VALUES(`subtitle`),
    `description`           = VALUES(`description`),
    `discount_type`         = VALUES(`discount_type`),
    `discount_value`        = VALUES(`discount_value`),
    `max_discount_amount`   = VALUES(`max_discount_amount`),
    `min_order_value`       = VALUES(`min_order_value`),
    `stackable_with_coupon` = VALUES(`stackable_with_coupon`),
    `version`               = `offers`.`version` + 1;

INSERT INTO `offer_targets` (`uuid`, `offer_id`, `target_type`, `category_id`)
SELECT UUID(), o.`id`, 'category', cat.`id`
FROM `offers` o
JOIN `categories` cat ON cat.`slug` = 'dry-fruits'
WHERE o.`code` = 'DRYFRUITWEEK'
  AND NOT EXISTS (
      SELECT 1 FROM `offer_targets` t
       WHERE t.`offer_id` = o.`id` AND t.`category_id` = cat.`id` AND t.`is_deleted` = 0
  );

-- ==========================================================================
--  005_orders_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 005 - Checkout, payment and order settings
--
--  No sample orders. Orders are created through the API so that every one of
--  them passes the same validation, OTP and payment path as a real customer's;
--  a hand-inserted order would bypass the rules this phase exists to enforce.
-- ============================================================================



INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'order',   'order_number_prefix',        'SDF',   'string',  'Prefix for customer-facing order numbers', 1),
    (UUID(), 'order',   'invoice_number_prefix',      'INV',   'string',  'Prefix for GST invoice numbers', 0),
    (UUID(), 'order',   'order_otp_required',         '1',     'bool',    'BR-003: OTP verification before an order is confirmed', 0),
    (UUID(), 'order',   'order_payment_window_minutes','30',   'int',     'Unpaid orders expire after this and release coupon and wallet credit', 1),
    (UUID(), 'order',   'order_cancellable_until',    'packed','string',  'Last status at which a customer may still cancel', 1),
    (UUID(), 'order',   'seller_state',               'Karnataka','string','Seller state; decides CGST+SGST versus IGST', 0),
    (UUID(), 'order',   'seller_gstin',               '',      'string',  'Seller GSTIN, printed on every invoice', 0),
    (UUID(), 'order',   'seller_legal_name',          'Spice & Dry Fruits', 'string', 'Legal entity name on the invoice', 0),
    (UUID(), 'payment', 'payment_gateway',            'sandbox','string', 'Active gateway: razorpay or sandbox', 0),
    (UUID(), 'payment', 'payment_currency',           'INR',   'string',  'Only INR is supported for UPI', 1),
    (UUID(), 'payment', 'payment_methods',            'upi',   'string',  'BR-004: prepaid UPI only', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;

-- Numbering counters for the current Indian financial year (April to March).
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('order:', fy.`year`), 'order', 'SDF', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('invoice:', fy.`year`), 'invoice', 'INV', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

-- ==========================================================================
--  006_couriers_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 006 - Couriers, serviceability and rate cards
--
--  Rates here are plausible Indian market figures for a small merchant, not
--  quotes. They must be replaced with the merchant's negotiated contract before
--  go-live, or every cost comparison BR-007 makes is comparing fiction.
--
--  The sandbox courier exists so the delivery flow can be exercised without a
--  courier account. Its adapter refuses to run outside local/testing.
-- ============================================================================



INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'delivery', 'courier_driver',              'sandbox', 'string', 'Active courier adapter: shiprocket or sandbox', 0),
    (UUID(), 'delivery', 'courier_selection_strategy',  'balanced','string', 'BR-007 weighting: cheapest, fastest, balanced or reliable', 0),
    (UUID(), 'delivery', 'courier_auto_assign',         '1',       'bool',   'Assign a courier automatically once an order is packed', 0),
    (UUID(), 'delivery', 'default_box_length_mm',       '250',     'int',    'Assumed when a variant has no measured dimensions', 0),
    (UUID(), 'delivery', 'default_box_width_mm',        '200',     'int',    'Assumed when a variant has no measured dimensions', 0),
    (UUID(), 'delivery', 'default_box_height_mm',       '120',     'int',    'Assumed when a variant has no measured dimensions', 0),
    (UUID(), 'delivery', 'packaging_weight_grams',      '80',      'int',    'Carton, filler and tape added to every parcel', 0),
    (UUID(), 'delivery', 'pickup_contact_name',         'Dispatch Desk', 'string', 'Contact given to the courier for pickup', 0),
    (UUID(), 'delivery', 'pickup_contact_phone',        '',        'string', 'Contact number given to the courier', 0),
    (UUID(), 'delivery', 'pickup_address',              '',        'string', 'Warehouse pickup address', 0),
    (UUID(), 'delivery', 'pickup_pincode',              '560001',  'string', 'Origin pincode; decides zone for rate lookup', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Couriers
--
-- SANDBOX is deliberately priciest and slowest so it never wins a scoring test
-- by accident and mask a selection bug.
-- ---------------------------------------------------------------------------
INSERT INTO `couriers`
    (`uuid`, `code`, `name`, `adapter`, `channel_code`, `tracking_url_template`,
     `min_weight_grams`, `max_weight_grams`, `max_order_value`, `handles_fragile`,
     `priority`, `reliability_score`, `volumetric_divisor`, `support_phone`, `is_enabled`)
VALUES
    (UUID(), 'DELHIVERY',  'Delhivery',   'shiprocket', '10',
     'https://www.delhivery.com/track/package/{awb}',      50, 30000, 50000.00, 1,  10, 88.00, 5000, '1800-000-000', 1),
    (UUID(), 'BLUEDART',   'Blue Dart',   'shiprocket', '20',
     'https://www.bluedart.com/tracking/{awb}',            50, 25000, 100000.00, 1, 20, 92.00, 5000, '1860-233-1234', 1),
    (UUID(), 'XPRESSBEES', 'XpressBees',  'shiprocket', '30',
     'https://www.xpressbees.com/track?awb={awb}',         50, 20000, 30000.00, 1,  30, 84.00, 5000, NULL, 1),
    (UUID(), 'DTDC',       'DTDC',        'shiprocket', '40',
     'https://www.dtdc.in/tracking/{awb}',                 50, 25000, 25000.00, 0,  40, 79.00, 5000, NULL, 1),
    (UUID(), 'SHADOWFAX',  'Shadowfax',   'shiprocket', '50',
     'https://shadowfax.in/track/{awb}',                   50, 10000, 15000.00, 0,  50, 81.00, 4000, NULL, 1),
    (UUID(), 'SANDBOX',    'Sandbox Courier', 'sandbox', NULL,
     'https://example.test/track/{awb}',                    1, 50000, NULL, 1,      99, 75.00, 5000, NULL, 1)
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `version` = `couriers`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Serviceability, by pincode prefix (longest match wins)
--
--   5 = Karnataka and the south, 4 = Maharashtra/Gujarat, 1 = Delhi and north,
--   7 = the north-east, 6 = Kerala/Tamil Nadu.
-- ---------------------------------------------------------------------------
INSERT INTO `courier_serviceability` (`uuid`, `courier_id`, `pincode_prefix`, `is_serviceable`, `sla_min_days`, `sla_max_days`, `is_express`, `notes`)
SELECT UUID(), c.`id`, v.`prefix`, v.`serviceable`, v.`sla_min`, v.`sla_max`, v.`express`, v.`notes`
FROM `couriers` c
JOIN (
    SELECT 'DELHIVERY' AS code, '5'  AS prefix, 1 AS serviceable, 1 AS sla_min, 3 AS sla_max, 0 AS express, NULL AS notes UNION ALL
    SELECT 'DELHIVERY', '4', 1, 2, 4, 0, NULL UNION ALL
    SELECT 'DELHIVERY', '1', 1, 3, 5, 0, NULL UNION ALL
    SELECT 'DELHIVERY', '6', 1, 2, 4, 0, NULL UNION ALL
    SELECT 'DELHIVERY', '7', 1, 5, 9, 0, 'North-east, longer transit' UNION ALL

    SELECT 'BLUEDART', '5', 1, 1, 2, 1, 'Express network' UNION ALL
    SELECT 'BLUEDART', '4', 1, 1, 3, 1, NULL UNION ALL
    SELECT 'BLUEDART', '1', 1, 2, 3, 1, NULL UNION ALL
    SELECT 'BLUEDART', '6', 1, 2, 3, 0, NULL UNION ALL
    SELECT 'BLUEDART', '79', 0, 5, 9, 0, 'Not serviced' UNION ALL

    SELECT 'XPRESSBEES', '5', 1, 2, 4, 0, NULL UNION ALL
    SELECT 'XPRESSBEES', '4', 1, 2, 5, 0, NULL UNION ALL
    SELECT 'XPRESSBEES', '1', 1, 3, 6, 0, NULL UNION ALL
    SELECT 'XPRESSBEES', '6', 1, 3, 5, 0, NULL UNION ALL

    SELECT 'DTDC', '5', 1, 2, 5, 0, NULL UNION ALL
    SELECT 'DTDC', '4', 1, 3, 6, 0, NULL UNION ALL
    SELECT 'DTDC', '6', 1, 3, 6, 0, NULL UNION ALL

    SELECT 'SHADOWFAX', '560', 1, 1, 1, 1, 'Same-city only' UNION ALL
    SELECT 'SHADOWFAX', '5600', 1, 1, 1, 1, 'Bengaluru metro' UNION ALL

    SELECT 'SANDBOX', '', 1, 3, 7, 0, 'Serves everywhere, for testing'
) v ON v.`code` = c.`code`
ON DUPLICATE KEY UPDATE
    `sla_min_days` = VALUES(`sla_min_days`),
    `sla_max_days` = VALUES(`sla_max_days`),
    `version`      = `courier_serviceability`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Rate cards, by zone and weight slab
-- ---------------------------------------------------------------------------
INSERT INTO `courier_rate_cards`
    (`uuid`, `courier_id`, `zone_code`, `min_weight_grams`, `max_weight_grams`,
     `base_charge`, `per_kg_charge`, `fuel_surcharge_pct`, `handling_charge`)
SELECT UUID(), c.`id`, v.`zone`, v.`min_w`, v.`max_w`, v.`base`, v.`per_kg`, v.`fuel`, v.`handling`
FROM `couriers` c
JOIN (
    -- Delhivery: cheapest at volume, middling speed
    SELECT 'DELHIVERY' AS code, 'LOCAL' AS zone, 0 AS min_w, 500 AS max_w, 32.00 AS base, 0.00 AS per_kg, 12.00 AS fuel, 5.00 AS handling UNION ALL
    SELECT 'DELHIVERY', 'LOCAL',  500, 2000,  45.00, 18.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'LOCAL', 2000, NULL,  70.00, 16.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'KA',       0,  500,  38.00,  0.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'KA',     500, 2000,  55.00, 22.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'KA',    2000, NULL,  85.00, 20.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'SOUTH',    0,  500,  46.00,  0.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'SOUTH',  500, 2000,  68.00, 28.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'SOUTH', 2000, NULL, 105.00, 26.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REST',     0,  500,  58.00,  0.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REST',   500, 2000,  88.00, 36.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REST',  2000, NULL, 140.00, 34.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REMOTE',   0,  500,  85.00,  0.00, 12.00, 10.00 UNION ALL
    SELECT 'DELHIVERY', 'REMOTE', 500, NULL, 130.00, 55.00, 12.00, 10.00 UNION ALL

    -- Blue Dart: fastest, dearest
    SELECT 'BLUEDART', 'LOCAL',     0,  500,  52.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'LOCAL',   500, 2000,  74.00, 28.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'LOCAL',  2000, NULL, 112.00, 26.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'KA',        0,  500,  60.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'KA',      500, 2000,  88.00, 34.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'KA',     2000, NULL, 132.00, 32.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'SOUTH',     0,  500,  72.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'SOUTH',   500, 2000, 104.00, 42.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'SOUTH',  2000, NULL, 158.00, 40.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'REST',      0,  500,  88.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'REST',    500, 2000, 128.00, 52.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'REST',   2000, NULL, 195.00, 50.00, 15.00, 8.00 UNION ALL

    -- XpressBees: cheap, slower
    SELECT 'XPRESSBEES', 'LOCAL',   0,  500,  29.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'LOCAL', 500, 2000,  42.00, 17.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'LOCAL',2000, NULL,  66.00, 15.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'KA',      0,  500,  35.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'KA',    500, 2000,  51.00, 21.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'KA',   2000, NULL,  80.00, 19.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'SOUTH',   0,  500,  43.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'SOUTH', 500, 2000,  63.00, 26.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'SOUTH',2000, NULL,  98.00, 24.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'REST',    0,  500,  54.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'REST',  500, 2000,  82.00, 34.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'REST', 2000, NULL, 130.00, 32.00, 10.00, 4.00 UNION ALL

    -- DTDC: cheapest small parcels, weak on heavy
    SELECT 'DTDC', 'LOCAL',   0,  500,  27.00,  0.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'LOCAL', 500, 2000,  44.00, 20.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'LOCAL',2000, NULL,  78.00, 22.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'KA',      0,  500,  33.00,  0.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'KA',    500, 2000,  54.00, 24.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'KA',   2000, NULL,  95.00, 26.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'SOUTH',   0,  500,  41.00,  0.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'SOUTH', 500, 2000,  67.00, 30.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'SOUTH',2000, NULL, 118.00, 32.00,  8.00, 3.00 UNION ALL

    -- Shadowfax: same-city only, flat and fast
    SELECT 'SHADOWFAX', 'LOCAL',   0, 2000,  40.00,  0.00,  0.00, 0.00 UNION ALL
    SELECT 'SHADOWFAX', 'LOCAL',2000, NULL,  62.00, 12.00,  0.00, 0.00 UNION ALL

    -- Sandbox: dearest everywhere, so it never wins a scoring test by accident
    SELECT 'SANDBOX', 'LOCAL',  0, NULL, 200.00, 50.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'KA',     0, NULL, 220.00, 55.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'SOUTH',  0, NULL, 240.00, 60.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'REST',   0, NULL, 260.00, 65.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'REMOTE', 0, NULL, 300.00, 75.00, 0.00, 0.00
) v ON v.`code` = c.`code`
ON DUPLICATE KEY UPDATE
    `base_charge`   = VALUES(`base_charge`),
    `per_kg_charge` = VALUES(`per_kg_charge`),
    `version`       = `courier_rate_cards`.`version` + 1;

-- Numbering counters for shipments and manifests in the current financial year.
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('shipment:', fy.`year`), 'order', 'SHP', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('manifest:', fy.`year`), 'order', 'MFT', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

-- ==========================================================================
--  007_staff_commission_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 007 - Staff operations, commission rules and settings
--
--  Commission figures are illustrative. Replace them with the merchant's actual
--  incentive scheme before go-live: staff will be paid these numbers.
-- ============================================================================



INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'operations', 'auto_assign_orders',          '1',    'bool',   'Assign confirmed orders to executives automatically', 0),
    (UUID(), 'operations', 'assignment_strategy',         'least_loaded', 'string', 'least_loaded or round_robin', 0),
    (UUID(), 'operations', 'assignment_sla_hours',        '8',    'int',    'Hours an executive has to complete an assignment', 0),
    (UUID(), 'operations', 'default_executive_capacity',  '25',   'int',    'Concurrent open orders per executive', 0),
    (UUID(), 'operations', 'commission_auto_accrue',      '1',    'bool',   'Accrue fulfilment commission when an order is delivered', 0),
    (UUID(), 'operations', 'commission_auto_approve',     '0',    'bool',   'Approve accruals without a supervisor reviewing them', 0),
    (UUID(), 'bulk',       'bulk_quote_validity_days',    '14',   'int',    'How long a bulk quote stays open', 1),
    (UUID(), 'bulk',       'bulk_minimum_order_value',    '10000','string', 'Smallest bulk enquiry worth quoting', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Commission rules
--
-- EXEC-STANDARD is the everyday rule. EXEC-HIGHVALUE overrides it on large
-- orders via a lower `priority`, because a flat fee on a Rs 25,000 order is a
-- poor incentive to handle it carefully.
-- ---------------------------------------------------------------------------
INSERT INTO `commission_rules`
    (`uuid`, `code`, `name`, `description`, `scope`, `calculation`,
     `flat_amount`, `percentage`, `tiers`, `min_order_value`, `max_commission`,
     `applies_to_role`, `priority`, `status`)
VALUES
    (UUID(), 'EXEC-STANDARD', 'Executive fulfilment fee',
     'Flat fee for each order an executive packs and dispatches.',
     'executive_fulfilment', 'flat_per_order',
     15.00, NULL, NULL, NULL, NULL, 'executive', 100, 'active'),

    (UUID(), 'EXEC-HIGHVALUE', 'High-value order incentive',
     'Replaces the flat fee on orders above Rs 5,000, capped so one large order cannot dominate a month.',
     'executive_fulfilment', 'percentage_of_order',
     NULL, 1.50, NULL, 5000.00, 250.00, 'executive', 50, 'active'),

    (UUID(), 'SUP-OVERRIDE', 'Supervisor override',
     'Paid to the supervisor of the executive who fulfilled the order.',
     'supervisor_override', 'flat_per_order',
     5.00, NULL, NULL, NULL, NULL, 'supervisor', 100, 'active'),

    (UUID(), 'EXEC-VOLUME', 'Volume bonus (draft)',
     'Tiered bonus once an executive passes a monthly volume. Draft until the scheme is agreed.',
     'campaign', 'tiered_by_volume',
     NULL, NULL,
     '[{"min_orders":0,"amount":0},{"min_orders":100,"amount":500},{"min_orders":250,"amount":1500}]',
     NULL, NULL, 'executive', 200, 'draft')
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `version` = `commission_rules`.`version` + 1;

-- Numbering counters for the operational documents added by this phase.
-- The whole SELECT is wrapped in a derived table on purpose. Written directly
-- as `... CROSS JOIN (...) v ON DUPLICATE KEY UPDATE`, MySQL parses the `ON` as
-- the join condition rather than the start of the upsert clause, and reports a
-- syntax error pointing at `KEY UPDATE` with no hint of the real cause.
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT * FROM (
    SELECT UUID() AS `uuid`,
           CONCAT(v.`counter_key`, ':', fy.`year`) AS `sequence_key`,
           'order' AS `purpose`,
           v.`counter_prefix` AS `prefix`,
           fy.`year` AS `financial_year`,
           0 AS `last_number`
    FROM (
        SELECT CASE WHEN MONTH(NOW()) >= 4
                    THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                    ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
               END AS `year`
    ) fy
    CROSS JOIN (
        SELECT 'packing_slip' AS `counter_key`, 'PS'  AS `counter_prefix` UNION ALL
        SELECT 'bulk_enquiry',                  'BE'  UNION ALL
        SELECT 'bulk_quote',                    'BQ'  UNION ALL
        SELECT 'settlement',                    'STL'
    ) v
) src
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

-- ==========================================================================
--  008_notifications_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 008 - Notification templates, settings and scheduled tasks
--
--  SMS bodies are deliberately short: Indian gateways bill per 160-character
--  segment, and a template that runs to 170 characters doubles the cost of
--  every message the business ever sends.
--
--  provider_template_id is blank here. Indian operators require every template
--  to be registered on the DLT platform before it can be delivered; unregistered
--  content is dropped silently. Fill these in from the operator portal before
--  go-live or nothing will arrive.
-- ============================================================================



INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'notifications', 'notifications_enabled',      '1',     'bool',   'Master switch for outbound messaging', 0),
    (UUID(), 'notifications', 'promotional_quiet_start',    '21:00', 'string', 'TRAI: no promotional messages after this time', 0),
    (UUID(), 'notifications', 'promotional_quiet_end',      '09:00', 'string', 'TRAI: no promotional messages before this time', 0),
    (UUID(), 'notifications', 'notification_batch_size',    '50',    'int',    'Messages dispatched per worker pass', 0),
    (UUID(), 'notifications', 'notification_retry_minutes', '15',    'int',    'Delay before retrying a failed send', 0),
    (UUID(), 'notifications', 'abandoned_cart_hours',       '6',     'int',    'Idle hours before a cart is treated as abandoned', 0),
    (UUID(), 'notifications', 'support_phone',              '',      'string', 'Shown in message footers', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

INSERT INTO `notification_templates`
    (`uuid`, `code`, `channel`, `name`, `category`, `subject`, `body`, `required_variables`)
VALUES
    (UUID(), 'order.placed', 'sms', 'Order placed', 'transactional', NULL,
     'Order {{order_number}} received. Pay {{amount}} to confirm. We will notify you once payment is received.',
     '["order_number","amount"]'),

    (UUID(), 'order.confirmed', 'sms', 'Order confirmed', 'transactional', NULL,
     'Payment received for order {{order_number}}. We are preparing your order and will notify you when it ships.',
     '["order_number"]'),

    (UUID(), 'order.confirmed', 'email', 'Order confirmed', 'transactional',
     'Your order {{order_number}} is confirmed',
     'Hello {{customer_name}},

Thank you for your order. We have received your payment of {{amount}} and your order {{order_number}} is now confirmed.

Invoice number: {{invoice_number}}
Delivery to: {{delivery_address}}

We will send you tracking details as soon as your parcel is dispatched.',
     '["customer_name","order_number","amount"]'),

    (UUID(), 'order.shipped', 'sms', 'Order shipped', 'transactional', NULL,
     'Order {{order_number}} shipped via {{courier_name}}. Track: {{tracking_number}}. Expected by {{expected_date}}.',
     '["order_number","courier_name","tracking_number"]'),

    (UUID(), 'order.delivered', 'sms', 'Order delivered', 'transactional', NULL,
     'Order {{order_number}} delivered. Thank you for shopping with us.',
     '["order_number"]'),

    (UUID(), 'order.cancelled', 'sms', 'Order cancelled', 'transactional', NULL,
     'Order {{order_number}} has been cancelled. Any amount paid will be refunded within 5-7 working days.',
     '["order_number"]'),

    (UUID(), 'payment.failed', 'sms', 'Payment failed', 'transactional', NULL,
     'Payment for order {{order_number}} did not complete. You can retry until {{expires_at}}.',
     '["order_number"]'),

    (UUID(), 'wallet.credited', 'sms', 'Wallet credited', 'transactional', NULL,
     '{{amount}} added to your wallet. Balance: {{balance}}.',
     '["amount","balance"]'),

    (UUID(), 'referral.rewarded', 'sms', 'Referral reward', 'transactional', NULL,
     'Your friend completed their first order. {{amount}} has been added to your wallet.',
     '["amount"]'),

    (UUID(), 'cart.abandoned', 'sms', 'Abandoned cart reminder', 'promotional', NULL,
     'You left {{item_count}} item(s) in your cart. Complete your order before stock changes.',
     '["item_count"]'),

    (UUID(), 'offer.announcement', 'sms', 'Offer announcement', 'promotional', NULL,
     '{{offer_title}}. Use code {{offer_code}} before {{valid_until}}.',
     '["offer_title","offer_code"]'),

    (UUID(), 'wallet.expiring', 'sms', 'Wallet credit expiring', 'promotional', NULL,
     '{{amount}} of wallet credit expires on {{expiry_date}}. Use it on your next order.',
     '["amount","expiry_date"]')
ON DUPLICATE KEY UPDATE
    `body`    = VALUES(`body`),
    `version` = `notification_templates`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Scheduled tasks
--
-- Every one of these already exists as a service method that was previously
-- callable only by hand. Nothing new is being invented here; the work is being
-- put on a clock.
-- ---------------------------------------------------------------------------
INSERT INTO `scheduled_tasks` (`uuid`, `code`, `name`, `description`, `interval_minutes`, `is_enabled`, `next_run_date`)
VALUES
    (UUID(), 'notifications.dispatch', 'Dispatch queued notifications',
     'Sends pending messages. The most frequent task, because a delivery notice an hour late is nearly worthless.',
     1, 1, NOW()),

    (UUID(), 'orders.expire_unpaid', 'Release unpaid orders',
     'Cancels orders whose payment window closed, returning wallet credit and releasing the coupon they were holding.',
     10, 1, NOW()),

    (UUID(), 'shipments.refresh_tracking', 'Refresh courier tracking',
     'Polls couriers for parcels with no recent scan, covering webhooks that never arrived.',
     60, 1, NOW()),

    (UUID(), 'carts.abandoned', 'Abandoned cart reminders',
     'One promotional reminder per abandoned cart. Deliberately not repeated.',
     120, 1, NOW()),

    (UUID(), 'wallet.expire_credits', 'Expire wallet credits',
     'Expires credits past their date and warns customers a week before.',
     720, 1, NOW()),

    (UUID(), 'promotions.expire', 'Expire coupons and offers',
     'Moves coupons and offers past their end date out of active status.',
     720, 1, NOW()),

    (UUID(), 'couriers.rescore', 'Recalculate courier reliability',
     'Feeds real delivery outcomes back into the BR-007 selection score.',
     1440, 1, NOW())
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `scheduled_tasks`.`version` + 1;

-- ==========================================================================
--  009_content_seed.sql
-- ==========================================================================
-- ============================================================================
--  Seed 009 - Engagement settings, legal pages and starter FAQ
--
--  The policy pages are PLACEHOLDERS with real structure. They must be reviewed
--  by someone qualified before go-live: a returns policy is a contract with the
--  customer, and Indian consumer law has specific requirements about what a
--  seller must disclose.
-- ============================================================================



INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'reviews', 'reviews_require_purchase',    '1',  'bool', 'Only customers with a delivered order may review that product', 0),
    (UUID(), 'reviews', 'reviews_auto_approve',        '0',  'bool', 'Publish reviews without moderation', 0),
    (UUID(), 'reviews', 'reviews_auto_hide_reports',   '3',  'int',  'Reports before a review is hidden pending moderation', 0),
    (UUID(), 'reviews', 'reviews_edit_window_days',    '30', 'int',  'How long a customer may edit their own review', 1),
    (UUID(), 'support', 'support_first_response_hours','4',  'int',  'First response SLA', 1),
    (UUID(), 'support', 'support_resolution_hours',    '48', 'int',  'Resolution SLA', 1),
    (UUID(), 'support', 'support_reopen_days',         '7',  'int',  'How long a resolved ticket may be reopened', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

INSERT INTO `cms_pages` (`uuid`, `slug`, `title`, `body`, `excerpt`, `status`, `published_date`, `is_system_page`, `display_order`)
VALUES
    (UUID(), 'shipping-policy', 'Shipping Policy',
     'PLACEHOLDER - review before go-live.

We dispatch orders within 1-2 working days of payment confirmation. Delivery timelines depend on your pincode and are shown at checkout before you pay.

Delivery charges are calculated by weight and destination and are displayed before payment. Orders above the free-delivery threshold for your zone ship at no charge.

We do not accept cash on delivery. All orders are prepaid by UPI.',
     'Dispatch timelines, delivery charges and serviceable areas.',
     'published', NOW(), 1, 10),

    (UUID(), 'returns-and-refunds', 'Returns and Refunds',
     'PLACEHOLDER - must be reviewed by someone qualified before go-live. Indian consumer law has specific disclosure requirements for food sellers.

Food products are perishable, so we accept returns only where an item arrives damaged, is past its shelf life on arrival, or is not what was ordered.

Report an issue within 48 hours of delivery through your order page or by raising a support ticket. Photographs help us resolve it faster.

Approved refunds are returned to the original payment method within 5-7 working days. Wallet credit used on the order is returned as wallet credit.',
     'When we accept returns and how refunds are processed.',
     'published', NOW(), 1, 20),

    (UUID(), 'privacy-policy', 'Privacy Policy',
     'PLACEHOLDER - review before go-live.

We collect the information needed to fulfil your order: your name, contact details and delivery address. Payment details are handled by our payment provider and are never stored on our systems.

We use your mobile number to send order updates. Promotional messages are sent only with your consent and can be switched off at any time in your notification settings.

We do not sell your personal information.',
     'What we collect, why, and how to control it.',
     'published', NOW(), 1, 30),

    (UUID(), 'terms-of-service', 'Terms of Service',
     'PLACEHOLDER - must be reviewed by a lawyer before go-live.

By placing an order you agree to these terms. Prices include GST. An order is confirmed only once payment is received and verified.

We may cancel an order and refund it in full where a product is unavailable or a pricing error has occurred.',
     'The terms on which we sell.',
     'published', NOW(), 1, 40)
ON DUPLICATE KEY UPDATE
    `title`   = VALUES(`title`),
    `version` = `cms_pages`.`version` + 1;

INSERT INTO `faq_entries` (`uuid`, `group_code`, `question`, `answer`, `display_order`, `status`)
VALUES
    (UUID(), 'orders', 'How do I pay for my order?',
     'All orders are prepaid by UPI. You can pay using any UPI app - Google Pay, PhonePe, Paytm, BHIM or your bank app. We do not offer cash on delivery.', 10, 'published'),

    (UUID(), 'orders', 'Why do I need to enter an OTP when ordering?',
     'The OTP confirms that the mobile number on the order is genuinely yours. It protects you against someone placing an order in your name and makes sure delivery updates reach you.', 20, 'published'),

    (UUID(), 'orders', 'Can I cancel my order?',
     'You can cancel from your order page any time before it is handed to the courier. After that, please raise a support ticket and we will help where we can.', 30, 'published'),

    (UUID(), 'delivery', 'How long will my order take?',
     'The estimate for your pincode is shown at checkout before you pay. Most metro deliveries take 1-3 working days; remote areas can take up to a week.', 10, 'published'),

    (UUID(), 'delivery', 'How is the delivery charge calculated?',
     'By the weight of your parcel and where it is going. The exact charge is shown before you pay, and orders above the free-delivery threshold for your area ship free.', 20, 'published'),

    (UUID(), 'products', 'How should I store spices and dry fruits?',
     'Keep them in airtight containers away from direct sunlight and moisture. Whole spices keep their aroma far longer than ground ones. Dry fruits are best refrigerated in a humid climate.', 10, 'published'),

    (UUID(), 'products', 'What is the shelf life?',
     'Shelf life is listed on each product page and printed on the pack. Ground spices are best used within six months of opening; whole spices keep for a year or more.', 20, 'published'),

    (UUID(), 'account', 'How does the referral programme work?',
     'Share your referral code with a friend. Once they complete their first paid order, a reward is credited to your wallet. Wallet credit can be used against any future order.', 10, 'published'),

    (UUID(), 'account', 'How do I stop promotional messages?',
     'Turn them off in your notification settings. Order confirmations, payment receipts, dispatch notices and OTPs will still be sent, since those are needed to complete your order.', 20, 'published')
ON DUPLICATE KEY UPDATE
    `answer`  = VALUES(`answer`),
    `version` = `faq_entries`.`version` + 1;

-- Numbering counter for support tickets.
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT * FROM (
    SELECT UUID() AS `uuid`,
           CONCAT('ticket:', fy.`year`) AS `sequence_key`,
           'order' AS `purpose`,
           'TKT' AS `prefix`,
           fy.`year` AS `financial_year`,
           0 AS `last_number`
    FROM (
        SELECT CASE WHEN MONTH(NOW()) >= 4
                    THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                    ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
               END AS `year`
    ) fy
) src
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

-- Notification templates for engagement events.
INSERT INTO `notification_templates`
    (`uuid`, `code`, `channel`, `name`, `category`, `subject`, `body`, `required_variables`)
VALUES
    (UUID(), 'ticket.created', 'sms', 'Support ticket raised', 'transactional', NULL,
     'Support ticket {{ticket_number}} raised. We will respond within {{sla_hours}} hours.',
     '["ticket_number","sla_hours"]'),

    (UUID(), 'ticket.replied', 'sms', 'Support reply', 'transactional', NULL,
     'We have replied to your ticket {{ticket_number}}. View it in your account.',
     '["ticket_number"]'),

    (UUID(), 'ticket.resolved', 'sms', 'Support ticket resolved', 'transactional', NULL,
     'Ticket {{ticket_number}} has been resolved. Reply within {{reopen_days}} days if you need more help.',
     '["ticket_number","reopen_days"]'),

    (UUID(), 'review.approved', 'sms', 'Review published', 'transactional', NULL,
     'Thank you - your review of {{product_name}} is now live.',
     '["product_name"]')
ON DUPLICATE KEY UPDATE
    `body`    = VALUES(`body`),
    `version` = `notification_templates`.`version` + 1;


-- ==========================================================================
--  012_manual_payment_delivery_seed.sql
--
--  Lets the store take payment and ship parcels before Razorpay/Shiprocket
--  credentials exist: a staff-verified UPI QR code and manual dispatch,
--  toggled from /admin/settings without a redeploy. See ManualGateway,
--  ManualCourierAdapter, SettingsService and bootstrap/container.php.
--
--  The customer-facing "delivers in N-M days" estimate is unaffected by this
--  block — it comes from delivery_zones above, which is courier-independent.
--
--  Values are left alone on re-import (only description/is_public refresh),
--  so re-running this file never clobbers a driver an admin has since
--  switched away from manual.
-- ==========================================================================

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'commerce', 'payment_driver',            'manual',              'string',
     'Active payment gateway: manual, sandbox, or razorpay. Editable from /admin/settings.', 0),
    (UUID(), 'commerce', 'delivery_driver',           'manual',              'string',
     'Active courier adapter: manual, sandbox, or shiprocket. Editable from /admin/settings.', 0),
    (UUID(), 'commerce', 'manual_payment_vpa',        '',                    'string',
     'UPI VPA shown under the manual payment QR code.', 0),
    (UUID(), 'commerce', 'manual_payment_payee_name', 'Anjeera Dry Fruits',  'string',
     'Payee name shown alongside the manual payment QR code.', 0),
    (UUID(), 'commerce', 'manual_payment_qr_path',    NULL,                  'string',
     'Storage path of the admin-uploaded manual payment QR image.', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  CONSTRAINTS REMOVED FOR PORTABILITY
--
--  Each of these referenced a column that is also part of a foreign key with
--  a cascading action. The application enforces the same rule.
--
--    coupons.chk_coupons_specific_user  (touches specific_user_id)
--    coupon_targets.chk_coupon_targets_one_reference  (touches category_id, product_id)
--    offer_targets.chk_offer_targets_one_reference  (touches category_id, product_id)
--    referrals.chk_referrals_not_self  (touches referee_user_id, referrer_user_id)
--    staff_profiles.chk_staff_not_own_manager  (touches user_id)
-- ============================================================================
