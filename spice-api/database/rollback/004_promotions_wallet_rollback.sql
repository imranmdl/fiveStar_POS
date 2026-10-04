-- ============================================================================
--  Rollback for migration 004 - Coupons, Offers, Referrals and Wallet
--
--  WARNING: this destroys the wallet ledger. In production, export
--  wallet_transactions first — those rows are the only record of what customers
--  are owed.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TRIGGER IF EXISTS `trg_wallet_transactions_no_update`;
DROP TRIGGER IF EXISTS `trg_wallet_transactions_no_delete`;

DROP VIEW  IF EXISTS `vw_referral_summary`;
DROP VIEW  IF EXISTS `vw_coupon_performance`;

ALTER TABLE `carts` DROP FOREIGN KEY `fk_carts_coupon`;
ALTER TABLE `carts` DROP KEY `idx_carts_coupon`;
ALTER TABLE `carts`
    DROP COLUMN `applied_coupon_id`,
    DROP COLUMN `applied_coupon_code`,
    DROP COLUMN `wallet_redeem_amount`;

DROP TABLE IF EXISTS `wallet_credit_expiries`;
DROP TABLE IF EXISTS `wallet_transactions`;
DROP TABLE IF EXISTS `wallet_accounts`;
DROP TABLE IF EXISTS `referrals`;
DROP TABLE IF EXISTS `offer_targets`;
DROP TABLE IF EXISTS `offers`;
DROP TABLE IF EXISTS `coupon_redemptions`;
DROP TABLE IF EXISTS `coupon_targets`;
DROP TABLE IF EXISTS `coupons`;

-- Restore vw_cart_lines to its migration-003 shape (without category columns).
CREATE OR REPLACE VIEW `vw_cart_lines` AS
SELECT
    ci.`id`, ci.`uuid`, ci.`cart_id`, ci.`quantity`, ci.`is_saved_for_later`,
    ci.`is_gift`, ci.`gift_message`, ci.`notes`, ci.`unit_price_snapshot`,
    ci.`unit_mrp_snapshot`, ci.`gst_rate_snapshot`, ci.`unit_weight_snapshot`,
    ci.`price_changed_date`, ci.`created_date`,
    v.`id` AS `variant_id`, v.`uuid` AS `variant_uuid`, v.`sku`, v.`variant_name`,
    v.`weight_grams`, v.`pack_type`, v.`max_order_quantity`,
    vp.`effective_price` AS `live_unit_price`, vp.`mrp` AS `live_unit_mrp`,
    vp.`shipping_weight_grams` AS `live_unit_weight`, vp.`offer_is_live`,
    vp.`discount_percentage`,
    p.`id` AS `product_id`, p.`uuid` AS `product_uuid`, p.`slug` AS `product_slug`,
    p.`name` AS `product_name`, p.`brand`, p.`gst_rate` AS `live_gst_rate`,
    p.`status` AS `product_status`, p.`is_gift_packable`,
    CASE
        WHEN p.`status` = 'published' AND p.`is_deleted` = 0 AND p.`is_active` = 1
             AND v.`is_deleted` = 0 AND v.`is_active` = 1 AND vp.`id` IS NOT NULL
        THEN 1 ELSE 0
    END AS `is_purchasable`
FROM `cart_items` ci
INNER JOIN `product_variants` v ON v.`id` = ci.`variant_id`
INNER JOIN `products` p ON p.`id` = ci.`product_id`
LEFT JOIN `vw_variant_pricing` vp ON vp.`id` = ci.`variant_id`
WHERE ci.`is_deleted` = 0;

DELETE FROM `schema_migrations` WHERE `migration` = '004_promotions_wallet';

SET FOREIGN_KEY_CHECKS = 1;
