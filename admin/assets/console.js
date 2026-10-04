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
 *
 * CACHE-BUSTING: every page-*.js imports this file as './console.js?v=N', and
 * every admin *.html links console.css as 'assets/console.css?v=N' (same N).
 * No build step means no content hash to do this automatically — browsers
 * cache both aggressively with no explicit Cache-Control here, so editing
 * this file or console.css and NOT bumping N leaves pages showing a stale
 * sidebar/styles until each one is hard-refreshed individually. Bump N in
 * BOTH places (a project-wide find/replace) whenever either file changes.
 */

import { api, ApiError, storeTokens, clearTokens, bootstrapSession, isSignedIn,
         signOut, escapeHtml, formatMoney } from '../../assets/js/api.js';

export { api, ApiError, escapeHtml, formatMoney, storeTokens, clearTokens };

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
               onerror="this.outerHTML='${fallback.replace(/'/g, "\\'").replace(/"/g, '&quot;')}'">`;
}

/**
 * Screens, in the order the work actually happens.
 *
 * The till (till.html) is deliberately not listed here — it is a separate,
 * unlinked panel with its own password re-confirmation gate (see
 * page-till.js), reachable only by its direct URL.
 */
const NAV = [
  ['index.html', 'Dashboard', 'What needs attention now'],
  ['orders.html', 'Orders', 'Confirm, pack, ship'],
  ['shipments.html', 'Shipments', 'Book, track, manage couriers'],
  ['payments.html', 'Payments', 'Manual UPI & Cash on Delivery'],
  ['cashiers.html', 'Cashiers (POS)', 'Till logins and daily sales'],
  ['wallets.html', 'Wallets', 'Customer balances, credits and refunds'],
  ['loyalty.html', 'Loyalty', 'Points program, ledger and settings'],
  ['customer-dues.html', 'Customer Dues', 'Partial payments and outstanding balances'],
  ['invoices.html', 'Invoice Tracking', 'Till invoices, payment status and WhatsApp reminders'],
  ['support.html', 'Support', 'Customer tickets'],
  ['reviews.html', 'Reviews', 'Moderation queue'],
  ['products.html', 'Products', 'Catalogue'],
  ['warehouses.html', 'Warehouses', 'Storage locations'],
  ['inventory.html', 'Inventory', 'Stock levels & movements'],
  ['vendors.html', 'Vendors', 'Suppliers you buy from'],
  ['purchase-inward.html', 'Purchase Inward', 'Record vendor purchases'],
  ['purchase-returns.html', 'Purchase Returns', 'Goods sent back to vendors'],
  ['pricing.html', 'Pricing', 'Markup & margin rules'],
  ['promotions.html', 'Promotions', 'Coupons and offers'],
  ['content.html', 'Shopfront', 'Categories and adverts'],
  ['festivals.html', 'Festival Calendar', 'Plan and stock festival gift packs'],
  ['bulk.html', 'Wholesale', 'Gifting and bulk enquiries'],
  ['customers.html', 'Customers', 'Who buys, and how to reach them'],
  ['marketing.html', 'Marketing', 'Leads and the recurring offer broadcast'],
  ['barcode-generator.html', 'Barcode Generator', 'Generate and print barcode labels'],
  ['reports.html', 'Reports', 'Takings, tax and refunds'],
  ['profit-loss.html', 'Profit & Loss', 'Item-wise margins and invoice loss'],
  ['stock-audit.html', 'Stock Audit', 'Cost, selling price and stock movement'],
  ['settings.html', 'Settings', 'Payment, delivery and store configuration'],
  ['backups.html', 'Backups', 'Export, restore, and clean up old data'],
  ['access-control.html', 'Admin Privilege', 'Roles, staff accounts, approvals, audit log'],
];

/**
 * One colour and one small icon per section of the rail. Was a plain
 * coloured dot next to every item — which, next to real icons everywhere
 * else in the product, read as an unfinished bullet list rather than an
 * app nav. Grouped exactly as NAV's own ordering already implies
 * (Orders/Shipments/Cashiers together, Vendors/Purchasing together, and so
 * on), same idea the Admin Privilege panel's sidebar uses for its modules.
 * Icon paths are reused from elsewhere in this codebase (page-index.js,
 * page-settings.js) where a matching one already exists, rather than
 * hand-drawing something new for every one of the 29 pages here.
 */
