-- ===========================================================================
-- 061 — Show categories that hold published products
--
-- Categories made with the quick "+ New" buttons (Purchase Inward, product
-- screens) were created with show_in_menu = 0 for a top-level category,
-- meant as "hidden until published" — but nothing ever un-hid them, so the
-- shop menu and the home page's category strip came back empty even with
-- published products inside. They are now created visible, and the menu
-- skips categories with nothing published, so empty ones still stay out of
-- sight.
--
-- This turns on every category that holds a published product, and every
-- parent above one. Categories with nothing published are left as they are.
-- Run more than once safely.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

-- Categories with a published product directly in them.
UPDATE `categories` c
  JOIN (SELECT DISTINCT p.`category_id` AS `id`
          FROM `products` p
         WHERE p.`status` = 'published' AND p.`is_deleted` = 0 AND p.`is_active` = 1) x
    ON x.`id` = c.`id`
   SET c.`show_in_menu` = 1
 WHERE c.`is_deleted` = 0 AND c.`show_in_menu` = 0;

-- Their parents (two levels, the depth the store uses).
UPDATE `categories` parent
  JOIN `categories` child ON child.`parent_id` = parent.`id`
   SET parent.`show_in_menu` = 1
 WHERE parent.`is_deleted` = 0 AND parent.`show_in_menu` = 0
   AND child.`is_deleted` = 0 AND child.`show_in_menu` = 1
   AND EXISTS (SELECT 1 FROM `products` p
                WHERE p.`category_id` = child.`id` AND p.`status` = 'published'
                  AND p.`is_deleted` = 0 AND p.`is_active` = 1);

UPDATE `categories` grand
  JOIN `categories` parent ON parent.`parent_id` = grand.`id`
  JOIN `categories` child  ON child.`parent_id` = parent.`id`
   SET grand.`show_in_menu` = 1
 WHERE grand.`is_deleted` = 0 AND grand.`show_in_menu` = 0
   AND parent.`is_deleted` = 0 AND child.`is_deleted` = 0
   AND EXISTS (SELECT 1 FROM `products` p
                WHERE p.`category_id` = child.`id` AND p.`status` = 'published'
                  AND p.`is_deleted` = 0 AND p.`is_active` = 1);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('061_show_categories_with_products', 61, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
