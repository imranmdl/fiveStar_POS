# Build Plan — Spice & Dry Fruits Commerce Platform

The SRS describes roughly 30 modules, ~450 endpoints and 100+ tables. That is a
multi-month build. It gets delivered in vertical slices: each phase ends with
working, migrated, documented, testable API endpoints — never a half-wired layer.

**Phase 1 is complete and in this repository.** Everything after it is planned,
not built.

---

## Phase 1 — Foundation & Authentication ✅ DELIVERED

| Deliverable | Status |
|---|---|
| Front controller, router, middleware pipeline, DI container | Done |
| Standard API envelope (`success`/`message`/`data`/`errors`) | Done |
| PDO layer, `BaseRepository` enforcing the 11 audit columns | Done |
| Validator, exception hierarchy, centralised error handling | Done |
| JWT access tokens + rotating refresh tokens with reuse detection | Done |
| OTP service (hashed, throttled, attempt-limited, single-use) | Done |
| Registration, OTP login, password login, lockout, reset, change | Done |
| Role-based authorisation middleware | Done |
| Audit log + per-request activity log | Done |
| DB rate limiting | Done |
| Migration 001 + rollback + idempotent seeds + migration runner | Done |
| 18-assertion end-to-end smoke test | Done |

Why authentication first: every other module depends on knowing who the caller
is, and the OTP service built here is the same one order confirmation needs for
BR-003. Building it once, properly, avoids reimplementing it five times.

---

## Phase 2 — Product Catalog ✅ DELIVERED

| Deliverable | Status |
|---|---|
| Migration 002: categories, products, variants, media, nutrition, attributes, banners | Done |
| Self-referencing category tree with loop protection and live product counts | Done |
| Products with regulatory data (HSN, GST, FSSAI, shelf life, origin) | Done |
| Pack-size variants carrying weight and price, with CHECK constraints | Done |
| Pricing views resolving live offers in one place | Done |
| Full-text search with regional synonyms, plus LIKE fallback | Done |
| Filtering by category tree, price, weight, rating, offer, organic, brand | Done |
| Nine sort modes including relevance and discount | Done |
| Draft → published → archived lifecycle with a publish-readiness gate | Done |
| Hardened image upload (content-verified, randomised names, non-executable dir) | Done |
| Video linking, nutrition table, open-ended specifications | Done |
| Banner management with scheduling, link-target verification, CTR stats | Done |
| Seed 002: 7 categories, 9 subcategories, 3 demo products, 8 pack sizes | Done |
| Catalog smoke test (~50 assertions) | Done |

Design notes worth carrying forward:

- **Weight lives on the variant, and is mandatory.** Courier selection (BR-007)
  and delivery charges (BR-006) both need it, so a product cannot exist without
  a shippable weight.
- **`vw_variant_pricing` is the only place that decides whether an offer is
  live.** Cart, checkout and order pricing must read `effective_price` from it
  rather than recomputing the window, or the three will eventually disagree.
- **No stock column exists anywhere** (BR-001/BR-002). Availability is `status`.
- **Publishing is gated, not automatic.** Refusing to publish a product with no
  price, no weight or no image is far cheaper than discovering it at checkout.

## Phase 3 — Cart, Wishlist & Pricing Engine ✅ DELIVERED

| Deliverable | Status |
|---|---|
| Migration 003: carts, cart_items, wishlist_items, delivery zones, pincode map, charge slabs | Done |
| `Money` value type — integer paise, largest-remainder allocation, GST extraction | Done |
| `PricingEngine` — pure, database-free, the single source of every total | Done |
| GST **extracted** from inclusive MRP, broken down per rate | Done |
| Order-level discounts apportioned across lines for correct per-line tax | Done |
| Guest carts with anonymous tokens, merged idempotently on login | Done |
| Price-change detection and re-quoting with customer-facing messages | Done |
| Unavailable lines retained and flagged rather than silently dropped | Done |
| Save-for-later, quantity limits, cart line caps, ownership checks | Done |
| Delivery charge engine (BR-006): longest-prefix zones, weight bands, waivers | Done |
| Serviceability check and published rate card | Done |
| Wishlist with price-at-add baselines and price-drop deltas | Done |
| Checkout readiness verdict listing every blocker at once | Done |
| `bin/test_pricing.php` — database-free unit and property tests | Done |
| `bin/smoke_test_cart.php` — ~70 end-to-end assertions | Done |

