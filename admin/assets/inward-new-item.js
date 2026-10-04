/**
 * "New item" form for Purchase Inward. The shape of the form follows the
 * business type of the chosen category (set once on the top-level category
 * and inherited by everything under it):
 *
 *   grocery / oils   packed (fixed pack) or loose (weighed/measured at the
 *                    counter, priced per kg / litre / piece)
 *   clothing /       one item, then a size x colour grid -> one variant each
 *   footwear
 *   toys / stationery / general   plain item with an optional variant label
 *
 * Category -> Sub-category -> Item: two cascading pickers, with inline
 * "add a category / sub-category" so staff never leave the screen.
 */

const PROFILES = {
  grocery:    { label: 'Grocery',    mode: 'weighed', units: ['kg', 'g', 'piece', 'dozen'], gst: 5 },
  oils:       { label: 'Oils',       mode: 'weighed', units: ['litre', 'ml', 'kg'], gst: 5 },
  clothing:   { label: 'Clothing',   mode: 'grid', gst: 12 },
  footwear:   { label: 'Footwear',   mode: 'grid', gst: 12 },
  toys:       { label: 'Toys',       mode: 'plain', gst: 12 },
  stationery: { label: 'Stationery', mode: 'plain', gst: 12 },
  general:    { label: 'General',    mode: 'plain', gst: 18 },
};

/** Grams that one stock unit represents — weight_grams is NOT NULL and > 0 on every variant. */
const UNIT_GRAMS = { kg: 1000, g: 1, litre: 1000, ml: 1, piece: 1, dozen: 1 };
const UNIT_NAMES = { kg: 'kg', g: 'gram', litre: 'litre', ml: 'ml', piece: 'piece', dozen: 'dozen' };

let uid = 0;

