/**
 * Shared UI helpers: layout chrome, toasts, and the loading and error states
 * that every page needs.
 *
 * Kept deliberately small. A storefront that needs a framework to render a
 * product list will need a build step, and a build step puts future edits out
 * of the merchant's reach.
 */

import { api, isSignedIn, onAuthChange, signOut, escapeHtml, formatMoney } from './api.js';

/** Renders the header, and keeps the cart count and sign-in state current. */
export async function mountChrome(activePage) {
  const header = document.querySelector('[data-chrome="header"]');
  if (!header) return;

  const nav = [
    ['index.html', 'Shop'],
    ['orders.html', 'My orders'],
    ['support.html', 'Support'],
  ];

  header.innerHTML = `
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color:#7a2e1d">
      <div class="container">
        <a class="navbar-brand fw-semibold" href="index.html">Spice &amp; Dry Fruits</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#primary-nav" aria-controls="primary-nav"
                aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="primary-nav">
          <ul class="navbar-nav me-auto">
            ${nav.map(([href, label]) => `
              <li class="nav-item">
                <a class="nav-link ${href === activePage ? 'active fw-semibold' : ''}" href="${href}">${label}</a>
              </li>`).join('')}
          </ul>
          <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-light position-relative" href="cart.html">
              Cart
              <span class="badge rounded-pill bg-light text-dark d-none" data-chrome="cart-count">0</span>
            </a>
            <a class="btn btn-light ${isSignedIn() ? 'd-none' : ''}" data-chrome="sign-in" href="account.html">Sign in</a>
            <button class="btn btn-outline-light ${isSignedIn() ? '' : 'd-none'}" data-chrome="sign-out" type="button">Sign out</button>
          </div>
        </div>
      </div>
    </nav>`;

  header.querySelector('[data-chrome="sign-out"]').addEventListener('click', async () => {
    await signOut();
    window.location.href = 'index.html';
  });

  onAuthChange((signedIn) => {
    header.querySelector('[data-chrome="sign-in"]').classList.toggle('d-none', signedIn);
    header.querySelector('[data-chrome="sign-out"]').classList.toggle('d-none', !signedIn);
  });

  refreshCartCount();
}

/** Updates the cart badge. Failures are silent: a badge is not worth an alert. */
export async function refreshCartCount() {
  const badge = document.querySelector('[data-chrome="cart-count"]');
  if (!badge) return;

  try {
    const response = await api.get('/cart');
    const count = (response.data.items || [])
      .filter((item) => !item.is_saved_for_later)
      .reduce((total, item) => total + Number(item.quantity || 0), 0);

    badge.textContent = String(count);
    badge.classList.toggle('d-none', count === 0);
  } catch {
    badge.classList.add('d-none');
  }
}

export function mountFooter() {
  const footer = document.querySelector('[data-chrome="footer"]');
  if (!footer) return;

  footer.innerHTML = `
    <footer class="mt-5 py-4 border-top bg-light">
      <div class="container small text-muted d-flex flex-wrap gap-3 justify-content-between">
        <div>
          <a class="link-secondary text-decoration-none me-3" href="page.html?slug=shipping-policy">Shipping</a>
          <a class="link-secondary text-decoration-none me-3" href="page.html?slug=returns-and-refunds">Returns</a>
          <a class="link-secondary text-decoration-none me-3" href="page.html?slug=privacy-policy">Privacy</a>
          <a class="link-secondary text-decoration-none" href="faq.html">FAQ</a>
        </div>
        <div>Prepaid UPI only. Prices include GST.</div>
      </div>
    </footer>`;
}

/** A dismissible toast. Errors persist; successes fade. */
export function toast(message, variant = 'success') {
  let host = document.querySelector('[data-toast-host]');

  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-toast-host', '');
    host.className = 'toast-container position-fixed top-0 end-0 p-3';
    host.style.zIndex = '1080';
    document.body.appendChild(host);
  }

  const element = document.createElement('div');
  element.className = `toast align-items-center text-bg-${variant} border-0 show`;
  element.setAttribute('role', 'alert');
  element.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Close"></button>
    </div>`;

  element.querySelector('.btn-close').addEventListener('click', () => element.remove());
  host.appendChild(element);

  if (variant !== 'danger') {
    setTimeout(() => element.remove(), 4000);
  }
}

/**
 * Shows an API error.
 *
 * The server writes `message` to be shown to a customer, so it is used as-is.
 * Field errors are listed under it rather than concatenated into one string,
 * which is how a customer ends up with a wall of red text and no idea which box
 * to fix.
 */
export function showError(error, container) {
  const messages = error.fieldMessages ? error.fieldMessages() : [];

  if (container) {
    container.innerHTML = `
      <div class="alert alert-danger" role="alert">
        <div>${escapeHtml(error.message)}</div>
        ${messages.length ? `<ul class="mb-0 mt-2 small">${messages.map((m) => `<li>${escapeHtml(m)}</li>`).join('')}</ul>` : ''}
      </div>`;
    container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return;
  }

  toast(error.message, 'danger');
}

export function setBusy(button, busy, busyLabel = 'Working…') {
  if (!button) return;

  if (busy) {
    button.dataset.originalLabel = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${escapeHtml(busyLabel)}`;
    return;
  }

  button.disabled = false;
  if (button.dataset.originalLabel) button.innerHTML = button.dataset.originalLabel;
}

export function skeleton(count = 4) {
  return Array.from({ length: count }, () => `
    <div class="col">
      <div class="card h-100 placeholder-glow">
        <div class="card-body">
          <p class="placeholder col-8"></p>
          <p class="placeholder col-5"></p>
          <p class="placeholder col-3"></p>
        </div>
      </div>
    </div>`).join('');
}

export function queryParam(name) {
  return new URLSearchParams(window.location.search).get(name);
}

export { escapeHtml, formatMoney };
