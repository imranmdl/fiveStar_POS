# Testing

Everything in this document has been executed against PHP 8.3.6 and MySQL
8.0.46. The assertion counts are real, not estimates.

**Current state: 1,039 backend, 34 concurrency, 101 storefront and 64 console
assertions, all passing.**

| Suite | Assertions | Needs |
|---|---|---|
| `bin/test_pricing.php` | 76 | Nothing but PHP |
| `bin/test_promotions.php` | 47 | Nothing but PHP |
| `bin/smoke_test.php` | 21 | Database + dev server |
| `bin/smoke_test_catalog.php` | 54 | + administrator login |
| `bin/smoke_test_cart.php` | 83 | Database + dev server |
| `bin/smoke_test_promotions.php` | 94 | + administrator login |
| `bin/test_orders.php` | 56 | Nothing but PHP |
| `bin/smoke_test_checkout.php` | 92 | + administrator login, `PAYMENT_DRIVER=sandbox` |
| `bin/test_courier_selection.php` | 54 | Nothing but PHP |
| `bin/smoke_test_delivery.php` | 70 | + administrator login, `COURIER_DRIVER=sandbox` |
| `bin/test_staff_operations.php` | 49 | Nothing but PHP |
| `bin/smoke_test_staff.php` | 57 | + administrator login |
| `bin/smoke_test_bulk.php` | 86 | + administrator login |
| `bin/test_notifications.php` | 39 | Nothing but PHP |
| `bin/test_bogo.php` | 35 | Nothing but PHP |
| `bin/smoke_test_notifications.php` | 43 | + administrator login |
| `bin/smoke_test_engagement.php` | 86 | + administrator login |
| `bin/test_openapi.php` | 32 | Nothing but PHP |
| `web/test/test_storefront.mjs` | 101 | jsdom + a running API |
| `web/test/test_console.mjs` | 64 | jsdom + a running API + staff login |

Plus `bin/audit_consistency.php`, a static check that needs neither database nor
server and should be run before every commit.

---

## Prerequisites

### PHP 8.3 with these extensions

```bash
sudo apt install php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-gd
```

Each one is load-bearing, and the failure modes are not obvious:

| Extension | Consequence if missing |
|---|---|
| `pdo_mysql` | Nothing works |
| `curl` | Every smoke test dies immediately: `Call to undefined function curl_init()` |
| `mbstring` | Multi-byte name and address handling breaks |
| `gd` | **The catalog suite silently skips image-upload success paths.** It reports 50 passes instead of 54 and tells you at the end. Not a failure, but four fewer assertions than you think you ran. |
| `fileinfo` | Upload MIME sniffing fails, so image validation rejects everything |

Verify:

```bash
php -m | grep -E '^(pdo_mysql|curl|mbstring|gd|fileinfo)$'
```

### MySQL 8.0.16 or newer

**8.0.16 is a hard floor.** CHECK constraints were only enforced from that
version; earlier 8.0 releases parse and silently ignore them. This schema relies
on 27 CHECK constraints for correctness — a negative wallet balance and a 150%
coupon are both prevented by CHECK, not by application code alone.

```bash
mysql --version   # must be >= 8.0.16
```

MySQL 5.7 will not work at all: the schema uses STORED generated columns with
unique indexes over them, and `SIGNAL` in triggers.

---

## Setup

```bash
# 1. Database and user
mysql -u root -p -e "
  CREATE DATABASE spice_commerce CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'spice'@'localhost' IDENTIFIED BY 'a-real-password';
  GRANT ALL PRIVILEGES ON spice_commerce.* TO 'spice'@'localhost';
  GRANT TRIGGER ON spice_commerce.* TO 'spice'@'localhost';
  FLUSH PRIVILEGES;"

# 2. Configuration
cd backend
cp .env.example .env
```

Edit `.env`. For a test run you need at minimum:

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8080

DB_HOST=127.0.0.1
DB_DATABASE=spice_commerce
DB_USERNAME=spice
DB_PASSWORD=a-real-password

JWT_SECRET=<64 random hex characters>
OTP_PEPPER=<64 random hex characters>

# Required by the smoke tests: exposes the OTP in the registration response so a
# test can complete a signup. Has no effect unless APP_ENV is local.
OTP_EXPOSE_IN_RESPONSE=true
SMS_DRIVER=log
```

Generate the secrets:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

The `TRIGGER` grant matters. Migration 004 creates the two triggers that make the
wallet ledger append-only; without that privilege the migration fails partway
through.

```bash
# 3. Schema and seed data
php bin/migrate.php

