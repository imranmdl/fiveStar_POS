/**
 * Shipments and couriers: the operating console for a delivery/courier
 * backend that was already fully built (booking, labels, pickups,
 * manifests, tracking, BR-007 automatic selection) but had no admin screen
 * at all — everything here is a thin view over endpoints that already
 * existed and were already tested.
 *
 * Two tabs, same query-param pattern as till.html: ?tab=shipments (default)
 * or ?tab=couriers, each with its own optional detail param.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         badge, emptyState, queryParam, iconStatCard, headerIcon } from './console.js?v=9';

const state = { tab: queryParam('tab') || 'shipments', status: '', courier: '', search: '' };
let root = null;

const ICONS = {
  box: '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
  truck: '<rect x="1" y="7" width="13" height="10" rx="1"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/>',
  check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  alert: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
  chart: '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/>',
};

function dt(value) {
  return escapeHtml(String(value || '').slice(0, 16).replace('T', ' ') || '—');
}

/** Three cheap count-only requests (per_page=1, only meta.total is read) rather than a new backend endpoint just for decoration. */
async function renderShipmentStats(container) {
  const host = container.querySelector('[data-shipment-stats]');
  if (!host) return;

  try {
    const [total, inTransit, delivered, failed, rto] = await Promise.all([
      api.get('/admin/shipments', { per_page: 1 }),
      api.get('/admin/shipments', { status: 'in_transit', per_page: 1 }),
      api.get('/admin/shipments', { status: 'delivered', per_page: 1 }),
      api.get('/admin/shipments', { status: 'failed_delivery', per_page: 1 }),
      api.get('/admin/shipments', { status: 'rto_initiated', per_page: 1 }),
    ]);

    const problems = (failed.meta?.total || 0) + (rto.meta?.total || 0);

    host.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.box, label: 'Total shipments', value: escapeHtml(total.meta?.total ?? 0) })}
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.truck, label: 'In transit', value: escapeHtml(inTransit.meta?.total ?? 0) })}
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.check, label: 'Delivered', value: escapeHtml(delivered.meta?.total ?? 0) })}
        ${iconStatCard({ tone: problems > 0 ? '#A6291F' : 'var(--muted-2)', iconSvgPaths: ICONS.alert, label: 'Failed / RTO', value: escapeHtml(problems) })}
      </div>`;
  } catch {
    // Decoration only — a failed count fetch should never block the shipments list itself.
    host.innerHTML = '';
  }
}

// ---------------------------------------------------------------------
// Shipments tab
// ---------------------------------------------------------------------

function shipmentRow(shipment) {
  return `
    <tr>
      <td>
        <a href="shipments.html?tab=shipments&shipment=${encodeURIComponent(shipment.uuid)}" class="fw-semibold text-decoration-none">
          ${escapeHtml(shipment.shipment_number)}
        </a>
        <div class="small text-muted">${escapeHtml(shipment.order_number)} · ${dt(shipment.created_date)}</div>
      </td>
      <td>${escapeHtml(shipment.courier_name)}</td>
      <td class="font-monospace small">${escapeHtml(shipment.awb_number || '—')}</td>
      <td>${badge(shipment.status, String(shipment.status || '').replace(/_/g, ' '))}</td>
      <td class="small">${escapeHtml([shipment.ship_city, shipment.ship_state].filter(Boolean).join(', ') || '—')}</td>
      <td class="small">
        ${shipment.last_scan_status ? escapeHtml(shipment.last_scan_status) : '<span class="text-muted">No scan yet</span>'}
        ${shipment.last_scan_location ? `<div class="text-muted">${escapeHtml(shipment.last_scan_location)}</div>` : ''}
      </td>
    </tr>`;
}

async function renderShipmentsList(container) {
  container.innerHTML = `
    <div data-shipment-stats></div>
    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
      <div>
        <label class="form-label small mb-0">Status</label>
        <select class="form-select form-select-sm" data-filter-status style="width:11rem">
          <option value="">All</option>
          ${['created', 'booked', 'label_generated', 'pickup_scheduled', 'picked_up', 'in_transit',
            'out_for_delivery', 'delivered', 'failed_delivery', 'rto_initiated', 'rto_delivered',
            'cancelled', 'lost'].map((s) => `<option value="${s}" ${state.status === s ? 'selected' : ''}>${s.replace(/_/g, ' ')}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="form-label small mb-0">Search</label>
        <input class="form-control form-control-sm" data-filter-search placeholder="AWB or shipment number" value="${escapeHtml(state.search)}" style="width:14rem">
      </div>
      <button class="btn btn-sm btn-outline-secondary" data-apply-filters>Apply</button>
      <button class="btn btn-sm btn-outline-secondary ms-auto" data-refresh-stale>Refresh stale shipments</button>
    </div>
    <div data-refresh-feedback class="small text-danger mb-2"></div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  renderShipmentStats(container);

  container.querySelector('[data-apply-filters]').addEventListener('click', () => {
    state.status = container.querySelector('[data-filter-status]').value;
    state.search = container.querySelector('[data-filter-search]').value.trim();
    renderShipmentsList(container);
  });

  container.querySelector('[data-refresh-stale]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    setBusy(button, true, 'Refreshing');

    try {
      const response = await api.post('/admin/shipments/refresh-stale', {});
      toast(response.message || 'Stale shipments refreshed.');
      renderShipmentsList(container);
    } catch (error) {
      setBusy(button, false);
      showError(error, container.querySelector('[data-refresh-feedback]'));
    }
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/shipments', {
      status: state.status || undefined,
      courier: state.courier || undefined,
      search: state.search || undefined,
      per_page: 30,
      direction: 'DESC',
    });

    const rows = response.data || [];

    if (rows.length === 0) {
      list.innerHTML = emptyState('No shipments yet', 'Book a courier from an order to see it here.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Shipment</th><th>Courier</th><th>AWB</th><th>Status</th><th>Destination</th><th>Last scan</th></tr></thead>
          <tbody>${rows.map(shipmentRow).join('')}</tbody>
        </table>
      </div>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function eventsTimeline(events) {
  if (!events || events.length === 0) {
    return '<p class="text-muted small mb-0">No tracking events yet.</p>';
  }

  return `
    <ul class="list-group list-group-flush">
      ${events.map((event) => `
        <li class="list-group-item py-2">
          <div class="fw-semibold small">${escapeHtml(event.title || event.status)}</div>
          ${event.description ? `<div class="small text-muted">${escapeHtml(event.description)}</div>` : ''}
          <div class="small text-muted">
            ${event.location ? escapeHtml(event.location) + ' · ' : ''}${dt(event.occurred_date)}
            ${event.source ? ` · ${escapeHtml(event.source)}` : ''}
          </div>
        </li>`).join('')}
    </ul>`;
}

function candidatesTable(candidates) {
  if (!candidates || candidates.length === 0) return '';

  return `
    <div class="table-responsive">
      <table class="table table-tight small mb-0">
        <thead><tr><th>Courier</th><th class="text-end">Cost</th><th>SLA</th><th>Eligible</th></tr></thead>
        <tbody>
          ${candidates.map((c) => `
            <tr class="${c.is_eligible ? '' : 'text-muted'}">
              <td>${escapeHtml(c.courier_name)}</td>
              <td class="text-end">${formatMoney(c.cost)}</td>
              <td>${escapeHtml(c.sla_min_days)}–${escapeHtml(c.sla_max_days)}d</td>
              <td>${c.is_eligible ? 'Yes' : escapeHtml((c.ineligibility_reasons || []).join('; ') || 'No')}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

async function renderShipmentDetail(container, uuid) {
  container.innerHTML = `<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>`;

  try {
    const shipment = (await api.get(`/admin/shipments/${encodeURIComponent(uuid)}`)).data;

    container.innerHTML = `
      <a class="small text-decoration-none" href="shipments.html?tab=shipments">← Shipments</a>

      <div class="d-flex flex-wrap justify-content-between align-items-start mt-2 mb-3 gap-2">
        <div>
          <h2 class="h5 mb-1">${escapeHtml(shipment.shipment_number)}</h2>
          ${badge(shipment.status, String(shipment.status).replace(/_/g, ' '))}
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" data-refresh-tracking>Refresh tracking</button>
          <button class="btn btn-sm btn-outline-dark" data-generate-label>${shipment.label_url ? 'Open label' : 'Generate label'}</button>
        </div>
      </div>

      <div data-detail-feedback class="small text-danger mb-2"></div>

      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.box)}Details</div>
            <div class="card-body small">
              <dl class="row mb-0">
                <dt class="col-6 fw-normal">Courier</dt><dd class="col-6 text-end">${escapeHtml(shipment.courier_name || '—')}</dd>
                <dt class="col-6 fw-normal">AWB</dt><dd class="col-6 text-end font-monospace">${escapeHtml(shipment.awb_number || '—')}</dd>
                <dt class="col-6 fw-normal">Estimated delivery</dt><dd class="col-6 text-end">${escapeHtml(shipment.estimated_delivery_date || '—')}</dd>
                <dt class="col-6 fw-normal">Delivered</dt><dd class="col-6 text-end">${escapeHtml(shipment.delivered_date || '—')}</dd>
                <dt class="col-6 fw-normal">Delivery attempts</dt><dd class="col-6 text-end">${escapeHtml(shipment.delivery_attempts)}</dd>
                <dt class="col-6 fw-normal">Actual weight</dt><dd class="col-6 text-end">${escapeHtml(shipment.actual_weight_grams)} g</dd>
                <dt class="col-6 fw-normal">Chargeable weight</dt><dd class="col-6 text-end">${escapeHtml(shipment.chargeable_weight_grams)} g${shipment.used_default_dimensions ? ' <span class="text-muted">(default box)</span>' : ''}</dd>
                <dt class="col-6 fw-normal">Courier charge</dt><dd class="col-6 text-end">${formatMoney(shipment.courier_charge)}</dd>
                <dt class="col-6 fw-normal">Customer paid</dt><dd class="col-6 text-end">${formatMoney(shipment.customer_paid_delivery)}</dd>
                <dt class="col-6 fw-normal">Declared value</dt><dd class="col-6 text-end">${formatMoney(shipment.declared_value)}</dd>
              </dl>
            </div>
          </div>

          ${shipment.selection ? `
            <div class="card">
              <div class="card-header bg-white fw-semibold">Why this courier</div>
              <div class="card-body small">
                <p class="mb-2">
                  Strategy: <b>${escapeHtml(shipment.selection.strategy)}</b>
                  ${shipment.selection.was_manual_override ? ' <span class="badge text-bg-secondary">Manual override</span>' : ''}
                  — ${escapeHtml(shipment.selection.reason)}
                </p>
                <p class="text-muted mb-2">${escapeHtml(shipment.selection.candidates_eligible)} of ${escapeHtml(shipment.selection.candidates_considered)} couriers were eligible. Winning score: ${escapeHtml(shipment.selection.winning_score)}.</p>
                ${candidatesTable(shipment.selection.candidates)}
              </div>
            </div>` : ''}
        </div>

        <div class="col-12 col-lg-6">
          <div class="card">
            <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.truck)}Tracking events</div>
            <div class="card-body p-0">${eventsTimeline(shipment.events)}</div>
          </div>
        </div>
      </div>`;

    container.querySelector('[data-refresh-tracking]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      setBusy(button, true, 'Refreshing');

      try {
        await api.post(`/admin/shipments/${encodeURIComponent(uuid)}/track`, {});
        toast('Tracking refreshed.');
        renderShipmentDetail(container, uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error, container.querySelector('[data-detail-feedback]'));
      }
    });

    container.querySelector('[data-generate-label]').addEventListener('click', async (event) => {
      const button = event.currentTarget;

      if (shipment.label_url) {
        window.open(shipment.label_url, '_blank', 'noopener');
        return;
      }

      setBusy(button, true, 'Generating');

      try {
        const response = await api.post(`/admin/shipments/${encodeURIComponent(uuid)}/label`, {});
        toast('Label ready.');
        window.open(response.data.label_url, '_blank', 'noopener');
        renderShipmentDetail(container, uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error, container.querySelector('[data-detail-feedback]'));
      }
    });
  } catch (error) {
    container.innerHTML = '<a class="small" href="shipments.html?tab=shipments">← Shipments</a>';
    showError(error, container);
  }
}

// ---------------------------------------------------------------------
// Couriers tab
// ---------------------------------------------------------------------

function courierRow(courier) {
  return `
    <tr>
      <td>
        <a href="shipments.html?tab=couriers&courier=${encodeURIComponent(courier.code)}" class="fw-semibold text-decoration-none">
          ${escapeHtml(courier.name)}
        </a>
        <div class="small text-muted">${escapeHtml(courier.code)} · ${escapeHtml(courier.adapter)}</div>
      </td>
      <td class="text-end">${escapeHtml(courier.priority)}</td>
      <td class="text-end">${escapeHtml(courier.reliability_score)}</td>
      <td>${courier.is_enabled
        ? '<span class="badge text-bg-success">Enabled</span>'
        : `<span class="badge text-bg-secondary" title="${escapeHtml(courier.disabled_reason || '')}">Disabled</span>`}</td>
    </tr>`;
}

async function renderCouriersList(container) {
  container.innerHTML = `<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>`;

  try {
    const [couriersRes, performanceRes] = await Promise.all([
      api.get('/admin/couriers'),
      api.get('/admin/couriers/performance'),
    ]);

    const couriers = couriersRes.data.couriers || [];
    const performance = performanceRes.data.performance || [];

    container.innerHTML = `
      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.truck)}Couriers</div>
        <div class="table-responsive">
          <table class="table table-tight table-hover mb-0">
            <thead><tr><th>Courier</th><th class="text-end">Priority</th><th class="text-end">Reliability</th><th>Status</th></tr></thead>
            <tbody>${couriers.length ? couriers.map(courierRow).join('') : '<tr><td colspan="4" class="text-center text-muted py-4">No couriers configured.</td></tr>'}</tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>${headerIcon('var(--forest)', ICONS.chart)}Performance</span>
          <button class="btn btn-sm btn-outline-secondary" data-recalculate>Recalculate reliability</button>
        </div>
        <div data-recalculate-feedback class="small text-danger px-3 pt-2"></div>
        <div class="table-responsive">
          <table class="table table-tight small mb-0">
            <thead><tr><th>Courier</th><th class="text-end">Shipments</th><th class="text-end">Delivered</th><th class="text-end">RTO</th><th class="text-end">Lost</th><th class="text-end">Avg transit</th><th class="text-end">On time</th></tr></thead>
            <tbody>
              ${performance.length ? performance.map((p) => `
                <tr>
                  <td>${escapeHtml(p.name)}</td>
                  <td class="text-end">${escapeHtml(p.total_shipments)}</td>
                  <td class="text-end">${escapeHtml(p.delivered_count)}</td>
                  <td class="text-end">${escapeHtml(p.rto_count)}</td>
                  <td class="text-end">${escapeHtml(p.lost_count)}</td>
                  <td class="text-end">${p.avg_transit_days !== null ? escapeHtml(p.avg_transit_days) + 'd' : '—'}</td>
                  <td class="text-end">${escapeHtml(p.on_time_count)}</td>
                </tr>`).join('') : '<tr><td colspan="7" class="text-center text-muted py-4">No shipment history yet.</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`;

    container.querySelector('[data-recalculate]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      setBusy(button, true, 'Recalculating');

      try {
        const response = await api.post('/admin/couriers/recalculate-reliability', {});
        toast(response.message || 'Reliability recalculated.');
        renderCouriersList(container);
      } catch (error) {
        setBusy(button, false);
        showError(error, container.querySelector('[data-recalculate-feedback]'));
      }
    });
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

