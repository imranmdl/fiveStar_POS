/**
 * Checkout: address, place, OTP, pay.
 *
 * This is the reference implementation of the order flow in
 * docs/CLIENT_INTEGRATION.md, and it demonstrates the three rules that guide
 * insists on:
 *
 *   1. Send `expected_grand_total` and handle the 409 that means it moved.
 *   2. Disable the place button on first click — placement is the one
 *      non-idempotent operation in the API.
 *   3. POLL THE ORDER after payment rather than trusting the client callback.
 *      The webhook is what confirms an order, and it arrives whether or not
 *      this page is still open.
 */

import { api, ApiError, bootstrapSession, isSignedIn } from './api.js';
import { mountChrome, mountFooter, refreshCartCount, escapeHtml, formatMoney,
         showError, toast, setBusy } from './ui.js';

const root = document.querySelector('[data-page-root]');

const state = {
  addresses: [],
  selectedAddress: null,
  review: null,
  order: null,
  otpReference: null,
};

function addressCard(address) {
  return `
    <label class="list-group-item d-flex gap-3 align-items-start">
      <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="address"
             value="${escapeHtml(address.uuid)}"
             ${state.selectedAddress === address.uuid ? 'checked' : ''}>
      <span>
        <span class="fw-semibold">${escapeHtml(address.contact_name)}</span>
        ${address.is_default ? '<span class="badge text-bg-secondary ms-2">Default</span>' : ''}
        <span class="d-block small text-muted">
          ${escapeHtml([address.address_line1, address.address_line2, address.city,
                        address.state, address.pincode].filter(Boolean).join(', '))}
        </span>
        <span class="d-block small text-muted">${escapeHtml(address.contact_mobile)}</span>
      </span>
    </label>`;
}

function newAddressForm() {
  return `
    <form class="row g-2 mt-3" data-address-form>
      <div class="col-12"><h3 class="h6 mb-0">Add a delivery address</h3></div>
      <div class="col-md-6"><input class="form-control" name="contact_name" placeholder="Full name" required></div>
      <div class="col-md-6"><input class="form-control" name="contact_mobile" placeholder="Mobile number" required></div>
      <div class="col-12"><input class="form-control" name="address_line1" placeholder="Address line 1" required></div>
      <div class="col-12"><input class="form-control" name="address_line2" placeholder="Address line 2 (optional)"></div>
      <div class="col-md-5"><input class="form-control" name="city" placeholder="City" required></div>
      <div class="col-md-4"><input class="form-control" name="state" placeholder="State" required></div>
      <div class="col-md-3"><input class="form-control" name="pincode" placeholder="Pincode" required inputmode="numeric"></div>
      <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Save address</button></div>
    </form>`;
}

function summaryPanel(review) {
  const pricing = review.cart.pricing.summary;
  const payment = review.cart.payment;
  const checkout = review.checkout;

  return `
    <div class="card">
      <div class="card-body">
        <h2 class="h6 mb-3">Order summary</h2>
        <dl class="row small mb-2">
          <dt class="col-7 fw-normal">Items</dt>
          <dd class="col-5 text-end">${formatMoney(pricing.items_subtotal)}</dd>
          ${Number(pricing.order_discount) > 0 ? `
            <dt class="col-7 fw-normal text-success">Discount</dt>
            <dd class="col-5 text-end text-success">−${formatMoney(pricing.order_discount)}</dd>` : ''}
          <dt class="col-7 fw-normal">Delivery</dt>
          <dd class="col-5 text-end">${Number(pricing.delivery_charge) === 0
            ? '<span class="text-success">Free</span>' : formatMoney(pricing.delivery_charge)}</dd>
        </dl>
        <hr>
        <div class="d-flex justify-content-between fw-semibold">
          <span>Total</span><span>${formatMoney(pricing.grand_total)}</span>
        </div>
        <div class="small text-muted">Includes ${formatMoney(pricing.tax_total)} GST</div>
        ${Number(payment.wallet_applied) > 0 ? `
          <div class="d-flex justify-content-between small text-success mt-2">
            <span>Wallet credit</span><span>−${formatMoney(payment.wallet_applied)}</span>
          </div>
          <div class="d-flex justify-content-between fw-semibold">
            <span>To pay by UPI</span><span>${formatMoney(payment.amount_payable)}</span>
          </div>` : ''}

        ${checkout.blockers.length ? `
          <div class="alert alert-warning small mt-3 mb-0">
            <ul class="mb-0 ps-3 blocker-list">
              ${checkout.blockers.map((b) => `<li>${escapeHtml(b)}</li>`).join('')}
            </ul>
          </div>` : ''}

        <button class="btn btn-spice w-100 mt-3" data-place type="button"
                ${checkout.blockers.length ? 'disabled' : ''}>
          Place order
        </button>
        <p class="small text-muted mt-2 mb-0">
          You will confirm with an OTP, then pay by UPI. Your order is not
          confirmed until payment is received.
        </p>
      </div>
    </div>`;
}

