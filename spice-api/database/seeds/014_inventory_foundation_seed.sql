-- ============================================================================
--  Seed 014 - Inventory roles, default warehouse, base variant dimensions
--  Idempotent: safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- Roles. Sits alongside the four seeded in 001_roles_permissions_settings:
-- administrator(10), supervisor(20), executive(30), customer(100).
-- --------------------------------------------------------------------------
INSERT INTO `roles` (`uuid`, `code`, `name`, `description`, `is_system`, `hierarchy`)
VALUES
    (UUID(), 'manager',         'Manager',         'Approves adjustments, imports and returns', 1, 25),
    (UUID(), 'inventory_staff', 'Inventory Staff',  'Records inward stock and stock movements', 1, 40),
    (UUID(), 'cashier',         'Cashier',          'Point-of-sale operator', 1, 50)
ON DUPLICATE KEY UPDATE
    `name`        = VALUES(`name`),
    `description` = VALUES(`description`),
    `hierarchy`   = VALUES(`hierarchy`),
    `version`     = `version` + 1;

-- --------------------------------------------------------------------------
-- One default warehouse. `is_default` is where automatic sale deduction and
-- COD/CSV/mobile inward land unless a caller names a different warehouse.
-- --------------------------------------------------------------------------
INSERT INTO `warehouses` (`uuid`, `code`, `name`, `country`, `is_default`)
VALUES (UUID(), 'WH-MAIN', 'Main Warehouse', 'India', 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- --------------------------------------------------------------------------
-- Base variant dimensions. `category_option_types` (not seeded here) is how
-- an administrator later opts a specific category into one or more of these
-- without any code change.
-- --------------------------------------------------------------------------
INSERT INTO `variant_option_types` (`uuid`, `code`, `name`, `display_order`)
VALUES
    (UUID(), 'pack_size', 'Pack size', 10),
    (UUID(), 'size',      'Size',      20),
    (UUID(), 'color',     'Colour',    30)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
