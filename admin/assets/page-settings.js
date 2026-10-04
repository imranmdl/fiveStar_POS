/**
 * Store settings: the switches that change how the whole store behaves —
 * which payment/delivery driver is live, manual UPI details, Cash on
 * Delivery, the inventory price-change prompt (brief §7), and the store
 * logo. Every field here already had a working API
 * (`SettingsController`/`SettingsService`) before this page existed; this is
 * only the first UI on top of it.
 *
 * Each section saves independently, on its own PATCH — matching the API's
 * own shape (one endpoint per concern) rather than a single "save everything"
 * button that would let an unrelated section's stale input overwrite a value
 * someone else just changed.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, headerIcon } from './console.js?v=9';

const ICONS = {
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  truck: '<rect x="1" y="7" width="13" height="10" rx="1"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/>',
  hand: '<path d="M11 12V4a1.5 1.5 0 0 1 3 0v7"/><path d="M14 11V3a1.5 1.5 0 0 1 3 0v9"/><path d="M17 12V5a1.5 1.5 0 0 1 3 0v9c0 4-2.5 7-6.5 7h-1c-3 0-4.5-1-6-3l-3-4.5a1.5 1.5 0 0 1 2.4-1.8L8 11.5V5a1.5 1.5 0 0 1 3-0v7"/>',
  sliders: '<path d="M4 6h10M18 6h2M4 18h2M10 18h10"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="18" r="2"/>',
  qr: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM19 14h2M14 19h2M19 19h2"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
};

let root = null;
let settings = null;
let dueReminderTask = null;
const DUE_REMINDER_TASK_CODE = 'pos.due_reminders';

const DRIVER_HINT = {
  payment: {
    manual: 'Customers pay by UPI to the VPA below; staff confirm receipt.',
    sandbox: 'A fake gateway for testing checkout without moving real money.',
    razorpay: 'Live gateway. Requires RAZORPAY_KEY_ID/SECRET in the server .env — this page does not set those.',
  },
  delivery: {
    manual: 'Staff book and track couriers by hand; no courier API is called.',
    sandbox: 'A fake courier for testing the booking/tracking flow end to end.',
    shiprocket: 'Live Shiprocket integration. Requires delivery.shiprocket.* credentials in the server .env — this page does not set those, and per the current project brief, going live is not the priority yet.',
  },
};

const PRICE_MODE_LABEL = {
  always_ask: 'Always ask',
  ask_on_increase: 'Ask only when the price increases',
  ask_on_decrease: 'Ask only when the price decreases',
  auto_apply: 'Automatically apply the pricing rule',
  never: 'Never change the selling price automatically',
};

function driverOptions(kind, options, current) {
  return options.map((value) => `<option value="${escapeHtml(value)}" ${value === current ? 'selected' : ''}>${escapeHtml(value)}</option>`).join('');
}

async function render() {
  root.innerHTML = `<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>`;

  try {
    const [response, tasksResponse] = await Promise.all([
      api.get('/admin/settings'),
      api.get('/admin/scheduler/tasks'),
    ]);
    settings = response.data;
    dueReminderTask = (tasksResponse.data.tasks || []).find((t) => t.code === DUE_REMINDER_TASK_CODE) || null;
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
    return;
  }

  root.innerHTML = `
    <h1 class="h4 mb-3">Settings</h1>

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="card mb-3">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.rupee)}Payment driver</div>
          <div class="card-body">
            <select class="form-select mb-2" data-driver-select="payment">
              ${driverOptions('payment', settings.payment_driver_options, settings.payment_driver)}
            </select>
            <p class="small text-muted" data-driver-hint="payment">${escapeHtml(DRIVER_HINT.payment[settings.payment_driver] || '')}</p>
            <button class="btn btn-sm btn-dark" data-save="payment-driver">Save</button>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.truck)}Delivery driver</div>
          <div class="card-body">
            <select class="form-select mb-2" data-driver-select="delivery">
              ${driverOptions('delivery', settings.delivery_driver_options, settings.delivery_driver)}
            </select>
            <p class="small text-muted" data-driver-hint="delivery">${escapeHtml(DRIVER_HINT.delivery[settings.delivery_driver] || '')}</p>
            <button class="btn btn-sm btn-dark" data-save="delivery-driver">Save</button>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.hand)}Cash on Delivery</div>
          <div class="card-body">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="cod-enabled" ${settings.cod_enabled ? 'checked' : ''}>
              <label class="form-check-label" for="cod-enabled">Offer COD at checkout</label>
            </div>
            <p class="small text-muted mt-2 mb-2">Independent of the payment driver above — a store can accept COD alongside whichever prepaid gateway is active.</p>
            <button class="btn btn-sm btn-dark" data-save="cod">Save</button>
          </div>
        </div>

        <div class="card">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.sliders)}Inventory price-change prompt</div>
          <div class="card-body">
            <p class="small text-muted">When a purchase inward changes the average cost, should the selling price be revisited?</p>
            <select class="form-select mb-2" data-price-mode>
              ${settings.inventory_price_change_mode_options.map((value) => `
                <option value="${escapeHtml(value)}" ${value === settings.inventory_price_change_mode ? 'selected' : ''}>${escapeHtml(PRICE_MODE_LABEL[value] || value)}</option>`).join('')}
            </select>
            <button class="btn btn-sm btn-dark" data-save="price-change-mode">Save</button>
          </div>
        </div>

        <div class="card mt-3">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.hand)}POS due-payment reminders</div>
          <div class="card-body">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="due-reminder-enabled" ${dueReminderTask && dueReminderTask.is_enabled ? 'checked' : ''}>
              <label class="form-check-label" for="due-reminder-enabled">Send a reminder SMS for unpaid/partially paid POS bills</label>
            </div>
            <p class="small text-muted mb-2">
              Off by default. When on, a customer with money still owed on a till sale is sent one
              SMS reminder after the delay below, and repeated no more often than the interval below,
              until the balance is settled.
            </p>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small mb-0">Wait before first reminder (hours)</label>
                <input class="form-control" type="number" min="1" max="720" data-due-delay value="${escapeHtml(settings.pos_due_reminder_delay_hours)}">
              </div>
              <div class="col-6">
                <label class="form-label small mb-0">Minimum gap between reminders (hours)</label>
                <input class="form-control" type="number" min="1" max="720" data-due-repeat value="${escapeHtml(settings.pos_due_reminder_repeat_hours)}">
              </div>
            </div>
            <button class="btn btn-sm btn-dark" data-save="due-reminder-timing">Save timing</button>
            ${dueReminderTask ? `<p class="small text-muted mt-2 mb-0">Last run: ${escapeHtml(dueReminderTask.last_run_date || 'never')} &middot; ${escapeHtml(dueReminderTask.last_run_summary || '')}</p>` : ''}
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="card mb-3">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--gold-dark)', ICONS.qr)}Manual UPI payment</div>
          <div class="card-body">
            <div class="mb-2">
              <label class="form-label small mb-0">Payee VPA</label>
              <input class="form-control" data-manual-vpa value="${escapeHtml(settings.manual_payment_vpa || '')}" placeholder="store@upi">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-0">Payee name shown to customers</label>
              <input class="form-control" data-manual-name value="${escapeHtml(settings.manual_payment_payee_name || '')}">
            </div>
            <button class="btn btn-sm btn-dark mb-3" data-save="manual">Save</button>
            <hr>
            <label class="form-label small mb-0">QR code image</label>
            ${settings.manual_payment_qr_url ? `<div class="mb-2"><img src="${escapeHtml(settings.manual_payment_qr_url)}" alt="Payment QR" style="max-width:160px" class="border rounded"></div>` : '<p class="small text-muted">No QR image uploaded yet.</p>'}
            <input class="form-control" type="file" accept="image/*" data-qr-file>
            <button class="btn btn-sm btn-outline-dark mt-2" data-save="qr-image">Upload</button>
          </div>
        </div>

        <div class="card">
          <div class="card-header bg-white fw-semibold">${headerIcon('var(--forest)', ICONS.image)}Store logo</div>
          <div class="card-body">
            ${settings.store_logo_url ? `<div class="mb-2"><img src="${escapeHtml(settings.store_logo_url)}" alt="Store logo" style="max-height:60px" class="border rounded p-1"></div>` : '<p class="small text-muted">No logo uploaded yet.</p>'}
            <input class="form-control" type="file" accept="image/*" data-logo-file>
            <button class="btn btn-sm btn-outline-dark mt-2" data-save="logo">Upload</button>
            <p class="small text-muted mt-2 mb-0">Uploading only hosts the file — copy the returned URL into <code>assets/config.js</code> as <code>logoUrl</code> to actually put it on the site, the same way this deployment's API base is configured there.</p>
          </div>
        </div>
      </div>
    </div>`;

  root.querySelectorAll('[data-driver-select]').forEach((select) => {
    select.addEventListener('change', () => {
      const kind = select.dataset.driverSelect;
      root.querySelector(`[data-driver-hint="${kind}"]`).textContent = DRIVER_HINT[kind][select.value] || '';
    });
  });

  root.querySelector('[data-save="payment-driver"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const driver = root.querySelector('[data-driver-select="payment"]').value;
    setBusy(button, true, 'Saving');

    try {
      await api.patch('/admin/settings/payment-driver', { driver });
      toast('Payment driver updated.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  root.querySelector('[data-save="delivery-driver"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const driver = root.querySelector('[data-driver-select="delivery"]').value;
    setBusy(button, true, 'Saving');

    try {
      await api.patch('/admin/settings/delivery-driver', { driver });
      toast('Delivery driver updated.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  root.querySelector('[data-save="cod"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const enabled = root.querySelector('#cod-enabled').checked;
    setBusy(button, true, 'Saving');

    try {
      await api.patch('/admin/settings/cod', { enabled });
      toast('Cash on Delivery setting updated.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  root.querySelector('[data-save="price-change-mode"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const mode = root.querySelector('[data-price-mode]').value;
    setBusy(button, true, 'Saving');

    try {
      await api.patch('/admin/settings/price-change-mode', { mode });
      toast('Price-change mode updated.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  const dueReminderToggle = root.querySelector('#due-reminder-enabled');

  if (dueReminderToggle) {
    dueReminderToggle.addEventListener('change', async () => {
      dueReminderToggle.disabled = true;

      try {
        await api.patch(`/admin/scheduler/tasks/${encodeURIComponent(DUE_REMINDER_TASK_CODE)}`, {
          is_enabled: dueReminderToggle.checked,
        });
        toast(dueReminderToggle.checked ? 'Due-payment reminders enabled.' : 'Due-payment reminders disabled.');
        render();
      } catch (error) {
        dueReminderToggle.checked = !dueReminderToggle.checked;
        dueReminderToggle.disabled = false;
        showError(error);
      }
    });
  }

  root.querySelector('[data-save="due-reminder-timing"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const delayHours = Number(root.querySelector('[data-due-delay]').value);
    const repeatHours = Number(root.querySelector('[data-due-repeat]').value);
    setBusy(button, true, 'Saving');

    try {
      await api.patch('/admin/settings/pos-due-reminder', { delay_hours: delayHours, repeat_hours: repeatHours });
      toast('Due-payment reminder timing updated.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  root.querySelector('[data-save="manual"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    setBusy(button, true, 'Saving');

    try {
      await api.patch('/admin/settings/manual', {
        manual_payment_vpa: root.querySelector('[data-manual-vpa]').value || null,
        manual_payment_payee_name: root.querySelector('[data-manual-name]').value || null,
      });
      toast('Manual payment settings updated.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  root.querySelector('[data-save="qr-image"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const file = root.querySelector('[data-qr-file]').files[0];

    if (!file) {
      toast('Choose an image first.', 'danger');
      return;
    }

    setBusy(button, true, 'Uploading');

    try {
      const formData = new FormData();
      formData.append('image', file);
      await api.upload('/admin/settings/manual/qr-image', formData);
      toast('QR code updated.');
      render();
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });

  root.querySelector('[data-save="logo"]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const file = root.querySelector('[data-logo-file]').files[0];

    if (!file) {
      toast('Choose an image first.', 'danger');
      return;
    }

    setBusy(button, true, 'Uploading');

    try {
      const formData = new FormData();
      formData.append('image', file);
      const response = await api.upload('/admin/settings/logo', formData);
      toast(response.message || 'Logo uploaded.');
      render();
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

const mounted = await mountConsole('settings.html');
if (mounted) { root = mounted.root; render(); }
