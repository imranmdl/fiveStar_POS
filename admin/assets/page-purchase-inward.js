/**
 * Purchase inward: one guided flow from "who did we buy this from" through
 * to "what does this stock actually cost us landed, and have we paid for
 * it" — record what was bought from a vendor (creating the vendor right
 * here if it's a new one), list or create the items, apply a transportation
 * charge as a landing-cost percentage, and track what's been paid.
 *
 * Immediate effect — saving posts inventory movements right away (see
 * PurchaseOrderService), there is no draft/receive step. A history tab
 * lists past purchase orders, with a detail view for payment follow-up.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         queryParam, emptyState, headerIcon } from './console.js?v=9';
import { resolvePriceDecisionQueue } from './pricing-decisions.js';
import { renderNewItemForm as renderNewItem } from './inward-new-item.js';
import { renderCsvUpload as renderBillUpload } from './inward-csv.js';

const ICONS = {
  building: '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1"/>',
  box: '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
  truck: '<rect x="1" y="7" width="13" height="10" rx="1"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/>',
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
};

const state = {
  tab: queryParam('tab') || 'record',
  history: { vendor_uuid: '', page: 1 },
};

/**
 * Opens a small popup window with one label per item, each rendered as a
 * scannable EAN-13 (via JsBarcode, loaded inside that window — not this
 * page, so browsing purchase-inward never pays for the library unless
 * someone actually prints), and triggers the browser print dialog once the
 * barcodes have drawn. Self-contained rather than reusing this page's own
 * print, since a receipt-style label sheet has nothing in common with the
 * rest of the form.
 */
function printBarcodeLabels(labels) {
  const win = window.open('', '_blank', 'width=420,height=600');

  if (!win) {
    toast('Allow pop-ups for this site to print barcode labels.', 'danger');
    return;
  }

  const label = (item, index) => `
    <div class="label">
      <div class="title">${escapeHtml(item.title)}</div>
      ${item.subtitle ? `<div class="subtitle">${escapeHtml(item.subtitle)}</div>` : ''}
      <div class="barcode-box">
        <svg id="bc${index}"></svg>
        <div class="code">${escapeHtml(item.barcode)}</div>
      </div>
    </div>`;

  const draw = (item, index) =>
    `try { JsBarcode("#bc${index}", ${JSON.stringify(item.barcode)}, { format: "EAN13", height: 50, fontSize: 13, margin: 4 }); } catch (e) {}`;

  win.document.write(`<!doctype html><html><head><title>Barcode labels</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }
      .label {
        border: 1px dashed #999; border-radius: 4px; padding: 10px 8px; margin-bottom: 10px;
        text-align: center; page-break-inside: avoid;
      }
      /* The item name is the whole point — big and unmistakable, sitting
         clearly above its own barcode box, so it's obvious which barcode
         belongs to which item before it goes on the shelf. */
      .title { font-size: 16px; font-weight: 700; }
      .subtitle { font-size: 12px; color: #555; margin-bottom: 8px; }
      .barcode-box { border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px; background: #fff; }
      .code { font-size: 12px; letter-spacing: 1px; margin-top: 2px; }
      svg { max-width: 100%; }
      @media print { .label { border: none; } .barcode-box { border: 1px solid #999; } }
    </style>
  </head><body>
    ${labels.map(label).join('')}
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></${''}script>
    <script>
      window.onload = function () {
        ${labels.map(draw).join('\n')}
        setTimeout(function () { window.print(); }, 250);
      };
    </${''}script>
  </body></html>`);
  win.document.close();
}

/**
 * The purchase order as a printable document — same self-contained popup +
 * `@media print` + window.print() pattern as printBarcodeLabels() above,
 * not a new PDF library: "Download" is the browser's own print-to-PDF.
 */
function printPurchaseOrder(po) {
  const win = window.open('', '_blank', 'width=800,height=900');

  if (!win) {
    toast('Allow pop-ups for this site to print the purchase order.', 'danger');
    return;
  }

  const itemRow = (item) => `
    <tr>
      <td>${escapeHtml(item.sku)}<div class="muted">${escapeHtml(item.variant_name)}</div></td>
      <td>${escapeHtml(item.batch_no || '—')}</td>
      <td>${escapeHtml(item.expiry_date || '—')}</td>
      <td class="num">${escapeHtml(Number(item.quantity))}</td>
      <td class="num">${formatMoney(item.unit_cost)}</td>
      <td class="num">${item.gst_rate ? escapeHtml(item.gst_rate) + '%' : '—'}</td>
      <td class="num">${formatMoney((Number(item.quantity) * Number(item.unit_cost)))}</td>
    </tr>`;

  win.document.write(`<!doctype html><html><head><title>${escapeHtml(po.po_number)}</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 24px; color: #1a1a1a; }
      h1 { font-size: 18px; margin-bottom: 2px; }
      .muted { color: #666; font-size: 11px; }
      table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12px; }
      th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
      .num { text-align: right; }
      .totals { width: 260px; margin-left: auto; margin-top: 12px; font-size: 12px; }
      .totals td { border: none; padding: 2px 4px; }
      .totals .grand td { font-weight: 700; border-top: 1px solid #333; }
      @media print { .no-print { display: none; } }
    </style>
  </head><body>
    <h1>Purchase Order ${escapeHtml(po.po_number)}</h1>
    <div class="muted">Vendor: ${escapeHtml(po.vendor_name)} · Warehouse: ${escapeHtml(po.warehouse_name)} · Date: ${escapeHtml(String(po.purchase_date || '').slice(0, 10))}</div>
    <table>
      <thead><tr><th>Item</th><th>Batch</th><th>Expiry</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">GST</th><th class="num">Line total</th></tr></thead>
      <tbody>${(po.items || []).map(itemRow).join('')}</tbody>
    </table>
    <table class="totals">
      <tr><td>Items subtotal</td><td class="num">${formatMoney(po.items_subtotal)}</td></tr>
      <tr><td>Discount</td><td class="num">${formatMoney(po.discount_amount)}</td></tr>
      <tr><td>Transportation</td><td class="num">${formatMoney(po.transport_charge)}</td></tr>
      <tr><td>Other charges</td><td class="num">${formatMoney(po.other_charges)}</td></tr>
      <tr><td>Tax</td><td class="num">${formatMoney(po.tax_amount)}</td></tr>
      <tr class="grand"><td>Grand total</td><td class="num">${formatMoney(po.grand_total)}</td></tr>
      <tr><td>Paid</td><td class="num">${formatMoney(po.amount_paid)}</td></tr>
      <tr><td>Returned</td><td class="num">${formatMoney(po.amount_returned)}</td></tr>
    </table>
    <script>window.onload = function () { setTimeout(function () { window.print(); }, 150); };</${''}script>
  </body></html>`);
  win.document.close();
}

/**
 * Select items/quantities from this PO to send back to the vendor. The
 * per-line max is only the ORIGINAL purchased quantity here — the server is
 * the actual authority on what's still available to return (it also
 * subtracts anything already returned), so an over-return attempt still
 * surfaces as a clear error on submit rather than being silently capped
 * wrong client-side.
 */
