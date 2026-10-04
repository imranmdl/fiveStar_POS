-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 046 - Shipment summary view: add order_uuid and courier_shipment_id
--
--  Gap found during a Shiprocket-integration audit (2026-09-28): the
--  Shiprocket integration itself (ShiprocketAdapter, ShipmentService,
--  couriers/shipments/shipment_events/courier_selections/pickup_requests/
--  manifests, the admin Shipments screen, customer order tracking) already
--  existed and was not rebuilt. Two fields the admin shipment list/detail
--  screens need were already columns on real tables but never reached the
--  view the admin screen reads:
--    * `orders.uuid` — so a shipment row/detail page can link back to its
--      order (the view already had order_number, but not the uuid a link
--      needs).
--    * `shipments.courier_shipment_id` — Shiprocket's own shipment/order
--      id, already captured by ShipmentService::book() and stored, but
--      never selected into this view.
--
--  View definition only — CREATE OR REPLACE VIEW touches no stored data,
--  drops nothing, and every existing column stays exactly where it was.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE OR REPLACE VIEW `vw_shipment_summary` AS
SELECT
    s.`id`,
    s.`uuid`,
    s.`shipment_number`,
    s.`awb_number`,
    s.`courier_shipment_id`,
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
    o.`uuid`         AS `order_uuid`,
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
INNER JOIN `orders` o ON o.`id` = s.`order_id`
INNER JOIN `couriers` c ON c.`id` = s.`courier_id`
WHERE s.`is_deleted` = 0;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('046_shipment_summary_view_fields', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
