-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 042 - Admin Privilege Management
--
--  Additive only. Nothing here alters an existing column, drops anything, or
--  changes what an existing role/permission already grants.
--
--  Adds:
--    * New roles used only by the new Admin Privilege panel and its
--      permission matrix (existing roles/users/sessions are untouched).
--    * approval_requests        - the sensitive-action approval queue.
--    * admin_privilege_activity - one row per user, used only to enforce the
--      Admin Privilege panel's own inactivity timeout. It never touches the
--      customer/staff session model in refresh_tokens.
--
--  MySQL 8.0+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- approval_requests (Admin Privilege Management: sensitive-action approval
-- queue). Any module can raise a request here; nothing about an existing
-- write endpoint is changed by this table's existence.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_requests` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL,
    `module`                VARCHAR(50)     NOT NULL,
    `action_type`           VARCHAR(60)     NOT NULL
        COMMENT 'large_discount | refund | wallet_adjustment | stock_adjustment | price_change | customer_credit | payment_adjustment | other',
    `entity_name`           VARCHAR(100)    NULL,
    `entity_id`             BIGINT UNSIGNED NULL,
    `entity_uuid`           CHAR(36)        NULL,
    `title`                 VARCHAR(200)    NOT NULL,
    `reason`                VARCHAR(500)    NOT NULL,
    `old_values`            JSON            NULL,
    `new_values`            JSON            NULL,
    `amount`                DECIMAL(12, 2)  NULL,
    `status`                ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    `requested_by_user_id`  BIGINT UNSIGNED NOT NULL,
    `decided_by_user_id`    BIGINT UNSIGNED NULL,
    `decided_date`          DATETIME        NULL,
    `decision_note`         VARCHAR(500)    NULL,
    `created_by`            BIGINT UNSIGNED NULL,
    `created_date`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`            BIGINT UNSIGNED NULL,
    `updated_date`          DATETIME        NULL,
    `deleted_by`            BIGINT UNSIGNED NULL,
    `deleted_date`          DATETIME        NULL,
    `is_active`             TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`            TINYINT(1)      NOT NULL DEFAULT 0,
    `version`               INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_approval_requests_uuid` (`uuid`),
    KEY `idx_approval_status` (`status`, `created_date`),
    KEY `idx_approval_module` (`module`, `action_type`),
    KEY `idx_approval_requested_by` (`requested_by_user_id`),
    KEY `idx_approval_decided_by` (`decided_by_user_id`),
    CONSTRAINT `fk_approval_requested_by`
        FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_approval_decided_by`
        FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- admin_privilege_activity - heartbeat table for the Admin Privilege panel's
