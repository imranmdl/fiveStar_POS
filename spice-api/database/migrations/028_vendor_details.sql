-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 028 - vendors: company/tax/bank details and a human vendor code
--
--  Purely additive — every existing vendor row and every existing read of
--  `vendors` keeps working unchanged. `vendor_code` is backfilled here for
--  rows that already exist (a simple, guaranteed-unique id-derived value);
--  every vendor created from now on gets a real one from NumberingService
--  (VendorService::create(), mirroring how purchase orders already get their
--  po_number — see NumberingService::nextVendorCode()).
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `vendors`
    ADD COLUMN `vendor_code`           VARCHAR(20)  NULL AFTER `uuid`,
    ADD COLUMN `company_name`          VARCHAR(160) NULL AFTER `name`,
    ADD COLUMN `pan`                   VARCHAR(10)  NULL AFTER `gstin`,
    ADD COLUMN `bank_account_name`     VARCHAR(160) NULL AFTER `pan`,
    ADD COLUMN `bank_account_number`   VARCHAR(30)  NULL AFTER `bank_account_name`,
    ADD COLUMN `bank_ifsc`             VARCHAR(11)  NULL AFTER `bank_account_number`,
    ADD COLUMN `bank_name`             VARCHAR(120) NULL AFTER `bank_ifsc`,
    ADD COLUMN `payment_terms`         VARCHAR(120) NULL COMMENT 'Free text, e.g. "Net 30"' AFTER `bank_name`;

-- Backfill: guaranteed-unique, distinguishable from the NumberingService
-- format (VEN2627000001) any vendor created after this migration gets, so
-- the two schemes never collide.
UPDATE `vendors` SET `vendor_code` = CONCAT('VEN', LPAD(`id`, 10, '0')) WHERE `vendor_code` IS NULL;

ALTER TABLE `vendors`
    ADD UNIQUE KEY `uq_vendors_code` (`vendor_code`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('028_vendor_details', 28, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
