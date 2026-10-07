-- ===========================================================================
-- 055 — Remove phone.email ("Sign in with Phone")
--
-- Mobile numbers are verified with OTP codes sent through MSG91 only, so the
-- table that recorded used phone.email verification links is dropped.
-- ===========================================================================

DROP TABLE IF EXISTS `phone_email_verifications`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('055_drop_phone_email', 55, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
