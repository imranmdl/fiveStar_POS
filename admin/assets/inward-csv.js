/**
 * Vendor bill upload for Purchase Inward.
 *
 * Upload a CSV/XLSX -> the server works out what each row is (existing item or
 * a new one to create), matches categories, and does the arithmetic (discount,
 * GST, line total, weight from "500 g", price from markup ...). This screen
 * shows the result for review: every field can be corrected in place, totals
 * update as you type, and rows with problems are held back until fixed.
 * "Add to purchase" then creates the new items (with their variants) and drops
 * every line into the purchase order table.
 */

const GST_BY_TYPE = { grocery: 5, oils: 5, clothing: 12, footwear: 12, toys: 12, stationery: 12, general: 18 };

export function renderCsvUpload(host, ctx, options = {}) {
  const { api, setup, addLine, escapeHtml, formatMoney, setBusy, showError, toast } = ctx;
  // 'purchase' = lines for a purchase order; 'products' = create catalogue items only (Products screen).
  const P = options.mode === 'products';
  let rows = [];
  let summary = null;
  let imageToken = null;
  let lastBatch = [];

  const cats = () => setup.categories || [];
  const byUuid = (u) => cats().find((c) => c.uuid === u);
  const tops = () => cats().filter((c) => !c.parent_uuid);
  const kids = (u) => cats().filter((c) => c.parent_uuid === u);
  const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
  const round2 = (n) => Math.round(n * 100) / 100;

  host.innerHTML = `
    <div class="card border-secondary mt-2 mb-3">
      <div class="card-body">
        <h3 class="h6">${P ? 'Import products (Excel, CSV or ZIP with photos)' : 'Upload a vendor bill (CSV, Excel or ZIP with photos)'}</h3>
        <p class="small text-muted mb-2">
          ${P
            ? 'One row per pack size. Give a <b>Category, Sub Category, Product Name</b> and a <b>Selling Price</b> (or MRP, or Markup % with a Unit Cost). Put the photo file name in the <b>Image</b> column and upload everything as one <b>ZIP</b> (the Excel plus the pictures). Quantity and cost are not needed here — stock comes in through Purchase Inward.'
            : 'One row per item. Only <b>Quantity</b> and <b>Unit Cost</b> are compulsory, plus either a <b>SKU</b> (existing item) or a <b>Category, Product Name</b> and a price (new item). Leave out anything you want worked out for you: weight from the pack size, GST by type, selling price from <b>Markup %</b>, MRP, line totals and discounts. To attach photos, put the file name in the <b>Image</b> column and upload a ZIP holding the Excel and the pictures.'}
          You review and fix everything on screen before anything is saved.
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <button class="btn btn-sm btn-outline-primary" type="button" data-dl-template>Download template</button>
          <span class="text-muted small">or test data:</span>
          <select class="form-select form-select-sm" style="width:auto" data-sample-rows>
            <option value="15">15 rows</option><option value="30" selected>30 rows</option><option value="60">60 rows</option><option value="100">100 rows</option>
          </select>
          <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="sample-err" data-sample-errors>
            <label class="form-check-label small" for="sample-err">include a few mistakes to fix</label></div>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-dl-sample>Generate test CSV</button>
        </div>
        <div class="input-group input-group-sm" style="max-width:32rem">
          <input class="form-control" type="file" accept=".csv,.xlsx,.zip" data-csv-file>
          <button class="btn btn-outline-primary" type="button" data-csv-upload>Review file</button>
        </div>
        <div data-csv-feedback class="small mt-2"></div>
        <div data-csv-review class="mt-3"></div>
      </div>
    </div>`;

  const feedback = host.querySelector('[data-csv-feedback]');
  const review = host.querySelector('[data-csv-review]');

  const download = async (path, params, fallbackName) => {
    const query = params ? '?' + new URLSearchParams(params).toString() : '';
    const blob = await api.downloadFile(path + query);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = fallbackName;
    a.click();
    URL.revokeObjectURL(a.href);
  };

  host.querySelector('[data-dl-template]').addEventListener('click', () => {
    download('/admin/purchase-orders/items/bill-template', undefined, 'purchase-bill-template.csv').catch((e) => showError(e, feedback));
  });

  host.querySelector('[data-dl-sample]').addEventListener('click', () => {
    const params = { rows: host.querySelector('[data-sample-rows]').value };
    if (host.querySelector('[data-sample-errors]').checked) params.errors = 1;
    download('/admin/purchase-orders/items/bill-sample', params, `purchase-bill-test-${params.rows}.csv`).catch((e) => showError(e, feedback));
  });

  host.querySelector('[data-csv-upload]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const file = host.querySelector('[data-csv-file]').files[0];

    if (!file) { feedback.innerHTML = '<span class="text-danger">Choose a file first.</span>'; return; }

    setBusy(button, true, 'Reading');
    feedback.textContent = '';

    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('mode', P ? 'products' : 'purchase');
      const response = await api.upload('/admin/purchase-orders/items/parse-bill', formData);
      imageToken = response.data.image_token || null;
      rows = (response.data.rows || []).map((r) => ({ ...r, include: r.status !== 'error' && !(P && r.kind === 'existing'), serverErrors: r.errors }));
      if (imageToken) feedback.innerHTML = `<span class="text-muted">${response.data.images_found} photo(s) found in the ZIP.</span>`;
      summary = response.data.summary;
      rows.forEach(revalidate);
      draw();
    } catch (error) {
      showError(error, feedback);
    } finally {
      setBusy(button, false);
    }
  });

  // -------------------------------------------------------------------------
  // Client-side recalculation — mirrors the server rules for what can be edited.
  // -------------------------------------------------------------------------

  function typeOf(row) {
    if (row.kind === 'existing') return row.item_type || 'general';
    const leaf = byUuid(row.category_uuid);
    return leaf ? leaf.item_type || 'general' : 'general';
  }

  function recalc(row) {
    const qty = num(row.quantity) || 0;
    const cost = num(row.unit_cost) || 0;
    const disc = num(row.discount_percent) || 0;
    row.discount_amount = round2(qty * cost * disc / 100);
    row.line_total = round2(qty * cost - row.discount_amount);
    row.net_unit_cost = cost * (1 - disc / 100);
    row.gst_amount = round2(row.line_total * (num(row.gst_rate) || 0) / 100);
  }

  function revalidate(row) {
    recalc(row);
    const errors = [];
    const warnings = [];
    const type = typeOf(row);
    const today = new Date().toISOString().slice(0, 10);

    if (!P && !(num(row.quantity) > 0)) errors.push('Quantity must be above 0.');
    if (!P && !(num(row.unit_cost) > 0)) errors.push('Unit cost must be above 0.');
    if ((num(row.discount_percent) || 0) < 0 || (num(row.discount_percent) || 0) > 100) errors.push('Discount % must be 0–100.');
    if (!P && row.expiry_date && row.expiry_date < today) errors.push('Expiry date is in the past.');

    if (row.kind === 'new') {
      if (!row.category_uuid) errors.push('Choose a category' + (row.category_uuid === null ? ' and sub-category.' : '.'));
      if (!String(row.product_name || '').trim()) errors.push('Product name is required.');
      if (['grocery', 'oils'].includes(type) && !row.loose && !(num(row.weight_grams) > 0)) errors.push('Weight (g) is required for this item.');
      if (!(num(row.selling_price) > 0) || !(num(row.mrp) > 0)) errors.push('Enter the MRP and the selling price.');
    }

    if (num(row.selling_price) > num(row.mrp)) errors.push('Selling price is above the MRP.');

    if (num(row.selling_price) > 0 && row.net_unit_cost > 0) {
      const margin = ((num(row.selling_price) - row.net_unit_cost) / num(row.selling_price)) * 100;
      row.margin_percent = Math.round(margin * 10) / 10;
      if (num(row.selling_price) < row.net_unit_cost) warnings.push('Selling below cost — a loss on every sale.');
      else if (margin < 5) warnings.push('Margin is under 5%.');
    }

    (row.serverErrors || []).filter((e) => e.startsWith('Duplicate')).forEach((e) => errors.push(e));
    row.errors = errors;
    row.warnings = warnings;
  }

  const usable = (r) => r.include && r.errors.length === 0;

  // -------------------------------------------------------------------------
  // Drawing
  // -------------------------------------------------------------------------

  function categoryCells(row, i) {
    if (row.kind === 'existing') return `<span class="text-muted small">existing item</span>`;

    const leaf = byUuid(row.category_uuid);
    const top = leaf ? (leaf.parent_uuid ? byUuid(leaf.parent_uuid) : leaf) : byUuid(row.category_pending_top);
    const subs = top ? kids(top.uuid) : [];

    return `
      <select class="form-select form-select-sm mb-1" data-f="cat_top" style="min-width:9rem">
        <option value="">Category…</option>${tops().map((c) => `<option value="${escapeHtml(c.uuid)}" ${top && top.uuid === c.uuid ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}
      </select>
      ${subs.length ? `<select class="form-select form-select-sm" data-f="cat_sub" style="min-width:9rem">
        <option value="">Sub-category…</option>${subs.map((c) => `<option value="${escapeHtml(c.uuid)}" ${leaf && leaf.uuid === c.uuid ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}
      </select>` : ''}`;
  }

  const cell = (i, field, value, opts = '', width = '5.5rem') =>
    `<input class="form-control form-control-sm" data-f="${field}" value="${escapeHtml(value ?? '')}" ${opts} style="width:${width}">`;

  function rowHtml(row, i) {
    const bad = row.errors.length > 0;
    const type = typeOf(row);
    const needsWeight = row.kind === 'new' && ['grocery', 'oils'].includes(type) && !row.loose;
    const extras = [row.size && `Size ${row.size}`, row.colour && `Colour ${row.colour}`, row.loose && `Loose per ${row.unit || 'kg'}`, row.brand && row.brand]
      .filter(Boolean).map(escapeHtml).join(' · ');

    return `
      <tr data-row="${i}" class="${bad ? 'table-danger' : row.warnings.length ? 'table-warning' : ''}">
        <td><input type="checkbox" class="form-check-input" data-f="include" ${row.include ? 'checked' : ''} ${bad ? 'disabled' : ''}></td>
        <td class="small text-nowrap">${row.row_number}<div>${row.kind === 'existing'
          ? '<span class="badge text-bg-secondary">Existing</span>' : '<span class="badge text-bg-info">New item</span>'}</div></td>
        <td>${categoryCells(row, i)}</td>
        <td style="min-width:11rem">
          ${row.kind === 'existing'
            ? `<span class="fw-semibold">${escapeHtml(row.product_name)}</span><div class="small text-muted">${escapeHtml(row.variant_name)} · ${escapeHtml(row.sku)}</div>`
            : `${cell(i, 'product_name', row.product_name, 'placeholder="Product name"', '11rem')}
               ${cell(i, 'variant_name', row.variant_name, 'placeholder="Variant / pack"', '11rem').replace('form-control-sm', 'form-control-sm mt-1')}
               ${needsWeight ? `<div class="input-group input-group-sm mt-1" style="width:11rem"><input class="form-control" data-f="weight_grams" value="${escapeHtml(row.weight_grams ?? '')}" placeholder="Weight"><span class="input-group-text">g</span></div>` : ''}
               <div class="small text-muted">${extras}</div>`}
        </td>
        ${P ? '' : `<td>${cell(i, 'quantity', row.quantity, 'type="number" step="0.001" min="0"')}</td>
        <td>${cell(i, 'unit_cost', row.unit_cost, 'type="number" step="0.01" min="0"', '6rem')}</td>
        <td>${cell(i, 'discount_percent', row.discount_percent || '', 'type="number" step="0.01" min="0" max="100" placeholder="0"', '4.5rem')}</td>`}
        <td>${cell(i, 'gst_rate', row.gst_rate, 'type="number" step="0.01" min="0" max="28"', '4.5rem')}</td>
        <td>${cell(i, 'mrp', row.mrp, 'type="number" step="0.01" min="0"', '5.5rem')}</td>
        <td>${cell(i, 'selling_price', row.selling_price, 'type="number" step="0.01" min="0"', '5.5rem')}</td>
        ${P ? `<td class="small text-nowrap">${row.image ? (row.image_ok ? '<span class="text-success">📷 ' + escapeHtml(row.image) + '</span>' : '<span class="text-warning-emphasis">📷 missing</span>') : '<span class="text-muted">no photo</span>'}</td>` : `<td>${cell(i, 'batch_no', row.batch_no, 'placeholder="Auto"', '6rem')}</td>
        <td>${cell(i, 'expiry_date', row.expiry_date, 'type="date"', '8.5rem')}</td>
        <td class="text-end small text-nowrap" data-calc>${calcHtml(row)}</td>`}
      </tr>
      ${(bad || row.warnings.length || row.notes.length || (row.publish && row.kind === 'new')) ? `
      <tr data-row-msg="${i}" class="${bad ? 'table-danger' : ''}"><td></td><td colspan="${P ? 7 : 12}" class="small pt-0">
        ${row.errors.map((e) => `<div class="text-danger">✖ ${escapeHtml(e)}</div>`).join('')}
        ${row.warnings.map((w) => `<div class="text-warning-emphasis">⚠ ${escapeHtml(w)}</div>`).join('')}
        ${row.notes.map((n) => `<div class="text-muted">ℹ ${escapeHtml(n)}</div>`).join('')}
        ${row.publish && row.kind === 'new' ? '<div class="text-muted">🌐 Will be published online' + (row.image_ok ? '.' : ' if it has a photo (otherwise saved as a draft).') + '</div>' : ''}
      </td></tr>` : ''}`;
  }

  function calcHtml(row) {
    return `${formatMoney(row.line_total)}<div class="text-muted">${row.discount_amount ? `disc −${formatMoney(row.discount_amount)} · ` : ''}GST ${formatMoney(row.gst_amount)}</div>
      ${row.margin_percent !== undefined ? `<div class="${row.margin_percent < 5 ? 'text-danger' : 'text-success'}">margin ${row.margin_percent}%</div>` : ''}`;
  }

  function totalsHtml() {
    const ok = rows.filter(usable);
    if (P) {
      const errs = rows.filter((r) => r.errors.length).length;
      const withPhoto = ok.filter((r) => r.image_ok).length;
      return `<div class="d-flex flex-wrap gap-3 align-items-center small"><span><b>${ok.length}</b> of ${rows.length} rows ready</span>
        <span><b>${withPhoto}</b> with a photo</span>${errs ? `<span class="text-danger"><b>${errs}</b> need fixing</span>` : ''}
        <span>${rows.filter((r) => r.kind === 'existing').length} already exist (skipped)</span></div>`;
    }
    const taxable = ok.reduce((s, r) => s + r.line_total, 0);
    const gst = ok.reduce((s, r) => s + r.gst_amount, 0);
    const disc = ok.reduce((s, r) => s + r.discount_amount, 0);
    const errors = rows.filter((r) => r.errors.length).length;
    const warn = rows.filter((r) => r.warnings.length && !r.errors.length).length;

    return `
      <div class="d-flex flex-wrap gap-3 align-items-center small">
        <span><b>${ok.length}</b> of ${rows.length} rows ready (${ok.filter((r) => r.kind === 'new').length} new, ${ok.filter((r) => r.kind === 'existing').length} existing)</span>
        ${errors ? `<span class="text-danger"><b>${errors}</b> need fixing</span>` : ''}
        ${warn ? `<span class="text-warning-emphasis"><b>${warn}</b> to double-check</span>` : ''}
        <span>Discount <b>${formatMoney(disc)}</b></span><span>Taxable <b>${formatMoney(taxable)}</b></span>
        <span>GST <b>${formatMoney(gst)}</b></span><span>Bill total <b>${formatMoney(taxable + gst)}</b></span>
      </div>`;
  }

  function draw() {
    if (!rows.length) { review.innerHTML = '<div class="text-muted small">No rows found in that file.</div>'; return; }

    review.innerHTML = `
      <div class="mb-2" data-totals>${totalsHtml()}</div>
      <div class="table-responsive" style="max-height:32rem">
        <table class="table table-sm align-middle mb-2">
          <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr>
            <th></th><th>Row</th><th>Category</th><th>Item</th>${P ? '' : '<th>Qty</th><th>Unit cost</th><th>Disc %</th>'}<th>GST %</th><th>MRP</th><th>Selling</th>${P ? '<th>Photo</th>' : '<th>Batch</th><th>Expiry</th><th class="text-end">Line</th>'}
          </tr></thead>
          <tbody>${rows.map(rowHtml).join('')}</tbody>
        </table>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn btn-sm btn-primary" type="button" data-add-all>${P ? 'Create ready items' : 'Add ready rows to this purchase'}</button>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-clear>Clear</button>
        <span class="small text-muted">Rows with a ✖ are skipped until fixed. ${P ? 'Items are created as products; receive stock through Purchase Inward.' : 'Nothing is saved to stock until you save the purchase.'}</span>
      </div>`;

    review.querySelector('[data-clear]').addEventListener('click', () => { rows = []; review.innerHTML = ''; feedback.textContent = ''; });
    review.querySelector('[data-add-all]').addEventListener('click', (e) => addAll(e.currentTarget));

    review.querySelectorAll('[data-row]').forEach((tr) => {
      const i = Number(tr.dataset.row);
      tr.querySelectorAll('[data-f]').forEach((el) => {
        el.addEventListener('change', () => onEdit(i, el));
        if (el.tagName === 'INPUT' && el.type !== 'checkbox') el.addEventListener('input', () => onEdit(i, el, true));
      });
    });
  }

  function onEdit(i, el, live = false) {
    const row = rows[i];
    const f = el.dataset.f;

    if (f === 'include') { row.include = el.checked; refreshTotals(); return; }

    if (f === 'cat_top' || f === 'cat_sub') {
      const uuid = el.value;
      if (f === 'cat_top') {
        row.category_uuid = uuid ? (kids(uuid).length ? null : uuid) : null;
        if (uuid && kids(uuid).length) row.category_pending_top = uuid;
      } else {
        row.category_uuid = uuid || null;
      }
      const leaf = byUuid(row.category_uuid);
      if (leaf && !row.gst_edited) row.gst_rate = GST_BY_TYPE[leaf.item_type || 'general'];
      revalidate(row);
      if (row.errors.length === 0 && !row.include) row.include = true;
      draw();
      return;
    }

    row[f] = f === 'weight_grams' ? (el.value === '' ? null : Number(el.value)) : el.value;
    if (f === 'gst_rate') row.gst_edited = true;
    const hadErrors = row.errors.length;
    const hadWarn = row.warnings.length;
    revalidate(row);

    if (live && hadErrors === row.errors.length && hadWarn === row.warnings.length) {
      const tr = review.querySelector(`[data-row="${i}"]`);
      tr.querySelector('[data-calc]').innerHTML = calcHtml(row);
      refreshTotals();
      return;
    }

    if (row.errors.length === 0 && hadErrors > 0) row.include = true;
    if (!live) { draw(); }
    else { refreshTotals(); }
  }

  function refreshTotals() {
    review.querySelector('[data-totals]').innerHTML = totalsHtml();
  }

  // -------------------------------------------------------------------------
  // Create new items, then add every line
  // -------------------------------------------------------------------------

  async function addAll(button) {
    const ready = rows.filter(usable);
    if (!ready.length) { toast('Nothing ready to add — fix the rows marked ✖.', 'warning'); return; }

    setBusy(button, true, 'Adding');

    try {
      const groups = new Map();
      ready.filter((r) => r.kind === 'new').forEach((r) => {
        const key = `${r.category_uuid}|${r.product_name.trim().toLowerCase()}|${(r.brand || '').toLowerCase()}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(r);
      });

      const created = new Map();
      const failed = new Map();

      if (groups.size) {
        const list = [...groups.values()];
        const items = list.map((g) => ({
          category_uuid: g[0].category_uuid,
          product_name: g[0].product_name.trim(),
          brand: g[0].brand || undefined,
          hsn_code: g[0].hsn_code || undefined,
          gst_rate: num(g[0].gst_rate) ?? undefined,
          publish: g.some((r) => r.publish),
          short_description: (g.find((r) => r.short_description) || {}).short_description,
          image: (g.find((r) => r.image_ok) || {}).image,
          image_token: g.some((r) => r.image_ok) ? imageToken : undefined,
          variants: g.map((r) => {
            const v = {
              variant_name: r.variant_name || 'Standard',
              mrp: num(r.mrp), selling_price: num(r.selling_price),
              options: {},
            };
            if (r.sku) v.sku = r.sku;
            if (r.barcode) v.barcode = r.barcode;
            if (num(r.weight_grams) > 0) v.weight_grams = Number(r.weight_grams);
            if (r.pack_type) v.pack_type = r.pack_type;
            if (r.loose) { v.stock_unit_type = 'weight'; v.unit_label = r.unit || 'kg'; v.pack_type = 'other'; }
            if (r.size) v.options.size = r.size;
            if (r.colour) v.options.color = r.colour;
            if (!Object.keys(v.options).length) delete v.options;
            return v;
          }),
        }));

        const response = await api.post('/admin/inventory/quick-create-batch', { items });

        lastBatch = response.data.results;
        response.data.results.forEach((res, gi) => {
          list[gi].forEach((r, vi) => {
            if (res.ok) created.set(r, res.variants[vi]);
            else failed.set(r, res.error);
          });
        });
      }

      let added = 0;
      let already = 0;
      const draftNotes = new Set();

      ready.forEach((r) => {
        if (failed.has(r)) return;
        const v = r.kind === 'new' ? created.get(r) : null;

        if (P) { r.done = true; added += 1; return; }

        const ok = addLine({
          variant_uuid: v ? v.uuid : r.variant_uuid,
          sku: v ? v.sku : r.sku,
          barcode: (v ? v.barcode : r.barcode) || null,
          product_name: r.product_name,
          variant_name: v ? v.variant_name : r.variant_name,
          quantity: String(r.quantity),
          unit_cost: String(r.unit_cost),
          batch_no: r.batch_no || '',
          expiry_date: r.expiry_date || '',
          mrp: String(r.mrp ?? ''),
          selling_price: String(r.selling_price ?? ''),
          gst_rate: String(r.gst_rate ?? ''),
          discount_amount: r.discount_amount ? String(r.discount_amount) : '',
          is_new: r.kind === 'new',
        });

        if (ok) added += 1; else already += 1;
        r.done = true;
      });

      ready.forEach((r) => { if (failed.has(r)) { r.serverErrors = [failed.get(r)]; r.errors = [failed.get(r)]; r.include = false; } });
      rows = rows.filter((r) => !r.done);

      const newCount = created.size;
      if (P) {
        const published = lastBatch.filter((x) => x.ok && x.published).length;
        const photos = lastBatch.filter((x) => x.ok && x.image_attached).length;
        const notes = [...new Set(lastBatch.map((x) => x.publish_note).filter(Boolean))];
        feedback.innerHTML = `<span class="text-success">${newCount} item(s) created — ${photos} product(s) with photos, ${published} published online.</span>
          ${notes.map((n) => `<div class="text-warning-emphasis small">${escapeHtml(n)}</div>`).join('')}
          ${failed.size ? `<div class="text-danger">${failed.size} row(s) could not be created — see below.</div>` : ''}`;
        toast(`${newCount} item(s) created.`);
        if (options.onDone) options.onDone();
        rows.length ? draw() : (review.innerHTML = '');
        return;
      }
      feedback.innerHTML = `<span class="text-success">${added} line(s) added to the purchase${newCount ? ` — ${newCount} new item(s) created` : ''}${already ? `, ${already} already on this order` : ''}.</span>
        ${failed.size ? `<div class="text-danger">${failed.size} row(s) could not be created — see below.</div>` : ''}
        <div class="text-muted">Scroll down to the items table to check quantities and costs, then save the purchase.</div>`;
      toast(`${added} line(s) added.`);
      rows.length ? draw() : (review.innerHTML = '');
    } catch (error) {
      showError(error, feedback);
    } finally {
      setBusy(button, false);
    }
  }
}
