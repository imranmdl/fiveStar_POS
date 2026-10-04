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

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

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