# 4. An administrator (prompts; the password is never written to a file)
php bin/seed_admin.php
```

Expected migration output:

```
Applying 001_core_foundation ... 18 statement(s) OK
Applying 002_catalog ... 14 statement(s) OK
Applying 003_cart_wishlist_delivery ... 11 statement(s) OK
Applying 004_promotions_wallet ... 19 statement(s) OK
Seeding 001_roles_permissions_settings ... 7 statement(s) OK
Seeding 002_catalog_seed ... 7 statement(s) OK
Seeding 003_delivery_pricing_seed ... 5 statement(s) OK
Seeding 004_promotions_seed ... 7 statement(s) OK
```

That produces 35 tables, 7 views, 27 CHECK constraints, 42 foreign keys and 2
triggers.

---

## Running the tests

### Fastest signal first — no database needed

```bash
php bin/audit_consistency.php    # structural checks
php bin/test_pricing.php         # 76 assertions
php bin/test_promotions.php      # 47 assertions
```

Run these on every change to money handling. `test_pricing.php` verifies GST
extraction across 20,000 amounts, proves 3,000 random discount allocations leak
zero paise, and checks that a 17-line cart with mixed GST rates and an awkward
coupon reconciles to the paisa. It takes under a second.

### End-to-end

```bash
# Terminal 1
php -S 127.0.0.1:8080 -t public

# Terminal 2
php bin/smoke_test.php
php bin/reset_rate_limits.php
php bin/smoke_test_catalog.php      # prompts for administrator credentials
php bin/reset_rate_limits.php
php bin/smoke_test_cart.php
php bin/reset_rate_limits.php
php bin/smoke_test_promotions.php   # prompts for administrator credentials
```

---

## The rate limit will bite you

**Symptom:** the second suite you run fails immediately with
`Too many attempts. Please try again in 462 seconds.`

**Cause:** the smoke tests register throwaway customers, and registration is
throttled per IP address. Every suite runs from the same machine. This is the
limiter working exactly as designed.

**Fix:** `php bin/reset_rate_limits.php` between suites. It refuses to run unless
`APP_ENV` is `local` or `testing`, because clearing these counters in production
would hand an attacker a fresh allowance mid-attack.

---

## Verifying the database-level guarantees yourself

Phase 10 added three more that are worth confirming on your own installation:

```sql
-- A customer cannot author an internal support note
INSERT INTO support_ticket_messages (uuid, ticket_id, author_type, body, is_internal_note)
VALUES (UUID(), 1, 'customer', 'x', 1);
-- ERROR 3819: Check constraint 'chk_message_internal_is_staff' is violated

-- A rating outside 1-5
INSERT INTO product_reviews (uuid, product_id, user_id, rating) VALUES (UUID(), 1, 1, 6);
-- ERROR 3819: Check constraint 'chk_review_rating_range' is violated

-- Two reviews of the same product by the same customer
-- ERROR 1062: Duplicate entry for key 'product_reviews.uq_review_user_product'
```


Several important properties are enforced by MySQL rather than by application
code. Worth confirming on your own installation rather than taking the comments
on trust — each of these should be **refused**:

```sql
-- The wallet ledger is append-only
UPDATE wallet_transactions SET amount = 999 WHERE id = 1;
-- ERROR 1644: wallet_transactions is append-only: post a compensating entry instead of editing
DELETE FROM wallet_transactions WHERE id = 1;
-- ERROR 1644: wallet_transactions is append-only: rows can never be deleted

-- One active cart per customer
INSERT INTO carts (uuid, user_id, status) VALUES (UUID(), 1, 'active');
INSERT INTO carts (uuid, user_id, status) VALUES (UUID(), 1, 'active');
-- ERROR 1062: Duplicate entry '1' for key 'carts.uq_carts_active_user'

-- A cart has exactly one owner
INSERT INTO carts (uuid, user_id, guest_token_hash, status)
VALUES (UUID(), 1, REPEAT('a', 64), 'active');
-- ERROR 3819: Check constraint 'chk_carts_single_owner' is violated

-- Self-referral
INSERT INTO referrals (uuid, referrer_user_id, referee_user_id, referral_code_used)
VALUES (UUID(), 1, 1, 'X');
-- ERROR 3819: Check constraint 'chk_referrals_not_self' is violated

-- Negative wallet balance
UPDATE wallet_accounts SET balance_amount = -50 WHERE id = 1;
-- ERROR 3819: Check constraint 'chk_wallet_balance_not_negative' is violated

-- An impossible coupon
INSERT INTO coupons (uuid, code, title, discount_type, discount_value)
VALUES (UUID(), 'BAD200', 'Bad', 'percentage', 200);
-- ERROR 3819: Check constraint 'chk_coupons_percentage_range' is violated

