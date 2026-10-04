-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 006 - Delivery Integration and Courier Selection
--
--  couriers, courier_serviceability, courier_rate_cards, shipments,
--  shipment_events, courier_selections, pickup_requests, manifests.
--
--  BR-007  The courier is chosen automatically from weight, destination
--          pincode, order value, availability, cost and SLA. Every decision is
--          recorded in courier_selections with the alternatives that were
--          considered, because "why did this parcel go to Delhivery?" is a
--          question operations will ask and guessing is not an answer.
--
--  BR-008  Every tracking scan is appended to shipment_events and never edited.
--
--  Two things worth knowing before reading further:
--
--  CHARGEABLE WEIGHT IS NOT ACTUAL WEIGHT. Couriers bill on whichever is
--  greater of actual and volumetric weight (L x W x H / 5000 in centimetres).
--  A carton of saffron boxes is light and bulky, so it bills volumetrically;
--  a bag of whole spices bills on the scale. Storing only actual weight means
--  every quote is wrong for half the catalogue.
--
--  COURIER CREDENTIALS ARE NOT IN THIS DATABASE. They live in environment
--  variables. A leaked database backup should not also be a leaked set of
--  shipping accounts capable of booking parcels at the merchant's expense.
--
--  MySQL 8.0.16+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Package dimensions on the variant.
--
-- Nullable on purpose: a retailer will not have measured every SKU on day one,
-- and refusing to ship until they have would be absurd. Where dimensions are
-- missing the selector falls back to a configured default box and records that
-- it did so, so the gap is visible rather than silent.
-- ---------------------------------------------------------------------------
ALTER TABLE `product_variants`
    ADD COLUMN `pack_length_mm` SMALLINT UNSIGNED NULL
        COMMENT 'Outer packed dimensions, for volumetric weight' AFTER `weight_grams`,
    ADD COLUMN `pack_width_mm`  SMALLINT UNSIGNED NULL AFTER `pack_length_mm`,
    ADD COLUMN `pack_height_mm` SMALLINT UNSIGNED NULL AFTER `pack_width_mm`,
    ADD COLUMN `is_fragile`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `pack_height_mm`;

