-- ============================================================================
--  Seed 008 - Notification templates, settings and scheduled tasks
--
--  SMS bodies are deliberately short: Indian gateways bill per 160-character
--  segment, and a template that runs to 170 characters doubles the cost of
--  every message the business ever sends.
--
--  provider_template_id is blank here. Indian operators require every template
--  to be registered on the DLT platform before it can be delivered; unregistered
--  content is dropped silently. Fill these in from the operator portal before
--  go-live or nothing will arrive.
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'notifications', 'notifications_enabled',      '1',     'bool',   'Master switch for outbound messaging', 0),
    (UUID(), 'notifications', 'promotional_quiet_start',    '21:00', 'string', 'TRAI: no promotional messages after this time', 0),
    (UUID(), 'notifications', 'promotional_quiet_end',      '09:00', 'string', 'TRAI: no promotional messages before this time', 0),
    (UUID(), 'notifications', 'notification_batch_size',    '50',    'int',    'Messages dispatched per worker pass', 0),
    (UUID(), 'notifications', 'notification_retry_minutes', '15',    'int',    'Delay before retrying a failed send', 0),
    (UUID(), 'notifications', 'abandoned_cart_hours',       '6',     'int',    'Idle hours before a cart is treated as abandoned', 0),
    (UUID(), 'notifications', 'support_phone',              '',      'string', 'Shown in message footers', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

INSERT INTO `notification_templates`
    (`uuid`, `code`, `channel`, `name`, `category`, `subject`, `body`, `required_variables`)
VALUES
    (UUID(), 'order.placed', 'sms', 'Order placed', 'transactional', NULL,
     'Order {{order_number}} received. Pay {{amount}} to confirm. We will notify you once payment is received.',
     '["order_number","amount"]'),

    (UUID(), 'order.confirmed', 'sms', 'Order confirmed', 'transactional', NULL,
     'Payment received for order {{order_number}}. We are preparing your order and will notify you when it ships.',
     '["order_number"]'),

    (UUID(), 'order.confirmed', 'email', 'Order confirmed', 'transactional',
     'Your order {{order_number}} is confirmed',
     'Hello {{customer_name}},

Thank you for your order. We have received your payment of {{amount}} and your order {{order_number}} is now confirmed.

Invoice number: {{invoice_number}}
Delivery to: {{delivery_address}}

We will send you tracking details as soon as your parcel is dispatched.',
     '["customer_name","order_number","amount"]'),

    (UUID(), 'order.shipped', 'sms', 'Order shipped', 'transactional', NULL,
     'Order {{order_number}} shipped via {{courier_name}}. Track: {{tracking_number}}. Expected by {{expected_date}}.',
     '["order_number","courier_name","tracking_number"]'),

    (UUID(), 'order.delivered', 'sms', 'Order delivered', 'transactional', NULL,
     'Order {{order_number}} delivered. Thank you for shopping with us.',
     '["order_number"]'),

    (UUID(), 'order.cancelled', 'sms', 'Order cancelled', 'transactional', NULL,
     'Order {{order_number}} has been cancelled. Any amount paid will be refunded within 5-7 working days.',
     '["order_number"]'),

    (UUID(), 'payment.failed', 'sms', 'Payment failed', 'transactional', NULL,
     'Payment for order {{order_number}} did not complete. You can retry until {{expires_at}}.',
     '["order_number"]'),

    (UUID(), 'wallet.credited', 'sms', 'Wallet credited', 'transactional', NULL,
     '{{amount}} added to your wallet. Balance: {{balance}}.',
     '["amount","balance"]'),

    (UUID(), 'referral.rewarded', 'sms', 'Referral reward', 'transactional', NULL,
     'Your friend completed their first order. {{amount}} has been added to your wallet.',
     '["amount"]'),

    (UUID(), 'cart.abandoned', 'sms', 'Abandoned cart reminder', 'promotional', NULL,
     'You left {{item_count}} item(s) in your cart. Complete your order before stock changes.',
     '["item_count"]'),

    (UUID(), 'offer.announcement', 'sms', 'Offer announcement', 'promotional', NULL,
     '{{offer_title}}. Use code {{offer_code}} before {{valid_until}}.',
     '["offer_title","offer_code"]'),

    (UUID(), 'wallet.expiring', 'sms', 'Wallet credit expiring', 'promotional', NULL,
     '{{amount}} of wallet credit expires on {{expiry_date}}. Use it on your next order.',
     '["amount","expiry_date"]')
ON DUPLICATE KEY UPDATE
    `body`    = VALUES(`body`),
    `version` = `notification_templates`.`version` + 1;

-- ---------------------------------------------------------------------------
-- Scheduled tasks
--
-- Every one of these already exists as a service method that was previously
-- callable only by hand. Nothing new is being invented here; the work is being
-- put on a clock.
-- ---------------------------------------------------------------------------
INSERT INTO `scheduled_tasks` (`uuid`, `code`, `name`, `description`, `interval_minutes`, `is_enabled`, `next_run_date`)
VALUES
    (UUID(), 'notifications.dispatch', 'Dispatch queued notifications',
     'Sends pending messages. The most frequent task, because a delivery notice an hour late is nearly worthless.',
     1, 1, NOW()),

    (UUID(), 'orders.expire_unpaid', 'Release unpaid orders',
     'Cancels orders whose payment window closed, returning wallet credit and releasing the coupon they were holding.',
     10, 1, NOW()),

    (UUID(), 'shipments.refresh_tracking', 'Refresh courier tracking',
     'Polls couriers for parcels with no recent scan, covering webhooks that never arrived.',
     60, 1, NOW()),

    (UUID(), 'carts.abandoned', 'Abandoned cart reminders',
     'One promotional reminder per abandoned cart. Deliberately not repeated.',
     120, 1, NOW()),

    (UUID(), 'wallet.expire_credits', 'Expire wallet credits',
     'Expires credits past their date and warns customers a week before.',
     720, 1, NOW()),

    (UUID(), 'promotions.expire', 'Expire coupons and offers',
     'Moves coupons and offers past their end date out of active status.',
     720, 1, NOW()),

    (UUID(), 'couriers.rescore', 'Recalculate courier reliability',
     'Feeds real delivery outcomes back into the BR-007 selection score.',
     1440, 1, NOW())
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `scheduled_tasks`.`version` + 1;
