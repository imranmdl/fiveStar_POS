/**
 * Categories, banners and pages — the merchandising a shop owner changes weekly.
 *
 * Three things share one screen because they are one job: deciding what the
 * shop puts in front of people. Splitting them across three menu items would
 * mean three places to look before a festival.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml,
         badge, emptyState, queryParam } from './console.js?v=9';

let root = null;
let tab = queryParam('tab') || 'categories';

// A festival on the Festival Calendar screen can open straight into its own
// campaign page here, instead of making staff find it again in the list.
let openCollection = tab === 'collections' ? queryParam('open') : null;

const PLACEMENTS = [
  ['home_hero', 'Home — large banner at the top'],
  ['home_strip', 'Home — slim strip below the categories'],
  ['category_top', 'Above a category listing'],
  ['checkout', 'Checkout page'],
  ['app_home', 'Mobile app home'],
];

const LINK_TYPES = [
  ['none', 'Not clickable'],
  ['collection', 'Open a campaign page'],
  ['category', 'Open a category'],
  ['product', 'Open a product'],
  ['offer', 'Open an offer'],
  ['url', 'Open a web address'],
];

const TEMPLATES = [
  ['grid', 'Grid — a plain row of products'],
  ['spotlight', 'Spotlight — one product large, then the rest'],
  ['story', 'Story — introduction, products, a closing button'],
  ['gift', 'Gift — hamper framing with gifting reassurances'],
];

function tabs() {
  return `
    <ul class="nav nav-tabs mb-3">
      ${[['categories', 'Categories'], ['collections', 'Campaign pages'],
         ['banners', 'Adverts'], ['pages', 'Pages']]
        .map(([key, label]) => `
          <li class="nav-item">
            <a class="nav-link ${tab === key ? 'active' : ''}" href="#" data-tab="${key}">${label}</a>
          </li>`).join('')}
    </ul>`;
}

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

function categoryRow(category, depth = 0) {
  const rows = [`
    <tr data-category="${escapeHtml(category.uuid)}">
      <td>
        <span style="padding-left:${depth * 1.25}rem">
          ${depth ? '<span class="text-muted">└ </span>' : ''}${escapeHtml(category.name)}
        </span>
        <div class="small text-muted" style="padding-left:${depth * 1.25}rem">${escapeHtml(category.slug)}</div>
      </td>
      <td class="text-center small">${escapeHtml(category.product_count ?? 0)}</td>
      <td class="text-center small">${category.is_featured ? 'Featured' : '—'}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary" data-edit-category type="button">Rename</button>
      </td>
    </tr>`];

  (category.children || []).forEach((child) => rows.push(categoryRow(child, depth + 1)));

  return rows.join('');
}

async function renderCategories(container) {
  try {
    const response = await api.get('/admin/categories');
    const categories = response.data.categories || response.data || [];

    container.innerHTML = `
      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Categories</div>
            <div class="table-responsive">
              <table class="table table-tight mb-0">
                <thead><tr><th>Name</th><th class="text-center">Products</th>
                  <th class="text-center">Home</th><th></th></tr></thead>
                <tbody>${categories.map((c) => categoryRow(c)).join('')}</tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Add a category</div>
            <div class="card-body">
              <form data-category-form>
                <div class="mb-2">
                  <label class="form-label small" for="cat_name">Name <span class="text-danger">*</span></label>
                  <input class="form-control form-control-sm" id="cat_name" name="name" required minlength="2">
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="cat_parent">Sits under</label>
                  <select class="form-select form-select-sm" id="cat_parent" name="parent_slug">
                    <option value="">Nothing — a top-level category</option>
                    ${categories.map((c) => `
                      <option value="${escapeHtml(c.slug)}">${escapeHtml(c.name)}</option>`).join('')}
                  </select>
                  <div class="form-text small">
                    Only top-level categories appear in the shop's navigation strip.
                  </div>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="cat_description">Description</label>
                  <textarea class="form-control form-control-sm" id="cat_description"
                            name="description" rows="2"></textarea>
                </div>

                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" id="cat_featured" name="is_featured">
                  <label class="form-check-label small" for="cat_featured">Show on the home page</label>
                </div>

                <button class="btn btn-sm btn-dark" type="submit">Add category</button>
              </form>
            </div>
          </div>
        </div>
      </div>`;

    container.querySelector('[data-category-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button');
      const payload = {};

      new FormData(form).forEach((value, key) => { if (value !== '') payload[key] = value; });
      payload.is_featured = form.querySelector('#cat_featured').checked;

      setBusy(button, true, 'Adding');

      try {
        await api.post('/admin/categories', payload);
        toast(`Category “${payload.name}” added.`);
        renderCategories(container);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    container.querySelectorAll('[data-edit-category]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-category]').dataset.category;
        const name = window.prompt('New name for this category');
        if (!name) return;

        setBusy(button, true, '…');

        try {
          await api.patch(`/admin/categories/${encodeURIComponent(uuid)}`, { name });
          toast('Category renamed.');
          renderCategories(container);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

// ---------------------------------------------------------------------------
// Campaign pages
// ---------------------------------------------------------------------------

async function renderCollections(container) {
  if (openCollection) return renderCollectionEditor(container, openCollection);

  try {
    const response = await api.get('/admin/collections');
    const collections = response.data.collections || [];

    container.innerHTML = `
      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Campaign pages</div>
            ${collections.length === 0
              ? `<div class="card-body">${emptyState('No campaign pages yet',
                  'Build one for a festival or a sale, then point an advert at it.')}</div>`
              : `<div class="table-responsive">
                   <table class="table table-tight mb-0">
                     <thead><tr><th>Page</th><th>Layout</th><th class="text-center">Items</th>
                       <th class="text-center">Views</th><th>Status</th><th></th></tr></thead>
                     <tbody>
                       ${collections.map((c) => `
                         <tr>
                           <td>
                             <span class="fw-semibold">${escapeHtml(c.title)}</span>
                             <div class="small text-muted">/${escapeHtml(c.slug)}</div>
                           </td>
                           <td class="small">${escapeHtml(c.template)}</td>
                           <td class="text-center small">
                             ${escapeHtml(c.purchasable_count)}
                             ${c.item_count !== c.purchasable_count
                               ? `<span class="text-warning"> of ${escapeHtml(c.item_count)}</span>` : ''}
                           </td>
                           <td class="text-center small">${escapeHtml(c.view_count)}</td>
                           <td>
                             ${c.is_live
                               ? '<span class="badge text-bg-success">Live</span>'
                               : (c.has_expired
                                   ? '<span class="badge text-bg-secondary">Ended</span>'
                                   : `<span class="badge text-bg-warning">${escapeHtml(c.status)}</span>`)}
                           </td>
                           <td class="text-end">
                             <button class="btn btn-sm btn-outline-secondary"
                                     data-open-collection="${escapeHtml(c.slug)}" type="button">Edit</button>
                           </td>
                         </tr>`).join('')}
                     </tbody>
                   </table>
                 </div>`}
          </div>
          <p class="text-muted small mt-2 mb-0">
            "3 of 5" under Items means two products on the page are not on sale, so
            shoppers will not see them. A page cannot be published with none.
          </p>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card">
            <div class="card-header bg-white fw-semibold">New campaign page</div>
            <div class="card-body">
              <form data-collection-form>
                <div class="mb-2">
                  <label class="form-label small" for="col_title">Title <span class="text-danger">*</span></label>
                  <input class="form-control form-control-sm" id="col_title" name="title"
                         required minlength="3" placeholder="Diwali Gifting 2026">
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="col_subtitle">Supporting line</label>
                  <input class="form-control form-control-sm" id="col_subtitle" name="subtitle"
                         placeholder="Hampers hand-packed to order">
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="col_template">Layout</label>
                  <select class="form-select form-select-sm" id="col_template" name="template">
                    ${TEMPLATES.map(([value, label]) => `
                      <option value="${value}">${escapeHtml(label)}</option>`).join('')}
                  </select>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="col_intro">Introduction</label>
                  <textarea class="form-control form-control-sm" id="col_intro" name="intro"
                            rows="2" placeholder="A short paragraph above the products."></textarea>
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label small" for="col_start">Runs from</label>
                    <input class="form-control form-control-sm" id="col_start" name="starts_date" type="date">
                  </div>
                  <div class="col-6">
                    <label class="form-label small" for="col_end">Until</label>
                    <input class="form-control form-control-sm" id="col_end" name="ends_date" type="date">
                    <div class="form-text small">
                      Set this. A Diwali page still live in January is the mistake
                      that actually happens.
                    </div>
                  </div>
                </div>

                <button class="btn btn-sm btn-dark" type="submit">Create page</button>
              </form>
            </div>
          </div>
        </div>
      </div>`;

    container.querySelectorAll('[data-open-collection]').forEach((button) => {
      button.addEventListener('click', () => {
        openCollection = button.dataset.openCollection;
        renderCollections(container);
      });
    });

    container.querySelector('[data-collection-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button');
      const payload = {};

      new FormData(form).forEach((value, key) => { if (value !== '') payload[key] = value; });

      if (payload.starts_date) payload.starts_date = `${payload.starts_date} 00:00:00`;
      if (payload.ends_date) payload.ends_date = `${payload.ends_date} 23:59:59`;

      setBusy(button, true, 'Creating');

      try {
        const response = await api.post('/admin/collections', payload);
        toast('Page created. Now choose what goes on it.');
        openCollection = response.data.collection.slug;
        renderCollections(container);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

async function renderCollectionEditor(container, slug) {
  try {
    const [detail, products] = await Promise.all([
      api.get(`/admin/collections/${encodeURIComponent(slug)}`),
      api.get('/admin/products', { per_page: 200 }),
    ]);

    const collection = detail.data.collection;
    const items = detail.data.items || [];
    const chosen = new Set(items.map((item) => item.slug));
    const live = items.filter((item) => item.is_purchasable).length;

    container.innerHTML = `
      <button class="btn btn-sm btn-link px-0 mb-2" data-back type="button">← All campaign pages</button>

      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h5 mb-1">${escapeHtml(collection.title)}</h2>
          <div class="small text-muted">
            /${escapeHtml(collection.slug)} · ${escapeHtml(collection.template)} layout ·
            ${escapeHtml(collection.view_count)} view(s)
          </div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
             href="../collection.html?slug=${encodeURIComponent(collection.slug)}">Preview</a>
          ${collection.status === 'published'
            ? '<button class="btn btn-sm btn-outline-warning" data-status="draft" type="button">Unpublish</button>'
            : '<button class="btn btn-sm btn-success" data-status="published" type="button">Publish</button>'}
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Picture</div>
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
          ${collection.hero_image_url
            ? `<img src="${escapeHtml(collection.hero_image_url)}" alt="" style="width:10rem;height:6rem;object-fit:cover;border-radius:.5rem">`
            : '<div class="text-muted small">No picture yet — shown as a plain tile on the home page until one is added.</div>'}
          <form class="d-flex flex-wrap gap-2 align-items-center" data-hero-form>
            <input class="form-control form-control-sm" type="file" name="image" accept="image/jpeg,image/png,image/webp" required style="width:auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit">${collection.hero_image_url ? 'Replace' : 'Upload'}</button>
          </form>
        </div>
      </div>

      ${live === 0 ? `
        <div class="alert alert-warning small">
          Nothing on this page is on sale, so it cannot be published. Add products
          that are published in the catalogue.
        </div>` : ''}

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card">
            <div class="card-header bg-white fw-semibold">On this page</div>
            ${items.length === 0
              ? `<div class="card-body">${emptyState('Nothing chosen yet',
                  'Pick products from the list on the right.')}</div>`
              : `<ul class="list-group list-group-flush">
                   ${items.map((item) => `
                     <li class="list-group-item d-flex justify-content-between align-items-center"
                         data-item="${escapeHtml(item.item_uuid)}">
                       <span>
                         ${escapeHtml(item.name)}
                         ${item.headline
                           ? `<div class="small" style="color:var(--gold-dark,#8A6A1F)">${escapeHtml(item.headline)}</div>`
                           : ''}
                         ${item.is_purchasable
                           ? ''
                           : '<div class="small text-danger">Not on sale — shoppers will not see this</div>'}
                       </span>
                       <button class="btn btn-sm btn-outline-danger" data-remove-item type="button">Remove</button>
                     </li>`).join('')}
                 </ul>`}
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Add a product</div>
            <div class="card-body">
              <form data-item-form>
                <div class="mb-2">
                  <label class="form-label small" for="item_product">Product</label>
                  <select class="form-select form-select-sm" id="item_product" name="product" required>
                    <option value="">Choose…</option>
                    ${(products.data || [])
                      .filter((product) => !chosen.has(product.slug))
                      .map((product) => `
                        <option value="${escapeHtml(product.slug)}">
                          ${escapeHtml(product.name)}${product.status === 'published' ? '' : ' (not on sale)'}
                        </option>`).join('')}
                  </select>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="item_headline">Label above it</label>
                  <input class="form-control form-control-sm" id="item_headline" name="headline"
                         maxlength="120" placeholder="Our pick for gifting">
                  <div class="form-text small">
                    Shown only on this page — the product itself is unchanged.
                  </div>
                </div>

                <button class="btn btn-sm btn-dark" type="submit">Add to page</button>
              </form>
            </div>
          </div>
        </div>
      </div>`;

    container.querySelector('[data-back]').addEventListener('click', () => {
      openCollection = null;
      renderCollections(container);
    });

    container.querySelector('[data-hero-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button');
      const file = form.querySelector('[name="image"]').files[0];

      if (!file) { toast('Choose a picture first.', 'danger'); return; }

      const body = new FormData();
      body.append('image', file);
      setBusy(button, true, 'Uploading');

      try {
        await api.upload(`/admin/collections/${encodeURIComponent(slug)}/image`, body);
        toast('Picture updated.');
        renderCollectionEditor(container, slug);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    container.querySelectorAll('[data-status]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Saving');
        try {
          await api.post(`/admin/collections/${encodeURIComponent(slug)}/status`,
            { status: button.dataset.status });
          toast(button.dataset.status === 'published' ? 'Page is live.' : 'Page unpublished.');
          renderCollectionEditor(container, slug);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    container.querySelector('[data-item-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button');
      const payload = { product: form.querySelector('#item_product').value };
      const headline = form.querySelector('#item_headline').value.trim();

      if (headline) payload.headline = headline;
      payload.display_order = (items.length + 1) * 10;

      setBusy(button, true, 'Adding');

      try {
        await api.post(`/admin/collections/${encodeURIComponent(slug)}/items`, payload);
        toast('Added to the page.');
        renderCollectionEditor(container, slug);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    container.querySelectorAll('[data-remove-item]').forEach((button) => {
      button.addEventListener('click', async () => {
        const itemUuid = button.closest('[data-item]').dataset.item;
        setBusy(button, true, '…');

        try {
          await api.delete(
            `/admin/collections/${encodeURIComponent(slug)}/items/${encodeURIComponent(itemUuid)}`
          );
          toast('Removed from the page.');
          renderCollectionEditor(container, slug);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    openCollection = null;
    container.innerHTML = '';
    showError(error, container);
  }
}

// ---------------------------------------------------------------------------
// Adverts

// ---------------------------------------------------------------------------

async function renderBanners(container) {
  try {
    const response = await api.get('/admin/banners');
    const banners = response.data.banners || response.data || [];

    container.innerHTML = `
      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Adverts</div>
            ${banners.length === 0
              ? `<div class="card-body">${emptyState('No adverts yet',
                  'Add one on the right. It appears in the shop as soon as it is live.')}</div>`
              : `<div class="table-responsive">
                   <table class="table table-tight mb-0">
                     <thead><tr><th>Advert</th><th>Where</th><th class="text-center">Seen</th>
                       <th class="text-center">Clicks</th><th></th></tr></thead>
                     <tbody>
                       ${banners.map((banner) => `
                         <tr data-banner="${escapeHtml(banner.uuid)}">
                           <td>
                             <span class="fw-semibold">${escapeHtml(banner.title)}</span>
                             <div class="small text-muted">${escapeHtml(banner.subtitle || '')}</div>
                           </td>
                           <td class="small">${escapeHtml(String(banner.placement).replace(/_/g, ' '))}</td>
                           <td class="text-center small">${escapeHtml(banner.impression_count ?? 0)}</td>
                           <td class="text-center small">${escapeHtml(banner.click_count ?? 0)}</td>
                           <td class="text-end">
                             <button class="btn btn-sm btn-outline-danger" data-remove-banner type="button">Remove</button>
                           </td>
                         </tr>`).join('')}
                     </tbody>
                   </table>
                 </div>`}
          </div>

          <p class="text-muted small mt-2 mb-0">
            Adverts never block the page. They load after the products, they can be
            dismissed, and a slot with nothing live in it collapses to nothing
            rather than leaving a gap.
          </p>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Add an advert</div>
            <div class="card-body">
              <form data-banner-form>
                <div class="mb-2">
                  <label class="form-label small" for="b_title">Headline <span class="text-danger">*</span></label>
                  <input class="form-control form-control-sm" id="b_title" name="title" required minlength="2">
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="b_subtitle">Supporting line</label>
                  <input class="form-control form-control-sm" id="b_subtitle" name="subtitle">
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="b_placement">Where it appears <span class="text-danger">*</span></label>
                  <select class="form-select form-select-sm" id="b_placement" name="placement" required>
                    ${PLACEMENTS.map(([value, label]) => `
                      <option value="${value}">${escapeHtml(label)}</option>`).join('')}
                  </select>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="b_link_type">On click</label>
                  <select class="form-select form-select-sm" id="b_link_type" name="link_type">
                    ${LINK_TYPES.map(([value, label]) => `
                      <option value="${value}">${escapeHtml(label)}</option>`).join('')}
                  </select>
                </div>

                <!-- A PICKER, NOT A TEXT BOX.
                     Asking someone to type a product's address means asking them
                     to know it, and getting it wrong is rejected only after the
                     form is submitted. The options come from the real catalogue,
                     so an advert cannot point at something that does not exist. -->
                <div class="mb-2 d-none" data-link-value-field>
                  <label class="form-label small" for="b_link_value">Which one</label>
                  <select class="form-select form-select-sm" id="b_link_value" name="link_value">
                    <option value="">Choose…</option>
                  </select>
                  <input class="form-control form-control-sm d-none" id="b_link_url" name="link_url"
                         placeholder="https://example.com/page">
                  <div class="form-text small" data-link-hint></div>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="b_cta">Button text</label>
                  <input class="form-control form-control-sm" id="b_cta" name="cta_label"
                         placeholder="Shop the offer">
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label small" for="b_start">Runs from</label>
                    <input class="form-control form-control-sm" id="b_start" name="start_date" type="date">
                  </div>
                  <div class="col-6">
                    <label class="form-label small" for="b_end">Until</label>
                    <input class="form-control form-control-sm" id="b_end" name="end_date" type="date">
                  </div>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="b_image">
                    Picture <span class="text-danger">*</span>
                  </label>
                  <input class="form-control form-control-sm" id="b_image" name="image"
                         type="file" accept="image/jpeg,image/png,image/webp" required>
                  <div class="form-text small">
                    Wide — roughly 1600×500 works well. Required: an advert without a
                    picture has nothing to show.
                  </div>
                </div>

                <div class="mb-2">
                  <label class="form-label small" for="b_alt">Description for screen readers</label>
                  <input class="form-control form-control-sm" id="b_alt" name="alt_text"
                         placeholder="Diwali gift hampers on a festive table">
                </div>

                <button class="btn btn-sm btn-dark" type="submit">Add advert</button>
              </form>
            </div>
          </div>
        </div>
      </div>`;

    const form = container.querySelector('[data-banner-form]');
    const linkType = form.querySelector('#b_link_type');
    const linkSelect = form.querySelector('#b_link_value');
    const linkUrl = form.querySelector('#b_link_url');

    // Fetched once and reused, so changing the link type does not re-query.
    const choices = { category: null, product: null, offer: null, collection: null };

    async function loadChoices(type) {
      if (choices[type]) return choices[type];

      try {
        if (type === 'category') {
          const response = await api.get('/admin/categories');
          const flatten = (list, depth = 0) => list.flatMap((item) => [
            { value: item.slug, label: `${'— '.repeat(depth)}${item.name}` },
            ...flatten(item.children || [], depth + 1),
          ]);
          choices.category = flatten(response.data.categories || response.data || []);
        } else if (type === 'product') {
          const response = await api.get('/admin/products', { per_page: 200 });
          choices.product = (response.data || []).map((item) => ({
            value: item.slug,
            label: item.status === 'published' ? item.name : `${item.name} (not on sale)`,
          }));
        } else if (type === 'collection') {
          const response = await api.get('/admin/collections');
          choices.collection = (response.data.collections || []).map((item) => ({
            value: item.slug,
            label: item.is_live ? item.title : `${item.title} (not live)`,
          }));
        } else {
          const response = await api.get('/admin/offers', { per_page: 100 });
          choices.offer = (response.data || []).map((item) => ({
            value: item.code,
            label: `${item.code} — ${item.title}`,
          }));
        }
      } catch {
        choices[type] = [];
      }

      return choices[type] || [];
    }

    const syncLink = async () => {
      const type = linkType.value;
      const field = form.querySelector('[data-link-value-field]');
      const hint = form.querySelector('[data-link-hint]');

      field.classList.toggle('d-none', type === 'none');

      if (type === 'none') return;

      // A web address is typed; everything else is chosen.
      const isUrl = type === 'url';
      linkSelect.classList.toggle('d-none', isUrl);
      linkUrl.classList.toggle('d-none', !isUrl);
      linkSelect.disabled = isUrl;

      if (isUrl) {
        hint.textContent = 'A full web address including https://';
        return;
      }

      linkSelect.innerHTML = '<option value="">Loading…</option>';
      const options = await loadChoices(type);

      linkSelect.innerHTML = options.length === 0
        ? '<option value="">Nothing available yet</option>'
        : ['<option value="">Choose…</option>',
           ...options.map((option) =>
             `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`)].join('');

      hint.textContent = {
        collection: 'Opens your campaign page. Build one under "Campaign pages".',
        category: 'Opens this category in the shop.',
        product: 'Opens this product. Unpublished products are allowed — useful for a launch.',
        offer: 'Opens the shop filtered to this offer.',
      }[type] || '';
    };

    linkType.addEventListener('change', syncLink);
    syncLink();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const file = form.querySelector('#b_image').files[0];

      if (!file) {
        toast('Choose a picture for the advert.', 'danger');
        return;
      }

      // FormData, not JSON: the picture goes with the rest of the fields in one
      // request, and the browser sets the multipart boundary itself.
      const body = new FormData();

      new FormData(form).forEach((value, key) => {
        if (key === 'image' || key === 'link_url' || value === '') return;
        body.append(key, value);
      });

      const type = linkType.value;

      if (type === 'url') {
        const url = linkUrl.value.trim();

        if (!/^https?:\/\//i.test(url)) {
          toast('A web address must start with https://', 'danger');
          return;
        }

        body.set('link_value', url);
      } else if (type !== 'none' && !linkSelect.value) {
        toast('Choose what the advert should open.', 'danger');
        return;
      }

      const startDate = form.querySelector('#b_start').value;
      const endDate = form.querySelector('#b_end').value;

      if (startDate) body.set('start_date', `${startDate} 00:00:00`);
      if (endDate) body.set('end_date', `${endDate} 23:59:59`);

      body.append('image', file);

      setBusy(button, true, 'Adding');

      try {
        await api.upload('/admin/banners', body);
        toast('Advert added.');
        renderBanners(container);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    container.querySelectorAll('[data-remove-banner]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!window.confirm('Remove this advert?')) return;
        const uuid = button.closest('[data-banner]').dataset.banner;
        setBusy(button, true, '…');

        try {
          await api.delete(`/admin/banners/${encodeURIComponent(uuid)}`);
          toast('Advert removed.');
          renderBanners(container);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

async function renderPages(container) {
  try {
    const response = await api.get('/content/pages');
    const pages = response.data.pages || [];

    container.innerHTML = `
      <div class="card">
        <div class="card-header bg-white fw-semibold">Pages</div>
        <div class="table-responsive">
          <table class="table table-tight mb-0">
            <thead><tr><th>Title</th><th>Address</th><th>Updated</th></tr></thead>
            <tbody>
              ${pages.map((page) => `
                <tr>
                  <td class="fw-semibold">${escapeHtml(page.title)}</td>
                  <td class="small text-muted">${escapeHtml(page.slug)}</td>
                  <td class="small">${escapeHtml(String(page.published_date || '').slice(0, 10))}</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-muted small mt-2 mb-0">
        Shipping, returns, privacy and terms ship with placeholder wording. They are
        a contract with your customer — have them reviewed before you go live.
      </p>`;
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

// ---------------------------------------------------------------------------

function render() {
  root.innerHTML = `
    <h1 class="h4 mb-3">Shopfront</h1>
    ${tabs()}
    <div data-panel><div class="text-center py-5 text-muted"><div class="spinner-border"></div></div></div>`;

  root.querySelectorAll('[data-tab]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      tab = link.dataset.tab;
      render();
    });
  });

  const panel = root.querySelector('[data-panel]');

  if (tab === 'categories') renderCategories(panel);
  else if (tab === 'collections') renderCollections(panel);
  else if (tab === 'banners') renderBanners(panel);
  else renderPages(panel);
}

const mounted = await mountConsole('content.html');
if (mounted) { root = mounted.root; render(); }
