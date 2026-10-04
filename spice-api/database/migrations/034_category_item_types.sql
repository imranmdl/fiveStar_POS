-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 034 - business types (item_type) on categories + starter trees
--
--  Purchase Inward has to serve more than spices: grocery (packed AND loose),
--  oils, clothing, footwear, toys, stationery. The type is set ONCE on a
--  top-level category and inherited by every sub-category and item beneath
--  it (children keep item_type NULL), so staff never pick a type per item.
--  The inward screen reads it to show only the fields that make sense
--  (weight/expiry for grocery, size x colour for clothing, and so on).
--
--  Also seeds a Category -> Sub-category starter tree for each new type
--  (hidden from the storefront menu until an administrator enables it),
--  common size/colour option values, and the size/colour dimensions for the
--  clothing and footwear categories. Everything is additive and re-runnable.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `categories`
    ADD COLUMN `item_type` ENUM('grocery','oils','clothing','footwear','toys','stationery','general') NULL
        COMMENT 'Business type; set on top-level categories, inherited by descendants'
        AFTER `description`;

UPDATE `categories` SET `item_type` = 'grocery'
 WHERE `parent_id` IS NULL AND `slug` IN
       ('spices','dry-fruits','herbs','seeds','organic-products','combo-packs','gift-packs');

-- Top-level categories -------------------------------------------------------
INSERT IGNORE INTO `categories` (`uuid`, `parent_id`, `slug`, `name`, `item_type`, `display_order`, `show_in_menu`)
VALUES
 (UUID(), NULL, 'grocery-staples', 'Grocery & Staples', 'grocery',    110, 0),
 (UUID(), NULL, 'oils',            'Oils',              'oils',       120, 0),
 (UUID(), NULL, 'clothing',        'Clothing',          'clothing',   130, 0),
 (UUID(), NULL, 'footwear',        'Footwear',          'footwear',   140, 0),
 (UUID(), NULL, 'toys',            'Toys',              'toys',       150, 0),
 (UUID(), NULL, 'stationery',      'Stationery',        'stationery', 160, 0);

-- Sub-categories ---------------------------------------------------------------
INSERT IGNORE INTO `categories` (`uuid`, `parent_id`, `slug`, `name`, `display_order`, `show_in_menu`)
SELECT UUID(), p.`id`, s.`slug`, s.`name`, s.`ord`, 0
  FROM `categories` p
  JOIN (
        SELECT 'grocery-staples' AS pslug, 'rice-grains' AS slug, 'Rice & Grains' AS name, 10 AS ord
  UNION ALL SELECT 'grocery-staples', 'pulses-dals',        'Pulses & Dals',         20
  UNION ALL SELECT 'grocery-staples', 'flours-atta',        'Flours & Atta',         30
  UNION ALL SELECT 'grocery-staples', 'sugar-salt-jaggery', 'Sugar, Salt & Jaggery', 40
  UNION ALL SELECT 'grocery-staples', 'snacks-biscuits',    'Snacks & Biscuits',     50
  UNION ALL SELECT 'oils', 'oils-cooking',     'Cooking Oils',      10
  UNION ALL SELECT 'oils', 'oils-coldpressed', 'Cold-pressed Oils', 20
  UNION ALL SELECT 'oils', 'oils-ghee',        'Ghee & Butter',     30
  UNION ALL SELECT 'oils', 'oils-hair',        'Hair Oils',         40
  UNION ALL SELECT 'oils', 'oils-essential',   'Essential Oils',    50
  UNION ALL SELECT 'clothing', 'clothing-men',       'Men',         10
  UNION ALL SELECT 'clothing', 'clothing-women',     'Women',       20
  UNION ALL SELECT 'clothing', 'clothing-kids',      'Kids',        30
  UNION ALL SELECT 'clothing', 'clothing-innerwear', 'Innerwear',   40
  UNION ALL SELECT 'clothing', 'clothing-ethnic',    'Ethnic Wear', 50
  UNION ALL SELECT 'footwear', 'footwear-men',     'Men',               10
  UNION ALL SELECT 'footwear', 'footwear-women',   'Women',             20
  UNION ALL SELECT 'footwear', 'footwear-kids',    'Kids',              30
  UNION ALL SELECT 'footwear', 'footwear-sports',  'Sports Shoes',      40
  UNION ALL SELECT 'footwear', 'footwear-sandals', 'Sandals & Slippers', 50
  UNION ALL SELECT 'toys', 'toys-soft',        'Soft Toys',             10
  UNION ALL SELECT 'toys', 'toys-educational', 'Educational Toys',      20
  UNION ALL SELECT 'toys', 'toys-action',      'Action Figures & Cars', 30
  UNION ALL SELECT 'toys', 'toys-games',       'Games & Puzzles',       40
  UNION ALL SELECT 'toys', 'toys-outdoor',     'Outdoor Toys',          50
  UNION ALL SELECT 'stationery', 'stationery-writing', 'Pens & Pencils',    10
  UNION ALL SELECT 'stationery', 'stationery-paper',   'Notebooks & Paper', 20
  UNION ALL SELECT 'stationery', 'stationery-art',     'Art & Craft',       30
  UNION ALL SELECT 'stationery', 'stationery-office',  'Office Supplies',   40
  UNION ALL SELECT 'stationery', 'stationery-school',  'School Supplies',   50
  ) s ON s.`pslug` = p.`slug`;