The two open questions were resolved as recommended: **guests get real carts**
(merged on login), and **price changes are re-quoted and disclosed** rather than
absorbed. Both are reversible — the first by dropping the guest branch in
`CartService::resolveCart`, the second by removing `reconcileSnapshots`.

Decisions that constrain later phases:

- **Indian MRP is GST-inclusive**, so tax is extracted, never added. Flipping
  this means revisiting the engine and every invoice template.
- **`Money` is the only way money is handled.** No float arithmetic on money
  anywhere, ever. `PriceBreakdown::reconciles()` is the standing guard.
- **`PriceAdjustment` is the coupon seam.** Phase 4 supplies adjustments and
  gets correctly apportioned GST for free; it must not compute totals itself.
- **`DeliveryChargeService` already resolves zone and chargeable weight**, which
  are exactly the inputs BR-007 courier selection needs in Phase 6.

## Phase 4 — Coupons, Offers, Referrals & Wallet ✅ DELIVERED

| Deliverable | Status |
|---|---|
| Migration 004: coupons, targets, redemptions, offers, referrals, wallet, expiries | Done |
| Wallet ledger **append-only, enforced by database triggers** | Done |
| Idempotent credits (UNIQUE key) — retried payouts are no-ops | Done |
| Row-locked balance mutations (`SELECT … FOR UPDATE`) | Done |
| Ledger integrity check that re-derives the balance and reports drift | Done |
| Credit expiry via compensating debits and a side table | Done |
| Coupon eligibility: window, audience, global and per-customer limits, scope | Done |
| Atomic usage claiming — two customers cannot both take the last use | Done |
| Specific rejection reason for every failure mode | Done |
| Offers as dated campaigns, with optional automatic discounts | Done |
| `PromotionResolver` — every stacking rule in one file | Done |
| Referral lifecycle pending → qualified → rewarded, paid only after a real order | Done |
| Referral signup recorded from `AuthService`, fraud signals logged | Done |
| Activation readiness gates on coupons and discounting offers | Done |
| Wallet as a payment tender: `amount_payable` split, GST untouched | Done |
| `bin/test_promotions.php` — database-free unit tests | Done |
| `bin/smoke_test_promotions.php` — end-to-end incl. referral payout | Done |

Stacking was resolved as recommended: **one coupon, one automatic offer, wallet
credit on top.** Coupon and offer combine only if both are flagged stackable;
otherwise the larger single discount wins and the customer is told which and why.

Decisions that constrain later phases:

- **Wallet credit is a payment tender, never a discount.** It changes
  `amount_payable` only; the order value and GST are untouched. Treating it as a
  discount would understate tax liability on every order it touched.
- **The ledger cannot be edited, by anyone.** Corrections are compensating
  entries. Phase 5 refunds and Phase 7 commissions must follow the same pattern.
- **`PromotionResolver` is the only place stacking is decided.** Phase 5 must not
  re-derive discounts at order placement; it reads the resolved breakdown.
- **`CouponService::redeem()` is called at order placement, not cart time**, so an
  abandoned cart never burns a limited-use coupon. Phase 5 must call it, and
  `release()` on cancellation.
- **`ReferralService::qualifyForOrder()` is ready and waiting** for Phase 5 to call
  on payment verification.

## Verification status

Phases 1-12 have been **executed**, not just written, against PHP 8.3.6 and MySQL
8.0.46: **1,039 assertions, 0 failures**, all migrations applying from empty and
rolling back cleanly. Six real bugs were found and fixed in the process; four are
now caught permanently by `bin/audit_consistency.php`. Full detail, including an
explicit list of what remains unverified, is in [TESTING.md](TESTING.md).

The significant known gap is **concurrency**: the atomic coupon claim, the wallet
row locking, and the one-active-cart unique index are correct by construction but
have never been exercised by parallel requests, because PHP's built-in server is
single-threaded. That needs load against Apache or php-fpm before go-live.

## Phase 5 — Checkout, UPI Payment & Orders — DELIVERED

Address selection, delivery slots, order OTP (BR-003), UPI intent/QR, webhook
verification, order state machine, timeline. Two hard rules enforced in the
service layer, not the UI: orders only progress after a *verified* payment
(BR-005), and no order dispatches unpaid.

