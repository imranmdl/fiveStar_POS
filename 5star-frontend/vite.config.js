import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// The live PHP API has no CORS entry for a local dev origin (it's locked down
// to the production storefront's own origin), so dev requests are proxied
// server-to-server instead of going cross-origin from the browser. The app
// calls '/api/v1/...' and this rewrites it to the real backend underneath —
// matching the same-host deployment shape described in the project's
// config.js ("If the storefront and the API are served from the SAME host,
// a path is enough and no CORS configuration is needed").
const API_PROXY_TARGET = 'https://5star.alimstech.com/spice-api/backend/public'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      devOptions: { enabled: true },
      includeAssets: ['favicon.svg'],
      manifest: {
        name: '5Star Spices',
        short_name: '5Star',
        description: 'Spices, dry fruits and groceries — shop online, or sign in to run the store.',
        theme_color: '#2b2a5c',
        background_color: '#2b2a5c',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // Product/cart/order data must never be served stale from a cache —
        // every /api request bypasses the service worker entirely and goes
        // straight to the network (it still works offline-first for the APP
        // SHELL, just not for data).
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [
          {
            urlPattern: /^\/api\/.*/,
            handler: 'NetworkOnly',
          },
          {
            urlPattern: ({ request }) => request.destination === 'image',
            handler: 'CacheFirst',
            options: {
              cacheName: 'images',
              expiration: { maxEntries: 120, maxAgeSeconds: 7 * 24 * 60 * 60 },
            },
          },
        ],
      },
    }),
  ],
  server: {
    proxy: {
      '/api': {
        target: API_PROXY_TARGET,
        changeOrigin: true,
        secure: true,
      },
    },
  },
})
