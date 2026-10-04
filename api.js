/**
 * API client for the Spice & Dry Fruits storefront.
 *
 * This is the reference implementation of docs/CLIENT_INTEGRATION.md. Anything
 * that guide says a client must do, this file does — most importantly
 * single-flight token refresh, which is the rule most likely to catch a client
 * out and the one whose failure signs customers out of everything.
 *
 * No build step. The merchant deploys this to shared hosting by copying files;
 * requiring npm and a bundler to change a button colour would put every future
 * edit out of their reach.
 */
window.SPICE_API_BASE = 'https://5star.alimstech.com/spice-api/backend/public/api/v1';

const API_BASE = (window.SPICE_API_BASE || '/api/v1').replace(/\/$/, '');

const STORAGE = {
  access: 'spice.access_token',
  refresh: 'spice.refresh_token',
  cart: 'spice.cart_token',
};

/**
 * TOKEN STORAGE, AND ITS LIMITS.
 *
 * The access token lives in memory only, so it disappears when the tab closes
 * and never sits in storage where a cross-site script could read it at leisure.
 * The refresh token has to survive a reload to keep people signed in, so it goes
 * to localStorage.
 *
 * That refresh token IS readable by any script running on this origin. If the
 * storefront ever executes untrusted JavaScript, it is exposed. The alternative
 * — an httpOnly cookie — needs a server-side session bridge, which would put
 * authentication logic back into the web tier and break the API-first rule this
 * platform is built on. The trade is deliberate rather than accidental, and the
 * mitigations that matter are a strict Content-Security-Policy and never
 * interpolating user content into HTML (see escapeHtml below).
 */
let accessToken = null;

/** Promise held while a refresh is in flight, so concurrent 401s share one. */
let refreshInFlight = null;

/**
 * Incremented on every successful refresh.
 *
 * Sharing one in-flight promise is not sufficient on its own. Six requests fired
 * together do not receive their 401s at the same instant: the first triggers a
 * refresh, that refresh completes and clears `refreshInFlight`, and the later
 * 401s then find no refresh in progress and start another one. The token they
 * would send is the new one, so the server does not treat it as theft — but it
 * rotates again needlessly, and with enough concurrency the window for two
 * requests to send the SAME token is real.
 *
 * Recording a generation closes it. A request notes the generation it was sent
 * under; if that number has moved by the time its 401 arrives, a refresh has
 * already happened and the request simply retries with the fresh token.
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
  if (tokens.refresh_token) {
    localStorage.setItem(STORAGE.refresh, tokens.refresh_token);
  }
  announceAuth();
}

export function clearTokens() {
  accessToken = null;
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
 * SINGLE FLIGHT IS NOT OPTIONAL. Refresh tokens rotate: using one invalidates
 * it. If two requests each notice a 401 and each POST the same refresh token,
 * the second presents a token the first has already spent, and the server treats
 * that as a stolen token and revokes every session — signing the customer out of
 * everything for no reason but a race in the client.
 */
async function refreshTokens() {
  if (refreshInFlight) return refreshInFlight;

  const refreshToken = localStorage.getItem(STORAGE.refresh);
  if (!refreshToken) {
    clearTokens();
    return Promise.reject(new ApiError(401, { message: 'Please sign in again.' }));
  }

  refreshInFlight = (async () => {
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

    // Persist BEFORE any waiting request retries. If the tab is closed between
    // refreshing and saving, the customer is signed out for nothing.
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
 * @param {object} options   method, body, auth, query
 */
export async function request(path, options = {}) {
  const {
    method = 'GET',
    body = null,
    query = null,
    retryOnAuthFailure = true,
  } = options;

  let url = `${API_BASE}${path}`;

  if (query) {
    const params = new URLSearchParams(
      Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
    if (params.toString()) url += `?${params}`;
  }

  // Noted before the request goes out, so a 401 that arrives after someone else
  // has already refreshed can be told apart from one that needs a refresh.
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
    // Only refresh if nobody else already has. If the generation has moved, this
    // request simply went out with a token that was stale by the time it landed,
    // and retrying with the current one is all that is needed.
    if (tokenGeneration === sentUnderGeneration) {
      try {
        await refreshTokens();
      } catch {
        clearTokens();
        throw new ApiError(401, payload);
      }
    }

    // One retry only. A 401 after a successful refresh means the session is
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

export const api = {
  get: (path, query) => request(path, { method: 'GET', query }),
  post: (path, body) => request(path, { method: 'POST', body }),
  patch: (path, body) => request(path, { method: 'PATCH', body }),
  delete: (path) => request(path, { method: 'DELETE' }),
};

/**
 * Signs out and forgets the guest cart token too.
 *
 * Leaving it behind would hand the next person on a shared machine the previous
 * customer's cart.
 */
export async function signOut() {
  try {
    await api.post('/auth/logout', {});
  } catch {
    // A failed logout still clears the client. The tokens are useless to us
    // either way, and refusing to sign out because the network is down is not
    // a defensible answer.
  }
  localStorage.removeItem(STORAGE.cart);
  clearTokens();
}

/**
 * Money, formatted the Indian way.
 *
 * The API returns rupees as a decimal number. Arithmetic on these values is
 * done server-side; this is display only, which is why there is no addition
 * anywhere in this file.
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
 * Escapes text for insertion into HTML.
 *
 * Everything from the API is treated as untrusted: product names, review bodies
 * and support messages are all written by people. With the refresh token in
 * localStorage, one missed escape is a session-theft vulnerability rather than
 * a cosmetic bug.
 */
export function escapeHtml(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Restores the session on page load, if a refresh token survived. */
export async function bootstrapSession() {
  if (accessToken) return true;
  if (!localStorage.getItem(STORAGE.refresh)) return false;

  try {
    await refreshTokens();
    return true;
  } catch {
    return false;
  }
}

export { API_BASE, STORAGE };
