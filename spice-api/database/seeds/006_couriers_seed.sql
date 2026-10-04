-- ============================================================================
--  Seed 006 - Couriers, serviceability and rate cards
--
--  Rates here are plausible Indian market figures for a small merchant, not
--  quotes. They must be replaced with the merchant's negotiated contract before
--  go-live, or every cost comparison BR-007 makes is comparing fiction.
--
--  The sandbox courier exists so the delivery flow can be exercised without a
--  courier account. Its adapter refuses to run outside local/testing.
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'delivery', 'courier_driver',              'sandbox', 'string', 'Active courier adapter: shiprocket or sandbox', 0),
    (UUID(), 'delivery', 'courier_selection_strategy',  'balanced','string', 'BR-007 weighting: cheapest, fastest, balanced or reliable', 0),
    (UUID(), 'delivery', 'courier_auto_assign',         '1',       'bool',   'Assign a courier automatically once an order is packed', 0),
    (UUID(), 'delivery', 'default_box_length_mm',       '250',     'int',    'Assumed when a variant has no measured dimensions', 0),
    (UUID(), 'delivery', 'default_box_width_mm',        '200',     'int',    'Assumed when a variant has no measured dimensions', 0),
    (UUID(), 'delivery', 'default_box_height_mm',       '120',     'int',    'Assumed when a variant has no measured dimensions', 0),
    (UUID(), 'delivery', 'packaging_weight_grams',      '80',      'int',    'Carton, filler and tape added to every parcel', 0),
    (UUID(), 'delivery', 'pickup_contact_name',         'Dispatch Desk', 'string', 'Contact given to the courier for pickup', 0),
    (UUID(), 'delivery', 'pickup_contact_phone',        '',        'string', 'Contact number given to the courier', 0),
    (UUID(), 'delivery', 'pickup_address',              '',        'string', 'Warehouse pickup address', 0),
    (UUID(), 'delivery', 'pickup_pincode',              '560001',  'string', 'Origin pincode; decides zone for rate lookup', 0)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Couriers
--
-- SANDBOX is deliberately priciest and slowest so it never wins a scoring test
-- by accident and mask a selection bug.
-- ---------------------------------------------------------------------------
INSERT INTO `couriers`
    (`uuid`, `code`, `name`, `adapter`, `channel_code`, `tracking_url_template`,
     `min_weight_grams`, `max_weight_grams`, `max_order_value`, `handles_fragile`,
     `priority`, `reliability_score`, `volumetric_divisor`, `support_phone`, `is_enabled`)
