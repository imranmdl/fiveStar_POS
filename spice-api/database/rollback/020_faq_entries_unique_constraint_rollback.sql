-- ============================================================================
--  Rollback for migration 020 - faq_entries unique constraint
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `faq_entries`
    DROP CONSTRAINT `uq_faq_entries_group_question`;

DELETE FROM `schema_migrations` WHERE `migration` = '020_faq_entries_unique_constraint';

SET FOREIGN_KEY_CHECKS = 1;
