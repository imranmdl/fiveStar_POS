-- ============================================================================
--  Rollback for migration 010 - Buy X Get Y offers
--
--  Any live buy-X-get-Y offer is retired first. Leaving one with discount_type
--  'free_items' behind would break the column change, and silently converting it
--  to a percentage would give customers a different deal from the one advertised.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `vw_offer_performance`;

UPDATE `offers`
   SET `status` = 'expired', `updated_date` = NOW(), `version` = `version` + 1
 WHERE `discount_type` = 'free_items';

ALTER TABLE `offers` DROP CONSTRAINT `chk_offers_quantities_only_for_free_items`;
ALTER TABLE `offers` DROP CONSTRAINT `chk_offers_bogo_quantities`;

DELETE FROM `offers` WHERE `discount_type` = 'free_items';

ALTER TABLE `offers`
    DROP COLUMN `max_free_items_per_order`,
    DROP COLUMN `free_item_scope`,
    DROP COLUMN `get_quantity`,
    DROP COLUMN `buy_quantity`;

ALTER TABLE `offers`
    MODIFY COLUMN `discount_type`
        ENUM('none','percentage','flat','free_delivery') NOT NULL DEFAULT 'none';

UPDATE `offers` SET `offer_type` = 'festival' WHERE `offer_type` = 'bogo';

ALTER TABLE `offers`
    MODIFY COLUMN `offer_type`
        ENUM('festival','flash_sale','deal_of_day','category','combo','free_shipping')
        NOT NULL DEFAULT 'festival';

DELETE FROM `schema_migrations` WHERE `migration` = '010_bogo_offers';

SET FOREIGN_KEY_CHECKS = 1;
