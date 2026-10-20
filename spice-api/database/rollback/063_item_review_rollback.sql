-- Rollback 063 — drops the review flag columns.
ALTER TABLE `products`
    DROP KEY `idx_products_admin_reviewed`,
    DROP COLUMN `admin_reviewed`,
    DROP COLUMN `review_reason`,
    DROP COLUMN `review_flagged_date`,
    DROP COLUMN `reviewed_by`,
    DROP COLUMN `reviewed_date`;

DELETE FROM `schema_migrations` WHERE `migration` = '063_item_review';
