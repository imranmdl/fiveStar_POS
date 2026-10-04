/** Dashboard: what needs attention, then today's figures. */

import { api, mountConsole, showError, escapeHtml, formatMoney, badge, iconStatCard, headerIcon, STATUS_TONE } from './console.js?v=9';
import { upcoming } from './festival-calendar.js';

/** Bootstrap's own tone variables — same tones badge() already uses for a
 * status, so the pipeline donut and its legend never introduce a second,
 * disagreeing colour for the same status. */
const TONE_COLOR = {
  secondary: 'var(--bs-secondary)',
  warning: 'var(--bs-warning)',
  danger: 'var(--bs-danger)',
  primary: 'var(--bs-primary)',
  info: 'var(--bs-info)',
  success: 'var(--bs-success)',
};

const COLLECTION_LABELS = {
  cash: 'Cash', upi: 'UPI', pos: 'POS (card)', other: 'Other',
};

const ATTENTION = [
  ['unassigned_orders', 'Paid orders not yet assigned', 'orders.html?status=confirmed'],
  ['overdue_assignments', 'Assignments past their due time', 'orders.html'],
  ['delivery_problems', 'Deliveries that failed or are returning', 'shipments.html?tab=shipments'],
  ['bulk_enquiries_waiting', 'Wholesale enquiries awaiting a quote', null],
  ['commission_awaiting_approval', 'Commission entries to approve', null],
  ['expired_unpaid', 'Unpaid orders past their window', null],
  ['expiring_soon_stock', 'Batches expiring within 15 days', 'inventory.html?tab=expiry'],
  ['out_of_stock_count', 'Pack sizes out of stock', 'inventory.html?tab=alerts&status=out'],
  ['low_stock_count', 'Pack sizes at or below their minimum level', 'inventory.html?tab=alerts&status=low'],
  ['overdue_dues_count', 'Customer dues overdue for collection', 'customer-dues.html?status=overdue'],
];

const ICONS = {
  orders: '<path d="M4 7l8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
  revenue: '<path d="M12 3v18"/><path d="M17 7.5c0-1.9-2.2-3-5-3s-5 1.2-5 3 2.2 2.6 5 3 5 1.1 5 3-2.2 3-5 3-5-1.1-5-3"/>',
  delivered: '<path d="m5 13 4 4L19 7"/>',
  cancelled: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
  sales: '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
  profit: '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
  loss: '<path d="M12 5v14"/><path d="m5 12 7 7 7-7"/>',
  collection: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="7.5" cy="14.5" r="1"/>',
  wallet: '<path d="M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v3"/><path d="M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-4"/><path d="M17 12h3v3h-3a1.5 1.5 0 0 1 0-3Z"/>',
  invoice: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
  pipeline: '<path d="M4 6h16M4 12h10M4 18h6"/><circle cx="19" cy="12" r="1.5"/><circle cx="15" cy="18" r="1.5"/>',
  trend: '<path d="M4 17 9 11l4 3 7-8"/><path d="M15 6h5v5"/>',
};

/**
 * A small labelled header above a loose row of stat cards — the "Today's
 * Overview" and "Profit & Loss" rows used to drop straight in with no
 * heading at all, which is what made the whole dashboard read as one
 * undifferentiated wall of boxes. Same visual language as every card-header
 * elsewhere on this page (headerIcon + a title), just sitting above an open
 * row instead of inside a <div class="card">.
 */
function sectionLabel(tone, iconSvgPaths, title, subtitle = '') {
  return `
    <div class="dash-section-label">
      ${headerIcon(tone, iconSvgPaths)}
      <span class="fw-semibold">${escapeHtml(title)}</span>
      ${subtitle ? `<span class="text-muted small ms-2">${escapeHtml(subtitle)}</span>` : ''}
    </div>`;
}

