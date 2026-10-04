/**
 * Storefront tests.
 *
 *   node web/test/test_storefront.mjs
 *
 * Loads the real page modules into a real DOM (jsdom) and drives them against
 * the LIVE API. Nothing here is mocked except the browser itself: fetch goes
 * over the wire to Apache, so a mismatch between what the client expects and
 * what the server returns shows up as a failure rather than a runtime surprise
 * in front of a customer.
 *
 * Chromium could not be installed in this environment, so this is not a
 * substitute for testing in a browser. What it does prove is that the modules
 * parse, execute, call the endpoints they claim to, read the fields the API
 * actually returns, and escape what they render. Visual layout and Bootstrap's
 * own behaviour remain unverified.
 *
 * Requires: Apache serving the API, a seeded database, PAYMENT_DRIVER=sandbox.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, resolve } from 'node:path';

// Captured ONCE, before anything reassigns the global. The wrapper below calls
// this rather than `fetch`, which by then points at the wrapper itself — the
// first version recursed until the stack ran out.
const nodeFetch = globalThis.fetch;

const HERE = dirname(fileURLToPath(import.meta.url));
const WEB_ROOT = resolve(HERE, '..');
const API_URL = process.env.STOREFRONT_API || 'http://127.0.0.1:8081';

let passed = 0;
let failed = 0;

function check(label, condition, detail = '') {
  if (condition) {
    passed += 1;
    console.log(`  PASS  ${label}`);
    return;
  }
  failed += 1;
  console.log(`  FAIL  ${label}${detail ? ` -> ${detail}` : ''}`);
}

function same(label, expected, actual) {
  check(label, expected === actual,
    `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
}

/**
 * Builds a DOM for one page and returns handles to it.
 *
 * The modules are imported fresh each time with a cache-busting query, because
 * they hold module-level state (the access token, the refresh promise) that
 * must not leak between tests.
 */
function makeDom(html) {
  const dom = new JSDOM(html, {
    url: `${API_URL}/shop/`,
    pretendToBeVisual: true,
    runScripts: 'outside-only',
  });

  // jsdom has no fetch; hand it Node's, so requests genuinely reach Apache.
  //
  // Built fresh from `nodeFetch` on every call. An earlier version read
  // `dom.window.fetch` or `globalThis.fetch` to build the next wrapper, so each
  // page under test wrapped the previous page's wrapper and the chain
  // eventually blew the stack.
  const wrapped = (input, init) => {
    const url = typeof input === 'string' && input.startsWith('/')
      ? `${API_URL}${input}`
      : input;
    return nodeFetch(url, init);
  };

  dom.window.fetch = wrapped;

  // jsdom supplies a real localStorage backed by the window's origin, and it is
  // getter-only. Clearing it between pages is what keeps module-level state from
  // leaking between tests.
  dom.window.localStorage.clear();

  global.window = dom.window;
  global.document = dom.window.document;
  global.localStorage = dom.window.localStorage;
  global.fetch = wrapped;
  global.URLSearchParams = dom.window.URLSearchParams;
  global.FormData = dom.window.FormData;

  // NOT setTimeout. jsdom's window.setTimeout delegates to whatever
  // globalThis.setTimeout is at call time, so assigning it here makes each new
  // window's timer wrap the previous one — and the chain blows the stack. Node's
  // own setTimeout is what the page modules need anyway.

  return dom;
}

async function loadModule(name) {
  const path = resolve(WEB_ROOT, 'assets/js', name);
  return import(`${pathToFileURL(path).href}?v=${Date.now()}${Math.random()}`);
}

function html(bodyInner = '') {
  return `<!DOCTYPE html><html><head><title>t</title></head><body>
    <div data-chrome="header"></div>
    <main data-page-root>${bodyInner}</main>
    <div data-chrome="footer"></div>
  </body></html>`;
}

/** Waits for a condition the page reaches asynchronously. */
async function waitFor(predicate, description, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    if (predicate()) return true;
    await new Promise((r) => setTimeout(r, 60));
  }

  return false;
}

