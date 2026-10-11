-- Rollback 060 — categories using the newer types become 'general' first so
-- the narrower ENUM can apply.
UPDATE `categories` SET `item_type` = 'general'
 WHERE `item_type` IN ('perfumes','personal_care','pooja','home_kitchen');

ALTER TABLE `categories`
    MODIFY COLUMN `item_type` ENUM('grocery','oils','clothing','footwear','toys','stationery','general') NULL
        COMMENT 'Business type; set on top-level categories, inherited by descendants';

DELETE FROM `schema_migrations` WHERE `migration` = '060_more_item_types';