-- An offer price above the selling price
UPDATE product_variants SET offer_price = selling_price + 100 WHERE id = 1;
-- ERROR 3819: Check constraint 'chk_variants_offer_below_selling' is violated
```

If any of those **succeed**, your MySQL is older than 8.0.16 and is ignoring
CHECK constraints. Stop and upgrade before going further.

---

## Rollbacks

The rollback chain has been executed end to end: all four migrations down to an
empty database and back up again.

```bash
php bin/migrate.php --status
php bin/migrate.php --rollback     # one batch at a time, prompts for confirmation
```

Rolling back 004 **destroys the wallet ledger**, which is the only record of what
customers are owed. Export `wallet_transactions` first if there is any real data:

```bash
mysqldump spice_commerce wallet_transactions wallet_accounts > wallet-backup.sql
```

---

## Concurrency — now verified

Run against Apache with mod_php (prefork MPM), not PHP's built-in server:

```bash
sudo apt install apache2 libapache2-mod-php8.3
# DocumentRoot must point at backend/public with AllowOverride All
sudo chown -R www-data:www-data backend/storage     # required; see below
CONCURRENCY_URL=http://127.0.0.1:8081 php bin/test_concurrency.php
```

The harness refuses to run against the development server: it reads the `Server`
response header and stops if it finds `PHP ... Development Server`, because
every test would otherwise pass while exercising nothing.

**All 34 assertions pass. Every guard held under genuine parallel load:**

| Guard | Attack | Result |
|---|---|---|
| Wallet row lock | 6 concurrent orders each spending the full balance | Never over-debited; balance never negative; cache matched the ledger |
| Invoice numbering | 8 payment webhooks confirming at once | 8 numbers, all distinct, **no gaps** |
| Notification queue claim | 6 workers dispatching simultaneously | No message sent twice; none stuck in `sending` |
| Scheduler lock | 6 concurrent runs of one task | Exactly one ran; the rest reported it locked; no lock leaked |
| Assignment unique index | 6 supervisors assigning one order | Exactly one open assignment; one 201 |
| Webhook dedupe | Same payment webhook delivered 6× at once | Recorded once; one capture; invoice not reissued |
| Cart unique index | 8 add-to-cart from a customer with no cart | Exactly one cart |

Every money-critical guard was correct. That was the outstanding go-live risk and
it is now evidence rather than reasoning.

### Three races found and fixed, each hidden behind the last

Concurrent add-to-cart initially failed 7 of 8 requests. Fixing it peeled back
three separate races in the same request path — each invisible until the one in
front of it was gone.

1. **`carts.uq_carts_active_user`** — two requests both find no cart and both
   insert one. Fixed by reading the winner's cart and continuing.
2. **`cart_items.uq_cart_item_variant`** — two requests both find no line and
   both insert one. Fixed with `INSERT ... ON DUPLICATE KEY UPDATE`.
3. **`wallet_accounts.uq_wallet_accounts_user`** — wallet accounts are created
   lazily, so several requests for a customer who has never had one all try to
   insert. Fixed with an upsert that touches only `updated_date`, because the
   balance is a cache of the ledger and must never be reset by a late arrival.

**Why an upsert rather than catch-and-retry.** Catching the duplicate and
re-reading does not work: under MySQL's REPEATABLE READ the loser's transaction
snapshot predates the winner's commit, so the re-read finds nothing. A locking
read does see the row but blocks until the winner commits, and under load those
waits cascade into lock timeouts. Measured against the item race:

| Approach | Failures out of 8 |
|---|---|
| Look, then insert | 7 |
| Catch duplicate, plain re-read | 4 |
| Catch duplicate, locking re-read | 7 |
| `INSERT ... ON DUPLICATE KEY UPDATE` | **0** |

An upsert has nothing to read and nothing to wait on.

One subtlety worth knowing if you edit that SQL: inside `ON DUPLICATE KEY
UPDATE`, MySQL evaluates assignments left to right and a column reads its OLD
value only until it is itself assigned. `is_deleted` is therefore read by every
conditional and set **last**. Moving that line up would make each branch take the
wrong path.

### Previously open, now closed

The single-use coupon race is now genuinely verified: ten customers place orders
simultaneously against a coupon with one use remaining, and exactly one
redemption is recorded. The earlier run reported zero because the test applied
the coupon with the wrong field name and it never reached a cart — the race
contended nothing and reported green regardless. The test now asserts that the
coupon was applied before racing on it, which is the sort of check a concurrency
test needs most: a setup failure that quietly makes the test vacuous is worse
than an outright failure.

## What the storefront tests do NOT cover

Chromium could not be installed in the build environment, so the storefront is
verified with jsdom against the live API rather than in a browser. That proves
the modules parse and execute, call the right endpoints, read the field names
the API actually sends, and escape their output. It does **not** prove visual
layout, Bootstrap's own JavaScript (accordions, the mobile nav toggle), touch
behaviour, or rendering on a real phone. Test in a browser before launch.

## Known inconsistency: the admin order list exposes `id`

`GET /admin/orders` returns the internal numeric `id` alongside `uuid`. The
platform's stated rule is that `id` is never exposed and everything is addressed
by `uuid`. It is a staff-only endpoint so the exposure is limited, but it is an
inconsistency rather than a decision, and the console deliberately does not use
the field.

## Buy X get Y

Implemented in migration 010. "Buy 1 get 1", "buy 2 get 1", "buy 1 get 5" and
anything else of that shape, created from the console under **Promotions →
Create an offer**.

**The free item is expressed as a discount, not as a zero-priced line.** Three
reasons, and the first is decisive:

1. **GST.** Indian MRP is tax-inclusive and tax is *extracted*. A zero-priced
   line has no taxable value, so the tax on the units actually paid for would be
   computed against the wrong base. As an order discount it reuses the
   apportionment that already handles coupons correctly, and the tax stays right
   by construction.
2. **Refunds.** A free line has no money attached, so a partial refund would have
   to decide what a zero-priced item is worth.
3. **Everything downstream.** A new line type would need handling in the cart,
   the order, the invoice, the courier weight calculation and the packing slip.

The customer still sees "1 free" — that comes from the offer title and the
disclosed benefit, not from the line structure.

Two choices worth understanding when creating one:

- **Which items are free.** `cheapest_eligible` (the default) discounts the
  cheapest units in the basket; `same_variant` requires the free units to be the
  same pack that was bought. Giving away the dearest item on a mixed basket costs
  far more than intended, which is why every large retailer discounts the
  cheapest.
- **A per-order cap.** Without one, "buy 1 get 1" on a fifty-unit wholesale order
  gives away twenty-five units. The cap is disclosed in the benefit text rather
  than silently reducing it.

`bin/test_bogo.php` covers the arithmetic without a database — 35 assertions,
weighted towards the boundaries, because that is where money is given away by
accident. Three items on "buy 1 get 1" earns one free, not one and a half.

`combo` remains an unimplemented enum value.

## Completing a payment while testing

With `PAYMENT_DRIVER=sandbox` the UPI intent points at a VPA that does not
exist, so no real app can pay it. The order sits on "Waiting for your payment to
be confirmed" forever.

```bash
php bin/sandbox_track.php --list                 # shipments in transit
php bin/sandbox_track.php SDF2627000002          # advance one scan
php bin/sandbox_track.php SDF2627000002 --deliver # straight to delivered
php bin/sandbox_track.php SDF2627000002 --fail    # a failed delivery attempt
```

Without it an order stops at "Handed to courier" forever, because nothing in
sandbox mode ever sends a tracking scan — so delivery, commission accrual and
the review invitation are unreachable while testing.

```bash
php bin/sandbox_pay.php --list                  # orders awaiting payment
php bin/sandbox_pay.php SDF2627000002           # pay one
php bin/sandbox_pay.php SDF2627000002 --fail    # fail one
```

It posts the same signed webhook the real gateway sends, through the real
endpoint. Nothing is written to the database directly, so what gets exercised is
the production confirmation path — signature verification, idempotency, invoice
numbering, referral payout and notification queueing. Paying twice is a no-op,
which is the duplicate-webhook guard doing its job.

It refuses to run unless `APP_ENV` is local or testing **and** the payment driver
is the sandbox. A tool that marks orders paid must not exist on a production
host.

## Seeing the review form while testing

The form only appears for a customer with a **delivered order containing that
product**. That means the shop owner, signed in as staff, will never see it — and
"only customers who have bought this can review it" reads like a broken feature
rather than a rule working correctly. Staff now get a notice saying so explicitly.

To see it, walk one order the whole way:

```bash
# 1. Register in the shop with a spare mobile number, order something, pay.
php bin/sandbox_pay.php SDF...          # settle the payment
# 2. Console -> Orders -> open it -> Mark as packed -> Book a courier
php bin/sandbox_track.php SDF... --deliver
```

Then open the product as that customer. The form is under the reviews list.

## Campaign pages

A shop owner builds a page for a season, curates products onto it, and points an
advert at it. Console → **Shopfront → Campaign pages**.

**Four fixed templates, not a page builder.** A general builder produces pages
nobody can be held to and lets a campaign drift into looking like a different
website. These four — grid, spotlight, story, gift — are designed once and share
the shop's own styling.

**Cards read the catalogue live rather than copying prices in at curation time.**
A campaign page quietly showing a stale price is a consumer-protection problem,
not a cosmetic one. One query per product; the right trade for a curated page of
a dozen items, and an obvious place to batch later.

Two guards worth knowing:

- **Publishing refuses an empty page.** An advert pointing at a live page with
  nothing on it makes the shop look broken.
- **Campaigns have dates.** A Diwali page still live in January is the mistake
  this feature will actually produce, so the console shows "live" separately from
  "published" and marks expired pages.

## Importing on shared hosting (Hostinger, cPanel, MariaDB)

`database/spice_commerce_full.sql` is the whole schema and seed data in one
file, for hosts where you import through phpMyAdmin rather than run a migration
runner. Create an empty utf8mb4 database and import it.

**Verified by importing into both engines**, not assumed:

| | MariaDB 10.11 | MySQL 8 |
|---|---|---|
| Tables | 76 | 76 |
| Views | 18 | 18 |
| Foreign keys | 112 | 112 |

The application was then run against the MariaDB database — health check passes,
products list with correct prices, categories resolve.

**Two differences from the migration set, both established by testing:**

**1. Five CHECK constraints are absent.**

The rule, established by testing rather than assumed:

> A CHECK constraint that references a column also used in a FOREIGN KEY with a
> cascading action is rejected. MySQL 8 accepts it **inline** but refuses it via
> `ALTER TABLE` (error 3823). MariaDB refuses it either way (error 1901).

Since MariaDB is what most shared hosts run, those five come out:

| Constraint | Was guarding |
|---|---|
| `chk_coupons_specific_user` | a customer-specific coupon must name the customer |
| `chk_coupon_targets_one_reference` | a coupon target is a category or a product, not both |
| `chk_offer_targets_one_reference` | same, for offers |
| `chk_referrals_not_self` | nobody refers themselves |
| `chk_staff_not_own_manager` | nobody manages themselves |

**2. The `active_owner_guest` generated column is absent.**

MariaDB rejects a STORED generated column whose expression is *conditional and
returns a string* — `IF()`, `CASE`, `CAST` and an explicit `COLLATE` were all
refused with error 1901. The equivalent BIGINT column (`active_owner_user`) is
accepted and stays.

Nothing is lost. That column existed to enforce "at most one active cart per
guest token", and `uq_carts_guest_token` is unique on the token across *every*
row — strictly stronger. Verified on MariaDB: a second cart for the same guest
token is refused with 1062. No application code referenced the column.

The other **58 CHECK constraints, all 112 foreign keys, 76 tables and 18 views
survive intact**, and the application enforces all five rules itself. What is
lost is a second line of defence against a hand-written `UPDATE`.

Verified by importing into an empty database and then running the real
application against it: products list, categories resolve, `seed_admin.php`
creates an account.

## Deployment layouts

The application works both as the document root and mounted in a subdirectory.
Both are verified.

| Layout | API base for `web/assets/js/config.js` |
|---|---|
| `backend/public` is the document root | `/api/v1` |
| `htdocs/spice-api/public` under XAMPP | `/spice-api/public/api/v1` |
| API on a separate host or port | `http://host:port/api/v1` (needs CORS) |

