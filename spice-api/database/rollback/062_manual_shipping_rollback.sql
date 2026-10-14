-- Rollback 062. Shipments recorded against the MANUAL courier keep their
-- rows (the courier row stays, disabled) so order history is not orphaned;
-- only the two columns are dropped.
ALTER TABLE `shipments`
    DROP COLUMN `carrier_name`,
    DROP COLUMN `tracking_url`;

DELETE FROM `schema_migrations` WHERE `migration` = '062_manual_shipping';
