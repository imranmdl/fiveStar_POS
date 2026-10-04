-- ============================================================================
--  Seed 001 - Roles, permission matrix and default settings
--  Idempotent: safe to re-run after adding new permissions.
--  No user accounts are created here. Use `php bin/seed_admin.php` so the
--  administrator password is hashed at runtime and never committed to source.
-- ============================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- Roles (SRS section 4)
-- --------------------------------------------------------------------------
INSERT INTO `roles` (`uuid`, `code`, `name`, `description`, `is_system`, `hierarchy`)
VALUES
    (UUID(), 'administrator', 'Administrator', 'Full system administration', 1, 10),
    (UUID(), 'supervisor',    'Supervisor',    'Manages executives, assigns orders, approves bulk orders', 1, 20),
    (UUID(), 'executive',     'Executive',     'Verifies orders, packing, labels, customer calls', 1, 30),
    (UUID(), 'customer',      'Customer',      'Retail and wholesale buyer', 1, 100)
ON DUPLICATE KEY UPDATE
    `name`        = VALUES(`name`),
    `description` = VALUES(`description`),
    `hierarchy`   = VALUES(`hierarchy`),
    `version`     = `version` + 1;

-- --------------------------------------------------------------------------
-- Permissions. Codes follow module.action and are referenced by name in code,
-- never by id.
-- --------------------------------------------------------------------------
INSERT INTO `permissions` (`uuid`, `code`, `module`, `action`, `name`)
VALUES
    (UUID(), 'users.view',        'users',    'view',    'View users'),
    (UUID(), 'users.create',      'users',    'create',  'Create users'),
    (UUID(), 'users.update',      'users',    'update',  'Update users'),
    (UUID(), 'users.delete',      'users',    'delete',  'Deactivate users'),
    (UUID(), 'users.impersonate', 'users',    'impersonate', 'Impersonate a user for support'),
    (UUID(), 'roles.manage',      'roles',    'manage',  'Manage roles and permissions'),
    (UUID(), 'settings.view',     'settings', 'view',    'View system settings'),
    (UUID(), 'settings.update',   'settings', 'update',  'Update system settings'),
    (UUID(), 'audit.view',        'audit',    'view',    'View audit and activity logs'),
    (UUID(), 'reports.view',      'reports',  'view',    'View reports'),
    (UUID(), 'dashboard.admin',   'dashboard','admin',   'Access administrator dashboard'),
    (UUID(), 'dashboard.supervisor','dashboard','supervisor','Access supervisor dashboard'),
    (UUID(), 'dashboard.executive','dashboard','executive','Access executive dashboard')
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `version` = `version` + 1;

-- --------------------------------------------------------------------------
-- Matrix: administrator gets everything.
-- --------------------------------------------------------------------------
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'administrator'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

-- Supervisor
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'users.view', 'users.create', 'users.update',
    'reports.view', 'dashboard.supervisor', 'audit.view'
)
WHERE r.`code` = 'supervisor'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

-- Executive
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN ('users.view', 'dashboard.executive')
WHERE r.`code` = 'executive'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

-- --------------------------------------------------------------------------
-- Default settings
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'general', 'store_name',              'Spice & Dry Fruits', 'string', 'Public store name', 1),
    (UUID(), 'general', 'support_mobile',          '',                   'string', 'Customer care number', 1),
    (UUID(), 'general', 'support_email',           '',                   'string', 'Customer care email', 1),
    (UUID(), 'general', 'currency_code',           'INR',                'string', 'ISO currency code', 1),
    (UUID(), 'order',   'min_order_value',         '199',                'decimal','Minimum order value in INR', 1),
    (UUID(), 'order',   'free_delivery_threshold', '999',                'decimal','Order value above which delivery is free', 1),
    (UUID(), 'order',   'require_otp_on_order',    '1',                  'bool',   'BR-003: OTP verification before order confirmation', 0),
    (UUID(), 'order',   'prepaid_only',            '1',                  'bool',   'BR-004: only prepaid UPI orders accepted', 1),
    (UUID(), 'auth',    'otp_ttl_seconds',         '300',                'int',    'OTP validity window', 0),
    (UUID(), 'auth',    'login_max_attempts',      '5',                  'int',    'Failed logins before lockout', 0),
    (UUID(), 'referral','referral_reward_amount',  '50',                 'decimal','Wallet credit per successful referral', 1),
    (UUID(), 'referral','referral_enabled',        '1',                  'bool',   'Referral programme master switch', 1)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`),
    `version`     = `settings`.`version` + 1;
