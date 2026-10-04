/**
 * Manual payments: the verification queue for the staff-checked UPI QR
 * gateway, plus the payment/delivery driver toggle and QR upload.
 *
 * This is the admin-facing half of ManualGateway/ManualPaymentService. Every
 * confirm/reject here calls the same /admin/payments/{uuid}/verify|reject
 * endpoints that PaymentService::applyVerification() gates on — there is no
 * separate "fast path" here, this page is just a UI on top of that API.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney, emptyState } from './console.js?v=9';

let root = null;

function paymentCard(payment) {
  return `
    <div class="card mb-3" data-payment="${escapeHtml(payment.uuid)}">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div>
            <div class="fw-semibold">Order ${escapeHtml(payment.order_number)}</div>
            <div class="small text-muted">
              ${escapeHtml(payment.customer_name || 'Customer')} ·
              ${escapeHtml(payment.customer_mobile || '')}
            </div>
            <div class="small text-muted mt-1">
              Placed ${escapeHtml(String(payment.created_date || '').slice(0, 16).replace('T', ' '))}
            </div>
          </div>
          <div class="text-end flex-shrink-0">
            <div class="fs-5 fw-semibold">₹${escapeHtml(payment.amount)}</div>
            <div class="small text-muted">Attempt ${escapeHtml(payment.attempt_number)}</div>
          </div>
        </div>

        <div class="alert alert-light border small mt-3 mb-3">
          Check your bank or UPI app for a transfer of exactly
          <strong>₹${escapeHtml(payment.amount)}</strong> referencing
          <code>${escapeHtml(payment.order_number)}</code> before confirming.
          Confirming with the wrong amount is refused automatically, but
          confirming a transfer that never happened is not — this decision is
          the only check.
        </div>

        <form class="row g-2 align-items-end" data-verify-form>
          <div class="col-12 col-sm-4">
            <label class="form-label small mb-1">Amount received</label>
            <input class="form-control form-control-sm" name="confirmed_amount"
                   value="${escapeHtml(payment.amount)}" required inputmode="decimal">
          </div>
          <div class="col-12 col-sm-4">
            <label class="form-label small mb-1">UTR / reference (optional)</label>
            <input class="form-control form-control-sm" name="utr_or_reference"
                   placeholder="Bank reference number">
          </div>
          <div class="col-12 col-sm-4 d-flex gap-2">
            <button class="btn btn-sm btn-success flex-grow-1" type="submit">Confirm payment</button>
            <button class="btn btn-sm btn-outline-danger" type="button" data-reject>Reject</button>
          </div>
        </form>
      </div>
    </div>`;
}

async function loadQueue() {
  const list = root.querySelector('[data-list]');
  list.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>';

  try {
    const response = await api.get('/admin/payments/pending', { per_page: 50 });
    const items = (response.data && response.data.items) || [];

    if (items.length === 0) {
      list.innerHTML = emptyState('Nothing waiting', 'No manual payments need review right now.');
      return;
    }

    list.innerHTML = items.map(paymentCard).join('');

    list.querySelectorAll('[data-verify-form]').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const uuid = form.closest('[data-payment]').dataset.payment;
        const button = form.querySelector('button[type="submit"]');
        const data = Object.fromEntries(new FormData(form).entries());

        if (!window.confirm(`Confirm this payment for order shown above as paid?`)) return;

        setBusy(button, true, 'Confirming');

        try {
          await api.post(`/admin/payments/${encodeURIComponent(uuid)}/verify`, data);
          toast('Payment verified — order confirmed.');
          loadQueue();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    list.querySelectorAll('[data-reject]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-payment]').dataset.payment;
        const reason = window.prompt(
          'Why is this being rejected? (shown in the audit log, e.g. "no matching transfer found")'
        );
        if (!reason || reason.trim().length < 3) return;

        setBusy(button, true, 'Rejecting');

        try {
          await api.post(`/admin/payments/${encodeURIComponent(uuid)}/reject`, { reason });
          toast('Payment rejected. The customer can retry.');
          loadQueue();
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

/** The driver toggle + manual QR settings, collapsed by default so the queue stays the focus. */
async function loadSettingsPanel() {
  const panel = root.querySelector('[data-settings-panel]');

  try {
    const response = await api.get('/admin/settings');
    const s = response.data;

    panel.innerHTML = `
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Payment gateway</label>
          <select class="form-select form-select-sm" data-payment-driver>
            ${s.payment_driver_options.map((opt) => `
              <option value="${escapeHtml(opt)}" ${opt === s.payment_driver ? 'selected' : ''}>
                ${escapeHtml(opt)}
              </option>`).join('')}
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Delivery mode</label>
          <select class="form-select form-select-sm" data-delivery-driver>
            ${s.delivery_driver_options.map((opt) => `
              <option value="${escapeHtml(opt)}" ${opt === s.delivery_driver ? 'selected' : ''}>
                ${escapeHtml(opt)}
              </option>`).join('')}
          </select>
        </div>

        <div class="col-12"><hr class="my-2"></div>

        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="codEnabled"
                   data-cod-enabled ${s.cod_enabled ? 'checked' : ''}>
            <label class="form-check-label small" for="codEnabled">
              Offer Cash on Delivery at checkout, alongside QR payment
            </label>
          </div>
        </div>

        <div class="col-12"><hr class="my-2"></div>

        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">UPI VPA shown under QR (optional)</label>
          <input class="form-control form-control-sm" data-manual-vpa value="${escapeHtml(s.manual_payment_vpa || '')}">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Payee name shown under QR</label>
          <input class="form-control form-control-sm" data-manual-payee value="${escapeHtml(s.manual_payment_payee_name || '')}">
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-sm btn-dark" data-save-settings type="button">Save</button>
        </div>

        <div class="col-12"><hr class="my-2"></div>

        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Manual payment QR code</label>
          ${s.manual_payment_qr_url
            ? `<div class="mb-2"><img src="${escapeHtml(s.manual_payment_qr_url)}" alt="Current QR"
                 style="max-width:160px" class="border rounded"></div>`
            : '<div class="small text-muted mb-2">No QR code uploaded yet.</div>'}
          <input class="form-control form-control-sm" type="file" accept="image/png,image/jpeg,image/webp"
                 data-qr-file>
          <button class="btn btn-sm btn-outline-dark mt-2" data-upload-qr type="button">Upload</button>
        </div>

        <div class="col-12"><hr class="my-2"></div>

        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Store logo</label>
          ${s.store_logo_url
            ? `<div class="mb-2"><img src="${escapeHtml(s.store_logo_url)}" alt="Current logo"
                 style="max-height:60px" class="border rounded p-1 bg-white"></div>`
            : '<div class="small text-muted mb-2">No logo uploaded yet.</div>'}
          <input class="form-control form-control-sm" type="file" accept="image/png,image/jpeg,image/webp"
                 data-logo-file>
          <button class="btn btn-sm btn-outline-dark mt-2" data-upload-logo type="button">Upload</button>
          <p class="small text-muted mt-2 mb-0">
            After uploading, copy the URL shown below into
            <code>logoUrl</code> in both <code>assets/js/config.js</code> and
            <code>admin/assets/config.js</code> — the storefront and console
            read the logo from those files, not live from here.
          </p>
          <div class="small mt-1" data-logo-url-display></div>
        </div>
      </div>`;

    panel.querySelector('[data-save-settings]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      setBusy(button, true, 'Saving');

      try {
        const driver = panel.querySelector('[data-payment-driver]').value;
        const courier = panel.querySelector('[data-delivery-driver]').value;
        const codEnabled = panel.querySelector('[data-cod-enabled]').checked;

        await api.patch('/admin/settings/payment-driver', { driver });
        await api.patch('/admin/settings/delivery-driver', { driver: courier });
        await api.patch('/admin/settings/cod', { enabled: codEnabled });
        await api.patch('/admin/settings/manual', {
          manual_payment_vpa: panel.querySelector('[data-manual-vpa]').value,
          manual_payment_payee_name: panel.querySelector('[data-manual-payee]').value,
        });

        toast('Settings saved.');
        setBusy(button, false);
        loadSettingsPanel();
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    panel.querySelector('[data-upload-qr]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const fileInput = panel.querySelector('[data-qr-file]');
      const file = fileInput.files && fileInput.files[0];

      if (!file) {
        toast('Choose an image file first.', 'warning');
        return;
      }

      setBusy(button, true, 'Uploading');

      try {
        const formData = new FormData();
        formData.append('image', file);
        await api.upload('/admin/settings/manual/qr-image', formData);
        toast('QR code updated.');
        loadSettingsPanel();
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    panel.querySelector('[data-upload-logo]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const fileInput = panel.querySelector('[data-logo-file]');
      const file = fileInput.files && fileInput.files[0];

      if (!file) {
        toast('Choose an image file first.', 'warning');
        return;
      }

      setBusy(button, true, 'Uploading');

      try {
        const formData = new FormData();
        formData.append('image', file);
        const response = await api.upload('/admin/settings/logo', formData);
        const url = (response.data && response.data.store_logo_url) || '';

        toast('Logo uploaded. Copy the URL into config.js as shown below.');

        const display = panel.querySelector('[data-logo-url-display]');
        if (display && url) {
          display.innerHTML = `<code class="user-select-all">${escapeHtml(url)}</code>`;
        }

        loadSettingsPanel();
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  } catch (error) {
    panel.innerHTML = '';
    showError(error, panel);
  }
}

function codCard(order) {
  return `
    <div class="card mb-3" data-cod-order="${escapeHtml(order.uuid)}">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div>
            <div class="fw-semibold">Order ${escapeHtml(order.order_number)}</div>
            <div class="small text-muted">
              Placed ${escapeHtml(String(order.placed_date || '').slice(0, 16).replace('T', ' '))}
            </div>
          </div>
          <div class="text-end flex-shrink-0">
            <div class="fs-5 fw-semibold">${formatMoney(order.amount_payable)}</div>
            <div class="small text-muted">Cash on delivery</div>
          </div>
        </div>

        <div class="alert alert-light border small mt-3 mb-3">
          No payment has been collected yet. Approving confirms this order for
          packing and shipping; the cash is collected by the courier on delivery.
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-success flex-grow-1" type="button" data-cod-approve>
            Approve
          </button>
          <button class="btn btn-sm btn-outline-danger" type="button" data-cod-decline>
            Decline
          </button>
        </div>
      </div>
    </div>`;
}

async function loadCodQueue() {
  const list = root.querySelector('[data-cod-list]');
  if (!list) return;

  list.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>';

  try {
    const response = await api.get('/admin/orders/cod/pending', { per_page: 50 });
    const items = (response.data && response.data.items) || [];

    if (items.length === 0) {
      list.innerHTML = emptyState('Nothing waiting', 'No Cash on Delivery orders need review right now.');
      return;
    }

    list.innerHTML = items.map(codCard).join('');

    list.querySelectorAll('[data-cod-approve]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-cod-order]').dataset.codOrder;

        if (!window.confirm('Approve this Cash on Delivery order? It will move straight into packing.')) return;

        setBusy(button, true, 'Approving');

        try {
          await api.post(`/admin/orders/${encodeURIComponent(uuid)}/cod/approve`, {});
          toast('Order approved and confirmed.');
          loadCodQueue();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    list.querySelectorAll('[data-cod-decline]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-cod-order]').dataset.codOrder;
        const reason = window.prompt('Why is this being declined? (shown in the audit log)');
        if (!reason || reason.trim().length < 3) return;

        setBusy(button, true, 'Declining');

        try {
          await api.post(`/admin/orders/${encodeURIComponent(uuid)}/cod/decline`, { reason });
          toast('Order declined. The customer can retry with UPI.');
          loadCodQueue();
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

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Payments</h1>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-settings>
        Payment &amp; delivery settings
      </button>
    </div>

    <div class="card mb-4 d-none" data-settings-card>
      <div class="card-body" data-settings-panel>
        <div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
      </div>
    </div>

    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <button class="nav-link active" type="button" data-tab="manual">Manual UPI payments</button>
      </li>
      <li class="nav-item">
        <button class="nav-link" type="button" data-tab="cod">Cash on Delivery</button>
      </li>
    </ul>

    <div data-list></div>
    <div data-cod-list class="d-none"></div>`;

  const settingsCard = root.querySelector('[data-settings-card]');
  let settingsLoaded = false;

  root.querySelector('[data-toggle-settings]').addEventListener('click', () => {
    settingsCard.classList.toggle('d-none');
    if (!settingsCard.classList.contains('d-none') && !settingsLoaded) {
      settingsLoaded = true;
      loadSettingsPanel();
    }
  });

  const manualList = root.querySelector('[data-list]');
  const codList = root.querySelector('[data-cod-list]');
  let codLoaded = false;

  root.querySelectorAll('[data-tab]').forEach((tabButton) => {
    tabButton.addEventListener('click', () => {
      root.querySelectorAll('[data-tab]').forEach((b) => b.classList.remove('active'));
      tabButton.classList.add('active');

      if (tabButton.dataset.tab === 'cod') {
        manualList.classList.add('d-none');
        codList.classList.remove('d-none');
        if (!codLoaded) {
          codLoaded = true;
          loadCodQueue();
        }
      } else {
        codList.classList.add('d-none');
        manualList.classList.remove('d-none');
      }
    });
  });

  loadQueue();
}

const mounted = await mountConsole('payments.html');
if (mounted) { root = mounted.root; render(); }
