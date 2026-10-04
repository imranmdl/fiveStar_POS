-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 005 - Checkout, Orders and UPI Payment
--
--  orders, order_items, order_tax_lines, order_status_history,
--  payments, payment_events, refunds, numbering_sequences.
--
--  The rules this schema exists to make unbreakable:
--
--  BR-003  An order cannot be confirmed until its OTP is verified.
--  BR-004  Prepaid UPI only. There is no cash-on-delivery column anywhere,
--          because there is no code path that could honour one.
--  BR-005  An order cannot progress past `confirmed` unless payment_status is
--          'paid'. Enforced in OrderStateMachine, and every transition is
--          recorded in order_status_history so the claim is auditable.
--  BR-008  Every order carries a complete, append-only timeline.
--
--  EVERYTHING ON AN ORDER IS A SNAPSHOT. Prices, addresses, product names, tax
--  rates and coupon values are all copied at placement. A product renamed or an
--  address deleted six months later must not alter a historical invoice — under
--  Indian GST rules that invoice has to reproduce exactly as issued.
--
--  MySQL 8.0.16+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- numbering_sequences
--
-- Gapless, atomic counters for order numbers and GST invoice numbers.
--
-- Invoice numbers are a legal requirement, not a convenience: under Indian GST
-- they must be sequential within a financial year and must not have gaps.
-- Deriving them from an AUTO_INCREMENT id would leave holes wherever a row was
-- rolled back, so they come from a counter incremented under a row lock and
-- only ever at the moment payment is confirmed. An unpaid order never consumes
-- an invoice number.
-- ---------------------------------------------------------------------------
CREATE TABLE `numbering_sequences` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `sequence_key`     VARCHAR(60)     NOT NULL COMMENT 'e.g. order:2026-27, invoice:2026-27',
    `purpose`          ENUM('order','invoice') NOT NULL,
    `prefix`           VARCHAR(12)     NOT NULL,
    `financial_year`   CHAR(7)         NOT NULL COMMENT 'Indian FY, e.g. 2026-27',
    `last_number`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_numbering_sequences_uuid` (`uuid`),
    UNIQUE KEY `uq_numbering_sequences_key` (`sequence_key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- orders
