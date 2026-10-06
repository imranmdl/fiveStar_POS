-- ===========================================================================
-- 052 — Shop name: "5 Star Spices & Dry Fruits"
--
-- The shop trades as 5 Star (Five Star). Settings and demo products still
-- carried the placeholder name "Spice & Dry Fruits", which showed in
-- invoices, WhatsApp/SMS messages, wallet narrations and the referral text.
-- Only rows still holding the old placeholder change — a name an admin has
-- already set is left alone. The UPI payee name is not touched: it should
-- match the bank account and is edited under Settings → Payments.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

SET NAMES utf8mb4;

UPDATE `settings`
   SET `setting_value` = '5 Star Spices & Dry Fruits',
       `updated_date`  = NOW(),
       `version`       = `version` + 1
 WHERE `setting_key` IN ('store_name', 'seller_legal_name')
   AND `setting_value` = 'Spice & Dry Fruits';

UPDATE `products`
   SET `brand`        = '5 Star Spices & Dry Fruits',
       `updated_date` = NOW(),
       `version`      = `version` + 1
 WHERE `brand` = 'Spice & Dry Fruits';

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('052_five_star_brand_name', 52, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
