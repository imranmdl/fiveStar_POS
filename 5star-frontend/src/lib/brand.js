/**
 * Brand constants, mirrored from assets/js/config.js (the live site's single
 * source of truth for the header logo). Kept here rather than re-fetched at
 * runtime because the vanilla site hardcodes it the same way — there's no
 * settings-API endpoint for the storefront logo, only the one for admin's
 * own console branding (/admin/settings/logo).
 */
export const BRAND_NAME = '5 Star Spices';
// Bundled with the app (public/brand/), so it loads instantly on the website
// and offline inside the Android app. VITE_BRAND_LOGO_URL (build time) can
// still point somewhere else.
export const BRAND_LOGO_URL = import.meta.env.VITE_BRAND_LOGO_URL || '/brand/logo-192.png';
export const BRAND_LOGO_ALT = '5 Star — Since 1984';
export const BRAND_LOGO_HEIGHT = 44;