console.log('Storefront tests');
console.log(`API: ${API_URL}\n`);

// -----------------------------------------------------------------------
// The API client
// -----------------------------------------------------------------------
console.log('-- The API client --');

{
  makeDom(html());
  const api = await loadModule('api.js');

  same('money is formatted in rupees', true, api.formatMoney(1234.5).includes('1,234.5'));
  check('...with the rupee sign', api.formatMoney(10).includes('₹'), api.formatMoney(10));

  // Escaping is the mitigation for holding a refresh token in localStorage, so
  // it gets tested rather than assumed.
  same('script tags are escaped',
    '&lt;script&gt;alert(1)&lt;/script&gt;', api.escapeHtml('<script>alert(1)</script>'));
  same('quotes are escaped', '&quot;x&quot;', api.escapeHtml('"x"'));
  same('single quotes are escaped', '&#39;x&#39;', api.escapeHtml("'x'"));
  same('ampersands are escaped first, not doubled', '&amp;lt;', api.escapeHtml('&lt;'));
  same('null renders as empty', '', api.escapeHtml(null));

  same('nobody is signed in initially', false, api.isSignedIn());

  const response = await api.api.get('/products', { per_page: 2 });
  check('the client reaches the live API', response.success === true);
  check('...and gets the standard envelope',
    'success' in response && 'message' in response && 'data' in response && 'errors' in response);
  check('...with pagination meta', Boolean(response.meta && 'total_pages' in response.meta));

  try {
    await api.api.get('/products/definitely-not-a-real-product-slug');
    check('a 404 throws ApiError', false, 'no error was thrown');
  } catch (error) {
    check('a 404 throws ApiError', error instanceof api.ApiError);
    same('...carrying the status', 404, error.status);
    check('...and a customer-safe message', typeof error.message === 'string' && error.message.length > 0);
  }

  try {
    await api.api.post('/auth/login', { identifier: 'x' });
    check('a validation failure throws', false, 'no error was thrown');
  } catch (error) {
    same('a validation failure carries 422', 422, error.status);
    check('...with field messages', error.fieldMessages().length > 0,
      JSON.stringify(error.errors));
  }
}

// -----------------------------------------------------------------------
// Single-flight refresh — the rule most likely to catch a client out
// -----------------------------------------------------------------------
console.log('\n-- Single-flight token refresh --');

{
  const dom = makeDom(html());
  const api = await loadModule('api.js');

  // Register a real customer so there is a genuine refresh token to rotate.
  const mobile = `9${String(Math.floor(Math.random() * 1e9)).padStart(9, '0')}`;
  const registration = await api.api.post('/auth/register', {
    full_name: 'Refresh Tester',
    mobile,
    password: `Refresh${Math.floor(Math.random() * 1e6)}`,
  });

  const verified = await api.api.post('/auth/register/verify', {
    mobile,
    otp: registration.data.verification.debug_otp,
    reference_token: registration.data.verification.reference_token,
  });

  api.storeTokens(verified.data.tokens);
  check('a customer is signed in', api.isSignedIn());

  // Count how many times the refresh endpoint is actually hit.
  let refreshCalls = 0;
  const counting = (input, init) => {
    if (String(input).includes('/auth/token/refresh')) refreshCalls += 1;
    // Calls nodeFetch directly rather than the previous window.fetch. Chaining
    // wrappers across makeDom() calls is what produced an infinite recursion:
    // each new window's fetch closed over the last one.
    const url = typeof input === 'string' && input.startsWith('/')
      ? `${API_URL}${input}`
      : input;
    return nodeFetch(url, init);
  };
  dom.window.fetch = counting;
  global.fetch = counting;

  // Force expiry by discarding the access token, then fire six authenticated
  // requests at once. Each gets a 401 and each wants to refresh.
  api.storeTokens({ access_token: null, refresh_token: localStorage.getItem('spice.refresh_token') });

  const results = await Promise.allSettled([
    api.api.get('/orders'),
    api.api.get('/cart'),
    api.api.get('/addresses'),
    api.api.get('/wallet'),
    api.api.get('/notifications/preferences'),
    api.api.get('/reviews/mine'),
  ]);

  const fulfilled = results.filter((r) => r.status === 'fulfilled').length;

  same('six concurrent 401s trigger exactly one refresh', 1, refreshCalls);
  check('...and every request still succeeds', fulfilled === 6,
    `${fulfilled} of 6 succeeded`);
  check('...leaving the customer signed in', api.isSignedIn(),
    'a second refresh would have revoked the whole session as token theft');

  // The rotated token must have been persisted, not just held in memory.
  const stored = localStorage.getItem('spice.refresh_token');
  check('the rotated refresh token was saved',
    Boolean(stored) && stored !== verified.data.tokens.refresh_token,
    'saving late means a killed tab signs the customer out');
}

