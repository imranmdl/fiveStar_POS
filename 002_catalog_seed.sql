-- ============================================================================
--  Seed 002 - Catalog taxonomy and demonstration products
--
--  Categories are the seven from SRS Module 3, plus subcategories.
--  Idempotent: matched on slug / product_code / sku, so re-running updates
--  rather than duplicating.
--
--  The three sample products exist so the catalog API returns something
--  meaningful on a fresh install and so the smoke test has data to filter,
--  sort and search against. Delete them before going live:
--    DELETE FROM products WHERE product_code LIKE 'DEMO-%';
-- ============================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- Top-level categories (SRS Module 3)
-- --------------------------------------------------------------------------
INSERT INTO `categories` (`uuid`, `parent_id`, `slug`, `name`, `description`, `display_order`, `is_featured`)
VALUES
    (UUID(), NULL, 'spices',           'Spices',           'Whole and ground spices sourced directly from growers', 10, 1),
    (UUID(), NULL, 'dry-fruits',       'Dry Fruits',       'Premium nuts and dried fruit',                         20, 1),
    (UUID(), NULL, 'herbs',            'Herbs',            'Culinary and wellness herbs',                          30, 0),
    (UUID(), NULL, 'seeds',            'Seeds',            'Edible and sprouting seeds',                           40, 0),
    (UUID(), NULL, 'organic-products', 'Organic Products', 'Certified organic range',                              50, 1),
    (UUID(), NULL, 'combo-packs',      'Combo Packs',      'Curated multi-product value packs',                    60, 1),
    (UUID(), NULL, 'gift-packs',       'Gift Packs',       'Festive and corporate gifting',                        70, 1)
ON DUPLICATE KEY UPDATE
    `name`          = VALUES(`name`),
    `description`   = VALUES(`description`),
    `display_order` = VALUES(`display_order`),
    `is_featured`   = VALUES(`is_featured`),
    `version`       = `categories`.`version` + 1;

-- --------------------------------------------------------------------------
-- Subcategories. parent_id is resolved by slug so ids are never hard-coded.
-- --------------------------------------------------------------------------
INSERT INTO `categories` (`uuid`, `parent_id`, `slug`, `name`, `display_order`)
SELECT UUID(), p.`id`, s.`slug`, s.`name`, s.`display_order`
FROM (
    SELECT 'spices'     AS `parent_slug`, 'whole-spices'     AS `slug`, 'Whole Spices'      AS `name`, 10 AS `display_order`
    UNION ALL SELECT 'spices',     'ground-spices',    'Ground Spices',     20
    UNION ALL SELECT 'spices',     'spice-blends',     'Spice Blends',      30
    UNION ALL SELECT 'dry-fruits', 'almonds',          'Almonds',           10
    UNION ALL SELECT 'dry-fruits', 'cashews',          'Cashews',           20
    UNION ALL SELECT 'dry-fruits', 'raisins-dates',    'Raisins & Dates',   30
    UNION ALL SELECT 'dry-fruits', 'pistachios',       'Pistachios',        40
    UNION ALL SELECT 'seeds',      'pumpkin-seeds',    'Pumpkin Seeds',     10
    UNION ALL SELECT 'seeds',      'chia-flax',        'Chia & Flax',       20
) s
JOIN `categories` p ON p.`slug` = s.`parent_slug`
ON DUPLICATE KEY UPDATE
    `name`          = VALUES(`name`),
    `display_order` = VALUES(`display_order`),
    `version`       = `categories`.`version` + 1;

-- --------------------------------------------------------------------------
-- Demonstration products
-- --------------------------------------------------------------------------
INSERT INTO `products` (
    `uuid`, `category_id`, `product_code`, `slug`, `name`, `brand`,
    `short_description`, `description`, `ingredients`, `storage_instructions`,
    `shelf_life_days`, `origin_country`, `origin_region`, `hsn_code`, `gst_rate`,
    `is_organic`, `status`, `published_date`, `is_featured`, `display_order`,
    `search_keywords`
)
SELECT
    UUID(), c.`id`, v.`product_code`, v.`slug`, v.`name`, v.`brand`,
    v.`short_description`, v.`description`, v.`ingredients`, v.`storage_instructions`,
    v.`shelf_life_days`, 'India', v.`origin_region`, v.`hsn_code`, v.`gst_rate`,
    v.`is_organic`, 'published', NOW(), v.`is_featured`, v.`display_order`,
    v.`search_keywords`
