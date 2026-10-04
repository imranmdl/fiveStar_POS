/**
 * The "leave your details for offers" popup — captures a VISITOR's contact
 * info, not a customer account. No password, no signup; just enough to
 * message them later about offers, new arrivals and best-sellers (see
 * MarketingService::sendRecurringBroadcast() on the backend).
 *
 * Same "must never cost the customer anything" rules banners.js already
 * follows: shown at most once per browser session, dismissible and
 * remembered for the session, never blocks the page, and a failed request
 * is a toast, not a wall.
 *
 * CONSENT IS A REAL CHECKBOX, UNCHECKED BY DEFAULT. Submitting without it
 * still saves the contact (so a second visit doesn't ask again) but the
 * backend never sends a promotional message to someone who didn't opt in —
 * see leads.consent_marketing. This isn't a UX nicety, it's what makes the
 * eventual SMS lawful under TRAI's promotional-message rules.
 */

import { api, escapeHtml } from './api.js';

const DISMISSED_KEY = 'spice.lead_popup_dismissed';
const SHOW_DELAY_MS = 4000;

function dismissedThisSession() {
  try {
    return sessionStorage.getItem(DISMISSED_KEY) === '1';
  } catch {
    return false;
  }
}

function remember() {
  try {
    sessionStorage.setItem(DISMISSED_KEY, '1');
  } catch {
    // No session storage — the popup simply reappears next page. Not worth failing over.
  }
}

function modalHost() {
  let host = document.querySelector('[data-lead-modal]');

  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-lead-modal', '');
    document.body.appendChild(host);
  }

  return host;
}

function markup() {
  return `
    <div class="modal fade" tabindex="-1" data-lead-popup>
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">Get offers &amp; new arrivals first</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form data-lead-form>
            <div class="modal-body">
              <p class="small text-muted">
                Leave your details and we'll send you offers, new products and
                best-sellers — nothing else, and you can stop any time.
              </p>
              <div class="mb-2">
                <label class="form-label small mb-0" for="lead_name">Name</label>
                <input class="form-control form-control-sm" id="lead_name" name="full_name" placeholder="Optional">
              </div>
              <div class="mb-2">
                <label class="form-label small mb-0" for="lead_email">Email <span class="text-danger">*</span></label>
                <input class="form-control form-control-sm" id="lead_email" name="email" type="email" required>
              </div>
              <div class="mb-2">
                <label class="form-label small mb-0" for="lead_mobile">Mobile <span class="text-danger">*</span></label>
                <input class="form-control form-control-sm" id="lead_mobile" name="mobile" type="tel" required
                       pattern="[0-9]{10}" maxlength="10" placeholder="10-digit mobile">
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="lead_consent" name="consent">
                <label class="form-check-label small" for="lead_consent">
                  I agree to receive offers and updates by SMS.
                </label>
              </div>
              <div data-lead-feedback class="small mt-2"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Not now</button>
              <button type="submit" class="btn btn-sm btn-dark">Notify me</button>
            </div>
          </form>
        </div>
      </div>
    </div>`;
}

export function mountLeadCapture() {
  if (dismissedThisSession()) return;
  if (!window.bootstrap || !window.bootstrap.Modal) return;

  setTimeout(() => {
    // A visitor may have already navigated away from the tab, or dismissed
    // a first popup that appeared on an earlier page before this timer fired.
    if (dismissedThisSession()) return;

    const host = modalHost();
    host.innerHTML = markup();

    const modalEl = host.querySelector('[data-lead-popup]');
    const modal = new window.bootstrap.Modal(modalEl);

    modalEl.addEventListener('hidden.bs.modal', () => {
      remember();
      host.innerHTML = '';
    }, { once: true });

    const form = host.querySelector('[data-lead-form]');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const feedback = form.querySelector('[data-lead-feedback]');
      const data = Object.fromEntries(new FormData(form).entries());

      button.disabled = true;
      button.textContent = 'Saving…';

      try {
        await api.post('/leads', {
          full_name: data.full_name || null,
          email: data.email,
          mobile: data.mobile,
          consent: data.consent === 'on',
          source: 'website_popup',
        });

        feedback.innerHTML = '<span class="text-success">Thanks — you\'re on the list.</span>';
        remember();
        setTimeout(() => modal.hide(), 1200);
      } catch (error) {
        button.disabled = false;
        button.textContent = 'Notify me';
        feedback.innerHTML = `<span class="text-danger">${escapeHtml(error.message || 'Something went wrong — try again.')}</span>`;
      }
    });

    modal.show();
  }, SHOW_DELAY_MS);
}
