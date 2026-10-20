-- ===========================================================================
-- 065 — "Reviewed" means an administrator actually checked the item
--
-- 063 started every existing item as reviewed except those a non-admin had
-- created, so items an administrator never looked at (seeded, imported, or
-- entered under the admin login before the review page existed) showed as
-- "Reviewed". From now on an item is reviewed only when an administrator
-- has marked it (reviewed_by is set) — or entered it themselves, which the
-- application records as reviewed by them.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `products`
    MODIFY COLUMN `admin_reviewed` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0 = waiting for an administrator to check the details';

UPDATE `products`
   SET `admin_reviewed` = 0,
       `review_reason` = COALESCE(`review_reason`, 'Not yet checked by an administrator'),
       `review_flagged_date` = COALESCE(`review_flagged_date`, `created_date`, NOW())
 WHERE `is_deleted` = 0
   AND `admin_reviewed` = 1
   AND `reviewed_by` IS NULL;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('065_review_flag_accuracy', 65, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