function attentionPanel(counts) {
  // Only what is actually outstanding. A list of six zeroes trains people to
  // ignore the panel, and then they ignore it on the day it is not zero.
  const live = ATTENTION.filter(([key]) => Number(counts[key] || 0) > 0);

  if (live.length === 0) {
    return `
      <div class="card mb-4">
        <div class="card-body text-center py-4">
          <div class="fw-semibold" style="color:var(--forest)">Nothing needs attention.</div>
          <div class="small text-muted">No unassigned orders, overdue work or delivery problems.</div>
        </div>
      </div>`;
  }

  return `
    <div class="card mb-4 needs-attention">
      <div class="card-header bg-white fw-semibold">${headerIcon('#A6291F', ICONS.cancelled)}Needs attention</div>
      <ul class="list-group list-group-flush">
        ${live.map(([key, label, href]) => `
          <li class="list-group-item dash-attention-item">
            <span class="dash-dot"></span>
            <span class="flex-fill">${escapeHtml(label)}</span>
            <span class="badge text-bg-danger">${escapeHtml(counts[key])}</span>
            ${href ? `<a class="btn btn-sm btn-outline-secondary" href="${href}">View</a>` : ''}
          </li>`).join('')}
      </ul>
    </div>`;
}

/**
 * Donut chart (plain CSS conic-gradient, no charting library) + legend —
 * replaces a stack of thin progress bars with the standard "status mix"
 * analytics widget: one glance at the ring for proportion, the legend for
 * the exact numbers. Colours are the same Bootstrap tones badge() already
 * uses for each status (via STATUS_TONE), so this never disagrees with how
 * that status is coloured anywhere else in the console.
 */
function pipelinePanel(pipeline) {
  if (!pipeline.length) {
    return '<p class="text-muted small mb-0">No paid orders in progress.</p>';
  }

  const totalCount = pipeline.reduce((sum, row) => sum + Number(row.count || 0), 0) || 1;
  const totalValue = pipeline.reduce((sum, row) => sum + Number(row.value || 0), 0);

  let acc = 0;
  const segments = pipeline.map((row) => {
    const tone = STATUS_TONE[row.status] || 'secondary';
    const color = TONE_COLOR[tone] || TONE_COLOR.secondary;
    const start = acc;
    acc += (Number(row.count || 0) / totalCount) * 100;

    return { row, color, start, end: acc };
  });

  const gradient = segments.map((s) => `${s.color} ${s.start}% ${s.end}%`).join(', ');

  return `
    <div class="d-flex align-items-center gap-4 flex-wrap">
      <div class="dash-donut" style="background:conic-gradient(${gradient})">
        <div class="dash-donut-hole">
          <span class="dash-donut-total">${escapeHtml(totalCount)}</span>
          <span class="dash-donut-label">orders</span>
        </div>
      </div>
      <div class="dash-legend flex-grow-1">
        <div class="small text-muted mb-2">${formatMoney(totalValue)} total value in progress</div>
        ${segments.map((s) => `
          <div class="dash-legend-row">
            <span class="dash-legend-dot" style="background:${s.color}"></span>
            <span class="flex-grow-1 small text-capitalize">${escapeHtml(s.row.status.replace(/_/g, ' '))}</span>
            <span class="small text-muted text-nowrap">${escapeHtml(s.row.count)} · ${formatMoney(s.row.value)}</span>
          </div>`).join('')}
      </div>
    </div>`;
}

/**
 * Trend line (plain inline SVG, no charting library) over the last 7 days —
 * replaces the bar-per-day sparkline with the standard analytics line/area
 * chart: a filled gradient under a smooth line, a dot per day, today's dot
 * highlighted.
 */
