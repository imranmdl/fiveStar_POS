-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 016 - Pricing Strategy and Price-Change Audit Trail
--
--  pricing_rules, price_change_log.
--
--  This migration is what is allowed to write to product_variants.selling_price
--  as a side effect of a purchase — Phases 1 (014) and 2 (015) were careful
--  never to. It stays that way even now: PricingService only ever changes a
--  selling price when a human or an explicitly configured 'auto_apply' rule
--  says so, never as an automatic consequence of recording inventory.
--
--  pricing_rules mirrors commission_rules' scope/priority resolution
--  (migration 007) — "lowest wins when several rules match" is the same rule
--  here as it is there. The scope-consistency CHECK below is the same
--  technique as chk_commission_has_its_figure (007): a rule must carry
--  exactly the reference its own scope needs, nothing more.
--
--  price_change_log is append-only, same technique as inventory_movements
--  (014): a BEFORE UPDATE trigger blocks every edit. Three figures never get
--  confused with each other here either: purchase_price (what was actually
--  paid), average_cost (the calculated blend), and old/new_selling_price (the
--  customer-facing figure) are distinct columns, matching the three-way
--  separation the whole inventory system has kept since migration 014.
--
--  Same audit contract as every table since migration 001.
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- pricing_rules
-- ---------------------------------------------------------------------------
CREATE TABLE `pricing_rules` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `name`                VARCHAR(120)    NOT NULL,
    `scope`               ENUM('global', 'category', 'product', 'variant') NOT NULL,
    `category_id`         BIGINT UNSIGNED NULL,
    `product_id`          BIGINT UNSIGNED NULL,
    `product_variant_id`  BIGINT UNSIGNED NULL,
    `calculation`         ENUM('markup_percent', 'margin_percent') NOT NULL,
    `rate`                DECIMAL(6, 3)   NOT NULL COMMENT 'Percentage figure the calculation applies',
    `tax_mode`            ENUM('exclusive', 'inclusive') NOT NULL DEFAULT 'exclusive'
                          COMMENT 'exclusive: GST is added on top of the calculated price. inclusive: rate already targets the final customer price.',
    `priority`            SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Lowest wins when several rules match',
    `status`              ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
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
    UNIQUE KEY `uq_pricing_rules_uuid` (`uuid`),
    KEY `idx_pricing_rules_lookup` (`status`, `scope`, `priority`),
    KEY `idx_pricing_rules_category` (`category_id`),
    KEY `idx_pricing_rules_product` (`product_id`),
    KEY `idx_pricing_rules_variant` (`product_variant_id`),
    CONSTRAINT `chk_pricing_rules_rate_positive`
        CHECK (`rate` > 0 AND `rate` < 1000),
    -- A margin rule of 100% or more divides by zero or goes negative in
    -- cost / (1 - rate/100); refused here rather than surfacing as a
    -- confusing runtime error when a purchase is recorded.
    CONSTRAINT `chk_pricing_rules_margin_below_100`
        CHECK (`calculation` <> 'margin_percent' OR `rate` < 100),
    -- Same shape as chk_commission_has_its_figure (007): the populated
    -- reference must match the declared scope, exactly one of them.
    CONSTRAINT `chk_pricing_rules_scope_target`
        CHECK (
            (`scope` = 'global'   AND `category_id` IS NULL     AND `product_id` IS NULL     AND `product_variant_id` IS NULL) OR
            (`scope` = 'category' AND `category_id` IS NOT NULL AND `product_id` IS NULL     AND `product_variant_id` IS NULL) OR
            (`scope` = 'product'  AND `category_id` IS NULL     AND `product_id` IS NOT NULL AND `product_variant_id` IS NULL) OR
            (`scope` = 'variant'  AND `category_id` IS NULL     AND `product_id` IS NULL     AND `product_variant_id` IS NOT NULL)
        ),
    -- ON UPDATE RESTRICT (not CASCADE): all three columns appear in the CHECK
    -- above, and MySQL 8 refuses any referential action on a CHECK column
    -- (error 3823). MariaDB accepts either; ids never change, so RESTRICT is
    -- equivalent in practice and keeps this file portable.
    CONSTRAINT `fk_pricing_rules_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pricing_rules_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pricing_rules_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- price_change_log  (append-only; the audit trail brief §7 requires)
-- ---------------------------------------------------------------------------
CREATE TABLE `price_change_log` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `reference_type`      ENUM('purchase_order', 'manual', 'auto_apply') NOT NULL,
    `reference_id`        BIGINT UNSIGNED NULL,
    `old_selling_price`   DECIMAL(10, 2)  NOT NULL,
    `new_selling_price`   DECIMAL(10, 2)  NOT NULL
                          COMMENT 'Equal to old_selling_price when decision = keep_old: the decision is logged even when nothing changed',
    `purchase_price`      DECIMAL(12, 4)  NULL
                          COMMENT 'The triggering inward unit cost, when applicable. Never the same field as average_cost or a selling price.',
    `average_cost`        DECIMAL(12, 4)  NULL,
    `decision`            ENUM('manual', 'use_average', 'use_new', 'keep_old', 'auto_apply') NOT NULL,
    `pricing_rule_id`     BIGINT UNSIGNED NULL,
    `reason`              VARCHAR(255)    NULL,
    `performed_by`        BIGINT UNSIGNED NULL,
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
    UNIQUE KEY `uq_price_change_log_uuid` (`uuid`),
    KEY `idx_price_change_log_variant` (`product_variant_id`, `created_date`),
    KEY `idx_price_change_log_reference` (`reference_type`, `reference_id`),
    CONSTRAINT `fk_price_change_log_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_price_change_log_rule`
        FOREIGN KEY (`pricing_rule_id`) REFERENCES `pricing_rules` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Same blanket immutability as trg_inventory_movements_immutable (014): this
-- ledger has no legitimate edit case, so every UPDATE is refused outright
-- rather than only protecting specific columns.
DELIMITER $$
CREATE TRIGGER `trg_price_change_log_immutable`
BEFORE UPDATE ON `price_change_log`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'price_change_log is an append-only ledger: post a new entry instead of editing one';
END$$
DELIMITER ;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('016_pricing_strategy', 16, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
