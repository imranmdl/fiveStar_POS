/**
 * Storefront settings (brand only).
 *
 * URLs — the API, the site, the uploads folder — are NOT set here. They live
 * in one place, assets/js/env.js, which every page loads first.
 */

/**
 * Your brand.
 *
 * `logoUrl` is drawn in the header. Leave it as the placeholder until your own
 * mark is ready — it is drawn in the shop's colours, so the header looks
 * deliberate rather than unfinished.
 *
 * To use your own: drop the file into assets/img/ and point logoUrl at it.
 * Roughly 4:1 works best (about 320x80). PNG, SVG and WebP all work; SVG stays
 * sharp on every screen.
 *
 * Set logoUrl to null to show the name as text instead.
 */
window.SPICE_BRAND = {
  name: 'Spice & Dry Fruits',
  logoUrl: window.SPICE_UPLOADS_URL + '/branding/2026/08/b5a20af59afb73c66516bb7552658271.png',
  logoAlt: 'Spice & Dry Fruits',
  // How tall the logo sits in the header, in pixels. The width follows.
  logoHeight: 38,
};