Subdirectory support required two fixes, both found by a user deploying to a
real XAMPP install rather than by any test here:

- `public/.htaccess` hardcoded `RewriteBase /`, which rewrote to `/index.php` at
  the site root instead of the one beside it. Removing the directive entirely
  makes mod_rewrite resolve relative to the `.htaccess` location, which is
  correct in both layouts.
- `Request::fromGlobals()` matched routes against the full `REQUEST_URI`, so a
  request for `/spice-api/public/api/v1/health` found no matching route. The
  mount prefix is now derived from `SCRIPT_NAME` and stripped. Derived rather
  than configured: a base path set by hand is one more thing to get wrong on
  deployment, and it fails silently.

## Deployment requirements found by running under Apache

- **`backend/storage` must be writable by the web server user.** Without it every
  request that logs fails.
- **`AllowOverride All`** on the `public` directory, or `.htaccess` is ignored and
  every route 404s.
- `mod_rewrite` must be enabled.

## What has NOT been verified

Being explicit about this, because the gap matters.

**Concurrency (partially closed — see above).** `php -S` is single-threaded, so no test here exercises parallel
requests. Three specific claims remain unproven by execution:

1. `CouponRepository::claimUsage()` — the atomic `UPDATE … WHERE total_redeemed <
   total_usage_limit`, which is meant to stop two customers both taking the last
   available use.
