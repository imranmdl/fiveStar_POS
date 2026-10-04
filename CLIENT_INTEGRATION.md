# Client Integration Guide

For the people building the web, Android and iOS clients.

The OpenAPI document at `docs/openapi.json` describes every endpoint, its
parameters and its request schema. This guide covers what a specification
cannot: the sequences, the failure modes, and the handful of rules that will
otherwise be discovered the hard way.

Browse the spec at `/docs/` on a running server, or load `docs/openapi.json`
into any OpenAPI tool.

---

## The response envelope

Every response has the same shape, including errors.

```json
{
  "success": true,
  "message": "Order placed",
  "data": { },
  "errors": [],
  "meta": { "page": 1, "per_page": 20, "total": 43, "total_pages": 3 }
}
```

`message` is always safe to show a customer. Technical detail goes to the server
log, never into this field. On failure, `errors` becomes an object mapping field
names to a list of problems:

```json
{
  "success": false,
  "message": "This order cannot be placed yet.",
  "data": [],
  "errors": {
    "checkout": ["Add a delivery address to continue."]
  }
}
```

Show `message` as the headline and attach `errors` to the relevant fields. Do
not concatenate them into one string — that is how a user ends up with a wall of
red text and no idea which box to fix.

`meta` is present only on paginated responses.

---

## Authentication

### Getting tokens

```
POST /api/v1/auth/register        → OTP challenge (account not yet usable)
POST /api/v1/auth/register/verify → access + refresh tokens
POST /api/v1/auth/login           → access + refresh tokens
POST /api/v1/auth/login/otp       → access + refresh tokens
```

Send the access token as `Authorization: Bearer <token>` on every authenticated
request.

### Refresh tokens rotate

This is the rule most likely to catch a client out.

When the access token expires, exchange the refresh token at
`POST /api/v1/auth/token/refresh`. **The server issues a new refresh token and
invalidates the old one.** Store the new one immediately.

If an already-used refresh token is presented, the server treats it as a stolen
token and **revokes every session for that account**. That is deliberate: the
legitimate client and an attacker cannot both hold the same refresh token
innocently.

The practical consequences for a mobile client:

- **Serialise refresh.** Two screens refreshing concurrently means one of them
  presents a token the other has already spent, and the customer is signed out
  of everything. Use a single-flight lock: the first 401 triggers a refresh, and
  every other in-flight request waits for its result.
- **Persist the new token before retrying** the original request. If the app is
  killed between refreshing and saving, the customer is logged out.
- **A 401 after a successful refresh means stop.** Do not loop.

### Sessions end

`POST /api/v1/auth/logout` revokes the current session. Discard both tokens.

---

## Rate limits

Several endpoints are throttled per IP or per account — registration, OTP
requests, order placement, enquiry submission. The spec states the limit on each
operation.

A throttled request returns **429** with a message saying when to retry. Show
that message; do not retry automatically on a 429, because the limits exist to
stop exactly that.

---

## The order flow

The single most important sequence in the API. Every step is required.

```
1. GET  /api/v1/checkout/review
       Addresses, priced cart, tender split, and `checkout.blockers`.
       If `blockers` is non-empty, show them and stop — placement will fail.

2. POST /api/v1/checkout/place
       Send `expected_grand_total` from step 1.
       → 201 with the order and an OTP challenge
       → 409 if the total moved. The response carries the NEW total and what
         changed. Re-display and ask again. Never place with the old figure.

3. POST /api/v1/checkout/orders/{uuid}/verify-otp
       Required before payment. An unverified order cannot be confirmed.

4. POST /api/v1/checkout/orders/{uuid}/payment
       → UPI intent URL, QR payload, and the amount.
       Open the intent URL or render the QR.

5. POST /api/v1/checkout/orders/{uuid}/payment/callback   (optional)
       Report what the UPI app said. This is a HINT, not proof.
```

### Payment confirmation is not the client's job

Step 5 exists so the client can show a result promptly. It is **not** what
confirms the order.

The server confirms an order when it receives a signature-verified webhook from
the payment gateway. That happens whether or not the customer's app is still
open. A client that treats its own callback as authoritative will show
"confirmed" for a payment that never settled.

**Poll the order after payment** rather than trusting your own callback:

```
GET /api/v1/orders/{uuid}
```

Watch `payment_status`. It moves `pending → paid`, and `status` moves to
`confirmed`. Poll every two seconds for about thirty seconds, then fall back to
"we are confirming your payment" and let a push notification finish the job.

