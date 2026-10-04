/** Order history and tracking. */

import { api, bootstrapSession, isSignedIn } from './api.js';
import { mountChrome, mountFooter, escapeHtml, formatMoney, showError, toast, setBusy, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');

const STATUS_STYLE = {
  delivered: 'success', cancelled: 'secondary', returned: 'warning',
  refunded: 'secondary', shipped: 'info', out_for_delivery: 'info',
};

function orderRow(order) {
  return `
    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <div class="flex-grow-1">
          <div class="fw-semibold">${escapeHtml(order.order_number)}</div>
          <div class="small text-muted">
            ${escapeHtml((order.placed_date || '').slice(0, 10))} · ${escapeHtml(order.item_count)} item(s)
          </div>
        </div>
        <span class="badge text-bg-${STATUS_STYLE[order.status] || 'primary'}">${escapeHtml(order.status_label)}</span>
        <div class="fw-semibold" style="min-width:6rem" >${formatMoney(order.grand_total)}</div>
        <a class="btn btn-outline-secondary btn-sm" href="orders.html?uuid=${encodeURIComponent(order.uuid)}">Details</a>
      </div>
    </div>`;
}

function timelineItem(entry) {
  return `
    <li class="list-group-item">
      <div class="fw-semibold">${escapeHtml(entry.title)}</div>
      ${entry.note ? `<div class="small text-muted">${escapeHtml(entry.note)}</div>` : ''}
      <div class="small text-muted">${escapeHtml((entry.date || '').replace('T', ' ').slice(0, 16))}</div>
    </li>`;
}

async function renderDetail(uuid) {
  try {
    const response = await api.get(`/orders/${encodeURIComponent(uuid)}`);
    const detail = response.data;
    const order = detail.order;

    root.innerHTML = `
      <a class="small" href="orders.html">← All orders</a>
      <h1 class="h4 mt-2">${escapeHtml(order.order_number)}</h1>
      <p class="text-muted">
        <span class="badge text-bg-${STATUS_STYLE[order.status] || 'primary'}">${escapeHtml(order.status_label)}</span>
        <span class="ms-2">${escapeHtml(order.payment_status_label)}</span>
      </p>

      <div class="row g-4">
        <div class="col-12 col-lg-7">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Items</div>
            <ul class="list-group list-group-flush">
              ${detail.items.map((item) => `
                <li class="list-group-item d-flex justify-content-between">
                  <span>
                    ${escapeHtml(item.product_name)}
                    <span class="text-muted small d-block">${escapeHtml(item.variant_name)} × ${escapeHtml(item.quantity)}</span>
                  </span>
                  <span>${formatMoney(item.line_payable)}</span>
                </li>`).join('')}
            </ul>
          </div>

          <div class="card">
            <div class="card-header bg-white fw-semibold">Progress</div>
            <ul class="list-group list-group-flush">
              ${detail.timeline.map(timelineItem).join('')}
            </ul>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card mb-3">
            <div class="card-body">
              <h2 class="h6">Payment</h2>
              <dl class="row small mb-0">
                <dt class="col-7 fw-normal">Total</dt><dd class="col-5 text-end">${formatMoney(detail.pricing.grand_total)}</dd>
                ${Number(detail.pricing.wallet_applied) > 0 ? `
                  <dt class="col-7 fw-normal">Wallet credit</dt>
                  <dd class="col-5 text-end">−${formatMoney(detail.pricing.wallet_applied)}</dd>` : ''}
                <dt class="col-7 fw-normal">Includes GST</dt><dd class="col-5 text-end">${formatMoney(detail.pricing.tax_total)}</dd>
              </dl>
              ${detail.invoice ? `<a class="btn btn-outline-secondary btn-sm mt-3"
                 href="invoice.html?uuid=${encodeURIComponent(order.uuid)}">View invoice</a>` : ''}
            </div>
          </div>

          <div class="card mb-3">
            <div class="card-body">
              <h2 class="h6">Delivery</h2>
              <p class="small mb-1">${escapeHtml(detail.shipping.address)}</p>
              ${detail.shipping.tracking_number ? `
                <p class="small mb-0">
                  ${escapeHtml(detail.shipping.courier_name || 'Courier')} ·
                  ${detail.shipping.tracking_url
                    ? `<a href="${escapeHtml(detail.shipping.tracking_url)}" rel="noopener noreferrer" target="_blank">${escapeHtml(detail.shipping.tracking_number)}</a>`
                    : escapeHtml(detail.shipping.tracking_number)}
                </p>` : '<p class="small text-muted mb-0">Not dispatched yet.</p>'}
            </div>
          </div>

          ${order.can_cancel ? `
            <button class="btn btn-outline-danger w-100" data-cancel type="button">Cancel this order</button>` : ''}
        </div>
      </div>`;

    const cancelButton = root.querySelector('[data-cancel]');
    if (cancelButton) {
      cancelButton.addEventListener('click', async () => {
        const reason = window.prompt('Why are you cancelling? This helps us improve.');
        if (!reason) return;

        setBusy(cancelButton, true, 'Cancelling');
        try {
          const result = await api.post(`/orders/${encodeURIComponent(uuid)}/cancel`, { reason });
          toast(result.message || 'Order cancelled.');
          renderDetail(uuid);
        } catch (error) {
          showError(error);
          setBusy(cancelButton, false);
        }
      });
    }
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

async function renderList() {
  try {
    const response = await api.get('/orders', { per_page: 20 });
    const orders = response.data || [];

    root.innerHTML = `
      <h1 class="h4 mb-4">Your orders</h1>
      ${orders.length
        ? orders.map(orderRow).join('')
        : `<div class="text-center py-5">
             <p class="text-muted">No orders yet.</p>
             <a class="btn btn-spice" href="index.html">Start shopping</a>
           </div>`}`;
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

async function start() {
  await bootstrapSession();

  if (!isSignedIn()) {
    root.innerHTML = `
      <div class="text-center py-5">
        <h1 class="h5">Sign in to see your orders</h1>
        <a class="btn btn-spice mt-2" href="account.html?next=orders.html">Sign in</a>
        <p class="small text-muted mt-4">
          Or <a href="track.html">track an order</a> with its number and mobile.
        </p>
      </div>`;
    return;
  }

  const uuid = queryParam('uuid');
  if (uuid) renderDetail(uuid); else renderList();
}

mountChrome('orders.html');
mountFooter();
start();
