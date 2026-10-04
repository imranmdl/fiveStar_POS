/**
 * Orders: the screen staff live in.
 *
 * The actions here are the ones performed dozens of times a day — mark packed,
 * book a courier, look up why something is stuck. Everything rarer is left to
 * the API.
 *
 * BR-005 IS THE SERVER'S RULE, NOT THIS SCREEN'S. An unpaid order cannot be
 * progressed, and the server enforces that regardless of what any client sends.
 * This screen disables the buttons anyway, so staff see the constraint before
 * they hit it rather than as a red error afterwards.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         badge, emptyState, queryParam } from './console.js?v=9';

const state = {
  view: queryParam('view') === 'counter' ? 'counter' : 'online',
  delivery: '',
  status: queryParam('status') || '',
  payment: queryParam('payment_status') || '',
  page: 1,
};

let root = null;

const FILTERS = [
  ['', 'All'],
  ['awaiting_payment', 'Awaiting payment'],
  ['confirmed', 'To pack'],
  ['packed', 'To ship'],
  ['shipped', 'In transit'],
  ['delivered', 'Delivered'],
  ['cancelled', 'Cancelled'],
];

const PAYMENT_FILTERS = [
  ['', 'All payments'],
  ['paid', 'Payment done'],
  ['pending', 'Payment not done'],
  ['processing', 'Payment processing'],
  ['failed', 'Payment failed'],
  ['refunded', 'Refunded'],
];

function row(order) {
  const paid = order.payment_status === 'paid' || order.payment_status === 'partially_refunded';

  return `
    <tr>
      <td>
        <a href="orders.html?uuid=${encodeURIComponent(order.uuid)}" class="fw-semibold text-decoration-none">
          ${escapeHtml(order.order_number)}
        </a>
        <div class="small text-muted">${escapeHtml(String(order.placed_date || '').slice(0, 16).replace('T', ' '))}</div>
      </td>
      <td>${escapeHtml(order.customer_name || '—')}</td>
      <td>${badge(order.status, String(order.status).replace(/_/g, ' '))}</td>
      <td>
        ${paid
          ? '<span class="badge text-bg-success">Paid</span>'
          : `<span class="badge text-bg-warning">${escapeHtml(order.payment_status)}</span>`}
      </td>
      <td class="text-end">${formatMoney(order.grand_total)}</td>
      <td class="text-end">
        <a class="btn btn-sm btn-outline-secondary"
           href="orders.html?uuid=${encodeURIComponent(order.uuid)}">Open</a>
      </td>
    </tr>`;
}

let isAdmin = false;

function viewTabs() {
  if (!isAdmin) return '';

  return `<div class="btn-group btn-group-sm" role="group" aria-label="Order type">
    ${[['online', 'Online orders'], ['counter', 'Counter (POS) sales']].map(([value, label]) => `
      <button type="button" class="btn ${state.view === value ? 'btn-primary' : 'btn-outline-primary'}" data-view="${value}">${label}</button>`).join('')}
  </div>`;
}

function wireViewTabs() {
  root.querySelectorAll('[data-view]').forEach((button) => {
    button.addEventListener('click', () => {
      state.view = button.dataset.view;
      state.page = 1;
      renderList();
    });
  });
}

const METHOD_LABEL = { cash: 'Cash', upi: 'UPI', card: 'Card (POS)', other: 'Other' };
const PAYMENT_STATUS_LABEL = { unpaid: 'Unpaid', partial: 'Partially Paid', paid: 'Paid' };

function counterRow(sale) {
  const delivered = sale.delivery_status === 'delivered';
  const balanceDue = Number(sale.balance_due || 0);

  return `
    <tr data-sale="${escapeHtml(sale.uuid)}">
      <td>
        <button type="button" class="btn btn-link p-0 fw-semibold text-decoration-none" data-open-sale="${escapeHtml(sale.uuid)}">${escapeHtml(sale.sale_number)}</button>
        <div class="small text-muted">${escapeHtml(String(sale.created_date || '').slice(0, 16).replace('T', ' '))} · ${escapeHtml(sale.cashier_name)}${sale.shop_label ? ' · ' + escapeHtml(sale.shop_label) : ''}</div>
      </td>
      <td class="small">
        ${sale.customer_name || sale.customer_mobile
          ? `${escapeHtml(sale.customer_name || 'Walk-in customer')}<div class="text-muted">${escapeHtml(sale.customer_mobile || '')}</div>`
          : '<span class="text-muted">Walk-in customer</span>'}
      </td>
      <td class="small">${sale.items.map((i) => `${escapeHtml(i.name)} × ${escapeHtml(i.quantity)}`).join('<br>')}</td>
      <td>
        ${badge(sale.payment_status, PAYMENT_STATUS_LABEL[sale.payment_status] || sale.payment_status)}
        ${sale.payment_status !== 'paid'
          ? `<div class="small text-muted">Paid ${formatMoney(sale.amount_paid)} of ${formatMoney(sale.grand_total)}</div>
             <div class="small ${balanceDue > 0 ? 'text-danger fw-semibold' : ''}">${formatMoney(balanceDue)} due</div>`
          : ''}
      </td>
      <td>
        ${delivered
          ? `<span class="badge text-bg-success">Delivered</span>
             <div class="small text-muted">${escapeHtml(String(sale.delivered_date || '').slice(0, 16).replace('T', ' '))}</div>`
          : `<span class="badge text-bg-warning">Not delivered</span>
             <div><button class="btn btn-sm btn-outline-success mt-1" data-deliver="${escapeHtml(sale.uuid)}">Mark delivered</button></div>`}
      </td>
      <td class="small">
        ${sale.reviews.length
          ? sale.reviews.map((r) => `
              <div><span class="text-warning">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</span>
                <span class="text-muted">${escapeHtml(r.product)}</span>
                ${badge(r.status, r.status)}
                ${r.body ? `<div>${escapeHtml(r.body)}</div>` : ''}</div>`).join('')
          : '<span class="text-muted">No review yet</span>'}
      </td>
      <td class="text-end">${formatMoney(sale.grand_total)}
        <div class="small text-muted">${escapeHtml(METHOD_LABEL[sale.payment_method] || sale.payment_method)}</div></td>
    </tr>`;
}

/** money(), null-safe. */
function money(value) {
  return formatMoney(Number(value || 0));
}

