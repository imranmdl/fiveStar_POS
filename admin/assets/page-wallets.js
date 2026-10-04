/**
 * Wallets: a customer's balance, their full ledger, and the admin actions on
 * it — credit, debit, freeze/unfreeze, export. Every figure and action here
 * is a thin UI over WalletService, which already does the real work (row
 * locks, idempotency, an append-only ledger, audit logging on every credit
 * and debit); this page has no business logic of its own.
 *
 * Administrator only, matching the API's own gate on every /admin/wallet/*
 * route.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         emptyState, queryParam } from './console.js?v=9';

let root = null;
let customer = null; // { uuid, full_name, mobile }
let statementPage = 1;

const SOURCE_LABEL = {
  referral_reward: 'Referral reward',
  referral_signup_bonus: 'Referral sign-up bonus',
  order_refund: 'Refund',
  promotional: 'Promotional credit',
  cashback: 'Cashback',
  redemption: 'Spent on an order',
  expiry: 'Expired',
  admin_adjustment: 'Admin adjustment',
};

async function downloadCsv(path, fallbackName) {
  const blob = await api.downloadFile(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

// Which tiles open which drill-down, and how each one's list is labelled —
// shared between dashboardTiles() (what renders the button) and
// openWalletDrilldownModal() (what the button opens).
const TILE_INFO = {
  balance: { title: 'Total wallet balance — accounts holding a balance' },
  refund_credits: { title: 'Total refund credits — credited back after a refund' },
  credits: { title: 'Total wallet credits — every credit, any reason' },
  debits: { title: 'Total wallet debits — every debit, any reason' },
  pending_refunds: { title: 'Pending refunds — still waiting on the gateway' },
};

function dashboardTiles(wallet) {
  const pr = wallet.pending_refunds || { count: 0, amount: 0 };

  const tile = (key, inner) => `
    <div class="col">
      <div class="card h-100 wallet-dash-tile" style="cursor:pointer" data-wallet-tile="${key}" role="button" tabindex="0">
        <div class="card-body">${inner}</div>
      </div>
    </div>`;

  return `
    <div class="row row-cols-2 row-cols-lg-5 g-3 mb-4">
      ${tile('balance', `
        <div class="small text-muted">Total wallet balance</div>
        <div class="fs-4 fw-semibold">${formatMoney(wallet.total_balance)}</div>
        <div class="small text-muted">${escapeHtml(wallet.account_count)} account(s) &middot; click for details</div>`)}
      ${tile('refund_credits', `
        <div class="small text-muted">Total refund credits</div>
        <div class="fs-4 fw-semibold">${formatMoney(wallet.total_refund_credits)}</div>
        <div class="small text-muted">click for details</div>`)}
      ${tile('credits', `
        <div class="small text-muted">Total wallet credits</div>
        <div class="fs-4 fw-semibold text-success">${formatMoney(wallet.total_credits)}</div>
        <div class="small text-muted">click for details</div>`)}
      ${tile('debits', `
        <div class="small text-muted">Total wallet debits</div>
        <div class="fs-4 fw-semibold">${formatMoney(wallet.total_debits)}</div>
        <div class="small text-muted">click for details</div>`)}
      ${tile('pending_refunds', `
        <div class="small text-muted">Pending refunds</div>
        <div class="fs-4 fw-semibold ${pr.count > 0 ? 'text-warning-emphasis' : ''}">${formatMoney(pr.amount)}</div>
        <div class="small text-muted">${escapeHtml(pr.count)} order(s) &middot; click for details</div>`)}
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

function walletTransactionRow(row) {
  const isCredit = row.direction === 'credit';

  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td class="small">
        <a href="wallets.html?customer_uuid=${encodeURIComponent(row.customer_uuid)}">${escapeHtml(row.customer_name)}</a>
        <div class="text-muted">${escapeHtml(row.customer_mobile)}</div>
      </td>
      <td class="small">${escapeHtml(SOURCE_LABEL[row.source] || row.source)}</td>
      <td class="small text-muted">${escapeHtml(row.narration)}</td>
      <td class="text-end ${isCredit ? 'text-success' : ''}">${isCredit ? '+' : '−'}${formatMoney(row.amount)}</td>
    </tr>`;
}

function walletAccountRow(row) {
  return `
    <tr>
      <td class="small">
        <a href="wallets.html?customer_uuid=${encodeURIComponent(row.uuid)}">${escapeHtml(row.customer_name)}</a>
        <div class="text-muted">${escapeHtml(row.customer_mobile)}</div>
      </td>
      <td class="text-end fw-semibold">${formatMoney(row.balance)}</td>
      <td class="text-end small text-muted">${formatMoney(row.lifetime_credited)}</td>
      <td class="text-end small text-muted">${formatMoney(row.lifetime_debited)}</td>
      <td>${row.is_frozen ? '<span class="badge text-bg-danger">Frozen</span>' : ''}</td>
    </tr>`;
}

function pendingRefundRow(row) {
  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td class="small">
        <a href="orders.html?uuid=${encodeURIComponent(row.order_uuid)}" target="_blank">${escapeHtml(row.order_number)}</a>
        <div class="text-muted">${escapeHtml(row.customer_name)} · ${escapeHtml(row.customer_mobile)}</div>
      </td>
      <td class="small">${escapeHtml(row.reason)}</td>
      <td class="small text-uppercase">${escapeHtml(row.gateway || '—')}</td>
      <td><span class="badge text-bg-warning">${escapeHtml(row.status)}</span></td>
      <td class="text-end fw-semibold">${formatMoney(row.total_amount)}</td>
    </tr>`;
}

/** The drill-down behind clicking a Wallets dashboard tile — a real, itemised list behind every summary number. */
async function openWalletDrilldownModal(kind) {
  const host = modalHost('data-wallet-drilldown-modal');
  const info = TILE_INFO[kind];

  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">${escapeHtml(info.title)}</h2>
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
    if (kind === 'balance') {
      const response = await api.get('/admin/wallet/accounts', { per_page: 100 });
      const items = response.data || [];

      body.innerHTML = items.length === 0
        ? '<p class="text-muted small mb-0">No account currently holds a balance.</p>'
        : `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
             <thead><tr><th>Customer</th><th class="text-end">Balance</th><th class="text-end">Lifetime credited</th><th class="text-end">Lifetime debited</th><th></th></tr></thead>
             <tbody>${items.map(walletAccountRow).join('')}</tbody>
           </table></div>`;

      return;
    }

    if (kind === 'pending_refunds') {
      const response = await api.get('/admin/wallet/pending-refunds', { per_page: 100 });
      const items = response.data || [];

      body.innerHTML = items.length === 0
        ? '<p class="text-muted small mb-0">Nothing is waiting on a refund right now.</p>'
        : `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
             <thead><tr><th>Since</th><th>Order</th><th>Reason</th><th>Gateway</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
             <tbody>${items.map(pendingRefundRow).join('')}</tbody>
           </table></div>`;

      return;
    }

    const flow = kind === 'debits' ? 'debit' : 'credit';
    const source = kind === 'refund_credits' ? 'order_refund' : '';
    const response = await api.get('/admin/wallet/transactions', { flow, source, per_page: 100 });
    const items = response.data || [];

    body.innerHTML = items.length === 0
      ? '<p class="text-muted small mb-0">No matching transactions yet.</p>'
      : `<div class="table-responsive"><table class="table table-tight table-hover mb-0">
           <thead><tr><th>Date</th><th>Customer</th><th>Reason</th><th>Note</th><th class="text-end">Amount</th></tr></thead>
           <tbody>${items.map(walletTransactionRow).join('')}</tbody>
         </table></div>`;
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

function transactionRow(row) {
  const isCredit = row.direction === 'credit';

  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td>
        <span class="badge ${isCredit ? 'text-bg-success' : 'text-bg-secondary'}">${isCredit ? 'Credit' : 'Debit'}</span>
        <span class="small ms-1">${escapeHtml(SOURCE_LABEL[row.source] || row.source)}</span>
      </td>
      <td class="small">${escapeHtml(row.narration)}</td>
      <td class="small text-muted">${row.reference.type ? `${escapeHtml(row.reference.type)} · ${escapeHtml(row.reference.id)}` : '—'}</td>
      <td class="text-end ${isCredit ? 'text-success' : ''}">${isCredit ? '+' : '−'}${formatMoney(row.amount)}</td>
      <td class="text-end small text-muted">${formatMoney(row.balance_after)}</td>
    </tr>`;
}

async function renderCustomerWallet(host) {
  host.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>';

  try {
    const [walletResponse, statementResponse] = await Promise.all([
      api.get(`/admin/wallet/${encodeURIComponent(customer.uuid)}`),
      api.get(`/admin/wallet/${encodeURIComponent(customer.uuid)}/statement`, { page: statementPage, per_page: 25 }),
    ]);

    const wallet = walletResponse.data.wallet;
    const integrity = walletResponse.data.integrity;
    const items = statementResponse.data || [];
    const meta = statementResponse.meta || {};

    host.innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h5 mb-0">${escapeHtml(customer.full_name)}</h2>
          <div class="small text-muted">${escapeHtml(customer.mobile)}</div>
        </div>
        <button class="btn btn-sm btn-outline-secondary" data-change-customer type="button">Search another customer</button>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
          <div class="small text-muted">Balance</div>
          <div class="fs-4 fw-semibold">${formatMoney(wallet.balance)}</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
          <div class="small text-muted">Lifetime credited</div>
          <div class="fs-5">${formatMoney(wallet.lifetime_credited)}</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
          <div class="small text-muted">Lifetime debited</div>
          <div class="fs-5">${formatMoney(wallet.lifetime_debited)}</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body">
          <div class="small text-muted">Status</div>
          <div class="fs-5">${wallet.is_frozen
            ? '<span class="badge text-bg-danger">Frozen</span>'
            : '<span class="badge text-bg-success">Active</span>'}</div>
        </div></div></div>
      </div>

      ${wallet.is_frozen ? `<div class="alert alert-warning small">Frozen: ${escapeHtml(wallet.frozen_reason || 'no reason on file')}. Credits still post; spending is blocked.</div>` : ''}
      ${!integrity.matches ? `<div class="alert alert-danger small">The ledger and the cached balance disagree for this account (ledger says ${formatMoney(integrity.derived_balance)}, stored balance reads ${formatMoney(integrity.cached_balance)}) — worth investigating before trusting the figure above.</div>` : ''}

      <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Add credit</div>
            <div class="card-body">
              <form class="row g-2" data-credit-form>
                <div class="col-6"><input class="form-control form-control-sm" type="number" step="0.01" min="1" name="amount" placeholder="Amount" required></div>
                <div class="col-6">
                  <select class="form-select form-select-sm" name="source">
                    <option value="admin_adjustment">Admin adjustment</option>
                    <option value="promotional">Promotional credit</option>
                    <option value="cashback">Cashback</option>
                    <option value="order_refund">Refund</option>
                  </select>
                </div>
                <div class="col-12"><input class="form-control form-control-sm" name="narration" placeholder="Reason (required)" required minlength="3"></div>
                <div class="col-6"><input class="form-control form-control-sm" type="number" min="1" name="expiry_days" placeholder="Expires in days (optional)"></div>
                <div class="col-6"><button class="btn btn-sm btn-success w-100" type="submit">Add credit</button></div>
              </form>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <div class="card">
            <div class="card-header bg-white fw-semibold">Deduct balance</div>
            <div class="card-body">
              <form class="row g-2" data-debit-form>
                <div class="col-6"><input class="form-control form-control-sm" type="number" step="0.01" min="1" name="amount" placeholder="Amount" required></div>
                <div class="col-6">
                  ${wallet.is_frozen
                    ? '<button class="btn btn-sm btn-outline-success w-100" type="button" data-unfreeze>Unfreeze wallet</button>'
                    : '<button class="btn btn-sm btn-outline-danger w-100" type="button" data-freeze>Freeze wallet</button>'}
                </div>
                <div class="col-12"><input class="form-control form-control-sm" name="narration" placeholder="Reason (required)" required minlength="3"></div>
                <div class="col-12"><button class="btn btn-sm btn-outline-dark w-100" type="submit">Deduct</button></div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Transactions</span>
          <button class="btn btn-sm btn-outline-secondary" data-export-customer type="button">Export this customer's CSV</button>
        </div>
        <div class="table-responsive">
          ${items.length === 0 ? `<div class="p-3">${emptyState('No transactions yet', 'Credits and debits will appear here.')}</div>` : `
            <table class="table table-tight table-hover mb-0">
              <thead><tr><th>Date</th><th>Type</th><th>Reason</th><th>Reference</th><th class="text-end">Amount</th><th class="text-end">Balance after</th></tr></thead>
              <tbody>${items.map(transactionRow).join('')}</tbody>
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
      statementPage = 1;
      render();
    });

    const prev = host.querySelector('[data-page-prev]');
    const next = host.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { statementPage -= 1; renderCustomerWallet(host); });
    if (next) next.addEventListener('click', () => { statementPage += 1; renderCustomerWallet(host); });

    host.querySelector('[data-export-customer]').addEventListener('click', async (event) => {
      setBusy(event.currentTarget, true, 'Preparing');
      try {
        // The statement itself, not the all-customers export — a customer's
        // own history, as CSV, for their own record or a dispute.
        const rows = (await api.get(`/admin/wallet/${encodeURIComponent(customer.uuid)}/statement`, { per_page: 5000 })).data || [];
        const header = 'date,direction,source,narration,reference_type,reference_id,amount,balance_after\n';
        const csv = header + rows.map((r) => [
          r.created_date, r.direction, r.source, `"${String(r.narration).replace(/"/g, '""')}"`,
          r.reference.type || '', r.reference.id || '', r.amount, r.balance_after,
        ].join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `wallet_${customer.mobile}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
      } catch (error) {
        showError(error);
      } finally {
        setBusy(event.currentTarget, false);
      }
    });

    host.querySelector('[data-credit-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type=submit]');
      const body = Object.fromEntries(new FormData(form));
      if (!body.expiry_days) delete body.expiry_days;

      setBusy(button, true, 'Saving');

      try {
        await api.post(`/admin/wallet/${encodeURIComponent(customer.uuid)}/credit`, body);
        toast('Wallet credited.');
        renderCustomerWallet(host);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    host.querySelector('[data-debit-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type=submit]');
      const body = Object.fromEntries(new FormData(form));

      setBusy(button, true, 'Saving');

      try {
        await api.post(`/admin/wallet/${encodeURIComponent(customer.uuid)}/debit`, body);
        toast('Wallet debited.');
        renderCustomerWallet(host);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    const freezeBtn = host.querySelector('[data-freeze]');
    if (freezeBtn) {
      freezeBtn.addEventListener('click', async () => {
        const reason = window.prompt('Why is this wallet being frozen? Shown to the customer.');
        if (!reason) return;

        setBusy(freezeBtn, true, 'Saving');

        try {
          await api.post(`/admin/wallet/${encodeURIComponent(customer.uuid)}/freeze`, { reason });
          toast('Wallet frozen.');
          renderCustomerWallet(host);
        } catch (error) {
          setBusy(freezeBtn, false);
          showError(error);
        }
      });
    }

    const unfreezeBtn = host.querySelector('[data-unfreeze]');
    if (unfreezeBtn) {
      unfreezeBtn.addEventListener('click', async () => {
        setBusy(unfreezeBtn, true, 'Saving');

        try {
          await api.post(`/admin/wallet/${encodeURIComponent(customer.uuid)}/unfreeze`);
          toast('Wallet unfrozen.');
          renderCustomerWallet(host);
        } catch (error) {
          setBusy(unfreezeBtn, false);
          showError(error);
        }
      });
    }
  } catch (error) {
    host.innerHTML = '';
    showError(error, host);
  }
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Wallets</h1>
      <button class="btn btn-sm btn-outline-secondary" data-export-all type="button">Export all wallet transactions</button>
    </div>
    <div data-dashboard><div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div></div>
    <div class="card mb-3"><div class="card-body" data-search-panel></div></div>
    <div data-wallet-panel></div>`;

  root.querySelector('[data-export-all]').addEventListener('click', async (event) => {
    setBusy(event.currentTarget, true, 'Preparing');
    try {
      await downloadCsv('/admin/export/wallet_transactions', 'wallet_transactions.csv');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(event.currentTarget, false);
    }
  });

  api.get('/admin/dashboard').then((response) => {
    const dash = root.querySelector('[data-dashboard]');
    if (!dash) return;
    dash.innerHTML = dashboardTiles(response.data.wallet || {});
    dash.querySelectorAll('[data-wallet-tile]').forEach((tile) => {
      tile.addEventListener('click', () => openWalletDrilldownModal(tile.dataset.walletTile));
      tile.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openWalletDrilldownModal(tile.dataset.walletTile);
        }
      });
    });
  }).catch(() => {
    const dash = root.querySelector('[data-dashboard]');
    if (dash) dash.innerHTML = '';
  });

  const searchPanel = root.querySelector('[data-search-panel]');
  const walletPanel = root.querySelector('[data-wallet-panel]');

  if (customer) {
    searchPanel.parentElement.hidden = true;
    renderCustomerWallet(walletPanel);
    return;
  }

  searchPanel.parentElement.hidden = false;
  walletPanel.innerHTML = '';
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
      statementPage = 1;
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

const mounted = await mountConsole('wallets.html');

if (mounted) {
  root = mounted.root;

  if (String(mounted.user.role) !== 'administrator') {
    root.innerHTML = emptyState('Administrators only', 'Ask an administrator to manage wallets.');
  } else {
    const prefillUuid = queryParam('customer_uuid');
    const prefillMobile = queryParam('mobile');

    if (prefillUuid) {
      // Straight from the wallet endpoint itself — works even where the
      // caller (e.g. the Customers report) only ever had a masked mobile
      // number to show, never a real one to look up by.
      try {
        const response = await api.get(`/admin/wallet/${encodeURIComponent(prefillUuid)}`);
        customer = response.data.customer;
      } catch { /* fall through to the search screen */ }
    } else if (prefillMobile) {
      try {
        const response = await api.get('/admin/pos/customers', { mobile: prefillMobile });
        customer = { uuid: response.data.uuid, full_name: response.data.full_name, mobile: response.data.mobile };
      } catch { /* fall through to the search screen */ }
    }
    render();
  }
}
