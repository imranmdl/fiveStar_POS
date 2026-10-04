-- ============================================================================
--  Rollback for migration 012 - Manual payment & delivery mode
--
--  Only removes the rows this migration seeded. If an administrator has since
--  changed payment_driver/delivery_driver away from the seeded default, this
--  still removes the row — rolling back the migration means the settings UI
--  and bootstrap/container.php fall back to the .env default again, which is
--  the correct rollback behaviour, not "restore whatever value existed".
-- ============================================================================

DELETE FROM `settings`
 WHERE `group_code` = 'commerce'
   AND `setting_key` IN (
       'payment_driver',
       'delivery_driver',
       'manual_payment_vpa',
       'manual_payment_payee_name',
       'manual_payment_qr_path'
   );
