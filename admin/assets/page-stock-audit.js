/**
 * Stock Audit: for a date range, what was bought at what landed cost, what it
 * is priced to sell at, and where every unit went (POS counter, online,
 * damage, loss, returns) — grouped by item, category or vendor — so the owner
 * can spot a wrong data entry: a selling price below cost, a missing cost, a
 * stock figure that no longer matches the ledger.
 *
 * Opening + inward - outflows +/- adjustments = closing, all read from the
 * inventory movement ledger. Click an item to see every movement behind it,
 * with who entered it and which counter / channel it came from.
 */

import { api, mountConsole, showError, escapeHtml, formatMoney, iconStatCard, emptyState, toast } from './console.js?v=9';

const AUDIT_ICONS = {
  grid: '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
  cost: '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  till: '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"/><path d="M2 12h20"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18Z"/>',
};

let root = null;

const iso = (d) => d.toISOString().slice(0, 10);
const today = new Date();
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d; };

const state = {
  group: 'item',
  from: iso(new Date(today.getFullYear(), today.getMonth(), 1)),
  to: iso(today),
  search: '',
  category_uuid: '',
  vendor_uuid: '',
  flaggedOnly: false,
  rows: [],
  totals: null,
  categories: [],
  vendors: [],
};

const FLAG_INFO = {
  below_cost: ['danger', 'Selling below cost'],
  no_cost: ['warning', 'No cost recorded'],
  low_margin: ['warning', 'Margin under 5%'],
  over_mrp: ['danger', 'Selling above MRP'],
  negative_stock: ['danger', 'Negative stock'],
  ledger_mismatch: ['danger', 'Stock ≠ ledger'],
};

const money = (v) => (v === null || v === undefined ? '—' : formatMoney(v));
const qty = (v) => (Number.isInteger(v) ? String(v) : Number(v).toFixed(3).replace(/\.?0+$/, ''));
const signed = (v) => (v > 0 ? `+${qty(v)}` : qty(v));

function flagBadges(flags) {
  if (!flags.length) return '<span class="text-success small">OK</span>';
  return flags.map((f) => `<span class="badge text-bg-${FLAG_INFO[f][0]} me-1" title="${escapeHtml(FLAG_INFO[f][1])}">${escapeHtml(FLAG_INFO[f][1])}</span>`).join('');
}

function marginCell(row) {
  if (row.margin_percent === null) return '<span class="text-muted">—</span>';
  const tone = row.margin_percent < 0 ? 'text-danger' : row.margin_percent < 5 ? 'text-warning-emphasis' : 'text-success';
  return `<span class="${tone} fw-semibold">${row.margin_percent}%</span>`;
}

// ---------------------------------------------------------------------------

function stockColumns(row, withUnit) {
  const u = withUnit && row.unit ? ` ${escapeHtml(row.unit)}` : '';
  return `
    <td class="text-end">${qty(row.opening)}${u}</td>
    <td class="text-end text-success">${row.inward ? '+' + qty(row.inward) : '·'}</td>
    <td class="text-end">${row.pos_sales ? '−' + qty(row.pos_sales) : '·'}</td>
    <td class="text-end">${row.online_sales ? '−' + qty(row.online_sales) : '·'}</td>
    <td class="text-end">${row.damaged || row.lost ? '−' + qty(row.damaged + row.lost) : '·'}</td>
    <td class="text-end">${row.vendor_returns ? '−' + qty(row.vendor_returns) : '·'}</td>
    <td class="text-end">${(row.customer_returns + row.adjustments + row.other) ? signed(row.customer_returns + row.adjustments + row.other) : '·'}</td>
    <td class="text-end fw-semibold ${row.closing < 0 ? 'text-danger' : ''}">${qty(row.closing)}${u}</td>`;
}

const STOCK_HEAD = `
  <th class="text-end">Opening</th><th class="text-end">Inward</th>
  <th class="text-end" title="Sold at the counter">POS sold</th><th class="text-end" title="Sold online">Online sold</th>
  <th class="text-end">Damage / lost</th><th class="text-end">Back to vendor</th>
  <th class="text-end" title="Customer returns, stock adjustments, other">Returns / adj.</th><th class="text-end">Closing</th>`;

