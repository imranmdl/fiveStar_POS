-- ===========================================================================
-- 050 — Storefront v2: text banners and admin-editable storefront theme
--
-- 1. Banners become usable without artwork. The v2 storefront's rotating
--    home banner is a coloured panel with an eyebrow, headline, body, call to
--    action and an optional promo code; a photo is optional. image_path is
--    therefore nullable, and the panel's colours/eyebrow/code are stored.
-- 2. Storefront theme settings (group `storefront`, public) that the admin
--    console edits: header colours, accent, page background, announcement
--    bar text and colours, banner rotation speed and the Deals of the Day
--    switch. Read by GET /api/v1/storefront/theme.
--
-- Portable between MySQL 8 and MariaDB 10.11.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE `banners`
    MODIFY COLUMN `image_path` VARCHAR(255) NULL COMMENT 'Desktop / wide artwork (optional for text banners)',
    ADD COLUMN `eyebrow`    VARCHAR(60) NULL COMMENT 'Small label above the headline, e.g. FESTIVE SALE' AFTER `subtitle`,
    ADD COLUMN `promo_code` VARCHAR(40) NULL COMMENT 'Code shown on the banner, e.g. FESTIVE20' AFTER `cta_label`,
    ADD COLUMN `bg_color`   CHAR(7) NOT NULL DEFAULT '#2a2829' COMMENT 'Panel background, #rrggbb' AFTER `promo_code`,
    ADD COLUMN `text_color` CHAR(7) NOT NULL DEFAULT '#ffffff' COMMENT 'Panel text colour, #rrggbb' AFTER `bg_color`;

INSERT INTO `settings`
    (`uuid`, `group_code`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`)
VALUES
    (UUID(), 'storefront', 'storefront_header_bg',        '#c62d1f', 'string', 'Header background colour', 1),
    (UUID(), 'storefront', 'storefront_header_text',      '#ffffff', 'string', 'Header text colour', 1),
    (UUID(), 'storefront', 'storefront_accent',           '#ffd23f', 'string', 'Accent: search button, cart count, banner button', 1),
    (UUID(), 'storefront', 'storefront_primary',          '#c62d1f', 'string', 'Primary buttons and links', 1),
    (UUID(), 'storefront', 'storefront_page_bg',          '#f1f0ee', 'string', 'Page background behind the white panels', 1),
    (UUID(), 'storefront', 'storefront_announcement',     'Dispatched within 24 hours · All prices include GST', 'string', 'Announcement bar text (empty hides the bar)', 1),
    (UUID(), 'storefront', 'storefront_announcement_bg',  '#2a2829', 'string', 'Announcement bar background', 1),
    (UUID(), 'storefront', 'storefront_announcement_text','#ffffff', 'string', 'Announcement bar text colour', 1),
    (UUID(), 'storefront', 'storefront_tagline',          'Spices & Dry Fruits', 'string', 'Line under the store name in the header', 1),
    (UUID(), 'storefront', 'storefront_banner_seconds',   '5',       'int',    'Seconds each home banner shows before rotating', 1),
    (UUID(), 'storefront', 'storefront_show_deals',       '1',       'bool',   'Show Deals of the Day on the home page', 1)
ON DUPLICATE KEY UPDATE
    `group_code`  = VALUES(`group_code`),
    `description` = VALUES(`description`),
    `is_public`   = VALUES(`is_public`);

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('050_storefront_banners_theme', 50, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
