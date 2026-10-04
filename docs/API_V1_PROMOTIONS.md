# API v1 — Coupons, Offers, Wallet & Referrals

Base URL: `{APP_URL}/api/v1`. Same envelope and status codes as
[API_V1_AUTH.md](API_V1_AUTH.md).

---

## The two rules that shape this whole module

### 1. Wallet credit is a payment tender, not a discount

This is the single most important thing to understand before writing a client.

A **coupon** or an **offer** is a discount: it reduces the transaction value, so
GST falls with it. A ₹100 coupon on a ₹1,000 order means you sold goods for ₹900
and owe tax on ₹900.

**Wallet credit is not a discount.** It is money the customer already holds,
being used to pay. The order is still worth ₹1,000 and you still owe tax on
₹1,000 — you just collect ₹950 online and ₹50 from their balance.

So the cart response separates them:

```json
"pricing": { "summary": { "grand_total": 888.30, "tax_total": 66.36 } },
"payment": {
  "grand_total":     888.30,
  "wallet_applied":   50.00,
  "amount_payable":  838.30
}
```

`grand_total` and `tax_total` do not move when wallet credit is applied. Only
`amount_payable` does. Charge the customer `payment.amount_payable`; put
`pricing.summary.grand_total` on the invoice.

Modelling wallet credit as a discount would understate GST on every order it
touched. That is a tax problem, not a rounding problem.

### 2. The stacking rules, in full

All of them live in `App\Services\Promotions\PromotionResolver`. Nothing else in
the codebase decides how discounts combine.

1. **At most one coupon per order.** Customer-entered, explicit.
2. **At most one automatic offer.** If several apply, the one worth most to the
   customer wins; ties break on the offer's `priority`.
3. **Coupon + offer only if both are marked stackable.** When they are not and
   both apply, the larger single discount wins **and the customer is told which
   one and why**. Silently dropping a coupon they just typed is the worst
   outcome — they assume the site is broken.
4. **Wallet credit stacks on top of everything**, capped at a percentage of the
   order value (20% by default).
5. **Order-scoped and delivery-scoped discounts do not compete.** A free-delivery
   coupon and a percentage-off offer reduce different things, so both can stand.

Changing these rules means changing that one file.

---

## Reading the promotions block

Every cart response carries `promotions`:

```json
"promotions": {
  "applied_coupon": {
    "code": "WELCOME10",
    "title": "Welcome offer: 10% off",
    "discount_amount": 98.70,
    "scope": "order"
  },
  "applied_offer": {
    "code": "DRYFRUITWEEK",
    "title": "Dry Fruit Week",
    "discount_amount": 40.00,
    "is_automatic": true
  },
  "rejected": [
    { "type": "coupon", "code": "SPICE50", "reason": "Not combinable with Dry Fruit Week, which saves you more." }
  ],
  "messages": [
    "Dry Fruit Week applied: ₹40.00 off.",
    "Coupon WELCOME10 applied: ₹98.70 off."
  ],
  "total_promotion_discount": 138.70
}
```

**Render `messages` verbatim.** They are the explanation of what the totals did,
and they are written to be shown to a customer.

`rejected` matters just as much: a coupon that was valid when applied can stop
being valid as the cart changes. Reading the cart never fails because of that —
it reports it.

---

## Coupons

### GET /cart/coupons *(authenticated)*

Coupons the customer could use, each annotated against the current cart:

```json
{
  "code": "SPICE50",
  "title": "Flat ₹50 off spices",
  "summary": "₹50.00 off",
  "terms": "Applies to the Spices category only…",
  "is_applicable": false,
  "estimated_saving": 0,
  "reason": "This code needs a minimum order of ₹499.00. Add ₹120.00 more to use it."
}
```

Non-applicable coupons are returned deliberately, with the reason. "Add ₹120 more
for ₹50 off" converts; hiding the coupon does not. Applicable coupons sort first,
then by what they would actually save.

### POST /cart/coupon *(authenticated)*

```json
{ "coupon_code": "WELCOME10" }
```