function renderReturnForm(host, po, poUuid, detailContainer) {
  host.innerHTML = `
    <div class="card mt-3"><div class="card-body">
      <h2 class="h6 mb-3">Return items to ${escapeHtml(po.vendor_name)}</h2>
      <form data-return-form>
        <div class="table-responsive mb-3">
          <table class="table table-sm">
            <thead><tr><th></th><th>Item</th><th>Purchased</th><th style="width:8rem">Return qty</th></tr></thead>
            <tbody>
              ${(po.items || []).map((item) => `
                <tr>
                  <td><input class="form-check-input" type="checkbox" data-return-check="${escapeHtml(item.uuid)}"></td>
                  <td class="small">${escapeHtml(item.sku)}<div class="text-muted">${escapeHtml(item.batch_no || '')}</div></td>
                  <td class="small">${escapeHtml(Number(item.quantity))}</td>
                  <td>
                    <input class="form-control form-control-sm" type="number" step="0.001" min="0.001"
                           max="${escapeHtml(item.quantity)}" data-return-qty="${escapeHtml(item.uuid)}" disabled>
                  </td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label small mb-0">Return date</label>
            <input class="form-control form-control-sm" type="date" name="return_date" value="${new Date().toISOString().slice(0, 10)}" required>
          </div>
          <div class="col-md-8">
            <label class="form-label small mb-0">Reason</label>
            <input class="form-control form-control-sm" name="reason" maxlength="500" required placeholder="e.g. damaged in transit">
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-sm btn-dark" type="submit">Record return</button>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-cancel-return>Cancel</button>
        </div>
      </form>
    </div></div>`;

  // The form is appended at the very bottom of a long page, so without this the
  // button looks like it does nothing.
  host.scrollIntoView({ behavior: 'smooth', block: 'start' });

  host.querySelectorAll('[data-return-check]').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      const qtyInput = host.querySelector(`[data-return-qty="${checkbox.dataset.returnCheck}"]`);
      qtyInput.disabled = !checkbox.checked;
      if (checkbox.checked && !qtyInput.value) qtyInput.value = qtyInput.max;
    });
  });

  host.querySelector('[data-cancel-return]').addEventListener('click', () => { host.innerHTML = ''; });

  host.querySelector('[data-return-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type=submit]');

    const lines = (po.items || [])
      .filter((item) => host.querySelector(`[data-return-check="${item.uuid}"]`).checked)
      .map((item) => ({
        purchase_order_item_uuid: item.uuid,
        quantity: host.querySelector(`[data-return-qty="${item.uuid}"]`).value,
      }));

    if (lines.length === 0) {
      toast('Select at least one item to return.', 'danger');
      return;
    }

    const data = Object.fromEntries(new FormData(form).entries());
    setBusy(button, true, 'Saving');

    try {
      await api.post('/admin/purchase-returns', {
        purchase_order_uuid: poUuid,
        return_date: data.return_date,
        reason: data.reason,
        lines,
      });
      toast('Return recorded. Stock and vendor balance are updated.');
      renderHistoryDetail(detailContainer, poUuid);
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

let root = null;
let vendors = [];
let warehouses = [];
let inwardSetup = { categories: [], option_types: [] };
let lines = [];

function vendorOptions(selected) {
  return vendors.map((v) => `
    <option value="${escapeHtml(v.uuid)}" ${v.uuid === selected ? 'selected' : ''}>${escapeHtml(v.name)}</option>`).join('');
}

function warehouseOptions(selected) {
  return warehouses.map((w) => `
    <option value="${escapeHtml(w.uuid)}" ${w.uuid === selected || (!selected && w.is_default) ? 'selected' : ''}>
      ${escapeHtml(w.name)}
    </option>`).join('');
}

/** Reads whatever is currently in the line-item inputs back into `lines`, so a re-render never loses an edit in progress. */
function syncLinesFromDom() {
  document.querySelectorAll('[data-line-row]').forEach((rowEl) => {
    const index = Number(rowEl.dataset.lineRow);
    const line = lines[index];
    if (!line) return;

    line.quantity = rowEl.querySelector('[name=quantity]').value;
    line.invoiced_quantity = rowEl.querySelector('[name=invoiced_quantity]').value;
    line.unit_cost = rowEl.querySelector('[name=unit_cost]').value;
    line.batch_no = rowEl.querySelector('[name=batch_no]').value;
    line.expiry_date = rowEl.querySelector('[name=expiry_date]').value;
  });

  document.querySelectorAll('[data-line-pricing-row]').forEach((rowEl) => {
    const index = Number(rowEl.dataset.linePricingRow);
    const line = lines[index];
    if (!line) return;

    line.mrp = rowEl.querySelector('[name=mrp]').value;
    line.selling_price = rowEl.querySelector('[name=selling_price]').value;
    line.gst_rate = rowEl.querySelector('[name=gst_rate]').value;
    line.discount_amount = rowEl.querySelector('[name=discount_amount]').value;
  });
}

/** Current transport %, or null when it doesn't apply — shared by the line table preview and the charges summary. */
function currentTransportPercent(form) {
  if (!form) return null;

  const included = form.querySelector('[name=transport_included_in_cost]').checked;
  const charge = Number(form.querySelector('[name=transport_charge]').value) || 0;
  const itemsSubtotal = lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);

  if (included || charge <= 0 || itemsSubtotal <= 0) return null;

  return (charge / itemsSubtotal) * 100;
}

function lineRow(line, index, transportPercent) {
  const lineTotal = (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0);
  const landingUnit = transportPercent === null
    ? null
    : (Number(line.unit_cost) || 0) * (1 + transportPercent / 100);

  return `
    <tr data-line-row="${index}">
      <td>
        <span class="fw-semibold">${escapeHtml(line.sku)}</span>
        <div class="small text-muted">${escapeHtml(line.product_name)} · ${escapeHtml(line.variant_name)}${line.is_new ? ' <span class="badge text-bg-info">New item</span>' : ''}</div>
      </td>
      <td class="small">
        ${line.barcode
          ? `${escapeHtml(line.barcode)}<br><button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 mt-1" data-print-barcode="${index}">Print</button>`
          : `<button type="button" class="btn btn-sm btn-outline-info py-0 px-1" data-generate-barcode="${index}">Generate</button>`}
      </td>
      <td><input class="form-control form-control-sm" name="quantity" type="number" step="0.001" min="0.001"
                  value="${escapeHtml(line.quantity)}" style="width:6.5rem" required></td>
      <td><input class="form-control form-control-sm" name="invoiced_quantity" type="number" step="0.001" min="0"
                  value="${escapeHtml(line.invoiced_quantity || '')}" placeholder="Same" title="Invoiced quantity, if different from what arrived" style="width:6.5rem"></td>
      <td><input class="form-control form-control-sm" name="unit_cost" type="number" step="0.0001" min="0"
                  value="${escapeHtml(line.unit_cost)}" style="width:7rem" required></td>
      <td><input class="form-control form-control-sm" name="batch_no" maxlength="60"
                  value="${escapeHtml(line.batch_no || '')}" placeholder="Auto" title="Leave blank to auto-generate from the GRN number" style="width:7rem"></td>
      <td><input class="form-control form-control-sm" name="expiry_date" type="date"
                  value="${escapeHtml(line.expiry_date || '')}" style="width:9rem"></td>
      <td class="text-end small" data-line-total>${formatMoney(lineTotal)}</td>
      ${transportPercent !== null ? `<td class="text-end small text-muted" data-line-landing>${formatMoney(landingUnit)}/unit</td>` : ''}
      <td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-line="${index}">×</button></td>
    </tr>
    <tr data-line-pricing-row="${index}" class="border-top-0">
      <td colspan="10" class="pt-0 pb-2 border-top-0">
        <div class="d-flex flex-wrap align-items-end gap-2 ps-2">
          <div>
            <label class="form-label small mb-0 text-muted">MRP</label>
            <input class="form-control form-control-sm" name="mrp" type="number" step="0.01" min="0"
                   value="${escapeHtml(line.mrp || '')}" placeholder="Optional" style="width:6.5rem">
          </div>
          <div>
            <label class="form-label small mb-0 text-muted">Selling price</label>
            <input class="form-control form-control-sm" name="selling_price" type="number" step="0.01" min="0"
                   value="${escapeHtml(line.selling_price || '')}" placeholder="Optional" style="width:6.5rem">
          </div>
          <div>
            <label class="form-label small mb-0 text-muted">GST %</label>
            <input class="form-control form-control-sm" name="gst_rate" type="number" step="0.01" min="0" max="28"
                   value="${escapeHtml(line.gst_rate || '')}" placeholder="Optional" style="width:5.5rem">
          </div>
          <div>
            <label class="form-label small mb-0 text-muted">Line discount (₹)</label>
            <input class="form-control form-control-sm" name="discount_amount" type="number" step="0.01" min="0"
                   value="${escapeHtml(line.discount_amount || '')}" placeholder="0" style="width:6.5rem">
          </div>
          ${line.mrp || line.selling_price
            ? '<div class="small text-muted">MRP/selling price here becomes this item\'s live price immediately on save.</div>'
            : ''}
        </div>
      </td>
    </tr>`;
}

function renderLinesTable() {
  const table = document.querySelector('[data-lines-table]');
  if (!table) return;

  const form = document.querySelector('[data-po-form]');
  const transportPercent = currentTransportPercent(form);
  document.querySelectorAll('[data-landing-col]').forEach((el) => { el.hidden = transportPercent === null; });

  table.innerHTML = lines.length === 0
    ? `<tr><td colspan="10" class="text-center text-muted small py-3">No items yet — add an existing item, a new item, or upload a CSV below.</td></tr>`
    : lines.map((line, index) => lineRow(line, index, transportPercent)).join('');

  table.querySelectorAll('[data-remove-line]').forEach((button) => {
    button.addEventListener('click', () => {
      syncLinesFromDom();
      lines.splice(Number(button.dataset.removeLine), 1);
      renderLinesTable();
      updateTotals();
    });
  });

  table.querySelectorAll('[data-print-barcode]').forEach((button) => {
    button.addEventListener('click', () => {
      const line = lines[Number(button.dataset.printBarcode)];
      printBarcodeLabels([{
        barcode: line.barcode,
        title: line.product_name,
        subtitle: `${line.variant_name} · ${line.sku}`,
      }]);
    });
  });

  table.querySelectorAll('[data-generate-barcode]').forEach((button) => {
    button.addEventListener('click', async () => {
      const index = Number(button.dataset.generateBarcode);
      const line = lines[index];

      setBusy(button, true, 'Generating');

      try {
        const response = await api.post(`/admin/inventory/variants/${encodeURIComponent(line.variant_uuid)}/barcode`, {});
        syncLinesFromDom();
        line.barcode = response.data.variant.barcode;
        renderLinesTable();
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  });

  table.querySelectorAll('input').forEach((input) => {
    input.addEventListener('input', updateTotals);
  });

  updateTotals();
}

function updateTotals() {
  syncLinesFromDom();

  const form = document.querySelector('[data-po-form]');
  if (!form) return;

  const transportPercent = currentTransportPercent(form);

  document.querySelectorAll('[data-line-row]').forEach((rowEl) => {
    const index = Number(rowEl.dataset.lineRow);
    const line = lines[index];
    const total = (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0);
    rowEl.querySelector('[data-line-total]').textContent = formatMoney(total);

    const landingCell = rowEl.querySelector('[data-line-landing]');
    if (landingCell && transportPercent !== null) {
      landingCell.textContent = formatMoney((Number(line.unit_cost) || 0) * (1 + transportPercent / 100)) + '/unit';
    }
  });

  const itemsSubtotal = lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);
  const discount = Number(form.querySelector('[name=discount_amount]').value) || 0;
  const included = form.querySelector('[name=transport_included_in_cost]').checked;
  const transportCharge = included ? 0 : (Number(form.querySelector('[name=transport_charge]').value) || 0);
  const charges = Number(form.querySelector('[name=other_charges]').value) || 0;
  const tax = Number(form.querySelector('[name=tax_amount]').value) || 0;
  const grandTotal = itemsSubtotal - discount + transportCharge + charges + tax;

  document.querySelector('[data-items-subtotal]').textContent = formatMoney(itemsSubtotal);
  document.querySelector('[data-grand-total]').textContent = formatMoney(grandTotal);

  const hint = form.querySelector('[data-transport-hint]');
  hint.textContent = transportPercent === null
    ? ''
    : `≈ ${transportPercent.toFixed(2)}% added to every item's landed cost`;

  updatePaymentPill(grandTotal);
}

