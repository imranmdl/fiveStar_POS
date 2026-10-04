-- ============================================================================
--  Rollback for migration 001 - Core Foundation
--  Dropped in reverse dependency order so foreign keys never block the drop.
--  WARNING: this destroys all user, session and audit data.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS `vw_active_staff`;

DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `refresh_tokens`;
DROP TABLE IF EXISTS `otp_requests`;
DROP TABLE IF EXISTS `user_addresses`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;

DELETE FROM `schema_migrations` WHERE `migration` = '001_core_foundation';

SET FOREIGN_KEY_CHECKS = 1;
