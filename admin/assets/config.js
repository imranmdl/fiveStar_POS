/**
 * Admin console settings (brand only).
 *
 * URLs — the API, the site, the uploads folder — are NOT set here. They live
 * in one place, assets/js/env.js (../assets/js/env.js from this folder),
 * which every page loads first.
 */

/**
 * Your brand, shown in the sidebar and on the sign-in card.
 *
 * The two logos differ because the backgrounds do: the sidebar is deep forest,
 * the sign-in card is white. A single logo would be invisible on one of them.
 *
 * Drop your own files into ../assets/img/ and point these at them, or set a
 * logoUrl to null to show the name as text.
 */
window.SPICE_BRAND = {
  name: 'Spice & Dry Fruits',
  // For the dark sidebar.
  logoUrl: window.SPICE_UPLOADS_URL + '/branding/2026/08/b5a20af59afb73c66516bb7552658271.png',
  // For the white sign-in card.
  logoUrlLight: '../assets/img/logo-dark.svg',
  logoAlt: 'Spice & Dry Fruits',
  logoHeight: 34,
};
