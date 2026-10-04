-- ============================================================================
--  Rollback for migration 008 - Notifications and Scheduling
--
--  Destroys the record of what was sent to whom. Under Indian telecom rules a
--  merchant must be able to show consent and delivery history on request, so
--  export before running:
--    mysqldump <db> notification_queue notification_preferences > notifications.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS `vw_notification_health`;
DROP TABLE IF EXISTS `scheduled_task_runs`;
DROP TABLE IF EXISTS `scheduled_tasks`;
DROP TABLE IF EXISTS `notification_preferences`;
DROP TABLE IF EXISTS `notification_queue`;
DROP TABLE IF EXISTS `notification_templates`;

DELETE FROM `schema_migrations` WHERE `migration` = '008_notifications_scheduler';

SET FOREIGN_KEY_CHECKS = 1;
