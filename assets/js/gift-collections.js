/**
 * Home page: festive gift collections — "Ganesh Chaturthi Gifts", "Eid Gifts"
 * and whatever else staff have published under the "gift" template on the
 * Campaign pages screen (admin/content.html). Each tile opens the full
 * campaign page for that occasion.
 *
 * Same advert rules banners.js and offer-hero.js already follow: loaded after
 * the products, and a slot with nothing live in it renders nothing at all — a
 * quiet shop with no festival on right now must not show an empty strip.
 */

import { api, escapeHtml } from './api.js';

function destination(collection) {
  return `collection.html?slug=${encodeURIComponent(collection.slug)}`;
}

function tile(collection) {
  const picture = collection.hero_image_url
    ? ` style="background-image:linear-gradient(180deg, rgba(11,59,46,0) 40%, rgba(11,59,46,.75)), url('${escapeHtml(collection.hero_image_url)}')"`
    : '';

  return `
    <a class="gift-tile" href="${destination(collection)}"${picture}>
      ${!collection.hero_image_url ? `<span class="gift-tile-fallback">${escapeHtml(collection.title)}</span>` : ''}
      <span class="gift-tile-caption">
        <span class="gift-tile-title">${escapeHtml(collection.title)}</span>
        ${collection.subtitle ? `<span class="gift-tile-sub">${escapeHtml(collection.subtitle)}</span>` : ''}
      </span>
    </a>`;
}

export async function mountGiftCollections(host) {
  if (!host) return;

  try {
    const response = await api.get('/collections');
    const collections = response.data.collections || [];

    if (collections.length === 0) return;

    host.innerHTML = `
      <div class="d-flex justify-content-between align-items-end mb-2">
        <h2 class="h5 mb-0">Festive gifting</h2>
        <a class="gifting-nav-btn" href="gifting.html">🎁 Corporate &amp; bulk gifting</a>
      </div>
      <div class="gift-tile-row">${collections.map(tile).join('')}</div>`;
  } catch {
    // An empty strip is fine; a broken home page is not.
    host.innerHTML = '';
  }
}
