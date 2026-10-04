-- ============================================================================
--  Rollback for migration 006 - Delivery Integration and Courier Selection
--
--  WARNING: this destroys every shipment, AWB and tracking history. If parcels
--  are in transit, the record of where they are goes with it. Export first:
--    mysqldump <db> shipments shipment_events manifests > shipments.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `vw_courier_performance`;
DROP VIEW IF EXISTS `vw_shipment_summary`;

-- Clear the courier columns denormalised onto orders. Leaving a tracking number
-- pointing at a shipments table that no longer exists would show customers a
-- tracking link that resolves to nothing.
UPDATE `orders`
   SET `courier_code` = NULL, `courier_name` = NULL,
       `tracking_number` = NULL, `tracking_url` = NULL
 WHERE `courier_code` IS NOT NULL OR `tracking_number` IS NOT NULL;

DROP TABLE IF EXISTS `courier_selections`;
DROP TABLE IF EXISTS `shipment_events`;
DROP TABLE IF EXISTS `shipments`;
DROP TABLE IF EXISTS `manifests`;
DROP TABLE IF EXISTS `pickup_requests`;
DROP TABLE IF EXISTS `courier_rate_cards`;
DROP TABLE IF EXISTS `courier_serviceability`;
DROP TABLE IF EXISTS `couriers`;

ALTER TABLE `product_variants`
    DROP COLUMN `is_fragile`,
    DROP COLUMN `pack_height_mm`,
    DROP COLUMN `pack_width_mm`,
    DROP COLUMN `pack_length_mm`;

DELETE FROM `schema_migrations` WHERE `migration` = '006_delivery_couriers';

SET FOREIGN_KEY_CHECKS = 1;
