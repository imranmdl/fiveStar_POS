# Deploying the storefront

Five minutes, two steps.

## 1. Copy the WHOLE folder

Copy this entire directory — not just the `.html` files. The pages are empty
shells; every one of them loads its behaviour from `assets/js/`.

Your web root should end up looking exactly like this:

```
5star/
├── index.html
├── product.html
├── cart.html
├── checkout.html
├── orders.html
├── account.html
├── support.html
├── faq.html
├── page.html
└── assets/
    ├── css/
    │   └── store.css
    └── js/
        ├── config.js          ← the only file you edit
        ├── api.js
        ├── ui.js
        ├── page-catalog.js
        ├── page-product.js
        ├── page-cart.js
        ├── page-checkout.js
        ├── page-orders.js
        ├── page-account.js
        ├── page-support.js
        ├── page-faq.js
        └── page-page.js
```

**Check it worked** by opening this in your browser:

```
http://localhost/5star/assets/js/api.js
```

You should see JavaScript source. If you get "Not Found", the `assets` folder
did not copy and nothing on the site will work.

## 2. Point it at your backend

Every URL the front end uses lives in **one file: `assets/js/env.js`** (the admin
console loads the same file). Nothing else — not `config.js`, not `api.js` — holds
a host name.

```js
var ACTIVE = 'auto';   // 'auto' | 'local' | 'production'

var ENVIRONMENTS = {
  local:      { siteUrl: 'http://localhost/5star' },
  production: { siteUrl: 'https://5star.alimstech.com' },
};
```

With `'auto'` (the default) it picks by the address in the browser — `localhost`
means local, anything else means production — so **deploying needs no edit at
all**. Change `siteUrl` if a site moves; add an entry to `ENVIRONMENTS` for a new
deployment. The API address (`<siteUrl>/spice-api/backend/public/api/v1`) and the
uploads address are derived from it. Confirm it by opening:

```
<siteUrl>/spice-api/backend/public/api/v1/health
```

You should get `{"success":true, ...}`. (The backend's own `APP_URL` in
`spice-api/backend/.env` is a separate, server-side setting.)

### Same host or different host?

**Same host** (storefront and API under one domain) is simplest and needs no
extra setting.

**Different host or port** — give that environment an explicit `apiBase` in
`env.js` (e.g. `apiBase: 'https://api.example.com/api/v1'`), and the backend
must send CORS headers allowing your storefront's origin. In `backend/public/.htaccess`:

```apache
Header always set Access-Control-Allow-Origin "http://localhost"
Header always set Access-Control-Allow-Headers "Authorization, Content-Type, X-Cart-Token"
Header always set Access-Control-Allow-Methods "GET, POST, PATCH, DELETE, OPTIONS"
```

Requires `mod_headers`: `sudo a2enmod headers`.

## If the page still looks broken

Open the browser console with **F12**. The storefront now shows a red banner
when it cannot reach the API, but the console has the detail.

| What you see | What it means |
|---|---|
| Page renders but no navbar, "Loading…" never changes | The JavaScript did not load. Check `assets/js/api.js` opens in the browser |
| `Failed to load module script ... MIME type "text/plain"` | Your server sends `.js` as the wrong type. Add `AddType text/javascript .js` to `.htaccess` |
| Red banner: "Cannot reach the shop" | `config.js` points at the wrong address, or the backend is not running |
| `blocked by CORS policy` | Storefront and API are on different origins; add the headers above |
| `404` on `/api/v1/products` | The API base is wrong — probably missing `/api/v1` |

## A note on file:// URLs

Opening `index.html` by double-clicking will **not** work. ES modules require
`http://`, and browsers block them on `file://`. Serve the folder from any web
server — Apache, nginx, or `python3 -m http.server`.