function updatePaymentPill(grandTotal) {
  const form = document.querySelector('[data-po-form]');
  const pill = form.querySelector('[data-payment-pill]');
  const amountPaid = Number(form.querySelector('[name=amount_paid]').value) || 0;

  let label = 'Unpaid';
  let tone = 'secondary';

  if (amountPaid > 0.005) {
    if (amountPaid >= grandTotal - 0.005) {
      label = 'Paid';
      tone = 'success';
    } else {
      label = 'Partial';
      tone = 'warning';
    }
  }

  pill.className = `badge text-bg-${tone}`;
  pill.textContent = label;
}

function addLine(entry) {
  if (lines.some((l) => l.variant_uuid === entry.variant_uuid)) {
    return false;
  }

  syncLinesFromDom();
  lines.push(entry);
  renderLinesTable();
  return true;
}

function addVariantLine(variant, input, feedback) {
  const added = addLine({
    variant_uuid: variant.uuid,
    sku: variant.sku,
    barcode: variant.barcode || null,
    product_name: variant.product_name,
    variant_name: variant.variant_name,
    quantity: '1',
    unit_cost: String(variant.selling_price ?? '0'),
    batch_no: '',
    expiry_date: '',
  });

  if (!added) {
    feedback.textContent = 'That item is already on this purchase order — edit its row below.';
    feedback.className = 'small text-warning-emphasis mt-1';
    return;
  }

  feedback.textContent = `Added: ${variant.product_name} — ${variant.variant_name}`;
  feedback.className = 'small text-success mt-1';
  input.value = '';
  input.focus();
}