/**
 * Sale history detail — items, discounts, amounts and payment status — shown
 * ON THIS PANEL rather than sending the admin to the Invoice Tracking page.
 * Reuses the same read endpoint Invoice Tracking uses (/admin/invoices/{uuid},
 * a POS sale by another name — see InvoiceService's own doc comment) but
 * renders its own, simpler view here: no WhatsApp composer, no communication
 * history or timeline — just what this request asked for.
 */
async function openCounterSaleDetail(uuid) {
  const offcanvasEl = root.querySelector('[data-sale-offcanvas]');
  const body = offcanvasEl.querySelector('[data-sale-detail-body]');
  body.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div></div>';

  const bsOffcanvas = window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
  bsOffcanvas.show();

  try {
    const response = await api.get(`/admin/invoices/${encodeURIComponent(uuid)}`);
    renderCounterSaleDetail(response.data);
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

function renderCounterSaleDetail(sale) {
  const offcanvasEl = root.querySelector('[data-sale-offcanvas]');
  const body = offcanvasEl.querySelector('[data-sale-detail-body]');
  const remaining = Number(sale.grand_total) - Number(sale.amount_paid);

  offcanvasEl.querySelector('[data-sale-detail-title]').textContent = sale.sale_number;

  body.innerHTML = `
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <div class="fw-semibold">${escapeHtml(sale.customer_name || 'Walk-in customer')}</div>
        <div class="small text-muted">${escapeHtml(sale.customer_mobile || 'No phone on file')}</div>
      </div>
      ${badge(sale.payment_status, PAYMENT_STATUS_LABEL[sale.payment_status] || sale.payment_status)}
    </div>

    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold py-2">Payment</div>
      <div class="card-body">
        <div class="row text-center g-2 mb-2">
          <div class="col"><div class="small text-muted">Total</div><div class="fw-semibold">${money(sale.grand_total)}</div></div>
          <div class="col"><div class="small text-muted">Paid</div><div class="fw-semibold text-success">${money(sale.amount_paid)}</div></div>
          <div class="col"><div class="small text-muted">Remaining</div><div class="fw-semibold ${remaining > 0 ? 'text-danger' : ''}">${money(Math.max(0, remaining))}</div></div>
        </div>
        <div class="small text-muted mb-2">Payment method: <span class="text-uppercase">${escapeHtml(sale.payment_method)}</span> · Discount: ${money(sale.discount_amount)}</div>
        ${sale.due_payments && sale.due_payments.length ? `
          <table class="table table-sm mb-0">
            <thead><tr><th>Date</th><th>Amount</th><th>Method</th></tr></thead>
            <tbody>${sale.due_payments.map((p) => `<tr><td class="small">${escapeHtml(p.payment_date)}</td><td class="num">${money(p.amount)}</td><td class="small text-uppercase">${escapeHtml(p.payment_method)}</td></tr>`).join('')}</tbody>
          </table>` : '<div class="small text-muted">No due-payment history.</div>'}
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold py-2">Items</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Discount</th><th>GST</th><th>Total</th></tr></thead>
          <tbody>${(sale.items || []).map((i) => `
            <tr>
              <td class="small">${escapeHtml(i.product_name)} <span class="text-muted">(${escapeHtml(i.variant_name)})</span></td>
              <td class="num">${escapeHtml(i.quantity)}</td>
              <td class="num">${money(i.unit_price)}</td>
              <td class="num">${money(i.discount_amount)}</td>
              <td class="num">${money(i.tax_amount)}</td>
              <td class="num">${money(i.line_total)}</td>
            </tr>`).join('')}</tbody>
        </table>
      </div>
    </div>

    ${sale.refunds && sale.refunds.length ? `
    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold py-2">Refunds</div>
      <div class="card-body">
        ${sale.refunds.map((r) => `
          <div class="d-flex justify-content-between small mb-1">
            <span>${escapeHtml(String(r.created_date || '').slice(0, 16).replace('T', ' '))} · ${escapeHtml(r.reason || '')}</span>
            <span class="fw-semibold">${money(r.refund_amount)}</span>
          </div>`).join('')}
      </div>
    </div>` : ''}

    ${remaining > 0.004 ? '<button type="button" class="btn btn-dark btn-sm" data-record-payment>Record Payment</button>' : ''}`;

  const payBtn = body.querySelector('[data-record-payment]');
  if (payBtn) {
    payBtn.addEventListener('click', async () => {
      const due = (Number(sale.grand_total) - Number(sale.amount_paid)).toFixed(2);
      const amount = prompt(`Remaining balance is ${money(due)}. Enter the amount received:`, due);
      if (amount === null || amount.trim() === '') return;

      const method = prompt('Payment method (cash, upi, card, other):', 'cash');
      if (!method) return;

      try {
        await api.post(`/admin/pos/sales/${sale.uuid}/payments`, {
          amount: Number(amount),
          payment_method: method.trim().toLowerCase(),
        });
        toast('Payment recorded. Balance updated.');
        await renderCounterList();
        openCounterSaleDetail(sale.uuid);
      } catch (error) {
        showError(error);
      }
    });
  }
}

/** Counter (POS) sales: delivery status and the customer's reviews of what they bought. */
async function renderCounterList() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Orders</h1>
      ${viewTabs()}
      <select class="form-select form-select-sm w-auto" data-delivery-filter aria-label="Filter by delivery">
        ${[['', 'All deliveries'], ['delivered', 'Delivered'], ['pending', 'Not delivered']].map(([value, label]) => `
          <option value="${value}" ${state.delivery === value ? 'selected' : ''}>${label}</option>`).join('')}
      </select>
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>

    <div class="offcanvas offcanvas-end" tabindex="-1" data-sale-offcanvas style="width:min(560px,100vw)">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" data-sale-detail-title>Sale</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body" data-sale-detail-body></div>
    </div>`;

  wireViewTabs();
  root.querySelector('[data-delivery-filter]').addEventListener('change', (event) => {
    state.delivery = event.target.value;
    state.page = 1;
    renderCounterList();
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/counter-sales', { delivery: state.delivery, page: state.page, per_page: 25 });
    const sales = response.data || [];

    if (sales.length === 0) {
      list.innerHTML = emptyState('No counter sales here', state.delivery ? 'Nothing matches that filter.' : 'Sales rung up at the till appear here.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Sale</th><th>Customer</th><th>Items</th><th>Payment</th><th>Delivery</th><th>Customer review</th><th class="text-end">Total</th></tr></thead>
          <tbody>${sales.map(counterRow).join('')}</tbody>
        </table>
      </div>
      ${(response.meta && response.meta.total_pages > 1) ? `
        <div class="d-flex justify-content-between align-items-center p-3 small text-muted">
          <span>Page ${response.meta.page} of ${response.meta.total_pages}</span>
          <span class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.page -= 1; renderCounterList(); });
    if (next) next.addEventListener('click', () => { state.page += 1; renderCounterList(); });

    list.querySelectorAll('[data-open-sale]').forEach((button) => {
      button.addEventListener('click', () => openCounterSaleDetail(button.dataset.openSale));
    });

    list.querySelectorAll('[data-deliver]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Saving');

        try {
          await api.post(`/admin/pos/sales/${encodeURIComponent(button.dataset.deliver)}/deliver`, {});
          toast('Marked as delivered.');
          renderCounterList();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

async function renderList() {
  if (state.view === 'counter' && isAdmin) { renderCounterList(); return; }

  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Orders</h1>
      ${viewTabs()}
      <select class="form-select form-select-sm w-auto" data-payment-filter aria-label="Filter by payment">
        ${PAYMENT_FILTERS.map(([value, label]) => `
          <option value="${value}" ${state.payment === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
      </select>
      <div class="btn-group btn-group-sm" role="group" aria-label="Filter orders">
        ${FILTERS.map(([value, label]) => `
          <button type="button" class="btn ${state.status === value ? 'btn-dark' : 'btn-outline-secondary'}"
                  data-filter="${value}">${escapeHtml(label)}</button>`).join('')}
      </div>
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  root.querySelectorAll('[data-filter]').forEach((button) => {
    button.addEventListener('click', () => {
      state.status = button.dataset.filter;
      state.page = 1;
      renderList();
    });
  });

  wireViewTabs();

  root.querySelector('[data-payment-filter]').addEventListener('change', (event) => {
    state.payment = event.target.value;
    state.page = 1;
    renderList();
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/orders', {
      status: state.status,
      payment_status: state.payment,
      page: state.page,
      per_page: 25,
    });

    const orders = response.data || [];

    if (orders.length === 0) {
      list.innerHTML = emptyState('No orders here',
        (state.status || state.payment) ? 'Nothing matches those filters.' : 'No orders have been placed yet.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead>
            <tr>
              <th>Order</th><th>Customer</th><th>Status</th><th>Payment</th>
              <th class="text-end">Total</th><th></th>
            </tr>
          </thead>
          <tbody>${orders.map(row).join('')}</tbody>
        </table>
      </div>
      ${(response.meta && response.meta.total_pages > 1) ? `
        <div class="p-3 border-top d-flex justify-content-between align-items-center">
          <span class="small text-muted">
            Page ${escapeHtml(response.meta.page)} of ${escapeHtml(response.meta.total_pages)},
            ${escapeHtml(response.meta.total)} order(s)
          </span>
          <span>
            <button class="btn btn-sm btn-outline-secondary" data-page-prev
                    ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next
                    ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.page -= 1; renderList(); });
    if (next) next.addEventListener('click', () => { state.page += 1; renderList(); });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

/** The actions available, given what the server will actually allow. */
function actionsFor(order, detail) {
  const paid = order.payment_status === 'paid' || order.payment_status === 'partially_refunded';
  const shipped = Boolean(detail.shipping && detail.shipping.tracking_number);
  const buttons = [];

  if (!paid && detail.pending_manual_payment) {
    const pending = detail.pending_manual_payment;

    return `
      <div class="small text-muted mb-2">
        Payment status: <strong>${escapeHtml(order.payment_status)}</strong>. Check that the
        ₹${escapeHtml(pending.amount)} has actually reached your account before marking it done.
      </div>
      <form class="row g-2 align-items-end" data-payment-form data-payment-uuid="${escapeHtml(pending.uuid)}">
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Amount received (₹)</label>
          <input class="form-control form-control-sm" name="confirmed_amount"
                 value="${escapeHtml(pending.amount)}" required inputmode="decimal">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label small mb-1">UPI reference / UTR</label>
          <input class="form-control form-control-sm" name="utr_or_reference" required>
        </div>
        <div class="col-12 col-md-5 d-flex gap-2">
          <button class="btn btn-sm btn-success" type="submit">Payment done</button>
          <button class="btn btn-sm btn-outline-danger" type="button" data-payment-reject>Payment not received</button>
        </div>
      </form>`;
  }

  if (!paid) {
    return `
      <div class="alert alert-warning small mb-0">
        <div class="fw-semibold">Nothing can be done until this is paid for.</div>
        Orders do not progress without a verified payment, and that applies to staff
        actions too. If the customer has paid, the confirmation arrives by webhook —
        it is not something to force through here.
      </div>`;
  }

  if (order.status === 'confirmed') {
    buttons.push(['packed', 'Mark as packed', 'btn-dark']);
  }

  if (['packed', 'ready_to_ship'].includes(order.status) && !shipped) {
    buttons.push(['__ship', 'Book a courier', 'btn-primary']);
  }

  if (['confirmed', 'packed'].includes(order.status)) {
    buttons.push(['cancelled', 'Cancel order', 'btn-outline-danger']);
  }

  if (buttons.length === 0) {
    return `<p class="text-muted small mb-0">No actions available at this status.</p>`;
  }

  return `<div class="d-flex flex-wrap gap-2">${buttons.map(([value, label, cls]) => `
    <button class="btn btn-sm ${cls}" data-action="${escapeHtml(value)}">${escapeHtml(label)}</button>`).join('')}</div>`;
}

async function renderDetail(uuid) {
  try {
    const response = await api.get(`/admin/orders/${encodeURIComponent(uuid)}`);
    const detail = response.data;
    const order = detail.order;

    root.innerHTML = `
      <a class="small text-decoration-none" href="orders.html">← All orders</a>

      <div class="d-flex flex-wrap justify-content-between align-items-start mt-2 mb-3 gap-2">
        <div>
          <h1 class="h4 mb-1">${escapeHtml(order.order_number)}</h1>
          <div>
            ${badge(order.status, String(order.status).replace(/_/g, ' '))}
            <span class="ms-2">${badge(order.payment_status, order.payment_status)}</span>
            ${order.invoice_number
              ? `<span class="ms-2 small text-muted">Invoice ${escapeHtml(order.invoice_number)}</span>`
              : ''}
          </div>
        </div>
        <div class="text-end">
          <div class="fs-5 fw-semibold">${formatMoney(order.grand_total)}</div>
          <div class="small text-muted">incl. ${formatMoney(detail.pricing.tax_total)} GST</div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Actions</div>
        <div class="card-body" data-actions>${actionsFor(order, detail)}</div>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Items</div>
            <div class="table-responsive">
              <table class="table table-tight mb-0">
                <tbody>
                  ${detail.items.map((item) => `
                    <tr>
                      <td>
                        ${escapeHtml(item.product_name)}
                        <div class="small text-muted">${escapeHtml(item.variant_name)} · ${escapeHtml(item.sku || '')}</div>
                      </td>
                      <td class="text-end">× ${escapeHtml(item.quantity)}</td>
                      <td class="text-end">${formatMoney(item.line_payable)}</td>
                    </tr>`).join('')}
                </tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header bg-white fw-semibold">Timeline</div>
            <ul class="list-group list-group-flush">
              ${(detail.timeline || []).map((entry) => `
                <li class="list-group-item py-2">
                  <div class="fw-semibold small">${escapeHtml(entry.title)}</div>
                  ${entry.note ? `<div class="small text-muted">${escapeHtml(entry.note)}</div>` : ''}
                  <div class="small text-muted">${escapeHtml(String(entry.date || '').slice(0, 16).replace('T', ' '))}</div>
                </li>`).join('')}
            </ul>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Deliver to</div>
            <div class="card-body small">
              <div class="fw-semibold">${escapeHtml(detail.shipping.name || '')}</div>
              <div>${escapeHtml(detail.shipping.address || '')}</div>
              <div class="mt-2">${escapeHtml(detail.shipping.mobile || '')}</div>
              ${detail.shipping.tracking_number ? `
                <hr>
                <div>${escapeHtml(detail.shipping.courier_name || 'Courier')}</div>
                <div class="font-monospace">${escapeHtml(detail.shipping.tracking_number)}</div>` : ''}
            </div>
          </div>

          <div class="card">
            <div class="card-header bg-white fw-semibold">Payment</div>
            <div class="card-body small">
              <dl class="row mb-0">
                <dt class="col-7 fw-normal">Items</dt>
                <dd class="col-5 text-end">${formatMoney(detail.pricing.items_subtotal)}</dd>
                <dt class="col-7 fw-normal">Discount</dt>
                <dd class="col-5 text-end">${formatMoney(detail.pricing.order_discount)}</dd>
                <dt class="col-7 fw-normal">Delivery</dt>
                <dd class="col-5 text-end">${formatMoney(detail.pricing.delivery_charge)}</dd>
                <dt class="col-7 fw-semibold">Total</dt>
                <dd class="col-5 text-end fw-semibold">${formatMoney(detail.pricing.grand_total)}</dd>
                ${Number(detail.pricing.wallet_applied) > 0 ? `
                  <dt class="col-7 fw-normal">Paid by wallet</dt>
                  <dd class="col-5 text-end">${formatMoney(detail.pricing.wallet_applied)}</dd>` : ''}
              </dl>
            </div>
          </div>
        </div>
      </div>`;

    root.querySelectorAll('[data-action]').forEach((button) => {
      button.addEventListener('click', () => performAction(uuid, button, order));
    });

    const paymentForm = root.querySelector('[data-payment-form]');

    if (paymentForm) {
      const paymentUuid = paymentForm.dataset.paymentUuid;

      paymentForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = paymentForm.querySelector('[type="submit"]');
        setBusy(submit, true, 'Saving');

        try {
          await api.post(`/admin/payments/${encodeURIComponent(paymentUuid)}/verify`,
            Object.fromEntries(new FormData(paymentForm)));
          toast('Payment marked as done. The order is confirmed.');
          renderDetail(uuid);
        } catch (error) {
          setBusy(submit, false);
          showError(error);
        }
      });

      paymentForm.querySelector('[data-payment-reject]').addEventListener('click', async (event) => {
        const reason = window.prompt('Why was the payment not received? (kept in the audit log)');
        if (!reason) return;

        setBusy(event.currentTarget, true, 'Saving');

        try {
          await api.post(`/admin/payments/${encodeURIComponent(paymentUuid)}/reject`, { reason });
          toast('Payment marked as not received. The customer can retry.');
          renderDetail(uuid);
        } catch (error) {
          setBusy(event.currentTarget, false);
          showError(error);
        }
      });
    }
  } catch (error) {
    root.innerHTML = '<a class="small" href="orders.html">← All orders</a>';
    showError(error, root);
  }
}

