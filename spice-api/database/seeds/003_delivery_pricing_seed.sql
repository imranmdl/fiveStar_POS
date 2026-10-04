-- ============================================================================
--  Seed 003 - Delivery zones, pincode map and charge slabs
--
--  Illustrative rates for an operation shipping out of Bengaluru. Replace the
--  charge amounts with your negotiated courier rates before going live; the
--  structure is what matters, not these numbers.
--
--  Idempotent: matched on zone code and (zone, min_weight_grams).
-- ============================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- Zones, ordered from cheapest to most expensive to serve.
-- --------------------------------------------------------------------------
INSERT INTO `delivery_zones` (`uuid`, `code`, `name`, `sla_min_days`, `sla_max_days`, `is_default`, `is_serviceable`)
VALUES
    (UUID(), 'LOCAL',  'Bengaluru local',            1, 2, 0, 1),
    (UUID(), 'KA',     'Rest of Karnataka',          2, 4, 0, 1),
    (UUID(), 'SOUTH',  'South India',                3, 5, 0, 1),
    (UUID(), 'REST',   'Rest of India',              4, 7, 1, 1),
    (UUID(), 'REMOTE', 'North-east, J&K and islands', 6, 12, 0, 1)
ON DUPLICATE KEY UPDATE
    `name`           = VALUES(`name`),
    `sla_min_days`   = VALUES(`sla_min_days`),
    `sla_max_days`   = VALUES(`sla_max_days`),
    `is_default`     = VALUES(`is_default`),
    `is_serviceable` = VALUES(`is_serviceable`),
    `version`        = `delivery_zones`.`version` + 1;

-- --------------------------------------------------------------------------
-- Pincode prefixes. Longest match wins, so a specific pincode can override a
-- broad range without touching the rest of the map.
-- --------------------------------------------------------------------------
INSERT INTO `delivery_pincode_map` (`uuid`, `zone_id`, `pincode_prefix`, `label`)
SELECT UUID(), z.`id`, m.`pincode_prefix`, m.`label`
FROM (
    SELECT 'LOCAL'  AS `zone_code`, '560' AS `pincode_prefix`, 'Bengaluru urban'   AS `label`
    UNION ALL SELECT 'LOCAL',  '561', 'Bengaluru rural'
    UNION ALL SELECT 'KA',     '56',  'Karnataka (56x)'
    UNION ALL SELECT 'KA',     '57',  'Karnataka (57x)'
    UNION ALL SELECT 'KA',     '58',  'Karnataka (58x)'
    UNION ALL SELECT 'KA',     '59',  'Karnataka (59x)'
    UNION ALL SELECT 'SOUTH',  '5',   'Andhra, Telangana, Karnataka belt'
    UNION ALL SELECT 'SOUTH',  '6',   'Tamil Nadu, Kerala'
    UNION ALL SELECT 'REST',   '1',   'Delhi, Haryana, Punjab, HP'
    UNION ALL SELECT 'REST',   '2',   'Uttar Pradesh, Uttarakhand'
    UNION ALL SELECT 'REST',   '3',   'Rajasthan, Gujarat'
    UNION ALL SELECT 'REST',   '4',   'Maharashtra, MP, Chhattisgarh, Goa'
    UNION ALL SELECT 'REST',   '7',   'West Bengal, Odisha, Bihar'
    UNION ALL SELECT 'REST',   '8',   'Bihar, Jharkhand'
    UNION ALL SELECT 'REMOTE', '19',  'Jammu & Kashmir, Ladakh'
    UNION ALL SELECT 'REMOTE', '78',  'Assam and north-east'
    UNION ALL SELECT 'REMOTE', '79',  'Arunachal, Nagaland, Manipur'
    UNION ALL SELECT 'REMOTE', '744', 'Andaman & Nicobar'
    UNION ALL SELECT 'REMOTE', '682', 'Lakshadweep routing'
) m
JOIN `delivery_zones` z ON z.`code` = m.`zone_code`
ON DUPLICATE KEY UPDATE
    `zone_id` = VALUES(`zone_id`),
    `label`   = VALUES(`label`),
    `version` = `delivery_pincode_map`.`version` + 1;

