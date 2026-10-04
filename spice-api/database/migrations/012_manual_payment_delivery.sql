-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 012 - Manual payment & delivery mode
--
--  WHY THIS EXISTS.
--
--  Razorpay and Shiprocket both need live credentials before they can be
--  switched on. A shop going live before those credentials exist still needs
--  to take payment and ship parcels — this seeds the settings rows that let
--  the store run on a staff-verified UPI QR code and a manual dispatch
--  process instead, toggled from /admin/settings without a redeploy. See
--  ManualGateway, ManualCourierAdapter, SettingsService and
--  bootstrap/container.php, which reads `payment_driver` / `delivery_driver`
--  from this table ahead of the .env default on every request.
--
--  Note: the customer-facing "delivers in N-M days" estimate is NOT part of
--  this migration. It already comes from `delivery_zones`
--  (see 003_cart_wishlist_delivery.sql), which is courier-independent and
--  stays correct whether delivery_driver is shiprocket or manual.
--
--  No new tables. `settings` (migration 001) is already a generic key/value
--  store designed for exactly this; this migration only seeds rows into it
--  and is safe to re-run (INSERT ... ON DUPLICATE KEY DO NOTHING keeps a
--  value an admin has already changed from being clobbered back to default).
--
--  MySQL 8.0.16+ / MariaDB 10.11+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

INSERT INTO `settings`
    (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`, `created_date`)
VALUES
    (UUID(), 'commerce', 'payment_driver', 'manual', 'string',
     'Active payment gateway: manual, sandbox, or razorpay. Editable from /admin/settings.',
     0, NOW()),
    (UUID(), 'commerce', 'delivery_driver', 'manual', 'string',
     'Active courier adapter: manual, sandbox, or shiprocket. Editable from /admin/settings.',
     0, NOW()),
    (UUID(), 'commerce', 'manual_payment_vpa', '', 'string',
     'UPI VPA shown under the manual payment QR code (optional; the QR image itself is what customers scan).',
     0, NOW()),
    (UUID(), 'commerce', 'manual_payment_payee_name', 'Anjeera Dry Fruits', 'string',
     'Payee name shown alongside the manual payment QR code.',
     0, NOW()),
    (UUID(), 'commerce', 'manual_payment_qr_path', NULL, 'string',
     'Storage path of the admin-uploaded manual payment QR image. Set via POST /admin/settings/manual/qr-image.',
     0, NOW())
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;