/**
 * Was: always POST /ship with no body, letting BR-007 choose silently. This
 * lets staff see what BR-007 would choose and why — cost, SLA, eligibility —
 * before committing, using the courier-options endpoint that already existed
 * but had no caller anywhere in the console.
 */
async function openCourierChooser(uuid) {
  const actionsBody = root.querySelector('[data-actions]');
  actionsBody.innerHTML = '<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>';

  try {
    const response = await api.get(`/admin/orders/${encodeURIComponent(uuid)}/courier-options`);
    const options = response.data;
    const candidates = options.candidates || [];

    actionsBody.innerHTML = `
      <p class="small text-muted">
        Automatic selection (${escapeHtml(options.strategy)}): ${escapeHtml(options.reason || '')}
      </p>
      <div class="list-group mb-3">
        <label class="list-group-item">
          <input class="form-check-input me-2" type="radio" name="courier-choice" value="" checked>
          Automatic (recommended)
        </label>
        ${candidates.map((c) => `
          <label class="list-group-item d-flex justify-content-between align-items-center ${c.is_eligible ? '' : 'text-muted'}">
            <span>
              <input class="form-check-input me-2" type="radio" name="courier-choice"
                     value="${escapeHtml(c.courier_code)}" ${c.is_eligible ? '' : 'disabled'}>
              ${escapeHtml(c.courier_name)}
              ${c.is_eligible ? '' : `<span class="small"> — ${escapeHtml((c.ineligibility_reasons || []).join('; '))}</span>`}
            </span>
            <span class="small">${formatMoney(c.cost)} · ${escapeHtml(c.sla_min_days)}–${escapeHtml(c.sla_max_days)}d</span>
          </label>`).join('')}
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-primary" data-confirm-ship>Book</button>
        <button class="btn btn-sm btn-outline-secondary" data-cancel-ship>Cancel</button>
      </div>`;

    actionsBody.querySelector('[data-cancel-ship]').addEventListener('click', () => {
      renderDetail(uuid);
    });

    actionsBody.querySelector('[data-confirm-ship]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const chosen = actionsBody.querySelector('input[name="courier-choice"]:checked').value;
      setBusy(button, true, 'Booking');

      try {
        const bookResponse = await api.post(`/admin/orders/${encodeURIComponent(uuid)}/ship`,
          chosen ? { courier_code: chosen } : {});
        toast(`Booked with ${bookResponse.data.courier_name || 'a courier'}.`);
        renderDetail(uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  } catch (error) {
    showError(error, actionsBody);
  }
}

/**
 * "Refund to original payment method, or to the customer's wallet?" — asked
 * only when there's actually a captured payment to send somewhere; an
 * unpaid order has nothing to choose a destination for. Resolves to
 * 'gateway', 'wallet', or null if the admin backed out of cancelling
 * altogether.
 */
function chooseRefundMethod() {
  return new Promise((resolve) => {
    let host = document.querySelector('[data-refund-method-modal]');

    if (!host) {
      host = document.createElement('div');
      host.setAttribute('data-refund-method-modal', '');
      document.body.appendChild(host);
    }

    host.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal>
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="h6 modal-title">Refund this payment to…</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Don't cancel"></button>
            </div>
            <div class="modal-body">
              <div class="list-group">
                <button type="button" class="list-group-item list-group-item-action" data-choice="gateway">
                  <span class="fw-semibold">Original payment method</span>
                  <div class="small text-muted">Sent back through the gateway the customer paid with.</div>
                </button>
                <button type="button" class="list-group-item list-group-item-action" data-choice="wallet">
                  <span class="fw-semibold">Customer's wallet</span>
                  <div class="small text-muted">Credited instantly as store credit — no gateway involved.</div>
                </button>
              </div>
            </div>
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

    host.querySelectorAll('[data-choice]').forEach((choiceButton) => {
      choiceButton.addEventListener('click', () => finish(choiceButton.dataset.choice));
    });

    // Dismissed (the X, or Esc) without a pick: treated as "don't cancel" —
    // a refund destination is not something to default silently.
    modalEl.addEventListener('hidden.bs.modal', () => finish(null), { once: true });

    modal.show();
  });
}

async function performAction(uuid, button, order) {
  const action = button.dataset.action;

  if (action === 'cancelled') {
    const reason = window.prompt('Why is this order being cancelled? The customer is told.');
    if (!reason) return;

    // Only a settled payment has anywhere to be refunded to.
    const hasCapturedPayment = ['paid', 'partially_refunded'].includes(order?.payment_status);
    let refundMethod = null;

    if (hasCapturedPayment) {
      refundMethod = await chooseRefundMethod();
      if (!refundMethod) return; // backed out rather than pick a destination
    }

    setBusy(button, true, 'Cancelling');

    try {
      await api.post(`/admin/orders/${encodeURIComponent(uuid)}/cancel`, {
        reason,
        ...(refundMethod ? { refund_method: refundMethod } : {}),
      });
      toast(refundMethod === 'wallet'
        ? 'Order cancelled. The payment was credited to the customer’s wallet.'
        : 'Order cancelled. Any payment will be refunded.');
      renderDetail(uuid);
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }

    return;
  }

  if (action === '__ship') {
    openCourierChooser(uuid);
    return;
  }

  setBusy(button, true, 'Updating');

  try {
    await api.post(`/admin/orders/${encodeURIComponent(uuid)}/status`, { status: action });
    toast('Order updated.');
    renderDetail(uuid);
  } catch (error) {
    setBusy(button, false);
    showError(error);
  }
}

const mounted = await mountConsole('orders.html');

if (mounted) {
  root = mounted.root;
  isAdmin = String(mounted.user.role) === 'administrator';
  const uuid = queryParam('uuid');
  if (uuid) renderDetail(uuid); else renderList();
}