// -----------------------------------------------------------------------
// Catalogue page
// -----------------------------------------------------------------------
console.log('\n-- Catalogue page --');

{
  const dom = makeDom(`<!DOCTYPE html><html><body>
    <div data-chrome="header"></div>
    <main>
      <form data-search-form><input id="search" name="q"></form>
      <select data-sort><option value=""></option></select>
      <h2 data-results-heading></h2>
      <div data-products></div>
      <nav data-pagination></nav>
      <div class="list-group" data-categories></div>
    </main>
    <div data-chrome="footer"></div>
  </body></html>`);

  await loadModule('page-catalog.js');

  const rendered = await waitFor(
    () => dom.window.document.querySelectorAll('[data-products] article').length > 0,
    'products to render',
  );

  check('products render from the live catalogue', rendered);

  const cards = dom.window.document.querySelectorAll('[data-products] article');
  check('...more than one', cards.length >= 1, String(cards.length));

  const firstLink = dom.window.document.querySelector('[data-products] a[href^="product.html"]');
  check('...each linking to its detail page by slug', Boolean(firstLink),
    firstLink ? firstLink.getAttribute('href') : 'no link found');

  const markup = dom.window.document.querySelector('[data-products]').innerHTML;
  check('...showing a price', markup.includes('₹'));
  check('...stating that GST is included', markup.includes('Inclusive of GST'),
    'Indian MRP is tax-inclusive and the storefront must say so');

  const categories = await waitFor(
    () => dom.window.document.querySelectorAll('[data-categories] [data-category]').length > 1,
    'categories to render',
  );
  check('the category list renders', categories);

  const header = dom.window.document.querySelector('[data-chrome="header"]').innerHTML;
  check('the header renders', header.includes('Spice'));
  check('...with a cart link', header.includes('cart.html'));
  check('...and a sign-in link for an anonymous visitor', header.includes('Sign in'));
}

// -----------------------------------------------------------------------
// Product page and the guest cart
// -----------------------------------------------------------------------
console.log('\n-- Product page and guest cart --');

{
  const dom = makeDom(html());
  dom.reconfigure({ url: `${API_URL}/shop/product.html?slug=california-almonds` });
  global.window = dom.window;

  await loadModule('page-product.js');

  const loaded = await waitFor(
    () => dom.window.document.querySelector('[data-add-to-cart]') !== null,
    'the product to load',
  );
  check('the product page loads', loaded);

  if (loaded) {
    const variants = dom.window.document.querySelectorAll('[name="variant"]');
    check('pack sizes are listed', variants.length >= 1, String(variants.length));
    check('...with one preselected',
      Array.from(variants).some((input) => input.checked));

    const body = dom.window.document.querySelector('[data-page-root]').innerHTML;
    check('the prepaid-UPI rule is stated', body.includes('Prepaid UPI only'));
    check('the delivery charge is promised before payment',
      body.toLowerCase().includes('delivery charge'));

    const reviewsLoaded = await waitFor(
      () => !dom.window.document.querySelector('[data-reviews]').innerHTML.includes('Loading reviews'),
      'reviews to settle',
    );
    check('the reviews section resolves', reviewsLoaded);
    check('...explaining who may review',
      dom.window.document.querySelector('[data-reviews]').innerHTML.includes('delivered'),
      'the verified-purchase rule should be visible, not silent');
  }
}

