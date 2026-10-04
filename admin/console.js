/**
 * Admin console shell: sign-in, navigation, and the helpers every screen needs.
 *
 * Same approach as the storefront — Bootstrap 5, ES modules, no build step —
 * and it reuses the storefront's API client rather than carrying a second copy.
 * Two clients would drift, and the one that drifts is always the one handling
 * token refresh.
 *
 * WHAT THIS CONSOLE IS FOR. Running the business day to day: seeing what needs
 * attention, moving orders along, answering customers. It is deliberately not a
 * complete mirror of all 112 admin endpoints. A console that exposes everything
 * equally makes the twenty things done hourly as hard to find as the two things
 * done yearly.
 */

import { api, ApiError, storeTokens, clearTokens, bootstrapSession, isSignedIn,
         signOut, escapeHtml, formatMoney } from '../../assets/js/api.js';

export { api, ApiError, escapeHtml, formatMoney };

/**
 * The brand: a logo if one is configured, the name otherwise.
 *
 * Two variants because the backgrounds differ — the sidebar is deep forest, the
 * sign-in card is white, and one logo cannot serve both. `onerror` falls back to
 * the name so a mistyped path never leaves a broken image in the sidebar of
 * every screen.
 *
 * @param {boolean} light  true for the white sign-in card
 */
function brandMarkup(light = false) {
  const brand = window.SPICE_BRAND || {};
  const name = brand.name || 'Spice & Dry Fruits';
  const url = light ? (brand.logoUrlLight || brand.logoUrl) : brand.logoUrl;

  const fallback = `<span class="fw-semibold ${light ? '' : 'text-white'}">${escapeHtml(name)}</span>`;

  if (!url) return fallback;

  return `<img src="${escapeHtml(url)}"
               alt="${escapeHtml(brand.logoAlt || name)}"
               style="height:${Number(brand.logoHeight) || 34}px;width:auto"
               onerror="this.outerHTML='${fallback.replace(/'/g, "\\'")}'">`;
}

/** Screens, in the order the work actually happens. */
const NAV = [
  ['index.html', 'Dashboard', 'What needs attention now'],
  ['orders.html', 'Orders', 'Confirm, pack, ship'],
  ['payments.html', 'Payments', 'Manual UPI verification'],
  ['support.html', 'Support', 'Customer tickets'],
  ['reviews.html', 'Reviews', 'Moderation queue'],
  ['products.html', 'Products', 'Catalogue'],
  ['inventory.html', 'Inventory', 'Stock levels and the movement ledger'],
  ['purchase-inward.html', 'Purchase Inward', 'Record purchases from vendors'],
  ['import.html', 'Import', 'Bulk product/stock import from CSV or Excel'],
  ['mobile.html', 'Mobile Scan', 'Scan barcodes to record inward on the move'],
  ['pricing.html', 'Pricing', 'Markup/margin rules and price-change behaviour'],
  ['vendors.html', 'Vendors', 'Suppliers stock is bought from'],
  ['warehouses.html', 'Warehouses', 'Locations stock is held at'],
  ['promotions.html', 'Promotions', 'Coupons and offers'],
  ['content.html', 'Shopfront', 'Categories and adverts'],
  ['bulk.html', 'Wholesale', 'Gifting and bulk enquiries'],
  ['customers.html', 'Customers', 'Who buys, and how to reach them'],
  ['reports.html', 'Reports', 'Takings, tax and refunds'],
];

export function queryParam(name) {
  return new URLSearchParams(window.location.search).get(name);
}

export function toast(message, variant = 'success') {
  let host = document.querySelector('[data-toast-host]');

  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-toast-host', '');
    host.className = 'toast-container position-fixed bottom-0 end-0 p-3';
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

  if (variant !== 'danger') setTimeout(() => element.remove(), 4000);
}

