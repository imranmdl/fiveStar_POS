/**
 * Profit & Loss: item-wise margins, invoice loss and damage/loss, over a
 * date range.
 *
 * Three different kinds of "loss" live on this page, and they are kept
 * visually separate on purpose:
 *   - A negative margin on an item (sold for less than it cost) — ordinary
 *     trading loss, the item-wise table's own worst-first ordering surfaces
 *     it without scrolling.
 *   - Invoice loss — money paid for stock a vendor's invoice claimed but
 *     that never actually arrived.
 *   - Damage & loss — stock that DID arrive but was later damaged, lost or
 *     written off (see Inventory's own Damage & Loss tab for the same data).
 * Neither of the last two is a trading outcome, so neither is baked into
 * the Profit figure — each is its own tile, opened on click rather than
 * shown inline, so the page reads as one clean summary until you ask for
 * the detail behind one of them.
 *
 * Profit here is an ESTIMATE (current average cost x units sold, not a
 * historical cost snapshot per sale) — the same approximation the
 * Dashboard's Profit figure makes, for the same reason: this system
 * snapshots cost at inward, not per sale. Opening/closing stock make the
 * same estimate for the same reason — quantity is reconstructed from the
 * movement ledger, but priced at today's average cost, not what it
 * actually was on that date (never recorded day by day).
 */

import { api, mountConsole, showError, escapeHtml, formatMoney, iconStatCard, badge, emptyState } from './console.js?v=9';

const PL_ICONS = {
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  cost: '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
  profit: '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
  net: '<path d="m5 13 4 4L19 7"/>',
  box: '<path d="M4 7l8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
};

let root = null;
let days = 30;