Validated at the moment it is typed, so an unusable code is refused here rather
than accepted and silently ignored at checkout. Only the coupon reference is
stored — the discount is recomputed on every read, because the cart it applies to
keeps changing.

Coupons require sign-in (**401** for guests): per-customer limits and
new-customer audiences are meaningless without an account.

Rejection reasons are specific and quotable:

| Situation | Status | Message |
|---|---|---|
| Unknown code | 404 | The code X does not exist. |
| Private code owned by someone else | 404 | *Same message as unknown* — see below |
| Expired | 422 | This code has expired. |
| Not yet started | 422 | This code is valid from 12 Aug 2026. |
| Global limit reached | 422 | This code has reached its usage limit. |
| Customer limit reached | 422 | You have already used this code. |
| First-order only | 422 | This code is only valid on a first order. |
| Below minimum | 422 | This code needs a minimum order of ₹499.00. Add ₹120.00 more to use it. |
| Nothing eligible | 422 | This code applies to Spices only. |
| Free delivery, already free | 422 | Delivery is already free on this order… |

A private (`specific_customer`) coupon belonging to another customer returns the
*same* message as a code that does not exist. Revealing that a private code
exists but is not theirs invites probing.

### DELETE /cart/coupon *(authenticated)*

**422** if no coupon is applied.

---

## Offers

Offers are merchandising campaigns, distinct from the per-variant `offer_price`
in the catalog. That is a price on one pack size; an offer is a named, dated
campaign that groups products for listing pages and can optionally discount a
whole cart with no code typed.

| Method | Path | Notes |
|---|---|---|
| GET | `/offers?type=deal_of_day` | Live campaigns; `type` optional |
| GET | `/offers/{code}` | One campaign |
| GET | `/offers/{code}/products` | Paginated products in the campaign |

Automatic discounts are applied silently, which makes them the most dangerous
thing in this module: an offer nobody remembers configuring quietly erodes margin
for as long as its window lasts. So activation refuses an offer that has no end
date, an uncapped percentage, or a scope with no targets selected.

---

## Wallet

| Method | Path | Notes |
|---|---|---|
| GET | `/wallet` | Balance, lifetime totals, redemption rules |
| GET | `/wallet/statement` | Paginated ledger with a running balance per entry |
| POST | `/cart/wallet` | `{"amount": 50}` — send `0` to clear |

An over-cap request is **clamped, not rejected**, with an explanation in
`payment.wallet.message`. A customer asking for more credit than the rules allow
should get what the rules allow.

### The ledger is append-only

Enforced by database triggers, not application discipline: any UPDATE or DELETE
on `wallet_transactions` is refused by MySQL itself. A future maintenance script
or console session cannot "fix" a wallet row.

Corrections are **compensating entries**. To recover credit, an administrator
posts a debit; both entries stay visible side by side. That is what makes the
ledger answerable when a customer disputes a balance.

`wallet_accounts.balance_amount` is a cache maintained inside the same
transaction as every entry, guarded by `SELECT … FOR UPDATE`. The ledger is the
authority — `GET /admin/wallet/{userUuid}` re-derives the balance from it and
reports any drift under `integrity`.

### Credits are idempotent

Every credit carries a caller-supplied `idempotency_key` with a UNIQUE index
behind it. A retried referral payout, a redelivered webhook or a double-clicked
admin form credits once and returns the original entry.

---

## Referrals

A referral **pays out only after the referee completes a qualifying paid order**.
Rewarding at signup is an open invitation to farm accounts, and by the time you
notice, the wallet liability is real money.

```
pending → qualified → rewarded
                   ↘ cancelled
```

| Method | Path | Notes |
|---|---|---|
| GET | `/referrals` | Code, share URL, share message, reward amounts, progress, terms |
| GET | `/referrals/history` | Friends invited, with status labels |

The referrer sees only each friend's **first name and masked mobile**. They do not
need, and should not get, a contact list.

Phase 5 calls `ReferralService::qualifyForOrder()` on payment verification. Until
then `POST /admin/referrals/{uuid}/qualify` is the wired path, and it remains
useful afterwards for genuine edge cases.