If the customer closes the app mid-payment, the order still confirms. Do not
cancel it client-side.

### The payment window

An unpaid order carries `expires_date`. After that it is cancelled
automatically, its coupon is released and any wallet credit is returned. Show
the remaining time; do not let a customer sit on a payment screen for an hour
and then fail.

---

## Guests and carts

An anonymous visitor gets a real cart. Send the `X-Cart-Token` returned by the
first cart response on every subsequent cart request.

On sign-in, the guest cart is **merged** into the account's cart automatically.
Discard the guest token afterwards.

Cart endpoints accept optional authentication: send the bearer token when you
have one. A signed-in customer whose token is omitted is treated as a guest,
loses their wallet balance and cannot apply coupons.

---

## Prices, money and tax

- Money is returned as a decimal number of rupees: `1234.50`.
- **Never do money arithmetic in floating point.** Parse to a minor-unit integer
  (paise) or a decimal type. `0.1 + 0.2` is the classic way to owe a customer a
  paisa.
- Indian MRP is **GST-inclusive**. The tax shown has been extracted from the
  price, not added to it. Displaying "+ GST" is wrong.
- `grand_total` is the order value. `wallet_applied` is a tender against it, and
  `amount_payable` is what the gateway will charge. `grand_total = wallet_applied
  + amount_payable`, always.

---

## Identifiers

The API never exposes numeric database ids. Everything is addressed by `uuid`,
or by `slug` for catalogue content. Treat both as opaque strings.

A resource that exists but belongs to someone else returns **404**, not 403, so
that identifiers cannot be probed. Do not infer existence from the status code.

---

## Idempotency

Several operations are safe to repeat and will not duplicate work:

- Payment webhooks, deduplicated by gateway event id.
- Order confirmation, guarded by the order row lock.
- Wallet credits, by caller-supplied idempotency key.
- Notifications, by dedupe key.

Retrying a network failure is therefore safe on these paths. **Order placement is
not idempotent** — a retried `POST /checkout/place` creates a second order.
Disable the button on first tap and wait for the response.

---

## Pagination

```
GET /api/v1/orders?page=2&per_page=20
```

`per_page` is capped per endpoint; the effective value comes back in `meta`.
Trust `meta.total_pages` rather than assuming a short page means the end.

---

## Errors worth handling specially

| Status | Meaning | What the client should do |
|---|---|---|
| 401 | Token missing, expired or invalid | Refresh once, then sign out |
| 403 | Authenticated but not permitted | Show the message; do not retry |
| 404 | No such resource, or not yours | Navigate away; do not probe |
| 409 | Conflict with current state | **Read the message.** Stale total, already paid, cannot cancel — each needs different handling |
| 422 | Validation failed | Attach `errors` to the relevant fields |
| 429 | Rate limited | Show when to retry; do not retry automatically |
| 502 | A third party failed | Offer a retry; the order is unaffected |

409 is the one that carries the most meaning. It is used for every "the world
changed under you" case, and the message is written to be shown as-is.

---

## Notifications

Customers receive order updates by SMS automatically. They can opt out of
**promotional** messages only:

```
GET   /api/v1/notifications/preferences
PATCH /api/v1/notifications/preferences   { "sms": false }
```

Transactional messages — OTPs, payment receipts, dispatch notices — are always
sent and cannot be disabled. If a client offers a blanket "turn off all
notifications" switch, it is lying to the customer.

---

## Things the specification does not describe

**Response payload shapes.** The `data` field varies per endpoint and is not
derived, because PHP arrays carry no type information a generator can read.
Inventing plausible schemas would leave you worse off than reading an example.
Per-module documentation in `docs/API_*.md` covers the shapes; a live call
against a development server is the fastest way to see one.

**Which fields are optional in a response.** Assume any field may be `null` and
code defensively. This is a real gap, not a stylistic choice.

---

## Before you start building

1. Run the backend locally (see `TESTING.md`) with `PAYMENT_DRIVER=sandbox` and
   `COURIER_DRIVER=sandbox`. Both settle deterministically without credentials.
2. Set `OTP_EXPOSE_IN_RESPONSE=true` so registration and order OTPs come back in
   the response and you are not waiting on SMS.
3. Read `bin/smoke_test_checkout.php`. It is a complete, working client for the
   whole order flow in about 400 lines, including the failure cases. Anything
   ambiguous in this guide is answered concretely there.