/** Name matches for what was typed, shown as a pick-list under the input. */
async function searchByName(text, input, feedback, resultsBox) {
  if (text.length < 2) { resultsBox.innerHTML = ''; return []; }

  const matches = (await api.get('/admin/inventory/search', { q: text })).data || [];

  if (matches.length === 0) {
    resultsBox.innerHTML = '<div class="list-group-item small text-muted">No item matches that name, SKU or barcode.</div>';
    return matches;
  }

  // Two entries with the same product name and pack weight are almost always
  // a data-entry mistake (the pack was created twice under two products), so
  // it's flagged here rather than left to look like a real choice.
  const seen = {};
  matches.forEach((m) => {
    const key = `${m.product_name}|${m.weight_grams}`;
    seen[key] = (seen[key] || 0) + 1;
  });

  // A plain list-group-item wrapping the "add" button, not a button itself —
  // the duplicate warning below needs a real link in it, and a link nested
  // inside a button double-fires (navigates AND adds the line) when clicked.
  resultsBox.innerHTML = matches.map((m, i) => {
    const isDuplicate = seen[`${m.product_name}|${m.weight_grams}`] > 1;

    return `
    <div class="list-group-item p-0">
      <button type="button" class="btn btn-link text-start text-decoration-none w-100 px-3 py-2" data-result="${i}">
        <span class="fw-semibold">${escapeHtml(m.product_name)}</span>
        <span class="text-muted small ms-1">${escapeHtml(m.variant_name)} · ${escapeHtml(m.sku)}</span>
        ${m.product_status === 'published'
          ? '<span class="badge text-bg-success ms-1">Published</span>'
          : `<span class="badge text-bg-secondary ms-1">${escapeHtml(m.product_status === 'archived' ? 'Unpublished' : 'Draft')}</span>`}
      </button>
      ${isDuplicate ? `
        <div class="small text-danger px-3 pb-2">
          Another pack size shares this name and weight — confirm the SKU before adding it.
          <a href="products.html?edit=${encodeURIComponent(m.product_uuid)}" target="_blank" rel="noopener">Edit this product →</a>
        </div>` : ''}
    </div>`;
  }).join('');

  resultsBox.querySelectorAll('[data-result]').forEach((button) => {
    button.addEventListener('click', () => {
      addVariantLine(matches[Number(button.dataset.result)], input, feedback);
      resultsBox.innerHTML = '';
    });
  });

  return matches;
}

/** Exact SKU/barcode first; if nothing has that code, treat the text as an item name. */
async function lookupSku(input, feedback, resultsBox) {
  const text = input.value.trim();
  if (!text) return;

  feedback.textContent = 'Looking up…';
  feedback.className = 'small text-muted mt-1';

  try {
    const response = await api.get('/admin/inventory/lookup', { sku: text });
    resultsBox.innerHTML = '';
    addVariantLine(response.data, input, feedback);
    return;
  } catch { /* not a SKU or barcode — try it as a name */ }

  try {
    const matches = await searchByName(text, input, feedback, resultsBox);

    if (matches.length === 1) {
      addVariantLine(matches[0], input, feedback);
      resultsBox.innerHTML = '';
    } else if (matches.length > 1) {
      feedback.textContent = 'Several items match — pick one from the list.';
      feedback.className = 'small text-muted mt-1';
    } else {
      feedback.textContent = 'No item has that name, SKU or barcode.';
      feedback.className = 'small text-danger mt-1';
    }
  } catch (error) {
    feedback.textContent = error.message || 'Could not look that up.';
    feedback.className = 'small text-danger mt-1';
  }
}

function renderNewItemForm(container) {
  const host = container.querySelector('[data-new-item-host]');
  renderNewItem(host, { api, setup: inwardSetup, addLine, escapeHtml, setBusy, showError, toast });
}

function renderCsvUpload(container) {
  renderBillUpload(container.querySelector('[data-csv-host]'), { api, setup: inwardSetup, addLine, escapeHtml, formatMoney, setBusy, showError, toast });
}

function renderNewVendorForm(container) {
  const host = container.querySelector('[data-new-vendor-host]');

  host.innerHTML = `
    <div class="card border-info mt-2 mb-3">
      <div class="card-body">
        <h3 class="h6">New vendor</h3>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small mb-0">Name</label>
            <input class="form-control form-control-sm" data-new-vendor-field="name" maxlength="150" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">GSTIN</label>
            <input class="form-control form-control-sm" data-new-vendor-field="gstin" maxlength="20">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">Mobile</label>
            <input class="form-control form-control-sm" data-new-vendor-field="phone" maxlength="15">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">Address</label>
            <input class="form-control form-control-sm" data-new-vendor-field="address_line1">
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">City</label>
            <input class="form-control form-control-sm" data-new-vendor-field="city">
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">State</label>
            <input class="form-control form-control-sm" data-new-vendor-field="state">
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">Pincode</label>
            <input class="form-control form-control-sm" data-new-vendor-field="pincode">
          </div>
        </div>
        <div data-new-vendor-feedback class="small mt-2">&nbsp;</div>
        <div class="d-flex gap-2 mt-2">
          <button class="btn btn-sm btn-info" type="button" data-create-vendor>Save vendor</button>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-cancel-new-vendor>Cancel</button>
        </div>
      </div>
    </div>`;

  host.querySelector('[data-cancel-new-vendor]').addEventListener('click', () => { host.innerHTML = ''; });

  host.querySelector('[data-create-vendor]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const feedback = host.querySelector('[data-new-vendor-feedback]');
    const data = {};
    host.querySelectorAll('[data-new-vendor-field]').forEach((field) => { data[field.dataset.newVendorField] = field.value || null; });

    if (!data.name) {
      feedback.textContent = 'A vendor name is required.';
      feedback.className = 'small text-danger mt-2';
      return;
    }

    setBusy(button, true, 'Saving');

    try {
      const response = await api.post('/admin/vendors', data);
      const vendor = response.data;
      vendors = [...vendors, vendor];

      const select = container.querySelector('#vendor_uuid');
      select.innerHTML = `<option value="">Select a vendor…</option>${vendorOptions(vendor.uuid)}`;

      toast('Vendor saved and selected.');
      host.innerHTML = '';
    } catch (error) {
      setBusy(button, false);
      showError(error, host);
    }
  });
}

