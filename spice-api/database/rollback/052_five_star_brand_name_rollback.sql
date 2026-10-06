-- Rollback 052 — restore the placeholder shop name.
SET NAMES utf8mb4;

UPDATE `settings`
   SET `setting_value` = 'Spice & Dry Fruits', `updated_date` = NOW(), `version` = `version` + 1
 WHERE `setting_key` IN ('store_name', 'seller_legal_name')
   AND `setting_value` = '5 Star Spices & Dry Fruits';

UPDATE `products`
   SET `brand` = 'Spice & Dry Fruits', `updated_date` = NOW(), `version` = `version` + 1
 WHERE `brand` = '5 Star Spices & Dry Fruits';

DELETE FROM `schema_migrations` WHERE `migration` = '052_five_star_brand_name';
