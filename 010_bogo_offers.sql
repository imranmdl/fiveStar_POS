-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 010 - Buy X Get Y offers
--
--  Adds "buy 2 get 1 free", "buy 1 get 1", "buy 3 get 5" and similar to the
--  existing offer engine.
--
--  THE FREE ITEM IS EXPRESSED AS A DISCOUNT, NOT AS AN EXTRA LINE.
--
--  The obvious implementation adds a zero-priced line to the order. It is also
--  wrong for this platform, for three reasons:
--
--    1. GST. Indian MRP is tax-inclusive and tax is EXTRACTED from the price. A
--       zero-priced line has no taxable value, so the tax on the units actually
--       paid for would be computed against the wrong base. Expressing the
--       benefit as an order discount reuses the apportionment that already
--       handles coupons correctly, and the tax stays right by construction.
--
--    2. Refunds and cancellations. A free line has no money attached, so a
--       partial refund would have to decide what a zero-priced item is worth.
--       A discount is simply reversed with everything else.
--
--    3. The pricing engine already knows how to apply and apportion a discount.
--       A new line type would need handling in the cart, the order, the invoice,
--       the courier weight calculation and the packing slip.
--
--  The customer still sees "1 free" — that wording comes from the offer title
--  and the disclosed benefit, not from the line structure.
--
--  MySQL 8.0.16+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- `bogo` joins the existing offer types.
ALTER TABLE `offers`
    MODIFY COLUMN `offer_type`
        ENUM('festival','flash_sale','deal_of_day','category','combo','free_shipping','bogo')
        NOT NULL DEFAULT 'festival';

-- `free_items` joins the existing discount types. It carries no percentage and
-- no flat amount; the benefit is worked out from the quantities below.
ALTER TABLE `offers`
    MODIFY COLUMN `discount_type`
        ENUM('none','percentage','flat','free_delivery','free_items')
        NOT NULL DEFAULT 'none';

ALTER TABLE `offers`
    ADD COLUMN `buy_quantity` SMALLINT UNSIGNED NULL
        COMMENT 'How many must be bought to earn the benefit'
        AFTER `discount_value`,
    ADD COLUMN `get_quantity` SMALLINT UNSIGNED NULL
        COMMENT 'How many are then free'
        AFTER `buy_quantity`,
    -- Which units are free when a customer qualifies more than once, or has
    -- several different eligible products in the basket.
    --
    -- `cheapest` is the honest default: a shop that gives away the most
    -- expensive item on a mixed basket loses money it did not intend to, and
    -- every large retailer discounts the cheapest for exactly this reason. It
    -- is also what a customer expects once they think about it.
    ADD COLUMN `free_item_scope` ENUM('same_variant','cheapest_eligible') NULL
        COMMENT 'same_variant: free units come from the same pack the customer bought'
        AFTER `get_quantity`,
    -- A ceiling on how many times one basket can claim the offer. Without it,
    -- "buy 1 get 1" on a fifty-unit order gives away twenty-five units.
    ADD COLUMN `max_free_items_per_order` SMALLINT UNSIGNED NULL
        COMMENT 'Total free units one order may earn; NULL means unlimited'
        AFTER `free_item_scope`;

-- A buy-X-get-Y offer is meaningless without both quantities, and a positive
-- buy quantity is what stops "buy 0 get 1" giving the shop away.
ALTER TABLE `offers`
    ADD CONSTRAINT `chk_offers_bogo_quantities`
        CHECK (
            `discount_type` <> 'free_items'
            OR (`buy_quantity` IS NOT NULL AND `buy_quantity` >= 1
                AND `get_quantity` IS NOT NULL AND `get_quantity` >= 1)
        );

-- A percentage or flat offer must not carry quantities, or an editor could
-- leave them behind when changing type and produce an offer that reads one way
-- and behaves another.
ALTER TABLE `offers`
    ADD CONSTRAINT `chk_offers_quantities_only_for_free_items`
        CHECK (
            `discount_type` = 'free_items'
            OR (`buy_quantity` IS NULL AND `get_quantity` IS NULL)
        );

CREATE OR REPLACE VIEW `vw_offer_performance` AS
SELECT
    o.`id`,
    o.`uuid`,
    o.`code`,
    o.`title`,
    o.`offer_type`,
    o.`discount_type`,
    o.`buy_quantity`,
    o.`get_quantity`,
    o.`status`,
    o.`starts_date`,
    o.`ends_date`,
    COUNT(ord.`id`)                                   AS `orders_using`,
    COALESCE(SUM(ord.`order_discount`), 0)            AS `discount_given`,
    COALESCE(SUM(ord.`grand_total`), 0)               AS `revenue_influenced`
FROM `offers` o
LEFT JOIN `orders` ord
       ON ord.`offer_code` = o.`code`
      AND ord.`status` <> 'cancelled'
      AND ord.`is_deleted` = 0
WHERE o.`is_deleted` = 0
GROUP BY o.`id`, o.`uuid`, o.`code`, o.`title`, o.`offer_type`, o.`discount_type`,
         o.`buy_quantity`, o.`get_quantity`, o.`status`, o.`starts_date`, o.`ends_date`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('010_bogo_offers', 10, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
