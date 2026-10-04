-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 031 - purchase_returns: goods sent back to a vendor
--
--  Header + lines, the same two-table shape purchase_orders/purchase_order_items
--  already use. A return always references the original purchase order line
--  it came from (purchase_order_item_id), so "how much of this line has
--  already been returned" is always answerable directly, without inferring it
--  from inventory_movements.
--
--  `return_to_vendor` is added to inventory_movements.movement_type (same
--  additive ENUM-widening migration 021 already used for 'lost') rather than
--  reusing the existing 'return' value — that value already means something
--  different (PurchaseOrderService::updateItem() reversing a line to correct
--  a data-entry mistake, not sending stock back to a vendor). Conflating the
--  two would make every future report of "how much did we actually return to
--  vendors" silently include purchase corrections too.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `inventory_movements`
    MODIFY COLUMN `movement_type` ENUM('inward', 'sale', 'return', 'damage', 'lost', 'adjustment',
                                        'transfer_in', 'transfer_out', 'return_to_vendor')
                                   NOT NULL,
    MODIFY COLUMN `reference_type` ENUM('order', 'purchase_order', 'csv_import', 'mobile_app', 'manual',
                                         'pos_sale', 'opening_balance', 'purchase_return')
                                    NULL;

CREATE TABLE `purchase_returns` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `return_number`       VARCHAR(20)     NOT NULL,
    `purchase_order_id`   BIGINT UNSIGNED NOT NULL,
    `vendor_id`           BIGINT UNSIGNED NOT NULL,
    `return_date`         DATE            NOT NULL,
    `reason`              VARCHAR(500)    NOT NULL,
    `total_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
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
    UNIQUE KEY `uq_purchase_returns_uuid` (`uuid`),
    UNIQUE KEY `uq_purchase_returns_number` (`return_number`),
    KEY `idx_purchase_returns_order` (`purchase_order_id`),
    KEY `idx_purchase_returns_vendor` (`vendor_id`, `return_date`),
    CONSTRAINT `fk_purchase_returns_order`
        FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_purchase_returns_vendor`
        FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `purchase_return_items` (
    `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                     CHAR(36)        NOT NULL,
    `purchase_return_id`       BIGINT UNSIGNED NOT NULL,
    `purchase_order_item_id`   BIGINT UNSIGNED NOT NULL,
    `product_variant_id`       BIGINT UNSIGNED NOT NULL,
    `quantity`                 DECIMAL(12, 3)  NOT NULL,
    `unit_cost`                DECIMAL(12, 4)  NOT NULL COMMENT 'Copied from the original line, for the refund/payable value',
    `line_amount`              DECIMAL(12, 2)  NOT NULL,
    `batch_no`                 VARCHAR(60)     NULL,
    `created_by`               BIGINT UNSIGNED NULL,
    `created_date`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`               BIGINT UNSIGNED NULL,
    `updated_date`             DATETIME        NULL,
    `deleted_by`                BIGINT UNSIGNED NULL,
    `deleted_date`             DATETIME        NULL,
    `is_active`                TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`               TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                  INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_purchase_return_items_uuid` (`uuid`),
    KEY `idx_purchase_return_items_return` (`purchase_return_id`),
    KEY `idx_purchase_return_items_order_item` (`purchase_order_item_id`),
    CONSTRAINT `chk_purchase_return_items_quantity_positive`
        CHECK (`quantity` > 0),
    CONSTRAINT `fk_purchase_return_items_return`
        FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_purchase_return_items_order_item`
        FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_purchase_return_items_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('031_purchase_returns', 31, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
