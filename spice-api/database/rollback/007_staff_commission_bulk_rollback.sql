-- ============================================================================
--  Rollback for migration 007 - Staff Operations, Commission and Bulk Orders
--
--  WARNING: this destroys the commission accrual ledger, which is the only
--  record of what staff are owed. Export before running:
--    mysqldump <db> commission_entries commission_settlements > commission.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `vw_commission_summary`;
DROP VIEW IF EXISTS `vw_executive_workload`;

DROP TRIGGER IF EXISTS `trg_commission_entries_amount_immutable`;

DROP TABLE IF EXISTS `bulk_order_quote_items`;
DROP TABLE IF EXISTS `bulk_order_quotes`;
DROP TABLE IF EXISTS `bulk_order_enquiries`;
DROP TABLE IF EXISTS `commission_entries`;
DROP TABLE IF EXISTS `commission_settlements`;
DROP TABLE IF EXISTS `commission_rules`;
DROP TABLE IF EXISTS `packing_slips`;
DROP TABLE IF EXISTS `order_assignments`;
DROP TABLE IF EXISTS `staff_profiles`;

DELETE FROM `schema_migrations` WHERE `migration` = '007_staff_commission_bulk';

SET FOREIGN_KEY_CHECKS = 1;
