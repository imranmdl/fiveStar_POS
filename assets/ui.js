/**
 * Shared UI helpers: layout chrome, toasts, and the loading and error states
 * that every page needs.
 *
 * Kept deliberately small. A storefront that needs a framework to render a
 * product list will need a build step, and a build step puts future edits out
 * of the merchant's reach.
 */

import { api, isSignedIn, onAuthChange, signOut, escapeHtml, formatMoney,
         bootstrapSession, mergeGuestCart } from './api.js';

/** Renders the header, and keeps the cart count and sign-in state current. */
export async function mountChrome(activePage) {
  const header = document.querySelector('[data-chrome="header"]');
  if (!header) return;

  // RESTORE THE SESSION FIRST.
  //
  // The cart badge is filled from GET /cart. Called before the access token has
  // been recovered from the refresh token, that request goes out unauthenticated
  // and returns the GUEST cart — so the header showed items while the page
  // itself, which had by then signed in, correctly showed the account's empty
  // cart. Two numbers, both honestly reported, describing different carts.
  await bootstrapSession();

  // And if a guest cart is still hanging around after signing in, hand it over
  // rather than leaving it orphaned.
  if (isSignedIn()) await mergeGuestCart();

  const nav = [
    ['index.html', 'Shop'],
    ['orders.html', 'My orders'],
    ['support.html', 'Support'],
  ];

  header.innerHTML = `
    <header class="site-header">
      <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark px-0 py-2">
          <a class="navbar-brand me-4" href="index.html">Spice <span>&amp;</span> Dry Fruits</a>

          <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse"
                  data-bs-target="#primary-nav" aria-controls="primary-nav"
                  aria-expanded="false" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
          </button>

          <!-- Search lives in the header on every page. It is how people find a
               spice; burying it on the home page makes them navigate instead. -->
          <form class="header-search d-none d-lg-block flex-grow-1 me-3" data-header-search
                style="max-width:30rem" role="search">
            <label class="visually-hidden" for="header-q">Search products</label>
            <input class="form-control form-control-sm" id="header-q" name="q" type="search"
                   placeholder="Search for haldi, cardamom, almonds…" autocomplete="off">
          </form>

          <div class="collapse navbar-collapse flex-grow-0" id="primary-nav">
            <ul class="navbar-nav me-3">
              ${nav.map(([href, label]) => `
                <li class="nav-item">
                  <a class="nav-link px-2 ${href === activePage ? 'active' : ''}" href="${href}">${label}</a>
                </li>`).join('')}
            </ul>

            <form class="header-search d-lg-none my-2" data-header-search-mobile role="search">
              <input class="form-control form-control-sm" name="q" type="search"
                     placeholder="Search products…" autocomplete="off">
            </form>

            <div class="d-flex align-items-center gap-2">
              <a class="cart-pill text-decoration-none d-inline-flex align-items-center gap-2" href="cart.html">
                Cart
                <span class="badge rounded-pill d-none" data-chrome="cart-count">0</span>
              </a>
              <a class="btn btn-marigold btn-sm ${isSignedIn() ? 'd-none' : ''}"
                 data-chrome="sign-in" href="account.html">Sign in</a>
              <button class="btn btn-quiet btn-sm text-white border-light ${isSignedIn() ? '' : 'd-none'}"
                      data-chrome="sign-out" type="button">Sign out</button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <!-- Categories as a scrollable rail. On a phone this is how a grocery app
         is browsed; a dropdown hides the range behind a tap. -->
    <div class="category-rail d-print-none">
      <div class="container">
        <div class="d-flex gap-2 py-2" data-category-rail></div>
      </div>
    </div>`;

  // Both search forms go to the catalogue, so search works from any page.
  header.querySelectorAll('[data-header-search], [data-header-search-mobile]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const term = new FormData(form).get('q');
      window.location.href = `index.html?q=${encodeURIComponent(String(term || ''))}`;
    });
  });

  loadCategoryRail();

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

/**
 * Fills the category rail.
 *
 * Top-level categories only. The seed has a two-level tree, and showing both
 * levels in one strip turns navigation into a wall of twenty chips — the point
 * of the rail is that the whole range is visible at a glance.
 */
