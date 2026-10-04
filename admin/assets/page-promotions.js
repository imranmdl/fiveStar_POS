/** Coupons and offers: what is live, and switching campaigns on and off. */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         badge, emptyState, queryParam } from './console.js?v=9';

let root = null;
let editingOfferUuid = null;

function couponRow(coupon) {
  const used = Number(coupon.total_redeemed || 0);
  const limit = coupon.total_usage_limit;

  return `
    <tr data-coupon="${escapeHtml(coupon.uuid)}">
      <td>
        <span class="font-monospace fw-semibold">${escapeHtml(coupon.code)}</span>
        <div class="small text-muted">${escapeHtml(coupon.title || '')}</div>
      </td>
      <td class="small">
        ${coupon.discount_type === 'percentage'
          ? `${escapeHtml(coupon.discount_value)}% off`
          : `${formatMoney(coupon.discount_value)} off`}
      </td>
      <td class="text-center small">
        ${escapeHtml(used)}${limit ? ` / ${escapeHtml(limit)}` : ''}
        ${limit && used >= limit ? '<div class="text-danger">Exhausted</div>' : ''}
      </td>
      <td class="small">${escapeHtml(String(coupon.valid_to || '—').slice(0, 10))}</td>
      <td>${badge(coupon.status === 'active' ? 'approved' : 'pending', coupon.status)}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary" data-status="${escapeHtml(coupon.status)}">
          ${coupon.status === 'active' ? 'Pause' : 'Activate'}
        </button>
      </td>
    </tr>`;
}

