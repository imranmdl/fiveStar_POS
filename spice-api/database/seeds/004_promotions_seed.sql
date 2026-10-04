-- ============================================================================
--  Seed 004 - Sample coupons, offers and promotion settings
--
--  The coupons and offers below are illustrative. Review the discount values
--  against your actual margins before activating any of them; two are left in
--  `draft` deliberately so nothing goes live by accident on a fresh install.
--
--  Idempotent: matched on coupon code and offer code.
-- ============================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- Promotion settings
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'referral', 'referral_referrer_reward',   '50',  'decimal', 'Wallet credit to the referrer once the referee orders', 1),
    (UUID(), 'referral', 'referral_referee_reward',    '50',  'decimal', 'Wallet credit to the new customer on their first order', 1),
    (UUID(), 'referral', 'referral_min_order_value',   '299', 'decimal', 'Minimum first-order value that qualifies a referral', 1),
    (UUID(), 'referral', 'referral_reward_expiry_days','180', 'int',     'Days before referral wallet credit expires', 1),
    (UUID(), 'wallet',   'wallet_max_redeem_percent',  '20',  'decimal', 'Cap on wallet credit per order, as a percent of order value', 1),
    (UUID(), 'wallet',   'wallet_min_redeem_amount',   '10',  'decimal', 'Smallest wallet redemption allowed', 1),
    (UUID(), 'wallet',   'wallet_enabled',             '1',   'bool',    'Wallet redemption master switch', 1),
    (UUID(), 'coupon',   'coupon_stacking_allowed',    '0',   'bool',    'One coupon per order; see PromotionResolver', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;

-- --------------------------------------------------------------------------
-- Coupons
-- --------------------------------------------------------------------------
INSERT INTO `coupons` (
    `uuid`, `code`, `title`, `description`, `terms`, `discount_type`, `discount_value`,
    `max_discount_amount`, `min_order_value`, `applies_to`, `audience`,
    `valid_from`, `valid_to`, `total_usage_limit`, `per_customer_limit`,
    `stackable_with_offer`, `status`
)
VALUES
    (UUID(), 'WELCOME10', 'Welcome offer: 10% off',
     '10% off your first order, up to ₹150.',
     'Valid on your first order only. Maximum discount ₹150. Minimum order value ₹299. Cannot be combined with another coupon.',
     'percentage', 10.00, 150.00, 299.00, 'all', 'new_customers',
     NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), NULL, 1, 1, 'active'),

    (UUID(), 'SPICE50', 'Flat ₹50 off spices',
     'Flat ₹50 off any order containing spices.',
     'Applies to the Spices category only. Minimum order value ₹499. One use per customer.',
     'flat', 50.00, NULL, 499.00, 'categories', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), 1000, 1, 0, 'active'),

    (UUID(), 'FREESHIP', 'Free delivery',
     'Free delivery on orders above ₹399.',
     'Delivery charges waived. Minimum order value ₹399. Valid twice per customer.',
     'free_delivery', 0.00, NULL, 399.00, 'all', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY), NULL, 2, 1, 'active'),

    (UUID(), 'BULK15', 'Bulk buyer: 15% off',
     '15% off orders above ₹2,499, capped at ₹500.',
     'Minimum order value ₹2,499. Maximum discount ₹500. Review margins before activating.',
     'percentage', 15.00, 500.00, 2499.00, 'all', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 200, 1, 0, 'draft'),

    (UUID(), 'DRYFRUIT100', 'Flat ₹100 off dry fruits',
     'Flat ₹100 off dry fruit orders above ₹1,499.',
     'Applies to the Dry Fruits category only. Minimum order value ₹1,499.',
     'flat', 100.00, NULL, 1499.00, 'categories', 'all',
     NOW(), DATE_ADD(NOW(), INTERVAL 45 DAY), 500, 1, 0, 'draft')
