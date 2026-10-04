-- ============================================================================
--  Rollback for migration 025 - Marketing leads + recurring broadcast
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM `scheduled_tasks` WHERE `code` = 'marketing.recurring_broadcast';
DELETE FROM `notification_templates` WHERE `code` = 'marketing.broadcast' AND `channel` = 'sms';
DROP TABLE IF EXISTS `leads`;

DELETE FROM `schema_migrations` WHERE `migration` = '025_marketing_leads';

SET FOREIGN_KEY_CHECKS = 1;