-- ---------------------------------------------------------------------------
CREATE TABLE `orders` (
    `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                        CHAR(36)        NOT NULL,
    `order_number`                VARCHAR(30)     NOT NULL,
    `user_id`                     BIGINT UNSIGNED NOT NULL,
    `cart_id`                     BIGINT UNSIGNED NULL COMMENT 'Source cart, for support queries',

    `status`                      ENUM('created','awaiting_payment','confirmed','packed',
                                       'ready_to_ship','assigned','shipped','out_for_delivery',
                                       'delivered','cancelled','returned','refunded')
                                  NOT NULL DEFAULT 'created',
    `payment_status`              ENUM('pending','processing','paid','failed',
                                       'refunded','partially_refunded')
                                  NOT NULL DEFAULT 'pending',

    -- Money. Every figure copied from the PriceBreakdown that the customer saw.
    `currency_code`               CHAR(3)         NOT NULL DEFAULT 'INR',
    `items_mrp_total`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `items_subtotal`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `product_discount`            DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `order_discount`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `order_surcharge`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `delivery_charge`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `delivery_charge_before_waiver` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `delivery_discount`           DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `taxable_value`               DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `tax_total`                   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `grand_total`                 DECIMAL(12, 2)  NOT NULL,
    -- Wallet credit is a TENDER, not a discount: it reduces amount_payable and
    -- leaves grand_total and tax_total untouched, so GST stays correct.
    `wallet_applied`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `amount_payable`              DECIMAL(12, 2)  NOT NULL,
    `amount_paid`                 DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `amount_refunded`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `total_savings`               DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,

    -- Promotion snapshot
    `coupon_id`                   BIGINT UNSIGNED NULL,
    `coupon_code`                 VARCHAR(30)     NULL,
    `coupon_discount`             DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `offer_id`                    BIGINT UNSIGNED NULL,
    `offer_code`                  VARCHAR(40)     NULL,
    `offer_discount`              DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,

    -- Shipping address SNAPSHOT. Never a foreign key to user_addresses: a
    -- customer editing or deleting an address must not rewrite past orders.
    `ship_name`                   VARCHAR(120)    NOT NULL,
    `ship_mobile`                 VARCHAR(15)     NOT NULL,
    `ship_alternate_mobile`       VARCHAR(15)     NULL,
    `ship_address_line1`          VARCHAR(180)    NOT NULL,
    `ship_address_line2`          VARCHAR(180)    NULL,
    `ship_landmark`               VARCHAR(120)    NULL,
    `ship_city`                   VARCHAR(90)     NOT NULL,
    `ship_state`                  VARCHAR(90)     NOT NULL,
    `ship_pincode`                VARCHAR(10)     NOT NULL,
    `ship_country`                VARCHAR(60)     NOT NULL DEFAULT 'India',
    `source_address_id`           BIGINT UNSIGNED NULL COMMENT 'Informational only; may be deleted later',

    -- Delivery
    `delivery_zone_code`          VARCHAR(30)     NULL,
    `delivery_sla_min_days`       TINYINT UNSIGNED NULL,
    `delivery_sla_max_days`       TINYINT UNSIGNED NULL,
    `expected_delivery_date`      DATE            NULL,
    `delivery_slot`               VARCHAR(60)     NULL,
    `delivery_instructions`       VARCHAR(500)    NULL,
    `total_weight_grams`          INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Courier fields, populated in Phase 6 (BR-007)
    `courier_code`                VARCHAR(40)     NULL,
    `courier_name`                VARCHAR(120)    NULL,
    `tracking_number`             VARCHAR(80)     NULL,
    `tracking_url`                VARCHAR(500)    NULL,
    `shipped_date`                DATETIME        NULL,

    `is_gift`                     TINYINT(1)      NOT NULL DEFAULT 0,
    `gift_message`                VARCHAR(320)    NULL,

    -- BR-003: OTP verification before confirmation
    `otp_verified`                TINYINT(1)      NOT NULL DEFAULT 0,
    `otp_verified_date`           DATETIME        NULL,

    -- GST invoice, assigned only when payment is confirmed
    `invoice_number`              VARCHAR(30)     NULL,
    `invoice_date`                DATETIME        NULL,
    `invoice_financial_year`      CHAR(7)         NULL,

    -- Lifecycle
    `placed_date`                 DATETIME        NULL,
    `confirmed_date`              DATETIME        NULL,
    `delivered_date`              DATETIME        NULL,
    `cancelled_date`              DATETIME        NULL,
    `cancelled_by`                BIGINT UNSIGNED NULL,
    `cancellation_reason`         VARCHAR(255)    NULL,
    `expires_date`                DATETIME        NULL
                                  COMMENT 'Unpaid orders expire, releasing coupon and wallet credit',

    `customer_note`               VARCHAR(500)    NULL,
    `internal_note`               VARCHAR(1000)   NULL,
    `placed_ip`                   VARCHAR(45)     NULL,
    `placed_channel`              ENUM('web','android','ios','admin') NOT NULL DEFAULT 'web',

    `created_by`                  BIGINT UNSIGNED NULL,
    `created_date`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`                  BIGINT UNSIGNED NULL,
    `updated_date`                DATETIME        NULL,
    `deleted_by`                  BIGINT UNSIGNED NULL,
    `deleted_date`                DATETIME        NULL,
    `is_active`                   TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`                  TINYINT(1)      NOT NULL DEFAULT 0,
    `version`                     INT UNSIGNED    NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_uuid` (`uuid`),
    UNIQUE KEY `uq_orders_number` (`order_number`),
    UNIQUE KEY `uq_orders_invoice_number` (`invoice_number`),
    KEY `idx_orders_user` (`user_id`, `created_date`),
    KEY `idx_orders_status` (`status`, `created_date`),
    KEY `idx_orders_payment_status` (`payment_status`, `created_date`),
    KEY `idx_orders_expiry` (`status`, `expires_date`),
    KEY `idx_orders_pincode` (`ship_pincode`),
    KEY `idx_orders_tracking` (`tracking_number`),
    KEY `idx_orders_coupon` (`coupon_id`),

    CONSTRAINT `chk_orders_totals_not_negative`
        CHECK (`grand_total` >= 0 AND `amount_payable` >= 0 AND `wallet_applied` >= 0
           AND `amount_paid` >= 0 AND `amount_refunded` >= 0),
    -- Wallet credit can never exceed the order value.
    CONSTRAINT `chk_orders_wallet_within_total`
        CHECK (`wallet_applied` <= `grand_total`),
    -- The tender split must always add back up to the order value.
    CONSTRAINT `chk_orders_payable_split`
        CHECK (`amount_payable` = `grand_total` - `wallet_applied`),

    CONSTRAINT `fk_orders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_orders_cart`
        FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_orders_coupon`
        FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_items
--
-- Product identity is snapshotted alongside the foreign key. The FK is for
-- reporting joins; the snapshot columns are what the invoice reproduces.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_items` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `order_id`              BIGINT UNSIGNED NOT NULL,
    `product_id`            BIGINT UNSIGNED NOT NULL,
    `variant_id`            BIGINT UNSIGNED NOT NULL,

    `product_name`          VARCHAR(180)    NOT NULL,
    `variant_name`          VARCHAR(120)    NOT NULL,
    `sku`                   VARCHAR(60)     NOT NULL,
    `brand`                 VARCHAR(120)    NULL,
    `hsn_code`              VARCHAR(12)     NULL COMMENT 'Required on a GST invoice',

    `quantity`              SMALLINT UNSIGNED NOT NULL,
    `unit_mrp`              DECIMAL(10, 2)  NOT NULL,
    `unit_price`            DECIMAL(10, 2)  NOT NULL,
    `line_mrp`              DECIMAL(12, 2)  NOT NULL,
    `line_subtotal`         DECIMAL(12, 2)  NOT NULL,
    `product_discount`      DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `apportioned_discount`  DECIMAL(12, 2)  NOT NULL DEFAULT 0.00
                            COMMENT 'Share of the order-level discount carried by this line',
    `line_payable`          DECIMAL(12, 2)  NOT NULL,
    `taxable_value`         DECIMAL(12, 2)  NOT NULL,
    `tax_amount`            DECIMAL(12, 2)  NOT NULL,
    `gst_rate`              DECIMAL(5, 2)   NOT NULL,
    `line_weight_grams`     INT UNSIGNED    NOT NULL DEFAULT 0,

    `is_gift`               TINYINT(1)      NOT NULL DEFAULT 0,
    `gift_message`          VARCHAR(320)    NULL,

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
    UNIQUE KEY `uq_order_items_uuid` (`uuid`),
    UNIQUE KEY `uq_order_item_variant` (`order_id`, `variant_id`),
    KEY `idx_order_items_order` (`order_id`),
    KEY `idx_order_items_product` (`product_id`),
    KEY `idx_order_items_variant` (`variant_id`),

    CONSTRAINT `chk_order_items_quantity`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_order_items_tax_reconciles`
        CHECK (`taxable_value` + `tax_amount` = `line_payable`),

    CONSTRAINT `fk_order_items_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_order_items_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_tax_lines
--
-- GST summary per rate. An invoice for a cart mixing 5% spices and 12% nuts
-- needs both lines shown separately; recomputing that later from order_items
-- would risk disagreeing with the issued invoice.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_tax_lines` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `order_id`        BIGINT UNSIGNED NOT NULL,
    `gst_rate`        DECIMAL(5, 2)   NOT NULL,
    `taxable_value`   DECIMAL(12, 2)  NOT NULL,
    `tax_amount`      DECIMAL(12, 2)  NOT NULL,
    -- Intra-state sales split GST into CGST + SGST; inter-state use IGST.
    `cgst_amount`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `sgst_amount`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `igst_amount`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
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
    UNIQUE KEY `uq_order_tax_lines_uuid` (`uuid`),
    UNIQUE KEY `uq_order_tax_rate` (`order_id`, `gst_rate`),
    CONSTRAINT `chk_order_tax_split`
        CHECK (`cgst_amount` + `sgst_amount` + `igst_amount` = `tax_amount`),
    CONSTRAINT `fk_order_tax_lines_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_status_history  (BR-008)
--
-- Append-only in practice: nothing in the codebase updates or deletes a row.
-- `is_customer_visible` separates the tracking page from the internal audit
-- trail, so operational notes never leak to a customer.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_status_history` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `order_id`              BIGINT UNSIGNED NOT NULL,
    `from_status`           VARCHAR(30)     NULL,
    `to_status`             VARCHAR(30)     NOT NULL,
    `payment_status`        VARCHAR(30)     NULL,
    `title`                 VARCHAR(120)    NOT NULL,
    `note`                  VARCHAR(500)    NULL,
    `is_customer_visible`   TINYINT(1)      NOT NULL DEFAULT 1,
    `changed_by`            BIGINT UNSIGNED NULL COMMENT 'NULL means the system, e.g. a webhook',
    `changed_by_role`       VARCHAR(30)     NULL,
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
    UNIQUE KEY `uq_order_status_history_uuid` (`uuid`),
    KEY `idx_order_status_history_order` (`order_id`, `created_date`),
    CONSTRAINT `fk_order_status_history_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payments
--
-- One row per attempt. A customer who fails UPI twice and succeeds on the third
-- try leaves three rows, which is what a payment dispute needs.
-- ---------------------------------------------------------------------------
CREATE TABLE `payments` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                   CHAR(36)        NOT NULL,
    `order_id`               BIGINT UNSIGNED NOT NULL,
    `user_id`                BIGINT UNSIGNED NOT NULL,
    `gateway`                VARCHAR(40)     NOT NULL COMMENT 'razorpay, cashfree, sandbox',
    `gateway_order_id`       VARCHAR(120)    NULL,
    `gateway_payment_id`     VARCHAR(120)    NULL,
    `attempt_number`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `amount`                 DECIMAL(12, 2)  NOT NULL,
    `currency_code`          CHAR(3)         NOT NULL DEFAULT 'INR',
    `status`                 ENUM('created','pending','authorized','captured',
                                  'failed','cancelled','refunded','partially_refunded')
                             NOT NULL DEFAULT 'created',
    -- BR-004: UPI only. The column exists to record which UPI app was used.
    `method`                 VARCHAR(30)     NOT NULL DEFAULT 'upi',
    `upi_vpa`                VARCHAR(120)    NULL,
    `upi_transaction_id`     VARCHAR(120)    NULL,
    `signature_verified`     TINYINT(1)      NOT NULL DEFAULT 0
                             COMMENT 'BR-005 depends on this: an unverified payment never confirms an order',
    `failure_code`           VARCHAR(60)     NULL,
    `failure_reason`         VARCHAR(255)    NULL,
    `authorized_date`        DATETIME        NULL,
    `captured_date`          DATETIME        NULL,
    `failed_date`            DATETIME        NULL,
    `expires_date`           DATETIME        NULL,
    `checkout_payload`       JSON            NULL COMMENT 'What was handed to the client',
    `gateway_response`       JSON            NULL,
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
    UNIQUE KEY `uq_payments_uuid` (`uuid`),
    -- The same gateway payment can never be recorded twice, whatever a retried
    -- webhook or a double callback does.
    UNIQUE KEY `uq_payments_gateway_payment` (`gateway`, `gateway_payment_id`),
    UNIQUE KEY `uq_payments_attempt` (`order_id`, `attempt_number`),
    KEY `idx_payments_order` (`order_id`, `status`),
    KEY `idx_payments_gateway_order` (`gateway`, `gateway_order_id`),
    KEY `idx_payments_status` (`status`, `created_date`),
    CONSTRAINT `chk_payments_amount_positive`
        CHECK (`amount` > 0),
    CONSTRAINT `fk_payments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_payments_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payment_events
--
-- Every inbound webhook, stored raw before anything acts on it.
--
-- The UNIQUE key on (gateway, event_id) is what makes webhook handling
-- idempotent: gateways retry aggressively and deliver out of order, and a
-- duplicate "payment captured" must not confirm an order twice or pay a
-- referral reward twice.
-- ---------------------------------------------------------------------------
CREATE TABLE `payment_events` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `gateway`             VARCHAR(40)     NOT NULL,
    `event_id`            VARCHAR(160)    NOT NULL COMMENT 'Gateway event identifier',
    `event_type`          VARCHAR(80)     NOT NULL,
    `gateway_order_id`    VARCHAR(120)    NULL,
    `gateway_payment_id`  VARCHAR(120)    NULL,
    `order_id`            BIGINT UNSIGNED NULL,
    `signature_valid`     TINYINT(1)      NOT NULL DEFAULT 0,
    `payload`             JSON            NOT NULL,
    `processed`           TINYINT(1)      NOT NULL DEFAULT 0,
    `processed_date`      DATETIME        NULL,
    `processing_error`    VARCHAR(500)    NULL,
    `received_ip`         VARCHAR(45)     NULL,
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
    UNIQUE KEY `uq_payment_events_uuid` (`uuid`),
    UNIQUE KEY `uq_payment_events_event` (`gateway`, `event_id`),
    KEY `idx_payment_events_order` (`order_id`),
    KEY `idx_payment_events_unprocessed` (`processed`, `created_date`),
    KEY `idx_payment_events_gateway_payment` (`gateway_payment_id`),
    CONSTRAINT `fk_payment_events_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- refunds
--
-- A refund can be split between the gateway and the wallet, because part of the
-- order may have been paid with wallet credit in the first place. Wallet credit
-- is returned as a wallet credit, not as cash.
-- ---------------------------------------------------------------------------
CREATE TABLE `refunds` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                 CHAR(36)        NOT NULL,
    `order_id`             BIGINT UNSIGNED NOT NULL,
    `payment_id`           BIGINT UNSIGNED NULL,
    `gateway`              VARCHAR(40)     NULL,
    `gateway_refund_id`    VARCHAR(120)    NULL,
    `total_amount`         DECIMAL(12, 2)  NOT NULL,
    `gateway_amount`       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `wallet_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `reason`               VARCHAR(255)    NOT NULL,
    `status`               ENUM('pending','processing','completed','failed')
                           NOT NULL DEFAULT 'pending',
    `failure_reason`       VARCHAR(255)    NULL,
    `completed_date`       DATETIME        NULL,
    `idempotency_key`      VARCHAR(120)    NULL,
    `gateway_response`     JSON            NULL,
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
    UNIQUE KEY `uq_refunds_uuid` (`uuid`),
    UNIQUE KEY `uq_refunds_idempotency` (`idempotency_key`),
    KEY `idx_refunds_order` (`order_id`, `status`),
    KEY `idx_refunds_payment` (`payment_id`),
    CONSTRAINT `chk_refunds_split_adds_up`
        CHECK (`gateway_amount` + `wallet_amount` = `total_amount`),
    CONSTRAINT `chk_refunds_amount_positive`
        CHECK (`total_amount` > 0),
    CONSTRAINT `fk_refunds_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_refunds_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- carts.converted_order_id can finally carry a foreign key.
-- ---------------------------------------------------------------------------
ALTER TABLE `carts`
    ADD CONSTRAINT `fk_carts_converted_order`
        FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_order_summary` AS
SELECT
    o.`id`,
    o.`uuid`,
    o.`order_number`,
    o.`user_id`,
    o.`status`,
    o.`payment_status`,
    o.`grand_total`,
    o.`wallet_applied`,
    o.`amount_payable`,
    o.`amount_paid`,
    o.`amount_refunded`,
    o.`tax_total`,
    o.`coupon_code`,
    o.`offer_code`,
    o.`ship_city`,
    o.`ship_state`,
    o.`ship_pincode`,
    o.`delivery_zone_code`,
    o.`total_weight_grams`,
    o.`courier_code`,
    o.`tracking_number`,
    o.`invoice_number`,
    o.`otp_verified`,
    o.`placed_date`,
    o.`confirmed_date`,
    o.`delivered_date`,
    o.`expected_delivery_date`,
    o.`created_date`,
    u.`full_name`  AS `customer_name`,
    u.`mobile`     AS `customer_mobile`,
    u.`email`      AS `customer_email`,
    (SELECT COUNT(*) FROM `order_items` oi
      WHERE oi.`order_id` = o.`id` AND oi.`is_deleted` = 0) AS `item_count`,
    (SELECT COALESCE(SUM(oi.`quantity`), 0) FROM `order_items` oi
      WHERE oi.`order_id` = o.`id` AND oi.`is_deleted` = 0) AS `unit_count`
FROM `orders` o
INNER JOIN `users` u ON u.`id` = o.`user_id`
WHERE o.`is_deleted` = 0;

CREATE OR REPLACE VIEW `vw_daily_sales` AS
SELECT
    DATE(o.`confirmed_date`)                       AS `sales_date`,
    COUNT(*)                                       AS `order_count`,
    SUM(o.`grand_total`)                           AS `gross_sales`,
    SUM(o.`taxable_value`)                         AS `taxable_value`,
    SUM(o.`tax_total`)                             AS `tax_collected`,
    SUM(o.`delivery_charge`)                       AS `delivery_collected`,
    SUM(o.`order_discount` + o.`product_discount`) AS `discount_given`,
    SUM(o.`wallet_applied`)                        AS `wallet_redeemed`,
    SUM(o.`amount_payable`)                        AS `collected_online`,
    SUM(o.`amount_refunded`)                       AS `refunded`
FROM `orders` o
WHERE o.`is_deleted` = 0
  AND o.`confirmed_date` IS NOT NULL
  AND o.`status` <> 'cancelled'
GROUP BY DATE(o.`confirmed_date`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('005_orders_payments', 5, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