2. `WalletRepository::lockAccountForUpdate()` — the `SELECT … FOR UPDATE` that is
   meant to stop two concurrent redemptions spending the same credit twice.
3. The unique index over `carts.active_owner_user`, which is meant to stop two
   simultaneous add-to-cart requests creating two carts.

All three are correct by construction — the guard is in a single SQL statement or
a row lock, not in PHP — but that is reasoning, not evidence. Proving them needs
parallel load against Apache with mod_php or nginx with php-fpm. Worth doing
before go-live; it is the one area where I would not take my own word for it.

**Load and performance.** No throughput testing at all. The SRS asks for 10,000
concurrent users and 1,000 orders an hour; nothing here demonstrates that.

**Courier integrations.** `COURIER_DRIVER=sandbox` books and tracks locally with
the same signature construction a real courier uses. The Shiprocket HTTP calls
have never run against the live API, and the pincode serviceability and rate
cards in the seed are plausible market figures, **not quotes** — they must be
replaced with the merchant's negotiated contract before go-live, or every cost
comparison BR-007 makes is comparing fiction.

**Notification delivery.** Every channel runs on the log driver in local and
testing. The queue, dedupe, retry, opt-out, quiet-hour deferral and scheduler
locking are all genuinely exercised; what has never run is an actual SMS, email,
WhatsApp or push send. Email, WhatsApp and push have **no provider implemented
at all** — they log rather than send, which is visible in the queue rather than
silent, but they are not production paths.

