# Storefront

The customer-facing web application. Plain HTML, Bootstrap 5 and ES modules —
**no build step**. The merchant deploys it by copying files; requiring npm and a
bundler to change a button colour would put every future edit out of their reach.

It is a pure API client. There is no business logic here and no server-side
rendering: the same endpoints the Android and iOS apps will use serve this too.

## Running it

Serve `web/` from any static host. If it shares an origin with the API, nothing
needs configuring — the client defaults to `/api/v1`.

Apache, alongside the backend:

```apache
Alias /shop /path/to/spice-commerce/web
<Directory /path/to/spice-commerce/web>
    Options -Indexes +FollowSymLinks
    Require all granted
    DirectoryIndex index.html
</Directory>
```

To point at an API on another host, set the base URL before the module loads:

```html
<script>window.SPICE_API_BASE = 'https://api.example.com/api/v1';</script>
```

That requires CORS on the API. Same-origin is simpler and is what these files
assume.

## Your logo

Replace two files and nothing else:

| File | Where it shows | Background |
|---|---|---|
| `web/assets/img/logo.svg` | Shop header, console sidebar | dark forest |
| `web/assets/img/logo-dark.svg` | Console sign-in card | white |

Two versions because the backgrounds differ — one logo cannot serve both.

Roughly **4:1, about 320×80**. PNG, SVG and WebP all work; SVG stays sharp on
every screen. Keep the file names and nothing else needs changing.

To use different names or sizes, edit `SPICE_BRAND` in `web/assets/js/config.js`
(and `web/admin/assets/config.js` for the console). Set `logoUrl` to `null` to
show the shop name as text instead.

The files that ship are **placeholders**, drawn in the shop's own colours so the
header looks deliberate rather than unfinished. If a path is wrong the header
falls back to the name in text — a first deployment should never show a broken
image on every page.

## Design direction

Imported from the **Anjeera Dry Fruits** design project.

| | |
|---|---|
| Display | Marcellus |
| Body | Karla |
| Primary | Forest `#0B3B2E` |
| Accent | Gold `#C9A253`, light gold `#E4C77E` on forest |
| Surface | Cream `#FBF7EE` page, white cards, sand `#E7E0CE` rules |
| Shape | Cards 16–20px, buttons pill 22px, 44px minimum tap target |

**The import was almost entirely a stylesheet swap.** The markup already carried
semantic class names — `.product-card`, `.tag`, `.price`, `.panel`, `.trust-strip`
— so the design landed in `store.css` without touching a single API call, field
name or event handler. That was deliberate: the flow was hard won, and the
pincode field, session gate, cart merge and per-kilo pricing all had to survive
unchanged. The 91 storefront and 64 console assertions were the guard, and both
were identical before and after.

Two things carried over from the design's own content rather than invented: the
trust strip now leads with dispatch time, nitrogen-flushed packing and the
free-delivery threshold, and pack sizes stay on the card because the design shows
250g / 500g / 1kg on every product.

### Retained from the previous pass

**Price per kilo, beside every price.** Comparing 250g of one thing with 500g of
another is arithmetic no shopper should do at the shelf. The design did not
include it; it is kept because it is genuinely useful and costs nothing.

## Old direction (superseded)

Marigold and ink rather than the terracotta-on-cream every food shop reaches
for. Bricolage Grotesque for display, Inter for body, and **tabular figures
throughout** because half this interface is quantities and prices that need to
line up when scanned down a column.

**The signature is price per kilo, shown beside every price.** Comparing 250g of
one thing against 500g of another is arithmetic no shopper should do at the
shelf, and it is the most useful number a spice merchant can show that a generic
template does not. Pack sizes appear as pills on the card for the same reason —
for spices the pack size *is* the decision, so it belongs on the card rather than
one click away.

Everything else stays quiet: white cards so photographs read true, one accent
colour, badges only where they carry information (discount percentage, live
offer, organic).

## The admin console

Same visual system as the shop, imported from the design's second turn (which is
the admin panel itself):

| | |
|---|---|
| Sidebar | `#072A21` at 250px, active screen marked with a gold left bar |
| Working area | Parchment `#F6F2E7`, white cards at 12px with sand borders |
| Type | Marcellus for headings, Karla for everything else, tabular figures |

Deliberately **denser than the shop**: this is a tool someone uses for hours, not
a shop window. Smaller type, tighter rows, and gold used only where it carries
meaning. Destructive actions keep a real red — cancelling an order and refunding
money should never look like every other button.

**The import was a stylesheet swap.** No API call, field name, event handler or
page flow was touched; the 64 console assertions were identical before and after.
The console is where orders get packed and money gets refunded, and a restyle has
no business risking any of it.

## The admin console — behaviour

`web/admin/` is the staff console: dashboard, orders, support, reviews, products
and promotions. Same approach — Bootstrap 5, ES modules, no build step — and it
imports the storefront's API client rather than carrying a second copy, because
two clients drift and the one that drifts is always the one handling token
refresh.

It covers the daily operational loop plus full product management — creating a
product with its pack sizes, uploading photographs, and putting it on sale —
rather than mirroring all 112 admin endpoints. A console that exposes everything equally makes the twenty things
done hourly as hard to find as the two done yearly.

**Do not expose `web/admin/` publicly** without access control in front of it.
The API refuses non-staff accounts on every endpoint, and the console checks the
role too — but that check is courtesy, not security.

## Testing

```bash
npm install jsdom
node web/test/test_storefront.mjs        # 61 assertions
node web/test/test_console.mjs           # 64 assertions
```

The tests load the real page modules into a real DOM and drive them against the
**live API** — nothing is mocked except the browser. A mismatch between what the
client expects and what the server returns fails here rather than in front of a
customer.

**This is not a substitute for a browser.** Chromium could not be installed in
the build environment, so visual layout, Bootstrap's own JavaScript, and mobile
rendering are unverified. What is proven: the modules parse and execute, they
call the endpoints they claim to, they read the field names the API actually
sends, and they escape what they render.

## Files

| File | Purpose |
|---|---|
| `assets/js/api.js` | The API client: tokens, refresh, envelope handling, escaping |
| `assets/js/ui.js` | Header, footer, toasts, error display, loading states |
| `assets/js/page-*.js` | One module per page |
| `assets/css/store.css` | Brand colours and the few things Bootstrap does not cover |

## Two decisions worth knowing

**The refresh token lives in localStorage.** The access token is held in memory
only, so it dies with the tab. The refresh token has to survive a reload, and it
is therefore readable by any script on this origin. The alternative — an
httpOnly cookie — needs a server-side session bridge, which would put
authentication back into the web tier and break the API-first rule this platform
is built on. The mitigations that matter are a strict Content-Security-Policy
and escaping everything (`escapeHtml` is used on every interpolated value,
including CMS bodies, which are rendered as text rather than HTML).

**Payment confirmation is the server's job.** The page reports what the UPI app
said, then polls the order. The client callback is a hint; only a
signature-verified webhook confirms an order, and it arrives whether or not the
page is still open. Treating the callback as proof would show "confirmed" for a
payment that never settled.
"# 5Star_latest" 
#   f i v e S t a r _ P O S  
 #   f i v e S t a r _ P O S  
 