-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 003 - Cart, Wishlist and Delivery Pricing
--
--  carts, cart_items, wishlist_items, delivery_zones, delivery_pincode_map,
--  delivery_charge_slabs.
--
--  Design notes:
--
--  * Guest carts are first-class. A cart belongs either to a user or to an
--    anonymous token, never both, and is merged into the user's cart on login.
--
--  * cart_items snapshot the unit price, MRP, GST rate and weight at the moment
--    the line was priced. The snapshot is what makes a price change detectable
--    and reportable to the customer instead of silently changing their total.
--
--  * "One active cart per owner" is enforced by the storage layer using STORED
--    generated columns, not by application convention. Two concurrent requests
--    cannot create two active carts for the same customer.
--
--  * Still no inventory (BR-001). A cart line references a variant and a
--    quantity; nothing is reserved or decremented anywhere.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- delivery_zones  (BR-006: delivery charges are calculated, not fixed)
-- ---------------------------------------------------------------------------
CREATE TABLE `delivery_zones` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `code`            VARCHAR(30)     NOT NULL COMMENT 'e.g. LOCAL, KA, SOUTH, REMOTE',
    `name`            VARCHAR(120)    NOT NULL,
    `sla_min_days`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `sla_max_days`    TINYINT UNSIGNED NOT NULL DEFAULT 7,
    `is_default`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Fallback for unmapped pincodes',
    `is_serviceable`  TINYINT(1)      NOT NULL DEFAULT 1,
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
    UNIQUE KEY `uq_delivery_zones_uuid` (`uuid`),
    UNIQUE KEY `uq_delivery_zones_code` (`code`),
    KEY `idx_delivery_zones_default` (`is_default`, `is_active`),
    CONSTRAINT `chk_delivery_zones_sla`
        CHECK (`sla_max_days` >= `sla_min_days`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- delivery_pincode_map
--
-- One table handles both exact pincodes and broad ranges by storing a prefix.
-- Resolution takes the longest matching prefix, so '560001' beats '560' beats
-- '56'. Adding a single-pincode exception never requires a schema change.
-- ---------------------------------------------------------------------------
CREATE TABLE `delivery_pincode_map` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `zone_id`         BIGINT UNSIGNED NOT NULL,
    `pincode_prefix`  VARCHAR(6)      NOT NULL COMMENT '1-6 digits; longest match wins',
    `label`           VARCHAR(120)    NULL,
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
    UNIQUE KEY `uq_delivery_pincode_map_uuid` (`uuid`),
    UNIQUE KEY `uq_delivery_pincode_prefix` (`pincode_prefix`),
    KEY `idx_delivery_pincode_zone` (`zone_id`),
    CONSTRAINT `chk_pincode_prefix_numeric`
        CHECK (`pincode_prefix` REGEXP '^[1-9][0-9]{0,5}$'),
    CONSTRAINT `fk_delivery_pincode_map_zone`
        FOREIGN KEY (`zone_id`) REFERENCES `delivery_zones` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- delivery_charge_slabs
--
-- Weight bands per zone. `per_extra_kg_amount` covers everything above the
-- heaviest band so there is never a weight the platform cannot quote.
-- ---------------------------------------------------------------------------
CREATE TABLE `delivery_charge_slabs` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `zone_id`                BIGINT UNSIGNED NOT NULL,
    `min_weight_grams`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_weight_grams`       INT UNSIGNED    NULL COMMENT 'NULL = open-ended top band',
    `charge_amount`          DECIMAL(10, 2)  NOT NULL,
    `per_extra_kg_amount`    DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                             COMMENT 'Applied per kg above max_weight_grams on the top band',
    `free_above_order_value` DECIMAL(10, 2)  NULL
                             COMMENT 'Zone override; falls back to the free_delivery_threshold setting',
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
    UNIQUE KEY `uq_delivery_charge_slabs_uuid` (`uuid`),
    UNIQUE KEY `uq_delivery_slab_band` (`zone_id`, `min_weight_grams`),
    KEY `idx_delivery_slab_lookup` (`zone_id`, `min_weight_grams`, `max_weight_grams`),
    CONSTRAINT `chk_slab_band_order`
        CHECK (`max_weight_grams` IS NULL OR `max_weight_grams` > `min_weight_grams`),
    CONSTRAINT `chk_slab_charge_not_negative`
        CHECK (`charge_amount` >= 0 AND `per_extra_kg_amount` >= 0),
    CONSTRAINT `fk_delivery_charge_slabs_zone`
        FOREIGN KEY (`zone_id`) REFERENCES `delivery_zones` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- carts
--
-- `active_owner_user` and `active_owner_guest` are STORED generated columns
-- that are non-NULL only while the cart is live. The unique indexes over them
-- make "at most one active cart per owner" a storage guarantee rather than an
-- application hope — two concurrent add-to-cart requests cannot race into two
-- carts.
-- ---------------------------------------------------------------------------
CREATE TABLE `carts` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `user_id`              BIGINT UNSIGNED NULL COMMENT 'NULL for a guest cart',
    `guest_token_hash`     CHAR(64)        NULL COMMENT 'SHA-256 of the anonymous cart token',
    `status`               ENUM('active','merged','converted','abandoned') NOT NULL DEFAULT 'active',
    `currency_code`        CHAR(3)         NOT NULL DEFAULT 'INR',
    `delivery_pincode`     VARCHAR(10)     NULL COMMENT 'Last pincode used to quote delivery',
    `merged_into_cart_id`  BIGINT UNSIGNED NULL,
    `converted_order_id`   BIGINT UNSIGNED NULL
                           COMMENT 'Set in Phase 5; no FK yet because orders does not exist',
    `last_activity_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`           BIGINT UNSIGNED NULL,
    `created_date`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`           BIGINT UNSIGNED NULL,
    `updated_date`         DATETIME        NULL,
    `deleted_by`           BIGINT UNSIGNED NULL,
    `deleted_date`         DATETIME        NULL,
    `is_active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`           TINYINT(1)      NOT NULL DEFAULT 0,
    `version`              INT UNSIGNED    NOT NULL DEFAULT 1,
    `active_owner_user`    BIGINT UNSIGNED
                           GENERATED ALWAYS AS (IF(`status` = 'active' AND `is_deleted` = 0, `user_id`, NULL)) STORED,
    `active_owner_guest`   CHAR(64)
                           GENERATED ALWAYS AS (IF(`status` = 'active' AND `is_deleted` = 0, `guest_token_hash`, NULL)) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_carts_uuid` (`uuid`),
    UNIQUE KEY `uq_carts_guest_token` (`guest_token_hash`),
    UNIQUE KEY `uq_carts_active_user` (`active_owner_user`),
    UNIQUE KEY `uq_carts_active_guest` (`active_owner_guest`),
    KEY `idx_carts_status` (`status`, `last_activity_date`),
    KEY `idx_carts_merged_into` (`merged_into_cart_id`),
    -- A cart has exactly one owner: a user or a guest token, never neither.
    CONSTRAINT `chk_carts_single_owner`
        CHECK ((`user_id` IS NOT NULL AND `guest_token_hash` IS NULL)
            OR (`user_id` IS NULL AND `guest_token_hash` IS NOT NULL)),
    -- RESTRICT on both actions, deliberately. `user_id` is the base column of the
    -- STORED generated column `active_owner_user`, and MySQL forbids CASCADE or
    -- SET NULL referential actions on such a column; it is also named in
    -- chk_carts_single_owner, which forbids ON UPDATE CASCADE independently.
    --
    -- The semantics are the better ones anyway: users in this system are
    -- soft-deleted, never hard-deleted, so a hard purge of a customer who still
    -- has a cart should fail loudly rather than cascade silently. Delete the
    -- carts first, deliberately, if a purge is genuinely required.
    CONSTRAINT `fk_carts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_carts_merged_into`
        FOREIGN KEY (`merged_into_cart_id`) REFERENCES `carts` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- cart_items
--
-- The `*_snapshot` columns record what the customer was shown. On every read
-- the service compares them against the live price and reports any difference
-- rather than quietly re-totalling.
--
-- UNIQUE (cart_id, variant_id) means a variant appears at most once per cart;
-- adding it again increments the quantity, and save-for-later flips a flag
-- instead of creating a second row.
-- ---------------------------------------------------------------------------
CREATE TABLE `cart_items` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `cart_id`                BIGINT UNSIGNED NOT NULL,
    `product_id`             BIGINT UNSIGNED NOT NULL COMMENT 'Denormalised for reporting joins',
    `variant_id`             BIGINT UNSIGNED NOT NULL,
    `quantity`               SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price_snapshot`    DECIMAL(10, 2)  NOT NULL COMMENT 'Effective price when last priced',
    `unit_mrp_snapshot`      DECIMAL(10, 2)  NOT NULL,
    `gst_rate_snapshot`      DECIMAL(5, 2)   NOT NULL,
    `unit_weight_snapshot`   INT UNSIGNED    NOT NULL COMMENT 'Shipping weight in grams',
    `price_changed_date`     DATETIME        NULL COMMENT 'Last time the live price moved away from the snapshot',
    `is_saved_for_later`     TINYINT(1)      NOT NULL DEFAULT 0,
    `is_gift`                TINYINT(1)      NOT NULL DEFAULT 0,
    `gift_message`           VARCHAR(320)    NULL,
    `notes`                  VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_cart_items_uuid` (`uuid`),
    UNIQUE KEY `uq_cart_item_variant` (`cart_id`, `variant_id`),
    KEY `idx_cart_items_cart` (`cart_id`, `is_deleted`, `is_saved_for_later`),
    KEY `idx_cart_items_variant` (`variant_id`),
    KEY `idx_cart_items_product` (`product_id`),
    CONSTRAINT `chk_cart_items_quantity`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_cart_items_prices`
        CHECK (`unit_price_snapshot` >= 0 AND `unit_mrp_snapshot` >= 0),
    CONSTRAINT `fk_cart_items_cart`
        FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_cart_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_cart_items_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wishlist_items
--
-- Wishlists are per product, not per pack size, with an optional preferred
-- variant. That keeps UNIQUE enforceable (a nullable column in a unique key
-- would let MySQL store unlimited duplicates, since NULLs never collide).
-- ---------------------------------------------------------------------------
CREATE TABLE `wishlist_items` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `user_id`                BIGINT UNSIGNED NOT NULL,
    `product_id`             BIGINT UNSIGNED NOT NULL,
    `preferred_variant_id`   BIGINT UNSIGNED NULL,
    `notify_on_offer`        TINYINT(1)      NOT NULL DEFAULT 1,
    `notify_on_price_drop`   TINYINT(1)      NOT NULL DEFAULT 1,
    `price_at_add`           DECIMAL(10, 2)  NULL COMMENT 'Baseline for price-drop alerts',
    `notes`                  VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_wishlist_items_uuid` (`uuid`),
    UNIQUE KEY `uq_wishlist_user_product` (`user_id`, `product_id`),
    KEY `idx_wishlist_user` (`user_id`, `is_deleted`),
    KEY `idx_wishlist_product` (`product_id`),
    CONSTRAINT `fk_wishlist_items_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wishlist_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wishlist_items_variant`
        FOREIGN KEY (`preferred_variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- View: cart lines joined to live pricing.
--
-- `live_unit_price` comes from vw_variant_pricing, so the cart reads the same
-- resolved offer price as the catalog. `is_purchasable` folds together every
-- reason a line might no longer be orderable, so no caller has to remember the
-- full list.
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
LEFT  JOIN `vw_variant_pricing` vp ON vp.`id` = ci.`variant_id`
WHERE ci.`is_deleted` = 0;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('003_cart_wishlist_delivery', 3, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
