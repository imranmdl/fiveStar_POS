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

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

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
    CONSTRAINT `chk_coupons_specific_user`
        CHECK (`audience` <> 'specific_customer' OR `specific_user_id` IS NOT NULL),
    -- ON UPDATE RESTRICT: specific_user_id appears in chk_coupons_specific_user,
    -- and MySQL will not allow a referential action to modify a checked column.
    CONSTRAINT `fk_coupons_specific_user`
        FOREIGN KEY (`specific_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
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
    CONSTRAINT `chk_coupon_targets_one_reference`
        CHECK ((`target_type` = 'category' AND `category_id` IS NOT NULL AND `product_id` IS NULL)
            OR (`target_type` = 'product'  AND `product_id`  IS NOT NULL AND `category_id` IS NULL)),
    CONSTRAINT `fk_coupon_targets_coupon`
        FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    -- ON UPDATE RESTRICT: both columns appear in chk_coupon_targets_one_reference.
    CONSTRAINT `fk_coupon_targets_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_coupon_targets_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
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
    CONSTRAINT `chk_offer_targets_one_reference`
        CHECK ((`target_type` = 'category' AND `category_id` IS NOT NULL AND `product_id` IS NULL)
            OR (`target_type` = 'product'  AND `product_id`  IS NOT NULL AND `category_id` IS NULL)),
    CONSTRAINT `fk_offer_targets_offer`
        FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    -- ON UPDATE RESTRICT: both columns appear in chk_offer_targets_one_reference.
    CONSTRAINT `fk_offer_targets_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_offer_targets_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
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
    CONSTRAINT `chk_referrals_not_self`
        CHECK (`referrer_user_id` <> `referee_user_id`),
    -- ON UPDATE RESTRICT: both columns appear in chk_referrals_not_self.
    CONSTRAINT `fk_referrals_referrer`
        FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_referrals_referee`
        FOREIGN KEY (`referee_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
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
