-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 015 - Vendors and Purchase Inward
--
--  vendors, purchase_orders (header), purchase_order_items (lines).
--
--  Purchase orders take immediate effect: creating one calls the same
--  InventoryService::recordMovement() built in migration 014, once per line,
--  with reference_type='purchase_order' and reference_id=purchase_orders.id.
--  There is deliberately no status column here — no draft/receive state
--  machine, per product direction (5Star inventory-first brief, Phase 2).
--
--  purchase_order_items carries no inventory_movement_id back-reference: the
--  ledger is found by reference_type/reference_id, the same way order_items
--  is never linked back to the 'sale' movements it produced. One source of
--  truth for "what happened", not two.
--
--  unit_cost here is the actual vendor price for one line — never the same
--  field as inventory_stock.average_cost (calculated) or
--  product_variants.selling_price (customer-facing, untouched by this
--  migration or the service that uses it).
--
--  Same audit contract as every table since migration 001.
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- vendors
-- ---------------------------------------------------------------------------
CREATE TABLE `vendors` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `name`             VARCHAR(150)    NOT NULL,
    `contact_person`   VARCHAR(120)    NULL,
    `phone`            VARCHAR(15)     NULL,
    `email`            VARCHAR(150)    NULL,
    `address_line1`    VARCHAR(255)    NULL,
    `address_line2`    VARCHAR(255)    NULL,
    `city`             VARCHAR(100)    NULL,
    `state`            VARCHAR(100)    NULL,
    `pincode`          VARCHAR(10)     NULL,
    `country`          VARCHAR(60)     NOT NULL DEFAULT 'India',
    `gstin`            VARCHAR(20)     NULL,
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
    UNIQUE KEY `uq_vendors_uuid` (`uuid`),
    KEY `idx_vendors_state` (`is_deleted`, `is_active`),
    KEY `idx_vendors_name` (`name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- purchase_orders  (header; immediate-effect, no draft/receive workflow)
-- ---------------------------------------------------------------------------
CREATE TABLE `purchase_orders` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `po_number`         VARCHAR(20)     NOT NULL,
    `vendor_id`         BIGINT UNSIGNED NOT NULL,
    `warehouse_id`      BIGINT UNSIGNED NOT NULL,
    `purchase_date`     DATE            NOT NULL,
    `invoice_reference` VARCHAR(80)     NULL,
    `items_subtotal`    DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `discount_amount`   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `other_charges`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `tax_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `grand_total`       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
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
    UNIQUE KEY `uq_purchase_orders_uuid` (`uuid`),
    UNIQUE KEY `uq_purchase_orders_number` (`po_number`),
    KEY `idx_purchase_orders_vendor` (`vendor_id`, `purchase_date`),
    KEY `idx_purchase_orders_warehouse` (`warehouse_id`),
    KEY `idx_purchase_orders_date` (`purchase_date`),
    CONSTRAINT `fk_purchase_orders_vendor`
        FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_purchase_orders_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- purchase_order_items  (lines; one inventory_movements row per line, posted
-- by PurchaseOrderService at creation time via the unchanged
-- InventoryService::recordMovement())
-- ---------------------------------------------------------------------------
CREATE TABLE `purchase_order_items` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `purchase_order_id`   BIGINT UNSIGNED NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `quantity`            DECIMAL(12, 3)  NOT NULL,
    `unit_cost`           DECIMAL(12, 4)  NOT NULL
                          COMMENT 'Vendor price for this line. Never the same field as average_cost or selling_price.',
    `line_subtotal`       DECIMAL(12, 2)  NOT NULL,
    `batch_no`            VARCHAR(60)     NULL,
    `expiry_date`         DATE            NULL,
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
    UNIQUE KEY `uq_purchase_order_items_uuid` (`uuid`),
    KEY `idx_purchase_order_items_order` (`purchase_order_id`),
    KEY `idx_purchase_order_items_variant` (`product_variant_id`),
    CONSTRAINT `chk_purchase_order_items_quantity_positive`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_purchase_order_items_cost_not_negative`
        CHECK (`unit_cost` >= 0),
    CONSTRAINT `fk_purchase_order_items_order`
        FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_purchase_order_items_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('015_vendors_purchases', 15, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