async function loadCategoryRail() {
  const rail = document.querySelector('[data-category-rail]');
  if (!rail) return;

  const current = new URLSearchParams(window.location.search).get('category') || '';

  try {
    const response = await api.get('/categories');
    const payload = response.data.categories || response.data || [];

    rail.innerHTML = [
      `<a class="category-chip ${current ? '' : 'active'}" href="index.html">Everything</a>`,
      ...payload.map((category) => `
        <a class="category-chip ${current === category.slug ? 'active' : ''}"
           href="index.html?category=${encodeURIComponent(category.slug)}">
          ${escapeHtml(category.name)}
        </a>`),
    ].join('');
  } catch {
    rail.closest('.category-rail').classList.add('d-none');
  }
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
    <footer class="mt-5 py-4">
      <div class="container">
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center small">
          <div class="d-flex flex-wrap gap-3">
            <a class="link-secondary text-decoration-none" href="page.html?slug=shipping-policy">Shipping</a>
            <a class="link-secondary text-decoration-none" href="page.html?slug=returns-and-refunds">Returns</a>
            <a class="link-secondary text-decoration-none" href="page.html?slug=privacy-policy">Privacy</a>
            <a class="link-secondary text-decoration-none" href="page.html?slug=terms-of-service">Terms</a>
            <a class="link-secondary text-decoration-none" href="faq.html">FAQ</a>
          </div>
          <div class="text-muted">Prepaid UPI only · All prices include GST</div>
        </div>
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
  // A request that never landed needs different words from one the server
  // refused. Showing "Failed to fetch" to a customer is meaningless.
  if (isConnectionFailure(error)) {
    showConnectionBanner(error);

    if (container) {
      container.innerHTML = `
        <div class="alert alert-warning" role="alert">
          We could not load this just now. Please check your connection and try again.
        </div>`;
    }

    return;
  }

  const messages = error.fieldMessages ? error.fieldMessages() : [];

  if (container) {
    container.innerHTML = `
      <div class="alert alert-danger" role="alert">
        <div>${escapeHtml(error.message)}</div>
        ${messages.length ? `<ul class="mb-0 mt-2 small">${messages.map((m) => `<li>${escapeHtml(m)}</li>`).join('')}</ul>` : ''}
      </div>`;
    // Guarded: not every element has it (detached nodes, older browsers), and an
    // error handler that throws replaces a useful message with a blank page.
    if (typeof container.scrollIntoView === 'function') {
      container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
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

/**
 * Shows a page-wide banner when the API cannot be reached at all.
 *
 * A network failure is not the same as an API error. An API error arrives with
 * a status and a message the server wrote to be shown; a network failure means
 * the request never landed — wrong address, backend not running, CORS refused —
 * and `fetch` rejects with a TypeError carrying nothing useful.
 *
 * Without this the page sits on "Loading…" indefinitely, which tells the person
 * looking at it precisely nothing. Found because a real browser showed exactly
 * that.
 */
export function showConnectionBanner(error) {
  if (document.querySelector('[data-connection-banner]')) return;

  const base = (window.SPICE_API_BASE || '/api/v1');

  const banner = document.createElement('div');
  banner.setAttribute('data-connection-banner', '');
  banner.className = 'alert alert-danger rounded-0 mb-0';
  banner.innerHTML = `
    <div class="container">
      <div class="fw-semibold">Cannot reach the shop right now.</div>
      <div class="small">
        The storefront could not contact the API at
        <code>${escapeHtml(base)}</code>.
        If you are setting this up, check that the backend is running and that
        <code>assets/js/config.js</code> points at it.
      </div>
    </div>`;

  document.body.prepend(banner);
}

/**
 * True when a failure means the request never reached the server.
 *
 * `fetch` rejects with a TypeError for DNS failures, refused connections and
 * blocked CORS alike; the browser deliberately withholds the detail. Anything
 * carrying a status came back from the server and is a different problem.
 */
export function isConnectionFailure(error) {
  return !error || typeof error.status !== 'number';
}

export { escapeHtml, formatMoney };
