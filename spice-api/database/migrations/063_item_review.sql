-- ===========================================================================
-- 063 — "Admin reviewed" flag on items, for correcting data-entry mistakes
--
-- Items entered by staff other than an administrator (Purchase Inward, Mobile
-- Scan, spreadsheet imports), items whose prices a staff purchase entry
-- changed, and items whose purchase cost looks wrong are flagged
-- admin_reviewed = 0 with the reason. An administrator checks them on the
-- "Item review" page, corrects name / unit / category / prices / landing cost
-- (the corrections flow into purchases, vendor balances and stock value),
-- and marks them reviewed.
--
-- Existing items added by a non-administrator start out flagged, so the
-- current catalogue gets one review pass.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `products`
    ADD COLUMN `admin_reviewed`      TINYINT(1)      NOT NULL DEFAULT 1
        COMMENT '0 = waiting for an administrator to check the details' AFTER `status`,
    ADD COLUMN `review_reason`       VARCHAR(255)    NULL
        COMMENT 'Why it was flagged for review' AFTER `admin_reviewed`,
    ADD COLUMN `review_flagged_date` DATETIME        NULL AFTER `review_reason`,
    ADD COLUMN `reviewed_by`         BIGINT UNSIGNED NULL AFTER `review_flagged_date`,
    ADD COLUMN `reviewed_date`       DATETIME        NULL AFTER `reviewed_by`,
    ADD KEY `idx_products_admin_reviewed` (`admin_reviewed`, `review_flagged_date`);

-- Items already in the catalogue that a non-administrator added.
UPDATE `products` p
  JOIN `users` u ON u.`id` = p.`created_by`
  JOIN `roles` r ON r.`id` = u.`role_id`
   SET p.`admin_reviewed` = 0,
       p.`review_reason` = CONCAT('Added by ', r.`name`, ' — not yet checked by an administrator'),
       p.`review_flagged_date` = COALESCE(p.`created_date`, NOW())
 WHERE p.`is_deleted` = 0
   AND r.`code` NOT IN ('administrator', 'super_admin');

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('063_item_review', 63, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
