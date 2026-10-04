-- ============================================================================
--  Rollback for migration 002 - Product Catalog
--  Reverse dependency order.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS `vw_category_tree`;
DROP VIEW  IF EXISTS `vw_product_price_range`;
DROP VIEW  IF EXISTS `vw_variant_pricing`;

DROP TABLE IF EXISTS `banners`;
DROP TABLE IF EXISTS `product_attributes`;
DROP TABLE IF EXISTS `product_nutrition`;
DROP TABLE IF EXISTS `product_media`;
DROP TABLE IF EXISTS `product_variants`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;

DELETE FROM `schema_migrations` WHERE `migration` = '002_catalog';

SET FOREIGN_KEY_CHECKS = 1;
