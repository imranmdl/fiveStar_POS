-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 018 - Point of Sale (Priority 2, brief §12)
--
--  pos_sales, pos_sale_items, pos_refunds, pos_refund_items.
--
--  POS sales draw from and restore the exact same inventory every other
--  channel does. `inventory_movements.reference_type` already includes
--  'pos_sale' (migration 014 anticipated this phase) — no inventory schema
--  change is needed here. A sale posts movement_type='sale'; a void or
--  refund posts movement_type='return'; both reference_type='pos_sale',
--  reference_id=pos_sales.id, via the same unmodified
--  InventoryService::recordMovement() every other channel already calls.
--  There is no separate POS stock balance and there never will be.
--
--  Payment here is cashier-attested, not gateway-verified — the same trust
--  boundary COD approval already uses in this codebase (a human physically
--  present confirms payment), not PaymentGatewayInterface/Razorpay.
--
--  pos_sale_items snapshots sku/product_name/variant_name at sale time, the
--  same reasoning order_items already applies: a later product rename or
--  deletion must not rewrite a past receipt.
--
--  Same audit contract as every table since migration 001.
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- pos_sales
-- ---------------------------------------------------------------------------
CREATE TABLE `pos_sales` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `sale_number`      VARCHAR(20)     NOT NULL,
    `cashier_id`       BIGINT UNSIGNED NOT NULL,
    `warehouse_id`     BIGINT UNSIGNED NOT NULL,
    `customer_id`      BIGINT UNSIGNED NULL,
    `walk_in_name`     VARCHAR(120)    NULL,
    `walk_in_mobile`   VARCHAR(15)     NULL,
    `payment_method`   ENUM('cash', 'upi', 'card', 'other') NOT NULL,
    `subtotal`         DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `discount_amount`  DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `tax_amount`       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `grand_total`      DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `amount_tendered`  DECIMAL(12, 2)  NULL COMMENT 'Cash only',
    `change_due`       DECIMAL(12, 2)  NULL COMMENT 'Cash only',
    `status`           ENUM('completed', 'voided') NOT NULL DEFAULT 'completed',
    `voided_by`        BIGINT UNSIGNED NULL,
    `voided_date`      DATETIME        NULL,
    `void_reason`      VARCHAR(255)    NULL,
    `notes`            VARCHAR(500)    NULL,
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
    UNIQUE KEY `uq_pos_sales_uuid` (`uuid`),
    UNIQUE KEY `uq_pos_sales_number` (`sale_number`),
    KEY `idx_pos_sales_cashier` (`cashier_id`, `created_date`),
    KEY `idx_pos_sales_warehouse` (`warehouse_id`),
    KEY `idx_pos_sales_payment` (`payment_method`, `created_date`),
    KEY `idx_pos_sales_status` (`status`),
    CONSTRAINT `chk_pos_sales_totals_not_negative`
        CHECK (`subtotal` >= 0 AND `discount_amount` >= 0 AND `tax_amount` >= 0 AND `grand_total` >= 0),
    CONSTRAINT `fk_pos_sales_cashier`
        FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pos_sales_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pos_sales_customer`
        FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_pos_sales_voided_by`
        FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pos_sale_items
-- ---------------------------------------------------------------------------
CREATE TABLE `pos_sale_items` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `pos_sale_id`         BIGINT UNSIGNED NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `sku`                 VARCHAR(60)     NOT NULL,
    `product_name`        VARCHAR(180)    NOT NULL,
    `variant_name`        VARCHAR(120)    NOT NULL,
    `quantity`            DECIMAL(12, 3)  NOT NULL,
    `unit_price`          DECIMAL(10, 2)  NOT NULL,
    `discount_amount`     DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `gst_rate`            DECIMAL(5, 2)   NOT NULL,
    `tax_amount`          DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `line_total`          DECIMAL(12, 2)  NOT NULL,
    `refunded_quantity`   DECIMAL(12, 3)  NOT NULL DEFAULT 0.000
                          COMMENT 'Running total across all refunds against this line',
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
    UNIQUE KEY `uq_pos_sale_items_uuid` (`uuid`),
    KEY `idx_pos_sale_items_sale` (`pos_sale_id`),
    KEY `idx_pos_sale_items_variant` (`product_variant_id`),
    CONSTRAINT `chk_pos_sale_items_quantity_positive`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_pos_sale_items_refunded_not_exceeding`
        CHECK (`refunded_quantity` <= `quantity`),
    CONSTRAINT `fk_pos_sale_items_sale`
        FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_pos_sale_items_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pos_refunds  (header)
-- ---------------------------------------------------------------------------
CREATE TABLE `pos_refunds` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `refund_number`   VARCHAR(20)     NOT NULL,
    `pos_sale_id`     BIGINT UNSIGNED NOT NULL,
    `refunded_by`     BIGINT UNSIGNED NULL,
    `reason`          VARCHAR(255)    NOT NULL,
    `refund_amount`   DECIMAL(12, 2)  NOT NULL,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`       BIGINT UNSIGNED NULL,
    `updated_date`     DATETIME        NULL,
    `deleted_by`       BIGINT UNSIGNED NULL,
    `deleted_date`     DATETIME        NULL,
    `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`        TINYINT(1)      NOT NULL DEFAULT 0,
    `version`           INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pos_refunds_uuid` (`uuid`),
    UNIQUE KEY `uq_pos_refunds_number` (`refund_number`),
    KEY `idx_pos_refunds_sale` (`pos_sale_id`),
    CONSTRAINT `chk_pos_refunds_amount_positive`
        CHECK (`refund_amount` > 0),
    CONSTRAINT `fk_pos_refunds_sale`
        FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pos_refunds_user`
        FOREIGN KEY (`refunded_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pos_refund_items
-- ---------------------------------------------------------------------------
CREATE TABLE `pos_refund_items` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `pos_refund_id`     BIGINT UNSIGNED NOT NULL,
    `pos_sale_item_id`  BIGINT UNSIGNED NOT NULL,
    `quantity`          DECIMAL(12, 3)  NOT NULL,
    `amount`            DECIMAL(12, 2)  NOT NULL,
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
    UNIQUE KEY `uq_pos_refund_items_uuid` (`uuid`),
    KEY `idx_pos_refund_items_refund` (`pos_refund_id`),
    KEY `idx_pos_refund_items_sale_item` (`pos_sale_item_id`),
    CONSTRAINT `chk_pos_refund_items_quantity_positive`
        CHECK (`quantity` > 0),
    CONSTRAINT `fk_pos_refund_items_refund`
        FOREIGN KEY (`pos_refund_id`) REFERENCES `pos_refunds` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_pos_refund_items_sale_item`
        FOREIGN KEY (`pos_sale_item_id`) REFERENCES `pos_sale_items` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('018_pos', 18, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
