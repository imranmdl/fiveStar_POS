-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 048 - Dynamic Offers: minimum quantity and a usage limit
--
--  Gap found while extending the offer engine for the Dynamic Offers &
--  Smart Discounts brief: `offers` already has min_order_value (a rupee
--  floor) but nothing to require a minimum QUANTITY of eligible items, and
--  nothing capping how many times an automatic offer may be used in total —
--  `coupons` has both (per_customer_limit/total_usage_limit), `offers` had
--  neither.
--
--  min_quantity: checked against the summed quantity of eligible cart/POS
--  lines, the same lines eligibleSubtotal() already sums for min_order_value.
--  Not needed for a `free_items` (BOGO) offer — buy_quantity already is a
--  minimum quantity for that type — so it stays NULL there by convention,
--  same as the existing chk_offers_quantities_only_for_free_items pattern.
--
--  usage_limit: unlike coupons, offers have no per-use "redemption" event
--  table to increment a counter on (an offer is applied silently, not
--  entered as a code) — so this is enforced with a live COUNT across
--  `orders.offer_id` (online) and `pos_sale_items.applied_offer_code`
--  (POS) rather than a maintained counter column. NULL means unlimited,
--  matching coupons.total_usage_limit's own convention.
--
--  Additive only.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `offers`
    ADD COLUMN `min_quantity` SMALLINT UNSIGNED NULL
        COMMENT 'Minimum combined quantity of eligible items required to qualify; NULL = no minimum'
        AFTER `min_order_value`,
    ADD COLUMN `usage_limit` INT UNSIGNED NULL
        COMMENT 'Total times this offer may be used across every customer, online + POS combined; NULL = unlimited'
        AFTER `priority`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('048_offer_quantity_and_usage_limit', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