export function renderNewItemForm(host, ctx) {
  const { api, setup, addLine, escapeHtml, setBusy, showError, toast } = ctx;
  const id = `ni${++uid}`;
  const st = { topUuid: '', subUuid: '', mode: 'packed', unit: 'kg', detailKey: '' };

  const cats = () => setup.categories || [];
  const tops = () => cats().filter((c) => !c.parent_uuid);
  const kids = (uuid) => cats().filter((c) => c.parent_uuid === uuid);
  const valuesOf = (code) => ((setup.option_types || []).find((t) => t.code === code)?.values) || [];

  host.innerHTML = `
    <div class="card border-info mt-2 mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h3 class="h6 mb-0">New item</h3>
          <span class="badge text-bg-light border" data-type-badge>Pick a category</span>
        </div>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small mb-0">Category</label>
            <div class="input-group input-group-sm">
              <select class="form-select" data-top></select>
              <button class="btn btn-outline-secondary" type="button" data-add-top title="Add a new category">+ New</button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">Sub-category</label>
            <div class="input-group input-group-sm">
              <select class="form-select" data-sub></select>
              <button class="btn btn-outline-secondary" type="button" data-add-sub title="Add a new sub-category">+ New</button>
            </div>
          </div>
        </div>
        <div data-cat-add class="mt-2"></div>

        <hr class="my-3">
        <div data-detail><div class="small text-muted">Choose a category to see the right fields for that kind of item.</div></div>

        <div data-new-item-feedback class="small mt-2">&nbsp;</div>
        <div class="d-flex gap-2 mt-2">
          <button class="btn btn-sm btn-info" type="button" data-create-new-item disabled>Create and add</button>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-cancel-new-item>Cancel</button>
        </div>
      </div>
    </div>`;

  const $ = (sel) => host.querySelector(sel);
  const topSel = $('[data-top]');
  const subSel = $('[data-sub]');
  const feedback = $('[data-new-item-feedback]');
  const createBtn = $('[data-create-new-item]');

  const say = (text, cls = 'text-muted') => { feedback.textContent = text; feedback.className = `small mt-2 ${cls}`; };

  const leafUuid = () => {
    if (!st.topUuid) return '';
    if (!kids(st.topUuid).length) return st.topUuid;
    return st.subUuid;
  };

  const currentType = () => {
    const top = tops().find((c) => c.uuid === st.topUuid);
    return top ? (top.item_type || 'general') : null;
  };

  function fillTop() {
    topSel.innerHTML = `<option value="">Select category…</option>${tops().map((c) => `
      <option value="${escapeHtml(c.uuid)}" ${c.uuid === st.topUuid ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}`;
  }

  function fillSub() {
    const subs = st.topUuid ? kids(st.topUuid) : [];
    subSel.disabled = !st.topUuid;
    $('[data-add-sub]').disabled = !st.topUuid;

    if (!st.topUuid) {
      subSel.innerHTML = '<option value="">Select a category first</option>';
    } else if (!subs.length) {
      subSel.innerHTML = '<option value="">No sub-categories — item goes directly in this category</option>';
      subSel.disabled = true;
    } else {
      subSel.innerHTML = `<option value="">Select sub-category…</option>${subs.map((c) => `
        <option value="${escapeHtml(c.uuid)}" ${c.uuid === st.subUuid ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}`;
    }
  }

  function refreshBadge() {
    const type = currentType();
    const badge = $('[data-type-badge]');

    if (!type) {
      badge.textContent = 'Pick a category';
      createBtn.disabled = true;
      return;
    }

    const profile = PROFILES[type] || PROFILES.general;
    badge.textContent = `${profile.label} item`;
    createBtn.disabled = false;
  }

  // -------------------------------------------------------------------------
  // Detail area — rebuilt only when the business type or grocery mode changes.
  // -------------------------------------------------------------------------

  function field(label, control, col = 'col-md-3') {
    return `<div class="${col}"><label class="form-label small mb-0">${label}</label>${control}</div>`;
  }

  const inp = (name, attrs = '') => `<input class="form-control form-control-sm" data-f="${name}" ${attrs}>`;

  function commonTail(profile, { showGst = true } = {}) {
    return `
      ${field('Brand <span class="text-muted">(optional)</span>', inp('brand', 'maxlength="120"'), 'col-md-4')}
      ${showGst ? field('GST %', inp('gst_rate', `type="number" step="0.01" min="0" max="28" value="${profile.gst}"`), 'col-md-2') : ''}
      ${field('HSN code <span class="text-muted">(optional)</span>', inp('hsn_code', 'maxlength="15"'), 'col-md-3')}
      ${field('Cost price (each)', inp('unit_cost', 'type="number" step="0.01" min="0" placeholder="Optional"'), 'col-md-3')}`;
  }

  function chipGroup(name, values, hint) {
    return `
      <div class="col-12">
        <label class="form-label small mb-1">${hint}</label>
        <div class="d-flex flex-wrap gap-1" data-chips="${name}">
          ${values.map((v) => chip(name, v, false)).join('')}
        </div>
        <div class="input-group input-group-sm mt-1" style="max-width:18rem">
          <input class="form-control" data-chip-input="${name}" placeholder="Other ${escapeHtml(name)}…" maxlength="60">
          <button class="btn btn-outline-secondary" type="button" data-chip-add="${name}">Add</button>
        </div>
      </div>`;
  }

  function chip(name, value, checked) {
    const cid = `${id}-${name}-${++uid}`;
    return `<span><input type="checkbox" class="btn-check" id="${cid}" data-chip-box="${name}" value="${escapeHtml(value)}" ${checked ? 'checked' : ''}>
      <label class="btn btn-sm btn-outline-primary py-0" for="${cid}">${escapeHtml(value)}</label></span>`;
  }

  function buildDetail() {
    const type = currentType();
    const detail = $('[data-detail]');

    if (!type) return;

    const profile = PROFILES[type] || PROFILES.general;
    const key = `${type}|${st.mode}|${st.unit}`;
    if (key === st.detailKey) return;
    st.detailKey = key;

    const kept = {};
    detail.querySelectorAll('[data-f]').forEach((el) => { kept[el.dataset.f] = el.value; });

    let body = '';

    if (profile.mode === 'weighed') {
      const loose = st.mode === 'loose';
      const isOil = type === 'oils';
      const unitOpts = profile.units.map((u) => `<option value="${u}" ${u === st.unit ? 'selected' : ''}>${UNIT_NAMES[u]}</option>`).join('');

      body += `
        <div class="col-12">
          <div class="btn-group btn-group-sm" role="group" aria-label="How is it sold">
            <input type="radio" class="btn-check" name="${id}-mode" id="${id}-m1" value="packed" ${!loose ? 'checked' : ''}>
            <label class="btn btn-outline-primary" for="${id}-m1">Packed — fixed pack sizes</label>
            <input type="radio" class="btn-check" name="${id}-mode" id="${id}-m2" value="loose" ${loose ? 'checked' : ''}>
            <label class="btn btn-outline-primary" for="${id}-m2">Loose — weighed / measured for the customer</label>
          </div>
        </div>
        ${field('Item name', inp('product_name', 'required maxlength="180"'), 'col-md-6')}`;

      if (loose) {
        body += `
          ${field('Sold by', `<select class="form-select form-select-sm" data-f="unit" data-unit-select>${unitOpts}</select>`, 'col-md-3')}
          ${field('Barcode', inp('barcode', 'placeholder="Auto"'), 'col-md-3')}
          ${field(`MRP per ${UNIT_NAMES[st.unit]}`, inp('mrp', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
          ${field(`Selling price per ${UNIT_NAMES[st.unit]}`, inp('selling_price', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
          <div class="col-md-6"><div class="small text-muted">
            Stock is counted in ${UNIT_NAMES[st.unit]}s. Enter the quantity received below in the same unit;
            the counter can then sell any amount (e.g. 0.350 ${UNIT_NAMES[st.unit]}) at this price.
          </div></div>`;
      } else {
        body += `
          ${field(isOil ? 'Pack size (e.g. "1 L pouch")' : 'Pack size (e.g. "500 g pouch")', inp('variant_name', 'required maxlength="80"'), 'col-md-6')}
          ${field(isOil ? 'Weight (g) — approx.' : 'Weight (g)', inp('weight_grams', 'type="number" min="1" max="100000" required'), 'col-md-3')}
          ${field('Pack type', `<select class="form-select form-select-sm" data-f="pack_type">
              <option value="pouch">Pouch</option><option value="jar">Jar</option><option value="box">Box</option>
              <option value="tin">Tin</option><option value="gift_box">Gift box</option><option value="refill">Refill</option>
              <option value="other">Bottle / other</option></select>`, 'col-md-3')}
          ${field('Barcode', inp('barcode', 'placeholder="Auto"'), 'col-md-3')}
          ${field('MRP', inp('mrp', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
          ${field('Selling price', inp('selling_price', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}`;
      }

      body += commonTail(profile);
    } else if (profile.mode === 'grid') {
      const sizes = valuesOf('size').filter((v) => (type === 'footwear' ? /^\d+$/.test(v) : !/^\d+$/.test(v)));
      const colours = valuesOf('color');

      body += `
        ${field('Item name', inp('product_name', 'required maxlength="180" placeholder="e.g. Cotton round-neck T-shirt"'), 'col-md-6')}
        ${field('MRP (same for all)', inp('mrp', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
        ${field('Selling price (same for all)', inp('selling_price', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
        ${chipGroup('size', sizes, `Sizes received <span class="text-muted">(tick every size)</span>`)}
        ${chipGroup('color', colours, `Colours <span class="text-muted">(optional — one variant per size × colour)</span>`)}
        <div class="col-12"><div class="small text-muted" data-grid-count></div></div>
        ${commonTail(profile)}`;
    } else {
      body += `
        ${field('Item name', inp('product_name', 'required maxlength="180"'), 'col-md-6')}
        ${field('Variant / pack <span class="text-muted">(optional, e.g. "Pack of 10")</span>', inp('variant_name', 'maxlength="80"'), 'col-md-6')}
        ${field('Barcode', inp('barcode', 'placeholder="Auto"'), 'col-md-3')}
        ${field('MRP', inp('mrp', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
        ${field('Selling price', inp('selling_price', 'type="number" step="0.01" min="0.01"'), 'col-md-3')}
        ${field('Weight (g) <span class="text-muted">(optional)</span>', inp('weight_grams', 'type="number" min="1" max="100000"'), 'col-md-3')}
        ${commonTail(profile)}`;
    }

    detail.innerHTML = `<div class="row g-2">${body}</div>`;

    Object.entries(kept).forEach(([name, value]) => {
      const el = detail.querySelector(`[data-f="${name}"]`);
      if (el && name !== 'unit' && value !== '') el.value = value;
    });

    detail.querySelectorAll(`input[name="${id}-mode"]`).forEach((radio) => radio.addEventListener('change', () => {
      st.mode = radio.value;
      st.detailKey = '';
      buildDetail();
    }));

    detail.querySelector('[data-unit-select]')?.addEventListener('change', (event) => {
      st.unit = event.target.value;
      st.detailKey = '';
      buildDetail();
    });

    detail.querySelectorAll('[data-chip-add]').forEach((btn) => btn.addEventListener('click', () => {
      const name = btn.dataset.chipAdd;
      const input = detail.querySelector(`[data-chip-input="${name}"]`);
      const value = input.value.trim();
      if (!value) return;

      const group = detail.querySelector(`[data-chips="${name}"]`);
      const existing = [...group.querySelectorAll('input')].find((b) => b.value.toLowerCase() === value.toLowerCase());
      if (existing) {
        existing.checked = true;
      } else {
        group.insertAdjacentHTML('beforeend', chip(name, value, true));
      }
      input.value = '';
      updateGridCount();
    }));

    updateGridCount();
  }

  host.addEventListener('change', (event) => {
    if (event.target.matches('[data-chip-box]')) updateGridCount();
  });

  const ticked = (name) => [...host.querySelectorAll(`[data-chip-box="${name}"]:checked`)].map((b) => b.value);

  function updateGridCount() {
    const out = host.querySelector('[data-grid-count]');
    if (!out) return;

    const s = ticked('size').length;
    const c = Math.max(ticked('color').length, 1);
    out.textContent = s ? `${s * c} variant${s * c === 1 ? '' : 's'} will be created (${s} size${s === 1 ? '' : 's'}${ticked('color').length ? ` × ${c} colour${c === 1 ? '' : 's'}` : ''}), each with its own barcode and stock.` : 'Tick at least one size.';
  }

  const val = (name) => (host.querySelector(`[data-f="${name}"]`)?.value ?? '').trim();

  // -------------------------------------------------------------------------
  // Inline category / sub-category creation
  // -------------------------------------------------------------------------

  function openCategoryAdd(isTop) {
    const holder = $('[data-cat-add]');
    const typeOptions = Object.entries(PROFILES).map(([k, p]) => `<option value="${k}">${p.label}</option>`).join('');

    holder.innerHTML = `
      <div class="d-flex flex-wrap gap-2 align-items-end p-2 border rounded bg-light">
        <div><label class="form-label small mb-0">${isTop ? 'New category name' : 'New sub-category name'}</label>
          <input class="form-control form-control-sm" data-cat-name maxlength="120" style="width:15rem"></div>
        ${isTop ? `<div><label class="form-label small mb-0">What kind of items?</label>
          <select class="form-select form-select-sm" data-cat-type>${typeOptions}</select></div>` : ''}
        <button class="btn btn-sm btn-primary" type="button" data-cat-save>Save</button>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-cat-cancel>Cancel</button>
      </div>`;

    holder.querySelector('[data-cat-name]').focus();
    holder.querySelector('[data-cat-cancel]').addEventListener('click', () => { holder.innerHTML = ''; });

    holder.querySelector('[data-cat-save]').addEventListener('click', async (event) => {
      const name = holder.querySelector('[data-cat-name]').value.trim();
      if (name.length < 2) { say('Enter a name (at least 2 letters).', 'text-danger'); return; }

      const body = { name };
      if (isTop) body.item_type = holder.querySelector('[data-cat-type]').value;
      else body.parent_uuid = st.topUuid;

      setBusy(event.currentTarget, true, 'Saving');

      try {
        const response = await api.post('/admin/inventory/quick-category', body);
        const created = response.data.category;
        const parentType = isTop ? created.item_type : currentType();

        setup.categories.push({
          uuid: created.uuid,
          slug: created.slug,
          name: created.name,
          parent_uuid: isTop ? null : st.topUuid,
          item_type: parentType || 'general',
          has_children: false,
        });

        if (isTop) { st.topUuid = created.uuid; st.subUuid = ''; }
        else { st.subUuid = created.uuid; }

        holder.innerHTML = '';
        fillTop(); fillSub(); refreshBadge(); buildDetail();
        say(`${isTop ? 'Category' : 'Sub-category'} "${created.name}" added.`, 'text-success');
      } catch (error) {
        showError(error, holder);
      }
    });
  }

  // -------------------------------------------------------------------------
  // Wiring
  // -------------------------------------------------------------------------

  topSel.addEventListener('change', () => {
    st.topUuid = topSel.value;
    st.subUuid = '';
    st.mode = 'packed';
    const t = currentType();
    st.unit = t === 'oils' ? 'litre' : 'kg';
    fillSub(); refreshBadge(); buildDetail();
  });
  subSel.addEventListener('change', () => { st.subUuid = subSel.value; });
  $('[data-add-top]').addEventListener('click', () => openCategoryAdd(true));
  $('[data-add-sub]').addEventListener('click', () => openCategoryAdd(false));
  $('[data-cancel-new-item]').addEventListener('click', () => { host.innerHTML = ''; });

  createBtn.addEventListener('click', async () => {
    const type = currentType();
    const profile = PROFILES[type] || PROFILES.general;
    const category = leafUuid();

    if (!category) { say('Choose a sub-category for this item.', 'text-danger'); return; }
    if (!val('product_name')) { say('Enter the item name.', 'text-danger'); return; }
    if (!val('mrp') || !val('selling_price')) { say('Enter the MRP and the selling price.', 'text-danger'); return; }
    if (Number(val('selling_price')) > Number(val('mrp'))) { say('Selling price cannot be above the MRP.', 'text-danger'); return; }

    const name = val('product_name');
    const mrp = val('mrp');
    const selling = val('selling_price');
    let variants = [];

    if (profile.mode === 'weighed') {
      if (st.mode === 'loose') {
        variants = [{
          variant_name: `Loose (per ${UNIT_NAMES[st.unit]})`,
          weight_grams: UNIT_GRAMS[st.unit],
          stock_unit_type: 'weight',
          unit_label: st.unit,
          pack_type: 'other',
          barcode: val('barcode'),
          mrp, selling_price: selling,
        }];
      } else {
        if (!val('variant_name') || !val('weight_grams')) { say('Enter the pack size and its weight.', 'text-danger'); return; }
        variants = [{
          variant_name: val('variant_name'),
          weight_grams: Number(val('weight_grams')),
          stock_unit_type: 'weight',
          pack_type: val('pack_type') || 'pouch',
          barcode: val('barcode'),
          mrp, selling_price: selling,
        }];
      }
    } else if (profile.mode === 'grid') {
      const sizes = ticked('size');
      const colours = ticked('color');
      if (!sizes.length) { say('Tick at least one size.', 'text-danger'); return; }

      for (const size of sizes) {
        for (const colour of (colours.length ? colours : [''])) {
          variants.push({
            variant_name: colour ? `${size} / ${colour}` : size,
            stock_unit_type: 'quantity',
            unit_label: 'pcs',
            mrp, selling_price: selling,
            options: colour ? { size, color: colour } : { size },
          });
        }
      }
    } else {
      variants = [{
        variant_name: val('variant_name') || 'Standard',
        weight_grams: val('weight_grams') ? Number(val('weight_grams')) : undefined,
        stock_unit_type: 'quantity',
        unit_label: 'pcs',
        barcode: val('barcode'),
        mrp, selling_price: selling,
      }];
    }

    variants = variants.map((v) => Object.fromEntries(Object.entries(v).filter(([, x]) => x !== '' && x !== undefined)));

    const payload = { category_uuid: category, product_name: name, variants };
    if (val('brand')) payload.brand = val('brand');
    if (val('hsn_code')) payload.hsn_code = val('hsn_code');
    if (val('gst_rate') !== '') payload.gst_rate = Number(val('gst_rate'));

    setBusy(createBtn, true, 'Creating');

    try {
      const response = await api.post('/admin/inventory/quick-create', payload);
      const created = response.data.variants || [response.data];
      const cost = val('unit_cost');

      created.forEach((v) => {
        addLine({
          variant_uuid: v.uuid,
          sku: v.sku,
          barcode: v.barcode || null,
          product_name: name,
          variant_name: v.variant_name,
          quantity: '1',
          unit_cost: cost,
          batch_no: '',
          expiry_date: '',
          mrp: String(v.mrp ?? ''),
          selling_price: String(v.selling_price ?? ''),
          gst_rate: val('gst_rate'),
          is_new: true,
        });
      });

      toast(`${created.length === 1 ? 'Item' : `${created.length} variants`} created and added — set the quantity${cost ? '' : ' and cost'} in the table below.`);
      host.innerHTML = '';
    } catch (error) {
      setBusy(createBtn, false);
      showError(error, host);
    }
  });

  fillTop();
  fillSub();
  refreshBadge();
}
