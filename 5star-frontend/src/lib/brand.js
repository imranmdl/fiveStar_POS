/**
 * Brand constants, mirrored from assets/js/config.js (the live site's single
 * source of truth for the header logo). Kept here rather than re-fetched at
 * runtime because the vanilla site hardcodes it the same way — there's no
 * settings-API endpoint for the storefront logo, only the one for admin's
 * own console branding (/admin/settings/logo).
 */
export const BRAND_NAME = 'Spice & Dry Fruits';
// VITE_BRAND_LOGO_URL (set at build time) overrides the hosted logo, so a new
// deployment can point at its own /uploads/... copy instead of the old host.
export const BRAND_LOGO_URL =
  import.meta.env.VITE_BRAND_LOGO_URL ||
  'https://5star.alimstech.com/spice-api/backend/public/uploads/branding/2026/08/b5a20af59afb73c66516bb7552658271.png';
export const BRAND_LOGO_ALT = 'Spice & Dry Fruits';
export const BRAND_LOGO_HEIGHT = 38;
