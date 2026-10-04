-- ============================================================================
--  Rollback for migration 011 - Campaign collections
--
--  Adverts pointing at a collection are retired first: leaving them would break
--  the enum change, and silently repointing them somewhere else would send
--  customers to a page nobody chose.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `vw_collection_summary`;

UPDATE `banners`
   SET `is_active` = 0, `link_type` = 'none', `link_value` = NULL,
       `updated_date` = NOW(), `version` = `version` + 1
 WHERE `link_type` = 'collection';

ALTER TABLE `banners`
    MODIFY COLUMN `link_type`
        ENUM('none','category','product','url','offer') NOT NULL DEFAULT 'none';

DROP TABLE IF EXISTS `collection_items`;
DROP TABLE IF EXISTS `collections`;

DELETE FROM `schema_migrations` WHERE `migration` = '011_collections';

SET FOREIGN_KEY_CHECKS = 1;
