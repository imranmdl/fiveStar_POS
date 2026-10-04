-- Counter (POS) sales: was the customer handed their items?
--
-- A cashier rings a sale up and, almost always, the customer walks out with the
-- goods — that is "delivered". Sometimes it is not (bought now, collected later,
-- or sent on to the customer), so the till asks, and a sale can be marked
-- delivered afterwards.
--
-- Every sale that already exists was over the counter, so it is delivered.
ALTER TABLE `pos_sales`
    ADD COLUMN `delivery_status` ENUM('delivered','pending') NOT NULL DEFAULT 'delivered' AFTER `status`,
    ADD COLUMN `delivered_date`  DATETIME NULL AFTER `delivery_status`,
    ADD KEY `idx_pos_sales_delivery` (`delivery_status`);

UPDATE `pos_sales` SET `delivered_date` = `created_date` WHERE `delivery_status` = 'delivered' AND `delivered_date` IS NULL;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('037_pos_sale_delivery', 37, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