FROM (
    SELECT
        'ground-spices' AS `category_slug`,
        'DEMO-TURMERIC' AS `product_code`,
        'organic-turmeric-powder' AS `slug`,
        'Organic Turmeric Powder' AS `name`,
        'Spice & Dry Fruits' AS `brand`,
        'Single-origin Erode turmeric, stone-ground, 3.5% curcumin' AS `short_description`,
        'Sun-dried Erode turmeric fingers, stone-ground in small batches to protect the volatile oils. Deep ochre colour, earthy aroma, no added colour or starch.' AS `description`,
        '100% turmeric (Curcuma longa)' AS `ingredients`,
        'Store in a cool, dry place away from direct sunlight. Keep the pouch sealed.' AS `storage_instructions`,
        540 AS `shelf_life_days`,
        'Erode, Tamil Nadu' AS `origin_region`,
        '09103020' AS `hsn_code`,
        5.00 AS `gst_rate`,
        1 AS `is_organic`,
        1 AS `is_featured`,
        10 AS `display_order`,
        'haldi, manjal, arishina, curcumin, halad, yellow spice' AS `search_keywords`
    UNION ALL SELECT
        'almonds', 'DEMO-ALMOND', 'california-almonds',
        'California Almonds', 'Spice & Dry Fruits',
        'Hand-sorted premium California almonds, crisp and unsalted',
        'Non-pareil grade California almonds, hand-sorted for uniform size and screened for shell fragments. Raw and unsalted.',
        '100% almonds (Prunus dulcis)',
        'Refrigerate after opening to preserve crispness.',
        365, 'Imported, packed in Karnataka', '08021200', 12.00, 0, 1, 20,
        'badam, badaam, nuts, almond, akhrot alternative'
    UNION ALL SELECT
        'whole-spices', 'DEMO-CARDAMOM', 'green-cardamom-8mm',
        'Green Cardamom 8mm', 'Spice & Dry Fruits',
        'Bold 8mm Idukki cardamom pods, intensely aromatic',
        'Grade AGEB 8mm green cardamom from the Idukki hills. Plump, tightly closed pods with a high volatile-oil content.',
        '100% green cardamom (Elettaria cardamomum)',
        'Keep in an airtight container; do not refrigerate.',
        450, 'Idukki, Kerala', '09083110', 5.00, 0, 1, 30,
        'elaichi, elachi, yelakkai, cardamom, hari elaichi'
) v
JOIN `categories` c ON c.`slug` = v.`category_slug`
ON DUPLICATE KEY UPDATE
    `name`              = VALUES(`name`),
    `short_description` = VALUES(`short_description`),
    `description`       = VALUES(`description`),
    `status`            = VALUES(`status`),
    `search_keywords`   = VALUES(`search_keywords`),
    `version`           = `products`.`version` + 1;

-- --------------------------------------------------------------------------
-- Variants (pack sizes). Weight is mandatory: BR-006 and BR-007 depend on it.
-- --------------------------------------------------------------------------
INSERT INTO `product_variants` (
    `uuid`, `product_id`, `sku`, `variant_name`, `weight_grams`,
    `packed_weight_grams`, `pack_type`, `mrp`, `selling_price`, `offer_price`,
    `offer_end_date`, `is_default`, `display_order`
)
SELECT
    UUID(), p.`id`, v.`sku`, v.`variant_name`, v.`weight_grams`,
    v.`packed_weight_grams`, v.`pack_type`, v.`mrp`, v.`selling_price`, v.`offer_price`,
    CASE WHEN v.`offer_price` IS NOT NULL THEN DATE_ADD(NOW(), INTERVAL 30 DAY) ELSE NULL END,
    v.`is_default`, v.`display_order`
