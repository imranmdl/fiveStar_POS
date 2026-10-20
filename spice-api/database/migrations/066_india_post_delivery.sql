-- ===========================================================================
-- 066 — India Post (post office) delivery
--
-- New shipping option next to Shiprocket: staff book the parcel at the post
-- office and enter its article number (EA123456789IN) on the order; the
-- customer tracks it with that number. Checkout charges delivery from the
-- India Post rate card (Payments → settings), stored in the
-- `india_post_settings` setting on first save — until then placeholder rates
-- apply.
--
-- Shops that had Shiprocket switched OFF ("manual") move to India Post. A
-- shop on Shiprocket keeps Shiprocket; the administrator can switch in
-- Payments → settings.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

UPDATE `settings`
   SET `setting_value` = 'india_post', `updated_date` = NOW()
 WHERE `setting_key` = 'delivery_driver'
   AND `setting_value` = 'manual'
   AND `is_deleted` = 0;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('066_india_post_delivery', 66, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