export function setBusy(button, busy, label = 'Working…') {
  if (!button) return;

  if (busy) {
    button.dataset.originalLabel = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${escapeHtml(label)}`;
    return;
  }

  button.disabled = false;
  if (button.dataset.originalLabel) button.innerHTML = button.dataset.originalLabel;
}

/**
 * Shows an error.
 *
 * Staff get MORE detail than customers, deliberately. A customer needs to know
 * what to do next; someone running the business needs to know what the system
 * actually refused, because they are the one who has to decide whether it is a
 * mistake or a rule doing its job.
 */
export function showError(error, container) {
  const messages = error.fieldMessages ? error.fieldMessages() : [];

  if (!error || typeof error.status !== 'number') {
    const banner = `
      <div class="alert alert-danger">
        <div class="fw-semibold">Cannot reach the API.</div>
        <div class="small">Check that the backend is running and that
          <code>admin/assets/config.js</code> points at it.</div>
      </div>`;

    if (container) container.innerHTML = banner;
    else toast('Cannot reach the API.', 'danger');

    return;
  }

  const detail = `
    <div class="alert alert-danger" role="alert">
      <div class="fw-semibold">${escapeHtml(error.message)}</div>
      ${messages.length
        ? `<ul class="mb-0 mt-2 small">${messages.map((m) => `<li>${escapeHtml(m)}</li>`).join('')}</ul>`
        : ''}
      <div class="small text-muted mt-2">HTTP ${escapeHtml(error.status)}</div>
    </div>`;

  if (container) container.innerHTML = detail;
  else toast(error.message, 'danger');
}

/** Renders the sidebar and header once the user is known to be staff. */
function renderChrome(activePage, user) {
  const shell = document.querySelector('[data-console]');

  shell.innerHTML = `
    <div class="d-flex flex-column flex-lg-row min-vh-100">
      <nav class="console-sidebar p-3 flex-shrink-0">
        <div class="mb-3">${brandMarkup()}</div>
        <ul class="nav flex-column gap-1">
          ${NAV.map(([href, label, hint]) => `
            <li class="nav-item">
              <a class="nav-link ${href === activePage ? 'active' : ''}" href="${href}">
                ${escapeHtml(label)}
                <span class="d-block small opacity-75">${escapeHtml(hint)}</span>
              </a>
            </li>`).join('')}
        </ul>
        <hr class="text-white-50">
        <div class="small text-white-50">
          Signed in as<br>
          <span class="text-white">${escapeHtml(user.full_name)}</span><br>
          <span class="text-capitalize">${escapeHtml(String(user.role || '').replace('_', ' '))}</span>
        </div>
        <button class="btn btn-sm btn-outline-light mt-3 w-100" data-sign-out type="button">Sign out</button>
      </nav>
      <main class="flex-grow-1 p-3 p-lg-4" data-page-root>
        <div class="text-center py-5 text-muted">
          <div class="spinner-border" role="status"><span class="visually-hidden">Loading</span></div>
        </div>
      </main>
    </div>`;

  shell.querySelector('[data-sign-out]').addEventListener('click', async () => {
    await signOut();
    window.location.reload();
  });
}

/** The sign-in screen. Staff only. */
function renderSignIn(message) {
  const shell = document.querySelector('[data-console]');

  shell.innerHTML = `
    <div class="d-flex align-items-center justify-content-center min-vh-100">
      <div class="card shadow-sm" style="width:min(26rem,92vw)">
        <div class="card-body p-4">
          <div class="mb-3">${brandMarkup(true)}</div>
          <h1 class="h5 mb-1">Staff sign-in</h1>
          <p class="text-muted small">Console</p>
          ${message ? `<div class="alert alert-warning small">${escapeHtml(message)}</div>` : ''}
          <form data-signin-form>
            <div class="mb-3">
              <label class="form-label" for="identifier">Mobile or email</label>
              <input class="form-control" id="identifier" name="identifier" required autocomplete="username">
            </div>
            <div class="mb-3">
              <label class="form-label" for="password">Password</label>
              <input class="form-control" id="password" name="password" type="password" required
                     autocomplete="current-password">
            </div>
            <button class="btn btn-dark w-100" type="submit">Sign in</button>
          </form>
        </div>
      </div>
    </div>`;

  shell.querySelector('[data-signin-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button');
    setBusy(button, true, 'Signing in');

    try {
      const response = await api.post('/auth/login',
        Object.fromEntries(new FormData(event.currentTarget).entries()));
      storeTokens(response.data.tokens);
      window.location.reload();
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

/**
 * Boots a console page.
 *
 * ROLE IS CHECKED, NOT ASSUMED. A customer with a valid token could open these
 * pages; every endpoint would refuse them, but they would see a broken console
 * full of red errors rather than a clear message. The server remains the
 * authority — this check is courtesy, not security.
 *
 * @returns {Promise<{root: HTMLElement, user: object}|null>}
 */
export async function mountConsole(activePage) {
  await bootstrapSession();

  if (!isSignedIn()) {
    renderSignIn();
    return null;
  }

  let user;

  try {
    const response = await api.get('/auth/me');
    user = response.data.user;
  } catch (error) {
    clearTokens();
    renderSignIn(error && error.status === 401
      ? 'Your session has ended. Please sign in again.'
      : 'Could not confirm your account.');
    return null;
  }

  const staffRoles = ['administrator', 'supervisor', 'executive', 'manager', 'inventory_staff', 'cashier'];

  if (!staffRoles.includes(String(user.role))) {
    clearTokens();
    renderSignIn('That account is not a staff account. Use a staff sign-in.');
    return null;
  }

  renderChrome(activePage, user);

  return { root: document.querySelector('[data-page-root]'), user };
}

/** A small headline figure. */
export function statCard(label, value, hint = '', tone = '') {
  return `
    <div class="col">
      <div class="card h-100 ${tone ? `border-${tone}` : ''}">
        <div class="card-body">
          <div class="text-muted small">${escapeHtml(label)}</div>
          <div class="fs-4 fw-semibold ${tone ? `text-${tone}` : ''}">${value}</div>
          ${hint ? `<div class="small text-muted">${escapeHtml(hint)}</div>` : ''}
        </div>
      </div>
    </div>`;
}

/** Status colours, shared so a status never means two things. */
export const STATUS_TONE = {
  created: 'secondary',
  awaiting_payment: 'warning',
  confirmed: 'primary',
  packed: 'primary',
  ready: 'primary',
  assigned: 'info',
  shipped: 'info',
  out_for_delivery: 'info',
  delivered: 'success',
  cancelled: 'secondary',
  returned: 'warning',
  refunded: 'secondary',
  pending: 'warning',
  paid: 'success',
  failed: 'danger',
  open: 'warning',
  in_progress: 'primary',
  awaiting_customer: 'info',
  resolved: 'success',
  closed: 'secondary',
  approved: 'success',
  rejected: 'danger',
  hidden: 'warning',
};

export function badge(status, label) {
  return `<span class="badge text-bg-${STATUS_TONE[status] || 'secondary'}">${escapeHtml(label || status)}</span>`;
}

export function emptyState(title, hint) {
  return `
    <div class="text-center py-5">
      <p class="fw-semibold mb-1">${escapeHtml(title)}</p>
      <p class="text-muted small mb-0">${escapeHtml(hint)}</p>
    </div>`;
}