FROM (
    SELECT 'DEMO-TURMERIC' AS `product_code`, 'DEMO-TURMERIC-100' AS `sku`, '100 g pouch' AS `variant_name`,
           100 AS `weight_grams`, 130 AS `packed_weight_grams`, 'pouch' AS `pack_type`,
           149.00 AS `mrp`, 129.00 AS `selling_price`, NULL AS `offer_price`, 0 AS `is_default`, 10 AS `display_order`
    UNION ALL SELECT 'DEMO-TURMERIC', 'DEMO-TURMERIC-250', '250 g pouch', 250, 295, 'pouch', 349.00, 299.00, 269.00, 1, 20
    UNION ALL SELECT 'DEMO-TURMERIC', 'DEMO-TURMERIC-500', '500 g pouch', 500, 560, 'pouch', 649.00, 559.00, NULL, 0, 30
    UNION ALL SELECT 'DEMO-ALMOND',   'DEMO-ALMOND-250',   '250 g pouch', 250, 290, 'pouch', 499.00, 449.00, NULL, 1, 10
    UNION ALL SELECT 'DEMO-ALMOND',   'DEMO-ALMOND-500',   '500 g pouch', 500, 555, 'pouch', 949.00, 849.00, 799.00, 0, 20
    UNION ALL SELECT 'DEMO-ALMOND',   'DEMO-ALMOND-1000',  '1 kg jar',   1000, 1120, 'jar',  1799.00, 1599.00, NULL, 0, 30
    UNION ALL SELECT 'DEMO-CARDAMOM', 'DEMO-CARDAMOM-050', '50 g jar',     50,  85, 'jar',   399.00, 359.00, NULL, 1, 10
    UNION ALL SELECT 'DEMO-CARDAMOM', 'DEMO-CARDAMOM-100', '100 g jar',   100, 140, 'jar',   749.00, 679.00, 629.00, 0, 20
) v
JOIN `products` p ON p.`product_code` = v.`product_code`
ON DUPLICATE KEY UPDATE
    `variant_name`  = VALUES(`variant_name`),
    `mrp`           = VALUES(`mrp`),
    `selling_price` = VALUES(`selling_price`),
    `offer_price`   = VALUES(`offer_price`),
    `is_default`    = VALUES(`is_default`),
    `version`       = `product_variants`.`version` + 1;

-- --------------------------------------------------------------------------
-- Nutrition (per 100 g, as printed on the label)
-- --------------------------------------------------------------------------
INSERT INTO `product_nutrition` (
    `uuid`, `product_id`, `serving_size_g`, `energy_kcal`, `protein_g`,
    `total_fat_g`, `carbohydrate_g`, `dietary_fibre_g`, `sodium_mg`, `iron_mg`, `allergen_info`
)
SELECT UUID(), p.`id`, 100, v.`energy_kcal`, v.`protein_g`, v.`total_fat_g`,
       v.`carbohydrate_g`, v.`dietary_fibre_g`, v.`sodium_mg`, v.`iron_mg`, v.`allergen_info`
FROM (
    SELECT 'DEMO-TURMERIC' AS `product_code`, 354.00 AS `energy_kcal`, 7.80 AS `protein_g`,
           9.90 AS `total_fat_g`, 64.90 AS `carbohydrate_g`, 21.10 AS `dietary_fibre_g`,
           38.00 AS `sodium_mg`, 41.40 AS `iron_mg`,
           'Packed in a facility that also handles tree nuts.' AS `allergen_info`
    UNION ALL SELECT 'DEMO-ALMOND', 579.00, 21.20, 49.90, 21.60, 12.50, 1.00, 3.70,
           'Contains tree nuts (almonds).'
    UNION ALL SELECT 'DEMO-CARDAMOM', 311.00, 10.80, 6.70, 68.50, 28.00, 18.00, 14.00,
           'Packed in a facility that also handles tree nuts.'
) v
JOIN `products` p ON p.`product_code` = v.`product_code`
ON DUPLICATE KEY UPDATE
    `energy_kcal` = VALUES(`energy_kcal`),
    `protein_g`   = VALUES(`protein_g`),
    `version`     = `product_nutrition`.`version` + 1;

-- --------------------------------------------------------------------------
-- Extra specifications
-- --------------------------------------------------------------------------
INSERT INTO `product_attributes` (`uuid`, `product_id`, `attribute_name`, `attribute_value`, `display_order`)
SELECT UUID(), p.`id`, v.`attribute_name`, v.`attribute_value`, v.`display_order`
FROM (
    SELECT 'DEMO-TURMERIC' AS `product_code`, 'Curcumin content' AS `attribute_name`, '3.5%' AS `attribute_value`, 10 AS `display_order`
    UNION ALL SELECT 'DEMO-TURMERIC', 'Grind',        'Stone-ground, fine', 20
    UNION ALL SELECT 'DEMO-ALMOND',   'Grade',        'Non-pareil',         10
    UNION ALL SELECT 'DEMO-ALMOND',   'Count per kg', '450 - 500',          20
    UNION ALL SELECT 'DEMO-CARDAMOM', 'Grade',        'AGEB 8mm',           10
) v
JOIN `products` p ON p.`product_code` = v.`product_code`
ON DUPLICATE KEY UPDATE
    `attribute_value` = VALUES(`attribute_value`),
    `version`         = `product_attributes`.`version` + 1;