function itemTable(rows) {
  return `
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 text-nowrap">
      <thead><tr>
        <th>Item</th>
        <th class="text-end">Open</th><th class="text-end">In</th>
        <th class="text-end" title="Sold at the counter">POS</th><th class="text-end" title="Sold online">Online</th>
        <th class="text-end">Dmg/lost</th><th class="text-end">To vendor</th>
        <th class="text-end" title="Customer returns, stock adjustments, other">Ret/adj</th><th class="text-end">Close</th>
        <th class="text-end" title="Weighted average landed cost per unit">Landing</th>
        <th class="text-end" title="Selling price, with MRP below">Sell / MRP</th>
        <th class="text-end">Margin</th>
        <th class="text-end" title="Closing stock × landing cost, and × selling price">Stock @ cost / sell</th>
      </tr></thead>
      <tbody>${rows.map((r) => `
        <tr data-item="${escapeHtml(r.uuid)}" style="cursor:pointer" class="${r.flags.length ? 'table-warning' : ''}">
          <td style="min-width:16rem;white-space:normal">
            <span class="fw-semibold">${escapeHtml(r.name)}</span>
            <div class="small text-muted">${escapeHtml(r.sku)} · ${escapeHtml(r.category)}</div>
            ${r.flags.length ? `<div class="mt-1">${flagBadges(r.flags)}</div>` : ''}
          </td>
          ${stockColumns(r, true)}
          <td class="text-end">${money(r.landing_cost)}${r.last_cost !== null && r.landing_cost !== null && Math.abs(r.last_cost - r.landing_cost) > 0.005
            ? `<div class="small text-muted">last ${money(r.last_cost)}</div>` : ''}</td>
          <td class="text-end">${money(r.selling_price)}<div class="small text-muted">${money(r.mrp)}</div></td>
          <td class="text-end">${marginCell(r)}</td>
          <td class="text-end">${money(r.closing_cost_value)}<div class="small text-muted">${money(r.closing_selling_value)}</div></td>
        </tr>`).join('')}</tbody>
    </table></div>`;
}

function categoryTable(rows) {
  return `
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 text-nowrap">
      <thead><tr>
        <th>Category</th><th class="text-end">Items</th>
        <th class="text-end">Open</th><th class="text-end">In</th>
        <th class="text-end" title="Sold at the counter">POS</th><th class="text-end" title="Sold online">Online</th>
        <th class="text-end">Dmg/lost</th><th class="text-end">To vendor</th>
        <th class="text-end" title="Customer returns, stock adjustments, other">Ret/adj</th><th class="text-end">Close</th>
        <th class="text-end" title="Closing stock × landing cost, and × selling price">Stock @ cost / sell</th>
        <th class="text-end">Margin</th>
        <th class="text-end" title="Sales value in this period">Sales ₹ POS / online</th>
      </tr></thead>
      <tbody>${rows.map((r) => `
        <tr data-category="${escapeHtml(r.uuid)}" style="cursor:pointer" class="${r.flagged ? 'table-warning' : ''}">
          <td style="min-width:12rem;white-space:normal"><span class="fw-semibold">${escapeHtml(r.name)}</span>
            ${r.flagged ? `<div><span class="badge text-bg-warning">${r.flagged} item${r.flagged === 1 ? '' : 's'} to check</span></div>` : ''}</td>
          <td class="text-end">${r.items}</td>
          ${stockColumns(r, false)}
          <td class="text-end">${money(r.closing_cost_value)}<div class="small text-muted">${money(r.closing_selling_value)}</div></td>
          <td class="text-end">${marginCell(r)}</td>
          <td class="text-end">${money(r.pos_sales_value)}<div class="small text-muted">${money(r.online_sales_value)}</div></td>
        </tr>`).join('')}</tbody>
    </table></div>`;
}

