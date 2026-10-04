-- POS: which physical counter/shop rang up this sale — typed by the cashier,
-- not chosen from a warehouse list. Stock still deducts from a real
-- warehouse behind the scenes (pos_sales.warehouse_id, unchanged); this is a
-- free-text label purely for identifying the sale on receipts, reports and
-- history when more than one physical counter shares this till software.
ALTER TABLE `pos_sales`
    ADD COLUMN `shop_label` VARCHAR(120) NULL AFTER `warehouse_id`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('038_pos_sale_shop_label', 38, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
