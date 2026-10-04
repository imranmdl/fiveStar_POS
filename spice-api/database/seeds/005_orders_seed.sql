-- ============================================================================
--  Seed 005 - Checkout, payment and order settings
--
--  No sample orders. Orders are created through the API so that every one of
--  them passes the same validation, OTP and payment path as a real customer's;
--  a hand-inserted order would bypass the rules this phase exists to enforce.
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'order',   'order_number_prefix',        'SDF',   'string',  'Prefix for customer-facing order numbers', 1),
    (UUID(), 'order',   'invoice_number_prefix',      'INV',   'string',  'Prefix for GST invoice numbers', 0),
    (UUID(), 'order',   'order_otp_required',         '1',     'bool',    'BR-003: OTP verification before an order is confirmed', 0),
    (UUID(), 'order',   'order_payment_window_minutes','30',   'int',     'Unpaid orders expire after this and release coupon and wallet credit', 1),
    (UUID(), 'order',   'order_cancellable_until',    'packed','string',  'Last status at which a customer may still cancel', 1),
    (UUID(), 'order',   'seller_state',               'Karnataka','string','Seller state; decides CGST+SGST versus IGST', 0),
    (UUID(), 'order',   'seller_gstin',               '',      'string',  'Seller GSTIN, printed on every invoice', 0),
    (UUID(), 'order',   'seller_legal_name',          'Spice & Dry Fruits', 'string', 'Legal entity name on the invoice', 0),
    (UUID(), 'payment', 'payment_gateway',            'sandbox','string', 'Active gateway: razorpay or sandbox', 0),
    (UUID(), 'payment', 'payment_currency',           'INR',   'string',  'Only INR is supported for UPI', 1),
    (UUID(), 'payment', 'payment_methods',            'upi',   'string',  'BR-004: prepaid UPI only', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;

-- Numbering counters for the current Indian financial year (April to March).
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('order:', fy.`year`), 'order', 'SDF', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT UUID(), CONCAT('invoice:', fy.`year`), 'invoice', 'INV', fy.`year`, 0
FROM (
    SELECT CASE WHEN MONTH(NOW()) >= 4
                THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
           END AS `year`
) fy
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;