-- ---------------------------------------------------------------------------
-- couriers
--
-- `adapter` selects the integration class. Several couriers can share one
-- adapter: Shiprocket is an aggregator that fronts Delhivery, Blue Dart,
-- XpressBees and DTDC under a single contract, so those arrive as separate
-- rows pointing at the same adapter with a different `channel_code`.
-- ---------------------------------------------------------------------------
CREATE TABLE `couriers` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `code`                  VARCHAR(40)     NOT NULL,
    `name`                  VARCHAR(120)    NOT NULL,
    `adapter`               VARCHAR(40)     NOT NULL
                            COMMENT 'shiprocket, delhivery, sandbox',
    `channel_code`          VARCHAR(60)     NULL
                            COMMENT 'Courier id within an aggregator',
    `logo_url`              VARCHAR(500)    NULL,
    `support_phone`         VARCHAR(20)     NULL,
    `tracking_url_template` VARCHAR(500)    NULL
                            COMMENT 'Contains {awb}',

    -- Eligibility. A courier outside these bounds is never a candidate.
    `min_weight_grams`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_weight_grams`      INT UNSIGNED    NOT NULL DEFAULT 30000,
    `max_order_value`       DECIMAL(12, 2)  NULL
                            COMMENT 'Insurance ceiling; above this the courier is skipped',
    `max_length_mm`         SMALLINT UNSIGNED NULL,
    `max_width_mm`          SMALLINT UNSIGNED NULL,
    `max_height_mm`         SMALLINT UNSIGNED NULL,
    `handles_fragile`       TINYINT(1)      NOT NULL DEFAULT 1,

    -- Scoring inputs (BR-007)
    `priority`              SMALLINT        NOT NULL DEFAULT 100
                            COMMENT 'Lower wins ties; a commercial preference lever',
    `reliability_score`     DECIMAL(5, 2)   NOT NULL DEFAULT 80.00
                            COMMENT '0-100, maintained from delivery outcomes',
    `volumetric_divisor`    SMALLINT UNSIGNED NOT NULL DEFAULT 5000
                            COMMENT 'cm3 per kg; 5000 is the Indian norm, some couriers use 4000',

    `supports_pickup`       TINYINT(1)      NOT NULL DEFAULT 1,
    `supports_label`        TINYINT(1)      NOT NULL DEFAULT 1,
    `supports_manifest`     TINYINT(1)      NOT NULL DEFAULT 1,
    `supports_rto`          TINYINT(1)      NOT NULL DEFAULT 1,

    `is_enabled`            TINYINT(1)      NOT NULL DEFAULT 1
                            COMMENT 'Operational switch, separate from is_active',
    `disabled_reason`       VARCHAR(255)    NULL,
    `settings`              JSON            NULL COMMENT 'Non-secret adapter options only',

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
    UNIQUE KEY `uq_couriers_uuid` (`uuid`),
    UNIQUE KEY `uq_couriers_code` (`code`),
    KEY `idx_couriers_enabled` (`is_enabled`, `is_active`, `is_deleted`),

    CONSTRAINT `chk_couriers_weight_range`
        CHECK (`max_weight_grams` > `min_weight_grams`),
    CONSTRAINT `chk_couriers_reliability_range`
        CHECK (`reliability_score` >= 0 AND `reliability_score` <= 100),
    CONSTRAINT `chk_couriers_divisor_positive`
        CHECK (`volumetric_divisor` > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- courier_serviceability
--
-- Longest-prefix matching, the same approach delivery_pincode_map uses: '5600'
-- covers Bengaluru, '560001' overrides it for one pincode. Storing every
-- serviceable pincode for every courier would be roughly 19,000 x N rows to
-- maintain by hand.
-- ---------------------------------------------------------------------------
CREATE TABLE `courier_serviceability` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `courier_id`       BIGINT UNSIGNED NOT NULL,
    `pincode_prefix`   VARCHAR(6)      NOT NULL,
    `is_serviceable`   TINYINT(1)      NOT NULL DEFAULT 1
                       COMMENT 'A row can exclude as well as include',
    `sla_min_days`     TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `sla_max_days`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `is_express`       TINYINT(1)      NOT NULL DEFAULT 0,
    `notes`            VARCHAR(255)    NULL,
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
    UNIQUE KEY `uq_courier_serviceability_uuid` (`uuid`),
    UNIQUE KEY `uq_courier_prefix` (`courier_id`, `pincode_prefix`),
    KEY `idx_courier_serviceability_prefix` (`pincode_prefix`),
    CONSTRAINT `chk_courier_sla_order`
        CHECK (`sla_max_days` >= `sla_min_days`),
    CONSTRAINT `fk_courier_serviceability_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- courier_rate_cards