async function renderCourierDetail(container, code) {
  container.innerHTML = `<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>`;

  let courier;

  try {
    const response = await api.get('/admin/couriers');
    courier = (response.data.couriers || []).find((c) => c.code === code);
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
    return;
  }

  if (!courier) {
    container.innerHTML = '<a class="small" href="shipments.html?tab=couriers">← Couriers</a><p class="mt-2">That courier does not exist.</p>';
    return;
  }

  container.innerHTML = `
    <a class="small text-decoration-none" href="shipments.html?tab=couriers">← Couriers</a>

    <div class="d-flex flex-wrap justify-content-between align-items-start mt-2 mb-3 gap-2">
      <div>
        <h2 class="h5 mb-1">${escapeHtml(courier.name)}</h2>
        <span class="small text-muted">${escapeHtml(courier.code)} · adapter: ${escapeHtml(courier.adapter)}</span>
      </div>
      <button class="btn btn-sm ${courier.is_enabled ? 'btn-outline-danger' : 'btn-outline-success'}" data-toggle-enabled>
        ${courier.is_enabled ? 'Disable' : 'Enable'}
      </button>
    </div>

    ${!courier.is_enabled && courier.disabled_reason ? `<div class="alert alert-secondary small">Disabled: ${escapeHtml(courier.disabled_reason)}</div>` : ''}

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="card mb-3">
          <div class="card-header bg-white fw-semibold">Routing settings</div>
          <div class="card-body">
            <div class="mb-2">
              <label class="form-label small mb-0">Priority (lower wins ties)</label>
              <input class="form-control form-control-sm" type="number" min="1" max="9999" data-field="priority" value="${escapeHtml(courier.priority)}">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-0">Max weight (grams)</label>
              <input class="form-control form-control-sm" type="number" min="1" data-field="max_weight_grams" value="${escapeHtml(courier.max_weight_grams)}">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-0">Max order value (blank = no limit)</label>
              <input class="form-control form-control-sm" type="number" min="0" step="0.01" data-field="max_order_value" value="${courier.max_order_value === null ? '' : escapeHtml(courier.max_order_value)}">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-0">Support phone</label>
              <input class="form-control form-control-sm" data-field="support_phone" value="${escapeHtml(courier.support_phone || '')}">
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="handles-fragile" ${courier.handles_fragile ? 'checked' : ''}>
              <label class="form-check-label small" for="handles-fragile">Handles fragile parcels</label>
            </div>
            <button class="btn btn-sm btn-dark" data-save-courier>Save</button>
            <div data-courier-feedback class="small text-danger mt-2"></div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="card">
          <div class="card-header bg-white fw-semibold">Bulk operations</div>
          <div class="card-body">
            ${courier.supports_pickup ? `
              <div class="mb-3">
                <label class="form-label small mb-0">Schedule a pickup</label>
                <div class="input-group input-group-sm">
                  <input type="date" class="form-control" data-pickup-date value="${new Date().toISOString().slice(0, 10)}">
                  <button class="btn btn-outline-dark" data-schedule-pickup>Schedule</button>
                </div>
              </div>` : '<p class="small text-muted mb-3">This courier does not support scheduled pickups.</p>'}
            ${courier.supports_manifest ? `
              <button class="btn btn-sm btn-outline-dark" data-generate-manifest>Generate manifest</button>` : '<p class="small text-muted mb-0">This courier does not support manifests.</p>'}
            <div data-bulk-feedback class="small text-danger mt-2"></div>
          </div>
        </div>
      </div>
    </div>`;

  container.querySelector('[data-toggle-enabled]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const payload = { is_enabled: !courier.is_enabled };

    if (courier.is_enabled) {
      const reason = window.prompt('Why is this courier being disabled? Staff will see this until it is re-enabled.');
      if (!reason) return;
      payload.disabled_reason = reason;
    }

    setBusy(button, true, 'Saving');

    try {
      await api.patch(`/admin/couriers/${encodeURIComponent(code)}`, payload);
      toast(courier.is_enabled ? 'Courier disabled.' : 'Courier enabled.');
      renderCourierDetail(container, code);
    } catch (error) {
      setBusy(button, false);
      showError(error, container.querySelector('[data-courier-feedback]'));
    }
  });

  container.querySelector('[data-save-courier]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const maxOrderValue = container.querySelector('[data-field="max_order_value"]').value;

    setBusy(button, true, 'Saving');

    try {
      await api.patch(`/admin/couriers/${encodeURIComponent(code)}`, {
        priority: container.querySelector('[data-field="priority"]').value,
        max_weight_grams: container.querySelector('[data-field="max_weight_grams"]').value,
        max_order_value: maxOrderValue === '' ? null : maxOrderValue,
        support_phone: container.querySelector('[data-field="support_phone"]').value || null,
        handles_fragile: container.querySelector('#handles-fragile').checked,
      });
      toast('Courier updated.');
      renderCourierDetail(container, code);
    } catch (error) {
      setBusy(button, false);
      showError(error, container.querySelector('[data-courier-feedback]'));
    }
  });

  const pickupButton = container.querySelector('[data-schedule-pickup]');
  if (pickupButton) {
    pickupButton.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const pickupDate = container.querySelector('[data-pickup-date]').value;
      setBusy(button, true, 'Scheduling');

      try {
        const response = await api.post(`/admin/couriers/${encodeURIComponent(code)}/pickup`, { pickup_date: pickupDate });
        toast(response.message || 'Pickup scheduled.');
      } catch (error) {
        showError(error, container.querySelector('[data-bulk-feedback]'));
      } finally {
        setBusy(button, false);
      }
    });
  }

  const manifestButton = container.querySelector('[data-generate-manifest]');
  if (manifestButton) {
    manifestButton.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      setBusy(button, true, 'Generating');

      try {
        const response = await api.post(`/admin/couriers/${encodeURIComponent(code)}/manifest`, {});
        toast(response.message || 'Manifest generated.');
      } catch (error) {
        showError(error, container.querySelector('[data-bulk-feedback]'));
      } finally {
        setBusy(button, false);
      }
    });
  }
}

// ---------------------------------------------------------------------

function renderTabs() {
  return `
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link ${state.tab === 'shipments' ? 'active' : ''}" href="shipments.html?tab=shipments">Shipments</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'couriers' ? 'active' : ''}" href="shipments.html?tab=couriers">Couriers</a></li>
    </ul>`;
}

async function render() {
  root.innerHTML = `<h1 class="h4 mb-3">Shipments</h1>${renderTabs()}<div data-tab-body></div>`;
  const body = root.querySelector('[data-tab-body]');

  if (state.tab === 'couriers') {
    const code = queryParam('courier');
    if (code) await renderCourierDetail(body, code); else await renderCouriersList(body);
    return;
  }

  const shipmentUuid = queryParam('shipment');
  if (shipmentUuid) await renderShipmentDetail(body, shipmentUuid); else await renderShipmentsList(body);
}

const mounted = await mountConsole('shipments.html');
if (mounted) { root = mounted.root; render(); }
