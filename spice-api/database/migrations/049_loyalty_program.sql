-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 049 - Customer Loyalty Program
--
--  A points ledger, structurally the same proven shape as wallet_accounts/
--  wallet_transactions (migration 004): an append-only ledger with a cached
--  balance, guarded by the same kind of trigger. Kept as its OWN table
--  rather than reusing the wallet directly, because points and rupees are
--  different units with different rules (points expire on a different
--  schedule, are earned by a different formula, and a "points redeemed"
--  event needs its own row even though what it produces IS an ordinary
--  wallet credit — see LoyaltyService::redeem(), which converts points to a
--  wallet credit at an admin-configured rate rather than reinventing how a
--  discount is applied at checkout/POS. Once converted, the existing wallet
--  system (already wired into checkout, POS and the admin console) does
--  everything else unchanged.
--
--  loyalty_accounts / loyalty_ledger / loyalty_point_expiries mirror
--  wallet_accounts / wallet_transactions / wallet_credit_expiries field for
--  field and trigger for trigger — see that migration's own comments for why
--  each of these decisions exists; not repeated here.
--
--  New permissions (loyalty.view/edit/adjust) follow migration 042's
--  module.action convention, granted to super_admin and administrator only
--  — the same roles migration 042 granted every other module to by default.
--
--  Additive only.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- loyalty_accounts
-- ---------------------------------------------------------------------------
CREATE TABLE `loyalty_accounts` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `points_balance`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `lifetime_earned`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `lifetime_redeemed`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `is_frozen`           TINYINT(1)      NOT NULL DEFAULT 0
                          COMMENT 'Set during a fraud review; blocks redemption but not earning',
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
    UNIQUE KEY `uq_loyalty_accounts_uuid` (`uuid`),
    UNIQUE KEY `uq_loyalty_accounts_user` (`user_id`),
    CONSTRAINT `fk_loyalty_accounts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- loyalty_ledger  (APPEND-ONLY — see the triggers below)
-- ---------------------------------------------------------------------------
CREATE TABLE `loyalty_ledger` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `account_id`        BIGINT UNSIGNED NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL COMMENT 'Denormalised for statement queries',
    `direction`         ENUM('credit','debit') NOT NULL,
    `source`            ENUM('purchase_online','purchase_pos','review','referral',
                             'redemption','expiry','admin_adjustment')
                        NOT NULL,
    `points`            INT UNSIGNED    NOT NULL,
    `balance_after`     INT UNSIGNED    NOT NULL COMMENT 'Running balance, so any row can be audited alone',
    `reference_type`    VARCHAR(50)     NULL COMMENT 'e.g. orders, pos_sales, product_reviews, referrals',
    `reference_id`      VARCHAR(60)     NULL,
    `idempotency_key`   VARCHAR(120)    NULL
                        COMMENT 'Unique; makes a retried earn event a no-op instead of double-crediting points',
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
    UNIQUE KEY `uq_loyalty_ledger_uuid` (`uuid`),
    UNIQUE KEY `uq_loyalty_ledger_idempotency` (`idempotency_key`),
    KEY `idx_loyalty_ledger_account` (`account_id`, `created_date`),
    KEY `idx_loyalty_ledger_user` (`user_id`, `created_date`),
    KEY `idx_loyalty_ledger_source` (`source`, `created_date`),
    KEY `idx_loyalty_ledger_expiry` (`direction`, `expires_date`),
    CONSTRAINT `chk_loyalty_ledger_points_positive`
        CHECK (`points` > 0),
    CONSTRAINT `fk_loyalty_ledger_account`
        FOREIGN KEY (`account_id`) REFERENCES `loyalty_accounts` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_loyalty_ledger_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TRIGGER `trg_loyalty_ledger_no_update`
BEFORE UPDATE ON `loyalty_ledger`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'loyalty_ledger is append-only: post a compensating entry instead of editing';

CREATE TRIGGER `trg_loyalty_ledger_no_delete`
BEFORE DELETE ON `loyalty_ledger`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'loyalty_ledger is append-only: rows can never be deleted';

-- ---------------------------------------------------------------------------
-- loyalty_point_expiries — mirrors wallet_credit_expiries exactly (a
-- write-off marker, needed only because the ledger row it refers to can
-- never itself be edited to say "expired").
-- ---------------------------------------------------------------------------
CREATE TABLE `loyalty_point_expiries` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `ledger_id`             BIGINT UNSIGNED NOT NULL COMMENT 'The credit that expired',
    `debit_ledger_id`       BIGINT UNSIGNED NULL COMMENT 'The compensating debit that wrote it off',
    `expired_points`        INT UNSIGNED    NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_loyalty_point_expiries_uuid` (`uuid`),
    UNIQUE KEY `uq_loyalty_point_expiries_ledger` (`ledger_id`),
    KEY `idx_loyalty_point_expiries_debit` (`debit_ledger_id`),
    CONSTRAINT `fk_loyalty_point_expiries_ledger`
        FOREIGN KEY (`ledger_id`) REFERENCES `loyalty_ledger` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_loyalty_point_expiries_debit`
        FOREIGN KEY (`debit_ledger_id`) REFERENCES `loyalty_ledger` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Settings — admin-configurable rules, all optional with a sane default,
-- off (loyalty_enabled = 0) until an admin deliberately turns it on. Same
-- settings-driven pattern as the welcome bonus (migration 045).
-- ---------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'loyalty', 'loyalty_enabled', '0', 'bool',
     'Master switch for earning and redeeming loyalty points', 0),
    (UUID(), 'loyalty', 'loyalty_rupees_per_point', '100', 'int',
     'Rupees of a paid order/sale that earns 1 point (e.g. 100 = 1 point per Rs.100 spent)', 0),
    (UUID(), 'loyalty', 'loyalty_redeem_value_per_point', '0.50', 'decimal',
     'Rupee value credited to the wallet for each point redeemed', 0),
    (UUID(), 'loyalty', 'loyalty_min_redeem_points', '100', 'int',
     'Minimum points a customer must redeem at once', 0),
    (UUID(), 'loyalty', 'loyalty_max_redeem_points_per_order', '2000', 'int',
     'Ceiling on how many points a single redemption may convert', 0),
    (UUID(), 'loyalty', 'loyalty_points_expiry_days', '365', 'int',
     'Days until earned points expire; 0 means they never expire', 0),
    (UUID(), 'loyalty', 'loyalty_review_points_enabled', '0', 'bool',
     'Award points when a product review is approved', 0),
    (UUID(), 'loyalty', 'loyalty_points_per_review', '20', 'int',
     'Points awarded per approved review, when enabled', 0),
    (UUID(), 'loyalty', 'loyalty_referral_points_enabled', '0', 'bool',
     'Award points to the referrer alongside the existing wallet referral reward', 0),
    (UUID(), 'loyalty', 'loyalty_points_per_referral', '100', 'int',
     'Points awarded per qualifying referral, when enabled', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`);

-- ---------------------------------------------------------------------------
-- Permissions (module.action convention from migration 042).
-- ---------------------------------------------------------------------------
INSERT INTO `permissions` (`uuid`, `code`, `module`, `action`, `name`)
VALUES
    (UUID(), 'loyalty.view',   'loyalty', 'view',   'View loyalty accounts, ledger and settings'),
    (UUID(), 'loyalty.edit',   'loyalty', 'edit',   'Edit loyalty program settings'),
    (UUID(), 'loyalty.adjust', 'loyalty', 'adjust', 'Manually add or deduct a customer''s points')
ON DUPLICATE KEY UPDATE
    `name`   = VALUES(`name`),
    `module` = VALUES(`module`);

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` IN ('super_admin', 'administrator')
  AND p.`module` = 'loyalty'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('049_loyalty_program', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