async function loadReview() {
  const response = await api.get('/checkout/review', {
    address_uuid: state.selectedAddress || undefined,
  });

  state.review = response.data;
  state.addresses = state.review.addresses || [];

  if (!state.selectedAddress && state.review.selected_address) {
    state.selectedAddress = state.review.selected_address.uuid;
  }

  render();
}

function render() {
  const review = state.review;

  root.innerHTML = `
    <h1 class="h4 mb-4">Checkout</h1>
    <div class="row g-4">
      <div class="col-12 col-lg-7">
        <div class="card">
          <div class="card-body">
            <h2 class="h6">Deliver to</h2>
            <div class="list-group" data-addresses>
              ${state.addresses.length
                ? state.addresses.map(addressCard).join('')
                : '<div class="list-group-item text-muted small">No saved addresses yet.</div>'}
            </div>
            ${newAddressForm()}
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-5" data-summary>
        ${summaryPanel(review)}
      </div>
    </div>`;

  root.querySelectorAll('[name="address"]').forEach((input) => {
    input.addEventListener('change', async () => {
      state.selectedAddress = input.value;
      // Re-price: the delivery charge depends on the destination, so changing
      // address changes the total.
      await loadReview();
    });
  });

  root.querySelector('[data-address-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button');
    setBusy(button, true, 'Saving');

    try {
      const payload = Object.fromEntries(new FormData(form).entries());
      const response = await api.post('/addresses', payload);
      state.selectedAddress = response.data.address.uuid;
      toast('Address saved.');
      await loadReview();
    } catch (error) {
      showError(error);
      setBusy(button, false);
    }
  });

  const placeButton = root.querySelector('[data-place]');
  if (placeButton) placeButton.addEventListener('click', () => placeOrder(placeButton));
}

async function placeOrder(button) {
  // ORDER PLACEMENT IS NOT IDEMPOTENT. A retried POST creates a second order,
  // so the button is disabled on the first click and stays disabled until the
  // response arrives.
  setBusy(button, true, 'Placing your order…');

  try {
    const response = await api.post('/checkout/place', {
      address_uuid: state.selectedAddress,
      // The total this customer actually saw. If it has moved, the server
      // refuses with 409 rather than charging a figure nobody agreed to.
      expected_grand_total: state.review.cart.pricing.summary.grand_total,
    });

    state.order = response.data.order;
    state.otpReference = response.data.otp && response.data.otp.reference_token;
    refreshCartCount();
    renderOtpStep(response.data.otp);
  } catch (error) {
    setBusy(button, false);

    if (error instanceof ApiError && error.status === 409) {
      // The total changed while they were deciding. Re-display and ask again;
      // do not retry with the stale figure.
      toast(error.message, 'danger');
      await loadReview();
      return;
    }

    showError(error);
  }
}

function renderOtpStep(otp) {
  root.innerHTML = `
    <div class="row justify-content-center">
      <div class="col-12 col-md-7 col-lg-5">
        <div class="card">
          <div class="card-body">
            <h1 class="h5">Confirm your order</h1>
            <p class="text-muted small">
              Order <span class="fw-semibold">${escapeHtml(state.order.order_number)}</span>.
              We have sent a code to ${escapeHtml((otp && otp.sent_to) || 'your mobile')}.
            </p>

            ${otp && otp.debug_otp ? `
              <div class="alert alert-info small">
                Development mode: your code is <span class="fw-semibold">${escapeHtml(otp.debug_otp)}</span>.
              </div>` : ''}

            <form data-otp-form>
              <label class="form-label" for="otp">Verification code</label>
              <input class="form-control form-control-lg text-center" id="otp" name="otp"
                     inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
              <button class="btn btn-spice w-100 mt-3" type="submit">Confirm and continue</button>
            </form>

            <div class="text-center mt-3">
              <button class="btn btn-link btn-sm" data-resend type="button">Resend the code</button>
            </div>
          </div>
        </div>
      </div>
    </div>`;

  root.querySelector('[data-otp-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button');
    setBusy(button, true, 'Verifying');

    try {
      await api.post(`/checkout/orders/${state.order.uuid}/verify-otp`, {
        otp: new FormData(event.currentTarget).get('otp'),
        reference_token: state.otpReference,
      });
      await startPayment();
    } catch (error) {
      showError(error);
      setBusy(button, false);
    }
  });

  root.querySelector('[data-resend]').addEventListener('click', async (event) => {
    setBusy(event.currentTarget, true, 'Sending');
    try {
      const response = await api.post(`/checkout/orders/${state.order.uuid}/resend-otp`, {});
      state.otpReference = response.data.reference_token;
      toast('A new code is on its way.');
    } catch (error) {
      showError(error);
    } finally {
      setBusy(event.currentTarget, false);
    }
  });
}

