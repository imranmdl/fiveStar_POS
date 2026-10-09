-- Rollback 059 — restore the original per-carrier tracking templates.
-- Links already saved on orders keep the Shiprocket tracking page (the old
-- carrier links did not show the parcel, so they are not restored).
UPDATE `couriers` SET `tracking_url_template` = 'https://www.delhivery.com/track/package/{awb}' WHERE `code` = 'DELHIVERY';
UPDATE `couriers` SET `tracking_url_template` = 'https://www.bluedart.com/tracking/{awb}'       WHERE `code` = 'BLUEDART';
UPDATE `couriers` SET `tracking_url_template` = 'https://www.xpressbees.com/track?awb={awb}'   WHERE `code` = 'XPRESSBEES';
UPDATE `couriers` SET `tracking_url_template` = 'https://www.dtdc.in/tracking/{awb}'            WHERE `code` = 'DTDC';
UPDATE `couriers` SET `tracking_url_template` = 'https://shadowfax.in/track/{awb}'             WHERE `code` = 'SHADOWFAX';

DELETE FROM `schema_migrations` WHERE `migration` = '059_shiprocket_tracking_link';