**Indian SMS requires DLT registration.** Every template must be registered on
the operator's platform and its `provider_template_id` filled in before messages
will be delivered; unregistered content is dropped silently by the DLT platform,
which is maddening to debug. The seeded templates leave that column blank.

**Review and CMS content is placeholder.** The seeded policy pages — shipping,
returns, privacy, terms — have real structure and are marked `is_system_page` so
they cannot be deleted, but the wording is a placeholder. A returns policy is a
contract with the customer and Indian consumer law has specific disclosure
requirements for food sellers; these must be reviewed by someone qualified
before go-live.

**Response payload shapes are not in the OpenAPI document.** Paths, parameters,
request schemas, authentication, roles and rate limits are all derived from the
code and are accurate. The `data` field of each response is not: PHP arrays carry
no type information a generator can read, and inventing plausible schemas would
leave a client author worse off than reading an example. This is a real gap for
anyone generating a typed client.

**171 of 210 operations use a derived summary** rather than a written one — the
action name humanised, because those controllers carry only a route annotation
in their docblock. The high-traffic customer flows have real descriptions.

**Real integrations.** The SMS driver is `log`. The payment gateway is `sandbox`,
which signs and verifies with the same HMAC construction Razorpay uses but
settles locally — the Razorpay HTTP calls themselves have never run against the
live API. No courier API yet (Phase 6).

**The `sandbox` driver refuses to construct unless `APP_ENV` is local or
testing**, so pointing production at it fails at boot rather than silently
accepting orders nobody paid for.

**Production web server.** Only PHP's built-in development server has been used.
`public/.htaccess` has never been exercised by Apache.

---

## Bugs this process found

Recorded because they show what execution catches that review does not. All six
are fixed, and four are now caught by `bin/audit_consistency.php`.

