/**
 * Adverts on the storefront.
 *
 * THE RULE: AN ADVERT MUST NEVER COST THE CUSTOMER ANYTHING.
 *
 * That is a design constraint, not a slogan, and it drives every decision here:
 *
 *   - Loaded AFTER the products, never before. A slow advert must not delay the
 *     thing the customer came for.
 *   - A slot with nothing live in it collapses to nothing. No reserved gap, no
 *     spinner, no "advertisement" placeholder.
 *   - A failed request is silent. If the banner endpoint is down the shop simply
 *     has no banners; it does not show an error about advertising.
 *   - Dismissible, and a dismissal is remembered for the session. Someone who
 *     said no once should not be asked again on the next page.
 *   - No layout shift: the panel is inserted with its final height, so nothing
 *     the customer was about to tap moves.
 */

import { api, escapeHtml } from './api.js';

const DISMISSED = 'spice.dismissed_banners';

function dismissed() {
  try {
    return new Set(JSON.parse(sessionStorage.getItem(DISMISSED) || '[]'));
  } catch {
    return new Set();
  }
}

function remember(uuid) {
  try {
    const set = dismissed();
    set.add(uuid);
    sessionStorage.setItem(DISMISSED, JSON.stringify([...set]));
  } catch {
    // Session storage refused; the banner simply reappears next page.
  }
}

/**
 * Where a click should go.
 *
 * THE PUBLIC PAYLOAD NESTS THIS. The API returns `link: { type, value }`, not the
 * flat `link_type` / `link_value` the admin endpoints use. Reading the flat names
 * gave `undefined` for every advert, so `destination()` returned null, no anchor
 * was rendered, and clicking did nothing at all — silently, because a banner
 * without a link is a legitimate thing to render.
 */
function destination(banner) {
  const link = banner.link || {};
  const type = link.type || banner.link_type;
  const value = link.value ?? banner.link_value;

  if (!type || type === 'none' || value === null || value === undefined || value === '') {
    return null;
  }

  switch (type) {
    case 'category':
      return `index.html?category=${encodeURIComponent(value)}`;
    case 'product':
      return `product.html?slug=${encodeURIComponent(value)}`;
    case 'offer':
      return `index.html?offer=${encodeURIComponent(value)}`;
    case 'collection':
      return `collection.html?slug=${encodeURIComponent(value)}`;
    case 'url':
      // Only http(s). A javascript: or data: URL from the database would be a
      // stored-XSS hole with a friendly admin form attached to it.
      return /^https?:\/\//i.test(String(value)) ? value : null;
    default:
      return null;
  }
}

/** Whether the destination leaves the site, so the anchor opens a new tab. */
function isExternal(banner) {
  return ((banner.link || {}).type || banner.link_type) === 'url';
}

function markup(banner, href) {
  const picture = banner.image_url
    ? `<img src="${escapeHtml(banner.image_url)}" alt="${escapeHtml(banner.alt_text || banner.title)}"
            class="banner-image" loading="lazy">`
    : '';

  const body = `
    <div class="banner-body">
      <div>
        <div class="banner-title">${escapeHtml(banner.title)}</div>
        ${banner.subtitle ? `<div class="banner-sub">${escapeHtml(banner.subtitle)}</div>` : ''}
      </div>
      ${href && banner.cta_label
        ? `<span class="btn btn-quiet btn-sm flex-shrink-0">${escapeHtml(banner.cta_label)}</span>` : ''}
    </div>`;

  // THE PICTURE IS INSIDE THE LINK. Wrapping only the text meant that on a
  // banner that is mostly image — which is most of them — the part everyone
  // actually clicks was not clickable.
  const inner = picture + body;

  return `
    <div class="banner" data-banner-id="${escapeHtml(banner.uuid)}">
      ${href
        ? `<a class="banner-link" href="${escapeHtml(href)}"
              ${isExternal(banner) ? 'target="_blank" rel="noopener noreferrer"' : ''}>${inner}</a>`
        : inner}
      <button class="banner-close" type="button" data-dismiss-banner
              aria-label="Hide this advert">&times;</button>
    </div>`;
}

/**
 * Fills a placement.
 *
 * @param {string} placement  home_hero | home_strip | category_top | checkout
 * @param {string} selector   where to put it
 */
export async function mountBanners(placement, selector) {
  const host = document.querySelector(selector);
  if (!host) return;

  let banners = [];

  try {
    const response = await api.get('/banners', { placement });
    banners = response.data.banners || response.data || [];
  } catch {
    // Silent by design. A shop with no adverts is a working shop.
    return;
  }

  const hidden = dismissed();
  const live = banners.filter((banner) => !hidden.has(banner.uuid));

  if (live.length === 0) return;

  const banner = live[0];
  const href = destination(banner);

  host.innerHTML = markup(banner, href);

  host.querySelector('[data-dismiss-banner]').addEventListener('click', () => {
    remember(banner.uuid);
    host.innerHTML = '';
  });

  const link = host.querySelector('.banner-link');

  if (link) {
    link.addEventListener('click', () => {
      // Fire and forget. A click must not wait on analytics, and a failed
      // count is not worth interrupting a journey for.
      api.post(`/banners/${encodeURIComponent(banner.uuid)}/click`, {}).catch(() => {});
    });
  }
}
