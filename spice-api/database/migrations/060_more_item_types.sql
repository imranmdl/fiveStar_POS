-- ===========================================================================
-- 060 — More kinds of business for categories
--
-- categories.item_type (set on a top-level category, inherited below it)
-- decides which fields the "new item" forms show and the default GST rate.
-- The store now also sells perfumes / attar, personal-care, pooja and
-- home & kitchen items; the admin screens suggest those categories from an
-- item's name and create them on request, so the type list grows to match.
-- No categories are created here.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

ALTER TABLE `categories`
    MODIFY COLUMN `item_type` ENUM('grocery','oils','clothing','footwear','toys','stationery',
                                   'perfumes','personal_care','pooja','home_kitchen','general') NULL
        COMMENT 'Business type; set on top-level categories, inherited by descendants';

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('060_more_item_types', 60, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
