-- ============================================================================
--  Seed 007 - Staff operations, commission rules and settings
--
--  Commission figures are illustrative. Replace them with the merchant's actual
--  incentive scheme before go-live: staff will be paid these numbers.
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'operations', 'auto_assign_orders',          '1',    'bool',   'Assign confirmed orders to executives automatically', 0),
    (UUID(), 'operations', 'assignment_strategy',         'least_loaded', 'string', 'least_loaded or round_robin', 0),
    (UUID(), 'operations', 'assignment_sla_hours',        '8',    'int',    'Hours an executive has to complete an assignment', 0),
    (UUID(), 'operations', 'default_executive_capacity',  '25',   'int',    'Concurrent open orders per executive', 0),
    (UUID(), 'operations', 'commission_auto_accrue',      '1',    'bool',   'Accrue fulfilment commission when an order is delivered', 0),
    (UUID(), 'operations', 'commission_auto_approve',     '0',    'bool',   'Approve accruals without a supervisor reviewing them', 0),
    (UUID(), 'bulk',       'bulk_quote_validity_days',    '14',   'int',    'How long a bulk quote stays open', 1),
    (UUID(), 'bulk',       'bulk_minimum_order_value',    '10000','string', 'Smallest bulk enquiry worth quoting', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Commission rules
--
-- EXEC-STANDARD is the everyday rule. EXEC-HIGHVALUE overrides it on large
-- orders via a lower `priority`, because a flat fee on a Rs 25,000 order is a
-- poor incentive to handle it carefully.
-- ---------------------------------------------------------------------------
INSERT INTO `commission_rules`
    (`uuid`, `code`, `name`, `description`, `scope`, `calculation`,
     `flat_amount`, `percentage`, `tiers`, `min_order_value`, `max_commission`,
     `applies_to_role`, `priority`, `status`)
VALUES
    (UUID(), 'EXEC-STANDARD', 'Executive fulfilment fee',
     'Flat fee for each order an executive packs and dispatches.',
     'executive_fulfilment', 'flat_per_order',
     15.00, NULL, NULL, NULL, NULL, 'executive', 100, 'active'),

    (UUID(), 'EXEC-HIGHVALUE', 'High-value order incentive',
     'Replaces the flat fee on orders above Rs 5,000, capped so one large order cannot dominate a month.',
     'executive_fulfilment', 'percentage_of_order',
     NULL, 1.50, NULL, 5000.00, 250.00, 'executive', 50, 'active'),

    (UUID(), 'SUP-OVERRIDE', 'Supervisor override',
     'Paid to the supervisor of the executive who fulfilled the order.',
     'supervisor_override', 'flat_per_order',
     5.00, NULL, NULL, NULL, NULL, 'supervisor', 100, 'active'),

    (UUID(), 'EXEC-VOLUME', 'Volume bonus (draft)',
     'Tiered bonus once an executive passes a monthly volume. Draft until the scheme is agreed.',
     'campaign', 'tiered_by_volume',
     NULL, NULL,
     '[{"min_orders":0,"amount":0},{"min_orders":100,"amount":500},{"min_orders":250,"amount":1500}]',
     NULL, NULL, 'executive', 200, 'draft')
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `version` = `commission_rules`.`version` + 1;

-- Numbering counters for the operational documents added by this phase.
-- The whole SELECT is wrapped in a derived table on purpose. Written directly
-- as `... CROSS JOIN (...) v ON DUPLICATE KEY UPDATE`, MySQL parses the `ON` as
-- the join condition rather than the start of the upsert clause, and reports a
-- syntax error pointing at `KEY UPDATE` with no hint of the real cause.
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT * FROM (
    SELECT UUID() AS `uuid`,
           CONCAT(v.`counter_key`, ':', fy.`year`) AS `sequence_key`,
           'order' AS `purpose`,
           v.`counter_prefix` AS `prefix`,
           fy.`year` AS `financial_year`,
           0 AS `last_number`
    FROM (
        SELECT CASE WHEN MONTH(NOW()) >= 4
                    THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                    ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
               END AS `year`
    ) fy
    CROSS JOIN (
        SELECT 'packing_slip' AS `counter_key`, 'PS'  AS `counter_prefix` UNION ALL
        SELECT 'bulk_enquiry',                  'BE'  UNION ALL
        SELECT 'bulk_quote',                    'BQ'  UNION ALL
        SELECT 'settlement',                    'STL'
    ) v
) src
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;