async function render() {
  root.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Promotions</h1>
      <span>
        <button class="btn btn-sm btn-outline-dark me-2" data-new-offer type="button">Create an offer</button>
        <button class="btn btn-sm btn-dark" data-new-coupon type="button">Create a coupon</button>
      </span>
    </div>

    <div class="card mb-4 d-none" data-coupon-editor>
      <div class="card-header bg-white fw-semibold">New coupon</div>
      <div class="card-body">
        <form class="row g-3" data-coupon-form>
          <div class="col-md-3">
            <label class="form-label small" for="code">Code <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm text-uppercase" id="code" name="code"
                   required minlength="3" maxlength="30" placeholder="DIWALI20">
            <div class="form-text small">What the customer types at checkout.</div>
          </div>

          <div class="col-md-5">
            <label class="form-label small" for="title">Title <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="title" name="title"
                   required minlength="3" placeholder="Diwali festival discount">
          </div>

          <div class="col-md-4">
            <label class="form-label small" for="discount_type">Type <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" id="discount_type" name="discount_type" required>
              <option value="percentage">Percentage off</option>
              <option value="flat">Fixed amount off</option>
              <option value="free_delivery">Free delivery</option>
            </select>
          </div>

          <div class="col-md-3" data-value-field>
            <label class="form-label small" for="discount_value">Value <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="discount_value" name="discount_value"
                   type="number" step="0.01" min="0" placeholder="20">
            <div class="form-text small" data-value-hint>Percent, e.g. 20 for 20% off.</div>
          </div>

          <div class="col-md-3" data-cap-field>
            <label class="form-label small" for="max_discount_amount">Cap the discount at</label>
            <input class="form-control form-control-sm" id="max_discount_amount"
                   name="max_discount_amount" type="number" step="0.01" min="1" placeholder="500">
            <div class="form-text small">
              Strongly advised on a percentage coupon, or a large order gives away a
              large amount.
            </div>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="min_order_value">Minimum order value</label>
            <input class="form-control form-control-sm" id="min_order_value" name="min_order_value"
                   type="number" step="0.01" min="0" placeholder="199">
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="valid_to">Expires on</label>
            <input class="form-control form-control-sm" id="valid_to" name="valid_to" type="date">
            <div class="form-text small">Leave blank to run indefinitely.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="total_usage_limit">Total uses allowed</label>
            <input class="form-control form-control-sm" id="total_usage_limit"
                   name="total_usage_limit" type="number" min="1" placeholder="100">
            <div class="form-text small">Blank means unlimited.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="per_customer_limit">Uses per customer</label>
            <input class="form-control form-control-sm" id="per_customer_limit"
                   name="per_customer_limit" type="number" min="1" value="1">
          </div>

          <div class="col-md-6">
            <label class="form-label small" for="audience">Who can use it</label>
            <select class="form-select form-select-sm" id="audience" name="audience">
              <option value="all">Anyone</option>
              <option value="new_customers">First-time customers only</option>
            </select>
          </div>

          <div class="col-12">
            <button class="btn btn-sm btn-dark" type="submit">Create coupon</button>
            <button class="btn btn-sm btn-outline-secondary" data-cancel-coupon type="button">Cancel</button>
            <span class="small text-muted ms-2">
              Created paused, so nothing goes live by accident. Activate it when ready.
            </span>
          </div>
        </form>
      </div>
    </div>

    <div class="card mb-4 d-none" data-offer-editor>
      <div class="card-header bg-white fw-semibold" data-offer-editor-title>New automatic offer</div>
      <div class="card-body">
        <p class="text-muted small">
          An offer applies on its own, with no code to type. One coupon and one
          offer can both apply to an order — whichever offer is best for the
          customer wins.
        </p>
        <form class="row g-3" data-offer-form>
          <div class="col-md-3">
            <label class="form-label small" for="offer_code">Reference <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm text-uppercase" id="offer_code" name="code"
                   required minlength="3" maxlength="40" placeholder="DIWALIBOGO">
            <div class="form-text small" data-offer-code-hint>Internal. Customers never type it.</div>
          </div>

          <div class="col-md-5">
            <label class="form-label small" for="offer_title">What the customer sees <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="offer_title" name="title"
                   required minlength="3" placeholder="Buy one get one free on spices">
          </div>

          <div class="col-md-4">
            <label class="form-label small" for="offer_discount_type">Benefit <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" id="offer_discount_type" name="discount_type" required>
              <option value="free_items">Buy X get Y free</option>
              <option value="percentage">Percentage off</option>
              <option value="flat">Fixed amount off</option>
              <option value="free_delivery">Free delivery</option>
            </select>
          </div>

          <div class="col-md-2 d-none" data-offer-value>
            <label class="form-label small" for="offer_discount_value">Value</label>
            <input class="form-control form-control-sm" id="offer_discount_value"
                   name="discount_value" type="number" step="0.01" min="0">
          </div>

          <div class="col-md-3 d-none" data-offer-max-discount>
            <label class="form-label small" for="offer_max_discount">Maximum discount <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="offer_max_discount"
                   name="max_discount_amount" type="number" step="0.01" min="1">
            <div class="form-text small">Required for a percentage offer — caps what an uncapped % could take off a large order.</div>
          </div>

          <div class="col-md-2" data-bogo-buy>
            <label class="form-label small" for="buy_quantity">Buy <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="buy_quantity" name="buy_quantity"
                   type="number" min="1" max="100" value="1">
          </div>

          <div class="col-md-2" data-bogo-get>
            <label class="form-label small" for="get_quantity">Get free <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="get_quantity" name="get_quantity"
                   type="number" min="1" max="100" value="1">
          </div>

          <div class="col-md-4" data-bogo-scope>
            <label class="form-label small" for="free_item_scope">Which items are free</label>
            <select class="form-select form-select-sm" id="free_item_scope" name="free_item_scope">
              <option value="cheapest_eligible">The cheapest in the basket</option>
              <option value="same_variant">Same pack the customer bought</option>
            </select>
            <div class="form-text small">
              Cheapest is the usual choice — giving away the dearest item on a
              mixed basket costs far more than intended.
            </div>
          </div>

          <div class="col-md-3" data-bogo-cap>
            <label class="form-label small" for="max_free_items_per_order">Free items per order</label>
            <input class="form-control form-control-sm" id="max_free_items_per_order"
                   name="max_free_items_per_order" type="number" min="1" max="1000" value="4">
            <div class="form-text small">
              Without a limit, a fifty-unit order claims twenty-five free.
            </div>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="offer_min_order">Minimum order value</label>
            <input class="form-control form-control-sm" id="offer_min_order" name="min_order_value"
                   type="number" step="0.01" min="0">
          </div>

          <div class="col-md-3" data-offer-min-quantity>
            <label class="form-label small" for="offer_min_quantity">Minimum quantity</label>
            <input class="form-control form-control-sm" id="offer_min_quantity" name="min_quantity"
                   type="number" step="1" min="1">
            <div class="form-text small">Of eligible items combined, e.g. 3 to require "buy 3 or more".</div>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="offer_usage_limit">Usage limit</label>
            <input class="form-control form-control-sm" id="offer_usage_limit" name="usage_limit"
                   type="number" step="1" min="1">
            <div class="form-text small">Total uses allowed, online + POS combined. Blank = unlimited.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="offer_audience">Who can use it</label>
            <select class="form-select form-select-sm" id="offer_audience" name="audience">
              <option value="all">Anyone</option>
              <option value="new_customers">First-time customers only</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="offer_starts">Starts</label>
            <input class="form-control form-control-sm" id="offer_starts" name="starts_date" type="date">
          </div>

          <div class="col-md-3">
            <label class="form-label small" for="offer_ends">Ends <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="offer_ends" name="ends_date" type="date" required>
            <div class="form-text small">Required to activate — an automatic discount with no end date runs forever.</div>
          </div>

          <div class="col-12">
            <button class="btn btn-sm btn-dark" type="submit" data-offer-submit>Create offer</button>
            <button class="btn btn-sm btn-outline-secondary" data-cancel-offer type="button">Cancel</button>
            <span class="small text-muted ms-2" data-offer-submit-hint>Created as a draft. Activate it when ready.</span>
          </div>
        </form>

        <div class="mt-4 pt-3 border-top d-none" data-offer-scope>
          <h2 class="h6">Applies to</h2>
          <p class="small text-muted mb-2">
            Which items this offer discounts. Saves separately from the form above, and takes
            effect immediately.
          </p>
          <div class="btn-group btn-group-sm mb-3" role="group" data-scope-mode>
            <input type="radio" class="btn-check" name="scope_mode" id="scope_all" value="all" autocomplete="off" checked>
            <label class="btn btn-outline-dark" for="scope_all">All items</label>
            <input type="radio" class="btn-check" name="scope_mode" id="scope_categories" value="categories" autocomplete="off">
            <label class="btn btn-outline-dark" for="scope_categories">Specific categories</label>
            <input type="radio" class="btn-check" name="scope_mode" id="scope_products" value="products" autocomplete="off">
            <label class="btn btn-outline-dark" for="scope_products">Specific products</label>
          </div>

          <div class="d-none" data-scope-categories>
            <div class="border rounded p-2 mb-2" style="max-height:220px;overflow-y:auto" data-scope-category-list>
              <div class="text-muted small">Loading…</div>
            </div>
          </div>

          <div class="d-none" data-scope-products>
            <input class="form-control form-control-sm mb-2" type="search"
                   placeholder="Filter products by name…" data-scope-product-filter>
            <div class="border rounded p-2 mb-2" style="max-height:220px;overflow-y:auto" data-scope-product-list>
              <div class="text-muted small">Loading…</div>
            </div>
          </div>

          <button type="button" class="btn btn-sm btn-dark" data-save-scope>Save scope</button>
          <span class="small text-muted ms-2" data-scope-hint></span>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header bg-white fw-semibold">Coupons</div>
      <div class="card-body p-0" data-coupons>
        <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white fw-semibold">Automatic offers</div>
      <div class="card-body p-0" data-offers>
        <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
      </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
      One coupon per order, plus one automatic offer — whichever is best for the
      customer. Wallet credit applies on top of both.
    </p>`;

  const offerEditor = root.querySelector('[data-offer-editor]');
  const offerForm = root.querySelector('[data-offer-form]');
  const offerCodeInput = offerForm.querySelector('#offer_code');

  // Buy-X-get-Y needs quantities; the others need a value. Showing both at once
  // invites an offer that reads one way and behaves another — which the database
  // CHECK would reject anyway, but with a worse message.
  const offerType = offerForm.querySelector('#offer_discount_type');

  const syncOfferFields = () => {
    const isBogo = offerType.value === 'free_items';
    const needsValue = offerType.value === 'percentage' || offerType.value === 'flat';
    // updateOffer() doesn't accept these — they can only be set at creation,
    // so editing an existing offer shows them (staff should see what's
    // configured) but disabled, rather than silently ignoring a change.
    const bogoLocked = Boolean(editingOfferUuid) && isBogo;

    ['[data-bogo-buy]', '[data-bogo-get]', '[data-bogo-scope]', '[data-bogo-cap]']
      .forEach((selector) => offerForm.querySelector(selector).classList.toggle('d-none', !isBogo));

    offerForm.querySelector('[data-offer-value]').classList.toggle('d-none', !needsValue);
    offerForm.querySelector('#offer_discount_value').required = needsValue;
    // Meaningless for BOGO — buy_quantity already is a minimum quantity for that type.
    offerForm.querySelector('[data-offer-min-quantity]').classList.toggle('d-none', isBogo);
    offerForm.querySelector('#buy_quantity').required = isBogo && !bogoLocked;
    offerForm.querySelector('#get_quantity').required = isBogo && !bogoLocked;
    ['#buy_quantity', '#get_quantity', '#free_item_scope', '#max_free_items_per_order']
      .forEach((sel) => { offerForm.querySelector(sel).disabled = bogoLocked; });

    // Required to activate a percentage offer (OfferService::setStatus()) —
    // shown only where it applies, same as the value field above.
    const isPercentage = offerType.value === 'percentage';
    offerForm.querySelector('[data-offer-max-discount]').classList.toggle('d-none', !isPercentage);
    offerForm.querySelector('#offer_max_discount').required = isPercentage;
  };

  offerType.addEventListener('change', syncOfferFields);

  /** Opens the editor blank (new offer) or pre-filled (correcting an existing one). */
  const openOfferEditor = (offer) => {
    editingOfferUuid = offer ? offer.uuid : null;
    offerForm.reset();
    offerEditor.classList.remove('d-none');

    root.querySelector('[data-offer-editor-title]').textContent = offer ? `Edit offer: ${offer.title}` : 'New automatic offer';
    offerForm.querySelector('[data-offer-submit]').textContent = offer ? 'Save changes' : 'Create offer';
    offerForm.querySelector('[data-offer-submit-hint]').textContent = offer ? '' : 'Created as a draft. Activate it when ready.';

    offerCodeInput.disabled = Boolean(offer);
    root.querySelector('[data-offer-code-hint]').textContent = offer
      ? "Can't be changed after an offer is created."
      : 'Internal. Customers never type it.';

    if (offer) {
      // offer comes straight from OfferService::present() — discount_type/value,
      // min_order_value etc. live nested under offer.discount, and the dates
      // under offer.schedule, not as flat top-level properties. Reading them
      // flat here silently left every one of these blank on Edit (only
      // title/code/buy_quantity/get_quantity/etc. happened to be top-level and
      // actually worked) — fixed alongside adding min_quantity/usage_limit.
      const discount = offer.discount || {};
      const schedule = offer.schedule || {};
      const usage = offer.usage || {};

      offerCodeInput.value = offer.code || '';
      offerForm.querySelector('#offer_title').value = offer.title || '';
      offerType.value = discount.type || 'percentage';
      offerForm.querySelector('#offer_discount_value').value = discount.value ?? '';
      offerForm.querySelector('#offer_max_discount').value = discount.max_amount ?? '';
      offerForm.querySelector('#buy_quantity').value = offer.buy_quantity ?? 1;
      offerForm.querySelector('#get_quantity').value = offer.get_quantity ?? 1;
      offerForm.querySelector('#free_item_scope').value = offer.free_item_scope || 'cheapest_eligible';
      offerForm.querySelector('#max_free_items_per_order').value = offer.max_free_items_per_order ?? 4;
      offerForm.querySelector('#offer_min_order').value = discount.min_order_value ?? '';
      offerForm.querySelector('#offer_min_quantity').value = discount.min_quantity ?? '';
      offerForm.querySelector('#offer_usage_limit').value = usage.limit ?? '';
      offerForm.querySelector('#offer_audience').value = offer.audience || 'all';
      offerForm.querySelector('#offer_starts').value = String(schedule.starts_date || '').slice(0, 10);
      offerForm.querySelector('#offer_ends').value = String(schedule.ends_date || '').slice(0, 10);
    }

    syncOfferFields();
    openOfferScope(offer);
    if (!offer) offerCodeInput.focus();
  };

  // --- Applies-to scope: items/category targeting for an existing offer ---
  // Only meaningful once an offer exists (setTargets() operates on a saved
  // offer), so this whole section stays hidden while creating a new one.
  const scopeSection = root.querySelector('[data-offer-scope]');
  const scopeModeInputs = Array.from(root.querySelectorAll('input[name="scope_mode"]'));
  const scopeCategoriesBox = root.querySelector('[data-scope-categories]');
  const scopeProductsBox = root.querySelector('[data-scope-products]');
  const scopeCategoryList = root.querySelector('[data-scope-category-list]');
  const scopeProductList = root.querySelector('[data-scope-product-list]');
  const scopeProductFilter = root.querySelector('[data-scope-product-filter]');
  const saveScopeButton = root.querySelector('[data-save-scope]');
  const scopeHint = root.querySelector('[data-scope-hint]');

  let categoryChoicesCache = null;
  let productChoicesCache = null;
  const selectedCategorySlugs = new Set();
  const selectedProductSlugs = new Set();
  const knownProductNames = new Map(); // slug -> name, so a checked item stays labelled even once filtered out of view

  async function loadCategoryChoices() {
    if (categoryChoicesCache) return categoryChoicesCache;

    const flatten = (list, depth = 0) => list.flatMap((item) => [
      { slug: item.slug, name: item.name, depth },
      ...flatten(item.children || [], depth + 1),
    ]);

    try {
      const response = await api.get('/admin/categories');
      categoryChoicesCache = flatten(response.data.categories || response.data || []);
    } catch {
      categoryChoicesCache = [];
    }

    return categoryChoicesCache;
  }

  // /admin/products doesn't actually filter on a search param server-side,
  // so the whole catalogue is fetched once and filtered here instead —
  // the same workaround page-content.js's banner-link picker already uses.
  async function loadProductChoices() {
    if (productChoicesCache) return productChoicesCache;

    try {
      const response = await api.get('/admin/products', { per_page: 200 });
      productChoicesCache = response.data || [];
    } catch {
      productChoicesCache = [];
    }

    productChoicesCache.forEach((p) => knownProductNames.set(p.slug, p.name));

    return productChoicesCache;
  }

  function renderCategoryList() {
    loadCategoryChoices().then((choices) => {
      scopeCategoryList.innerHTML = choices.length === 0
        ? '<div class="text-muted small">No categories yet.</div>'
        : choices.map((c) => `
            <div class="form-check">
              <input class="form-check-input" type="checkbox" value="${escapeHtml(c.slug)}"
                     id="scope-cat-${escapeHtml(c.slug)}" data-scope-category-checkbox
                     ${selectedCategorySlugs.has(c.slug) ? 'checked' : ''}>
              <label class="form-check-label small" for="scope-cat-${escapeHtml(c.slug)}">
                ${'— '.repeat(c.depth)}${escapeHtml(c.name)}
              </label>
            </div>`).join('');

      scopeCategoryList.querySelectorAll('[data-scope-category-checkbox]').forEach((box) => {
        box.addEventListener('change', () => {
          if (box.checked) selectedCategorySlugs.add(box.value);
          else selectedCategorySlugs.delete(box.value);
        });
      });
    });
  }

  async function renderProductList(query) {
    scopeProductList.innerHTML = '<div class="text-muted small">Loading…</div>';

    const all = await loadProductChoices();
    const needle = (query || '').trim().toLowerCase();
    const filtered = needle === ''
      ? all
      : all.filter((p) => p.name.toLowerCase().includes(needle));

    // A checked product stays visible even once the typed filter no longer
    // matches it — unchecking it should be a deliberate click, not an
    // accident of what the current search text happens to match.
    const checkedElsewhere = Array.from(selectedProductSlugs)
      .filter((slug) => !filtered.some((p) => p.slug === slug))
      .map((slug) => ({ slug, name: knownProductNames.get(slug) || slug }));

    const rows = [...filtered, ...checkedElsewhere];

    scopeProductList.innerHTML = rows.length === 0
      ? '<div class="text-muted small">No products match.</div>'
      : rows.map((p) => `
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="${escapeHtml(p.slug)}"
                   id="scope-prod-${escapeHtml(p.slug)}" data-scope-product-checkbox
                   ${selectedProductSlugs.has(p.slug) ? 'checked' : ''}>
            <label class="form-check-label small" for="scope-prod-${escapeHtml(p.slug)}">
              ${escapeHtml(p.name)}
            </label>
          </div>`).join('');

    scopeProductList.querySelectorAll('[data-scope-product-checkbox]').forEach((box) => {
      box.addEventListener('change', () => {
        if (box.checked) selectedProductSlugs.add(box.value);
        else selectedProductSlugs.delete(box.value);
      });
    });
  }

  function syncScopeMode() {
    const mode = scopeModeInputs.find((i) => i.checked)?.value || 'all';
    scopeCategoriesBox.classList.toggle('d-none', mode !== 'categories');
    scopeProductsBox.classList.toggle('d-none', mode !== 'products');

    if (mode === 'categories' && scopeCategoryList.dataset.loaded !== '1') {
      scopeCategoryList.dataset.loaded = '1';
      renderCategoryList();
    }

    if (mode === 'products' && scopeProductList.dataset.loaded !== '1') {
      scopeProductList.dataset.loaded = '1';
      renderProductList('');
    }
  }

  scopeModeInputs.forEach((input) => input.addEventListener('change', syncScopeMode));

  scopeProductFilter.addEventListener('input', () => {
    renderProductList(scopeProductFilter.value.trim());
  });

  /** Shows/hides and pre-fills the scope section for the offer being edited. */
  async function openOfferScope(offer) {
    selectedCategorySlugs.clear();
    selectedProductSlugs.clear();
    scopeProductFilter.value = '';
    scopeCategoryList.dataset.loaded = '';
    scopeProductList.dataset.loaded = '';
    scopeHint.textContent = '';

    if (!offer) {
      scopeSection.classList.add('d-none');
      return;
    }

    scopeSection.classList.remove('d-none');
    scopeModeInputs.forEach((input) => { input.checked = input.value === 'all'; });
    scopeCategoriesBox.classList.add('d-none');
    scopeProductsBox.classList.add('d-none');

    try {
      const response = await api.get(`/admin/offers/${encodeURIComponent(offer.uuid)}/targets`);
      const targets = response.data;

      targets.categories.forEach((c) => selectedCategorySlugs.add(c.slug));
      targets.products.forEach((p) => {
        selectedProductSlugs.add(p.slug);
        knownProductNames.set(p.slug, p.name);
      });

      const mode = targets.applies_to === 'categories' || targets.applies_to === 'products'
        ? targets.applies_to
        : 'all';
      scopeModeInputs.forEach((input) => { input.checked = input.value === mode; });
      syncScopeMode();
    } catch (error) {
      showError(error, scopeHint);
    }
  }

  saveScopeButton.addEventListener('click', async () => {
    if (!editingOfferUuid) return;

    const mode = scopeModeInputs.find((i) => i.checked)?.value || 'all';

    if (mode === 'categories' && selectedCategorySlugs.size === 0) {
      toast('Pick at least one category, or switch to "All items".', 'danger');
      return;
    }

    if (mode === 'products' && selectedProductSlugs.size === 0) {
      toast('Pick at least one product, or switch to "All items".', 'danger');
      return;
    }

    const payload = {
      category_slugs: mode === 'categories' ? Array.from(selectedCategorySlugs) : [],
      product_slugs: mode === 'products' ? Array.from(selectedProductSlugs) : [],
    };

    setBusy(saveScopeButton, true, 'Saving');

    try {
      // Not a full render(): that would collapse the editor staff is looking
      // at. The offer list's "Scoped to…" hint catches up on the next visit.
      await api.put(`/admin/offers/${encodeURIComponent(editingOfferUuid)}/targets`, payload);
      setBusy(saveScopeButton, false);
      toast('Offer scope updated.');
      scopeHint.textContent = 'Saved.';
    } catch (error) {
      setBusy(saveScopeButton, false);
      showError(error, scopeHint);
    }
  });

  root.querySelector('[data-new-offer]').addEventListener('click', () => {
    if (!offerEditor.classList.contains('d-none') && !editingOfferUuid) {
      offerEditor.classList.add('d-none');
      return;
    }
    openOfferEditor(null);
  });

  // Deep link from the dashboard's Recommended Offers card
  // (page-index.js's recommendedOffersPanel) — opens straight to a blank
  // offer with the suggested title/discount pre-filled. Still just a draft:
  // the admin still reviews it, sets the scope and Activates it themselves.
  if (queryParam('new_offer') === '1') {
    openOfferEditor(null);
    if (queryParam('title')) offerForm.querySelector('#offer_title').value = queryParam('title');
    if (queryParam('discount_value')) {
      offerType.value = 'percentage';
      syncOfferFields();
      offerForm.querySelector('#offer_discount_value').value = queryParam('discount_value');
      offerForm.querySelector('#offer_max_discount').value = '500';
    }
    offerEditor.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  root.querySelector('[data-cancel-offer]').addEventListener('click', () => {
    offerEditor.classList.add('d-none');
    offerForm.reset();
    editingOfferUuid = null;
    scopeSection.classList.add('d-none');
  });

  offerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = offerForm.querySelector('button[type="submit"]');
    const payload = { offer_type: offerType.value === 'free_items' ? 'bogo' : 'festival' };

    new FormData(offerForm).forEach((value, key) => {
      if (value !== '') payload[key] = value;
    });

    payload.code = String(payload.code || offerCodeInput.value || '').toUpperCase();

    // Dates come from a date input; the API expects a datetime.
    if (payload.starts_date) payload.starts_date = `${payload.starts_date} 00:00:00`;
    if (payload.ends_date) payload.ends_date = `${payload.ends_date} 23:59:59`;

    if (payload.discount_type !== 'free_items') {
      delete payload.buy_quantity;
      delete payload.get_quantity;
      delete payload.free_item_scope;
      delete payload.max_free_items_per_order;
    }

    const editing = editingOfferUuid;

    if (editing) {
      // The update endpoint doesn't accept these — sending them is harmless
      // (silently ignored server-side) but omitting them here is clearer
      // about what this save actually changes.
      delete payload.code;
      delete payload.buy_quantity;
      delete payload.get_quantity;
      delete payload.free_item_scope;
      delete payload.max_free_items_per_order;
    }

    setBusy(button, true, editing ? 'Saving' : 'Creating');

    try {
      if (editing) {
        await api.patch(`/admin/offers/${encodeURIComponent(editing)}`, payload);
        toast('Offer updated.');
      } else {
        await api.post('/admin/offers', payload);
        toast(`Offer ${payload.code} created. Activate it when you are ready.`);
      }

      offerEditor.classList.add('d-none');
      offerForm.reset();
      editingOfferUuid = null;
      render();
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });

  const editor = root.querySelector('[data-coupon-editor]');
  const couponForm = root.querySelector('[data-coupon-form]');

  root.querySelector('[data-new-coupon]').addEventListener('click', () => {
    editor.classList.toggle('d-none');
    if (!editor.classList.contains('d-none')) couponForm.querySelector('#code').focus();
  });

  root.querySelector('[data-cancel-coupon]').addEventListener('click', () => {
    editor.classList.add('d-none');
    couponForm.reset();
  });

  // Free delivery has no amount, and a flat discount cannot be capped.
  const typeSelect = couponForm.querySelector('#discount_type');

  const syncTypeFields = () => {
    const type = typeSelect.value;
    couponForm.querySelector('[data-value-field]').classList.toggle('d-none', type === 'free_delivery');
    couponForm.querySelector('[data-cap-field]').classList.toggle('d-none', type !== 'percentage');
    couponForm.querySelector('[data-value-hint]').textContent = type === 'percentage'
      ? 'Percent, e.g. 20 for 20% off.'
      : 'Amount in rupees off the order.';
    couponForm.querySelector('#discount_value').required = type !== 'free_delivery';
  };

  typeSelect.addEventListener('change', syncTypeFields);
  syncTypeFields();

  couponForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = couponForm.querySelector('button[type="submit"]');
    const payload = {};

    new FormData(couponForm).forEach((value, key) => {
      if (value !== '') payload[key] = value;
    });

    payload.code = String(payload.code || '').toUpperCase();

    if (payload.discount_type === 'free_delivery') {
      payload.discount_value = 0;
    }

    if (payload.discount_type === 'percentage' && Number(payload.discount_value) > 100) {
      toast('A percentage discount cannot be more than 100%.', 'danger');
      return;
    }

    setBusy(button, true, 'Creating');

    try {
      await api.post('/admin/coupons', payload);
      toast(`Coupon ${payload.code} created. Activate it when you are ready.`);
      editor.classList.add('d-none');
      couponForm.reset();
      render();
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });

  const couponsEl = root.querySelector('[data-coupons]');
  const offersEl = root.querySelector('[data-offers]');

  try {
    const response = await api.get('/admin/coupons', { per_page: 50 });
    const coupons = response.data || [];

    couponsEl.innerHTML = coupons.length === 0
      ? emptyState('No coupons', 'Create one through the API.')
      : `<div class="table-responsive">
           <table class="table table-tight mb-0">
             <thead><tr><th>Code</th><th>Discount</th><th class="text-center">Used</th>
               <th>Expires</th><th>Status</th><th></th></tr></thead>
             <tbody>${coupons.map(couponRow).join('')}</tbody>
           </table>
         </div>`;

    couponsEl.querySelectorAll('[data-status]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-coupon]').dataset.coupon;
        const next = button.dataset.status === 'active' ? 'paused' : 'active';

        setBusy(button, true, 'Saving');

        try {
          await api.post(`/admin/coupons/${encodeURIComponent(uuid)}/status`, { status: next });
          toast(next === 'active' ? 'Coupon is live.' : 'Coupon paused.');
          render();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    couponsEl.innerHTML = '';
    showError(error, couponsEl);
  }

  try {
    const response = await api.get('/admin/offers', { per_page: 50 });
    const offers = response.data || [];

    offersEl.innerHTML = offers.length === 0
      ? emptyState('No offers', 'Automatic offers apply without a code.')
      : `<div class="table-responsive">
           <table class="table table-tight mb-0">
             <thead><tr><th>Offer</th><th>Discount</th><th>Runs until</th><th>Status</th><th></th></tr></thead>
             <tbody>
               ${offers.map((offer) => `
                 <tr data-offer="${escapeHtml(offer.uuid)}">
                   <td>
                     <span class="fw-semibold">${escapeHtml(offer.title)}</span>
                     <div class="small text-muted">${escapeHtml(offer.code || '')}</div>
                     <div class="small text-muted">
                       ${offer.applies_to === 'categories' ? 'Scoped: specific categories'
                         : offer.applies_to === 'products' ? 'Scoped: specific products' : ''}
                       ${offer.audience === 'new_customers' ? ' &middot; First-time customers only' : ''}
                     </div>
                   </td>
                   <td class="small">
                     ${(offer.discount && offer.discount.summary)
                       ? escapeHtml(offer.discount.summary)
                       : (offer.discount_type === 'percentage'
                           ? `${escapeHtml(offer.discount_value)}%`
                           : formatMoney(offer.discount_value))}
                   </td>
                   <td class="small">
                     ${escapeHtml(String((offer.schedule && offer.schedule.ends_date) || '—').slice(0, 10))}
                     ${offer.usage && offer.usage.limit !== null
                       ? `<div class="text-muted">${escapeHtml(offer.usage.used)} / ${escapeHtml(offer.usage.limit)} used</div>`
                       : ''}
                   </td>
                   <td>${badge(offer.status === 'active' ? 'approved' : 'pending', offer.status)}</td>
                   <td class="text-end text-nowrap">
                     <button class="btn btn-sm btn-outline-secondary" data-edit-offer type="button">Edit</button>
                     <button class="btn btn-sm btn-outline-secondary" data-offer-status="${escapeHtml(offer.status)}" type="button">
                       ${offer.status === 'active' ? 'Pause' : 'Activate'}
                     </button>
                   </td>
                 </tr>
                 <tr data-offer-error-row="${escapeHtml(offer.uuid)}" hidden>
                   <td colspan="5" class="pt-0" data-offer-error></td>
                 </tr>`).join('')}
             </tbody>
           </table>
         </div>`;

    offersEl.querySelectorAll('[data-edit-offer]').forEach((button) => {
      button.addEventListener('click', () => {
        const uuid = button.closest('[data-offer]').dataset.offer;
        const offer = offers.find((o) => o.uuid === uuid);
        if (offer) openOfferEditor(offer);
      });
    });

    offersEl.querySelectorAll('[data-offer-status]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-offer]').dataset.offer;
        const next = button.dataset.offerStatus === 'active' ? 'paused' : 'active';
        setBusy(button, true, 'Saving');

        try {
          await api.post(`/admin/offers/${encodeURIComponent(uuid)}/status`, { status: next });
          toast(next === 'active' ? 'Offer activated.' : 'Offer paused.');
          render();
        } catch (error) {
          setBusy(button, false);
          // The API's own reasons (e.g. "Set an end date.", "Set a maximum
          // discount.") are the actual explanation — shown here instead of
          // dropped in favour of the generic "not ready to activate." toast.
          const errorRow = offersEl.querySelector(`[data-offer-error-row="${uuid}"]`);
          errorRow.hidden = false;
          showError(error, errorRow.querySelector('[data-offer-error]'));
        }
      });
    });
  } catch (error) {
    offersEl.innerHTML = '';
    showError(error, offersEl);
  }
}

const mounted = await mountConsole('promotions.html');
if (mounted) { root = mounted.root; render(); }
