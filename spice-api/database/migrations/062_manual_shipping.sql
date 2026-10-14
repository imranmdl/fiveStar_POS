-- ===========================================================================
-- 062 — Ship with your own courier, enter the tracking number
--
-- Staff can ship an order outside Shiprocket (India Post, DTDC, a local
-- delivery person…) and record the courier's name, tracking number and,
-- optionally, its tracking link. Such shipments belong to one internal
-- courier row, MANUAL, which is never offered by automatic courier
-- selection (is_enabled = 0); the courier actually used is stored on the
-- shipment itself:
--   shipments.carrier_name  "India Post", "DTDC", "Local delivery"…
--   shipments.tracking_url  the courier's own tracking link, when known
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `shipments`
    ADD COLUMN `carrier_name` VARCHAR(100) NULL
        COMMENT 'Courier actually used, for shipments sent outside an integration'
        AFTER `courier_id`,
    ADD COLUMN `tracking_url` VARCHAR(500) NULL
        COMMENT 'Courier tracking link entered by staff (manual shipments)'
        AFTER `awb_number`;

INSERT INTO `couriers`
    (`uuid`, `code`, `name`, `adapter`, `channel_code`, `tracking_url_template`,
     `min_weight_grams`, `max_weight_grams`, `max_order_value`, `handles_fragile`,
     `priority`, `reliability_score`, `volumetric_divisor`, `support_phone`,
     `supports_pickup`, `supports_label`, `supports_manifest`, `supports_rto`,
     `is_enabled`, `disabled_reason`)
VALUES
    (UUID(), 'MANUAL', 'Own courier', 'manual', NULL, NULL,
     0, 1000000, NULL, 1,
     999, 80.00, 5000, NULL,
     0, 0, 0, 0,
     0, 'Internal: shipments sent outside Shiprocket. Never chosen automatically.')
ON DUPLICATE KEY UPDATE `code` = `code`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('062_manual_shipping', 62, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
