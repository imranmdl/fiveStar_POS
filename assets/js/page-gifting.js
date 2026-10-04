/**
 * Corporate gifting and bulk enquiries.
 *
 * A company ordering two hundred Diwali boxes does not use a shopping cart —
 * they ask for a price. This form starts that conversation, and deliberately
 * needs no account: making a business register before they can ask what
 * something costs loses the enquiry.
 */

import { api, ApiError } from './api.js';
import { mountChrome, mountFooter, escapeHtml, showError, toast, setBusy } from './ui.js';

const root = document.querySelector('[data-page-root]');

function form() {
  return `
    <div class="row g-4">
      <div class="col-12 col-lg-7">
        <h1 class="h4 mb-2">Gifting &amp; bulk orders</h1>
        <p class="text-muted">
          Diwali hampers for a team, wedding favours, a standing order for an
          office pantry. Tell us what you need and we will send a price — usually
          the same working day.
        </p>

        <form class="panel p-3 p-md-4" data-enquiry-form>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="business_name">Business or organisation <span class="text-danger">*</span></label>
              <input class="form-control" id="business_name" name="business_name" required minlength="2">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="contact_name">Your name <span class="text-danger">*</span></label>
              <input class="form-control" id="contact_name" name="contact_name" required minlength="2">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="contact_mobile">Mobile <span class="text-danger">*</span></label>
              <input class="form-control" id="contact_mobile" name="contact_mobile"
                     inputmode="numeric" maxlength="10" required placeholder="9876543210">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="contact_email">Email</label>
              <input class="form-control" id="contact_email" name="contact_email" type="email">
            </div>

            <div class="col-12">
              <label class="form-label" for="requirements">What do you need? <span class="text-danger">*</span></label>
              <textarea class="form-control" id="requirements" name="requirements" rows="4"
                        required minlength="10"
                        placeholder="200 gift boxes with almonds, cashews and anjeer. Company logo on the sleeve. Needed by 15 October."></textarea>
              <div class="form-text">
                The more specific you are, the more accurate the quote. Quantities,
                packing and the date you need them all help.
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label" for="estimated_quantity">Roughly how many</label>
              <input class="form-control" id="estimated_quantity" name="estimated_quantity"
                     type="number" min="1">
            </div>

            <div class="col-md-4">
              <label class="form-label" for="estimated_budget">Budget (₹)</label>
              <input class="form-control" id="estimated_budget" name="estimated_budget"
                     type="number" min="0" step="0.01">
            </div>

            <div class="col-md-4">
              <label class="form-label" for="expected_delivery_date">Needed by</label>
              <input class="form-control" id="expected_delivery_date" name="expected_delivery_date" type="date">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="delivery_pincode">Delivery pincode</label>
              <input class="form-control" id="delivery_pincode" name="delivery_pincode"
                     inputmode="numeric" maxlength="6">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="gstin">GSTIN</label>
              <input class="form-control" id="gstin" name="gstin" maxlength="15">
              <div class="form-text">For a GST invoice in your company's name.</div>
            </div>

            <div class="col-12">
              <button class="btn btn-spice" type="submit">Send enquiry</button>
              <span class="small text-muted ms-2">No account needed.</span>
            </div>
          </div>
        </form>
      </div>

      <div class="col-12 col-lg-5">
        <div class="panel p-3 p-md-4">
          <h2 class="h6">How it works</h2>
          <ol class="small ps-3 mb-3">
            <li class="mb-2">You tell us what you need.</li>
            <li class="mb-2">We send a written quotation, valid for a set period.</li>
            <li class="mb-2">You accept it, and it becomes a normal order.</li>
            <li>Same OTP confirmation, same prepaid UPI, same tracking.</li>
          </ol>
          <p class="small text-muted mb-0">
            A wholesale order follows exactly the same rules as any other. Nothing
            ships before payment is confirmed — which matters most here, because
            these are the largest amounts.
          </p>
        </div>
      </div>
    </div>`;
}

function bind() {
  const element = root.querySelector('[data-enquiry-form]');

  element.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = element.querySelector('button[type="submit"]');
    const payload = {};

    new FormData(element).forEach((value, key) => { if (value !== '') payload[key] = value; });

    setBusy(button, true, 'Sending');

    try {
      const response = await api.post('/bulk-orders/enquiries', payload);
      const number = response.data.enquiry.enquiry_number;

      root.innerHTML = `
        <div class="panel p-4 text-center">
          <h1 class="h4 mb-2">Thank you — we have your enquiry</h1>
          <p class="mb-1">Reference <b>${escapeHtml(number)}</b></p>
          <p class="text-muted">
            We will send a quotation to the mobile number you gave us, usually the
            same working day. Keep the reference handy if you call.
          </p>
          <a class="btn btn-spice mt-2" href="index.html">Back to the shop</a>
        </div>`;
    } catch (error) {
      setBusy(button, false);

      if (error instanceof ApiError && error.status === 422) {
        showError(error, root.querySelector('[data-enquiry-form]'));
        return;
      }

      showError(error);
    }
  });
}

mountChrome('gifting.html');
mountFooter();
root.innerHTML = form();
bind();