-- --------------------------------------------------------------------------
-- Weight bands. Every zone has an open-ended top band with a per-kg rate, so
-- there is no order weight the platform cannot quote.
-- --------------------------------------------------------------------------
INSERT INTO `delivery_charge_slabs` (
    `uuid`, `zone_id`, `min_weight_grams`, `max_weight_grams`,
    `charge_amount`, `per_extra_kg_amount`, `free_above_order_value`
)
SELECT UUID(), z.`id`, s.`min_weight_grams`, s.`max_weight_grams`,
       s.`charge_amount`, s.`per_extra_kg_amount`, s.`free_above_order_value`
FROM (
    -- LOCAL
    SELECT 'LOCAL' AS `zone_code`, 0 AS `min_weight_grams`, 500 AS `max_weight_grams`,
            29.00 AS `charge_amount`, 0.00 AS `per_extra_kg_amount`, 499.00 AS `free_above_order_value`
    UNION ALL SELECT 'LOCAL',  500,  1000,  39.00,  0.00, 499.00
    UNION ALL SELECT 'LOCAL', 1000,  2000,  59.00,  0.00, 499.00
    UNION ALL SELECT 'LOCAL', 2000,  NULL,  79.00, 20.00, 499.00
    -- KA
    UNION ALL SELECT 'KA',       0,   500,  45.00,  0.00, 799.00
    UNION ALL SELECT 'KA',     500,  1000,  65.00,  0.00, 799.00
    UNION ALL SELECT 'KA',    1000,  2000,  89.00,  0.00, 799.00
    UNION ALL SELECT 'KA',    2000,  NULL, 119.00, 30.00, 799.00
    -- SOUTH
    UNION ALL SELECT 'SOUTH',    0,   500,  59.00,  0.00, 999.00
    UNION ALL SELECT 'SOUTH',  500,  1000,  85.00,  0.00, 999.00
    UNION ALL SELECT 'SOUTH', 1000,  2000, 119.00,  0.00, 999.00
    UNION ALL SELECT 'SOUTH', 2000,  NULL, 159.00, 40.00, 999.00
    -- REST
    UNION ALL SELECT 'REST',     0,   500,  75.00,  0.00, 999.00
    UNION ALL SELECT 'REST',   500,  1000, 109.00,  0.00, 999.00
    UNION ALL SELECT 'REST',  1000,  2000, 149.00,  0.00, 999.00
    UNION ALL SELECT 'REST',  2000,  NULL, 199.00, 55.00, 999.00
    -- REMOTE: no free-shipping threshold, freight is genuinely expensive
    UNION ALL SELECT 'REMOTE',   0,   500, 129.00,  0.00, NULL
    UNION ALL SELECT 'REMOTE', 500,  1000, 179.00,  0.00, NULL
    UNION ALL SELECT 'REMOTE',1000,  2000, 249.00,  0.00, NULL
    UNION ALL SELECT 'REMOTE',2000,  NULL, 329.00, 90.00, NULL
) s
JOIN `delivery_zones` z ON z.`code` = s.`zone_code`
ON DUPLICATE KEY UPDATE
    `max_weight_grams`       = VALUES(`max_weight_grams`),
    `charge_amount`          = VALUES(`charge_amount`),
    `per_extra_kg_amount`    = VALUES(`per_extra_kg_amount`),
    `free_above_order_value` = VALUES(`free_above_order_value`),
    `version`                = `delivery_charge_slabs`.`version` + 1;

-- --------------------------------------------------------------------------
-- Commerce settings used by the pricing engine.
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'cart',  'cart_max_line_items',   '50',   'int',    'Distinct pack sizes allowed in one cart', 0),
    (UUID(), 'cart',  'cart_abandon_after_days', '30', 'int',    'Idle days before an active cart is marked abandoned', 0),
    (UUID(), 'order', 'delivery_gst_rate',     '18',   'decimal','GST rate applied to the delivery charge', 0),
    (UUID(), 'order', 'prices_include_gst',    '1',    'bool',   'Indian MRP convention: displayed prices are GST-inclusive', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;
