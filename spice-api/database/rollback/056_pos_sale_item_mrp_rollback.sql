-- Rollback 056 — drop the MRP snapshot on till sale lines.
ALTER TABLE `pos_sale_items` DROP COLUMN `mrp`;
DELETE FROM `schema_migrations` WHERE `migration` = '056_pos_sale_item_mrp';
