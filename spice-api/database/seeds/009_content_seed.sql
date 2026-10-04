-- ============================================================================
--  Seed 009 - Engagement settings, legal pages and starter FAQ
--
--  The policy pages are PLACEHOLDERS with real structure. They must be reviewed
--  by someone qualified before go-live: a returns policy is a contract with the
--  customer, and Indian consumer law has specific requirements about what a
--  seller must disclose.
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'reviews', 'reviews_require_purchase',    '1',  'bool', 'Only customers with a delivered order may review that product', 0),
    (UUID(), 'reviews', 'reviews_auto_approve',        '0',  'bool', 'Publish reviews without moderation', 0),
    (UUID(), 'reviews', 'reviews_auto_hide_reports',   '3',  'int',  'Reports before a review is hidden pending moderation', 0),
    (UUID(), 'reviews', 'reviews_edit_window_days',    '30', 'int',  'How long a customer may edit their own review', 1),
    (UUID(), 'support', 'support_first_response_hours','4',  'int',  'First response SLA', 1),
    (UUID(), 'support', 'support_resolution_hours',    '48', 'int',  'Resolution SLA', 1),
    (UUID(), 'support', 'support_reopen_days',         '7',  'int',  'How long a resolved ticket may be reopened', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `version`     = `settings`.`version` + 1;

INSERT INTO `cms_pages` (`uuid`, `slug`, `title`, `body`, `excerpt`, `status`, `published_date`, `is_system_page`, `display_order`)
VALUES
    (UUID(), 'shipping-policy', 'Shipping Policy',
     'PLACEHOLDER - review before go-live.

We dispatch orders within 1-2 working days of payment confirmation. Delivery timelines depend on your pincode and are shown at checkout before you pay.

Delivery charges are calculated by weight and destination and are displayed before payment. Orders above the free-delivery threshold for your zone ship at no charge.

We do not accept cash on delivery. All orders are prepaid by UPI.',
     'Dispatch timelines, delivery charges and serviceable areas.',
     'published', NOW(), 1, 10),

    (UUID(), 'returns-and-refunds', 'Returns and Refunds',
     'PLACEHOLDER - must be reviewed by someone qualified before go-live. Indian consumer law has specific disclosure requirements for food sellers.

Food products are perishable, so we accept returns only where an item arrives damaged, is past its shelf life on arrival, or is not what was ordered.

Report an issue within 48 hours of delivery through your order page or by raising a support ticket. Photographs help us resolve it faster.

Approved refunds are returned to the original payment method within 5-7 working days. Wallet credit used on the order is returned as wallet credit.',
     'When we accept returns and how refunds are processed.',
     'published', NOW(), 1, 20),

    (UUID(), 'privacy-policy', 'Privacy Policy',
     'PLACEHOLDER - review before go-live.

We collect the information needed to fulfil your order: your name, contact details and delivery address. Payment details are handled by our payment provider and are never stored on our systems.

We use your mobile number to send order updates. Promotional messages are sent only with your consent and can be switched off at any time in your notification settings.

We do not sell your personal information.',
     'What we collect, why, and how to control it.',
     'published', NOW(), 1, 30),

    (UUID(), 'terms-of-service', 'Terms of Service',
     'PLACEHOLDER - must be reviewed by a lawyer before go-live.

By placing an order you agree to these terms. Prices include GST. An order is confirmed only once payment is received and verified.

We may cancel an order and refund it in full where a product is unavailable or a pricing error has occurred.',
     'The terms on which we sell.',
     'published', NOW(), 1, 40)
ON DUPLICATE KEY UPDATE
    `title`   = VALUES(`title`),
    `version` = `cms_pages`.`version` + 1;

INSERT INTO `faq_entries` (`uuid`, `group_code`, `question`, `answer`, `display_order`, `status`)
VALUES
    (UUID(), 'orders', 'How do I pay for my order?',
     'All orders are prepaid by UPI. You can pay using any UPI app - Google Pay, PhonePe, Paytm, BHIM or your bank app. We do not offer cash on delivery.', 10, 'published'),

    (UUID(), 'orders', 'Why do I need to enter an OTP when ordering?',
     'The OTP confirms that the mobile number on the order is genuinely yours. It protects you against someone placing an order in your name and makes sure delivery updates reach you.', 20, 'published'),

    (UUID(), 'orders', 'Can I cancel my order?',
     'You can cancel from your order page any time before it is handed to the courier. After that, please raise a support ticket and we will help where we can.', 30, 'published'),

    (UUID(), 'delivery', 'How long will my order take?',
     'The estimate for your pincode is shown at checkout before you pay. Most metro deliveries take 1-3 working days; remote areas can take up to a week.', 10, 'published'),

    (UUID(), 'delivery', 'How is the delivery charge calculated?',
     'By the weight of your parcel and where it is going. The exact charge is shown before you pay, and orders above the free-delivery threshold for your area ship free.', 20, 'published'),

    (UUID(), 'products', 'How should I store spices and dry fruits?',
     'Keep them in airtight containers away from direct sunlight and moisture. Whole spices keep their aroma far longer than ground ones. Dry fruits are best refrigerated in a humid climate.', 10, 'published'),

    (UUID(), 'products', 'What is the shelf life?',
     'Shelf life is listed on each product page and printed on the pack. Ground spices are best used within six months of opening; whole spices keep for a year or more.', 20, 'published'),

    (UUID(), 'account', 'How does the referral programme work?',
     'Share your referral code with a friend. Once they complete their first paid order, a reward is credited to your wallet. Wallet credit can be used against any future order.', 10, 'published'),

    (UUID(), 'account', 'How do I stop promotional messages?',
     'Turn them off in your notification settings. Order confirmations, payment receipts, dispatch notices and OTPs will still be sent, since those are needed to complete your order.', 20, 'published')
ON DUPLICATE KEY UPDATE
    `answer`  = VALUES(`answer`),
    `version` = `faq_entries`.`version` + 1;

-- Numbering counter for support tickets.
INSERT INTO `numbering_sequences` (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`)
SELECT * FROM (
    SELECT UUID() AS `uuid`,
           CONCAT('ticket:', fy.`year`) AS `sequence_key`,
           'order' AS `purpose`,
           'TKT' AS `prefix`,
           fy.`year` AS `financial_year`,
           0 AS `last_number`
    FROM (
        SELECT CASE WHEN MONTH(NOW()) >= 4
                    THEN CONCAT(YEAR(NOW()), '-', LPAD(MOD(YEAR(NOW()) + 1, 100), 2, '0'))
                    ELSE CONCAT(YEAR(NOW()) - 1, '-', LPAD(MOD(YEAR(NOW()), 100), 2, '0'))
               END AS `year`
    ) fy
) src
ON DUPLICATE KEY UPDATE `version` = `numbering_sequences`.`version` + 1;

-- Notification templates for engagement events.
INSERT INTO `notification_templates`
    (`uuid`, `code`, `channel`, `name`, `category`, `subject`, `body`, `required_variables`)
VALUES
    (UUID(), 'ticket.created', 'sms', 'Support ticket raised', 'transactional', NULL,
     'Support ticket {{ticket_number}} raised. We will respond within {{sla_hours}} hours.',
     '["ticket_number","sla_hours"]'),

    (UUID(), 'ticket.replied', 'sms', 'Support reply', 'transactional', NULL,
     'We have replied to your ticket {{ticket_number}}. View it in your account.',
     '["ticket_number"]'),

    (UUID(), 'ticket.resolved', 'sms', 'Support ticket resolved', 'transactional', NULL,
     'Ticket {{ticket_number}} has been resolved. Reply within {{reopen_days}} days if you need more help.',
     '["ticket_number","reopen_days"]'),

    (UUID(), 'review.approved', 'sms', 'Review published', 'transactional', NULL,
     'Thank you - your review of {{product_name}} is now live.',
     '["product_name"]')
ON DUPLICATE KEY UPDATE
    `body`    = VALUES(`body`),
    `version` = `notification_templates`.`version` + 1;