function vendorTable(rows) {
  return `
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 text-nowrap">
      <thead><tr>
        <th>Vendor</th><th class="text-end">Purchases</th><th class="text-end">Items</th><th class="text-end">Qty bought</th>
        <th class="text-end" title="What the vendor billed, before transport">Billed cost</th>
        <th class="text-end" title="Including transport share">Landed cost</th>
        <th class="text-end" title="Qty × the selling price entered at inward">Selling value</th>
        <th class="text-end">MRP value</th><th class="text-end">Expected margin</th>
        <th class="text-end">Returned</th><th>Check</th>
      </tr></thead>
      <tbody>${rows.map((r) => {
        const issues = [];
        if (r.below_cost_lines) issues.push(`${r.below_cost_lines} line(s) priced below cost`);
        if (r.no_cost_lines) issues.push(`${r.no_cost_lines} line(s) with no cost`);
        return `
        <tr data-vendor="${escapeHtml(r.uuid)}" style="cursor:pointer" class="${issues.length ? 'table-warning' : ''}">
          <td class="fw-semibold">${escapeHtml(r.name)}</td>
          <td class="text-end">${r.orders}</td><td class="text-end">${r.items}</td><td class="text-end">${qty(r.quantity)}</td>
          <td class="text-end">${money(r.base_value)}</td><td class="text-end">${money(r.landed_value)}</td>
          <td class="text-end">${money(r.selling_value)}</td><td class="text-end">${money(r.mrp_value)}</td>
          <td class="text-end">${marginCell({ margin_percent: r.expected_margin_percent })}</td>
          <td class="text-end">${r.returned_quantity ? `${qty(r.returned_quantity)} (${money(r.returned_value)})` : '·'}</td>
          <td>${issues.length ? issues.map((i) => `<span class="badge text-bg-warning me-1">${escapeHtml(i)}</span>`).join('') : '<span class="text-success small">OK</span>'}</td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>`;
}

// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Card layout for phones and tablets (the wide tables are desktop-only).
// ---------------------------------------------------------------------------

const kv = (label, value, cls = '') => `
  <div class="col-4"><div class="text-muted" style="font-size:.7rem">${escapeHtml(label)}</div><div class="fw-semibold small ${cls}">${value}</div></div>`;

function stockGrid(r, withUnit) {
  const u = withUnit && r.unit ? ` ${escapeHtml(r.unit)}` : '';
  const other = r.customer_returns + r.adjustments + r.other;
  return `
    <div class="row g-2 mt-1">
      ${kv('Opening', qty(r.opening) + u)}
      ${kv('Inward', r.inward ? '+' + qty(r.inward) : '·', 'text-success')}
      ${kv('Closing', qty(r.closing) + u, r.closing < 0 ? 'text-danger' : '')}
      ${kv('POS sold', r.pos_sales ? '−' + qty(r.pos_sales) : '·')}
      ${kv('Online sold', r.online_sales ? '−' + qty(r.online_sales) : '·')}
      ${kv('Damage / lost', r.damaged || r.lost ? '−' + qty(r.damaged + r.lost) : '·')}
      ${kv('To vendor', r.vendor_returns ? '−' + qty(r.vendor_returns) : '·')}
      ${kv('Returns / adj.', other ? signed(other) : '·')}
    </div>`;
}

function itemCards(rows) {
  return rows.map((r) => `
    <div class="col"><div class="card h-100 ${r.flags.length ? 'border-warning' : ''}" data-item="${escapeHtml(r.uuid)}" style="cursor:pointer">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2">
          <div class="min-w-0"><div class="fw-semibold">${escapeHtml(r.name)}</div>
            <div class="small text-muted">${escapeHtml(r.sku)} · ${escapeHtml(r.category)}</div></div>
          <div class="text-end flex-shrink-0">${marginCell(r)}</div>
        </div>
        <div class="mt-1">${flagBadges(r.flags)}</div>
        ${stockGrid(r, true)}
        <hr class="my-2">
        <div class="row g-2">
          ${kv('Landing cost', money(r.landing_cost))}${kv('Selling', money(r.selling_price))}${kv('MRP', money(r.mrp))}
          ${kv('Stock @ cost', money(r.closing_cost_value))}${kv('Stock @ selling', money(r.closing_selling_value))}
          ${kv('Sales ₹ POS / online', `${money(r.pos_sales_value)} / ${money(r.online_sales_value)}`)}
        </div>
      </div>
    </div></div>`).join('');
}

function categoryCards(rows) {
  return rows.map((r) => `
    <div class="col"><div class="card h-100 ${r.flagged ? 'border-warning' : ''}" data-category="${escapeHtml(r.uuid)}" style="cursor:pointer">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2">
          <div><div class="fw-semibold">${escapeHtml(r.name)}</div><div class="small text-muted">${r.items} item${r.items === 1 ? '' : 's'}</div></div>
          <div class="text-end">${marginCell(r)}${r.flagged ? `<div><span class="badge text-bg-warning">${r.flagged} to check</span></div>` : ''}</div>
        </div>
        ${stockGrid(r, false)}
        <hr class="my-2">
        <div class="row g-2">
          ${kv('Stock @ cost', money(r.closing_cost_value))}${kv('Stock @ selling', money(r.closing_selling_value))}
          ${kv('POS sales ₹', money(r.pos_sales_value))}${kv('Online sales ₹', money(r.online_sales_value))}
        </div>
      </div>
    </div></div>`).join('');
}

function vendorCards(rows) {
  return rows.map((r) => {
    const issues = [];
    if (r.below_cost_lines) issues.push(`${r.below_cost_lines} line(s) priced below cost`);
    if (r.no_cost_lines) issues.push(`${r.no_cost_lines} line(s) with no cost`);
    return `
    <div class="col"><div class="card h-100 ${issues.length ? 'border-warning' : ''}" data-vendor="${escapeHtml(r.uuid)}" style="cursor:pointer">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2">
          <div><div class="fw-semibold">${escapeHtml(r.name)}</div>
            <div class="small text-muted">${r.orders} purchase${r.orders === 1 ? '' : 's'} · ${r.items} item${r.items === 1 ? '' : 's'} · qty ${qty(r.quantity)}</div></div>
          <div class="text-end">${marginCell({ margin_percent: r.expected_margin_percent })}</div>
        </div>
        ${issues.length ? `<div class="mt-1">${issues.map((i) => `<span class="badge text-bg-warning me-1">${escapeHtml(i)}</span>`).join('')}</div>` : ''}
        <div class="row g-2 mt-1">
          ${kv('Billed cost', money(r.base_value))}${kv('Landed cost', money(r.landed_value))}${kv('Selling value', money(r.selling_value))}
          ${kv('MRP value', money(r.mrp_value))}${kv('Returned', r.returned_quantity ? `${qty(r.returned_quantity)} (${money(r.returned_value)})` : '·')}
        </div>
      </div>
    </div></div>`;
  }).join('');
}

function toCsv(rows) {
  const cols = state.group === 'vendor'
    ? [['name', 'Vendor'], ['orders', 'Purchases'], ['items', 'Items'], ['quantity', 'Qty bought'], ['base_value', 'Billed cost'],
       ['landed_value', 'Landed cost'], ['selling_value', 'Selling value'], ['mrp_value', 'MRP value'],
       ['expected_margin_percent', 'Expected margin %'], ['returned_quantity', 'Returned qty'], ['returned_value', 'Returned value']]
    : [[state.group === 'item' ? 'sku' : 'uuid', state.group === 'item' ? 'SKU' : 'Id'], ['name', state.group === 'item' ? 'Item' : 'Category'],
       ...(state.group === 'item' ? [['category', 'Category']] : [['items', 'Items']]),
       ['opening', 'Opening'], ['inward', 'Inward'], ['pos_sales', 'POS sold'], ['online_sales', 'Online sold'], ['damaged', 'Damaged'], ['lost', 'Lost'],
       ['vendor_returns', 'Back to vendor'], ['customer_returns', 'Customer returns'], ['adjustments', 'Adjustments'], ['closing', 'Closing'],
       ...(state.group === 'item' ? [['landing_cost', 'Landing cost'], ['selling_price', 'Selling price'], ['mrp', 'MRP']] : []),
       ['margin_percent', 'Margin %'], ['closing_cost_value', 'Stock @ cost'], ['closing_selling_value', 'Stock @ selling'],
       ['pos_sales_value', 'POS sales value'], ['online_sales_value', 'Online sales value'],
       ...(state.group === 'item' ? [['flags', 'Checks']] : [])];
  const cell = (v) => {
    const s = Array.isArray(v) ? v.map((f) => FLAG_INFO[f][1]).join('; ') : (v ?? '');
    return `"${String(s).replace(/"/g, '""')}"`;
  };
  return [cols.map(([, h]) => cell(h)).join(','), ...rows.map((r) => cols.map(([k]) => cell(r[k])).join(','))].join('\n');
}

