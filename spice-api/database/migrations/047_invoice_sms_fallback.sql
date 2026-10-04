-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 047 - Invoice Tracking: SMS fallback for WhatsApp sends
--
--  wa.me is a click-to-chat deep link, not the WhatsApp Business API — this
--  project has no such API credential (see InvoiceService's doc comment).
--  That means there is no way for our backend to know in advance whether a
--  given mobile number has a WhatsApp account; only WhatsApp itself can tell
--  the admin that, inside its own app, after the link opens. For a brand new
--  customer that is common enough that the brief now needs a real fallback
--  channel, not just a retried WhatsApp link.
--
--  This reuses the SMS gateway already wired for OTP delivery
--  (SmsGatewayInterface / SmsChannel) — no new gateway, no new credential.
--
--  Additive only:
--    * communication_log.channel gains 'sms' alongside the existing
--      'whatsapp' value. Every existing row keeps its value untouched.
--    * communication_log.status gains 'sent' (a real gateway accept), kept
--      distinct from 'opened' (a WhatsApp composer was opened, not a
--      delivery receipt) and 'failed' (already existed).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE `communication_log`
    MODIFY COLUMN `channel` ENUM('whatsapp', 'sms') NOT NULL DEFAULT 'whatsapp',
    MODIFY COLUMN `status` ENUM('opened', 'sent', 'failed') NOT NULL DEFAULT 'opened';

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('047_invoice_sms_fallback', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