VALUES
    (UUID(), 'DELHIVERY',  'Delhivery',   'shiprocket', '10',
     'https://www.delhivery.com/track/package/{awb}',      50, 30000, 50000.00, 1,  10, 88.00, 5000, '1800-000-000', 1),
    (UUID(), 'BLUEDART',   'Blue Dart',   'shiprocket', '20',
     'https://www.bluedart.com/tracking/{awb}',            50, 25000, 100000.00, 1, 20, 92.00, 5000, '1860-233-1234', 1),
    (UUID(), 'XPRESSBEES', 'XpressBees',  'shiprocket', '30',
     'https://www.xpressbees.com/track?awb={awb}',         50, 20000, 30000.00, 1,  30, 84.00, 5000, NULL, 1),
    (UUID(), 'DTDC',       'DTDC',        'shiprocket', '40',
     'https://www.dtdc.in/tracking/{awb}',                 50, 25000, 25000.00, 0,  40, 79.00, 5000, NULL, 1),
    (UUID(), 'SHADOWFAX',  'Shadowfax',   'shiprocket', '50',
     'https://shadowfax.in/track/{awb}',                   50, 10000, 15000.00, 0,  50, 81.00, 4000, NULL, 1),
    (UUID(), 'SANDBOX',    'Sandbox Courier', 'sandbox', NULL,
     'https://example.test/track/{awb}',                    1, 50000, NULL, 1,      99, 75.00, 5000, NULL, 1)
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `version` = `couriers`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Serviceability, by pincode prefix (longest match wins)
--
--   5 = Karnataka and the south, 4 = Maharashtra/Gujarat, 1 = Delhi and north,
--   7 = the north-east, 6 = Kerala/Tamil Nadu.
-- ---------------------------------------------------------------------------
INSERT INTO `courier_serviceability` (`uuid`, `courier_id`, `pincode_prefix`, `is_serviceable`, `sla_min_days`, `sla_max_days`, `is_express`, `notes`)
SELECT UUID(), c.`id`, v.`prefix`, v.`serviceable`, v.`sla_min`, v.`sla_max`, v.`express`, v.`notes`
FROM `couriers` c
JOIN (
    SELECT 'DELHIVERY' AS code, '5'  AS prefix, 1 AS serviceable, 1 AS sla_min, 3 AS sla_max, 0 AS express, NULL AS notes UNION ALL
    SELECT 'DELHIVERY', '4', 1, 2, 4, 0, NULL UNION ALL
    SELECT 'DELHIVERY', '1', 1, 3, 5, 0, NULL UNION ALL
    SELECT 'DELHIVERY', '6', 1, 2, 4, 0, NULL UNION ALL
    SELECT 'DELHIVERY', '7', 1, 5, 9, 0, 'North-east, longer transit' UNION ALL

    SELECT 'BLUEDART', '5', 1, 1, 2, 1, 'Express network' UNION ALL
    SELECT 'BLUEDART', '4', 1, 1, 3, 1, NULL UNION ALL
    SELECT 'BLUEDART', '1', 1, 2, 3, 1, NULL UNION ALL
    SELECT 'BLUEDART', '6', 1, 2, 3, 0, NULL UNION ALL
    SELECT 'BLUEDART', '79', 0, 5, 9, 0, 'Not serviced' UNION ALL

    SELECT 'XPRESSBEES', '5', 1, 2, 4, 0, NULL UNION ALL
    SELECT 'XPRESSBEES', '4', 1, 2, 5, 0, NULL UNION ALL
    SELECT 'XPRESSBEES', '1', 1, 3, 6, 0, NULL UNION ALL
    SELECT 'XPRESSBEES', '6', 1, 3, 5, 0, NULL UNION ALL

    SELECT 'DTDC', '5', 1, 2, 5, 0, NULL UNION ALL
    SELECT 'DTDC', '4', 1, 3, 6, 0, NULL UNION ALL
    SELECT 'DTDC', '6', 1, 3, 6, 0, NULL UNION ALL

    SELECT 'SHADOWFAX', '560', 1, 1, 1, 1, 'Same-city only' UNION ALL
    SELECT 'SHADOWFAX', '5600', 1, 1, 1, 1, 'Bengaluru metro' UNION ALL

    SELECT 'SANDBOX', '', 1, 3, 7, 0, 'Serves everywhere, for testing'
) v ON v.`code` = c.`code`
ON DUPLICATE KEY UPDATE
    `sla_min_days` = VALUES(`sla_min_days`),
    `sla_max_days` = VALUES(`sla_max_days`),
    `version`      = `courier_serviceability`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Rate cards, by zone and weight slab
-- ---------------------------------------------------------------------------
INSERT INTO `courier_rate_cards`
    (`uuid`, `courier_id`, `zone_code`, `min_weight_grams`, `max_weight_grams`,
     `base_charge`, `per_kg_charge`, `fuel_surcharge_pct`, `handling_charge`)