function visibleRows() {
  return state.flaggedOnly && state.group !== 'vendor'
    ? state.rows.filter((r) => (state.group === 'item' ? r.flags.length : r.flagged))
    : state.rows;
}

async function load() {
  const panel = root.querySelector('[data-audit-panel]');
  panel.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>';

  try {
    const params = { from: state.from, to: state.to, group: state.group };
    if (state.group === 'item') {
      if (state.search) params.search = state.search;
      if (state.vendor_uuid) params.vendor_uuid = state.vendor_uuid;
    }
    if (state.category_uuid && state.group === 'item') params.category_uuid = state.category_uuid;

    const data = (await api.get('/admin/reports/stock-audit', params)).data;
    state.rows = data.rows || [];
    state.totals = data.totals || null;
    draw();
  } catch (error) {
    panel.innerHTML = '';
    showError(error, panel);
  }
}

function draw() {
  const panel = root.querySelector('[data-audit-panel]');
  const rows = visibleRows();
  const t = state.totals;

  const stats = state.group === 'vendor' ? '' : `
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-3">
      ${iconStatCard({ tone: '#2E7D5B', iconSvgPaths: AUDIT_ICONS.grid, label: 'Items shown', value: String(t.items), hint: state.group === 'item' ? '' : 'across the categories below' })}
      ${iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: AUDIT_ICONS.cost, label: 'Stock @ landing cost', value: money(t.closing_cost_value), hint: 'closing stock' })}
      ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: AUDIT_ICONS.rupee, label: 'Stock @ selling price', value: money(t.closing_selling_value), hint: 'closing stock' })}
      ${iconStatCard({ tone: '#2B6E8F', iconSvgPaths: AUDIT_ICONS.till, label: 'Sales — counter (POS)', value: money(t.pos_sales_value), hint: 'in this period' })}
      ${iconStatCard({ tone: '#2B6E8F', iconSvgPaths: AUDIT_ICONS.globe, label: 'Sales — online', value: money(t.online_sales_value), hint: 'in this period' })}
    </div>
    ${t.flagged ? `<div class="alert alert-warning py-2 small mb-3"><b>${t.flagged}</b> item(s) need a look — highlighted below. Click any row to see every stock movement behind it.</div>` : ''}`;

  const table = state.group === 'item' ? itemTable(rows) : state.group === 'category' ? categoryTable(rows) : vendorTable(rows);
  const cards = state.group === 'item' ? itemCards(rows) : state.group === 'category' ? categoryCards(rows) : vendorCards(rows);

  const body = !rows.length
    ? `<div class="card"><div class="card-body">${emptyState('Nothing to show', 'No stock movement or purchases in this period for these filters.')}</div></div>`
    : `<div class="card d-none d-xl-block"><div class="card-body p-0">${table}</div></div><div class="d-xl-none row row-cols-1 row-cols-md-2 g-2">${cards}</div>`;

  panel.innerHTML = `${stats}${body}
    ${state.group === 'vendor' ? '<div class="small text-muted mt-2">Click a vendor to see the items bought from them.</div>' : ''}
    ${state.group === 'category' ? '<div class="small text-muted mt-2">Click a category to see its items.</div>' : ''}`;

  panel.querySelectorAll('[data-item]').forEach((tr) => tr.addEventListener('click', () => openMovements(tr.dataset.item)));
  panel.querySelectorAll('[data-category]').forEach((tr) => tr.addEventListener('click', () => {
    state.group = 'item'; state.category_uuid = tr.dataset.category; state.vendor_uuid = ''; renderShell(); load();
  }));
  panel.querySelectorAll('[data-vendor]').forEach((tr) => tr.addEventListener('click', () => {
    state.group = 'item'; state.vendor_uuid = tr.dataset.vendor; state.category_uuid = ''; renderShell(); load();
  }));
}