-- Common Size and Colour values (shared by clothing/footwear/toys) -------------
INSERT INTO `variant_option_values` (`uuid`, `option_type_id`, `value`, `display_order`)
SELECT UUID(), t.`id`, v.`value`, v.`ord`
  FROM `variant_option_types` t
  JOIN (
        SELECT 'size' AS code, 'XS' AS value, 10 AS ord
  UNION ALL SELECT 'size', 'S', 20      UNION ALL SELECT 'size', 'M', 30
  UNION ALL SELECT 'size', 'L', 40      UNION ALL SELECT 'size', 'XL', 50
  UNION ALL SELECT 'size', 'XXL', 60    UNION ALL SELECT 'size', 'Free Size', 70
  UNION ALL SELECT 'size', '0-1 Yr', 80 UNION ALL SELECT 'size', '1-2 Yr', 81
  UNION ALL SELECT 'size', '2-4 Yr', 82 UNION ALL SELECT 'size', '4-6 Yr', 83
  UNION ALL SELECT 'size', '6-8 Yr', 84 UNION ALL SELECT 'size', '8-10 Yr', 85
  UNION ALL SELECT 'size', '10-12 Yr', 86
  UNION ALL SELECT 'size', '5', 100     UNION ALL SELECT 'size', '6', 101
  UNION ALL SELECT 'size', '7', 102     UNION ALL SELECT 'size', '8', 103
  UNION ALL SELECT 'size', '9', 104     UNION ALL SELECT 'size', '10', 105
  UNION ALL SELECT 'size', '11', 106    UNION ALL SELECT 'size', '12', 107
  UNION ALL SELECT 'color', 'Black', 10 UNION ALL SELECT 'color', 'White', 20
  UNION ALL SELECT 'color', 'Red', 30   UNION ALL SELECT 'color', 'Blue', 40
  UNION ALL SELECT 'color', 'Navy', 50  UNION ALL SELECT 'color', 'Green', 60
  UNION ALL SELECT 'color', 'Yellow', 70 UNION ALL SELECT 'color', 'Pink', 80
  UNION ALL SELECT 'color', 'Grey', 90  UNION ALL SELECT 'color', 'Brown', 100
  UNION ALL SELECT 'color', 'Orange', 110 UNION ALL SELECT 'color', 'Purple', 120
  ) v ON v.`code` = t.`code`
 WHERE NOT EXISTS (
        SELECT 1 FROM `variant_option_values` x
         WHERE x.`option_type_id` = t.`id` AND x.`value` = v.`value` AND x.`is_deleted` = 0);

-- Size (required) + Colour dimensions for clothing and footwear ---------------
INSERT INTO `category_option_types` (`uuid`, `category_id`, `option_type_id`, `is_required`, `display_order`)
SELECT UUID(), c.`id`, t.`id`, IF(t.`code` = 'size', 1, 0), IF(t.`code` = 'size', 10, 20)
  FROM `categories` c
  JOIN `variant_option_types` t ON t.`code` IN ('size', 'color')
 WHERE c.`slug` IN ('clothing', 'footwear')
   AND NOT EXISTS (SELECT 1 FROM `category_option_types` x
                    WHERE x.`category_id` = c.`id` AND x.`option_type_id` = t.`id` AND x.`is_deleted` = 0);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('034_category_item_types', 34, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
