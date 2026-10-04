/**
 * Customer Dues: every POS sale a registered customer was knowingly left
 * owing money on (PosSaleService's "accept partial payment" path), how much
 * of it is still outstanding, and recording a payment against it. Every
 * figure here is a thin UI over PosDuePaymentService, which does the real
 * work (the overpayment guard, the duplicate-reference guard, recomputing
 * payment_status from the ledger) — this page has no business logic of its
 * own, the same relationship page-wallets.js has to WalletService.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         emptyState, badge, queryParam } from './console.js?v=9';

const state = {
  filter: queryParam('status') || 'all', // all | partial | unpaid | overdue
  page: 1,
};

let root = null;
let customer = null; // { uuid, full_name, mobile } — narrows the list to one customer when set

function statusBadge(status) {
  if (status === 'paid') return badge('paid', 'Fully Paid');
  if (status === 'partial') return badge('partial', 'Partially Paid');

  return badge('unpaid', 'Unpaid');
}

function dashboardTiles(dues) {
  return `
    <div class="row row-cols-2 row-cols-lg-5 g-3 mb-4">
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Total due</div>
        <div class="fs-4 fw-semibold ${dues.total_due > 0 ? 'text-warning-emphasis' : ''}">${formatMoney(dues.total_due)}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Partially paid</div>
        <div class="fs-4 fw-semibold">${escapeHtml(dues.partially_paid_count)}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Fully paid</div>
        <div class="fs-4 fw-semibold text-success">${escapeHtml(dues.fully_paid_count)}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Overdue (${escapeHtml(dues.overdue_after_days)}+ days)</div>
        <div class="fs-4 fw-semibold ${dues.overdue_count > 0 ? 'text-danger' : ''}">${escapeHtml(dues.overdue_count)}</div>
        <div class="small text-muted">${formatMoney(dues.overdue_amount)}</div>
      </div></div></div>
      <div class="col"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Collected today</div>
        <div class="fs-4 fw-semibold text-success">${formatMoney(dues.collected_today)}</div>
      </div></div></div>
    </div>`;
}

/**
 * A form modal (amount, method, reference, notes) resolving to the payload
 * to POST, or null if the cashier backed out — same self-contained-modal
 * shape as page-orders.js's chooseRefundMethod().
 */
