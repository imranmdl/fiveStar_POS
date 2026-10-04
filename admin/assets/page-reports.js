/**
 * Reports: what came in, what it cost, and where it went.
 *
 * The question a shop owner asks at the end of a day is not "what is my
 * revenue" — it is "how much money actually reached me, and how much of what I
 * am holding is not mine". Tax collected and refunds owed are both in that
 * second category, so they are shown next to the takings rather than buried.
 *
 * Field names come from the API's own sales series. Every one was checked
 * against a live response before this file was written, because a report that
 * silently shows ₹0.00 for a column nobody reads is worse than no report.
 */

import { api, mountConsole, showError, escapeHtml, formatMoney,
         iconStatCard, headerIcon, badge, emptyState } from './console.js?v=9';

let root = null;

const REPORT_ICONS = {
  orders: '<path d="M4 7l8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  check: '<path d="m5 13 4 4L19 7"/>',
  cancelled: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
  wallet: '<path d="M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v3"/><path d="M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-4"/><path d="M17 12h3v3h-3a1.5 1.5 0 0 1 0-3Z"/>',
  collection: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="7.5" cy="14.5" r="1"/>',
};
let days = 30;

function money(value) {
  return formatMoney(Number(value) || 0);
}

/** Adds up a column across the series. */
function total(series, key) {
  return series.reduce((sum, row) => sum + (Number(row[key]) || 0), 0);
}

