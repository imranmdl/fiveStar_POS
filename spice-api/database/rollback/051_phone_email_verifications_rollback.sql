-- Rollback for 051_phone_email_verifications.
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `phone_email_verifications`;

DELETE FROM `schema_migrations` WHERE `migration` = '051_phone_email_verifications';