| Bug | Consequence | Now caught by |
|---|---|---|
| Cart routes had no auth middleware, so `authUserId()` was always null | **A signed-in customer was treated as a guest on every cart operation.** Coupons refused, wallet credit invisible | audit check 9 |
| Migration 004's three new `carts` columns were missing from `CartRepository::fillable()` | Coupon and wallet writes **silently discarded** — no error anywhere | audit check 5 |
| 8 foreign keys used `ON UPDATE CASCADE` on columns named in CHECK constraints | Migration 003 would not apply (MySQL error 3823) | audit check 6 |
| `fk_carts_user` used `ON DELETE CASCADE` on the base column of a STORED generated column | Migration 003 would not apply | audit check 7 |
| Coupon creation wrote `null` into NOT NULL columns that have defaults | Every admin coupon creation was a 500 | — |
| A 150% coupon reached the database CHECK instead of validation | Opaque 500 instead of a 422 naming the field | — |
| Mobile validation stripped a leading `91` unconditionally | **Every Indian number beginning with 91 was rejected as invalid** — roughly 1% of customers locked out of registration, login, OTP and password reset, while being told their own number was wrong | regression test in `smoke_test.php` |
| A rejected webhook consumed the idempotency key of the genuine one | **A single forged webhook with a guessable payment id stopped the real payment confirming that order.** Money taken, order stuck at `awaiting_payment` forever | `smoke_test_checkout.php` sends a bad signature before the good one |
| `availableTransitions()` offered fulfilment statuses to customers | A customer's order page would render a "Mark as packed" button the API then refused | `test_orders.php` |
| `PaymentService` transitioned status without consulting the state machine | The one place BR-005 is enforced was bypassed by the one service that most needed it | `test_orders.php` |
| Rolling back migration 005 left orphaned `carts.converted_order_id` values | The rollback appeared to succeed, then re-applying 005 failed with error 1452. A rollback that cannot be followed by a re-apply is not a rollback | executed rollback → re-apply cycle |
| An INSERT bound 12 of its 13 placeholders | Pickup scheduling failed with `HY093 Invalid parameter number`, but only on the one path that ran it | audit check 1b |
| Re-enabling a courier left its old `disabled_reason` in place | The console showed a live courier as "under renegotiation", and the next disable slipped through unexplained because the guard saw a stale reason | `smoke_test_delivery.php`, run twice |
| `bin/scheduler.php` built the container before loading `.env` | Every cron run died at boot: `APP_ENV` read as `production`, so the sandbox payment gateway's safety guard refused to construct. The guard working correctly, on a script that had never been run | executing the CLI |
| Naming a task still honoured its `next_run_date` | An operator asking to run one task during an incident got silence. The lock is about safety; the schedule is about timing, and only the first should apply to a manual run | `smoke_test_notifications.php` |
| `RewriteBase /` hardcoded in `public/.htaccess` | Every API route 404'd when the backend was installed in a subdirectory — the standard XAMPP and shared-hosting layout | user deployment; now verified in both layouts |
| Routes matched against the full `REQUEST_URI` | Same symptom, second cause: the mount prefix was never stripped | user deployment |
| The storefront sat on "Loading…" forever when the API was unreachable | A network failure and an API error were handled identically, so a misconfigured base URL produced a blank page with no explanation | user screenshot |
| **The guest cart was never merged on sign-in** | `/cart/merge` exists and the client never called it. A visitor built a cart anonymously, signed in, and the items vanished — still in the database, orphaned | reported by the user |
| Every page navigation spent a refresh token | The access token lived only in memory, so each page load refreshed — and refresh tokens rotate, with 30 allowed per 10 minutes. Browsing the shop normally got people locked out of their own account with "Too many attempts". The token is now kept in `sessionStorage` for the tab | reported by the user |
| Pages queried the API before the session was restored | Fixed twice before it was fixed properly. Patching `mountChrome` cured the header and left the same race in nine page modules — the cart page then showed empty beside a header counting four. The session restore is now a shared promise awaited inside `request()` itself, so no page can get it wrong | reported by the user, twice |
| The header queried the cart before the session was restored | `mountChrome` filled the badge from an unauthenticated `GET /cart`, which returns the GUEST cart, while the page itself had signed in and read the account's. Hence "Cart 3" beside "Your cart is empty" — two honest numbers describing different carts | reported by the user |
| The missing-pincode message disabled the Checkout button | It is not a real blocker: choosing an address at checkout supplies the pincode and the server then reports `is_ready`. Treating every blocker as fatal stranded customers on a cart they could legitimately have ordered from | reported by the user |
| **Every price on the storefront read ₹0.00** | The API nests these — `pricing.min_price`, `rating.average`, `category.name` — and the client read flat names. `formatMoney(undefined)` returns ₹0.00, so the markup looked well-formed, nothing threw, and the "no undefined values" assertion passed. Tests now assert prices are non-zero | reported by the user |
| Product images rendered as `[object Object]` | `primary_image` is an object `{url, alt_text}`, not a string | reported by the user |
| The cart announced "Enter a delivery pincode" with no input anywhere | A blocker the customer cannot act on is a dead end — checkout was unreachable | reported by the user |
| Coupons could be listed and paused but never created | The console had no coupon form at all | reported by the user |
| **Customers had no way to write a review** | The moderation queue existed in the console and the API accepted submissions, but the shop offered no form anywhere. The whole review system was write-only from the merchant's side | reported by the user |
| **Adverts displayed but clicking did nothing** | The public payload nests the link as `link: { type, value }`; the admin endpoints use flat `link_type` / `link_value`. The client read the flat names, got undefined, and rendered no anchor — silently, because a banner without a link is legitimate | reported by the user |
| `collection` was missing from the banner validator | Added to the database enum and the console dropdown but not the controller's `in:` rule, so campaign-page adverts were rejected with a 422 | reported by the user |
| Only the banner text was wrapped in the link | A banner is mostly image, so the part everyone actually clicks was not clickable | found fixing the above |
| `BaseRepository::update()` sets `updated_by` itself | Passing it in the attributes too produced a duplicate `:updated_by` placeholder and a bare "Invalid parameter number". The actor is the third argument | building collections |
| A swallowed `catch (\Throwable)` hid a wrong array key | Campaign cards silently fell back to name-only for a full round of testing because `detail()` returns the product, not `['product' => ...]`. The fallback now logs why | building collections |
| `OfferRepository::fillable()` missed all four new BOGO columns | Writes would have been silently discarded, so every offer would have been created without its quantities. Caught by audit check 5 — the same check written after this exact bug in Phase 4 | `bin/audit_consistency.php` |
| The console had no way to ADD a product | A merchant could publish and unpublish, but not list their own goods without calling the API by hand. The catalogue was effectively read-only | building the product editor |
| The product editor assumed a two-step create | The API creates a product and its first pack size atomically — so a product can never exist with no weight and no price. The form contradicted that and failed on submit; the API's rule was right and the form now follows it | `test_console.mjs` |
| `public/uploads` was not writable by the web server | Image upload failed with "Permission denied" after the merchant had filled in a whole form. Now checked by `setup.php` alongside `storage/` | `test_console.mjs` |
| `setup.php` chmod'd `.env` to 0640, locking out the web server | When setup runs as a different user from PHP-FPM or Apache, the application could no longer read its own credentials and fell back to the template defaults — the symptom being a health check citing a username nobody configured | found running the console tests |
| The storefront refreshed its token twice under concurrent 401s | Six requests fired together do not get their 401s at the same instant: the first triggers a refresh, that refresh completes and clears the in-flight flag, and the later 401s start another. Fixed with a token generation counter — a request that finds the generation has moved just retries | `web/test/test_storefront.mjs` |
| A logger that could not write its file killed the whole response | The exception handler called the logger, the logger threw, and the client received a **completely empty body** — no JSON, no envelope. A full disk would have turned every error in the system into an unparseable blank | found under Apache; the logger now falls back to `error_log()` and never throws |
| Losing the cart-creation race returned a 500 | A customer double-tapping "Add to cart" saw an error for something that worked | `test_concurrency.php` |
| The OpenAPI summary extractor matched from the *class* docblock | Every operation summary contained the class comment plus every line of code down to the method. `preg_match` starts at the leftmost `/**`, so a non-greedy `.*?` does not mean "the nearest one" | `test_openapi.php` |
| Wholesale enquiry submission had no `auth.optional` | A signed-in customer's enquiry was recorded as a guest's, so they could never view their own quotation — the same failure as the Phase 4 cart bug | audit check 9 |
| Two queries used column names that do not exist (`v.name`, `total_referrals`) | 500 on quote creation and on the promotions report | `smoke_test_bulk.php` |
| `Validator` cast arrays to the string `"Array"` for `min`/`max` | `required\|array\|min:1` — a completely natural rule — raised a conversion warning and 500'd the request instead of validating it | audit check 1d and `smoke_test_staff.php` |
| Four controllers used a validation rule named `integer`, which does not exist | Every one of those endpoints threw on first use. Two had been sitting in Phase 6 unexercised | audit check 1d |
| An order could not go from `shipped` to `delivered` without an intermediate scan | Hyperlocal couriers deliver with no out-for-delivery scan, so those orders sat at `shipped` forever: no completion, no commission, and a customer holding a parcel the site called in transit | `smoke_test_staff.php` |
| The migration runner could not parse `DELIMITER` blocks | Multi-statement triggers were impossible, so Phase 4's guards had to be written without conditional logic | fixed in `bin/migrate.php` |
| A manual courier override dereferenced a null selection | 500 on every hand-picked courier; the automatic path was fine, so it would have shipped | `smoke_test_delivery.php` |
| `reset_rate_limits.php` passed a `Config` object where an array was required | The helper crashed silently when its output was redirected, so the throttle was never cleared | — |
| Mobile validation stripped a leading `91` unconditionally | **Every Indian number beginning with 91 was rejected as invalid** — roughly 1% of customers locked out of registration, login, OTP and password reset, while being told their own number was wrong | regression test in `smoke_test.php` |
| `reset_rate_limits.php` passed a `Config` object where an array was required | The helper crashed silently when its output was redirected, so the throttle was never cleared | — |