--
-- Negotiated rates, used to quote without an API round trip and as the fallback
-- when a live rate call fails. A courier API being slow must not stop a parcel
-- being booked.
-- ---------------------------------------------------------------------------
CREATE TABLE `courier_rate_cards` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `courier_id`           BIGINT UNSIGNED NOT NULL,
    `zone_code`            VARCHAR(30)     NOT NULL
                           COMMENT 'Matches delivery_zones.code',
    `min_weight_grams`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `max_weight_grams`     INT UNSIGNED    NULL COMMENT 'NULL means open-ended',
    `base_charge`          DECIMAL(10, 2)  NOT NULL,
    `per_kg_charge`        DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                           COMMENT 'Applied to weight above min_weight_grams',
    `fuel_surcharge_pct`   DECIMAL(5, 2)   NOT NULL DEFAULT 0.00,
    `handling_charge`      DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
    `effective_from`       DATE            NULL,
    `effective_until`      DATE            NULL,
    `created_by`           BIGINT UNSIGNED NULL,
    `created_date`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`           BIGINT UNSIGNED NULL,
    `updated_date`         DATETIME        NULL,
    `deleted_by`           BIGINT UNSIGNED NULL,
    `deleted_date`         DATETIME        NULL,
    `is_active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`           TINYINT(1)      NOT NULL DEFAULT 0,
    `version`              INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_courier_rate_cards_uuid` (`uuid`),
    UNIQUE KEY `uq_courier_zone_slab` (`courier_id`, `zone_code`, `min_weight_grams`),
    KEY `idx_courier_rate_cards_lookup` (`courier_id`, `zone_code`, `min_weight_grams`),
    CONSTRAINT `chk_courier_rate_slab_order`
        CHECK (`max_weight_grams` IS NULL OR `max_weight_grams` > `min_weight_grams`),
    CONSTRAINT `chk_courier_rate_not_negative`
        CHECK (`base_charge` >= 0 AND `per_kg_charge` >= 0 AND `handling_charge` >= 0),
    CONSTRAINT `fk_courier_rate_cards_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- shipments
--
-- One row per parcel. An order normally has one, but a split consignment has
-- several, which is why this is not a set of columns on `orders`.
-- ---------------------------------------------------------------------------
CREATE TABLE `shipments` (
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                    CHAR(36)        NOT NULL,
    `shipment_number`         VARCHAR(30)     NOT NULL,
    `order_id`                BIGINT UNSIGNED NOT NULL,
    `courier_id`              BIGINT UNSIGNED NOT NULL,

    `awb_number`              VARCHAR(80)     NULL COMMENT 'Airway bill / tracking number',
    `courier_shipment_id`     VARCHAR(120)    NULL COMMENT 'Identifier in the courier system',
    `label_url`               VARCHAR(500)    NULL,
    `label_generated_date`    DATETIME        NULL,
    `manifest_id`             BIGINT UNSIGNED NULL,
    `pickup_request_id`       BIGINT UNSIGNED NULL,

    `status`                  ENUM('created','booked','label_generated','pickup_scheduled',
                                   'picked_up','in_transit','out_for_delivery','delivered',
                                   'failed_delivery','rto_initiated','rto_delivered',
                                   'cancelled','lost')
                              NOT NULL DEFAULT 'created',

    -- Weight and dimensions as declared to the courier. Snapshotted: a variant
    -- remeasured later must not change what was booked.
    `actual_weight_grams`     INT UNSIGNED    NOT NULL,
    `volumetric_weight_grams` INT UNSIGNED    NOT NULL DEFAULT 0,
    `chargeable_weight_grams` INT UNSIGNED    NOT NULL
                              COMMENT 'The greater of actual and volumetric; couriers bill on this',
    `length_mm`               SMALLINT UNSIGNED NULL,
    `width_mm`                SMALLINT UNSIGNED NULL,
    `height_mm`               SMALLINT UNSIGNED NULL,
    `used_default_dimensions` TINYINT(1)      NOT NULL DEFAULT 0
                              COMMENT 'True when a variant had no measurements and a default box was assumed',

    `declared_value`          DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `courier_charge`          DECIMAL(10, 2)  NOT NULL DEFAULT 0.00
                              COMMENT 'What we pay the courier, not what the customer paid',
    `customer_paid_delivery`  DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,

    `promised_sla_min_days`   TINYINT UNSIGNED NULL,
    `promised_sla_max_days`   TINYINT UNSIGNED NULL,
    `estimated_delivery_date` DATE            NULL,

    `pickup_scheduled_date`   DATETIME        NULL,
    `picked_up_date`          DATETIME        NULL,
    `delivered_date`          DATETIME        NULL,
    `delivery_attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_scan_date`          DATETIME        NULL,
    `last_scan_location`      VARCHAR(180)    NULL,
    `last_scan_status`        VARCHAR(120)    NULL,

    `rto_reason`              VARCHAR(255)    NULL,
    `failure_reason`          VARCHAR(255)    NULL,
    `booking_response`        JSON            NULL,

    `created_by`              BIGINT UNSIGNED NULL,
    `created_date`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`              BIGINT UNSIGNED NULL,
    `updated_date`            DATETIME        NULL,
    `deleted_by`              BIGINT UNSIGNED NULL,
    `deleted_date`            DATETIME        NULL,
    `is_active`               TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`              TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                 INT UNSIGNED    NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shipments_uuid` (`uuid`),
    UNIQUE KEY `uq_shipments_number` (`shipment_number`),
    -- An AWB is unique per courier, not globally: two couriers can legitimately
    -- issue the same digits.
    UNIQUE KEY `uq_shipments_courier_awb` (`courier_id`, `awb_number`),
    KEY `idx_shipments_order` (`order_id`),
    KEY `idx_shipments_status` (`status`, `created_date`),
    KEY `idx_shipments_awb` (`awb_number`),
    KEY `idx_shipments_manifest` (`manifest_id`),
    KEY `idx_shipments_pickup` (`pickup_request_id`),

    CONSTRAINT `chk_shipments_weights_positive`
        CHECK (`actual_weight_grams` > 0 AND `chargeable_weight_grams` > 0),
    -- Chargeable weight is by definition the greater of the two. A row that
    -- breaks this would silently undercharge or overcharge every reconciliation.
    CONSTRAINT `chk_shipments_chargeable_is_greater`
        CHECK (`chargeable_weight_grams` >= `actual_weight_grams`
           AND `chargeable_weight_grams` >= `volumetric_weight_grams`),

    CONSTRAINT `fk_shipments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_shipments_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- shipment_events  (BR-008)
--
-- Every scan the courier reports. Append-only in practice; nothing updates or
-- deletes these rows. The unique key on (shipment, courier event id) makes
-- webhook redelivery and polling overlap harmless.
-- ---------------------------------------------------------------------------
CREATE TABLE `shipment_events` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `shipment_id`           BIGINT UNSIGNED NOT NULL,
    `courier_event_id`      VARCHAR(160)    NULL
                            COMMENT 'Courier scan id, when they provide one',
    `event_code`            VARCHAR(60)     NULL,
    `status`                VARCHAR(60)     NOT NULL,
    `title`                 VARCHAR(180)    NOT NULL,
    `description`           VARCHAR(500)    NULL,
    `location`              VARCHAR(180)    NULL,
    `occurred_date`         DATETIME        NOT NULL,
    `is_customer_visible`   TINYINT(1)      NOT NULL DEFAULT 1,
    `source`                ENUM('webhook','poll','manual') NOT NULL DEFAULT 'webhook',
    `raw_payload`           JSON            NULL,
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
    UNIQUE KEY `uq_shipment_events_uuid` (`uuid`),
    UNIQUE KEY `uq_shipment_event_courier_id` (`shipment_id`, `courier_event_id`),
    KEY `idx_shipment_events_shipment` (`shipment_id`, `occurred_date`),
    CONSTRAINT `fk_shipment_events_shipment`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- courier_selections  (BR-007 audit trail)
--
-- Why this courier, and what else was considered.
--
-- Without this, an automatic choice is unarguable: operations cannot tell a
-- bad rate card from a bad algorithm, and a customer complaint about a slow
-- courier has no evidence behind it. The candidates are stored as JSON because
-- they are read as a whole, never queried field by field.
-- ---------------------------------------------------------------------------
CREATE TABLE `courier_selections` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `order_id`             BIGINT UNSIGNED NOT NULL,
    `shipment_id`          BIGINT UNSIGNED NULL,
    `selected_courier_id`  BIGINT UNSIGNED NULL,
    `strategy`             VARCHAR(40)     NOT NULL DEFAULT 'balanced',
    `destination_pincode`  VARCHAR(10)     NOT NULL,
    `chargeable_weight_grams` INT UNSIGNED NOT NULL,
    `order_value`          DECIMAL(12, 2)  NOT NULL,
    `candidates_considered` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `candidates_eligible`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `winning_score`        DECIMAL(6, 3)   NULL,
    `reason`               VARCHAR(500)    NOT NULL,
    `candidates`           JSON            NOT NULL
                           COMMENT 'Every courier considered, its score and why it won or lost',
    `was_manual_override`  TINYINT(1)      NOT NULL DEFAULT 0,
    `overridden_by`        BIGINT UNSIGNED NULL,
    `override_reason`      VARCHAR(255)    NULL,
    `created_by`           BIGINT UNSIGNED NULL,
    `created_date`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`           BIGINT UNSIGNED NULL,
    `updated_date`         DATETIME        NULL,
    `deleted_by`           BIGINT UNSIGNED NULL,
    `deleted_date`         DATETIME        NULL,
    `is_active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`           TINYINT(1)      NOT NULL DEFAULT 0,
    `version`              INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_courier_selections_uuid` (`uuid`),
    KEY `idx_courier_selections_order` (`order_id`),
    KEY `idx_courier_selections_courier` (`selected_courier_id`),
    CONSTRAINT `fk_courier_selections_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_courier_selections_courier`
        FOREIGN KEY (`selected_courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_courier_selections_shipment`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pickup_requests
-- ---------------------------------------------------------------------------
CREATE TABLE `pickup_requests` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `courier_id`            BIGINT UNSIGNED NOT NULL,
    `courier_reference`     VARCHAR(120)    NULL,
    `pickup_date`           DATE            NOT NULL,
    `pickup_slot`           VARCHAR(60)     NULL,
    `shipment_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_weight_grams`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`                ENUM('requested','confirmed','completed','cancelled','failed')
                            NOT NULL DEFAULT 'requested',
    `contact_name`          VARCHAR(120)    NULL,
    `contact_phone`         VARCHAR(20)     NULL,
    `pickup_address`        VARCHAR(500)    NULL,
    `failure_reason`        VARCHAR(255)    NULL,
    `courier_response`      JSON            NULL,
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
    UNIQUE KEY `uq_pickup_requests_uuid` (`uuid`),
    KEY `idx_pickup_requests_courier_date` (`courier_id`, `pickup_date`),
    KEY `idx_pickup_requests_status` (`status`),
    CONSTRAINT `fk_pickup_requests_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- manifests
--
-- The handover document a courier signs when collecting parcels. It is the
-- merchant's proof that a parcel left the building, which matters when a
-- courier later says they never received it.
-- ---------------------------------------------------------------------------
CREATE TABLE `manifests` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `manifest_number`    VARCHAR(30)     NOT NULL,
    `courier_id`         BIGINT UNSIGNED NOT NULL,
    `pickup_request_id`  BIGINT UNSIGNED NULL,
    `manifest_date`      DATE            NOT NULL,
    `shipment_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_weight_grams` INT UNSIGNED    NOT NULL DEFAULT 0,
    `document_url`       VARCHAR(500)    NULL,
    `courier_reference`  VARCHAR(120)    NULL,
    `status`             ENUM('open','closed','handed_over') NOT NULL DEFAULT 'open',
    `handed_over_date`   DATETIME        NULL,
    `handed_over_to`     VARCHAR(120)    NULL,
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
    UNIQUE KEY `uq_manifests_uuid` (`uuid`),
    UNIQUE KEY `uq_manifests_number` (`manifest_number`),
    KEY `idx_manifests_courier_date` (`courier_id`, `manifest_date`),
    CONSTRAINT `fk_manifests_courier`
        FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_manifests_pickup`
        FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Deferred foreign keys: shipments references manifests and pickup_requests,
