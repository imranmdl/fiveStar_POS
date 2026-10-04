/**
 * Minimal service worker: what Chrome/Android needs to consider the storefront
 * installable, plus enough offline behaviour to be worth having one at all.
 *
 * WHAT IS NEVER CACHED: anything hitting /api/ (prices, stock, cart, orders —
 * this is a live commerce site; serving a stale cart total or a stale price
 * from cache would be a correctness bug, not a convenience). Those requests
 * always go straight to the network and are never intercepted below.
 *
 * Everything else (pages, CSS, JS) is fetched from the network first, so a
 * deploy always shows up immediately; the cached copy is used only when the
 * network is down.
 */

const CACHE_NAME = 'spice-shell-v3';

// Just enough to make a repeat visit and a basic offline state work — not an
// attempt to pre-cache the whole catalogue.
const APP_SHELL = [
  './',
  './index.html',
  './assets/css/store.css',
  './assets/js/ui.js',
  './assets/js/api.js',
  './assets/js/env.js',
  './assets/js/config.js',
  './assets/img/logo.svg',
  './assets/img/icon.svg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never intercept the API, or anything cross-origin (fonts CDN, bootstrap
  // CDN) — only this origin's own static files get the offline treatment.
  if (event.request.method !== 'GET' || url.pathname.includes('/api/') || url.origin !== self.location.origin) {
    return;
  }

  // Network-first: a deploy (or an edited env.js / config.js) must reach the
  // user on the very next load. The cache is only the offline fallback.
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        }
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