-- own inactivity timeout. Deliberately separate from refresh_tokens: the
-- panel's idle policy must never affect the storefront/staff session model
-- that every other page in the system depends on.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_privilege_activity` (
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `last_activity_date`  DATETIME        NOT NULL,
    `ip_address`          VARCHAR(45)     NULL,
    `user_agent`          VARCHAR(255)    NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_admin_privilege_activity_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- New roles for the Admin Privilege permission matrix. Every existing role
-- (administrator, supervisor, executive, customer, manager, inventory_staff,
-- cashier) is untouched.
-- ---------------------------------------------------------------------------
INSERT INTO `roles` (`uuid`, `code`, `name`, `description`, `is_system`, `hierarchy`)
VALUES
    (UUID(), 'super_admin',          'Super Admin',           'Unrestricted access to every module and the Admin Privilege panel itself', 1, 5),
    (UUID(), 'inventory_executive',  'Inventory Executive',   'Day-to-day stock entry and lookup', 0, 45),
    (UUID(), 'purchase_executive',   'Purchase Executive',    'Vendor and purchase-order entry', 0, 45),
    (UUID(), 'sales_executive',      'Sales Executive',       'POS sales and customer credit entry', 0, 45),
    (UUID(), 'accountant',           'Accountant',            'Payments, wallet and customer-dues oversight', 0, 35)
ON DUPLICATE KEY UPDATE
    `name`        = VALUES(`name`),
    `description` = VALUES(`description`),
    `hierarchy`   = VALUES(`hierarchy`);

-- ---------------------------------------------------------------------------
-- Permissions for the Admin Privilege module/action matrix (view / add /
-- edit / delete / approve / export). Existing permission codes from
-- 001_roles_permissions_settings.sql (users.*, roles.manage, settings.*,
-- audit.view, reports.view, dashboard.*) are untouched and unrelated to this
-- module.* set, which is scoped to the new panel and its role editor.
-- ---------------------------------------------------------------------------
INSERT INTO `permissions` (`uuid`, `code`, `module`, `action`, `name`)
VALUES
    (UUID(), 'admin_privilege.access',      'admin_privilege',    'access',  'Sign in to the Admin Privilege panel'),

    (UUID(), 'user_management.view',        'user_management',    'view',    'View users'),
    (UUID(), 'user_management.add',         'user_management',    'add',     'Create users'),
    (UUID(), 'user_management.edit',        'user_management',    'edit',    'Edit users and change roles'),
    (UUID(), 'user_management.delete',      'user_management',    'delete',  'Deactivate users'),
    (UUID(), 'user_management.export',      'user_management',    'export',  'Export user lists'),

    (UUID(), 'role_management.view',        'role_management',    'view',    'View roles and permissions'),
    (UUID(), 'role_management.add',         'role_management',    'add',     'Create roles'),
    (UUID(), 'role_management.edit',        'role_management',    'edit',    'Edit roles and toggle permissions'),
    (UUID(), 'role_management.delete',      'role_management',    'delete',  'Delete custom roles'),

    (UUID(), 'product_management.view',     'product_management', 'view',    'View products'),
    (UUID(), 'product_management.add',      'product_management', 'add',     'Create products'),
    (UUID(), 'product_management.edit',     'product_management', 'edit',    'Edit products'),
    (UUID(), 'product_management.delete',   'product_management', 'delete',  'Delete/archive products'),
    (UUID(), 'product_management.export',   'product_management', 'export',  'Export product catalogue'),

    (UUID(), 'inventory.view',              'inventory',          'view',    'View stock and movements'),
    (UUID(), 'inventory.add',               'inventory',          'add',     'Record inward stock'),
    (UUID(), 'inventory.edit',              'inventory',          'edit',    'Edit stock records'),
    (UUID(), 'inventory.delete',            'inventory',          'delete',  'Remove stock records'),
    (UUID(), 'inventory.approve',           'inventory',          'approve', 'Approve stock adjustments'),
    (UUID(), 'inventory.export',            'inventory',          'export',  'Export inventory reports'),

    (UUID(), 'vendors_purchases.view',      'vendors_purchases',  'view',    'View vendors and purchase orders'),
    (UUID(), 'vendors_purchases.add',       'vendors_purchases',  'add',     'Create vendors and purchase orders'),
    (UUID(), 'vendors_purchases.edit',      'vendors_purchases',  'edit',    'Edit vendors and purchase orders'),
    (UUID(), 'vendors_purchases.delete',    'vendors_purchases',  'delete',  'Delete vendors and purchase orders'),
    (UUID(), 'vendors_purchases.approve',   'vendors_purchases',  'approve', 'Approve vendor payments and returns'),
    (UUID(), 'vendors_purchases.export',    'vendors_purchases',  'export',  'Export vendor/purchase data'),

    (UUID(), 'pos_sales.view',              'pos_sales',          'view',    'View POS sales'),
    (UUID(), 'pos_sales.add',               'pos_sales',          'add',     'Ring up POS sales'),
    (UUID(), 'pos_sales.edit',              'pos_sales',          'edit',    'Edit POS sales'),
    (UUID(), 'pos_sales.delete',            'pos_sales',          'delete',  'Void POS sales'),
    (UUID(), 'pos_sales.export',            'pos_sales',          'export',  'Export POS sales data'),

    (UUID(), 'discounts_offers.view',       'discounts_offers',   'view',    'View discounts, coupons and offers'),
    (UUID(), 'discounts_offers.add',        'discounts_offers',   'add',     'Create discounts and offers'),
    (UUID(), 'discounts_offers.edit',       'discounts_offers',   'edit',    'Edit discounts and offers'),
    (UUID(), 'discounts_offers.delete',     'discounts_offers',   'delete',  'Remove discounts and offers'),
    (UUID(), 'discounts_offers.approve',    'discounts_offers',   'approve', 'Approve large discounts'),

    (UUID(), 'refunds.view',                'refunds',            'view',    'View refunds'),
    (UUID(), 'refunds.add',                 'refunds',            'add',     'Request refunds'),
    (UUID(), 'refunds.approve',             'refunds',            'approve', 'Approve refunds'),

    (UUID(), 'customer_credit.view',        'customer_credit',    'view',    'View customer dues/credit'),
    (UUID(), 'customer_credit.add',         'customer_credit',    'add',     'Record customer dues/credit'),
    (UUID(), 'customer_credit.edit',        'customer_credit',    'edit',    'Edit customer dues/credit'),
    (UUID(), 'customer_credit.approve',     'customer_credit',    'approve', 'Approve customer credit changes'),

    (UUID(), 'wallet.view',                 'wallet',             'view',    'View wallet balances'),
    (UUID(), 'wallet.add',                  'wallet',             'add',     'Request wallet adjustments'),
    (UUID(), 'wallet.approve',              'wallet',             'approve', 'Approve wallet adjustments'),

    (UUID(), 'payments.view',               'payments',           'view',    'View payments'),
    (UUID(), 'payments.add',                'payments',           'add',     'Record payments'),
    (UUID(), 'payments.edit',               'payments',           'edit',    'Edit payment records'),
    (UUID(), 'payments.approve',            'payments',           'approve', 'Approve payment adjustments'),

    (UUID(), 'reports.export',              'reports',            'export',  'Export reports'),

    (UUID(), 'import_export.view',          'import_export',      'view',    'View import/export history'),
    (UUID(), 'import_export.add',           'import_export',      'add',     'Run imports'),
    (UUID(), 'import_export.export',        'import_export',      'export',  'Run exports'),

    (UUID(), 'system_settings.view',        'system_settings',    'view',    'View system settings'),
    (UUID(), 'system_settings.edit',        'system_settings',    'edit',    'Edit system settings'),

    (UUID(), 'audit_logs.view',             'audit_logs',         'view',    'View audit and activity logs'),
    (UUID(), 'audit_logs.export',           'audit_logs',         'export',  'Export audit and activity logs')
ON DUPLICATE KEY UPDATE
    `name`    = VALUES(`name`),
    `module`  = VALUES(`module`),
    `action`  = VALUES(`action`);

-- ---------------------------------------------------------------------------
-- Role -> permission matrix for the new module.* permissions.
-- super_admin and administrator get everything, including admin_privilege.
-- access, so the account bin/seed_admin.php already created can sign in to
-- the new panel with no extra setup.
-- ---------------------------------------------------------------------------
INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` IN ('super_admin', 'administrator')
  AND p.`module` IN (
    'admin_privilege', 'user_management', 'role_management', 'product_management',
    'inventory', 'vendors_purchases', 'pos_sales', 'discounts_offers', 'refunds',
    'customer_credit', 'wallet', 'payments', 'reports', 'import_export',
    'system_settings', 'audit_logs'
  )
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'admin_privilege.access',
    'user_management.view',
    'inventory.view', 'inventory.add', 'inventory.edit', 'inventory.approve', 'inventory.export',
    'vendors_purchases.view', 'vendors_purchases.add', 'vendors_purchases.edit', 'vendors_purchases.approve', 'vendors_purchases.export',
    'pos_sales.view', 'pos_sales.add', 'pos_sales.edit', 'pos_sales.export',
    'discounts_offers.view', 'discounts_offers.approve',
    'refunds.view', 'refunds.approve',
    'customer_credit.view', 'customer_credit.edit', 'customer_credit.approve',
    'wallet.view', 'wallet.approve',
    'payments.view', 'payments.edit', 'payments.approve',
    'reports.export',
    'audit_logs.view'
)
WHERE r.`code` = 'manager'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'admin_privilege.access',
    'user_management.view',
    'pos_sales.view',
    'discounts_offers.view',
    'refunds.view',
    'reports.export',
    'audit_logs.view'
)
WHERE r.`code` = 'supervisor'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'admin_privilege.access',
    'payments.view', 'payments.add', 'payments.edit', 'payments.approve',
    'wallet.view', 'wallet.approve',
    'customer_credit.view', 'customer_credit.edit', 'customer_credit.approve',
    'reports.export',
    'vendors_purchases.view',
    'audit_logs.view'
)
WHERE r.`code` = 'accountant'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'admin_privilege.access',
    'inventory.view', 'inventory.add', 'inventory.edit', 'inventory.export',
    'vendors_purchases.view', 'vendors_purchases.add',
    'import_export.view', 'import_export.add', 'import_export.export'
)
WHERE r.`code` = 'inventory_executive'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'admin_privilege.access',
    'vendors_purchases.view', 'vendors_purchases.add', 'vendors_purchases.edit', 'vendors_purchases.export',
    'inventory.view',
    'import_export.view', 'import_export.add', 'import_export.export'
)
WHERE r.`code` = 'purchase_executive'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN (
    'admin_privilege.access',
    'pos_sales.view', 'pos_sales.add',
    'discounts_offers.view',
    'product_management.view',
    'customer_credit.view', 'customer_credit.add'
)
WHERE r.`code` = 'sales_executive'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN ('pos_sales.view', 'pos_sales.add', 'customer_credit.view', 'customer_credit.add')
WHERE r.`code` = 'cashier'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `role_permissions` (`uuid`, `role_id`, `permission_id`)
SELECT UUID(), r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p ON p.`code` IN ('pos_sales.view', 'product_management.view')
WHERE r.`code` = 'executive'
ON DUPLICATE KEY UPDATE `version` = `role_permissions`.`version` + 1;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('042_admin_privilege', 1, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
