-- ===========================================================================
-- 059 — Track Shiprocket parcels on Shiprocket's tracking page
--
-- Couriers booked through Shiprocket were linked to guessed carrier URLs
-- (e.g. https://shadowfax.in/track/{awb}), which do not show the parcel.
-- Shiprocket's own page, https://shiprocket.co/tracking/{awb}, works for every
-- courier it assigns. The code now builds that link for Shiprocket couriers;
-- this migration updates the stored templates and the links already saved on
-- orders that were shipped through Shiprocket.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

UPDATE `couriers`
   SET `tracking_url_template` = 'https://shiprocket.co/tracking/{awb}',
       `updated_date` = NOW(), `version` = `version` + 1
 WHERE `adapter` = 'shiprocket';

UPDATE `orders` o
  JOIN `shipments` s ON s.`order_id` = o.`id` AND s.`is_deleted` = 0
  JOIN `couriers` c ON c.`id` = s.`courier_id`
   SET o.`tracking_url` = CONCAT('https://shiprocket.co/tracking/', o.`tracking_number`)
 WHERE c.`adapter` = 'shiprocket'
   AND o.`tracking_number` IS NOT NULL AND o.`tracking_number` <> '';

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('059_shiprocket_tracking_link', 59, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
