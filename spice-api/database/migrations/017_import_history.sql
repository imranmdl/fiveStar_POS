-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 017 - CSV / Excel Import History
--
--  import_batches, import_batch_items.
--
--  Nothing about these two tables is written before an import is confirmed.
--  Upload and preview (ImportService::preview()) parse and validate a file
--  and return the result directly to the caller; only ImportService::confirm()
--  — which re-parses and re-validates from scratch, never trusting an echoed
--  preview — writes a row here, in the same transaction as the catalog/
--  inventory writes it triggers. There is deliberately no "pending" status:
--  an unconfirmed preview leaves no trace in this schema at all.
--
--  `inventory_movements.reference_type` already includes 'csv_import'
--  (migration 014 anticipated this phase) — an imported row with a quantity
--  posts through the same, unmodified InventoryService::recordMovement()
--  every other inward channel uses. No schema change needed there.
--
--  Same audit contract as every table since migration 001.
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- import_batches  (one row per confirmed — successful or failed — import)
-- ---------------------------------------------------------------------------
CREATE TABLE `import_batches` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `file_name`       VARCHAR(255)    NOT NULL,
    `file_type`       ENUM('csv', 'xlsx') NOT NULL,
    `status`          ENUM('completed', 'failed') NOT NULL,
    `warehouse_id`    BIGINT UNSIGNED NULL COMMENT 'Default warehouse for rows that did not specify their own',
    `total_rows`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `valid_rows`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `invalid_rows`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `created_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `updated_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `skipped_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `error_message`   VARCHAR(500)    NULL COMMENT 'Set when status = failed',
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
    UNIQUE KEY `uq_import_batches_uuid` (`uuid`),
    KEY `idx_import_batches_created` (`created_date`),
    KEY `idx_import_batches_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_import_batches_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- import_batch_items  (one row per input row, valid or not — the row-level
-- error reporting and audit trail the brief asks for, together)
-- ---------------------------------------------------------------------------
CREATE TABLE `import_batch_items` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `import_batch_id`     BIGINT UNSIGNED NOT NULL,
    `row_number`          SMALLINT UNSIGNED NOT NULL,
    `sku`                 VARCHAR(50)     NULL,
    `is_valid`            TINYINT(1)      NOT NULL DEFAULT 0,
    `validation_errors`   JSON            NULL,
    `action`              ENUM('created', 'updated', 'skipped') NULL,
    `product_variant_id`  BIGINT UNSIGNED NULL,
    `raw_data`            JSON            NOT NULL COMMENT 'The original row, for traceability',
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
    UNIQUE KEY `uq_import_batch_items_uuid` (`uuid`),
    KEY `idx_import_batch_items_batch` (`import_batch_id`, `row_number`),
    KEY `idx_import_batch_items_sku` (`sku`),
    CONSTRAINT `fk_import_batch_items_batch`
        FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_import_batch_items_variant`
        FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('017_import_history', 17, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