function salesLineChart(series) {
  if (!series.length) return '<p class="text-muted small mb-0">No sales in the last seven days.</p>';

  const max = Math.max(...series.map((d) => Number(d.gross_sales) || 0), 1);
  const today = new Date().toISOString().slice(0, 10);
  const weekTotal = series.reduce((sum, d) => sum + (Number(d.gross_sales) || 0), 0);

  const w = 300;
  const h = 100;
  const padX = 12;
  const padY = 14;
  const stepX = series.length > 1 ? (w - padX * 2) / (series.length - 1) : 0;

  const points = series.map((day, index) => ({
    x: padX + index * stepX,
    y: h - padY - (Number(day.gross_sales || 0) / max) * (h - padY * 2),
    day,
  }));

  const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
  const areaPath = `${linePath} L${points[points.length - 1].x.toFixed(1)},${h - padY} `
    + `L${points[0].x.toFixed(1)},${h - padY} Z`;

  return `
    <div class="d-flex justify-content-between align-items-baseline mb-2">
      <span class="small text-muted">7-day total</span>
      <span class="stat-value" style="font-size:20px">${formatMoney(weekTotal)}</span>
    </div>
    <svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" class="dash-line-chart" role="img" aria-label="Sales over the last seven days">
      <defs>
        <linearGradient id="dashLineFill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="var(--gold)" stop-opacity="0.35"></stop>
          <stop offset="100%" stop-color="var(--gold)" stop-opacity="0"></stop>
        </linearGradient>
      </defs>
      <line x1="${padX}" y1="${h - padY}" x2="${w - padX}" y2="${h - padY}" class="dash-line-grid"></line>
      <line x1="${padX}" y1="${(h) / 2}" x2="${w - padX}" y2="${(h) / 2}" class="dash-line-grid"></line>
      <path d="${areaPath}" fill="url(#dashLineFill)" stroke="none"></path>
      <path d="${linePath}" fill="none" class="dash-line-stroke"></path>
      ${points.map((p) => `
        <circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="${p.day.date === today ? 3.6 : 2.4}"
                class="${p.day.date === today ? 'dash-line-dot is-today' : 'dash-line-dot'}">
          <title>${escapeHtml(p.day.date)}: ${formatMoney(p.day.gross_sales)} across ${escapeHtml(p.day.orders)} order(s)</title>
        </circle>`).join('')}
    </svg>
    <div class="dash-line-labels">
      ${points.map((p) => {
        const weekday = new Date(`${p.day.date}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short' });
        return `<span class="${p.day.date === today ? 'is-today' : ''}">${escapeHtml(weekday)}</span>`;
      }).join('')}
    </div>`;
}

/**
 * Analytics: which products actually drove the last 7 days, ranked by
 * revenue. Reuses ReportingService::topProducts() as-is — the same figures
 * the full Reports page shows for a chosen range — just the top 5 for a
 * fixed trailing week, so the dashboard gives a real "what's selling"
 * answer instead of only "how much came in today".
 */
/** Fixed palette for the top-products donut — these aren't status-coloured
 * like the pipeline donut (a product has no fixed tone), so each of the top
 * 5 slots gets a distinct, consistent colour instead. */
const PRODUCT_PALETTE = ['var(--gold)', 'var(--forest)', '#2B6E8F', '#A6291F', '#7A1F3D'];

/**
 * Donut chart + legend — same visual language as the "In progress" panel
 * above, so the dashboard has one consistent chart style instead of a bar
 * chart here and a donut there. Each of the top 5 products gets a slice
 * sized by its share of their combined revenue.
 */
function topProductsPanel(products) {
  if (!products.length) return '<p class="text-muted small mb-0">No sales in the last seven days.</p>';

  const top = products.slice(0, 5);
  const totalRevenue = top.reduce((sum, p) => sum + (Number(p.revenue) || 0), 0) || 1;

  let acc = 0;
  const segments = top.map((p, index) => {
    const color = PRODUCT_PALETTE[index % PRODUCT_PALETTE.length];
    const start = acc;
    acc += (Number(p.revenue || 0) / totalRevenue) * 100;

    return { p, color, start, end: acc };
  });

  const gradient = segments.map((s) => `${s.color} ${s.start}% ${s.end}%`).join(', ');

  return `
    <div class="d-flex align-items-center gap-4 flex-wrap">
      <div class="dash-donut" style="background:conic-gradient(${gradient})">
        <div class="dash-donut-hole">
          <span class="dash-donut-total">${formatMoney(totalRevenue)}</span>
          <span class="dash-donut-label">top 5</span>
        </div>
      </div>
      <div class="dash-legend flex-grow-1">
        ${segments.map((s) => {
          const share = Math.round((Number(s.p.revenue || 0) / totalRevenue) * 100);

          return `
          <div class="dash-legend-row">
            <span class="dash-legend-dot" style="background:${s.color}"></span>
            <span class="flex-grow-1 min-w-0 small text-truncate">${escapeHtml(s.p.product_name)}</span>
            <span class="small text-muted text-nowrap">${formatMoney(s.p.revenue)} · ${share}%</span>
          </div>`;
        }).join('')}
      </div>
    </div>`;
}

/**
 * Dynamic Offers & Smart Discounts: slow-moving and near-expiry stock,
 * surfaced as a suggestion only — see OfferRecommendationService's own doc
 * comment for why this never creates or activates anything by itself. The
 * button below opens Promotions to create a real offer by hand; nothing on
 * this dashboard card can apply a discount.
 */
function recommendedOffersPanel(items) {
  if (!items.length) return '<p class="text-muted small mb-0">Nothing needs a push right now.</p>';

  const REASON_LABEL = { slow_moving: 'Slow-moving', near_expiry: 'Near expiry' };

  return `
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Product</th><th>Why</th><th class="text-end">Suggested</th><th></th></tr></thead>
        <tbody>
          ${items.slice(0, 8).map((item) => `
            <tr>
              <td>
                <span class="fw-semibold small">${escapeHtml(item.product_name)}</span>
                <span class="small text-muted"> (${escapeHtml(item.variant_name)})</span>
                ${item.urgency === 'high' ? '<span class="badge text-bg-danger ms-1">Urgent</span>' : ''}
                <div class="small text-muted">${escapeHtml(item.detail)}</div>
              </td>
              <td class="small">${escapeHtml(REASON_LABEL[item.reason] || item.reason)}</td>
              <td class="text-end small text-nowrap">${escapeHtml(item.suggested_discount_value)}% off</td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-dark" href="promotions.html?new_offer=1&title=${encodeURIComponent(item.product_name + ' — Special offer')}&discount_value=${encodeURIComponent(item.suggested_discount_value)}">
                  Create offer
                </a>
              </td>
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

function collectionsPanel(collections) {
  const buckets = Object.entries(collections)
    .filter(([key, bucket]) => key !== 'other' || Number(bucket.amount) > 0 || Number(bucket.count) > 0);

  return `
    <div class="row row-cols-2 row-cols-lg-3 g-2">
      ${buckets.map(([key, bucket]) => `
        <div class="col">
          <button type="button" class="btn btn-outline-secondary w-100 text-start p-3" data-collection-tile="${escapeHtml(key)}">
            <div class="small text-muted">${escapeHtml(COLLECTION_LABELS[key] || key)}</div>
            <div class="fw-semibold fs-6">${formatMoney(bucket.amount)}</div>
            <div class="small text-muted">${escapeHtml(bucket.count)} transaction(s)</div>
          </button>
        </div>`).join('')}
    </div>`;
}

/** The drill-down behind clicking a collections tile — every transaction that made up today's total for that payment type. */
async function openCollectionModal(method) {
  const host = modalHost('data-collection-modal');
  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">${escapeHtml(COLLECTION_LABELS[method] || method)} — today's transactions</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>
          </div>
        </div>
      </div>
    </div>`;

  const modalEl = host.querySelector('[data-modal]');
  const modal = new window.bootstrap.Modal(modalEl);
  modal.show();

  const body = host.querySelector('.modal-body');

  try {
    const response = await api.get(`/admin/dashboard/collections/${encodeURIComponent(method)}`);
    const transactions = response.data.transactions || [];

    body.innerHTML = transactions.length === 0
      ? '<p class="text-muted small mb-0">Nothing collected this way yet today.</p>'
      : `
        <div class="table-responsive">
          <table class="table table-tight table-hover mb-0">
            <thead><tr><th>Reference</th><th>Channel</th><th>Cashier</th><th>Time</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
              ${transactions.map((t) => `
                <tr>
                  <td class="fw-semibold small">${escapeHtml(t.reference)}</td>
                  <td class="small">${t.channel === 'pos' ? 'POS' : 'Online order'}</td>
                  <td class="small">${t.cashier_name ? escapeHtml(t.cashier_name) : '<span class="text-muted">—</span>'}</td>
                  <td class="small">${escapeHtml(String(t.date || '').slice(0, 16).replace('T', ' '))}</td>
                  <td class="text-end">${formatMoney(t.amount)}</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>`;
  } catch (error) {
    showError(error, body);
  }
}

const TODAY_DRILLDOWN_TITLE = {
  sales: "Today's orders & sales",
  delivered: 'Delivered today',
  cancelled: 'Cancelled today',
  collection: 'Total collection — today',
  profit: 'Profit (est.) — today, by item',
  loss: 'Loss — today',
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

function profitRowMarkup(row) {
  const profit = Number(row.profit);

  return `
    <tr>
      <td class="small">${escapeHtml(row.product_name)} <span class="text-muted">(${escapeHtml(row.variant_name)})</span></td>
      <td class="small text-end">${escapeHtml(row.units_sold)}</td>
      <td class="small text-end">${formatMoney(row.revenue)}</td>
      <td class="small text-end">${formatMoney(row.cost)}</td>
      <td class="small text-end ${profit < 0 ? 'text-danger' : 'text-success'}">${formatMoney(row.profit)}</td>
    </tr>`;
}

function lossRowMarkup(row) {
  const label = row.type === 'expired' ? 'Expired' : row.type === 'damage' ? 'Damaged' : 'Lost';

  return `
    <tr>
      <td>${badge(row.type === 'expired' ? 'expired' : 'damage', label)}</td>
      <td class="small">${escapeHtml(row.product_name || row.sku || '—')} ${row.variant_name ? `<span class="text-muted">(${escapeHtml(row.variant_name)})</span>` : ''}</td>
      <td class="small text-end">${escapeHtml(row.quantity)}</td>
      <td class="small text-end">${row.value === null ? '—' : formatMoney(row.value)}</td>
      <td class="small">${escapeHtml(String(row.date || '').slice(0, 10))}</td>
    </tr>`;
}

/**
 * The drill-down behind clicking a "Today's Overview" / "Profit & Loss"
 * stat tile — a real, itemised list behind every one of those summary
 * numbers, the same posture openCollectionModal() already takes for the
 * Collections tiles. GET /admin/dashboard/today/{kind} is a single endpoint
 * shared by all six kinds (see ReportController::today()); which table
 * shape to render is decided here, client-side, by `kind`.
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

    if (items.length === 0) {
      body.innerHTML = '<p class="text-muted small mb-0">Nothing here yet today.</p>';
      return;
    }

    if (kind === 'profit') {
      body.innerHTML = `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
        <thead><tr><th>Item</th><th class="text-end">Units</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Profit</th></tr></thead>
        <tbody>${items.map(profitRowMarkup).join('')}</tbody>
      </table></div>`;

      return;
    }

    if (kind === 'loss') {
      body.innerHTML = `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
        <thead><tr><th>Type</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Value</th><th>Date</th></tr></thead>
        <tbody>${items.map(lossRowMarkup).join('')}</tbody>
      </table></div>`;

      return;
    }

    body.innerHTML = `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
      <thead><tr><th>Reference</th><th>Channel</th><th>Customer</th><th>Status</th><th>Time</th><th class="text-end">Amount</th></tr></thead>
      <tbody>${items.map(saleRowMarkup).join('')}</tbody>
    </table></div>`;
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

/**
 * "A festival is coming up — want to set up its gift page?" One at a time,
 * nearest first, and never twice for the same festival in the same session —
 * an administrator who says "not now" should not be asked again every time
 * they open the dashboard today.
 */
async function maybeShowFestivalReminder() {
  const modalHost = document.querySelector('[data-festival-reminder]')
    ?? document.body.appendChild(Object.assign(document.createElement('div'), { dataset: { festivalReminder: '' } }));

  try {
    const collections = (await api.get('/admin/collections')).data.collections || [];
    const bySlug = Object.fromEntries(collections.map((c) => [c.slug, c]));

    const due = upcoming(bySlug, 14).find(({ festival, collection }) => {
      const dismissKey = `festival_reminder_dismissed:${festival.slug}:${festival.date2026}`;
      if (sessionStorage.getItem(dismissKey)) return false;

      const isLive = collection && collection.status === 'published'
        && (!collection.ends_date || String(collection.ends_date).slice(0, 10) >= new Date().toISOString().slice(0, 10));

      return !isLive;
    });

    if (!due) return;

    const { festival } = due;
    const dismissKey = `festival_reminder_dismissed:${festival.slug}:${festival.date2026}`;
    const readableDate = new Date(`${festival.date2026}T00:00:00`)
      .toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' });

    modalHost.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal>
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="h6 modal-title">🎉 ${escapeHtml(festival.title)} is coming up</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body small">
              <p class="mb-1">${escapeHtml(readableDate)} — ${escapeHtml(festival.note)}</p>
              <p class="mb-0">Would you like to set up its gift pack, or add an offer for it, before then?</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" data-not-now>Not now</button>
              <a class="btn btn-sm btn-dark" href="festivals.html">Set up gifts</a>
            </div>
          </div>
        </div>
      </div>`;

    const modalEl = modalHost.querySelector('[data-modal]');
    const modal = new window.bootstrap.Modal(modalEl);
    modalEl.querySelector('[data-not-now]').addEventListener('click', () => sessionStorage.setItem(dismissKey, '1'));
    modalEl.addEventListener('hidden.bs.modal', () => modalHost.remove(), { once: true });
    modal.show();
  } catch {
    // A reminder is a nicety; it must never break the dashboard.
  }
}

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
}