async function openMovements(uuid) {
  document.querySelector('[data-audit-modal]')?.remove();
  document.body.insertAdjacentHTML('beforeend', `
    <div class="modal fade" tabindex="-1" data-audit-modal><div class="modal-dialog modal-xl modal-fullscreen-lg-down modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Stock movements</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="text-center py-4 text-muted"><div class="spinner-border"></div></div></div>
    </div></div></div>`);
  const el = document.querySelector('[data-audit-modal]');
  const modal = new window.bootstrap.Modal(el);
  el.addEventListener('hidden.bs.modal', () => el.remove());
  modal.show();

  const bodyEl = el.querySelector('.modal-body');

  try {
    const data = (await api.get('/admin/reports/stock-audit/movements', { variant_uuid: uuid, from: state.from, to: state.to })).data;
    el.querySelector('.modal-title').textContent = `${data.item.name} (${data.item.sku}) — ${state.from} to ${state.to}`;
    const TYPE = { inward: 'Inward', sale: 'Sale', return: 'Return', damage: 'Damage', lost: 'Lost', adjustment: 'Adjustment', return_to_vendor: 'To vendor', transfer_in: 'Transfer in', transfer_out: 'Transfer out' };

    bodyEl.innerHTML = data.movements.length ? `
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Date</th><th>Type</th><th>Source</th><th>Counter / channel</th><th class="text-end">Qty</th><th class="text-end">Balance</th>
          <th class="text-end">Unit cost</th><th>Batch</th><th>Entered by</th><th>Reason</th></tr></thead>
        <tbody>${data.movements.map((m) => `
          <tr><td class="small text-nowrap">${escapeHtml(m.date)}</td><td>${escapeHtml(TYPE[m.type] || m.type)}</td>
            <td class="small">${escapeHtml(m.source)}</td><td class="small">${escapeHtml(m.channel || '—')}</td>
            <td class="text-end ${m.quantity_delta < 0 ? 'text-danger' : 'text-success'}">${signed(m.quantity_delta)}</td>
            <td class="text-end">${qty(m.quantity_after)}</td><td class="text-end">${m.unit_cost !== null ? money(m.unit_cost) : '—'}</td>
            <td class="small">${escapeHtml(m.batch_no || '—')}</td><td class="small">${escapeHtml(m.entered_by || '—')}</td>
            <td class="small text-muted">${escapeHtml(m.reason || '')}</td></tr>`).join('')}</tbody>
      </table></div>` : emptyState('No movements', 'Nothing was recorded for this item in the period.');
  } catch (error) {
    showError(error, bodyEl);
  }
}

