-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 014 - Inventory Foundation
--
--  warehouses, variant_option_types, variant_option_values,
--  category_option_types, product_variant_options, inventory_stock,
--  inventory_movements, inventory_batches. Plus ALTERs on product_variants,
--  categories and products.
--
--  THIS MIGRATION SUPERSEDES BR-001 / BR-002 (see migration 002). Those rules
--  said no stock, quantity-on-hand or warehouse column would exist anywhere,
--  and that availability was a publishing decision, not an inventory
--  calculation. That was a deliberate decision at the time; it is deliberately
--  reversed here on explicit product direction (5Star inventory-first brief,
--  2026-09-14) to make inventory the platform's single source of truth for
--  stock across online sales, POS, mobile inward, CSV import and purchases.
--
--  ARCHITECTURE
--
--  `inventory_movements` is the append-only ledger and the source of truth:
--  every stock-changing event (a sale, a purchase inward, a return, damage, an
--  adjustment, a transfer) is one row, never edited afterwards — a BEFORE
--  UPDATE trigger enforces that the same way `trg_commission_entries_amount_
--  immutable` does in migration 007. Corrections are new rows, not edits.
--
--  `inventory_stock` is a fast-read CACHE of the current balance per
--  (product_variant_id, warehouse_id), maintained transactionally alongside
--  every movement insert by InventoryService. It is deliberately allowed to go
--  negative: checkout is never blocked on stock (unchanged platform behaviour,
--  restated rather than reversed), and a negative balance is a visibility
--  signal for staff to investigate, not an error condition.
--
--  `inventory_batches` is populated opportunistically whenever a movement
--  carries a batch_no, giving batch/expiry visibility without a second
--  parallel stock cache to keep in sync — full FEFO consumption logic is a
--  later phase (purchase inward / POS), not this one.
--
--  GENERIC VARIANT DIMENSIONS
--
--  `product_variants` stays one row per SKU (unchanged). Size/colour/pack-size
--  are modelled as a proper option/value schema rather than bolted onto that
--  table: `variant_option_types` (Size, Colour, Pack size, ...),
--  `variant_option_values` (the values each type can take), and
--  `product_variant_options` (which values a given variant carries — at most
--  one value per type, enforced by a unique key on (product_variant_id,
--  option_type_id)). `category_option_types` lets an administrator configure
--  which dimensions apply to which category (spices -> pack size; clothing ->
--  size + colour) without any change to the inventory engine's code — the
--  same "no core-code change per category" principle that
--  `categories.inventory_tracking` / `products.inventory_tracking` apply to
--  batch/expiry tracking.
--
--  PURCHASE PRICE, AVERAGE COST AND SELLING PRICE ARE THREE DIFFERENT FIELDS.
--  `inventory_movements.unit_cost` is the actual vendor cost for one specific
--  inward event, and is never overwritten once written (it lives on an
--  immutable ledger row). `inventory_stock.average_cost` is the weighted
--  average purchase cost, recalculated by InventoryService whenever an inward
--  movement carries a unit_cost. `product_variants.selling_price` is the
--  customer-facing price and this migration never touches it — no pricing
--  logic writes to it as a side effect of recording inventory.
--
--  Same audit contract as every prior migration (see migration 001).
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- warehouses
-- ---------------------------------------------------------------------------
CREATE TABLE `warehouses` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `code`           VARCHAR(30)     NOT NULL COMMENT 'Short machine code, e.g. WH-MAIN',
    `name`           VARCHAR(120)    NOT NULL,
    `address_line1`  VARCHAR(255)    NULL,
    `address_line2`  VARCHAR(255)    NULL,
    `city`           VARCHAR(100)    NULL,
    `state`          VARCHAR(100)    NULL,
    `pincode`        VARCHAR(10)     NULL,
    `country`        VARCHAR(60)     NOT NULL DEFAULT 'India',
    `phone`          VARCHAR(15)     NULL,
    `is_default`     TINYINT(1)      NOT NULL DEFAULT 0
                     COMMENT 'Exactly one row true; enforced in WarehouseService, not here. Target of automatic sales deduction.',
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
    UNIQUE KEY `uq_warehouses_uuid` (`uuid`),
    UNIQUE KEY `uq_warehouses_code` (`code`),
    KEY `idx_warehouses_state` (`is_deleted`, `is_active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- variant_option_types  (Size, Colour, Pack size, ...)
-- ---------------------------------------------------------------------------
CREATE TABLE `variant_option_types` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `code`           VARCHAR(40)     NOT NULL COMMENT 'Machine name, e.g. size, color, pack_size',
    `name`           VARCHAR(80)     NOT NULL,
    `display_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
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
    UNIQUE KEY `uq_variant_option_types_uuid` (`uuid`),
    UNIQUE KEY `uq_variant_option_types_code` (`code`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- variant_option_values  (e.g. Size = "L", Colour = "Red")
-- ---------------------------------------------------------------------------
CREATE TABLE `variant_option_values` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `option_type_id`  BIGINT UNSIGNED NOT NULL,
    `value`           VARCHAR(60)     NOT NULL,
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
    UNIQUE KEY `uq_variant_option_values_uuid` (`uuid`),
    UNIQUE KEY `uq_variant_option_value` (`option_type_id`, `value`),
    KEY `idx_variant_option_values_type` (`option_type_id`, `display_order`),
    CONSTRAINT `fk_variant_option_values_type`
        FOREIGN KEY (`option_type_id`) REFERENCES `variant_option_types` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- category_option_types  (which dimensions apply to which category)
-- ---------------------------------------------------------------------------
CREATE TABLE `category_option_types` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `category_id`     BIGINT UNSIGNED NOT NULL,
    `option_type_id`  BIGINT UNSIGNED NOT NULL,
    `is_required`     TINYINT(1)      NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_category_option_types_uuid` (`uuid`),
    UNIQUE KEY `uq_category_option_type` (`category_id`, `option_type_id`),
    KEY `idx_category_option_types_category` (`category_id`, `display_order`),
    CONSTRAINT `fk_category_option_types_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_category_option_types_type`
        FOREIGN KEY (`option_type_id`) REFERENCES `variant_option_types` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_variant_options  (which option values a given variant carries)
--
-- `option_type_id` is denormalised from `option_value_id` purely so a unique
-- key can enforce "at most one value per dimension per variant" without a
-- subquery.
-- ---------------------------------------------------------------------------
CREATE TABLE `product_variant_options` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `option_type_id`      BIGINT UNSIGNED NOT NULL,
    `option_value_id`     BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_product_variant_options_uuid` (`uuid`),
    UNIQUE KEY `uq_variant_option_type_slot` (`product_variant_id`, `option_type_id`),
    UNIQUE KEY `uq_variant_option_value_slot` (`product_variant_id`, `option_value_id`),
    KEY `idx_variant_options_value` (`option_value_id`),
    CONSTRAINT `fk_variant_options_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_variant_options_type`
        FOREIGN KEY (`option_type_id`) REFERENCES `variant_option_types` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_variant_options_value`
        FOREIGN KEY (`option_value_id`) REFERENCES `variant_option_values` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_variants: barcode, stock unit type, display unit label.
-- Same technique as migration 006 adding courier dimensions to this table.
-- ---------------------------------------------------------------------------
ALTER TABLE `product_variants`
    ADD COLUMN `barcode` VARCHAR(64) NULL
        COMMENT 'EAN/UPC or similar, distinct from the internal SKU' AFTER `sku`,
    ADD COLUMN `stock_unit_type` ENUM('weight', 'quantity') NOT NULL DEFAULT 'weight'
        COMMENT 'How inventory_stock.quantity for this variant should be read' AFTER `pack_type`,
    ADD COLUMN `unit_label` VARCHAR(20) NULL
        COMMENT 'Display only, e.g. pcs, box' AFTER `stock_unit_type`,
    ADD UNIQUE KEY `uq_product_variants_barcode` (`barcode`);

-- ---------------------------------------------------------------------------
-- categories / products: data-driven inventory tracking configuration.
-- Resolved product -> category -> platform default by InventoryConfigResolver
-- in the application, never by branching on category in the inventory engine.
-- ---------------------------------------------------------------------------
ALTER TABLE `categories`
    ADD COLUMN `inventory_tracking` JSON NULL
        COMMENT 'e.g. {"tracks_batch":true,"tracks_expiry":true,"requires_batch":false,"requires_expiry":false}'
        AFTER `meta_description`;

ALTER TABLE `products`
    ADD COLUMN `inventory_tracking` JSON NULL
        COMMENT 'Overrides the category default when set; same shape'
        AFTER `meta_description`;

-- ---------------------------------------------------------------------------
-- inventory_stock  (fast-read cache; inventory_movements is the source of
-- truth). Deliberately allowed to go negative — see migration banner.
-- ---------------------------------------------------------------------------
CREATE TABLE `inventory_stock` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `warehouse_id`        BIGINT UNSIGNED NOT NULL,
    `quantity`            DECIMAL(12, 3)  NOT NULL DEFAULT 0.000
                          COMMENT 'May go negative: checkout is never blocked on stock',
    `reorder_threshold`   DECIMAL(12, 3)  NULL,
    `average_cost`        DECIMAL(12, 4)  NULL COMMENT 'Weighted-average purchase cost per unit',
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
    UNIQUE KEY `uq_inventory_stock_uuid` (`uuid`),
    UNIQUE KEY `uq_inventory_stock_variant_warehouse` (`product_variant_id`, `warehouse_id`),
    KEY `idx_inventory_stock_warehouse` (`warehouse_id`),
    KEY `idx_inventory_stock_low` (`reorder_threshold`, `quantity`),
    CONSTRAINT `fk_inventory_stock_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_inventory_stock_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- inventory_movements  (append-only ledger; the source of truth)
--
-- Every column below is fixed at insert. A trigger blocks any UPDATE outright
-- — unlike commission_entries (migration 007), which only protects specific
-- columns because it has a legitimate settlement workflow, this ledger has no
-- legitimate reason to ever be edited. A correction is a new movement with an
-- opposite-signed quantity_delta.
-- ---------------------------------------------------------------------------
CREATE TABLE `inventory_movements` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `warehouse_id`        BIGINT UNSIGNED NOT NULL,
    `movement_type`       ENUM('inward', 'sale', 'return', 'damage', 'adjustment', 'transfer_in', 'transfer_out')
                                          NOT NULL,
    `reference_type`      ENUM('order', 'purchase_order', 'csv_import', 'mobile_app', 'manual', 'pos_sale', 'opening_balance')
                                          NULL,
    `reference_id`        BIGINT UNSIGNED NULL,
    `reference_uuid`      CHAR(36)        NULL,
    `quantity_delta`      DECIMAL(12, 3)  NOT NULL
                          COMMENT 'Signed: positive for inward/return, negative for sale/damage/transfer_out',
    `quantity_after`      DECIMAL(12, 3)  NOT NULL COMMENT 'Running balance snapshot, for audit reconstruction',
    `unit_cost`           DECIMAL(12, 4)  NULL
                          COMMENT 'Purchase price for this inward only. Never the same field as average_cost or selling_price.',
    `batch_no`            VARCHAR(60)     NULL,
    `expiry_date`         DATE            NULL,
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
    UNIQUE KEY `uq_inventory_movements_uuid` (`uuid`),
    KEY `idx_inventory_movements_variant` (`product_variant_id`, `warehouse_id`, `created_date`),
    KEY `idx_inventory_movements_reference` (`reference_type`, `reference_id`),
    KEY `idx_inventory_movements_batch` (`batch_no`),
    KEY `idx_inventory_movements_type` (`movement_type`, `created_date`),
    CONSTRAINT `fk_inventory_movements_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_inventory_movements_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER `trg_inventory_movements_immutable`
BEFORE UPDATE ON `inventory_movements`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'inventory_movements is an append-only ledger: post a reversing movement instead of editing one';
END$$
DELIMITER ;

-- ---------------------------------------------------------------------------
-- inventory_batches  (populated opportunistically when a movement carries a
-- batch_no; not a second stock cache — full FEFO consumption is a later
-- phase)
-- ---------------------------------------------------------------------------
CREATE TABLE `inventory_batches` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_variant_id`  BIGINT UNSIGNED NOT NULL,
    `warehouse_id`        BIGINT UNSIGNED NOT NULL,
    `batch_no`            VARCHAR(60)     NOT NULL,
    `expiry_date`         DATE            NULL,
    `quantity`            DECIMAL(12, 3)  NOT NULL DEFAULT 0.000,
    `unit_cost`           DECIMAL(12, 4)  NULL,
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
    UNIQUE KEY `uq_inventory_batches_uuid` (`uuid`),
    UNIQUE KEY `uq_inventory_batch` (`product_variant_id`, `warehouse_id`, `batch_no`),
    KEY `idx_inventory_batches_expiry` (`expiry_date`),
    CONSTRAINT `fk_inventory_batches_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_inventory_batches_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('014_inventory_foundation', 14, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
