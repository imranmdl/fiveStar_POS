/**
 * API client for the 5Star React frontend.
 *
 * Ported from assets/js/api.js (the live vanilla-JS client used by the
 * current storefront) — same token storage, single-flight refresh, guest
 * cart handling and response envelope. Do not re-derive this logic; port
 * changes back from assets/js/api.js if that file changes.
 */

// '/api/v1' is proxied to the live backend in dev (see vite.config.js) and
// is correct as-is once this app is deployed on the same host as the API.
const API_BASE = (import.meta.env.VITE_API_BASE || '/api/v1').replace(/\/$/, '');

// The Till keeps its own sign-in, separate from the console's, so a cashier
// signing in at the till doesn't replace an administrator's login in the
// same browser (and vice versa).
const IS_TILL = /^\/till(\/|$)/.test(window.location.pathname);

const STORAGE = {
  access: IS_TILL ? 'spice.till.access_token' : 'spice.access_token',
  refresh: IS_TILL ? 'spice.till.refresh_token' : 'spice.refresh_token',
  cart: 'spice.cart_token',
};

/**
 * TOKEN STORAGE, AND ITS LIMITS.
 *
 * The access token lives in sessionStorage (tab-scoped, cleared on close) so
 * it survives a page navigation without spending a refresh on every load —
 * refresh tokens rotate, so browsing several pages would otherwise burn
 * several of the limited refreshes allowed per window. The refresh token
 * goes to localStorage to survive a reload and keep people signed in; it IS
 * readable by any script on this origin, a deliberate trade (see
 * CLIENT_INTEGRATION.md) whose mitigations are a strict CSP and never
 * interpolating untrusted content into HTML.
 */
let accessToken = null;

function restoreAccessToken() {
  try {
    return window.sessionStorage.getItem(STORAGE.access);
  } catch {
    // Private browsing can refuse sessionStorage entirely; memory-only still
    // works, just with more refreshes.
    return null;
  }
}

function persistAccessToken(token) {
  try {
    if (token) window.sessionStorage.setItem(STORAGE.access, token);
    else window.sessionStorage.removeItem(STORAGE.access);
  } catch {
    // Ignore; memory-only is a working fallback.
  }
}

accessToken = restoreAccessToken();

/** Promise held while a refresh is in flight, so concurrent 401s share one. */
let refreshInFlight = null;

/**
 * Incremented on every successful refresh, so a request that was sent before
 * a refresh completed can tell "my token just went stale" apart from "I
 * actually need a new refresh" and avoid rotating the token twice.
 */
let tokenGeneration = 0;

const listeners = { auth: [] };

export function onAuthChange(handler) {
  listeners.auth.push(handler);
}

function announceAuth() {
  listeners.auth.forEach((handler) => {
    try {
      handler(isSignedIn());
    } catch (error) {
      console.error('auth listener failed', error);
    }
  });
}

export function isSignedIn() {
  return Boolean(accessToken || localStorage.getItem(STORAGE.refresh));
}

export function storeTokens(tokens) {
  if (!tokens) return;
  accessToken = tokens.access_token || null;
  persistAccessToken(accessToken);

  if (tokens.refresh_token) {
    localStorage.setItem(STORAGE.refresh, tokens.refresh_token);
  }

  announceAuth();
}

export function clearTokens() {
  accessToken = null;
  persistAccessToken(null);
  localStorage.removeItem(STORAGE.refresh);
  announceAuth();
}

/** An error carrying the server's envelope, so callers can show field errors. */
export class ApiError extends Error {
  constructor(status, body) {
    super((body && body.message) || 'Something went wrong.');
    this.name = 'ApiError';
    this.status = status;
    this.errors = (body && body.errors) || {};
    this.body = body || {};
  }

  /** Every field message, flattened, for a form-level summary. */
  fieldMessages() {
    if (Array.isArray(this.errors)) return [];
    return Object.values(this.errors).flat();
  }
}

/**
 * Exchanges the refresh token, exactly once however many callers are waiting.
 *
 * Refresh tokens rotate on use. If two requests each notice a 401 and each
 * POST the same refresh token, the server treats the second as theft and
 * revokes every session for that user — so every caller must share one
 * in-flight exchange rather than firing their own.
 */