// ---------------------------------------------------------------------------

function renderShell() {
  const preset = (label, from, to) => `<button type="button" class="btn btn-outline-secondary btn-sm" data-preset="${from}|${to}">${label}</button>`;
  const monthStart = iso(new Date(today.getFullYear(), today.getMonth(), 1));
  const lastMonthStart = iso(new Date(today.getFullYear(), today.getMonth() - 1, 1));
  const lastMonthEnd = iso(new Date(today.getFullYear(), today.getMonth(), 0));

  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h1 class="h3 mb-1">Stock Audit</h1>
        <p class="text-muted mb-0 small">Landing cost, selling value and every stock movement — to check that data entry is right.</p>
      </div>
      <button class="btn btn-outline-secondary btn-sm" data-export>Export CSV</button>
    </div>

    <div class="card mb-3"><div class="card-body">
      <ul class="nav nav-pills flex-nowrap overflow-auto mb-3" style="white-space:nowrap">
        ${[['item', 'By item'], ['category', 'By category'], ['vendor', 'By vendor']].map(([k, l]) => `
          <li class="nav-item"><button class="nav-link ${state.group === k ? 'active' : ''}" data-group="${k}">${l}</button></li>`).join('')}
      </ul>
      <div class="row g-2 align-items-end">
        <div class="col-6 col-lg-2"><label class="form-label small mb-0">From</label><input type="date" class="form-control form-control-sm" data-from value="${state.from}"></div>
        <div class="col-6 col-lg-2"><label class="form-label small mb-0">To</label><input type="date" class="form-control form-control-sm" data-to value="${state.to}"></div>
        <div class="col-12 col-lg-4 d-flex flex-wrap gap-1">
          ${preset('Today', iso(today), iso(today))}${preset('7 days', iso(daysAgo(6)), iso(today))}${preset('30 days', iso(daysAgo(29)), iso(today))}
          ${preset('This month', monthStart, iso(today))}${preset('Last month', lastMonthStart, lastMonthEnd)}
        </div>
        ${state.group === 'item' ? `
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Category</label>
          <select class="form-select form-select-sm" data-category-filter>
            <option value="">All</option>${state.categories.map((c) => `<option value="${escapeHtml(c.uuid)}" ${c.uuid === state.category_uuid ? 'selected' : ''}>${escapeHtml(c.label)}</option>`).join('')}
          </select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Vendor</label>
          <select class="form-select form-select-sm" data-vendor-filter>
            <option value="">All</option>${state.vendors.map((v) => `<option value="${escapeHtml(v.uuid)}" ${v.uuid === state.vendor_uuid ? 'selected' : ''}>${escapeHtml(v.name)}</option>`).join('')}
          </select></div>
        <div class="col-12 col-md-4"><input class="form-control form-control-sm" placeholder="Search item, SKU or barcode" data-search value="${escapeHtml(state.search)}"></div>` : ''}
        ${state.group !== 'vendor' ? `
        <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="flagged-only" data-flagged ${state.flaggedOnly ? 'checked' : ''}>
          <label class="form-check-label small" for="flagged-only">Only show items needing a check</label></div></div>` : ''}
      </div>
    </div></div>

    <div data-audit-panel></div>`;

  root.querySelectorAll('[data-group]').forEach((b) => b.addEventListener('click', () => {
    state.group = b.dataset.group; renderShell(); load();
  }));
  root.querySelector('[data-from]').addEventListener('change', (e) => { state.from = e.target.value; load(); });
  root.querySelector('[data-to]').addEventListener('change', (e) => { state.to = e.target.value; load(); });
  root.querySelectorAll('[data-preset]').forEach((b) => b.addEventListener('click', () => {
    [state.from, state.to] = b.dataset.preset.split('|'); renderShell(); load();
  }));
  root.querySelector('[data-category-filter]')?.addEventListener('change', (e) => { state.category_uuid = e.target.value; load(); });
  root.querySelector('[data-vendor-filter]')?.addEventListener('change', (e) => { state.vendor_uuid = e.target.value; load(); });
  root.querySelector('[data-flagged]')?.addEventListener('change', (e) => { state.flaggedOnly = e.target.checked; draw(); });

  let timer = null;
  root.querySelector('[data-search]')?.addEventListener('input', (e) => {
    state.search = e.target.value.trim();
    clearTimeout(timer);
    timer = setTimeout(load, 350);
  });

  root.querySelector('[data-export]').addEventListener('click', () => {
    const rows = visibleRows();
    if (!rows.length) { toast('Nothing to export.', 'warning'); return; }
    const blob = new Blob(['﻿' + toCsv(rows)], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `stock-audit-${state.group}-${state.from}_${state.to}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  });
}

const mounted = await mountConsole('stock-audit.html');

if (mounted) {
  root = mounted.root;

  try {
    const [setup, vendors] = await Promise.all([
      api.get('/admin/inventory/setup').catch(() => ({ data: { categories: [] } })),
      api.get('/admin/vendors', { per_page: 200 }).catch(() => ({ data: [] })),
    ]);
    const cats = setup.data.categories || [];
    const byUuid = Object.fromEntries(cats.map((c) => [c.uuid, c]));
    state.categories = cats.map((c) => ({ uuid: c.uuid, label: c.parent_uuid && byUuid[c.parent_uuid] ? `${byUuid[c.parent_uuid].name} › ${c.name}` : c.name }))
      .sort((a, b) => a.label.localeCompare(b.label));
    state.vendors = vendors.data || [];
  } catch { /* filters just stay empty */ }

  renderShell();
  load();
}
