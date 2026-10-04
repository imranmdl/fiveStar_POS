/** Catalogue browsing: categories, search, sorting and pagination. */

import { api } from './api.js';
import { mountChrome, mountFooter, escapeHtml, formatMoney, showError, skeleton,
         queryParam, showConnectionBanner, isConnectionFailure } from './ui.js';

const state = {
  page: Number(queryParam('page') || 1),
  category: queryParam('category') || '',
  search: queryParam('q') || '',
  sort: queryParam('sort') || '',
};

const productsEl = document.querySelector('[data-products]');
const paginationEl = document.querySelector('[data-pagination]');
const headingEl = document.querySelector('[data-results-heading]');

/**
 * Formats a price per kilo.
 *
 * THE SIGNATURE OF THIS SHOP. Comparing 250g of one thing against 500g of
 * another is arithmetic no shopper should have to do at the shelf, and it is
 * the single most useful number a spice merchant can show. Derived from the
 * cheapest pack, which is the one the headline price refers to.
 */
function perKilo(price, grams) {
  if (!price || !grams || grams <= 0) return '';

  const rate = (Number(price) / Number(grams)) * 1000;

  return `${formatMoney(rate)} per kg`;
}

function productCard(product) {
  const pricing = product.pricing || {};
  const rating = product.rating || {};
  const weight = product.weight_grams || {};
  const flags = product.flags || {};

  const from = pricing.min_price;
  const mrp = pricing.min_mrp;
  const saving = Number(pricing.max_discount_percentage) || 0;
  const image = product.primary_image && product.primary_image.url;
  const category = product.category && product.category.name;

  // Pack sizes as pills. For spices the pack IS the decision, so it belongs on
  // the card rather than one click away.
  const packs = [];

  if (weight.min) packs.push(weight.min >= 1000 ? `${weight.min / 1000}kg` : `${weight.min}g`);
  if (weight.max && weight.max !== weight.min) {
    packs.push(weight.max >= 1000 ? `${weight.max / 1000}kg` : `${weight.max}g`);
  }

  return `
    <div class="col">
      <article class="product-card">
        <a class="product-media ${image ? '' : 'product-media--empty'} d-block text-decoration-none"
           href="product.html?slug=${encodeURIComponent(product.slug)}"
           aria-label="${escapeHtml(product.name)}">
          ${image
            ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(product.name)}" loading="lazy">`
            : escapeHtml((product.name || '?').charAt(0))}

          <div class="badge-stack">
            ${saving > 0 ? `<span class="tag tag--save">${escapeHtml(saving)}% off</span>` : ''}
            ${pricing.has_live_offer ? '<span class="tag tag--offer">Offer</span>' : ''}
            ${flags.is_organic ? '<span class="tag tag--organic">Organic</span>' : ''}
          </div>
        </a>

        <div class="product-body">
          ${category ? `<div class="eyebrow mb-1">${escapeHtml(category)}</div>` : ''}

          <a class="product-name" href="product.html?slug=${encodeURIComponent(product.slug)}">
            ${escapeHtml(product.name)}
          </a>

          ${packs.length ? `
            <div class="pack-pills">
              ${packs.map((pack) => `<span class="pack-pill">${escapeHtml(pack)}</span>`).join('')}
              ${Number(pricing.variant_count) > packs.length
                ? `<span class="pack-pill">+${escapeHtml(pricing.variant_count - packs.length)} more</span>`
                : ''}
            </div>` : ''}

          <div class="rating-line mb-2">
            ${Number(rating.count) > 0
              ? `<span class="rating-star">★</span> ${escapeHtml(Number(rating.average).toFixed(1))}
                 <span class="text-muted">(${escapeHtml(rating.count)})</span>`
              : '<span class="text-muted">No reviews yet</span>'}
          </div>

          <div class="mt-auto">
            <span class="price">${formatMoney(from)}</span>
            ${mrp && Number(mrp) > Number(from)
              ? `<span class="price-was">${formatMoney(mrp)}</span>` : ''}
            <div class="price-per-kg">${escapeHtml(perKilo(from, weight.min))}</div>
          </div>
        </div>
      </article>
    </div>`;
}

function renderPagination(meta) {
  if (!meta || meta.total_pages <= 1) {
    paginationEl.innerHTML = '';
    return;
  }

  const pages = [];
  for (let page = 1; page <= meta.total_pages; page += 1) {
    pages.push(`
      <li class="page-item ${page === meta.page ? 'active' : ''}">
        <a class="page-link" href="#" data-page="${page}">${page}</a>
      </li>`);
  }

  paginationEl.innerHTML = `<ul class="pagination">${pages.join('')}</ul>`;

  paginationEl.querySelectorAll('[data-page]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      state.page = Number(link.dataset.page);
      loadProducts();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });
}

async function loadCategories() {
  const container = document.querySelector('[data-categories]');

  try {
    const response = await api.get('/categories');
    const categories = response.data.categories || [];

    container.innerHTML = [
      `<a class="list-group-item list-group-item-action ${state.category ? '' : 'active'}"
          href="#" data-category="">All products</a>`,
      ...categories.map((category) => `
        <a class="list-group-item list-group-item-action ${state.category === category.slug ? 'active' : ''}"
           href="#" data-category="${escapeHtml(category.slug)}">
          ${escapeHtml(category.name)}
          ${category.product_count ? `<span class="badge bg-light text-dark float-end">${escapeHtml(category.product_count)}</span>` : ''}
        </a>`),
    ].join('');

    container.querySelectorAll('[data-category]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        state.category = link.dataset.category;
        state.page = 1;
        loadCategories();
        loadProducts();
      });
    });
  } catch (error) {
    // Never leave the "Loading…" placeholder sitting there. A stuck spinner is
    // indistinguishable from a broken page.
    container.innerHTML =
      '<div class="list-group-item text-muted small">Categories unavailable.</div>';

    if (isConnectionFailure(error)) showConnectionBanner(error);
  }
}

async function loadProducts() {
  productsEl.innerHTML = Array.from({ length: 8 }, () => `
      <div class="col">
        <div class="product-card">
          <div class="product-media skeleton"></div>
          <div class="product-body">
            <div class="skeleton mb-2" style="height:.7rem;width:40%"></div>
            <div class="skeleton mb-2" style="height:1rem;width:85%"></div>
            <div class="skeleton" style="height:1.2rem;width:50%"></div>
          </div>
        </div>
      </div>`).join('');

  try {
    const response = await api.get('/products', {
      page: state.page,
      per_page: 12,
      category: state.category,
      q: state.search,
      sort: state.sort,
    });

    const products = response.data || [];

    headingEl.textContent = state.search
      ? `Results for “${state.search}”`
      : (state.category ? 'Category' : 'All products');

    if (products.length === 0) {
      productsEl.innerHTML = `
        <div class="col-12">
          <div class="panel text-center py-5 px-3">
            <p class="h5 mb-2">Nothing matched that</p>
            <p class="text-muted mb-3">
              Regional names work too — try &ldquo;haldi&rdquo; for turmeric or
              &ldquo;badam&rdquo; for almonds.
            </p>
            <a class="btn btn-quiet btn-sm" href="index.html">Show everything</a>
          </div>
        </div>`;
      paginationEl.innerHTML = '';
      return;
    }

    productsEl.innerHTML = products.map(productCard).join('');
    renderPagination(response.meta);
  } catch (error) {
    productsEl.innerHTML = '';
    showError(error, productsEl);
  }
}

document.querySelector('[data-search-form]').addEventListener('submit', (event) => {
  event.preventDefault();
  state.search = new FormData(event.target).get('q') || '';
  state.page = 1;
  loadProducts();
});

document.querySelector('[data-sort]').addEventListener('change', (event) => {
  state.sort = event.target.value;
  state.page = 1;
  loadProducts();
});

document.querySelector('#search').value = state.search;
document.querySelector('[data-sort]').value = state.sort;

mountChrome('index.html');
mountFooter();
loadCategories();
loadProducts();
