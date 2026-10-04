-- ============================================================================
--  Rollback for migration 013 - Cash on Delivery (COD)
--
--  Refuses to roll back while a COD order still exists that hasn't reached a
--  terminal status — dropping payment_method out from under an order mid-
--  fulfilment would silently turn it into a UPI order the state machine
--  believes is unpaid, blocking every further transition. Cancel, decline or
--  deliver those orders first.
-- ============================================================================

SELECT COUNT(*) INTO @cod_in_flight
  FROM `orders`
 WHERE `payment_method` = 'cod'
   AND `status` NOT IN ('cancelled', 'delivered', 'returned', 'refunded');

-- MySQL/MariaDB have no native "abort if" statement outside stored procedures,
-- so this forces a divide-by-zero error (bounces the whole script) when the
-- guard condition is true, and is a no-op otherwise.
SET @guard = IF(@cod_in_flight > 0, (SELECT 1/0), 1);

ALTER TABLE `orders`
    DROP KEY `idx_orders_cod_pending`,
    DROP COLUMN `cod_declined_reason`,
    DROP COLUMN `cod_approved_date`,
    DROP COLUMN `cod_approved_by`,
    DROP COLUMN `payment_method`;

DELETE FROM `settings` WHERE `group_code` = 'commerce' AND `setting_key` = 'cod_enabled';