-- both of which are created after it.
ALTER TABLE `shipments`
    ADD CONSTRAINT `fk_shipments_manifest`
        FOREIGN KEY (`manifest_id`) REFERENCES `manifests` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    ADD CONSTRAINT `fk_shipments_pickup`
        FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_shipment_summary` AS
SELECT
    s.`id`,
    s.`uuid`,
    s.`shipment_number`,
    s.`awb_number`,
    s.`status`,
    s.`chargeable_weight_grams`,
    s.`courier_charge`,
    s.`customer_paid_delivery`,
    s.`estimated_delivery_date`,
    s.`delivered_date`,
    s.`delivery_attempts`,
    s.`last_scan_status`,
    s.`last_scan_location`,
    s.`last_scan_date`,
    s.`created_date`,
    o.`order_number`,
    o.`user_id`,
    o.`ship_city`,
    o.`ship_state`,
    o.`ship_pincode`,
    o.`grand_total`  AS `order_value`,
    c.`code`         AS `courier_code`,
    c.`name`         AS `courier_name`,
    c.`tracking_url_template`
FROM `shipments` s
INNER JOIN `orders`   o ON o.`id` = s.`order_id`
INNER JOIN `couriers` c ON c.`id` = s.`courier_id`
WHERE s.`is_deleted` = 0;

-- Courier performance. Feeds the reliability score that BR-007 scoring uses,
-- so the selector improves from its own outcomes rather than a fixed guess.
CREATE OR REPLACE VIEW `vw_courier_performance` AS
SELECT
    c.`id`   AS `courier_id`,
    c.`code`,
    c.`name`,
    COUNT(s.`id`)                                                          AS `total_shipments`,
    SUM(s.`status` = 'delivered')                                          AS `delivered_count`,
    SUM(s.`status` IN ('rto_initiated','rto_delivered'))                   AS `rto_count`,
    SUM(s.`status` = 'lost')                                               AS `lost_count`,
    SUM(s.`delivery_attempts` > 1)                                         AS `multi_attempt_count`,
    ROUND(AVG(NULLIF(DATEDIFF(s.`delivered_date`, s.`picked_up_date`), NULL)), 2)
                                                                           AS `avg_transit_days`,
    SUM(CASE WHEN s.`status` = 'delivered'
              AND s.`estimated_delivery_date` IS NOT NULL
              AND DATE(s.`delivered_date`) <= s.`estimated_delivery_date`
             THEN 1 ELSE 0 END)                                            AS `on_time_count`,
    ROUND(SUM(s.`courier_charge`), 2)                                      AS `total_courier_cost`,
    ROUND(SUM(s.`customer_paid_delivery`), 2)                              AS `total_delivery_collected`
FROM `couriers` c
LEFT JOIN `shipments` s ON s.`courier_id` = c.`id` AND s.`is_deleted` = 0
WHERE c.`is_deleted` = 0
GROUP BY c.`id`, c.`code`, c.`name`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('006_delivery_couriers', 6, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