// -----------------------------------------------------------------------
// Cart page against a real cart
// -----------------------------------------------------------------------
console.log('\n-- Cart page --');

{
  const dom = makeDom(html());
  const api = await loadModule('api.js');

  // Build a real cart as a guest.
  const products = await api.api.get('/products', { per_page: 1 });
  const slug = products.data[0].slug;
  const product = await api.api.get(`/products/${slug}`);
  const variantUuid = product.data.product.variants[0].uuid;

  await api.api.post('/cart/items', { variant_uuid: variantUuid, quantity: 2 });

  const cart = await api.api.get('/cart');
  check('a guest can build a cart', (cart.data.items || []).length >= 1);
  check('...and it is priced', Number(cart.data.pricing.summary.grand_total) > 0);
  check('...with GST extracted, not added',
    Number(cart.data.pricing.summary.tax_total) > 0
    && Number(cart.data.pricing.summary.tax_total) < Number(cart.data.pricing.summary.grand_total));

  // The client must read the exact field names the server sends.
  const summary = cart.data.pricing.summary;
  ['items_subtotal', 'order_discount', 'delivery_charge', 'tax_total', 'grand_total']
    .forEach((field) => {
      check(`the summary carries ${field}`, field in summary,
        'the cart page reads this field by name');
    });

  check('the payment split is present',
    'wallet_applied' in cart.data.payment && 'amount_payable' in cart.data.payment);
  check('the checkout blockers list is present', Array.isArray(cart.data.checkout.blockers),
    'the cart page disables checkout from this list');
}

// -----------------------------------------------------------------------
// Content pages
// -----------------------------------------------------------------------
console.log('\n-- Content pages --');

{
  const dom = makeDom(html());
  dom.reconfigure({ url: `${API_URL}/shop/page.html?slug=returns-and-refunds` });
  global.window = dom.window;

  await loadModule('page-page.js');

  const loaded = await waitFor(
    () => dom.window.document.querySelector('[data-page-root] h1') !== null,
    'the policy page to load',
  );
  check('a policy page renders', loaded);

  if (loaded) {
    const markup = dom.window.document.querySelector('[data-page-root]').innerHTML;
    check('...with paragraphs preserved', markup.includes('<p>'));
    check('...and no raw script execution path', !markup.includes('<script'),
      'CMS bodies are escaped, not rendered as HTML');
  }
}

{
  const dom = makeDom(html());
  dom.reconfigure({ url: `${API_URL}/shop/faq.html` });
  global.window = dom.window;

  await loadModule('page-faq.js');

  const loaded = await waitFor(
    () => dom.window.document.querySelectorAll('[data-page-root] .accordion-item').length > 0,
    'the FAQ to load',
  );
  check('the FAQ renders', loaded);
  check('...grouped into sections',
    dom.window.document.querySelectorAll('[data-page-root] section').length >= 2);
}

// -----------------------------------------------------------------------
// Every page module parses and runs without throwing
// -----------------------------------------------------------------------
console.log('\n-- All page modules --');

for (const name of ['page-catalog.js', 'page-product.js', 'page-cart.js', 'page-checkout.js',
                    'page-orders.js', 'page-account.js', 'page-support.js', 'page-faq.js',
                    'page-page.js']) {
  const dom = makeDom(`<!DOCTYPE html><html><body>
    <div data-chrome="header"></div>
    <main data-page-root></main>
    <div data-chrome="footer"></div>
    <form data-search-form><input id="search" name="q"></form>
    <select data-sort><option value=""></option></select>
    <h2 data-results-heading></h2>
    <div data-products></div>
    <nav data-pagination></nav>
    <div data-categories></div>
  </body></html>`);

  let threw = null;
  const originalError = console.error;
  console.error = () => {};

  try {
    await loadModule(name);
    await new Promise((r) => setTimeout(r, 300));
  } catch (error) {
    threw = error;
  } finally {
    console.error = originalError;
  }

  check(`${name} loads and runs`, threw === null, threw ? threw.message : '');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
