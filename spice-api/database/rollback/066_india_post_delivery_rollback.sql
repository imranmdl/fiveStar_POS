-- Rollback 066: India Post back to "Shiprocket OFF" (own courier).
UPDATE `settings` SET `setting_value` = 'manual', `updated_date` = NOW()
 WHERE `setting_key` = 'delivery_driver' AND `setting_value` = 'india_post';

DELETE FROM `settings` WHERE `setting_key` = 'india_post_settings';

DELETE FROM `schema_migrations` WHERE `migration` = '066_india_post_delivery';
