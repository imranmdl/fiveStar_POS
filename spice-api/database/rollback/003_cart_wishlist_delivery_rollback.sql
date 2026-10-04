-- ============================================================================
--  Rollback for migration 003 - Cart, Wishlist and Delivery Pricing
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS `vw_cart_lines`;

DROP TABLE IF EXISTS `wishlist_items`;
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `carts`;
DROP TABLE IF EXISTS `delivery_charge_slabs`;
DROP TABLE IF EXISTS `delivery_pincode_map`;
DROP TABLE IF EXISTS `delivery_zones`;

DELETE FROM `schema_migrations` WHERE `migration` = '003_cart_wishlist_delivery';

SET FOREIGN_KEY_CHECKS = 1;