SELECT UUID(), c.`id`, v.`zone`, v.`min_w`, v.`max_w`, v.`base`, v.`per_kg`, v.`fuel`, v.`handling`
FROM `couriers` c
JOIN (
    -- Delhivery: cheapest at volume, middling speed
    SELECT 'DELHIVERY' AS code, 'LOCAL' AS zone, 0 AS min_w, 500 AS max_w, 32.00 AS base, 0.00 AS per_kg, 12.00 AS fuel, 5.00 AS handling UNION ALL
    SELECT 'DELHIVERY', 'LOCAL',  500, 2000,  45.00, 18.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'LOCAL', 2000, NULL,  70.00, 16.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'KA',       0,  500,  38.00,  0.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'KA',     500, 2000,  55.00, 22.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'KA',    2000, NULL,  85.00, 20.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'SOUTH',    0,  500,  46.00,  0.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'SOUTH',  500, 2000,  68.00, 28.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'SOUTH', 2000, NULL, 105.00, 26.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REST',     0,  500,  58.00,  0.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REST',   500, 2000,  88.00, 36.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REST',  2000, NULL, 140.00, 34.00, 12.00, 5.00 UNION ALL
    SELECT 'DELHIVERY', 'REMOTE',   0,  500,  85.00,  0.00, 12.00, 10.00 UNION ALL
    SELECT 'DELHIVERY', 'REMOTE', 500, NULL, 130.00, 55.00, 12.00, 10.00 UNION ALL

    -- Blue Dart: fastest, dearest
    SELECT 'BLUEDART', 'LOCAL',     0,  500,  52.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'LOCAL',   500, 2000,  74.00, 28.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'LOCAL',  2000, NULL, 112.00, 26.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'KA',        0,  500,  60.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'KA',      500, 2000,  88.00, 34.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'KA',     2000, NULL, 132.00, 32.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'SOUTH',     0,  500,  72.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'SOUTH',   500, 2000, 104.00, 42.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'SOUTH',  2000, NULL, 158.00, 40.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'REST',      0,  500,  88.00,  0.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'REST',    500, 2000, 128.00, 52.00, 15.00, 8.00 UNION ALL
    SELECT 'BLUEDART', 'REST',   2000, NULL, 195.00, 50.00, 15.00, 8.00 UNION ALL

    -- XpressBees: cheap, slower
    SELECT 'XPRESSBEES', 'LOCAL',   0,  500,  29.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'LOCAL', 500, 2000,  42.00, 17.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'LOCAL',2000, NULL,  66.00, 15.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'KA',      0,  500,  35.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'KA',    500, 2000,  51.00, 21.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'KA',   2000, NULL,  80.00, 19.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'SOUTH',   0,  500,  43.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'SOUTH', 500, 2000,  63.00, 26.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'SOUTH',2000, NULL,  98.00, 24.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'REST',    0,  500,  54.00,  0.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'REST',  500, 2000,  82.00, 34.00, 10.00, 4.00 UNION ALL
    SELECT 'XPRESSBEES', 'REST', 2000, NULL, 130.00, 32.00, 10.00, 4.00 UNION ALL

    -- DTDC: cheapest small parcels, weak on heavy
    SELECT 'DTDC', 'LOCAL',   0,  500,  27.00,  0.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'LOCAL', 500, 2000,  44.00, 20.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'LOCAL',2000, NULL,  78.00, 22.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'KA',      0,  500,  33.00,  0.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'KA',    500, 2000,  54.00, 24.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'KA',   2000, NULL,  95.00, 26.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'SOUTH',   0,  500,  41.00,  0.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'SOUTH', 500, 2000,  67.00, 30.00,  8.00, 3.00 UNION ALL
    SELECT 'DTDC', 'SOUTH',2000, NULL, 118.00, 32.00,  8.00, 3.00 UNION ALL

    -- Shadowfax: same-city only, flat and fast
    SELECT 'SHADOWFAX', 'LOCAL',   0, 2000,  40.00,  0.00,  0.00, 0.00 UNION ALL
    SELECT 'SHADOWFAX', 'LOCAL',2000, NULL,  62.00, 12.00,  0.00, 0.00 UNION ALL

    -- Sandbox: dearest everywhere, so it never wins a scoring test by accident
    SELECT 'SANDBOX', 'LOCAL',  0, NULL, 200.00, 50.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'KA',     0, NULL, 220.00, 55.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'SOUTH',  0, NULL, 240.00, 60.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'REST',   0, NULL, 260.00, 65.00, 0.00, 0.00 UNION ALL
    SELECT 'SANDBOX', 'REMOTE', 0, NULL, 300.00, 75.00, 0.00, 0.00
) v ON v.`code` = c.`code`
ON DUPLICATE KEY UPDATE
    `base_charge`   = VALUES(`base_charge`),
    `per_kg_charge` = VALUES(`per_kg_charge`),
    `version`       = `courier_rate_cards`.`version` + 1;

-- Numbering counters for shipments and manifests in the current financial year.
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('shipment:', fy.`year`), 'order', 'SHP', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('manifest:', fy.`year`), 'order', 'MFT', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;