const NAV_ICON = {
  home: '<path d="M4 11 12 4l8 7"/><path d="M6 10v10h5v-6h2v6h5V10"/>',
  box: '<path d="M4 7l8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
  wallet: '<path d="M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v3"/><path d="M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-4"/><path d="M17 12h3v3h-3a1.5 1.5 0 0 1 0-3Z"/>',
  chat: '<path d="M4 4h16v12H8l-4 4V4Z"/>',
  grid: '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
  truck: '<rect x="1" y="7" width="13" height="10" rx="1"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/>',
  gift: '<rect x="3" y="9" width="18" height="12" rx="1"/><path d="M3 13h18M12 9v12"/><path d="M12 9C9 9 8 7.5 8 6a2 2 0 0 1 4 0 2 2 0 0 1 4 0c0 1.5-1 3-4 3Z"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  sliders: '<path d="M4 6h10M18 6h2M4 18h2M10 18h10"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="18" r="2"/>',
  shield: '<path d="M12 3.5 4.5 6.2v5.3c0 4.6 3.1 8.4 7.5 9.8 4.4-1.4 7.5-5.2 7.5-9.8V6.2z"/><path d="m9 12 2 2 4-4"/>',
};

const NAV_SECTION = {
  'index.html': ['var(--gold)', 'home'],
  'orders.html': ['#2B6E8F', 'box'], 'shipments.html': ['#2B6E8F', 'truck'], 'cashiers.html': ['#2B6E8F', 'box'],
  'payments.html': ['#6E4E9E', 'wallet'], 'wallets.html': ['#6E4E9E', 'wallet'],
  'loyalty.html': ['#2E6B4F', 'gift'],
  'customer-dues.html': ['#6E4E9E', 'wallet'], 'invoices.html': ['#6E4E9E', 'wallet'],
  'support.html': ['#1F7A6C', 'chat'], 'reviews.html': ['#1F7A6C', 'chat'],
  'products.html': ['#2E7D5B', 'grid'], 'warehouses.html': ['#2E7D5B', 'grid'], 'inventory.html': ['#2E7D5B', 'grid'],
  'vendors.html': ['#B5773A', 'truck'], 'purchase-inward.html': ['#B5773A', 'truck'],
  'purchase-returns.html': ['#B5773A', 'truck'], 'pricing.html': ['#B5773A', 'chart'],
  'promotions.html': ['#C1670E', 'gift'], 'content.html': ['#C1670E', 'gift'], 'festivals.html': ['#C1670E', 'gift'],
  'bulk.html': ['#C1670E', 'gift'], 'customers.html': ['#C1670E', 'chat'], 'marketing.html': ['#C1670E', 'gift'],
  'barcode-generator.html': ['#C1670E', 'grid'],
  'reports.html': ['#5C6459', 'chart'], 'profit-loss.html': ['#5C6459', 'chart'], 'stock-audit.html': ['#5C6459', 'chart'],
  'settings.html': ['#52525B', 'sliders'], 'backups.html': ['#52525B', 'sliders'],
  'access-control.html': ['#7A1F3D', 'shield'],
};

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

/**
 * A bare shell with no sidebar and no links to the rest of the console —
 * just a thin top bar (brand, who's signed in, sign out) over the full-width
 * page content. Used by till.html so opening the till shows only the till,
 * not a click away from every other admin screen.
 */
export function renderMinimalChrome(user) {
  const shell = document.querySelector('[data-console]');

  shell.innerHTML = `
    <div class="min-vh-100 d-flex flex-column">
      <nav class="d-flex align-items-center justify-content-between px-3 py-2 console-sidebar flex-shrink-0 w-100">
        <div>${brandMarkup()}</div>
        <div class="d-flex align-items-center gap-3">
          <span class="small text-white-50">
            ${escapeHtml(user.full_name)} ·
            <span class="text-capitalize">${escapeHtml(String(user.role || '').replace('_', ' '))}</span>
          </span>
          <button class="btn btn-sm btn-outline-light" data-sign-out type="button">Sign out</button>
        </div>
      </nav>
      <main class="flex-grow-1 p-3 p-lg-4" data-page-root>
        <div class="text-center py-5 text-muted">
          <div class="spinner-border" role="status"><span class="visually-hidden">Loading</span></div>
        </div>
      </main>
    </div>`;

  shell.querySelector('[data-sign-out]').addEventListener('click', async () => {
    await signOut();
    sessionStorage.removeItem('till_session_user');
    window.location.reload();
  });
}