ON DUPLICATE KEY UPDATE
    `title`               = VALUES(`title`),
    `description`         = VALUES(`description`),
    `terms`               = VALUES(`terms`),
    `discount_type`       = VALUES(`discount_type`),
    `discount_value`      = VALUES(`discount_value`),
    `max_discount_amount` = VALUES(`max_discount_amount`),
    `min_order_value`     = VALUES(`min_order_value`),
    `version`             = `coupons`.`version` + 1;

-- Category scoping for the two category coupons.
INSERT INTO `coupon_targets` (`uuid`, `coupon_id`, `target_type`, `category_id`)
SELECT UUID(), c.`id`, 'category', cat.`id`
FROM `coupons` c
JOIN `categories` cat ON cat.`slug` = 'spices'
WHERE c.`code` = 'SPICE50'
  AND NOT EXISTS (
      SELECT 1 FROM `coupon_targets` t
       WHERE t.`coupon_id` = c.`id` AND t.`category_id` = cat.`id` AND t.`is_deleted` = 0
  );

INSERT INTO `coupon_targets` (`uuid`, `coupon_id`, `target_type`, `category_id`)
SELECT UUID(), c.`id`, 'category', cat.`id`
FROM `coupons` c
JOIN `categories` cat ON cat.`slug` = 'dry-fruits'
WHERE c.`code` = 'DRYFRUIT100'
  AND NOT EXISTS (
      SELECT 1 FROM `coupon_targets` t
       WHERE t.`coupon_id` = c.`id` AND t.`category_id` = cat.`id` AND t.`is_deleted` = 0
  );

-- --------------------------------------------------------------------------
-- Offers (merchandising campaigns, optionally auto-discounting)
-- --------------------------------------------------------------------------
INSERT INTO `offers` (
    `uuid`, `code`, `title`, `subtitle`, `description`, `offer_type`,
    `discount_type`, `discount_value`, `max_discount_amount`, `min_order_value`,
    `applies_to`, `stackable_with_coupon`, `priority`,
    `starts_date`, `ends_date`, `display_order`, `is_featured`, `status`
)
VALUES
    (UUID(), 'TODAYSDEALS', 'Today''s Deals', 'Fresh picks, sharper prices',
     'A rotating selection of products at their best price of the week.',
     'deal_of_day', 'none', 0.00, NULL, NULL, 'all', 1, 100,
     NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 10, 1, 'active'),

    (UUID(), 'DRYFRUITWEEK', 'Dry Fruit Week', '5% off all dry fruits',
     'An automatic 5% off everything in the Dry Fruits category this week.',
     'category', 'percentage', 5.00, 200.00, 599.00, 'categories', 1, 50,
     NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 20, 1, 'active'),

    (UUID(), 'FESTIVESHIP', 'Festive free shipping', 'No delivery charges above ₹699',
     'Delivery charges waived automatically on festive orders above ₹699.',
     'free_shipping', 'free_delivery', 0.00, NULL, 699.00, 'all', 0, 40,
     NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), 30, 0, 'active')
ON DUPLICATE KEY UPDATE
    `title`                 = VALUES(`title`),
    `subtitle`              = VALUES(`subtitle`),
    `description`           = VALUES(`description`),
    `discount_type`         = VALUES(`discount_type`),
    `discount_value`        = VALUES(`discount_value`),
    `max_discount_amount`   = VALUES(`max_discount_amount`),
    `min_order_value`       = VALUES(`min_order_value`),
    `stackable_with_coupon` = VALUES(`stackable_with_coupon`),
    `version`               = `offers`.`version` + 1;

INSERT INTO `offer_targets` (`uuid`, `offer_id`, `target_type`, `category_id`)
SELECT UUID(), o.`id`, 'category', cat.`id`
FROM `offers` o
JOIN `categories` cat ON cat.`slug` = 'dry-fruits'
WHERE o.`code` = 'DRYFRUITWEEK'
  AND NOT EXISTS (
      SELECT 1 FROM `offer_targets` t
       WHERE t.`offer_id` = o.`id` AND t.`category_id` = cat.`id` AND t.`is_deleted` = 0
  );