async function startPayment() {
  const response = await api.post(`/checkout/orders/${state.order.uuid}/payment`, {});
  const payment = response.data.payment;

  if (response.data.fully_paid_by_wallet) {
    renderConfirmed();
    return;
  }

  root.innerHTML = `
    <div class="row justify-content-center">
      <div class="col-12 col-md-7 col-lg-5">
        <div class="card">
          <div class="card-body text-center">
            <h1 class="h5">Pay ${formatMoney(payment.amount)}</h1>
            <p class="text-muted small">Order ${escapeHtml(state.order.order_number)}</p>

            ${payment.upi_intent_url ? `
              <a class="btn btn-spice btn-lg w-100 my-3" href="${escapeHtml(payment.upi_intent_url)}">
                Pay with a UPI app
              </a>` : ''}

            ${payment.qr_payload ? `
              <p class="small text-muted">Or scan this with any UPI app.</p>
              <div class="border rounded p-3 mb-3 text-break small font-monospace">
                ${escapeHtml(payment.qr_payload)}
              </div>` : ''}

            <div class="alert alert-light border small mb-0" data-payment-status>
              <div class="spinner-border spinner-border-sm me-2"></div>
              Waiting for your payment to be confirmed…
            </div>

            <p class="small text-muted mt-3 mb-0">
              You can close this page. Your order will be confirmed as soon as the
              payment reaches us, and we will send you a message.
            </p>
          </div>
        </div>
      </div>
    </div>`;

  pollForConfirmation();
}

/**
 * Waits for the server to confirm the order.
 *
 * THE CLIENT DOES NOT DECIDE WHETHER A PAYMENT SUCCEEDED. The server confirms
 * an order only on a signature-verified webhook from the gateway, which arrives
 * whether or not this page is still open. Polling the order is the honest way
 * to find out; treating our own callback as proof would show "confirmed" for a
 * payment that never settled.
 */
async function pollForConfirmation(attempt = 0) {
  const statusEl = root.querySelector('[data-payment-status]');
  if (!statusEl) return;

  try {
    const response = await api.get(`/orders/${state.order.uuid}`);
    const order = response.data.order;

    if (order.payment_status === 'paid') {
      renderConfirmed(order);
      return;
    }

    if (order.status === 'cancelled') {
      statusEl.className = 'alert alert-warning small mb-0';
      statusEl.textContent = 'This order was cancelled because payment was not completed in time.';
      return;
    }
  } catch {
    // A failed poll is not a failed payment. Keep waiting.
  }

  if (attempt >= 15) {
    // Give up on the page, not on the order. The webhook may still arrive, and
    // saying so is more honest than an indefinite spinner.
    statusEl.className = 'alert alert-info small mb-0';
    statusEl.innerHTML = `
      We have not seen your payment yet. If money has left your account it will be
      matched automatically within a few minutes and we will message you.
      <a href="orders.html" class="d-block mt-2">Check your orders</a>`;
    return;
  }

  setTimeout(() => pollForConfirmation(attempt + 1), 2000);
}

function renderConfirmed(order) {
  root.innerHTML = `
    <div class="row justify-content-center">
      <div class="col-12 col-md-7 col-lg-5 text-center py-4">
        <div class="display-6 text-success mb-2">✓</div>
        <h1 class="h4">Your order is confirmed</h1>
        <p class="text-muted">
          Order ${escapeHtml(state.order.order_number)}${order && order.invoice_number
            ? ` · Invoice ${escapeHtml(order.invoice_number)}` : ''}
        </p>
        <p class="small text-muted">
          We are preparing it now and will send tracking details as soon as it ships.
        </p>
        <a class="btn btn-spice" href="orders.html">View your orders</a>
        <a class="btn btn-link" href="index.html">Continue shopping</a>
      </div>
    </div>`;

  refreshCartCount();
}

async function start() {
  await bootstrapSession();

  if (!isSignedIn()) {
    root.innerHTML = `
      <div class="row justify-content-center">
        <div class="col-12 col-md-6 text-center py-5">
          <h1 class="h5">Please sign in to check out</h1>
          <p class="text-muted small">Your cart will be waiting.</p>
          <a class="btn btn-spice" href="account.html?next=checkout.html">Sign in or create an account</a>
        </div>
      </div>`;
    return;
  }

  try {
    await loadReview();
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

mountChrome('cart.html');
mountFooter();
start();
