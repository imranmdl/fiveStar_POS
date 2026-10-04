# Architecture

## Layering

```
HTTP request
   ↓
public/index.php        front controller: env, CORS, error mapping
   ↓
Router + Middleware     activity log → throttle → authenticate → authorise
   ↓
Controller              validate input, delegate, shape response.  NO LOGIC.
   ↓
Service                 all business rules, transactions, orchestration
   ↓
Repository              SQL only, prepared statements, audit columns
   ↓
MySQL
```

Dependencies point one direction only. A repository never calls a service; a
service never touches `$_POST`; a controller never writes SQL.

## Why this satisfies the API-first rule

`routes/api_v1.php` is the single contract. The Bootstrap web app (Phase 10)
consumes it over AJAX and the Flutter app consumes it over HTTPS. Because
controllers hold no logic, there is no code path a client can reach that bypasses
a service rule — which is what makes "never duplicate business logic" structural
rather than aspirational.

## Conventions

**Every table** carries `id`, `uuid`, `created_by`, `created_date`, `updated_by`,
`updated_date`, `deleted_by`, `deleted_date`, `is_active`, `is_deleted`,
`version`. `BaseRepository` is the only code that maintains them, so a new
repository gets the contract for free and cannot forget it.

**`id` is never exposed.** APIs address resources by `uuid`. Sequential integers
leak volume (how many customers you have, how many orders yesterday) and invite
enumeration.

**Deletes are soft.** `softDelete()` sets flags; nothing is removed. `version`
increments on every write for optimistic locking.

**Money** is `DECIMAL`, never `FLOAT`. Weight is grams as an integer.

**Times** are `DATETIME` in IST (`+05:30`), set at both the PHP and MySQL session
level so no conversion surprises appear in reports.

## Security decisions and their reasons

| Decision | Reason |
|---|---|
| `ATTR_EMULATE_PREPARES => false` | Real server-side prepares; SQL injection is structurally impossible through `Database` |
| bcrypt cost 12, rehash on login | Cost can be raised later without a forced password reset |
| OTP stored as HMAC-SHA256 with an env-held pepper | A database dump alone cannot reveal or brute-force codes |
| `hash_equals` for OTP comparison | No timing oracle on the code |
| Issuing an OTP invalidates outstanding ones | Only the newest code is ever redeemable |
| Refresh tokens opaque + stored as SHA-256 | Logout and revocation are real, not cosmetic; a leak yields nothing usable |
| Refresh rotation with reuse detection | A replayed rotated token means theft, so every session for that user is killed |
| `users.tokens_valid_from` | A password change invalidates already-issued access tokens without a blacklist |
| Uniform response for unknown accounts, plus `wasteTime()` | No account enumeration by message or by timing |
| CORS whitelist of exact origins | `*` with credentials is never possible |
| Application code outside `public/` | A web-server misconfiguration cannot serve source or `.env` |
| Mass-assignment guarded by `fillable()` | A caller cannot set `role_id` or audit columns by adding a JSON key |
| Column names validated by shape before reaching SQL | Identifiers cannot be bound, so `sort`/filter inputs are whitelisted |
| Secrets in `.env` only | Nothing sensitive in migrations, seeds or version control |

## Money and pricing

One engine produces every total in the system: `App\Services\Pricing\PricingEngine`.
Cart, checkout, order creation and invoicing all call it. If any of them ever
computes its own total, the customer will eventually see one number on the cart
page and a different one on the payment screen, and the difference is the one
they screenshot.

| Decision | Reason |
|---|---|
| `Money` stores integer paise | 0.1 + 0.2 is not 0.3 in binary floating point; summing thirty float lines eventually misses the invoice by a paisa |
| GST is **extracted**, not added | Indian MRP is tax-inclusive by law. Adding GST on top overcharges the customer and misstates the liability |
| Tax derived by subtraction (`tax = inclusive - net`) | Guarantees `net + tax == inclusive` exactly, with no second rounding |
| Order discounts apportioned by line value | A flat ₹100 coupon on a cart mixing 5% and 12% goods gives different tax depending on the split; by-value is the defensible one |
| Largest-remainder allocation | Naive per-line rounding leaks paise, so the parts stop summing to the whole |
| The engine is pure — no database, clock or request | It can be unit-tested in under a second, which `bin/test_pricing.php` does |
| `PriceBreakdown::reconciles()` | A standing self-check that lines still add to the grand total, surfaced to clients so a rounding regression cannot silently bill anyone |
| Prices resolved once, in `vw_variant_pricing` | Catalog, cart and checkout read the same effective price, so they cannot disagree about whether an offer is live |

`PriceAdjustment` is the extension seam. Phase 4 coupons and Phase 5 wallet
redemption supply adjustments and get correct per-line GST for free; they must
never compute totals themselves.

## Promotions and the wallet ledger

| Decision | Reason |
|---|---|
| Wallet credit is a payment tender, not a discount | A coupon reduces the transaction value and its GST; wallet credit is money already owned. Modelling it as a discount understates tax liability |
| The wallet ledger is append-only, enforced by DB triggers | Application discipline is not enough — a future maintenance script must be refused by MySQL itself. Corrections are compensating entries |
| `balance_amount` is a cache, the ledger is the authority | `verifyIntegrity()` re-derives it and reports drift, so a bug is found before a customer finds it |
| Every balance change holds `SELECT … FOR UPDATE` | Two concurrent redemptions would otherwise read the same balance and both succeed |
| Credits carry a UNIQUE idempotency key | A retried payout or redelivered webhook must not pay twice |
| Coupon usage claimed in one atomic UPDATE | A SELECT-then-increment lets two customers both take the last use |
| Redemption is written at order placement, not cart time | An abandoned cart must not burn a limited-use coupon |
| Referrals pay out only after a qualifying paid order | Rewarding at signup is an invitation to farm accounts |
| A scoped promotion with no targets discounts nothing | Failing closed; the alternative discounts the whole catalogue |
| Percentage promotions cannot activate without a cap | An uncapped percentage on a large order is an unbounded liability |
| All stacking rules in `PromotionResolver` | Discount logic becomes unmaintainable when "can X combine with Y" is answered in five files |
| Rejections carry a specific, quotable reason | "Invalid coupon" is a support ticket that costs more than the discount |
| A private coupon returns the same message as an unknown one | Revealing that a code exists but belongs to someone else invites probing |

## Error handling

Business failures throw `HttpException` subclasses and return a clean envelope
with no stack trace and no error-log noise. Anything else is logged with a
request id and returns a generic 500 quoting that id — the client learns nothing
about internals, and support can find the exact log line.

Every response carries `X-Request-Id`, and every request produces one
`activity_logs` row (method, endpoint, status, duration, actor, IP), satisfying
BR-009. Log writes can never break the request they describe: a failure there
falls back to the file log.

## Rate limiting

Fixed-window counters in the `rate_limits` table via a single atomic UPSERT.
Per-route limits are declared in the route table (`throttle:5,600`), so the
policy is visible next to the endpoint rather than buried in a config file. At
scale, swap `RateLimitRepository` for a Redis implementation — one container
binding, no other change.

## What Phase 1 deliberately does not include

No queue worker (notifications are synchronous for now), no caching layer, no
WhatsApp/email channel, no OpenAPI generation. Each arrives with the phase that
first needs it, so nothing is built speculatively.
