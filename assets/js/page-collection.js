/**
 * A campaign page: a curated set of products under one headline.
 *
 * Built by the shop owner in the console, pointed at by an advert. The customer
 * lands here, sees the selection, and buys from it exactly as they would from
 * the shop — same cards, same prices, same cart.
 *
 * THE TEMPLATES ARE FIXED. Four of them, designed once, sharing the shop's own
 * styling. A general page builder would let a campaign page drift into looking
 * like a different website, and the customer would rightly wonder where they
 * had ended up.
 */

import { api, ApiError } from './api.js';
import { mountChrome, mountFooter, escapeHtml, formatMoney, showError, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');
const slug = queryParam('slug');

function perKilo(price, grams) {
  if (!price || !grams || grams <= 0) return '';
  return `${formatMoney((Number(price) / Number(grams)) * 1000)} per kg`;
}

/**
 * A product card.
 *
 * Deliberately the same shape as the catalogue's. A campaign page that invents
 * its own card teaches the customer a second layout for no reason, and the two
 * drift apart the moment one is changed.
 */
function card(item, wide = false) {
  const pricing = item.pricing || {};
  const rating = item.rating || {};
  const weight = item.weight_grams || {};
  const flags = item.flags || {};
  const image = item.primary_image && item.primary_image.url;
  const saving = Number(pricing.max_discount_percentage) || 0;

  // Skipped for a sized product (clothing, footwear) — its weight is only a
  // shipping figure, not a customer decision.
  const packs = [];
  if (!item.has_size_options) {
    if (weight.min) packs.push(weight.min >= 1000 ? `${weight.min / 1000}kg` : `${weight.min}g`);
    if (weight.max && weight.max !== weight.min) {
      packs.push(weight.max >= 1000 ? `${weight.max / 1000}kg` : `${weight.max}g`);
    }
  }

  return `
    <div class="${wide ? 'col-12 col-md-6' : 'col'}">
      <article class="product-card h-100">
        <a class="product-media ${image ? '' : 'product-media--empty'} d-block text-decoration-none"
           href="product.html?slug=${encodeURIComponent(item.slug)}"
           aria-label="${escapeHtml(item.name)}">
          ${image
            ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(item.name)}" loading="lazy">`
            : escapeHtml((item.name || '?').charAt(0))}
          <div class="badge-stack">
            ${saving > 0 ? `<span class="tag tag--save">${escapeHtml(saving)}% off</span>` : ''}
            ${pricing.has_live_offer ? '<span class="tag tag--offer">Offer</span>' : ''}
            ${flags.is_organic ? '<span class="tag tag--organic">Organic</span>' : ''}
          </div>
        </a>

        <div class="product-body">
          ${item.headline
            ? `<div class="eyebrow mb-1" style="color:var(--gold-dark)">${escapeHtml(item.headline)}</div>`
            : (item.category ? `<div class="eyebrow mb-1">${escapeHtml(item.category.name)}</div>` : '')}

          <a class="product-name" href="product.html?slug=${encodeURIComponent(item.slug)}">
            ${escapeHtml(item.name)}
          </a>

          ${packs.length ? `
            <div class="pack-pills">
              ${packs.map((pack) => `<span class="pack-pill">${escapeHtml(pack)}</span>`).join('')}
            </div>` : ''}

          <div class="rating-line mb-2">
            ${Number(rating.count) > 0
              ? `<span class="rating-star">★</span> ${escapeHtml(Number(rating.average).toFixed(1))}
                 <span class="text-muted">(${escapeHtml(rating.count)})</span>`
              : '<span class="text-muted">No reviews yet</span>'}
          </div>

          <div class="mt-auto">
            <span class="price">${formatMoney(pricing.min_price)}</span>
            ${pricing.min_mrp && Number(pricing.min_mrp) > Number(pricing.min_price)
              ? `<span class="price-was">${formatMoney(pricing.min_mrp)}</span>` : ''}
            ${!item.has_size_options ? `<div class="price-per-kg">${escapeHtml(perKilo(pricing.min_price, weight.min))}</div>` : ''}
          </div>
        </div>
      </article>
    </div>`;
}

function header(collection) {
  const hero = collection.hero_image_url;

  return `
    <header class="mb-4 ${hero ? 'panel overflow-hidden' : ''}">
      ${hero
        ? `<img src="${escapeHtml(hero)}" alt="${escapeHtml(collection.hero_alt_text || collection.title)}"
                style="width:100%;max-height:320px;object-fit:cover">`
        : ''}
      <div class="${hero ? 'p-3 p-md-4' : ''}">
        <h1 class="h3 mb-2">${escapeHtml(collection.title)}</h1>
        ${collection.subtitle ? `<p class="lead mb-2">${escapeHtml(collection.subtitle)}</p>` : ''}
        ${collection.intro ? `<p class="text-muted mb-0">${escapeHtml(collection.intro)}</p>` : ''}
      </div>
    </header>`;
}

/** Each template arranges the same cards differently. */
function layout(collection, items) {
  if (items.length === 0) {
    return `
      <div class="panel text-center py-5">
        <p class="h5 mb-2">Nothing here just now</p>
        <p class="text-muted mb-3">This selection is being put together.</p>
        <a class="btn btn-spice" href="index.html">Browse the shop</a>
      </div>`;
  }

  const grid = (list, wide = false) => `
    <div class="row row-cols-2 ${wide ? '' : 'row-cols-md-3 row-cols-xl-4'} g-3">
      ${list.map((item) => card(item, wide)).join('')}
    </div>`;

  switch (collection.template) {
    // One product carried large, then the rest. For a campaign built around a
    // single hero item with supporting cast.
    case 'spotlight':
      return `
        ${grid(items.slice(0, 1), true)}
        ${items.length > 1 ? `<div class="mt-4">${grid(items.slice(1))}</div>` : ''}`;

    // Text, products, text. For a campaign that needs explaining — a harvest,
    // a region, a process.
    case 'story':
      return `
        ${grid(items)}
        ${collection.cta_label ? `
          <div class="text-center mt-4">
            <a class="btn btn-spice" href="index.html">${escapeHtml(collection.cta_label)}</a>
          </div>` : ''}`;

    // Gift framing: the practical questions a gift buyer has, answered above
    // the products rather than left to a FAQ.
    case 'gift':
      return `
        <div class="trust-strip mb-4">
          <span><b>Hand-packed</b> to order</span>
          <span><b>No prices</b> on anything we send as a gift</span>
          <span><b>Same-day dispatch</b> before 2pm</span>
        </div>
        ${grid(items)}
        <p class="text-muted small mt-3 mb-0">
          Sending to more than a few addresses, or want your logo on the box?
          <a href="gifting.html">Ask for a bulk quote</a> instead.
        </p>`;

    default:
      return grid(items);
  }
}

async function load() {
  if (!slug) {
    root.innerHTML = '<div class="alert alert-warning">No page was specified.</div>';
    return;
  }

  try {
    const response = await api.get(`/collections/${encodeURIComponent(slug)}`);
    const collection = response.data.collection;
    const items = response.data.items || [];

    document.title = `${collection.meta_title || collection.title} · Spice & Dry Fruits`;

    root.innerHTML = header(collection) + layout(collection, items);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      // A campaign that has ended is the most likely reason to be here, so the
      // page says so and offers the shop rather than showing a bare error.
      root.innerHTML = `
        <div class="panel text-center py-5">
          <p class="h5 mb-2">This page is no longer available</p>
          <p class="text-muted mb-3">The offer may have ended.</p>
          <a class="btn btn-spice" href="index.html">Browse the shop</a>
        </div>`;
      return;
    }

    showError(error, root);
  }
}

mountChrome('index.html');
mountFooter();
load();