function money(value) {
  return formatMoney(Number(value) || 0);
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

function openModal(title, bodyHtml) {
  const host = modalHost('data-pl-modal');
  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">${escapeHtml(title)}</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">${bodyHtml}</div>
        </div>
      </div>
    </div>`;

  new window.bootstrap.Modal(host.querySelector('[data-modal]')).show();
}

function itemRow(item) {
  const profit = Number(item.profit) || 0;
  const toneClass = profit < 0 ? 'text-danger' : (profit > 0 ? 'text-success' : '');

  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(item.sku)}</span>
        <div class="small text-muted">${escapeHtml(item.product_name)} — ${escapeHtml(item.variant_name)}</div>
      </td>
      <td class="text-end">${escapeHtml(item.units_sold)}</td>
      <td class="text-end">${money(item.revenue)}</td>
      <td class="text-end text-muted">${money(item.cost)}</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-link p-0 fw-semibold ${toneClass}" data-item-profit="${escapeHtml(item.variant_uuid)}"
                data-item-label="${escapeHtml(item.product_name)} — ${escapeHtml(item.variant_name)}">
          ${money(item.profit)}
        </button>
      </td>
      <td class="text-end ${toneClass}">${escapeHtml(item.margin_percent)}%</td>
    </tr>`;
}

function itemsTable(items) {
  if (!items || items.length === 0) {
    return emptyState('No sales in this period', 'Try a longer range.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr>
            <th>Item</th>
            <th class="text-end">Units sold</th>
            <th class="text-end">Revenue</th>
            <th class="text-end">Cost (est.)</th>
            <th class="text-end">Profit</th>
            <th class="text-end">Margin</th>
          </tr>
        </thead>
        <tbody>${items.map(itemRow).join('')}</tbody>
      </table>
    </div>`;
}

function invoiceLossRow(row) {
  return `
    <tr>
      <td class="small">${escapeHtml(String(row.date || '').slice(0, 10))}</td>
      <td>
        <span class="fw-semibold">${escapeHtml(row.sku)}</span>
        <div class="small text-muted">${escapeHtml(row.variant_name)} · ${escapeHtml(row.warehouse_name)}</div>
      </td>
      <td class="small">
        ${escapeHtml(row.vendor_name)}
        <div class="text-muted">${escapeHtml(row.po_number)}${row.invoice_reference ? ` · invoice ${escapeHtml(row.invoice_reference)}` : ''}</div>
      </td>
      <td class="text-end small">${escapeHtml(row.invoiced_quantity)} invoiced, ${escapeHtml(row.received_quantity)} received</td>
      <td class="text-end text-danger fw-semibold">${escapeHtml(row.short_quantity)} short</td>
      <td class="text-end">${row.value !== null ? formatMoney(row.value) : '—'}</td>
    </tr>`;
}

function invoiceLossTable(invoiceLoss) {
  if (!invoiceLoss.rows || invoiceLoss.rows.length === 0) {
    return emptyState('No invoice loss in this period', 'Every purchase order line received matched what the vendor invoiced.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr><th>Date</th><th>Item</th><th>Vendor / PO</th>
            <th class="text-end">Invoiced vs received</th><th class="text-end">Short</th><th class="text-end">Value</th></tr>
        </thead>
        <tbody>${invoiceLoss.rows.map(invoiceLossRow).join('')}</tbody>
      </table>
    </div>`;
}

function damageLossRow(row) {
  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td>
        <span class="fw-semibold">${escapeHtml(row.sku)}</span>
        <div class="small text-muted">${escapeHtml(row.variant_name)} · ${escapeHtml(row.warehouse_name)}</div>
      </td>
      <td>${badge(row.movement_type, row.movement_type)}</td>
      <td class="text-end">${escapeHtml(row.quantity)}</td>
      <td class="text-end small">${row.line_value !== null ? formatMoney(row.line_value) + (row.value_source === 'current_average_cost' ? ' *' : '') : '—'}</td>
      <td class="small">${escapeHtml(row.reason || '—')}</td>
    </tr>`;
}

function damageLossTable(damageLoss) {
  if (!damageLoss.rows || damageLoss.rows.length === 0) {
    return emptyState('No damage or loss recorded', 'Nothing was written off in this period.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr><th>When</th><th>Item</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Value</th><th>Reason</th></tr>
        </thead>
        <tbody>${damageLoss.rows.map(damageLossRow).join('')}</tbody>
      </table>
    </div>
    <p class="small text-muted p-3 mb-0 border-top">
      * Value estimated from the item's current average cost. See Inventory &gt; Damage &amp; loss for the full tool (filters, Adjust action).
    </p>`;
}

function invoiceHistoryRow(row) {
  const isShort = row.invoiced_quantity !== null && Number(row.invoiced_quantity) > Number(row.quantity);

  return `
    <tr>
      <td class="small">${escapeHtml(String(row.purchase_date || '').slice(0, 10))}</td>
      <td class="small">
        ${escapeHtml(row.vendor_name)}
        <div class="text-muted">${escapeHtml(row.po_number)}${row.invoice_reference ? ` · invoice ${escapeHtml(row.invoice_reference)}` : ' · no invoice reference on file'}</div>
      </td>
      <td class="text-end small">${escapeHtml(row.quantity)}${isShort ? ` <span class="text-danger">(${escapeHtml(row.invoiced_quantity)} invoiced)</span>` : ''}</td>
      <td class="text-end small">${formatMoney(row.unit_cost)}</td>
      <td class="text-end small">${formatMoney(row.landing_cost)}</td>
      <td class="small">${row.batch_no ? escapeHtml(row.batch_no) : '<span class="text-muted">—</span>'}</td>
    </tr>`;
}

function invoiceHistoryTable(rows) {
  if (!rows || rows.length === 0) {
    return emptyState('No purchase history', 'This item has never been received on a purchase order.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr><th>Date</th><th>Vendor / invoice</th><th class="text-end">Qty received</th>
            <th class="text-end">Unit cost</th><th class="text-end">Landing cost</th><th>Batch</th></tr>
        </thead>
        <tbody>${rows.map(invoiceHistoryRow).join('')}</tbody>
      </table>
    </div>
    <p class="small text-muted p-3 mb-0 border-top">
      Landing cost includes this purchase order's share of transportation — it's what actually
      set this item's average cost, not the raw vendor price.
    </p>`;
}

function invoiceRow(po) {
  return `
    <tr data-invoice-row="${escapeHtml(po.uuid)}" role="button">
      <td class="small">${escapeHtml(String(po.purchase_date || '').slice(0, 10))}</td>
      <td>
        <span class="fw-semibold">${escapeHtml(po.po_number)}</span>
        <div class="small text-muted">${po.invoice_reference ? `invoice ${escapeHtml(po.invoice_reference)}` : 'no invoice reference on file'}</div>
      </td>
      <td class="small">${escapeHtml(po.vendor_name)}</td>
      <td class="small">${escapeHtml(po.warehouse_name)}</td>
      <td class="text-end">${money(po.grand_total)}</td>
    </tr>`;
}

function invoicesTable(purchaseOrders) {
  if (!purchaseOrders || purchaseOrders.length === 0) {
    return emptyState('No purchases in this period', 'Nothing was recorded on Purchase Inward in this range.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr><th>Date</th><th>Invoice</th><th>Vendor</th><th>Warehouse</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>${purchaseOrders.map(invoiceRow).join('')}</tbody>
      </table>
    </div>
    <p class="small text-muted p-3 mb-0 border-top">Click an invoice to see the profit &amp; loss of the items on it.</p>`;
}

function poLineProfitRow(line, items) {
  const match = items.find((item) => item.variant_uuid === line.variant_uuid);
  const profit = match ? Number(match.profit) : null;
  const toneClass = profit === null ? 'text-muted' : (profit < 0 ? 'text-danger' : (profit > 0 ? 'text-success' : ''));

  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(line.sku)}</span>
        <div class="small text-muted">${escapeHtml(line.variant_name)}</div>
      </td>
      <td class="text-end small">${escapeHtml(line.quantity)} @ ${formatMoney(line.unit_cost)}</td>
      <td class="text-end small">${match ? escapeHtml(match.units_sold) : '—'}</td>
      <td class="text-end small">${match ? money(match.revenue) : '—'}</td>
      <td class="text-end fw-semibold ${toneClass}">${match ? money(match.profit) : 'Not sold in this period'}</td>
      <td class="text-end ${toneClass}">${match ? `${escapeHtml(match.margin_percent)}%` : '—'}</td>
    </tr>`;
}

function poProfitLossTable(po, items) {
  return `
    <p class="small text-muted">
      Profit &amp; loss for each item on this invoice, across the WHOLE selected period — not just
      the units this one delivery brought in, since sales aren't tracked back to a specific
      purchase batch. "Not sold in this period" means none of this item sold in the current range,
      even though it was bought on this invoice.
    </p>
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr><th>Item</th><th class="text-end">Bought (this invoice)</th><th class="text-end">Units sold</th>
            <th class="text-end">Revenue</th><th class="text-end">Profit</th><th class="text-end">Margin</th></tr>
        </thead>
        <tbody>${po.items.map((line) => poLineProfitRow(line, items)).join('')}</tbody>
      </table>
    </div>`;
}

function vendorRow(vendor) {
  const rate = Number(vendor.loss_rate_percent) || 0;
  const toneClass = rate > 10 ? 'text-danger fw-semibold' : (rate > 0 ? 'text-warning-emphasis' : 'text-muted');

  return `
    <tr>
      <td class="fw-semibold">${escapeHtml(vendor.vendor_name)}</td>
      <td class="text-end">${escapeHtml(vendor.po_count)}</td>
      <td class="text-end">${money(vendor.total_value)}</td>
      <td class="text-end small">${vendor.invoice_loss_count ? `${money(vendor.invoice_loss_value)} (${escapeHtml(vendor.invoice_loss_count)})` : '—'}</td>
      <td class="text-end small">${vendor.damage_loss_count ? `${money(vendor.damage_loss_value)} (${escapeHtml(vendor.damage_loss_count)})` : '—'}</td>
      <td class="text-end ${toneClass}">${escapeHtml(vendor.loss_rate_percent)}%</td>
    </tr>`;
}

function vendorReliabilityTable(vendors) {
  if (!vendors || vendors.length === 0) {
    return emptyState('No purchases in this period', 'Nothing was bought from any vendor in this range.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr><th>Vendor</th><th class="text-end">POs</th><th class="text-end">Purchased</th>
            <th class="text-end">Invoice loss</th><th class="text-end">Damage/loss</th><th class="text-end">Loss rate</th></tr>
        </thead>
        <tbody>${vendors.map(vendorRow).join('')}</tbody>
      </table>
    </div>
    <p class="small text-muted p-3 mb-0 border-top">
      Loss rate = (invoice loss + traced damage/loss) &divide; total purchased from that vendor.
      Damage/loss only counts when it could be traced back to a specific purchase order — an
      untraceable incident (no batch number recorded) won't show up against any vendor here.
    </p>`;
}

/** A stat card that's also a button — used for the two loss figures that open a detail popup instead of showing inline. */
function clickableStatCard(label, value, hint, tone, action) {
  return `
    <div class="col">
      <button type="button" class="btn btn-outline-${tone || 'secondary'} w-100 h-100 text-start p-3" data-pl-action="${escapeHtml(action)}">
        <div class="small ${tone ? '' : 'text-muted'}">${escapeHtml(label)}</div>
        <div class="fs-4 fw-semibold">${value}</div>
        ${hint ? `<div class="small ${tone ? '' : 'text-muted'}">${escapeHtml(hint)}</div>` : ''}
      </button>
    </div>`;
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Profit &amp; Loss</h1>
      <div class="d-flex flex-wrap gap-2">
        <div class="btn-group btn-group-sm">
          ${[[1, 'Today'], [7, 'Last 7 days'], [30, 'Last 30 days'], [90, 'Last 90 days']].map(([value, label]) => `
            <button type="button" class="btn ${days === value ? 'btn-dark' : 'btn-outline-secondary'}"
                    data-days="${value}">${escapeHtml(label)}</button>`).join('')}
        </div>
        <button type="button" class="btn btn-sm btn-outline-dark" data-view-items>View item-wise profit &amp; loss</button>
      </div>
    </div>
    <div data-panel><div class="text-center py-5 text-muted"><div class="spinner-border"></div></div></div>`;

  root.querySelectorAll('[data-days]').forEach((button) => {
    button.addEventListener('click', () => { days = Number(button.dataset.days); render(); });
  });

  const panel = root.querySelector('[data-panel]');

  const to = new Date();
  const from = new Date(to.getTime() - (days - 1) * 86400000);
  const iso = (date) => date.toISOString().slice(0, 10);
  const rangeLabel = days === 1 ? 'today' : `last ${days} days`;

  // Wires the "click a Profit figure for its purchase invoices" behaviour
  // inside whatever container currently holds the item-wise table — that
  // table only ever appears inside the on-demand modal now, so this is
  // called after opening it rather than once at page load.
  const wireItemProfitButtons = (container) => {
    container.querySelectorAll('[data-item-profit]').forEach((button) => {
      button.addEventListener('click', async () => {
        const variantUuid = button.dataset.itemProfit;
        const label = button.dataset.itemLabel;

        openModal(`Purchase invoices — ${label}`, '<div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>');
        const body = document.querySelector('[data-pl-modal] .modal-body');

        try {
          const response = await api.get(`/admin/inventory/stock/${encodeURIComponent(variantUuid)}`);
          body.innerHTML = invoiceHistoryTable(response.data.purchase_history || []);
        } catch (error) {
          showError(error, body);
        }
      });
    });
  };

  try {
    const [plResponse, poResponse] = await Promise.all([
      api.get('/admin/reports/profit-loss', { from: iso(from), to: iso(to) }),
      api.get('/admin/purchase-orders', { from: iso(from), to: iso(to), per_page: 100, direction: 'DESC' }),
    ]);
    const { items, invoice_loss: invoiceLoss, damage_loss: damageLoss, vendor_reliability: vendorReliability, summary } = plResponse.data;
    const purchaseOrders = poResponse.data || [];

    panel.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
        ${iconStatCard({ tone: '#2B6E8F', iconSvgPaths: PL_ICONS.rupee, label: 'Revenue', value: money(summary.total_revenue), hint: rangeLabel })}
        ${iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: PL_ICONS.cost, label: 'Cost (est.)', value: money(summary.total_cost), hint: 'against current average cost' })}
        ${iconStatCard({
          tone: summary.total_profit < 0 ? '#A6291F' : 'var(--forest)',
          iconSvgPaths: PL_ICONS.profit,
          label: 'Profit',
          value: money(summary.total_profit),
          hint: `${escapeHtml(summary.margin_percent)}% margin`,
        })}
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: PL_ICONS.net, label: 'Net after invoice loss', value: money(summary.net_after_invoice_loss), hint: 'profit less invoice loss' })}
      </div>

      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
        ${iconStatCard({ tone: '#B5773A', iconSvgPaths: PL_ICONS.box, label: 'Opening stock', value: money(summary.opening_stock_value), hint: `as of start of ${escapeHtml(rangeLabel)}` })}
        ${iconStatCard({ tone: '#B5773A', iconSvgPaths: PL_ICONS.box, label: 'Closing stock', value: money(summary.closing_stock_value), hint: `as of end of ${escapeHtml(rangeLabel)}` })}
        ${clickableStatCard('Invoice loss', money(invoiceLoss.summary.total_value), `${invoiceLoss.summary.incident_count} shortfall(s) — click to view`, invoiceLoss.summary.total_value > 0 ? 'danger' : 'secondary', 'invoice-loss')}
        ${clickableStatCard('Damage & loss', money(damageLoss.summary.total_value), `${damageLoss.summary.incident_count} incident(s) — click to view`, damageLoss.summary.total_value > 0 ? 'danger' : 'secondary', 'damage-loss')}
      </div>

      <div class="alert alert-light border small mb-4">
        <b>Profit, cost and stock values here are estimates.</b> They price everything at
        each item's CURRENT average cost — this system doesn't snapshot cost per sale or
        per day, only at the point stock comes in. Invoice loss and Damage &amp; loss (the two
        tiles above) are exact, recorded figures — click either to see what makes it up.
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>Invoices — ${escapeHtml(rangeLabel)}</span>
          <span class="small text-muted">Click an invoice for its items' profit &amp; loss</span>
        </div>
        <div class="card-body p-0">${invoicesTable(purchaseOrders)}</div>
      </div>

      <div class="card">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>Vendor reliability — ${escapeHtml(rangeLabel)}</span>
          <span class="small text-muted">Worst loss rate first</span>
        </div>
        <div class="card-body p-0">${vendorReliabilityTable(vendorReliability)}</div>
      </div>`;

    panel.querySelector('[data-pl-action="invoice-loss"]').addEventListener('click', () => {
      openModal(`Invoice loss — ${rangeLabel}`, invoiceLossTable(invoiceLoss));
    });

    panel.querySelector('[data-pl-action="damage-loss"]').addEventListener('click', () => {
      openModal(`Damage & loss — ${rangeLabel}`, damageLossTable(damageLoss));
    });

    panel.querySelectorAll('[data-invoice-row]').forEach((row) => {
      row.addEventListener('click', async () => {
        const uuid = row.dataset.invoiceRow;

        openModal('Profit & loss — invoice', '<div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>');
        const modalHostEl = document.querySelector('[data-pl-modal]');
        const body = modalHostEl.querySelector('.modal-body');

        try {
          const po = (await api.get(`/admin/purchase-orders/${encodeURIComponent(uuid)}`)).data;
          modalHostEl.querySelector('.modal-title').textContent = `Profit & loss — ${po.po_number}`;
          body.innerHTML = poProfitLossTable(po, items);
        } catch (error) {
          showError(error, body);
        }
      });
    });

    root.querySelector('[data-view-items]').addEventListener('click', () => {
      openModal(`Item-wise profit & loss — ${rangeLabel}`, itemsTable(items));
      wireItemProfitButtons(document.querySelector('[data-pl-modal]'));
    });
  } catch (error) {
    panel.innerHTML = '';
    showError(error, panel);
  }
}

const mounted = await mountConsole('profit-loss.html');
if (mounted) { root = mounted.root; render(); }
