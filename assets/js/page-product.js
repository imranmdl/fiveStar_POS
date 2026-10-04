/** Product detail: pack sizes, add to cart, and reviews. */

import { api, ApiError } from './api.js';
import { mountChrome, mountFooter, refreshCartCount, escapeHtml, formatMoney,
         showError, toast, setBusy, queryParam } from './ui.js';
import { shareButton, bindShare } from './share.js';
import { mountReviewForm } from './review-form.js';

const root = document.querySelector('[data-page-root]');
const slug = queryParam('slug');
let selectedVariant = null;

function offerCategoryLabel(offer) {
  return offer.discount_type === 'free_items' ? 'Combo offer' : 'Special price';
}

/**
 * The "Available offers" box — same idea as the offer list under the price
 * on a Flipkart product page (a labelled category, the offer itself, one row
 * each), scaled to what this shop actually has: at most one live campaign
 * offer per product (see OfferRepository::activeOffersForProductUuids —
 * highest-priority offer wins, not a stacked list), so this renders exactly
 * one row rather than inventing bank/card offers this store doesn't run.
 */
function offersBox(offer) {
  const validity = offer.ends_date
    ? `<div class="small text-muted mt-1">Valid till ${escapeHtml(new Date(offer.ends_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }))}</div>`
    : '';

  return `
    <div class="panel p-3 mt-3" data-offers-box>
      <div class="fw-semibold small mb-2">Available offers</div>
      <div class="d-flex align-items-start gap-2">
        <span class="tag tag--offer flex-shrink-0 text-uppercase">${escapeHtml(offerCategoryLabel(offer))}</span>
        <div class="small">
          <span class="fw-semibold">${escapeHtml(offer.summary)}</span>
          <span class="text-muted">— ${escapeHtml(offer.title)}</span>
          ${validity}
        </div>
      </div>
    </div>`;
}

/**
 * A pack size, presented as a choosable card.
 *
 * `price_per_kg` comes straight from the API. Showing it here is what turns
 * "250g for ₹129 or 500g for ₹239" from a mental sum into an obvious answer —
 * and a shopper who can see the answer buys the bigger pack.
 *
 * A variant carrying a `size_label` (clothing, footwear, anything sized
 * rather than weighed — see VariantOptionService) shows that instead of the
 * weight, and skips pack_type/price_per_kg, which mean nothing for a sized
 * item. `weight_grams` still exists underneath for courier weight either way.
 */
function variantOption(variant) {
  const onOffer = Number(variant.mrp) > Number(variant.effective_price);
  const isSized = variant.size_label !== null && variant.size_label !== undefined && variant.size_label !== '';
  const weight = Number(variant.weight_grams) >= 1000
    ? `${Number(variant.weight_grams) / 1000} kg`
    : `${variant.weight_grams} g`;

  // Small boxes, not full-width rows — the product itself stays the focus,
  // and weight + price is all a buyer needs to pick a pack. Pack type and
  // per-kg rate (shown elsewhere) are left off this compact view on purpose.
  return `
    <label class="pack-option">
      <input type="radio" name="variant" value="${escapeHtml(variant.uuid)}" ${variant.is_default ? 'checked' : ''}>
      <span class="pack-option-size">${isSized ? escapeHtml(variant.size_label) : escapeHtml(weight)}</span>
      <span class="pack-option-price">${formatMoney(variant.effective_price)}</span>
      ${onOffer ? `<span class="pack-option-was">${formatMoney(variant.mrp)}</span>` : ''}
      ${Number(variant.discount_percentage) > 0
        ? `<span class="pack-option-badge">${escapeHtml(variant.discount_percentage)}% off</span>`
        : ''}
    </label>`;
}

/**
 * A compact card for the "More of this kind" / "You might also like" rows
 * below the product. No shared card module exists in this codebase —
 * page-catalog.js and page-collection.js each keep their own local card
 * function too — so this follows the same convention rather than
 * introducing a new shared dependency.
 */
