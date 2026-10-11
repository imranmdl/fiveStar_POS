-- Rollback 061 — data-only change. Which categories were hidden before is not
-- recorded, so visibility is left as it is; hide a category from the admin
-- Categories page if needed. Only the migration record is removed.
DELETE FROM `schema_migrations` WHERE `migration` = '061_show_categories_with_products';
