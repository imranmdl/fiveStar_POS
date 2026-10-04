-- ============================================================================
--  Rollback for migration 026 - offers.audience
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `offers`
    DROP COLUMN `audience`;

DELETE FROM `schema_migrations` WHERE `migration` = '026_offer_audience';

SET FOREIGN_KEY_CHECKS = 1;
