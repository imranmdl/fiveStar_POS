-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 007 - Staff Operations, Commission and Bulk Orders
--
--  staff_profiles, order_assignments, packing_slips, commission_rules,
--  commission_entries, commission_settlements, bulk_order_enquiries,
--  bulk_order_quotes, bulk_order_quote_items.
--
--  Three decisions are baked into this schema:
--
--  COMMISSION IS EARNED AT DELIVERY, NOT AT PLACEMENT. An order that is
--  cancelled, refunded or returned to origin has cost the business money, and
--  paying a fulfilment commission on it would reward the wrong outcome. This
--  mirrors the referral rule from Phase 4, and for the same reason.
--
--  A COMMISSION AMOUNT IS NEVER EDITED. Status moves through a workflow
--  (pending, approved, settled) because that is process, not money — but the
--  amount is fixed at accrual and a correction is a new entry with the opposite
--  sign. Editing an accrued amount destroys the only record of what someone was
--  originally told they had earned.
--
--  AN APPROVED BULK QUOTE BECOMES AN ORDINARY ORDER. It does not get its own
--  payment path, its own dispatch path or its own exemptions. BR-003, BR-004,
--  BR-005 and BR-007 apply to a fifty-kilo wholesale order exactly as they apply
--  to a 200g pouch, because the moment a B2B flow gets a shortcut is the moment
--  unpaid goods start leaving the building.
--
--  MySQL 8.0.16+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- staff_profiles
--
-- The employment side of a staff user: who they report to, how much work they
-- can hold, whether they are on shift. Kept out of `users` because it applies
-- to three roles out of four and would be null for every customer — and the
-- customer table is the one that grows to six figures.
-- ---------------------------------------------------------------------------
CREATE TABLE `staff_profiles` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `user_id`               BIGINT UNSIGNED NOT NULL,
    `employee_code`         VARCHAR(30)     NOT NULL,
    `reports_to_user_id`    BIGINT UNSIGNED NULL
                            COMMENT 'Supervisor for an executive; administrator for a supervisor',
    `department`            VARCHAR(60)     NULL,
    `shift_start`           TIME            NULL,
    `shift_end`             TIME            NULL,
    -- Assignment capacity. An executive already holding their limit is skipped
    -- by auto-assignment: piling work onto whoever is nominally "next" is how
    -- orders sit untouched for a day.
    `max_concurrent_orders` SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    `is_available`          TINYINT(1)      NOT NULL DEFAULT 1,
    `unavailable_reason`    VARCHAR(255)    NULL,
    `unavailable_until`     DATETIME        NULL,
    `joined_date`           DATE            NULL,
    `notes`                 VARCHAR(500)    NULL,
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
    UNIQUE KEY `uq_staff_profiles_uuid` (`uuid`),
    UNIQUE KEY `uq_staff_profiles_user` (`user_id`),
    UNIQUE KEY `uq_staff_profiles_employee_code` (`employee_code`),
    KEY `idx_staff_profiles_reports_to` (`reports_to_user_id`),
    KEY `idx_staff_profiles_available` (`is_available`, `is_active`, `is_deleted`),
    CONSTRAINT `chk_staff_capacity_positive`
        CHECK (`max_concurrent_orders` > 0),
    -- Nobody reports to themselves. Cheap to prevent, awkward to unpick once a
    -- reporting query has gone into an infinite loop.
    CONSTRAINT `chk_staff_not_own_manager`
        CHECK (`reports_to_user_id` IS NULL OR `reports_to_user_id` <> `user_id`),
    CONSTRAINT `fk_staff_profiles_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_staff_profiles_manager`
        FOREIGN KEY (`reports_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_assignments
--
-- History, not a single current value: reassignment happens (illness, shift
-- end, escalation) and knowing an order changed hands twice before shipping is
-- exactly what a post-mortem needs.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_assignments` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `order_id`            BIGINT UNSIGNED NOT NULL,
    `assigned_to_user_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NULL
                          COMMENT 'NULL when assigned automatically',
    `status`              ENUM('assigned','accepted','completed','reassigned','released')
                          NOT NULL DEFAULT 'assigned',
    `assignment_method`   ENUM('auto','manual','self') NOT NULL DEFAULT 'auto',
    `assigned_date`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `accepted_date`       DATETIME        NULL,
    `completed_date`      DATETIME        NULL,
    `released_date`       DATETIME        NULL,
    `release_reason`      VARCHAR(255)    NULL,
    -- The clock a supervisor is actually managing against.
    `due_date`            DATETIME        NULL,
    `notes`               VARCHAR(500)    NULL,
    `created_by`          BIGINT UNSIGNED NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`          BIGINT UNSIGNED NULL,
    `updated_date`        DATETIME        NULL,
    `deleted_by`          BIGINT UNSIGNED NULL,
    `deleted_date`        DATETIME        NULL,
    `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`          TINYINT(1)      NOT NULL DEFAULT 0,
    `version`             INT UNSIGNED    NOT NULL DEFAULT 1,

    -- Exactly one live assignment per order, enforced by the database rather
    -- than by remembering to check. The generated column is NULL for finished
    -- assignments, and a unique index ignores NULLs, so history accumulates
    -- freely while only one row can ever be open.
    `active_order_id`     BIGINT UNSIGNED
                          GENERATED ALWAYS AS (
                              CASE WHEN `status` IN ('assigned','accepted') AND `is_deleted` = 0
                                   THEN `order_id` END
                          ) STORED,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_assignments_uuid` (`uuid`),
    UNIQUE KEY `uq_order_assignments_active` (`active_order_id`),
    KEY `idx_order_assignments_order` (`order_id`),
    KEY `idx_order_assignments_assignee` (`assigned_to_user_id`, `status`),
    KEY `idx_order_assignments_due` (`status`, `due_date`),
    -- RESTRICT, not CASCADE: `order_id` is the base of the STORED generated
    -- column above, and MySQL forbids a referential action that would modify
    -- it. Correct semantics anyway, since orders are soft-deleted.
    CONSTRAINT `fk_order_assignments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_order_assignments_assignee`
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_order_assignments_assigner`
        FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- packing_slips
--
-- Reprints are counted rather than blocked. A jammed printer is not fraud, but
-- a slip printed nine times is worth a supervisor noticing.
-- ---------------------------------------------------------------------------
CREATE TABLE `packing_slips` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `slip_number`       VARCHAR(30)     NOT NULL,
    `order_id`          BIGINT UNSIGNED NOT NULL,
    `generated_by`      BIGINT UNSIGNED NULL,
    `generated_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `print_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_printed_date` DATETIME        NULL,
    `item_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `unit_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_weight_grams` INT UNSIGNED   NOT NULL DEFAULT 0,
    `payload`           JSON            NOT NULL COMMENT 'Snapshot of what was picked',
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
    UNIQUE KEY `uq_packing_slips_uuid` (`uuid`),
    UNIQUE KEY `uq_packing_slips_number` (`slip_number`),
    KEY `idx_packing_slips_order` (`order_id`),
    CONSTRAINT `fk_packing_slips_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_packing_slips_generator`
        FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- commission_rules
-- ---------------------------------------------------------------------------
CREATE TABLE `commission_rules` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `code`                VARCHAR(40)     NOT NULL,
    `name`                VARCHAR(120)    NOT NULL,
    `description`         VARCHAR(500)    NULL,
    `scope`               ENUM('executive_fulfilment','supervisor_override','campaign')
                          NOT NULL DEFAULT 'executive_fulfilment',
    `calculation`         ENUM('flat_per_order','percentage_of_order','tiered_by_volume')
                          NOT NULL DEFAULT 'flat_per_order',
    `flat_amount`         DECIMAL(10, 2)  NULL,
    `percentage`          DECIMAL(5, 2)   NULL,
    `tiers`               JSON            NULL
                          COMMENT '[{"min_orders":0,"amount":10},{"min_orders":50,"amount":15}]',
    -- Caps keep a percentage rule from paying out absurdly on one large order.
    `min_order_value`     DECIMAL(12, 2)  NULL,
    `max_commission`      DECIMAL(10, 2)  NULL,
    `applies_to_role`     VARCHAR(30)     NULL COMMENT 'Role code, or NULL for all staff',
    `effective_from`      DATE            NULL,
    `effective_until`     DATE            NULL,
    `priority`            SMALLINT        NOT NULL DEFAULT 100
                          COMMENT 'Lowest wins when several rules match',
    `status`              ENUM('draft','active','paused','expired') NOT NULL DEFAULT 'draft',
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
    UNIQUE KEY `uq_commission_rules_uuid` (`uuid`),
    UNIQUE KEY `uq_commission_rules_code` (`code`),
    KEY `idx_commission_rules_lookup` (`status`, `scope`, `priority`),
    CONSTRAINT `chk_commission_percentage_range`
        CHECK (`percentage` IS NULL OR (`percentage` > 0 AND `percentage` <= 100)),
    CONSTRAINT `chk_commission_flat_positive`
        CHECK (`flat_amount` IS NULL OR `flat_amount` >= 0),
    -- A rule must carry the figure its own calculation needs. Without this a
    -- percentage rule with a null percentage accrues zero on every order and
    -- nobody notices until payday.
    CONSTRAINT `chk_commission_has_its_figure`
        CHECK (
            (`calculation` = 'flat_per_order'      AND `flat_amount` IS NOT NULL) OR
            (`calculation` = 'percentage_of_order' AND `percentage`  IS NOT NULL) OR
            (`calculation` = 'tiered_by_volume'    AND `tiers`       IS NOT NULL)
        )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- commission_settlements
--
-- A payout batch. Created before its entries are attached, so an entry can
-- point at the settlement that paid it.
-- ---------------------------------------------------------------------------
CREATE TABLE `commission_settlements` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `settlement_number` VARCHAR(30)     NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `period_start`      DATE            NOT NULL,
    `period_end`        DATE            NOT NULL,
    `entry_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `gross_amount`      DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `deductions`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `net_amount`        DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `status`            ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
    `approved_by`       BIGINT UNSIGNED NULL,
    `approved_date`     DATETIME        NULL,
    `paid_date`         DATETIME        NULL,
    `payment_reference` VARCHAR(120)    NULL,
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
    UNIQUE KEY `uq_commission_settlements_uuid` (`uuid`),
    UNIQUE KEY `uq_commission_settlements_number` (`settlement_number`),
    -- One settlement per person per period. Running the batch twice must not
    -- pay twice.
    UNIQUE KEY `uq_settlement_user_period` (`user_id`, `period_start`, `period_end`),
    KEY `idx_commission_settlements_status` (`status`, `period_end`),
    CONSTRAINT `chk_settlement_period_order`
        CHECK (`period_end` >= `period_start`),
    CONSTRAINT `chk_settlement_net_adds_up`
        CHECK (`net_amount` = `gross_amount` - `deductions`),
    CONSTRAINT `fk_commission_settlements_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_commission_settlements_approver`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- commission_entries
--
-- The accrual ledger. `amount` is written once and never updated — a correction
-- is a second row with the opposite sign, carrying `reverses_entry_id`. Status
-- moves through a workflow because that is process rather than money.
-- ---------------------------------------------------------------------------
CREATE TABLE `commission_entries` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `user_id`            BIGINT UNSIGNED NOT NULL,
    `order_id`           BIGINT UNSIGNED NULL,
    `rule_id`            BIGINT UNSIGNED NULL,
    `settlement_id`      BIGINT UNSIGNED NULL,
    `reverses_entry_id`  BIGINT UNSIGNED NULL
                         COMMENT 'Set on a correcting entry; the original stays untouched',
    `scope`              VARCHAR(40)     NOT NULL,
    `amount`             DECIMAL(10, 2)  NOT NULL
                         COMMENT 'Negative on a reversal',
    `order_value`        DECIMAL(12, 2)  NULL COMMENT 'Basis at the time of accrual',
    `calculation_note`   VARCHAR(255)    NOT NULL
                         COMMENT 'How this figure was reached, in words',
    `status`             ENUM('pending','approved','settled','cancelled')
                         NOT NULL DEFAULT 'pending',
    `accrued_date`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_by`        BIGINT UNSIGNED NULL,
    `approved_date`      DATETIME        NULL,
    -- One accrual per person per order per scope: replaying the delivery
    -- webhook must not pay twice.
    `idempotency_key`    VARCHAR(160)    NOT NULL,
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
    UNIQUE KEY `uq_commission_entries_uuid` (`uuid`),
    UNIQUE KEY `uq_commission_entries_idempotency` (`idempotency_key`),
    KEY `idx_commission_entries_user` (`user_id`, `status`),
    KEY `idx_commission_entries_order` (`order_id`),
    KEY `idx_commission_entries_settlement` (`settlement_id`),
    KEY `idx_commission_entries_unsettled` (`status`, `accrued_date`),
    CONSTRAINT `fk_commission_entries_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_commission_entries_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_commission_entries_rule`
        FOREIGN KEY (`rule_id`) REFERENCES `commission_rules` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_commission_entries_settlement`
        FOREIGN KEY (`settlement_id`) REFERENCES `commission_settlements` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_commission_entries_reversal`
        FOREIGN KEY (`reverses_entry_id`) REFERENCES `commission_entries` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The amount is immutable. Enforced by a trigger rather than convention,
-- because the whole value of an accrual ledger is that yesterday's figure still
-- reads the same today.
DELIMITER $$
CREATE TRIGGER `trg_commission_entries_amount_immutable`
BEFORE UPDATE ON `commission_entries`
FOR EACH ROW
BEGIN
    IF NEW.`amount` <> OLD.`amount` THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'commission_entries.amount is immutable: post a reversing entry instead';
    END IF;

    IF NEW.`order_id` <> OLD.`order_id` OR NEW.`user_id` <> OLD.`user_id` THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'A commission entry cannot be moved to another user or order';
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------------
-- bulk_order_enquiries
--
-- B2B starts as a conversation, not a cart: quantities are negotiated, prices
-- are not on the website, and a GSTIN has to be captured for the invoice.
-- ---------------------------------------------------------------------------
CREATE TABLE `bulk_order_enquiries` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `enquiry_number`        VARCHAR(30)     NOT NULL,
    `user_id`               BIGINT UNSIGNED NULL COMMENT 'NULL for a guest enquiry',
    `business_name`         VARCHAR(180)    NOT NULL,
    `gstin`                 VARCHAR(15)     NULL,
    `contact_name`          VARCHAR(120)    NOT NULL,
    `contact_mobile`        VARCHAR(15)     NOT NULL,
    `contact_email`         VARCHAR(180)    NULL,
    `delivery_pincode`      VARCHAR(10)     NULL,
    `delivery_city`         VARCHAR(90)     NULL,
    `delivery_state`        VARCHAR(90)     NULL,
    `requirements`          TEXT            NOT NULL,
    `expected_delivery_date` DATE           NULL,
    `estimated_quantity`    VARCHAR(120)    NULL,
    `estimated_budget`      DECIMAL(12, 2)  NULL,
    `status`                ENUM('new','under_review','quoted','negotiating',
                                 'accepted','converted','declined','expired')
                            NOT NULL DEFAULT 'new',
    `assigned_to_user_id`   BIGINT UNSIGNED NULL,
    `decline_reason`        VARCHAR(500)    NULL,
    `converted_order_id`    BIGINT UNSIGNED NULL,
    `source`                ENUM('web','phone','email','walk_in') NOT NULL DEFAULT 'web',
    `internal_notes`        TEXT            NULL,
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
    UNIQUE KEY `uq_bulk_enquiries_uuid` (`uuid`),
    UNIQUE KEY `uq_bulk_enquiries_number` (`enquiry_number`),
    KEY `idx_bulk_enquiries_status` (`status`, `created_date`),
    KEY `idx_bulk_enquiries_assignee` (`assigned_to_user_id`, `status`),
    KEY `idx_bulk_enquiries_user` (`user_id`),
    CONSTRAINT `fk_bulk_enquiries_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_bulk_enquiries_assignee`
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_bulk_enquiries_order`
        FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- bulk_order_quotes
--
-- Revisions are rows, not edits. A customer who accepts revision 2 must be held
-- to revision 2 even after revision 3 is drafted, and "what did we actually
-- offer them" is the first question in any pricing dispute.
-- ---------------------------------------------------------------------------
CREATE TABLE `bulk_order_quotes` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `quote_number`      VARCHAR(30)     NOT NULL,
    `enquiry_id`        BIGINT UNSIGNED NOT NULL,
    `revision`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `items_subtotal`    DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `discount_amount`   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `delivery_charge`   DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `taxable_value`     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `tax_total`         DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `grand_total`       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    `valid_until`       DATE            NOT NULL,
    `payment_terms`     VARCHAR(255)    NULL DEFAULT '100% advance by UPI before dispatch',
    `delivery_terms`    VARCHAR(255)    NULL,
    `status`            ENUM('draft','sent','accepted','rejected','superseded','expired')
                        NOT NULL DEFAULT 'draft',
    `sent_date`         DATETIME        NULL,
    `responded_date`    DATETIME        NULL,
    `rejection_reason`  VARCHAR(500)    NULL,
    `prepared_by`       BIGINT UNSIGNED NULL,
    `notes`             VARCHAR(1000)   NULL,
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
    UNIQUE KEY `uq_bulk_quotes_uuid` (`uuid`),
    UNIQUE KEY `uq_bulk_quotes_number` (`quote_number`),
    UNIQUE KEY `uq_bulk_quote_revision` (`enquiry_id`, `revision`),
    KEY `idx_bulk_quotes_status` (`status`, `valid_until`),
    CONSTRAINT `chk_bulk_quote_totals_not_negative`
        CHECK (`grand_total` >= 0 AND `items_subtotal` >= 0 AND `discount_amount` >= 0),
    CONSTRAINT `chk_bulk_quote_tax_reconciles`
        CHECK (`taxable_value` + `tax_total` = `grand_total`),
    CONSTRAINT `fk_bulk_quotes_enquiry`
        FOREIGN KEY (`enquiry_id`) REFERENCES `bulk_order_enquiries` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_bulk_quotes_preparer`
        FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- bulk_order_quote_items
-- ---------------------------------------------------------------------------
CREATE TABLE `bulk_order_quote_items` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `quote_id`        BIGINT UNSIGNED NOT NULL,
    `variant_id`      BIGINT UNSIGNED NULL COMMENT 'NULL for a bespoke line',
    `description`     VARCHAR(255)    NOT NULL,
    `sku`             VARCHAR(60)     NULL,
    `quantity`        INT UNSIGNED    NOT NULL,
    `unit_price`      DECIMAL(10, 2)  NOT NULL COMMENT 'Negotiated, GST-inclusive',
    `line_total`      DECIMAL(12, 2)  NOT NULL,
    `gst_rate`        DECIMAL(5, 2)   NOT NULL DEFAULT 5.00,
    `unit_weight_grams` INT UNSIGNED  NOT NULL DEFAULT 0,
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
    UNIQUE KEY `uq_bulk_quote_items_uuid` (`uuid`),
    KEY `idx_bulk_quote_items_quote` (`quote_id`),
    CONSTRAINT `chk_bulk_quote_item_quantity`
        CHECK (`quantity` > 0),
    CONSTRAINT `chk_bulk_quote_item_price`
        CHECK (`unit_price` >= 0),
    CONSTRAINT `fk_bulk_quote_items_quote`
        FOREIGN KEY (`quote_id`) REFERENCES `bulk_order_quotes` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_bulk_quote_items_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_executive_workload` AS
SELECT
    u.`id`   AS `user_id`,
    u.`uuid` AS `user_uuid`,
    u.`full_name`,
    sp.`employee_code`,
    sp.`max_concurrent_orders`,
    sp.`is_available`,
    sp.`reports_to_user_id`,
    COALESCE(SUM(oa.`status` IN ('assigned','accepted')), 0)      AS `open_assignments`,
    COALESCE(SUM(oa.`status` = 'completed'), 0)                   AS `completed_assignments`,
    COALESCE(SUM(oa.`status` = 'completed'
        AND oa.`completed_date` >= CURDATE()), 0)                 AS `completed_today`,
    GREATEST(0, sp.`max_concurrent_orders`
        - COALESCE(SUM(oa.`status` IN ('assigned','accepted')), 0)) AS `remaining_capacity`
FROM `users` u
INNER JOIN `staff_profiles` sp ON sp.`user_id` = u.`id` AND sp.`is_deleted` = 0
LEFT JOIN `order_assignments` oa ON oa.`assigned_to_user_id` = u.`id` AND oa.`is_deleted` = 0
WHERE u.`is_deleted` = 0
GROUP BY u.`id`, u.`uuid`, u.`full_name`, sp.`employee_code`,
         sp.`max_concurrent_orders`, sp.`is_available`, sp.`reports_to_user_id`;

CREATE OR REPLACE VIEW `vw_commission_summary` AS
SELECT
    u.`id`   AS `user_id`,
    u.`uuid` AS `user_uuid`,
    u.`full_name`,
    COUNT(ce.`id`)                                                       AS `entry_count`,
    ROUND(COALESCE(SUM(ce.`amount`), 0), 2)                              AS `total_accrued`,
    ROUND(COALESCE(SUM(CASE WHEN ce.`status` = 'pending'  THEN ce.`amount` ELSE 0 END), 0), 2) AS `pending_amount`,
    ROUND(COALESCE(SUM(CASE WHEN ce.`status` = 'approved' THEN ce.`amount` ELSE 0 END), 0), 2) AS `approved_amount`,
    ROUND(COALESCE(SUM(CASE WHEN ce.`status` = 'settled'  THEN ce.`amount` ELSE 0 END), 0), 2) AS `settled_amount`,
    MAX(ce.`accrued_date`)                                               AS `last_accrual_date`
FROM `users` u
INNER JOIN `commission_entries` ce ON ce.`user_id` = u.`id` AND ce.`is_deleted` = 0
WHERE u.`is_deleted` = 0
GROUP BY u.`id`, u.`uuid`, u.`full_name`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('007_staff_commission_bulk', 7, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
