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

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

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