function relatedCard(item) {
  const pricing = item.pricing || {};
  const weight = item.weight_grams || {};
  const image = item.primary_image && item.primary_image.url;

  const packLabel = item.has_size_options
    ? ''
    : (weight.min
        ? (weight.min >= 1000 ? `${weight.min / 1000}kg` : `${weight.min}g`)
        : '');

  return `
    <a class="panel p-2 text-decoration-none text-reset flex-shrink-0" style="width:9.5rem"
       href="product.html?slug=${encodeURIComponent(item.slug)}">
      <div class="product-media ${image ? '' : 'product-media--empty'}" style="aspect-ratio:1;border-radius:.5rem;overflow:hidden">
        ${image
          ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(item.name)}" loading="lazy" style="width:100%;height:100%;object-fit:cover">`
          : escapeHtml((item.name || '?').charAt(0))}
      </div>
      <div class="small fw-semibold mt-2 text-truncate">${escapeHtml(item.name)}</div>
      ${packLabel ? `<div class="text-muted small">${escapeHtml(packLabel)}</div>` : ''}
      <div class="price small">${formatMoney(pricing.min_price)}</div>
    </a>`;
}

function relatedSection(title, items) {
  if (!items || items.length === 0) return '';

  return `
    <section class="mt-5">
      <h2 class="h5 mb-3">${escapeHtml(title)}</h2>
      <div class="d-flex gap-3 overflow-auto pb-2">
        ${items.map(relatedCard).join('')}
      </div>
    </section>`;
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

function modalHost(attr) {
  let host = document.querySelector(`[${attr}]`);

  if (!host) {
    host = document.createElement('div');
    host.setAttribute(attr, '');
    document.body.appendChild(host);
  }

  return host;
}

/**
 * "This item has an offer — add it and avail the offer, or just add it?"
 * The offer itself is automatic (see OfferService's own doc comment — it
 * applies silently once the item is in the cart, there is no way to add an
 * item WITHOUT it being eligible), so both buttons lead to the same cart
 * call; this is purely so a shopper isn't surprised by a discount appearing
 * in their cart with no idea why. Resolves true unless dismissed via the X.
 */
function offerConfirmModal(offer, itemLabel) {
  return new Promise((resolve) => {
    const host = modalHost('data-offer-confirm-modal');

    host.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal>
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="h6 modal-title">Offer available — ${escapeHtml(itemLabel)}</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
              <p class="mb-0">
                <span class="tag tag--offer">${escapeHtml(offer.summary)}</span>
                <span class="d-block small text-muted mt-2">${escapeHtml(offer.title)}</span>
              </p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-marigold" data-avail-offer>Add to cart &amp; avail offer</button>
            </div>
          </div>
        </div>
      </div>`;

    const modalEl = host.querySelector('[data-modal]');
    const modal = new window.bootstrap.Modal(modalEl);
    let settled = false;

    const finish = (value) => {
      if (settled) return;
      settled = true;
      modal.hide();
      resolve(value);
    };

    host.querySelector('[data-avail-offer]').addEventListener('click', () => finish(true));
    modalEl.addEventListener('hidden.bs.modal', () => finish(false), { once: true });
    modal.show();
  });
}

async function addToCart(button, offer, productName) {
  if (!selectedVariant) {
    toast('Choose a pack size first.', 'danger');
    return;
  }

  if (offer) {
    const proceed = await offerConfirmModal(offer, productName);
    if (!proceed) return;
  }

  const quantity = Number(document.querySelector('[data-quantity]').value || 1);

  // Disabled on first click. Add-to-cart is safe to repeat — the server upserts
  // the line — but a customer double-tapping should see one confident response,
  // not two spinners.
  setBusy(button, true, 'Adding…');

  try {
    await api.post('/cart/items', { variant_uuid: selectedVariant, quantity });
    toast(offer ? `Added to your cart — ${offer.summary}.` : 'Added to your cart.');
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

    // Best effort — a product page without an offer badge is still complete.
    let offer = null;
    try {
      const lookup = await api.get('/offers/product-lookup', { product_uuids: product.uuid });
      offer = (lookup.data || {})[product.uuid] || null;
    } catch { /* no badge this time */ }

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
        <div class="col-12 col-lg-4 mx-auto">
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

        <div class="col-12">
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

          <div class="eyebrow mt-4 mb-2">${product.has_size_options ? 'Choose a size' : 'Choose a pack'}</div>
          <div data-variants class="pack-option-grid mb-2">${variants.map(variantOption).join('')}</div>

          ${offer ? offersBox(offer) : ''}

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
            ${shareButton(product)}
          </div>

          <div class="trust-strip mt-4">
            <span><b>Same-day dispatch</b> before 2pm</span>
            <span><b>Prepaid UPI</b> · GST included</span>
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

      ${relatedSection('More of this kind', product.similar_products)}
      ${relatedSection('You might also like', product.other_products)}

      <section class="mt-5" id="review">
        <h2 class="h5">Customer reviews</h2>
        <div data-reviews><div class="text-muted small">Loading reviews…</div></div>
        <div data-review-form-host></div>
      </section>`;

    // The gallery swaps the main image rather than opening a lightbox: fewer
    // moving parts, and it works the same on a phone.
    root.querySelectorAll('[data-thumb]').forEach((button) => {
      button.addEventListener('click', () => {
        const main = root.querySelector('[data-main-image]');
        if (main) main.src = button.dataset.thumb;
      });
    });

    // WhatsApp first on a phone, because that is how a recommendation travels.
    bindShare(root, formatMoney(variants[0] && variants[0].effective_price));

    // Deliberately not awaited: whether someone may write a review has nothing
    // to do with whether the page can be read.
    mountReviewForm(product.slug, root.querySelector('[data-review-form-host]'), () => {
      loadReviews(product.slug);
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
      .addEventListener('click', (event) => addToCart(event.currentTarget, offer, product.name));

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
