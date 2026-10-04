-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 020 - faq_entries: a real idempotency key
--
--  Bug this fixes: `database/seeds/009_content_seed.sql`'s FAQ block generates
--  a fresh UUID() per row and relies on `ON DUPLICATE KEY UPDATE` to make
--  re-seeding safe — the same pattern every other seeded table uses. It works
--  everywhere else because those tables carry a second, real UNIQUE key
--  (settings.setting_key, roles.code, couriers.code, warehouses.code, ...)
--  that the ON DUPLICATE KEY UPDATE clause actually collides on, independent
--  of the freshly-random uuid. faq_entries had no such key — only uuid was
--  unique — so a fresh UUID() every run meant every run inserted new rows
--  instead of updating existing ones. `bin/migrate.php` re-runs every seed
--  file on every invocation ("seeds are idempotent" is the documented
--  contract), so this multiplied on every migration run: 108 rows for what
--  should have been 9 by the time it was noticed and deduplicated.
--
--  The fix is the missing key: (group_code, question) is a sensible natural
--  identity for an FAQ entry — the same question should not appear twice in
--  the same group. This also makes the existing ON DUPLICATE KEY UPDATE
--  clause in the seed file actually do what its own comment already claimed.
--
--  Data cleanup (108 duplicate rows down to 9, helpful_count summed onto the
--  earliest-created survivor of each group) was done directly against the
--  live database before this migration, since it's data hygiene, not schema
--  — this migration only adds the constraint that prevents a recurrence.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `faq_entries`
    ADD CONSTRAINT `uq_faq_entries_group_question` UNIQUE (`group_code`, `question`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('020_faq_entries_unique_constraint', 20, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