async function refreshTokens() {
  if (refreshInFlight) return refreshInFlight;

  const refreshToken = localStorage.getItem(STORAGE.refresh);
  if (!refreshToken) {
    clearTokens();
    return Promise.reject(new ApiError(401, { message: 'Please sign in again.' }));
  }

  refreshInFlight = (async () => {
    // The stored access token may simply have expired. Clear it first so a
    // retry never sends the stale value.
    accessToken = null;
    persistAccessToken(null);

    const response = await fetch(`${API_BASE}/auth/token/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
      clearTokens();
      throw new ApiError(response.status, body);
    }

    // Persist before any waiting request retries — if the tab closes between
    // refreshing and saving, the customer would be signed out for nothing.
    storeTokens(body.data && body.data.tokens);
    tokenGeneration += 1;
    return accessToken;
  })().finally(() => {
    refreshInFlight = null;
  });

  return refreshInFlight;
}

/**
 * Performs a request against the API.
 *
 * @param {string} path      e.g. '/cart'
 * @param {object} options   method, body, query, retryOnAuthFailure
 */
export async function request(path, options = {}) {
  const {
    method = 'GET',
    body = null,
    query = null,
    retryOnAuthFailure = true,
  } = options;

  // Wait for the session before any request goes out, so a component that
  // queries the API on first render can never get an anonymous response for
  // a signed-in visitor just because the refresh hadn't resolved yet.
  if (!accessToken && localStorage.getItem(STORAGE.refresh) && path !== '/auth/token/refresh') {
    await bootstrapSession();
  }

  let url = `${API_BASE}${path}`;

  if (query) {
    const params = new URLSearchParams(
      Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
    if (params.toString()) url += `?${params}`;
  }

  // Noted before the request goes out, so a 401 that arrives after someone
  // else already refreshed can be told apart from one that needs a refresh.
  const sentUnderGeneration = tokenGeneration;

  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;

  // Guest carts are addressed by a token the server issues. Sent on every
  // request so an anonymous visitor keeps the same cart across pages.
  const cartToken = localStorage.getItem(STORAGE.cart);
  if (cartToken) headers['X-Cart-Token'] = cartToken;

  const response = await fetch(url, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const payload = await response.json().catch(() => ({}));

  if (response.status === 401 && retryOnAuthFailure && localStorage.getItem(STORAGE.refresh)) {
    if (tokenGeneration === sentUnderGeneration) {
      try {
        await refreshTokens();
      } catch {
        clearTokens();
        throw new ApiError(401, payload);
      }
    }

    // One retry only — a 401 after a successful refresh means the session is
    // genuinely finished, and looping would hammer the endpoint.
    return request(path, { ...options, retryOnAuthFailure: false });
  }

  if (!response.ok) {
    throw new ApiError(response.status, payload);
  }

  // The server hands a guest their cart token once; keep it.
  const issued = payload.data && payload.data.cart && payload.data.cart.guest_token;
  if (issued) localStorage.setItem(STORAGE.cart, issued);

  return payload;
}

/**
 * Uploads a file as multipart/form-data. Separate from `request` because the
 * Content-Type header must NOT be set by hand — the browser adds it along
 * with the multipart boundary, and setting it manually produces a request
 * the server cannot parse.
 */
export async function upload(path, formData) {
  const headers = { Accept: 'application/json' };
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;

  const send = () => fetch(`${API_BASE}${path}`, { method: 'POST', headers, body: formData });

  let response = await send();

  if (response.status === 401 && localStorage.getItem(STORAGE.refresh)) {
    await refreshTokens();
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
    response = await send();
  }

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) throw new ApiError(response.status, payload);

  return payload;
}

/**
 * Fetches a non-JSON response (a file) with the same auth/refresh handling
 * as `request()`, which always calls `response.json()` and can't be reused
 * here. Returns the raw Blob; the caller turns it into a download via a
 * temporary object URL.
 */
export async function downloadFile(path) {
  const headers = {};
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;

  const send = () => fetch(`${API_BASE}${path}`, { headers });

  let response = await send();

  if (response.status === 401 && localStorage.getItem(STORAGE.refresh)) {
    await refreshTokens();
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
    response = await send();
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throw new ApiError(response.status, payload);
  }

  return response.blob();
}

export const api = {
  get: (path, query) => request(path, { method: 'GET', query }),
  post: (path, body) => request(path, { method: 'POST', body }),
  put: (path, body) => request(path, { method: 'PUT', body }),
  patch: (path, body) => request(path, { method: 'PATCH', body }),
  delete: (path, body) => request(path, { method: 'DELETE', body }),
  upload,
  downloadFile,
};

/**
 * Signs out and forgets the guest cart token too — leaving it behind would
 * hand the next person on a shared machine the previous customer's cart.
 */
export async function signOut() {
  try {
    await api.post('/auth/logout', {});
  } catch {
    // A failed logout still clears the client; refusing to sign out because
    // the network is down is not a defensible answer.
  }
  localStorage.removeItem(STORAGE.cart);
  clearTokens();
}

/**
 * Money, formatted the Indian way. The API returns rupees as a decimal
 * number; arithmetic happens server-side, so there's no addition here.
 */
export function formatMoney(amount) {
  const value = Number(amount || 0);
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 2,
  }).format(value);
}

/**
 * Hands the guest cart to the account that just signed in. Without this the
 * cart is silently lost: a visitor builds a cart anonymously, signs in, and
 * their account cart is empty while the guest cart sits orphaned in the
 * database. The server merges idempotently, so calling it twice is harmless.
 */
let mergeAttempt = null;

export async function mergeGuestCart() {
  if (mergeAttempt) return mergeAttempt;

  mergeAttempt = (async () => {
    const merged = await performMerge();
    if (!merged) mergeAttempt = null;
    return merged;
  })();

  return mergeAttempt;
}

async function performMerge() {
  const token = localStorage.getItem(STORAGE.cart);
  if (!token || !accessToken) return false;

  try {
    await api.post('/cart/merge', { cart_token: token });
    localStorage.removeItem(STORAGE.cart);
    return true;
  } catch {
    // A failed merge must not block sign-in; the guest token is kept so a
    // later attempt can still recover the items.
    return false;
  }
}

/**
 * One session restore per page load, which every request waits for — the
 * fix for a page querying the API before the access token was recovered
 * from the refresh token, which used to render as briefly-anonymous.
 */
let sessionRestore = null;

export function bootstrapSession() {
  if (sessionRestore) return sessionRestore;

  sessionRestore = (async () => {
    if (accessToken) return true;
    if (!localStorage.getItem(STORAGE.refresh)) return false;

    try {
      await refreshTokens();
      return true;
    } catch {
      return false;
    }
  })();

  return sessionRestore;
}

export { API_BASE, STORAGE };