The mobile bug is the one worth dwelling on. It surfaced only because a random
test value happened to begin with 91, and it had passed review and 19 green
assertions beforehand. Roughly one Indian mobile number in a hundred starts with
91, so about 1% of customers could never have registered, logged in, received an
OTP or reset a password — and the error message would have insisted their own
phone number was invalid. There is now a regression test that registers a
91-prefixed number and confirms the same number with a country code is recognised
as a duplicate rather than creating a second account.

Two of these are worth dwelling on.

The **mobile bug** surfaced only because a random test value happened to begin
with 91, after the code had passed review and 19 green assertions. Roughly one
Indian mobile in a hundred starts with 91, so about 1% of customers could never
have registered, logged in, received an OTP or reset a password — and the error
message would have insisted their own phone number was invalid.

The **webhook idempotency bug** is the most serious found so far. The rejected
webhook and the genuine one carried identical payloads; only the signature
differed. Both derived the same event id, so the forgery was recorded first and
the real gateway callback that followed was silently discarded as a duplicate.
An attacker needed one forged request with a guessable payment id to ensure a
paying customer's order never confirmed. It was found because the smoke test
deliberately sends a badly signed webhook *before* the good one — testing the
rejection alone would have passed.

Two defects were also found **in the audit script itself** while verifying it
against deliberately reintroduced bugs: it scanned only single-quoted SQL strings
(missing most queries in this codebase, which use double quotes because they
contain literals like `'active'`), and its referential-action regex greedily
consumed the following `ON`, hiding every `ON DELETE` clause. A check that never
fires proves nothing — verify new checks against a broken copy.

Separately, five wrong expectations were found in the test suites themselves:
four where a subtotal was hand-computed from MRP instead of the selling price,
and one that compared a combined coupon-plus-offer discount against the coupon
alone. In each case the engine was right and the test was wrong.
