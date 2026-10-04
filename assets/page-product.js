/** Product detail: pack sizes, add to cart, and reviews. */

import { api, ApiError } from './api.js';
import { mountChrome, mountFooter, refreshCartCount, escapeHtml, formatMoney,
         showError, toast, setBusy, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');
const slug = queryParam('slug');
let selectedVariant = null;

/**
 * A pack size, presented as a choosable card.
 *
 * `price_per_kg` comes straight from the API. Showing it here is what turns
 * "250g for ₹129 or 500g for ₹239" from a mental sum into an obvious answer —
 * and a shopper who can see the answer buys the bigger pack.
 */
function variantOption(variant) {
  const onOffer = Number(variant.mrp) > Number(variant.effective_price);
  const weight = Number(variant.weight_grams) >= 1000
    ? `${Number(variant.weight_grams) / 1000} kg`
    : `${variant.weight_grams} g`;

  return `
    <label class="panel p-3 d-flex align-items-center gap-3 mb-2" style="cursor:pointer">
      <input class="form-check-input flex-shrink-0 m-0" type="radio" name="variant"
             value="${escapeHtml(variant.uuid)}" ${variant.is_default ? 'checked' : ''}>

      <span class="flex-grow-1">
        <span class="fw-semibold">${escapeHtml(weight)}</span>
        <span class="text-muted small ms-2">${escapeHtml(variant.pack_type || '')}</span>
        ${variant.price_per_kg
          ? `<span class="price-per-kg d-block">${formatMoney(variant.price_per_kg)} per kg</span>`
          : ''}
      </span>

      <span class="text-end flex-shrink-0">
        <span class="price">${formatMoney(variant.effective_price)}</span>
        ${onOffer ? `<span class="price-was d-block">${formatMoney(variant.mrp)}</span>` : ''}
        ${Number(variant.discount_percentage) > 0
          ? `<span class="tag tag--save d-inline-block mt-1">${escapeHtml(variant.discount_percentage)}% off</span>`
          : ''}
      </span>
    </label>`;
}

function reviewItem(review) {
  return `
    <div class="border-bottom py-3">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <span class="fw-semibold">${escapeHtml(review.author || 'Customer')}</span>
          ${review.is_verified_purchase
            ? '<span class="badge text-bg-success ms-2">Verified purchase</span>'
            : ''}
        </div>
        <div class="text-warning">${'★'.repeat(review.rating)}${'☆'.repeat(5 - review.rating)}</div>
      </div>
      ${review.title ? `<div class="fw-semibold mt-1">${escapeHtml(review.title)}</div>` : ''}
      ${review.body ? `<p class="mb-1 mt-1">${escapeHtml(review.body)}</p>` : ''}
      ${review.merchant_reply
        ? `<div class="bg-light border-start border-3 ps-3 py-2 mt-2 small">
             <span class="fw-semibold">Our reply:</span> ${escapeHtml(review.merchant_reply)}
           </div>`
        : ''}
    </div>`;
}

async function loadReviews(identifier) {
  const container = document.querySelector('[data-reviews]');
  if (!container) return;

  try {
    const response = await api.get(`/products/${encodeURIComponent(identifier)}/reviews`, { per_page: 10 });
    const reviews = response.data || [];
    const summary = (response.meta && response.meta.summary) || {};

    if (reviews.length === 0) {
      container.innerHTML = `
        <p class="text-muted">No reviews yet. Reviews can be written by customers
        once an order containing this product has been delivered to them.</p>`;
      return;
    }

    container.innerHTML = `
      <div class="d-flex align-items-baseline gap-3 mb-3">
        <span class="display-6">${escapeHtml(Number(summary.rating_average || 0).toFixed(1))}</span>
        <span class="text-muted">${escapeHtml(summary.review_count || 0)} review(s),
          ${escapeHtml(summary.verified_count || 0)} from verified purchases</span>
      </div>
      ${reviews.map(reviewItem).join('')}`;
  } catch {
    container.innerHTML = '<p class="text-muted">Reviews could not be loaded.</p>';
  }
}

async function addToCart(button) {
  if (!selectedVariant) {
    toast('Choose a pack size first.', 'danger');
    return;
  }

  const quantity = Number(document.querySelector('[data-quantity]').value || 1);

  // Disabled on first click. Add-to-cart is safe to repeat — the server upserts
  // the line — but a customer double-tapping should see one confident response,
  // not two spinners.
  setBusy(button, true, 'Adding…');

  try {
    await api.post('/cart/items', { variant_uuid: selectedVariant, quantity });
    toast('Added to your cart.');
    refreshCartCount();
  } catch (error) {
    showError(error);
  } finally {
    setBusy(button, false);
  }
}

async function load() {
  if (!slug) {
    root.innerHTML = '<div class="alert alert-warning">No product was specified.</div>';
    return;
  }

  try {
    const response = await api.get(`/products/${encodeURIComponent(slug)}`);
    const product = response.data.product;
    const variants = product.variants || [];
    selectedVariant = (variants.find((v) => v.is_default) || variants[0] || {}).uuid || null;

    document.title = `${product.name} · Spice & Dry Fruits`;

    const flags = product.flags || {};
    const origin = product.origin || {};
    const rating = product.rating || {};
    const image = product.primary_image && product.primary_image.url;
    const gallery = (product.media || []).filter((item) => item.media_type === 'image');

    root.innerHTML = `
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
          <li class="breadcrumb-item"><a class="link-secondary text-decoration-none" href="index.html">Shop</a></li>
          ${product.category ? `
            <li class="breadcrumb-item">
              <a class="link-secondary text-decoration-none"
                 href="index.html?category=${encodeURIComponent(product.category.slug)}">
                ${escapeHtml(product.category.name)}
              </a>
            </li>` : ''}
          <li class="breadcrumb-item active" aria-current="page">${escapeHtml(product.name)}</li>
        </ol>
      </nav>

      <div class="row g-4">
        <div class="col-12 col-lg-5">
          <div class="panel overflow-hidden">
            <div class="product-media ${image ? '' : 'product-media--empty'}">
              ${image
                ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(product.name)}" data-main-image>`
                : escapeHtml(product.name.charAt(0))}
            </div>
          </div>

          ${gallery.length > 1 ? `
            <div class="d-flex gap-2 mt-2 overflow-auto">
              ${gallery.map((item) => `
                <button class="panel p-0 overflow-hidden flex-shrink-0 border-0"
                        style="width:4.5rem;height:4.5rem" data-thumb="${escapeHtml(item.url)}"
                        type="button" aria-label="Show this photograph">
                  <img src="${escapeHtml(item.url)}" alt="" style="width:100%;height:100%;object-fit:cover">
                </button>`).join('')}
            </div>` : ''}
        </div>

        <div class="col-12 col-lg-7">
          ${product.brand ? `<div class="eyebrow mb-1">${escapeHtml(product.brand)}</div>` : ''}
          <h1 class="h3 mb-2">${escapeHtml(product.name)}</h1>

          <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            ${Number(rating.count) > 0
              ? `<span class="rating-line">
                   <span class="rating-star">★</span>
                   <b>${escapeHtml(Number(rating.average).toFixed(1))}</b>
                   <span class="text-muted">(${escapeHtml(rating.count)} reviews)</span>
                 </span>`
              : '<span class="text-muted small">No reviews yet</span>'}
            ${flags.is_organic ? '<span class="tag tag--organic">Organic</span>' : ''}
            ${origin.region ? `<span class="small text-muted">From ${escapeHtml(origin.region)}</span>` : ''}
          </div>

          ${product.short_description
            ? `<p class="text-muted">${escapeHtml(product.short_description)}</p>` : ''}

          <div class="eyebrow mt-4 mb-2">Choose a pack</div>
          <div data-variants>${variants.map(variantOption).join('')}</div>

          <div class="d-flex gap-2 align-items-center mt-3">
            <div class="qty-stepper">
              <button type="button" data-qty-down aria-label="Fewer">−</button>
              <label class="visually-hidden" for="quantity">Quantity</label>
              <input id="quantity" data-quantity type="number" value="1" min="1" max="20" readonly>
              <button type="button" data-qty-up aria-label="More">+</button>
            </div>
            <button class="btn btn-marigold btn-lg flex-grow-1" data-add-to-cart type="button">
              Add to cart
            </button>
          </div>

          <div class="trust-strip mt-4">
            <span><b>Prepaid UPI</b> only</span>
            <span><b>GST included</b> in the price</span>
            ${product.shelf_life_days
              ? `<span><b>${escapeHtml(product.shelf_life_days)} days</b> shelf life</span>` : ''}
          </div>
        </div>
      </div>

      ${product.description || product.ingredients ? `
        <section class="mt-5 row g-4">
          ${product.description ? `
            <div class="col-12 col-lg-7">
              <h2 class="h5">About this product</h2>
              <p class="mb-0">${escapeHtml(product.description)}</p>
            </div>` : ''}
          ${product.ingredients ? `
            <div class="col-12 col-lg-5">
              <h2 class="h5">Ingredients</h2>
              <p class="mb-0 text-muted">${escapeHtml(product.ingredients)}</p>
            </div>` : ''}
        </section>` : ''}

      <section class="mt-5">
        <h2 class="h5">Customer reviews</h2>
        <div data-reviews><div class="text-muted small">Loading reviews…</div></div>
      </section>`;

    // The gallery swaps the main image rather than opening a lightbox: fewer
    // moving parts, and it works the same on a phone.
    root.querySelectorAll('[data-thumb]').forEach((button) => {
      button.addEventListener('click', () => {
        const main = root.querySelector('[data-main-image]');
        if (main) main.src = button.dataset.thumb;
      });
    });

    const quantityInput = root.querySelector('[data-quantity]');

    root.querySelector('[data-qty-down]').addEventListener('click', () => {
      quantityInput.value = String(Math.max(1, Number(quantityInput.value) - 1));
    });

    root.querySelector('[data-qty-up]').addEventListener('click', () => {
      quantityInput.value = String(Math.min(20, Number(quantityInput.value) + 1));
    });

    root.querySelectorAll('[name="variant"]').forEach((input) => {
      input.addEventListener('change', () => { selectedVariant = input.value; });
    });

    root.querySelector('[data-add-to-cart]')
      .addEventListener('click', (event) => addToCart(event.currentTarget));

    loadReviews(slug);
  } catch (error) {
    root.innerHTML = '';
    if (error instanceof ApiError && error.status === 404) {
      root.innerHTML = `
        <div class="alert alert-warning">
          That product is no longer available. <a href="index.html">Back to the shop</a>.
        </div>`;
      return;
    }
    showError(error, root);
  }
}

mountChrome('index.html');
mountFooter();
load();