Cancelling a referral does **not** claw back credit already spent — that would
push a balance negative, which the schema forbids. An administrator posts a
compensating wallet adjustment if recovery is warranted, leaving both actions
visible in the ledger.

Several signups from one IP against the same code are **logged as a fraud signal,
not auto-blocked**. A family sharing a connection looks identical to abuse, so a
human decides.

---

## Administrator endpoints

All require the `administrator` role.

### Coupons

| Method | Path |
|---|---|
| GET | `/admin/coupons?status=active` |
| POST | `/admin/coupons` |
| PATCH | `/admin/coupons/{uuid}` |
| POST | `/admin/coupons/{uuid}/status` |
| GET | `/admin/coupons/{uuid}/redemptions` |
| DELETE | `/admin/coupons/{uuid}` |

New coupons are created as **drafts**. Activation is refused with a list of
what's missing if the coupon has no expiry date, is an uncapped percentage, or is
scoped with no targets:

```json
{
  "success": false,
  "message": "This coupon is not ready to activate.",
  "errors": {
    "activation": [
      "Set an expiry date. A coupon with no end date runs forever.",
      "Set a maximum discount. An uncapped percentage on a large order is unbounded."
    ]
  }
}
```

Scope a coupon with `category_slugs` **or** `product_slugs`, never both — mixing
them makes the discount scope ambiguous (**422**).

A scoped promotion with **no** targets discounts **nothing**. Failing closed is
the safe direction; the alternative discounts the entire catalogue.

### Offers

`/admin/offers` mirrors the coupon routes, plus `PUT /admin/offers/{uuid}/targets`
and `POST /admin/offers/{uuid}/banner` (multipart field `image`).

### Wallet & referrals

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/wallet/{userUuid}` | Summary plus a ledger integrity check |
| GET | `/admin/wallet/{userUuid}/statement` | Full ledger |
| POST | `/admin/wallet/{userUuid}/credit` | `amount`, `narration`, `source`, `expiry_days`, `reference` |
| POST | `/admin/wallet/{userUuid}/debit` | A compensating entry, not an edit |
| POST | `/admin/wallet/{userUuid}/freeze` | Blocks redemption; credits still post |
| POST | `/admin/wallet/{userUuid}/unfreeze` | |
| POST | `/admin/wallet/expire-credits` | Writes off passed expiries |
| GET | `/admin/referrals?status=pending` | |
| POST | `/admin/referrals/{uuid}/qualify` | `order_reference`, `order_value` |
| POST | `/admin/referrals/{uuid}/cancel` | `reason` |

Pass `reference` on a credit to make it idempotent — without one, the amount and
minute are hashed, which stops the obvious accidental double-submit but is weaker.

---

## Settings

Runtime-tunable via the `settings` table, no deploy needed:

| Key | Default | Meaning |
|---|---|---|
| `referral_referrer_reward` | 50 | Credit to the referrer |
| `referral_referee_reward` | 50 | Credit to the new customer |
| `referral_min_order_value` | 299 | Minimum first order that qualifies |
| `referral_reward_expiry_days` | 180 | Days before referral credit expires |
| `wallet_max_redeem_percent` | 20 | Cap per order, as a percent of order value |
| `wallet_min_redeem_amount` | 10 | Smallest redemption allowed |
| `wallet_enabled` | 1 | Redemption master switch |

The percentage cap is what keeps wallet credit a **supplement** to a real payment
rather than a replacement for one. An order paid entirely from promotional credit
brings in no cash and is trivially farmable.

---

## Verifying the arithmetic

```bash
php bin/test_promotions.php        # no database or server needed
php bin/smoke_test_promotions.php # end-to-end, needs the dev server + admin login
```

`test_promotions.php` checks the three discount caps, the uncapped-percentage
exposure that activation refuses, per-line apportionment across mixed GST rates,
and — explicitly — that wallet credit changes the amount payable while leaving
the order value and GST untouched.