function dailyTable(series) {
  if (series.length === 0) {
    return emptyState('No sales in this period', 'Try a longer range.');
  }

  // Newest first: the day someone wants is almost always today or yesterday.
  const rows = [...series].reverse();

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th class="text-center">Orders</th>
            <th class="text-end">Taken</th>
            <th class="text-end">Of which GST</th>
            <th class="text-end">Discounts</th>
            <th class="text-end">Delivery</th>
            <th class="text-end">Refunded</th>
            <th class="text-end">Yours</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map((row) => {
            // What the shop actually keeps: money in, less the tax it is holding
            // for the government, less anything refunded.
            const kept = (Number(row.gross_sales) || 0)
              - (Number(row.tax_collected) || 0)
              - (Number(row.refunded) || 0);

            return `
              <tr>
                <td class="fw-semibold">${escapeHtml(row.date)}</td>
                <td class="text-center">${escapeHtml(row.orders)}</td>
                <td class="text-end">${money(row.gross_sales)}</td>
                <td class="text-end text-muted">${money(row.tax_collected)}</td>
                <td class="text-end text-muted">${money(row.discount_given)}</td>
                <td class="text-end text-muted">${money(row.delivery_collected)}</td>
                <td class="text-end ${Number(row.refunded) > 0 ? 'text-danger' : 'text-muted'}">
                  ${money(row.refunded)}
                </td>
                <td class="text-end fw-semibold">${money(kept)}</td>
              </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>`;
}

function pipelineTable(pipeline) {
  if (!pipeline || pipeline.length === 0) {
    return '<p class="text-muted small mb-0">No paid orders in progress.</p>';
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight mb-0">
        <thead><tr><th>Status</th><th class="text-end">Orders</th><th class="text-end">Value</th></tr></thead>
        <tbody>
          ${pipeline.map((row) => `
            <tr>
              <td>${badge(row.status, String(row.status).replace(/_/g, ' '))}</td>
              <td class="text-end">${escapeHtml(row.count)}</td>
              <td class="text-end">${money(row.value)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

function cashierTable(rows) {
  if (!rows || rows.length === 0) {
    return '<p class="text-muted small mb-0">No counter sales in this period.</p>';
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight mb-0">
        <thead><tr><th>Cashier</th><th class="text-end">Sales</th><th class="text-end">Gross</th><th class="text-end">Discounts</th><th class="text-end">Tax</th></tr></thead>
        <tbody>
          ${rows.map((row) => `
            <tr>
              <td>${escapeHtml(row.cashier_name)}</td>
              <td class="text-end">${escapeHtml(row.sale_count)}</td>
              <td class="text-end">${money(row.gross_sales)}</td>
              <td class="text-end text-muted">${money(row.discount_given)}</td>
              <td class="text-end text-muted">${money(row.tax_collected)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
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

const TODAY_DRILLDOWN_TITLE = {
  sales: "Today's orders & sales",
  delivered: 'Delivered today',
  cancelled: 'Cancelled today',
  collection: 'Taken today — every transaction',
};

function saleRowMarkup(row) {
  return `
    <tr>
      <td class="fw-semibold small">${escapeHtml(row.reference)}</td>
      <td class="small">${row.channel === 'pos' ? 'POS' : 'Online order'}</td>
      <td class="small">
        ${escapeHtml(row.customer_name || 'Walk-in customer')}
        <div class="text-muted">${escapeHtml(row.customer_mobile || '')}</div>
      </td>
      <td class="small">${badge(row.status, row.status)}</td>
      <td class="small">${escapeHtml(String(row.date || '').slice(0, 16).replace('T', ' '))}</td>
      <td class="text-end">${formatMoney(row.amount)}</td>
    </tr>`;
}

/**
 * The drill-down behind clicking one of the "today" stat tiles — same
 * GET /admin/dashboard/today/{kind} endpoint and row shape the Dashboard's
 * own drill-down uses (see page-index.js's openTodayDrilldownModal()), just
 * duplicated here rather than shared: this codebase keeps each page's own
 * card/modal functions local rather than a shared component module (see
 * page-catalog.js/page-collection.js's near-identical card functions for
 * the same pattern elsewhere).
 */
async function openTodayDrilldownModal(kind) {
  const host = modalHost('data-today-drilldown-modal');
  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">${escapeHtml(TODAY_DRILLDOWN_TITLE[kind] || kind)}</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>
          </div>
        </div>
      </div>
    </div>`;

  const modalEl = host.querySelector('[data-modal]');
  new window.bootstrap.Modal(modalEl).show();

  const body = host.querySelector('.modal-body');

  try {
    const response = await api.get(`/admin/dashboard/today/${encodeURIComponent(kind)}`);
    const items = response.data.items || [];

    body.innerHTML = items.length === 0
      ? '<p class="text-muted small mb-0">Nothing here yet today.</p>'
      : `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
          <thead><tr><th>Reference</th><th>Channel</th><th>Customer</th><th>Status</th><th>Time</th><th class="text-end">Amount</th></tr></thead>
          <tbody>${items.map(saleRowMarkup).join('')}</tbody>
        </table></div>`;
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

/** Scrolls to and briefly highlights an existing on-page card, for a stat
 * tile whose supporting detail is already shown elsewhere on this page (the
 * "Day by day" table is literally the per-day breakdown of the Cash flow
 * tiles) — no point opening a modal that would just repeat it. */
function scrollToAndFlash(selector) {
  const target = document.querySelector(selector);
  if (!target) return;

  target.scrollIntoView({ behavior: 'smooth', block: 'center' });
  target.classList.remove('flash-highlight');
  // Force reflow so the animation restarts if the tile is clicked twice in a row.
  void target.offsetWidth;
  target.classList.add('flash-highlight');
}

function paymentMethodTable(rows) {
  if (!rows || rows.length === 0) {
    return '<p class="text-muted small mb-0">No counter sales in this period.</p>';
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight mb-0">
        <thead><tr><th>Method</th><th class="text-end">Sales</th><th class="text-end">Gross</th></tr></thead>
        <tbody>
          ${rows.map((row) => `
            <tr>
              <td class="text-uppercase">${escapeHtml(row.payment_method)}</td>
              <td class="text-end">${escapeHtml(row.sale_count)}</td>
              <td class="text-end">${money(row.gross_sales)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Reports</h1>
      <div class="btn-group btn-group-sm">
        ${[[1, 'Today'], [7, 'Last 7 days'], [30, 'Last 30 days'], [90, 'Last 90 days']].map(([value, label]) => `
          <button type="button" class="btn ${days === value ? 'btn-dark' : 'btn-outline-secondary'}"
                  data-days="${value}">${escapeHtml(label)}</button>`).join('')}
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

  try {
    const [sales, dashboard, cancellations, pos] = await Promise.all([
      api.get('/admin/reports/sales', { from: iso(from), to: iso(to) }),
      api.get('/admin/dashboard'),
      api.get('/admin/reports/cancellations', { from: iso(from), to: iso(to) }),
      api.get('/admin/reports/pos', { from: iso(from), to: iso(to) }),
    ]);

    const series = sales.data.series || [];
    const today = dashboard.data.today || {};
    const refunds = cancellations.data.refunds || {};

    const taken = total(series, 'gross_sales');
    const tax = total(series, 'tax_collected');
    const refunded = total(series, 'refunded');
    const orders = total(series, 'orders');
    const kept = taken - tax - refunded;

    panel.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: REPORT_ICONS.orders, label: 'Orders today', value: escapeHtml(today.orders ?? 0), drillKey: 'sales' })}
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: REPORT_ICONS.rupee, label: 'Taken today', value: money(today.revenue), drillKey: 'collection' })}
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: REPORT_ICONS.check, label: 'Delivered today', value: escapeHtml(today.delivered ?? 0), drillKey: 'delivered' })}
        ${iconStatCard({
          tone: Number(today.cancelled) > 0 ? '#A6291F' : 'var(--muted-2)',
          iconSvgPaths: REPORT_ICONS.cancelled,
          label: 'Cancelled today',
          value: escapeHtml(today.cancelled ?? 0),
          drillKey: 'cancelled',
        })}
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white fw-semibold">
          ${headerIcon('#2B6E8F', REPORT_ICONS.collection)}Cash flow — last ${escapeHtml(days)} days
        </div>
        <div class="card-body">
          <div class="row row-cols-2 row-cols-lg-4 g-3">
            ${iconStatCard({ tone: '#2B6E8F', iconSvgPaths: REPORT_ICONS.wallet, label: 'Money in', value: money(taken), hint: `${escapeHtml(orders)} order(s)`, drillKey: 'cash-money-in' })}
            ${iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: REPORT_ICONS.rupee, label: 'GST collected', value: money(tax), hint: 'held for the government', drillKey: 'cash-gst' })}
            ${iconStatCard({ tone: refunded > 0 ? '#A6291F' : 'var(--muted-2)', iconSvgPaths: REPORT_ICONS.cancelled, label: 'Refunded', value: money(refunded), drillKey: 'cash-refunded' })}
            ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: REPORT_ICONS.check, label: 'Yours to keep', value: money(kept), hint: 'after tax and refunds', drillKey: 'cash-kept' })}
          </div>

          <p class="small text-muted mt-3 mb-0">
            <b>GST is not income.</b> Indian prices include tax, so a share of every
            rupee taken is money you are holding on behalf of the government until
            you file. "Yours to keep" is takings less that tax and less anything
            refunded — the figure to plan against.
          </p>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-7">
          <div class="card h-100" data-daily-card>
            <div class="card-header bg-white fw-semibold">Day by day</div>
            <div class="card-body p-0">${dailyTable(series)}</div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Orders in progress</div>
            <div class="card-body p-0">${pipelineTable(dashboard.data.pipeline)}</div>
          </div>

          <div class="card">
            <div class="card-header bg-white fw-semibold">Refunds</div>
            <div class="card-body small">
              <dl class="row mb-0">
                <dt class="col-7 fw-normal">Refunds issued</dt>
                <dd class="col-5 text-end">${escapeHtml(refunds.count ?? 0)}</dd>
                <dt class="col-7 fw-normal">Back to the payer</dt>
                <dd class="col-5 text-end">${money(refunds.to_gateway)}</dd>
                <dt class="col-7 fw-normal">Back to wallet</dt>
                <dd class="col-5 text-end">${money(refunds.to_wallet)}</dd>
                ${Number(refunds.failed) > 0 ? `
                  <dt class="col-7 fw-normal text-danger">Failed</dt>
                  <dd class="col-5 text-end text-danger">${escapeHtml(refunds.failed)}</dd>` : ''}
              </dl>
              ${Number(refunds.failed) > 0
                ? '<div class="alert alert-danger small mt-2 mb-0">A failed refund is a customer waiting for money. Chase these first.</div>'
                : ''}
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Counter sales by cashier — last ${escapeHtml(days)} days</div>
            <div class="card-body p-0">${cashierTable(pos.data.by_cashier)}</div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Counter sales by payment method — last ${escapeHtml(days)} days</div>
            <div class="card-body p-0">${paymentMethodTable(pos.data.by_payment_method)}</div>
          </div>
        </div>
      </div>

      <p class="text-muted small mb-0">
        Only confirmed, non-cancelled orders count. An order placed but never paid
        for is not revenue and never appears here. Counter-sale figures above
        exclude voided sales.
      </p>`;

    panel.querySelectorAll('[data-drill-tile]').forEach((tile) => {
      const key = tile.dataset.drillTile;
      const open = () => (key.startsWith('cash-') ? scrollToAndFlash('[data-daily-card]') : openTodayDrilldownModal(key));
      tile.addEventListener('click', open);
      tile.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
  } catch (error) {
    panel.innerHTML = '';
    showError(error, panel);
  }
}

const mounted = await mountConsole('reports.html');
if (mounted) { root = mounted.root; render(); }