/** Renders the sidebar and header once the user is known to be staff. */
function renderChrome(activePage, user) {
  const shell = document.querySelector('[data-console]');

  shell.innerHTML = `
    <div class="d-flex flex-column flex-lg-row min-vh-100">
      <nav class="console-sidebar p-3 flex-shrink-0">
        <div class="d-flex align-items-center justify-content-between mb-lg-3">
          <div>${brandMarkup()}</div>
          <button class="btn btn-sm btn-outline-light d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#console-menu" aria-controls="console-menu" aria-expanded="false" aria-label="Toggle menu">☰ Menu</button>
        </div>
        <div class="collapse d-lg-block mt-3 mt-lg-0" id="console-menu">
        <ul class="nav flex-column gap-1">
          ${NAV.map(([href, label, hint]) => {
            // Reports carries a standing highlight rather than blending in with
            // the rest of the rail — it's meant to be found at a glance.
            const highlight = href === 'reports.html';
            const [tone, iconKey] = NAV_SECTION[href] || ['var(--gold)', 'grid'];
            return `
            <li class="nav-item">
              <a class="nav-link d-flex align-items-center gap-2 ${href === activePage ? 'active' : ''} ${highlight ? 'nav-link-highlight' : ''}" href="${href}">
                <div class="nav-icon-chip" style="--tone:${tone}">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${NAV_ICON[iconKey]}</svg>
                </div>
                <div class="flex-fill min-w-0">
                ${escapeHtml(label)}${highlight ? ' <span class="badge text-bg-warning" style="font-size:9px;vertical-align:middle">★</span>' : ''}
                <span class="d-block small opacity-75">${escapeHtml(hint)}</span>
                </div>
              </a>
            </li>`;
          }).join('')}
        </ul>
        <hr class="text-white-50">
        <div class="small text-white-50">
          Signed in as<br>
          <span class="text-white">${escapeHtml(user.full_name)}</span><br>
          <span class="text-capitalize">${escapeHtml(String(user.role || '').replace('_', ' '))}</span>
        </div>
        <button class="btn btn-sm btn-outline-light mt-3 w-100" data-sign-out type="button">Sign out</button>
        </div>
      </nav>
      <main class="flex-grow-1 p-3 p-lg-4" data-page-root>
        <div class="text-center py-5 text-muted">
          <div class="spinner-border" role="status"><span class="visually-hidden">Loading</span></div>
        </div>
      </main>
    </div>`;

  shell.querySelector('[data-sign-out]').addEventListener('click', async () => {
    await signOut();
    sessionStorage.removeItem('till_session_user');
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
 * @param {boolean} [minimal] true renders a bare, sidebar-free shell (see
 *   renderMinimalChrome) instead of the full console nav — for standalone
 *   panels like till.html that should not double as a way into every other
 *   admin screen.
 * @returns {Promise<{root: HTMLElement, user: object}|null>}
 */
export async function mountConsole(activePage, minimal = false) {
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

  // Kept in sync with every `role:` list any admin route actually accepts
  // (see routes/api_v1.php) — this check is courtesy only (the API is the
  // real gate), but a role missing here locks a legitimate staff member out
  // of the console before their token is even sent to an endpoint.
  const staffRoles = [
    'administrator', 'supervisor', 'executive', 'manager', 'inventory_staff', 'cashier',
    // super_admin used to sign in only at the separate Admin Privilege panel
    // (admin-privilege/), which had its own role list. That panel is gone —
    // its screens are the access-control.html tab below — so this is the one
    // role that needed adding here for that account to reach the console at
    // all. The other admin-privilege-only roles (inventory_executive,
    // purchase_executive, sales_executive, accountant) are unaffected: no
    // real account holds one yet, and giving them the ordinary /admin/*
    // screens they were never scoped for is a separate decision.
    'super_admin',
  ];

  if (!staffRoles.includes(String(user.role))) {
    clearTokens();
    renderSignIn('That account is not a staff account. Please use your staff sign-in.');
    return null;
  }

  // A till-only account has no access to any console page. This happens when a
  // cashier signs in at the Till in the same browser as an administrator (the
  // sign-in is shared) — say so, rather than letting every page fail with a 403.
  if (!minimal && String(user.role) === 'cashier') {
    document.querySelector('[data-console]').innerHTML = `
      <div class="d-flex align-items-center justify-content-center min-vh-100">
        <div class="card shadow-sm" style="width:min(28rem,92vw)">
          <div class="card-body p-4">
            <h1 class="h5 mb-2">You are signed in as a cashier</h1>
            <p class="text-muted small">${escapeHtml(user.full_name)} can only use the Till. If you are the
              administrator, sign out here and sign in again with your own login.</p>
            <div class="d-flex gap-2">
              <a class="btn btn-dark" href="till.html">Open the Till</a>
              <button class="btn btn-outline-secondary" type="button" data-cashier-signout>Sign out</button>
            </div>
          </div>
        </div>
      </div>`;

    document.querySelector('[data-cashier-signout]').addEventListener('click', async () => {
      await signOut();
      sessionStorage.removeItem('till_session_user');
      window.location.reload();
    });

    return null;
  }

  if (minimal) {
    renderMinimalChrome(user);
  } else {
    renderChrome(activePage, user);
  }

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

/**
 * The Dashboard's richer figure treatment (accent bar, icon chip, serif
 * number) — generalised so any screen can lead with a headline number the
 * same way instead of the plain statCard() above. `iconSvgPaths` is raw
 * inner SVG markup (just the <path>/<circle> elements) — this stays a pure
 * layout helper with no fixed icon registry, so each page owns its own icon
 * shapes rather than everything routing through one shared, growing list.
 *
 * `drillKey`, when passed, makes the card itself the trigger for a
 * drill-down (see page-index.js's openTodayDrilldownModal and
 * page-wallets.js's openWalletDrilldownModal for the two callers that use
 * this): it adds `data-drill-tile`, a pointer cursor and a "view details"
 * hint, and the caller wires the actual click handler after rendering.
 * Omitted (the default), the card renders exactly as it always has — every
 * other one of this helper's ~30 call sites is unaffected.
 */
export function iconStatCard({ tone = 'var(--gold)', iconSvgPaths, label, value, hint = '', drillKey = null }) {
  return `
    <div class="col">
      <div class="stat-card ${drillKey ? 'stat-card-clickable' : ''}" style="--tone:${escapeHtml(tone)}"
           ${drillKey ? `data-drill-tile="${escapeHtml(drillKey)}" role="button" tabindex="0"` : ''}>
        <div class="stat-card-top">
          <span class="eyebrow">${escapeHtml(label)}</span>
          <span class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">${iconSvgPaths}</svg>
          </span>
        </div>
        <div class="stat-value">${value}</div>
        ${hint ? `<div class="stat-hint">${hint}</div>` : ''}
        ${drillKey ? '<div class="stat-hint">Click for details</div>' : ''}
      </div>
    </div>`;
}

/** A small icon chip next to a card-header label — see .card-header-icon in console.css. */
export function headerIcon(tone, iconSvgPaths) {
  return `
    <span class="card-header-icon" style="--tone:${escapeHtml(tone)}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">${iconSvgPaths}</svg>
    </span>`;
}

/** Status colours, shared so a status never means two things. */
export const STATUS_TONE = {
  created: 'secondary',
  damage: 'warning',
  lost: 'danger',
  unpaid: 'danger',
  partial: 'warning',
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
  booked: 'primary',
  label_generated: 'primary',
  pickup_scheduled: 'info',
  picked_up: 'info',
  in_transit: 'info',
  failed_delivery: 'warning',
  rto_initiated: 'danger',
  rto_delivered: 'danger',
  lost: 'danger',
  requested: 'warning',
  completed: 'success',
  expiring_soon: 'warning',
  expired: 'danger',
  ok: 'secondary',
  invoice_short: 'danger',
  success: 'success',
  skipped: 'secondary',
  running: 'info',
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
