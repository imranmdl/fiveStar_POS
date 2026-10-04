-- ============================================================================
--  Rollback for migration 023 - POS payment methods / applied-offer trace
--
--  Only safe to run if no pos_sales row has been written with
--  payment_method IN ('phonepe','scanner') yet (a narrowing ENUM MODIFY
--  truncates unrepresentable values to '', corrupting those rows).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `pos_sale_items`
    DROP COLUMN `applied_offer_code`;

ALTER TABLE `pos_sales`
    MODIFY COLUMN `payment_method` ENUM('cash', 'upi', 'card', 'other')
                                    NOT NULL;

DELETE FROM `schema_migrations` WHERE `migration` = '023_pos_payment_methods';

SET FOREIGN_KEY_CHECKS = 1;