Phase 4 left three hooks for this phase to call, all already written and tested:
`CouponService::redeem()` at placement (and `release()` on cancellation),
`ReferralService::qualifyForOrder()` on payment verification, and
`WalletService::debit()` for the wallet portion of the tender.

`carts.converted_order_id` and `coupon_redemptions.order_reference` are waiting
for the orders table; neither carries a foreign key yet.

## Phase 6 — Delivery Integration

Courier adapter interface with Shiprocket/Delhivery/Blue Dart/XpressBees/DTDC/
Porter/Shadowfax implementations; the BR-007 selection algorithm scoring weight,
pincode serviceability, cost and SLA; labels, manifests, pickups, tracking
webhooks.

## Phase 7 — Staff Operations & Commission — DELIVERED

## Phase 8 — Wholesale Quotations & Reporting — DELIVERED

## Phase 9 — Notifications & Scheduling — DELIVERED

## Phase 10 — Reviews, Support & Content — DELIVERED

## Phase 11 — API Contract & Client Foundations — DELIVERED

## Phase 12 — Concurrency Verification & Hardening — DELIVERED

## Phase 13 — Closing the Concurrency Gaps — DELIVERED

## Phase 14 — Web Storefront — DELIVERED

## Phase 15 — Admin Console — DELIVERED

## Phase 16 — Product Management — DELIVERED

## Phase 17 — Go-Live Readiness — DELIVERED

## Phase 18 — Buy X Get Y Offers — DELIVERED

## Phase 19 — Merchandising, Gifting & Sharing — DELIVERED

## Phase 20 — Campaign Pages — DELIVERED
- Migration 011: `collections` + `collection_items`, banner link_type gains `collection`
- Four fixed templates: grid, spotlight, story, gift
- Console: Shopfront → Campaign pages (create, curate, publish)
- Storefront: `collection.html`, adverts can point at one
- Console: Shopfront (categories, adverts, pages), Wholesale, Customers
- Storefront: advert slots, gifting enquiry page, WhatsApp sharing
- NOT DONE: bulk promotional messaging, WhatsApp order updates (no provider)

Both items left open by Phase 12 are closed. Three separate insert races in
the add-to-cart path were found and fixed with atomic upserts, and the
single-use coupon guard is now genuinely contended and verified.

**The concurrency suite passes 34 of 34.** The outstanding go-live blocker
carried since Phase 4 is closed.

What remains is the client layer: the Bootstrap 5 storefront, the Flutter
applications, and the operational items in TESTING.md (DLT template
registration, live gateway and courier credentials, and a legal review of the
placeholder policy pages).

The backend is now feature-complete against the SRS. What remains is the
client layer — Bootstrap web, Flutter apps, OpenAPI specification — and the
hardening work in TESTING.md, of which **concurrency testing is the one
genuine blocker**.

Executive/supervisor dashboards, order assignment, packing slips, bulk order
quotation and approval, commission calculation and settlement.

## Phase 8 — Engagement

Reviews and ratings with moderation, photo/video reviews, support tickets, CMS,
blog, FAQ, feedback.

## Phase 9 — Notifications & Reporting

SMS/WhatsApp/email/push fan-out via a queued dispatcher; the full report suite
and dashboard aggregates.

## Phase 10 — Clients & Hardening

Bootstrap 5 web app and Flutter app consuming the same APIs; Swagger/OpenAPI
generation; load testing to the SRS targets; Redis cache; CDN; deployment
runbook.

---

## Two decisions worth revisiting before Phase 2

**1. No framework.** The SRS specifies PHP 8.3, MVC, repository pattern and
service layer without naming a framework, so this is a purpose-built thin core
with zero third-party dependencies. It is small, fast and fully inspectable.

The trade-off is real: Laravel or Symfony would give you queues, a mature
migration system, an ORM, a test harness, mail transports and a large hiring
pool for free — and you will end up hand-building thin versions of several of
those by Phase 9. If the team is comfortable with a framework, switching now
costs about a week and pays back before Phase 5. Switching at Phase 6 costs
far more. Worth deciding deliberately rather than by default.

**2. `/api/v1` is the only contract.** The web UI in Phase 10 will consume these
same endpoints over AJAX. Resist the temptation to add a "quick" server-rendered
page with SQL in it — that is the one shortcut that breaks the architecture rule
and forces every later change to be made twice.