async function render(root, role) {
  try {
    // Top Products needs /admin/reports/products, which — unlike
    // /admin/dashboard itself — is administrator/supervisor only (see
    // $supervisory in routes/api_v1.php). Fetched only for those roles so
    // an executive's dashboard never issues a call it will get a 403 back
    // for, and wrapped so that even a genuine failure just hides the
    // analytics card instead of breaking the rest of the dashboard.
    const canSeeAnalytics = ['administrator', 'supervisor'].includes(String(role));
    const weekAgo = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);
    const today = new Date().toISOString().slice(0, 10);

    const [response, productsResponse, recommendationsResponse] = await Promise.all([
      api.get('/admin/dashboard'),
      canSeeAnalytics
        ? api.get('/admin/reports/products', { from: weekAgo, to: today }).catch(() => null)
        : Promise.resolve(null),
      canSeeAnalytics
        ? api.get('/admin/offers/recommendations').catch(() => null)
        : Promise.resolve(null),
    ]);
    const data = response.data;
    const topProducts = productsResponse ? (productsResponse.data.products || []) : null;
    const recommendations = recommendationsResponse ? (recommendationsResponse.data.recommendations || []) : null;

    root.innerHTML = `
      <div class="page-header">
        <div>
          <div class="eyebrow">${escapeHtml(greeting())}</div>
          <h1 class="h4">Dashboard</h1>
        </div>
        <div class="date">${escapeHtml(new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</div>
      </div>

      ${attentionPanel(data.needs_attention || {})}

      ${sectionLabel('var(--forest)', ICONS.orders, "Today's Overview")}
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.orders, label: 'Orders today', value: escapeHtml(data.today.orders), hint: `Online ${data.today.online_orders} · Counter ${data.today.pos_orders}`, drillKey: 'sales' })}
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.revenue, label: 'Revenue today', value: formatMoney(data.today.revenue), hint: `Online ${formatMoney(data.today.online_revenue)} · Counter ${formatMoney(data.today.pos_revenue)}`, drillKey: 'sales' })}
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.delivered, label: 'Delivered today', value: escapeHtml(data.today.delivered), drillKey: 'delivered' })}
        ${iconStatCard({
          tone: Number(data.today.cancelled) > 0 ? '#A6291F' : 'var(--muted-2)',
          iconSvgPaths: ICONS.cancelled,
          label: 'Cancelled today',
          value: escapeHtml(data.today.cancelled),
          drillKey: 'cancelled',
        })}
      </div>

      ${sectionLabel('#2B6E8F', ICONS.sales, 'Profit & Loss', "today's figures")}
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
        ${iconStatCard({ tone: '#2B6E8F', iconSvgPaths: ICONS.sales, label: "Today's sales", value: formatMoney(data.profit_loss.sales), hint: 'Online + POS', drillKey: 'sales' })}
        ${iconStatCard({
          tone: Number(data.profit_loss.profit) < 0 ? '#A6291F' : 'var(--gold-dark)',
          iconSvgPaths: ICONS.profit,
          label: 'Profit (est.)',
          value: formatMoney(data.profit_loss.profit),
          hint: 'Against current avg. cost',
          drillKey: 'profit',
        })}
        ${iconStatCard({
          tone: Number(data.profit_loss.loss) > 0 ? '#A6291F' : 'var(--muted-2)',
          iconSvgPaths: ICONS.loss,
          label: 'Loss',
          value: formatMoney(data.profit_loss.loss),
          hint: 'Damaged, lost, expired today',
          drillKey: 'loss',
        })}
        ${iconStatCard({ tone: '#2B6E8F', iconSvgPaths: ICONS.collection, label: 'Total collection', value: formatMoney(data.profit_loss.total_collection), hint: 'Money actually in hand', drillKey: 'collection' })}
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white fw-semibold">${headerIcon('#2B6E8F', ICONS.collection)}Collections by payment type</div>
        <div class="card-body">${collectionsPanel(data.collections || {})}</div>
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">${headerIcon('#6E4E9E', ICONS.wallet)}Wallets</span>
          <a class="small" href="wallets.html">Manage wallets →</a>
        </div>
        <div class="card-body">
          <div class="row row-cols-2 row-cols-lg-5 g-3">
            ${iconStatCard({ tone: '#6E4E9E', iconSvgPaths: ICONS.wallet, label: 'Total wallet balance', value: formatMoney((data.wallet || {}).total_balance), hint: `${escapeHtml((data.wallet || {}).account_count ?? 0)} account(s)` })}
            ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.wallet, label: 'Total refund credits', value: formatMoney((data.wallet || {}).total_refund_credits) })}
            ${iconStatCard({ tone: '#6E4E9E', iconSvgPaths: ICONS.wallet, label: 'Total wallet credits', value: formatMoney((data.wallet || {}).total_credits) })}
            ${iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: ICONS.wallet, label: 'Total wallet debits', value: formatMoney((data.wallet || {}).total_debits) })}
            ${iconStatCard({
              tone: Number(((data.wallet || {}).pending_refunds || {}).count) > 0 ? '#A6291F' : 'var(--muted-2)',
              iconSvgPaths: ICONS.wallet,
              label: 'Pending refunds',
              value: formatMoney(((data.wallet || {}).pending_refunds || {}).amount),
              hint: `${escapeHtml(((data.wallet || {}).pending_refunds || {}).count ?? 0)} order(s)`,
            })}
          </div>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">${headerIcon('#B5773A', ICONS.invoice)}Customer Dues</span>
          <a class="small" href="customer-dues.html">Manage dues →</a>
        </div>
        <div class="card-body">
          <div class="row row-cols-2 row-cols-lg-5 g-3">
            ${iconStatCard({ tone: Number((data.customer_dues || {}).total_due) > 0 ? 'var(--gold-dark)' : 'var(--muted-2)', iconSvgPaths: ICONS.invoice, label: 'Total due', value: formatMoney((data.customer_dues || {}).total_due) })}
            ${iconStatCard({ tone: '#B5773A', iconSvgPaths: ICONS.invoice, label: 'Partially paid', value: escapeHtml((data.customer_dues || {}).partially_paid_count ?? 0) })}
            ${iconStatCard({ tone: '#B5773A', iconSvgPaths: ICONS.invoice, label: 'Fully paid', value: escapeHtml((data.customer_dues || {}).fully_paid_count ?? 0) })}
            ${iconStatCard({
              tone: Number((data.customer_dues || {}).overdue_count) > 0 ? '#A6291F' : 'var(--muted-2)',
              iconSvgPaths: ICONS.invoice,
              label: 'Overdue',
              value: escapeHtml((data.customer_dues || {}).overdue_count ?? 0),
              hint: formatMoney((data.customer_dues || {}).overdue_amount),
            })}
            ${iconStatCard({ tone: '#B5773A', iconSvgPaths: ICONS.invoice, label: 'Collected today', value: formatMoney((data.customer_dues || {}).collected_today) })}
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card">
            <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.pipeline)}In progress</div>
            <div class="card-body">${pipelinePanel(data.pipeline || [])}</div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card">
            <div class="card-header bg-white fw-semibold">${headerIcon('#2B6E8F', ICONS.trend)}Last seven days</div>
            <div class="card-body">${salesLineChart(data.last_7_days || [])}</div>
          </div>
        </div>
      </div>

      ${topProducts ? `
        ${sectionLabel('#C1670E', ICONS.trend, 'Analytics', 'top products, last 7 days')}
        <div class="row g-3">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-white fw-semibold">${headerIcon('#C1670E', ICONS.trend)}Top products by revenue</div>
              <div class="card-body">${topProductsPanel(topProducts)}</div>
            </div>
          </div>
        </div>` : ''}

      ${recommendations ? `
        ${sectionLabel('#7A1F3D', ICONS.trend, 'Recommended Offers', 'slow-moving & near-expiry stock')}
        <div class="row g-3 mb-4">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-white fw-semibold">${headerIcon('#7A1F3D', ICONS.trend)}Suggestions — nothing here is applied automatically</div>
              <div class="card-body p-0">${recommendedOffersPanel(recommendations)}</div>
            </div>
          </div>
        </div>` : ''}

      <p class="text-muted small mt-4 mb-0">
        Revenue counts confirmed, non-cancelled orders only. An order that has been
        placed but not paid for is not revenue. Profit is estimated against each
        item's current average cost, not a historical snapshot — treat it as a
        same-day approximation, not a ledger figure. Total collection is money
        actually in hand today: paid online orders plus completed POS sales.
      </p>`;

    root.querySelectorAll('[data-collection-tile]').forEach((button) => {
      button.addEventListener('click', () => openCollectionModal(button.dataset.collectionTile));
    });

    root.querySelectorAll('[data-drill-tile]').forEach((tile) => {
      const open = () => openTodayDrilldownModal(tile.dataset.drillTile);
      tile.addEventListener('click', open);
      tile.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
      });
    });
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

const mounted = await mountConsole('index.html');

if (mounted) {
  await render(mounted.root, mounted.user.role);

  // Whether to set up a festival's page is an administrator's call — the
  // one role that can act on the prompt (see festivals.html's own gating).
  if (String(mounted.user.role) === 'administrator') maybeShowFestivalReminder();
}