async function renderRecordTab(container) {
  lines = [];

  container.innerHTML = `
    <form data-po-form>
      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.building)}Vendor &amp; order</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="vendor_uuid">Vendor</label>
              <select class="form-select" id="vendor_uuid" name="vendor_uuid" required>
                <option value="">Select a vendor…</option>${vendorOptions()}
              </select>
              <button class="btn btn-sm btn-link p-0 mt-1" type="button" data-toggle-new-vendor>+ New vendor</button>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="warehouse_uuid">Warehouse</label>
              <select class="form-select" id="warehouse_uuid" name="warehouse_uuid" required>${warehouseOptions()}</select>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="purchase_date">Purchase date</label>
              <input class="form-control" id="purchase_date" name="purchase_date" type="date" required
                     value="${new Date().toISOString().slice(0, 10)}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="invoice_reference">Vendor invoice / reference</label>
              <input class="form-control" id="invoice_reference" name="invoice_reference" maxlength="80">
            </div>
          </div>
          <div data-new-vendor-host></div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.box)}Items</div>
        <div class="card-body">
          <div class="d-flex flex-wrap gap-3 align-items-end mb-2">
            <div>
              <label class="form-label">Add existing item</label>
              <div class="input-group" style="max-width:20rem">
                <input class="form-control" data-sku-input placeholder="Scan, or type a SKU / barcode / item name" autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" data-sku-lookup>Add</button>
              </div>
            </div>
            <button class="btn btn-outline-info" type="button" data-toggle-new-item>+ New item</button>
            <button class="btn btn-outline-secondary" type="button" data-toggle-csv>Upload CSV</button>
          </div>
          <div class="list-group mb-1 position-relative" data-search-results style="max-width:32rem;z-index:5"></div>
          <div data-sku-feedback class="small text-muted mb-2">&nbsp;</div>
          <div data-new-item-host></div>
          <div data-csv-host></div>

          <div class="table-responsive mt-2">
            <table class="table table-tight mb-0">
              <thead>
                <tr><th>Item</th><th>Barcode</th><th>Quantity</th><th>Invoiced qty</th><th>Unit cost</th><th>Batch</th><th>Expiry</th>
                  <th class="text-end">Line total</th><th class="text-end" data-landing-col hidden>Landing cost</th><th></th></tr>
              </thead>
              <tbody data-lines-table></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.truck)}Charges &amp; landing cost</div>
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-md-3">
              <label class="form-label" for="discount_amount">Discount (₹)</label>
              <input class="form-control" id="discount_amount" name="discount_amount" type="number" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="other_charges">Other/misc. charges (₹)</label>
              <input class="form-control" id="other_charges" name="other_charges" type="number" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="tax_enabled">
                <label class="form-check-label" for="tax_enabled">Add tax (GST) to this bill <span class="text-muted small">— optional</span></label>
              </div>
              <div class="row g-2 align-items-end" data-tax-box hidden>
                <div class="col-sm-6">
                  <label class="form-label small mb-0" for="tax_amount">Tax (₹)</label>
                  <input class="form-control" id="tax_amount" name="tax_amount" type="number" step="0.01" min="0" value="0">
                </div>
                <div class="col-sm-6">
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-tax-calc>Calculate from item GST %</button>
                </div>
                <div class="col-12 small text-muted">Tax is added to the bill total only — it does not change item landing cost.</div>
              </div>
            </div>
          </div>
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="transport_included" name="transport_included_in_cost">
            <label class="form-check-label" for="transport_included">The item cost above already includes transportation</label>
          </div>
          <div class="row g-3 mt-1" data-transport-charge-row>
            <div class="col-md-3">
              <label class="form-label" for="transport_charge">Transportation charge (₹)</label>
              <input class="form-control" id="transport_charge" name="transport_charge" type="number" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-9 d-flex align-items-end">
              <span class="small text-muted" data-transport-hint></span>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.rupee)}Payment</div>
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-md-3">
              <label class="form-label" for="amount_paid">Amount paid now (₹)</label>
              <input class="form-control" id="amount_paid" name="amount_paid" type="number" step="0.01" min="0" value="0">
            </div>
            <div class="col-auto">
              <span class="badge text-bg-secondary" data-payment-pill>Unpaid</span>
            </div>
          </div>
          <p class="small text-muted mt-2 mb-0">Leave at 0 if payment is fully deferred — you can record it later from purchase order history.</p>
        </div>
      </div>

      <div class="d-flex justify-content-end gap-4 mb-3 small">
        <div>Items subtotal: <span class="fw-semibold" data-items-subtotal>${formatMoney(0)}</span></div>
        <div>Grand total: <span class="fw-semibold fs-6" data-grand-total>${formatMoney(0)}</span></div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="notes">Notes</label>
        <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="500"></textarea>
      </div>

      <button class="btn btn-dark" type="submit">Record purchase</button>
    </form>`;

  renderLinesTable();

  const skuInput = container.querySelector('[data-sku-input]');
  const skuFeedback = container.querySelector('[data-sku-feedback]');
  const skuResults = container.querySelector('[data-search-results]');
  let searchTimer = null;

  container.querySelector('[data-sku-lookup]').addEventListener('click', () => lookupSku(skuInput, skuFeedback, skuResults));
  skuInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      clearTimeout(searchTimer);
      lookupSku(skuInput, skuFeedback, skuResults);
    }
  });
  skuInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const text = skuInput.value.trim();

    if (text.length < 2) { skuResults.innerHTML = ''; return; }

    // A search hiccup must not interrupt typing — Enter still does the exact lookup.
    searchTimer = setTimeout(() => searchByName(text, skuInput, skuFeedback, skuResults).catch(() => {}), 300);
  });

  container.querySelector('[data-toggle-new-vendor]').addEventListener('click', () => renderNewVendorForm(container));
  container.querySelector('[data-toggle-new-item]').addEventListener('click', () => renderNewItemForm(container));
  container.querySelector('[data-toggle-csv]').addEventListener('click', () => renderCsvUpload(container));

  // Arrived here via "Create Purchase" on a low-stock alert — the item and
  // its warehouse are already known, so the form starts with the line
  // already added instead of asking the vendor to look it up again.
  const prefillSku = queryParam('sku');
  const prefillWarehouse = queryParam('warehouse');

  if (prefillWarehouse) {
    const warehouseSelect = container.querySelector('#warehouse_uuid');
    if (warehouseSelect && warehouseSelect.querySelector(`option[value="${CSS.escape(prefillWarehouse)}"]`)) {
      warehouseSelect.value = prefillWarehouse;
    }
  }

  if (prefillSku) {
    skuInput.value = prefillSku;
    lookupSku(skuInput, skuFeedback, skuResults);
  }

  const form = container.querySelector('[data-po-form]');
  const transportRow = form.querySelector('[data-transport-charge-row]');
  const transportIncluded = form.querySelector('#transport_included');

  const syncTransportVisibility = () => {
    transportRow.hidden = transportIncluded.checked;
    if (transportIncluded.checked) form.querySelector('[name=transport_charge]').value = '0';
    renderLinesTable();
  };

  transportIncluded.addEventListener('change', syncTransportVisibility);
  syncTransportVisibility();

  // updateTotals() (not renderLinesTable()) — none of these four change the
  // items table's structure, only the numbers it and the summary show, so
  // there's no reason to rebuild every row (and its inputs) on every
  // keystroke. transport_charge is the one exception: crossing zero flips
  // whether the landing-cost column exists at all, which genuinely needs
  // the fuller rebuild.
  ['discount_amount', 'other_charges', 'tax_amount', 'amount_paid'].forEach((name) => {
    form.querySelector(`[name=${name}]`).addEventListener('input', updateTotals);
  });
  form.querySelector('[name=transport_charge]').addEventListener('input', () => { renderLinesTable(); });

  const taxBox = form.querySelector('[data-tax-box]');
  const taxInput = form.querySelector('[name=tax_amount]');
  form.querySelector('#tax_enabled').addEventListener('change', (event) => {
    taxBox.hidden = !event.target.checked;
    if (!event.target.checked) taxInput.value = '0';
    updateTotals();
  });
  form.querySelector('[data-tax-calc]').addEventListener('click', () => {
    syncLinesFromDom();
    const tax = lines.reduce((sum, l) => {
      const base = (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0) - (Number(l.discount_amount) || 0);
      return sum + Math.max(0, base) * (Number(l.gst_rate) || 0) / 100;
    }, 0);
    taxInput.value = tax.toFixed(2);
    updateTotals();
    if (tax === 0) toast('No item has a GST % yet — enter it on each item, or type the tax amount.', 'warning');
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    syncLinesFromDom();

    if (lines.length === 0) {
      toast('Add at least one item first.', 'danger');
      return;
    }

    if (lines.some((l) => !l.unit_cost || Number(l.unit_cost) < 0)) {
      toast('Every item needs a unit cost.', 'danger');
      return;
    }

    const button = form.querySelector('button[type=submit]');
    const data = Object.fromEntries(new FormData(form).entries());

    setBusy(button, true, 'Saving');

    try {
      const response = await api.post('/admin/purchase-orders', {
        vendor_uuid: data.vendor_uuid,
        warehouse_uuid: data.warehouse_uuid,
        purchase_date: data.purchase_date,
        invoice_reference: data.invoice_reference || null,
        discount_amount: data.discount_amount,
        other_charges: data.other_charges,
        transport_included_in_cost: form.querySelector('#transport_included').checked,
        transport_charge: data.transport_charge,
        tax_amount: data.tax_amount,
        amount_paid: data.amount_paid,
        notes: data.notes || null,
        lines: lines.map((l) => ({
          variant_uuid: l.variant_uuid,
          quantity: l.quantity,
          invoiced_quantity: l.invoiced_quantity || null,
          unit_cost: l.unit_cost,
          batch_no: l.batch_no || null,
          expiry_date: l.expiry_date || null,
          mrp: l.mrp || null,
          selling_price: l.selling_price || null,
          gst_rate: l.gst_rate || null,
          discount_amount: l.discount_amount || null,
        })),
      });

      toast('Purchase recorded. Stock and landing cost are updated.');

      const pending = response.data.price_decisions_pending || [];
      const poId = response.data.id;

      if (pending.length > 0) {
        await resolvePriceDecisionQueue(pending, poId);
      }

      window.location.href = 'purchase-inward.html?tab=history';
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

function paymentBadge(po) {
  const tone = po.payment_status === 'paid' ? 'success' : po.payment_status === 'partial' ? 'warning' : 'secondary';
  return `<span class="badge text-bg-${tone}">${escapeHtml(po.payment_status)}</span>`;
}

function historyRow(po) {
  return `
    <tr>
      <td>
        <a href="purchase-inward.html?tab=history&uuid=${encodeURIComponent(po.uuid)}" class="fw-semibold text-decoration-none">
          ${escapeHtml(po.po_number)}
        </a>
        <div class="small text-muted">${escapeHtml(String(po.purchase_date || '').slice(0, 10))}</div>
      </td>
      <td class="small">${escapeHtml(po.vendor_name)}</td>
      <td class="small">${escapeHtml(po.warehouse_name)}</td>
      <td>${paymentBadge(po)}</td>
      <td class="text-end">${formatMoney(po.grand_total)}</td>
    </tr>`;
}

let editingItemUuid = null;

/**
 * Read-only row, or — when this line is the one being corrected — an
 * inline edit row (quantity/unit cost/batch/expiry) with Save/Cancel.
 * Editing calls PurchaseOrderService::updateItem(): this does not rewrite
 * history in place, it reverses the line's original inventory effect and
 * posts the corrected one as new movements, so stock/average-cost stay
 * consistent — see that method's own doc comment for exactly what it does
 * and does not reconstruct.
 */
function itemRow(item) {
  if (item.uuid !== editingItemUuid) {
    return `
      <tr>
        <td>
          ${escapeHtml(item.sku)}<div class="text-muted">${escapeHtml(item.variant_name)}</div>
          <div class="small">
            <span class="text-muted">${escapeHtml(item.product_name || '')}</span>
            ${item.product_status === 'published'
              ? '<span class="badge text-bg-success ms-1">On sale</span>'
              : `<span class="badge text-bg-secondary ms-1">${escapeHtml(item.product_status || 'draft')}</span>`}
            ${item.product_uuid ? `
              <button type="button" class="btn btn-sm btn-link p-0 ms-1 align-baseline"
                      data-toggle-product="${escapeHtml(item.product_uuid)}"
                      data-next="${item.product_status === 'published' ? 'archive' : 'publish'}">
                ${item.product_status === 'published' ? 'Unpublish' : 'Publish to shop'}
              </button>` : ''}
          </div>
          ${(item.loss_events || []).map((event) => `
            <div class="small text-danger mt-1">
              ${escapeHtml(Math.abs(Number(event.quantity_delta)))} unit(s) marked ${escapeHtml(event.movement_type)}
              on ${escapeHtml(String(event.created_date || '').slice(0, 10))} — ${escapeHtml(event.reason || 'no reason given')}
            </div>`).join('')}
          ${item.invoiced_quantity !== null && Number(item.invoiced_quantity) > Number(item.quantity) ? `
            <div class="small text-danger mt-1">
              Invoice loss: billed ${escapeHtml(item.invoiced_quantity)}, received ${escapeHtml(item.quantity)}
              — ${escapeHtml((Number(item.invoiced_quantity) - Number(item.quantity)).toFixed(3).replace(/\.?0+$/, ''))} unit(s) short
            </div>` : ''}
        </td>
        <td class="small">
          ${item.barcode
            ? `${escapeHtml(item.barcode)}<br><button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 mt-1" data-print-barcode-item="${escapeHtml(item.variant_uuid)}" data-print-title="${escapeHtml(item.sku)}" data-print-subtitle="${escapeHtml(item.variant_name)}">Print</button>`
            : `<button type="button" class="btn btn-sm btn-outline-info py-0 px-1" data-generate-barcode-item="${escapeHtml(item.variant_uuid)}">Generate</button>`}
        </td>
        <td class="text-end">${escapeHtml(Number(item.quantity))}</td>
        <td class="text-end">${formatMoney(item.unit_cost)}</td>
        <td class="text-end ${Number(item.landing_cost) !== Number(item.unit_cost) ? 'text-warning-emphasis fw-semibold' : ''}">${formatMoney(item.landing_cost)}</td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-secondary" data-start-edit="${escapeHtml(item.uuid)}">Edit</button>
          <button class="btn btn-sm btn-outline-danger" data-delete-item="${escapeHtml(item.uuid)}">Delete</button>
        </td>
      </tr>`;
  }

  const lossWarning = (item.loss_events || []).length ? `
    <div class="alert alert-warning small py-1 px-2 mb-2">
      ${escapeHtml((item.loss_events || []).reduce((sum, e) => sum + Math.abs(Number(e.quantity_delta)), 0))} unit(s) already marked damaged/lost against this line — correcting the quantity won't change that.
    </div>` : '';

  return `
    <tr data-edit-row="${escapeHtml(item.uuid)}">
      <td colspan="6">
        ${lossWarning}
        <div class="row g-2 align-items-end">
          <div class="col-6 col-lg-2">
            <label class="form-label small mb-0">Quantity</label>
            <input class="form-control form-control-sm" type="number" step="0.001" min="0.001" data-edit-quantity value="${escapeHtml(item.quantity)}">
          </div>
          <div class="col-6 col-lg-2">
            <label class="form-label small mb-0">Invoiced qty</label>
            <input class="form-control form-control-sm" type="number" step="0.001" min="0" data-edit-invoiced-quantity
                   value="${escapeHtml(item.invoiced_quantity ?? '')}" placeholder="Same" title="Fill in once you have the vendor's invoice in hand, if it differs from what arrived">
          </div>
          <div class="col-6 col-lg-2">
            <label class="form-label small mb-0">Unit cost</label>
            <input class="form-control form-control-sm" type="number" step="0.0001" min="0" data-edit-unit-cost value="${escapeHtml(item.unit_cost)}">
          </div>
          <div class="col-6 col-lg-3">
            <label class="form-label small mb-0">Batch</label>
            <input class="form-control form-control-sm" data-edit-batch maxlength="60" value="${escapeHtml(item.batch_no || '')}">
          </div>
          <div class="col-6 col-lg-2">
            <label class="form-label small mb-0">Expiry</label>
            <input class="form-control form-control-sm" type="date" data-edit-expiry value="${escapeHtml(item.expiry_date || '')}">
          </div>
          <div class="col-12 col-lg-3 d-flex gap-2">
            <button class="btn btn-sm btn-dark" data-save-edit="${escapeHtml(item.uuid)}">Save</button>
            <button class="btn btn-sm btn-outline-secondary" data-cancel-edit>Cancel</button>
          </div>
        </div>
      </td>
    </tr>`;
}

async function renderHistoryDetail(container, uuid) {
  container.innerHTML = `<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>`;

  try {
    const po = (await api.get(`/admin/purchase-orders/${encodeURIComponent(uuid)}`)).data;

    container.innerHTML = `
      <a class="small text-decoration-none" href="purchase-inward.html?tab=history">← Purchase orders</a>

      <div class="d-flex flex-wrap justify-content-between align-items-start mt-2 mb-3 gap-2">
        <div>
          <h2 class="h5 mb-1">${escapeHtml(po.po_number)}</h2>
          <span class="small text-muted">${escapeHtml(po.vendor_name)} · ${escapeHtml(po.warehouse_name)} · ${escapeHtml(String(po.purchase_date || '').slice(0, 10))}</span>
        </div>
        ${paymentBadge(po)}
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.box)}Items</div>
            <div class="table-responsive">
              <table class="table table-tight small mb-0">
                <thead><tr><th>Item</th><th>Barcode</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th><th class="text-end">Landing cost</th><th></th></tr></thead>
                <tbody>
                  ${po.items.map(itemRow).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.truck)}Charges</div>
            <div class="card-body small">
              <dl class="row mb-0">
                <dt class="col-7 fw-normal">Items subtotal</dt><dd class="col-5 text-end">${formatMoney(po.items_subtotal)}</dd>
                <dt class="col-7 fw-normal">Discount</dt><dd class="col-5 text-end">${formatMoney(po.discount_amount)}</dd>
                <dt class="col-7 fw-normal">Transportation</dt><dd class="col-5 text-end">${formatMoney(po.transport_charge)}${po.transport_percent ? ` <span class="text-muted">(${Number(po.transport_percent).toFixed(2)}%)</span>` : ''}</dd>
                <dt class="col-7 fw-normal">Other charges</dt><dd class="col-5 text-end">${formatMoney(po.other_charges)}</dd>
                <dt class="col-7 fw-normal">Tax</dt><dd class="col-5 text-end">${formatMoney(po.tax_amount)}</dd>
                <dt class="col-7 fw-semibold">Grand total</dt><dd class="col-5 text-end fw-semibold">${formatMoney(po.grand_total)}</dd>
              </dl>
            </div>
          </div>

          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>${headerIcon('var(--gold-dark)', ICONS.rupee)}Payments</span>
              <button class="btn btn-sm btn-outline-secondary" data-print-po type="button">Print</button>
            </div>
            <div class="card-body small">
              <dl class="row mb-2">
                <dt class="col-7 fw-normal">Paid so far</dt><dd class="col-5 text-end">${formatMoney(po.amount_paid)}</dd>
                <dt class="col-7 fw-normal">Returned</dt><dd class="col-5 text-end">${formatMoney(po.amount_returned)}</dd>
                <dt class="col-7 fw-normal">Balance due</dt><dd class="col-5 text-end">${formatMoney(Number(po.grand_total) - Number(po.amount_paid) - Number(po.amount_returned))}</dd>
              </dl>

              ${(po.payments_list || []).length ? `
                <table class="table table-sm mb-2">
                  <tbody>
                    ${po.payments_list.map((p) => `
                      <tr>
                        <td class="small">${escapeHtml(p.payment_date)}<div class="text-muted text-uppercase">${escapeHtml(p.payment_method)}${p.reference_number ? ' · ' + escapeHtml(p.reference_number) : ''}</div></td>
                        <td class="text-end">${formatMoney(p.amount)}</td>
                      </tr>`).join('')}
                  </tbody>
                </table>` : ''}

              <form class="row g-2 align-items-end" data-payment-form>
                <div class="col-6">
                  <label class="form-label small mb-0">Amount</label>
                  <input class="form-control form-control-sm" type="number" step="0.01" min="0.01" name="amount" required>
                </div>
                <div class="col-6">
                  <label class="form-label small mb-0">Method</label>
                  <select class="form-select form-select-sm" name="payment_method" required>
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="pos">POS</option>
                  </select>
                </div>
                <div class="col-6">
                  <label class="form-label small mb-0">Date</label>
                  <input class="form-control form-control-sm" type="date" name="payment_date" value="${new Date().toISOString().slice(0, 10)}" required>
                </div>
                <div class="col-6">
                  <label class="form-label small mb-0">Reference</label>
                  <input class="form-control form-control-sm" name="reference_number" maxlength="100" placeholder="Optional">
                </div>
                <div class="col-12">
                  <button class="btn btn-sm btn-dark w-100" type="submit">Record payment</button>
                </div>
              </form>
            </div>
          </div>

          <div class="card">
            <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.box)}Returns</div>
            <div class="card-body small">
              ${(po.returns_list || []).length ? `
                <table class="table table-sm mb-2">
                  <tbody>
                    ${po.returns_list.map((r) => `
                      <tr>
                        <td class="small">${escapeHtml(r.return_number)}<div class="text-muted">${escapeHtml(r.reason)}</div></td>
                        <td class="text-end">${formatMoney(r.total_amount)}</td>
                      </tr>`).join('')}
                  </tbody>
                </table>` : '<p class="text-muted mb-2">No returns recorded.</p>'}
              <button class="btn btn-sm btn-outline-secondary w-100" data-open-return type="button">Return items to vendor</button>
            </div>
          </div>
        </div>
      </div>
      <div data-return-form-host></div>`;

    container.querySelector('[data-payment-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type=submit]');
      const data = Object.fromEntries(new FormData(form).entries());
      setBusy(button, true, 'Saving');

      try {
        await api.post(`/admin/purchase-orders/${encodeURIComponent(uuid)}/payments`, data);
        toast('Payment recorded.');
        renderHistoryDetail(container, uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    container.querySelector('[data-print-po]').addEventListener('click', () => printPurchaseOrder(po));

    container.querySelector('[data-open-return]').addEventListener('click', () => {
      renderReturnForm(container.querySelector('[data-return-form-host]'), po, uuid, container);
    });

    container.querySelectorAll('[data-start-edit]').forEach((button) => {
      button.addEventListener('click', () => {
        editingItemUuid = button.dataset.startEdit;
        renderHistoryDetail(container, uuid);
      });
    });

    container.querySelectorAll('[data-toggle-product]').forEach((button) => {
      button.addEventListener('click', async () => {
        const productUuid = button.dataset.toggleProduct;
        const action = button.dataset.next; // 'publish' or 'archive'

        setBusy(button, true, action === 'publish' ? 'Publishing' : 'Unpublishing');

        try {
          await api.post(`/admin/products/${encodeURIComponent(productUuid)}/${action}`);
          toast(action === 'publish' ? 'Now on sale — visible on the shop.' : 'Taken off the shop.');
          renderHistoryDetail(container, uuid);
        } catch (error) {
          setBusy(button, false);
          // publish() can refuse (no image, no short description, no pack size)
          // — the goal of this button, but not always achievable from here alone.
          showError(error);
        }
      });
    });

    container.querySelectorAll('[data-delete-item]').forEach((button) => {
      button.addEventListener('click', async () => {
        const itemUuid = button.dataset.deleteItem;
        const item = po.items.find((i) => i.uuid === itemUuid);

        if (!window.confirm(
          `Delete ${item ? item.sku + ' — ' + item.variant_name : 'this line'} from ${po.po_number}? ` +
          'The stock it brought in will be reversed. This cannot be undone.'
        )) return;

        setBusy(button, true, 'Deleting');

        try {
          await api.delete(`/admin/purchase-orders/${encodeURIComponent(uuid)}/items/${encodeURIComponent(itemUuid)}`);
          toast('Line deleted. Stock has been reversed.');
          renderHistoryDetail(container, uuid);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    container.querySelectorAll('[data-print-barcode-item]').forEach((button) => {
      button.addEventListener('click', () => {
        const item = po.items.find((i) => i.variant_uuid === button.dataset.printBarcodeItem);
        if (!item) return;

        printBarcodeLabels([{
          barcode: item.barcode,
          title: button.dataset.printTitle,
          subtitle: button.dataset.printSubtitle,
        }]);
      });
    });

    container.querySelectorAll('[data-generate-barcode-item]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Generating');

        try {
          await api.post(`/admin/inventory/variants/${encodeURIComponent(button.dataset.generateBarcodeItem)}/barcode`, {});
          renderHistoryDetail(container, uuid);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    const cancelButton = container.querySelector('[data-cancel-edit]');
    if (cancelButton) {
      cancelButton.addEventListener('click', () => {
        editingItemUuid = null;
        renderHistoryDetail(container, uuid);
      });
    }

    const saveButton = container.querySelector('[data-save-edit]');
    if (saveButton) {
      saveButton.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const itemUuid = button.dataset.saveEdit;
        const row = container.querySelector(`[data-edit-row="${itemUuid}"]`);

        const quantity = row.querySelector('[data-edit-quantity]').value;
        const invoicedQuantity = row.querySelector('[data-edit-invoiced-quantity]').value;
        const unitCost = row.querySelector('[data-edit-unit-cost]').value;
        const batchNo = row.querySelector('[data-edit-batch]').value;
        const expiryDate = row.querySelector('[data-edit-expiry]').value;

        setBusy(button, true, 'Saving');

        try {
          await api.patch(`/admin/purchase-orders/${encodeURIComponent(uuid)}/items/${encodeURIComponent(itemUuid)}`, {
            quantity,
            invoiced_quantity: invoicedQuantity || null,
            unit_cost: unitCost,
            batch_no: batchNo || null,
            expiry_date: expiryDate || null,
          });
          toast('Line corrected.');
          editingItemUuid = null;
          renderHistoryDetail(container, uuid);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    }
  } catch (error) {
    container.innerHTML = '<a class="small" href="purchase-inward.html?tab=history">← Purchase orders</a>';
    showError(error, container);
  }
}

async function renderHistoryTab(container) {
  const uuid = queryParam('uuid');

  if (uuid) {
    await renderHistoryDetail(container, uuid);
    return;
  }

  container.innerHTML = `
    <form class="row row-cols-lg-auto g-2 align-items-center mb-3" data-filter-form>
      <div class="col-12"><select class="form-select form-select-sm" name="vendor_uuid">
        <option value="">All vendors</option>${vendorOptions(state.history.vendor_uuid)}
      </select></div>
      <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div>
    </form>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    state.history.vendor_uuid = new FormData(event.currentTarget).get('vendor_uuid') || '';
    state.history.page = 1;
    renderHistoryTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/purchase-orders', { ...state.history, per_page: 30, direction: 'DESC' });
    const rows = response.data || [];

    if (rows.length === 0) {
      list.innerHTML = emptyState('No purchases recorded yet', 'Use the Record inward tab to log your first purchase.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>PO</th><th>Vendor</th><th>Warehouse</th><th>Payment</th><th class="text-end">Total</th></tr></thead>
          <tbody>${rows.map(historyRow).join('')}</tbody>
        </table>
      </div>
      ${(response.meta && response.meta.total_pages > 1) ? `
        <div class="p-3 border-top d-flex justify-content-between align-items-center">
          <span class="small text-muted">Page ${escapeHtml(response.meta.page)} of ${escapeHtml(response.meta.total_pages)}</span>
          <span>
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.history.page -= 1; renderHistoryTab(container); });
    if (next) next.addEventListener('click', () => { state.history.page += 1; renderHistoryTab(container); });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function renderTabs() {
  return `
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link ${state.tab === 'record' ? 'active' : ''}" href="purchase-inward.html?tab=record">Record inward</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'history' ? 'active' : ''}" href="purchase-inward.html?tab=history">History</a></li>
    </ul>`;
}

async function render() {
  root.innerHTML = `<h1 class="h4 mb-3">Purchase Inward</h1>${renderTabs()}<div data-tab-body></div>`;
  const body = root.querySelector('[data-tab-body]');

  if (state.tab === 'history') {
    await renderHistoryTab(body);
  } else {
    await renderRecordTab(body);
  }
}

const mounted = await mountConsole('purchase-inward.html');

if (mounted) {
  root = mounted.root;

  try {
    const [vendorResponse, warehouseResponse, categoryResponse] = await Promise.all([
      api.get('/admin/vendors', { per_page: 200, active_only: true }),
      api.get('/admin/warehouses', { active_only: true }),
      api.get('/admin/inventory/setup').catch(() => ({ data: { categories: [], option_types: [] } })),
    ]);
    vendors = vendorResponse.data || [];
    warehouses = warehouseResponse.data || [];
    inwardSetup = categoryResponse.data || inwardSetup;
    render();
  } catch (error) {
    showError(error, root);
  }
}
