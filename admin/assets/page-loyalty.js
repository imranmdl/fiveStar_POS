/**
 * Loyalty: program settings, store-wide totals, and one customer's points
 * ledger with a manual adjustment action. Every figure and action here is a
 * thin UI over LoyaltyService (row locks, idempotency, an append-only
 * ledger, audit logging on every earn/redeem/adjustment) — this page has no
 * business logic of its own, the same posture page-wallets.js takes toward
 * WalletService.
 *
 * Administrator/super_admin only, matching the API's own
 * adminPrivilege:loyalty.* gate on every /admin/loyalty/* route.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         emptyState, queryParam } from './console.js?v=9';

let root = null;
let customer = null; // { uuid, full_name, mobile }
let ledgerPage = 1;

const SOURCE_LABEL = {
  purchase_online: 'Online purchase',
  purchase_pos: 'Counter (POS) purchase',
  review: 'Approved review',
  referral: 'Referral',
  redemption: 'Redeemed to wallet',
  expiry: 'Expired',
  admin_adjustment: 'Admin adjustment',
};

function dashboardTiles(summary) {
  return `
    <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Program status</div>
        <div class="fs-5">${summary.enabled
          ? '<span class="badge text-bg-success">Enabled</span>'
          : '<span class="badge text-bg-secondary">Disabled</span>'}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Total points issued</div>
        <div class="fs-4 fw-semibold">${escapeHtml(summary.total_issued)}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Total points redeemed</div>
        <div class="fs-4 fw-semibold">${escapeHtml(summary.total_redeemed)}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Total points remaining</div>
        <div class="fs-4 fw-semibold text-success">${escapeHtml(summary.total_remaining)}</div>
        <div class="small text-muted">${escapeHtml(summary.account_count)} member(s)</div>
      </div></div></div>
    </div>`;
}

function settingsPanel(s) {
  return `
    <form class="row g-2" data-settings-form>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="loy-enabled" name="enabled" ${s.enabled ? 'checked' : ''}>
          <label class="form-check-label fw-semibold" for="loy-enabled">Loyalty program enabled</label>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Rupees spent per point</label>
        <input class="form-control form-control-sm" type="number" min="1" name="rupees_per_point" value="${escapeHtml(s.rupees_per_point)}">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">₹ value per point redeemed</label>
        <input class="form-control form-control-sm" type="number" step="0.01" min="0.01" name="redeem_value_per_point" value="${escapeHtml(s.redeem_value_per_point)}">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Min. points to redeem</label>
        <input class="form-control form-control-sm" type="number" min="1" name="min_redeem_points" value="${escapeHtml(s.min_redeem_points)}">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Max. points per redemption</label>
        <input class="form-control form-control-sm" type="number" min="0" name="max_redeem_points_per_order" value="${escapeHtml(s.max_redeem_points_per_order)}">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Points expire after (days, 0 = never)</label>
        <input class="form-control form-control-sm" type="number" min="0" name="points_expiry_days" value="${escapeHtml(s.points_expiry_days)}">
      </div>
      <div class="col-6 col-md-3 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="loy-review" name="review_points_enabled" ${s.review_points_enabled ? 'checked' : ''}>
          <label class="form-check-label small" for="loy-review">Points for reviews</label>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Points per approved review</label>
        <input class="form-control form-control-sm" type="number" min="0" name="points_per_review" value="${escapeHtml(s.points_per_review)}">
      </div>
      <div class="col-6 col-md-3 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="loy-referral" name="referral_points_enabled" ${s.referral_points_enabled ? 'checked' : ''}>
          <label class="form-check-label small" for="loy-referral">Points for referrals</label>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Points per referral</label>
        <input class="form-control form-control-sm" type="number" min="0" name="points_per_referral" value="${escapeHtml(s.points_per_referral)}">
      </div>
      <div class="col-12"><button class="btn btn-sm btn-dark" type="submit">Save settings</button></div>
    </form>`;
}

function ledgerRow(row) {
  const isCredit = row.direction === 'credit';

  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td>
        <span class="badge ${isCredit ? 'text-bg-success' : 'text-bg-secondary'}">${isCredit ? 'Earned' : 'Spent'}</span>
        <span class="small ms-1">${escapeHtml(SOURCE_LABEL[row.source] || row.source)}</span>
      </td>
      <td class="small">${escapeHtml(row.narration)}</td>
      <td class="small text-muted">${row.reference_type ? `${escapeHtml(row.reference_type)} · ${escapeHtml(row.reference_id)}` : '—'}</td>
      <td class="text-end ${isCredit ? 'text-success' : ''}">${isCredit ? '+' : '−'}${escapeHtml(row.points)}</td>
      <td class="text-end small text-muted">${escapeHtml(row.balance_after)}</td>
    </tr>`;
}

async function renderCustomerLoyalty(host) {
  host.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>';

  try {
    const ledgerResponse = await api.get(`/admin/loyalty/accounts/${encodeURIComponent(customer.uuid)}/ledger`, { page: ledgerPage, per_page: 25 });
    const items = ledgerResponse.data || [];
    const meta = ledgerResponse.meta || {};
    const balance = items.length > 0 ? Number(items[0].balance_after) : 0;

    host.innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h5 mb-0">${escapeHtml(customer.full_name)}</h2>
          <div class="small text-muted">${escapeHtml(customer.mobile)}</div>
        </div>
        <button class="btn btn-sm btn-outline-secondary" data-change-customer type="button">Search another customer</button>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-4"><div class="card h-100"><div class="card-body">
          <div class="small text-muted">Current balance</div>
          <div class="fs-4 fw-semibold">${escapeHtml(balance)} point(s)</div>
        </div></div></div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Manual adjustment</div>
        <div class="card-body">
          <form class="row g-2" data-adjust-form>
            <div class="col-6 col-md-3"><input class="form-control form-control-sm" type="number" min="1" name="points" placeholder="Points" required></div>
            <div class="col-6 col-md-3">
              <select class="form-select form-select-sm" name="direction">
                <option value="credit">Add (credit)</option>
                <option value="debit">Deduct (debit)</option>
              </select>
            </div>
            <div class="col-12 col-md-4"><input class="form-control form-control-sm" name="reason" placeholder="Reason (required)" required minlength="3"></div>
            <div class="col-12 col-md-2"><button class="btn btn-sm btn-dark w-100" type="submit">Apply</button></div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white fw-semibold">Points ledger</div>
        <div class="table-responsive">
          ${items.length === 0 ? `<div class="p-3">${emptyState('No activity yet', 'Points earned, spent or adjusted will appear here.')}</div>` : `
            <table class="table table-tight table-hover mb-0">
              <thead><tr><th>Date</th><th>Type</th><th>Reason</th><th>Reference</th><th class="text-end">Points</th><th class="text-end">Balance after</th></tr></thead>
              <tbody>${items.map(ledgerRow).join('')}</tbody>
            </table>`}
        </div>
        ${meta.total_pages > 1 ? `
          <div class="d-flex justify-content-between align-items-center p-2 small text-muted">
            <span>Page ${meta.page} of ${meta.total_pages}</span>
            <span class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-secondary" data-page-prev ${meta.page <= 1 ? 'disabled' : ''}>Previous</button>
              <button class="btn btn-sm btn-outline-secondary" data-page-next ${meta.page >= meta.total_pages ? 'disabled' : ''}>Next</button>
            </span>
          </div>` : ''}
      </div>`;

    host.querySelector('[data-change-customer]').addEventListener('click', () => {
      customer = null;
      ledgerPage = 1;
      render();
    });

    const prev = host.querySelector('[data-page-prev]');
    const next = host.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { ledgerPage -= 1; renderCustomerLoyalty(host); });
    if (next) next.addEventListener('click', () => { ledgerPage += 1; renderCustomerLoyalty(host); });

    host.querySelector('[data-adjust-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type=submit]');
      const body = Object.fromEntries(new FormData(form));

      setBusy(button, true, 'Saving');

      try {
        await api.post(`/admin/loyalty/accounts/${encodeURIComponent(customer.uuid)}/adjust`, body);
        toast('Points adjusted.');
        renderCustomerLoyalty(host);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  } catch (error) {
    host.innerHTML = '';
    showError(error, host);
  }
}

async function render() {
  root.innerHTML = `
    <h1 class="h4 mb-3">Loyalty</h1>
    <div data-dashboard><div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div></div>
    <div class="card mb-4">
      <div class="card-header bg-white fw-semibold">Program settings</div>
      <div class="card-body" data-settings-panel>
        <div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
      </div>
    </div>
    <div class="card mb-3"><div class="card-body" data-search-panel></div></div>
    <div data-loyalty-panel></div>`;

  const dashboardHost = root.querySelector('[data-dashboard]');
  const settingsHost = root.querySelector('[data-settings-panel]');

  api.get('/admin/loyalty/summary').then((response) => {
    if (dashboardHost) dashboardHost.innerHTML = dashboardTiles(response.data.summary || {});
  }).catch((error) => {
    if (dashboardHost) dashboardHost.innerHTML = '';
    showError(error, dashboardHost);
  });

  const loadSettings = () => api.get('/admin/loyalty/settings').then((response) => {
    settingsHost.innerHTML = settingsPanel(response.data.settings || {});

    settingsHost.querySelector('[data-settings-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type=submit]');
      const body = Object.fromEntries(new FormData(form));
      // Checkbox switches only appear in FormData when checked — an
      // unchecked switch must still be sent as false, not silently omitted.
      ['enabled', 'review_points_enabled', 'referral_points_enabled'].forEach((key) => {
        body[key] = form.querySelector(`[name="${key}"]`).checked;
      });

      setBusy(button, true, 'Saving');

      try {
        await api.patch('/admin/loyalty/settings', body);
        toast('Settings saved.');
        await loadSettings();
        dashboardHost.innerHTML = '<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>';
        const summaryResponse = await api.get('/admin/loyalty/summary');
        dashboardHost.innerHTML = dashboardTiles(summaryResponse.data.summary || {});
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  }).catch((error) => {
    settingsHost.innerHTML = '';
    showError(error, settingsHost);
  });

  loadSettings();

  const searchPanel = root.querySelector('[data-search-panel]');
  const loyaltyPanel = root.querySelector('[data-loyalty-panel]');

  if (customer) {
    searchPanel.parentElement.hidden = true;
    renderCustomerLoyalty(loyaltyPanel);
    return;
  }

  searchPanel.parentElement.hidden = false;
  loyaltyPanel.innerHTML = '';
  searchPanel.innerHTML = `
    <label class="form-label small mb-1">Find a customer by mobile number</label>
    <div class="input-group" style="max-width:26rem">
      <input class="form-control" data-customer-mobile placeholder="Mobile number" inputmode="numeric" autocomplete="off">
      <button class="btn btn-dark" type="button" data-find-customer>Find</button>
    </div>
    <div class="small text-danger mt-2" data-search-error></div>`;

  const runSearch = async () => {
    const mobile = searchPanel.querySelector('[data-customer-mobile]').value.trim();
    if (!mobile) return;

    const errorBox = searchPanel.querySelector('[data-search-error]');
    errorBox.textContent = '';

    try {
      const response = await api.get('/admin/pos/customers', { mobile });
      customer = { uuid: response.data.uuid, full_name: response.data.full_name, mobile: response.data.mobile };
      ledgerPage = 1;
      render();
    } catch (error) {
      errorBox.textContent = error.message || 'That customer could not be found.';
    }
  };

  searchPanel.querySelector('[data-find-customer]').addEventListener('click', runSearch);
  searchPanel.querySelector('[data-customer-mobile]').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); runSearch(); }
  });
}

const mounted = await mountConsole('loyalty.html');

if (mounted) {
  root = mounted.root;

  if (!['administrator', 'super_admin'].includes(String(mounted.user.role))) {
    root.innerHTML = emptyState('Administrators only', 'Ask an administrator to manage the loyalty program.');
  } else {
    const prefillMobile = queryParam('mobile');

    if (prefillMobile) {
      try {
        const response = await api.get('/admin/pos/customers', { mobile: prefillMobile });
        customer = { uuid: response.data.uuid, full_name: response.data.full_name, mobile: response.data.mobile };
      } catch { /* fall through to the search screen */ }
    }

    render();
  }
}