function recordPaymentModal(invoice) {
  return new Promise((resolve) => {
    let host = document.querySelector('[data-record-payment-modal]');

    if (!host) {
      host = document.createElement('div');
      host.setAttribute('data-record-payment-modal', '');
      document.body.appendChild(host);
    }

    host.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal>
        <div class="modal-dialog">
          <div class="modal-content">
            <form data-form>
              <div class="modal-header">
                <h2 class="h6 modal-title">Record a payment — ${escapeHtml(invoice.sale_number)}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
              </div>
              <div class="modal-body">
                <p class="small text-muted">Balance due: <strong>${formatMoney(invoice.balance_due)}</strong> of ${formatMoney(invoice.grand_total)}</p>
                <div class="mb-2">
                  <label class="form-label small mb-1">Amount</label>
                  <input class="form-control" type="number" step="0.01" min="0.01" max="${invoice.balance_due}" name="amount" value="${invoice.balance_due}" required>
                </div>
                <div class="mb-2">
                  <label class="form-label small mb-1">Payment method</label>
                  <select class="form-select" name="payment_method">
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="card">Card</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="mb-2">
                  <label class="form-label small mb-1">Reference number (optional)</label>
                  <input class="form-control" name="reference_number" placeholder="UPI/card transaction ID">
                </div>
                <div class="mb-0">
                  <label class="form-label small mb-1">Notes (optional)</label>
                  <input class="form-control" name="notes" maxlength="255">
                </div>
                <div class="small text-danger mt-2" data-modal-error></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Record payment</button>
              </div>
            </form>
          </div>
        </div>
      </div>`;

    const modalEl = host.querySelector('[data-modal]');
    const modal = new window.bootstrap.Modal(modalEl);
    let settled = false;

    const finish = (value) => {
      if (settled) return;
      settled = true;
      modal.hide();
      resolve(value);
    };

    host.querySelector('[data-form]').addEventListener('submit', (event) => {
      event.preventDefault();
      const body = Object.fromEntries(new FormData(event.currentTarget));
      const amount = Number(body.amount);

      if (!(amount > 0) || amount > Number(invoice.balance_due) + 0.005) {
        host.querySelector('[data-modal-error]').textContent = 'Enter an amount greater than zero and no more than the balance due.';

        return;
      }

      if (!body.reference_number) delete body.reference_number;
      if (!body.notes) delete body.notes;

      finish(body);
    });

    modalEl.addEventListener('hidden.bs.modal', () => finish(null), { once: true });
    modal.show();
  });
}

const METHOD_LABEL = { cash: 'Cash', upi: 'UPI', card: 'Card', other: 'Other' };

/**
 * Payment details behind clicking a customer name or sale number — the full
 * payment history (each entry's own date and time, not just the running
 * total this page's list already showed) plus the item lines and bill
 * summary. Reuses /admin/invoices/{uuid} (InvoiceService — a POS sale by
 * another name, see its own doc comment), the same read endpoint the
 * Invoice Tracking Center and the Orders page's counter-sale drawer already
 * use, so this is a second view onto data this admin console already
 * fetches elsewhere, not a new source of truth.
 */
async function openPaymentDetailsModal(uuid) {
  let host = document.querySelector('[data-payment-details-modal]');

  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-payment-details-modal', '');
    document.body.appendChild(host);
  }

  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">Payment details</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
    const response = await api.get(`/admin/invoices/${encodeURIComponent(uuid)}`);
    const sale = response.data;
    const remaining = Number(sale.grand_total) - Number(sale.amount_paid);

    host.querySelector('.modal-title').textContent = `Payment details — ${sale.sale_number}`;

    body.innerHTML = `
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <div class="fw-semibold">${escapeHtml(sale.customer_name || 'Walk-in customer')}</div>
          <div class="small text-muted">${escapeHtml(sale.customer_mobile || 'No phone on file')}</div>
          <div class="small text-muted">Rung up ${escapeHtml(String(sale.created_date || '').slice(0, 16).replace('T', ' '))} by ${escapeHtml(sale.cashier_name || '—')}</div>
        </div>
        ${statusBadge(sale.payment_status)}
      </div>

      <div class="row text-center g-2 mb-3">
        <div class="col"><div class="small text-muted">Total</div><div class="fw-semibold">${formatMoney(sale.grand_total)}</div></div>
        <div class="col"><div class="small text-muted">Paid</div><div class="fw-semibold text-success">${formatMoney(sale.amount_paid)}</div></div>
        <div class="col"><div class="small text-muted">Remaining</div><div class="fw-semibold ${remaining > 0 ? 'text-danger' : ''}">${formatMoney(Math.max(0, remaining))}</div></div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold py-2">Payment history</div>
        ${sale.due_payments && sale.due_payments.length ? `
          <table class="table table-sm mb-0">
            <thead><tr><th>Date &amp; time</th><th>Amount</th><th>Method</th><th>Notes</th></tr></thead>
            <tbody>${sale.due_payments.map((p) => `
              <tr>
                <td class="small">${escapeHtml(String(p.created_date || p.payment_date || '').replace('T', ' ').slice(0, 16))}</td>
                <td class="small">${formatMoney(p.amount)}</td>
                <td class="small text-uppercase">${escapeHtml(METHOD_LABEL[p.payment_method] || p.payment_method)}</td>
                <td class="small text-muted">${escapeHtml(p.notes || '')}</td>
              </tr>`).join('')}</tbody>
          </table>` : '<div class="card-body small text-muted">No separate payment entries — settled in full at the till.</div>'}
      </div>

      <div class="card">
        <div class="card-header bg-white fw-semibold py-2">Items</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
            <tbody>${(sale.items || []).map((i) => `
              <tr>
                <td class="small">${escapeHtml(i.product_name)} <span class="text-muted">(${escapeHtml(i.variant_name)})</span></td>
                <td class="small">${escapeHtml(i.quantity)}</td>
                <td class="small">${formatMoney(i.unit_price)}</td>
                <td class="small">${formatMoney(i.line_total)}</td>
              </tr>`).join('')}</tbody>
          </table>
        </div>
      </div>`;
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

function bindDetailLinks(container) {
  container.querySelectorAll('[data-open-details]').forEach((button) => {
    button.addEventListener('click', () => openPaymentDetailsModal(button.dataset.openDetails));
  });
}

function invoiceRow(invoice, { showCustomer }) {
  return `
    <tr>
      ${showCustomer ? `<td>
        <button type="button" class="btn btn-link p-0 fw-semibold text-decoration-none text-start" data-open-details="${escapeHtml(invoice.uuid)}">${escapeHtml(invoice.customer_name)}</button>
        <div class="small text-muted">${escapeHtml(invoice.customer_mobile)}</div>
      </td>` : ''}
      <td class="small">
        <button type="button" class="btn btn-link p-0 text-decoration-none text-start" data-open-details="${escapeHtml(invoice.uuid)}">${escapeHtml(invoice.sale_number)}</button>
        <div class="text-muted">${escapeHtml(String(invoice.created_date || '').slice(0, 16).replace('T', ' '))}</div>
      </td>
      <td class="text-end small">${formatMoney(invoice.grand_total)}</td>
      <td class="text-end small">${formatMoney(invoice.amount_paid)}</td>
      <td class="text-end fw-semibold ${Number(invoice.balance_due) > 0 ? 'text-warning-emphasis' : ''}">${formatMoney(invoice.balance_due)}</td>
      <td>${statusBadge(invoice.payment_status)}${invoice.is_overdue ? ' <span class="badge text-bg-danger">Overdue</span>' : ''}</td>
      <td class="text-end">${Number(invoice.balance_due) > 0.005
        ? `<button class="btn btn-sm btn-outline-success" data-record-payment>Record payment</button>`
        : '<span class="small text-muted">Settled</span>'}</td>
    </tr>`;
}

function bindPaymentButtons(container, invoices, onSaved) {
  container.querySelectorAll('[data-record-payment]').forEach((button, index) => {
    button.addEventListener('click', async () => {
      const invoice = invoices[index];
      const payload = await recordPaymentModal(invoice);
      if (!payload) return;

      setBusy(button, true, 'Saving');

      try {
        await api.post(`/admin/pos/sales/${encodeURIComponent(invoice.uuid)}/payments`, payload);
        toast('Payment recorded.');
        onSaved();
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  });
}

async function renderCustomerView(host) {
  host.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>';

  try {
    const response = await api.get(`/admin/customers/${encodeURIComponent(customer.uuid)}/dues`);
    const { invoices, total_outstanding: totalOutstanding } = response.data;

    host.innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h5 mb-0">${escapeHtml(customer.full_name)}</h2>
          <div class="small text-muted">${escapeHtml(customer.mobile)}</div>
        </div>
        <button class="btn btn-sm btn-outline-secondary" data-change-customer type="button">Browse all customers instead</button>
      </div>
      <div class="card mb-3"><div class="card-body">
        <div class="small text-muted">Total outstanding across ${escapeHtml(invoices.length)} invoice(s)</div>
        <div class="fs-3 fw-semibold ${totalOutstanding > 0 ? 'text-warning-emphasis' : 'text-success'}">${formatMoney(totalOutstanding)}</div>
      </div></div>
      <div class="card"><div class="card-body p-0">
        ${invoices.length === 0 ? `<div class="p-3">${emptyState('No credit sales', 'This customer has no sale that was ever left partly or fully due.')}</div>` : `
        <div class="table-responsive">
          <table class="table table-tight table-hover mb-0">
            <thead><tr><th>Sale</th><th class="text-end">Bill</th><th class="text-end">Paid</th><th class="text-end">Balance due</th><th>Status</th><th></th></tr></thead>
            <tbody>${invoices.map((invoice) => invoiceRow(invoice, { showCustomer: false })).join('')}</tbody>
          </table>
        </div>`}
      </div></div>`;

    host.querySelector('[data-change-customer]').addEventListener('click', () => {
      customer = null;
      render();
    });

    bindPaymentButtons(host, invoices, () => renderCustomerView(host));
    bindDetailLinks(host);
  } catch (error) {
    host.innerHTML = '';
    showError(error, host);
  }
}

async function renderAllDuesView(host) {
  host.innerHTML = `
    <div class="btn-group btn-group-sm mb-3" role="group">
      ${[['all', 'All'], ['partial', 'Partially Paid'], ['unpaid', 'Unpaid'], ['overdue', 'Overdue']].map(([value, label]) => `
        <button type="button" class="btn ${state.filter === value ? 'btn-dark' : 'btn-outline-secondary'}" data-status-filter="${value}">${label}</button>`).join('')}
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  host.querySelectorAll('[data-status-filter]').forEach((button) => {
    button.addEventListener('click', () => {
      state.filter = button.dataset.statusFilter;
      state.page = 1;
      renderAllDuesView(host);
    });
  });

  const list = host.querySelector('[data-list]');
  const filters = { page: state.page, per_page: 25 };

  if (state.filter === 'overdue') filters.overdue_only = 1;
  else if (state.filter !== 'all') filters.payment_status = state.filter;

  try {
    const response = await api.get('/admin/pos/dues', filters);
    const invoices = response.data || [];
    const meta = response.meta || {};

    if (invoices.length === 0) {
      list.innerHTML = emptyState('Nothing here', 'No credit sale matches this filter.');

      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Customer</th><th>Sale</th><th class="text-end">Bill</th><th class="text-end">Paid</th><th class="text-end">Balance due</th><th>Status</th><th></th></tr></thead>
          <tbody>${invoices.map((invoice) => invoiceRow(invoice, { showCustomer: true })).join('')}</tbody>
        </table>
      </div>
      ${meta.total_pages > 1 ? `
        <div class="d-flex justify-content-between align-items-center p-2 small text-muted">
          <span>Page ${meta.page} of ${meta.total_pages} · ${meta.total} invoice(s)</span>
          <span class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${meta.page >= meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.page -= 1; renderAllDuesView(host); });
    if (next) next.addEventListener('click', () => { state.page += 1; renderAllDuesView(host); });

    bindPaymentButtons(list, invoices, () => renderAllDuesView(host));
    bindDetailLinks(list);
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Customer Dues</h1>
    </div>
    <div data-dashboard><div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div></div>
    <div class="card mb-3"><div class="card-body" data-search-panel></div></div>
    <div data-dues-panel></div>`;

  api.get('/admin/dashboard').then((response) => {
    const dash = root.querySelector('[data-dashboard]');
    if (dash) dash.innerHTML = dashboardTiles(response.data.customer_dues || {});
  }).catch(() => {
    const dash = root.querySelector('[data-dashboard]');
    if (dash) dash.innerHTML = '';
  });

  const searchPanel = root.querySelector('[data-search-panel]');
  const duesPanel = root.querySelector('[data-dues-panel]');

  if (customer) {
    searchPanel.parentElement.hidden = true;
    renderCustomerView(duesPanel);

    return;
  }

  searchPanel.parentElement.hidden = false;
  searchPanel.innerHTML = `
    <label class="form-label small mb-1">Look up one customer's total outstanding balance</label>
    <div class="input-group" style="max-width:26rem">
      <input class="form-control" data-customer-mobile placeholder="Mobile number" inputmode="numeric" maxlength="10" autocomplete="off">
      <button class="btn btn-dark" type="button" data-find-customer>Find</button>
    </div>
    <div class="small text-danger mt-2" data-search-error></div>`;

  searchPanel.querySelector('[data-customer-mobile]').addEventListener('input', (event) => {
    event.target.value = event.target.value.replace(/\D/g, '').slice(0, 10);
  });

  const runSearch = async () => {
    const mobile = searchPanel.querySelector('[data-customer-mobile]').value.trim();
    if (!mobile) return;

    const errorBox = searchPanel.querySelector('[data-search-error]');
    errorBox.textContent = '';

    try {
      const response = await api.get('/admin/pos/customers', { mobile });
      customer = { uuid: response.data.uuid, full_name: response.data.full_name, mobile: response.data.mobile };
      render();
    } catch (error) {
      errorBox.textContent = error.message || 'That customer could not be found.';
    }
  };

  searchPanel.querySelector('[data-find-customer]').addEventListener('click', runSearch);
  searchPanel.querySelector('[data-customer-mobile]').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); runSearch(); }
  });

  renderAllDuesView(duesPanel);
}

const mounted = await mountConsole('customer-dues.html');

if (mounted) {
  root = mounted.root;
  render();
}
