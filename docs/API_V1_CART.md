# API v1 — Cart, Wishlist & Delivery

Base URL: `{APP_URL}/api/v1`. Same envelope and status codes as
[API_V1_AUTH.md](API_V1_AUTH.md).

---

## How cart identity works

Cart endpoints serve guests and signed-in customers from the same routes.

- **Signed in** — send `Authorization: Bearer <access_token>`. The account cart
  is always used and any cart token is **ignored**. Honouring a cart token for
  an authenticated caller would let a leaked token expose one customer's cart to
  another.
- **Guest** — send `X-Cart-Token: <token>` (or `cart_token` in the body). On the
  first request without one, the server mints a token and returns it at
  `data.cart.guest_token`. **The client must persist it** — localStorage on web,
  shared preferences in Flutter. Only the SHA-256 digest is stored server-side,
  so a lost token means a lost guest cart, which is the correct trade.

At most one active cart exists per owner. That is enforced by unique indexes over
generated columns, so two concurrent add-to-cart requests cannot race into two
carts.

### Merging on login

After a successful sign-in, if the client holds a guest token, call
`POST /cart/merge`. Quantities are summed and capped at each pack size's limit;
the guest cart is marked `merged` with a pointer to its destination rather than
deleted, so support can answer "where did my cart go".

The call is **idempotent** — safe to retry after a flaky login. An expired or
already-merged token returns the user's current cart rather than an error.

---

## Pricing: read this before building any client

**Prices are GST-inclusive.** Indian MRP includes tax by law, so the engine
*extracts* GST from the price rather than adding it on top. In every response,
`taxable_value + tax_total == items_subtotal`, and `grand_total` is
`items_subtotal - discounts + delivery_charge`. Do not add `tax_total` to
`grand_total`; you would overcharge by the tax amount.

**All arithmetic is server-side and integer-based.** Money is handled in paise
internally, so totals never drift. Do not re-derive totals in Dart or
JavaScript — display what the server returns. `data.reconciles` is the server's
own check that the lines add up to the grand total; if it is ever `false`, treat
it as a bug and do not let the customer pay.

**Tax is broken down per rate.** A cart mixing 5% spices with 12% nuts returns
two entries in `tax_breakdown`, which is what the invoice needs.

### Price changes are re-quoted, not absorbed

Every line stores the price the customer was shown. On each cart read, that
snapshot is compared to the live price. If an offer expired or a price moved,
the cart is re-priced **and** the change is reported:

```json
"price_changes": [
  {
    "item_uuid": "…",
    "product_name": "Green Cardamom 8mm",
    "direction": "increased",
    "previous_unit_price": 629.00,
    "new_unit_price": 679.00,
    "difference": 50.00,
    "message": "Green Cardamom 8mm (100 g jar) has increased from ₹629.00 to ₹679.00 since you added it."
  }
]
```

Show this to the customer. Absorbing the difference silently means selling at a
loss every time an offer expires mid-session. Call
`POST /cart/price-changes/acknowledge` once they have seen it, so the notice
stops appearing.

### Unavailable lines are kept, not deleted

If a product is archived while sitting in a cart, the line moves to
`unavailable_items` with an `unavailable_reason` and contributes nothing to the
totals. It is not removed — a line vanishing with no explanation reads as a bug.
Checkout stays blocked until the customer clears them.

---

## Cart endpoints

### GET /cart

Optional `?pincode=560001` quotes delivery without saving the pincode.

Response shape:

| Key | Contents |
|---|---|
| `cart` | `uuid`, `is_guest_cart`, `guest_token`, `currency_code`, `delivery_pincode` |
| `items` | Active, purchasable lines |
| `saved_for_later` | Lines parked for later |
| `unavailable_items` | Lines that can no longer be bought, with reasons |
| `pricing.lines` | Per-line costing: MRP, price, discounts, taxable value, tax |
| `pricing.summary` | Subtotals, discounts, delivery, tax, `grand_total`, `total_savings` |
| `pricing.tax_breakdown` | Taxable value and tax per GST rate |
| `pricing.delivery` | Zone, charge, waiver reason, SLA, chargeable weight |
| `price_changes` | What moved since the customer last looked |
| `checkout` | `is_ready`, `blockers[]`, `minimum_order_value`, `payment_modes` |
| `reconciles` | Server-side arithmetic check |

### POST /cart/items

```json
{ "variant_uuid": "…", "quantity": 2, "is_gift": false, "gift_message": null }
```

Adding a pack size already in the cart **increments the quantity** rather than
creating a second line. Re-adding a previously removed pack size revives that
line. **422** if the quantity exceeds the pack's `max_order_quantity`, **409**
if the cart is at its line limit (50 by default) or the product is not on sale.

### Other cart operations

| Method | Path | Notes |
|---|---|---|
| PATCH | `/cart/items/{uuid}` | `{"quantity": 3}`. Quantity must be ≥ 1; use DELETE to remove. |
| DELETE | `/cart/items/{uuid}` | Soft delete. |
| POST | `/cart/items/{uuid}/save-for-later` | Excluded from totals. |
| POST | `/cart/items/{uuid}/move-to-cart` | **409** if it became unavailable while parked. |
| POST | `/cart/clear` | `{"include_saved_for_later": false}` |
| POST | `/cart/pincode` | `{"pincode": "560001"}` — persists it on the cart. |
| POST | `/cart/price-changes/acknowledge` | Clears the notice. |
| POST | `/cart/merge` | Authenticated. `{"cart_token": "…"}` |

Every cart operation returns the **full recalculated cart**, so a client never
needs a follow-up GET and can never render a stale total.

Cart lines are ownership-checked, not merely existence-checked: a guessed item
UUID from another cart returns **404**.

---

## Delivery pricing (BR-006)

Charges are calculated from destination and weight, never fixed.

1. Pincode resolves to a zone by **longest matching prefix**, so `560001` beats
   `560` beats `56`. A single-pincode exception needs no schema change. Unmapped
   pincodes fall back to the default zone rather than dead-ending.
2. Weight selects a charge band. The heaviest band is open-ended with a per-kg
   rate, so no order weight is unquotable.
3. Free shipping applies from the zone's own threshold if set, otherwise the
   global `free_delivery_threshold` setting. Remote zones deliberately have no
   threshold because that freight is genuinely expensive.

### GET /delivery/serviceability?pincode=560001

Zone, SLA, free-delivery threshold and a display-ready `message`. Call this from
the address form so a customer learns about a problem before checkout.

### GET /delivery/rate-card

Every zone with its weight bands. Lets you publish a shipping policy page
generated from the same data that charges the customer.

When delivery is not yet free, `pricing.delivery.spend_more_for_free_delivery`
gives the exact shortfall. Show it — it is the highest-converting line of copy on
a cart page.

---

## Wishlist

Requires authentication. There is no guest wishlist: it is only useful if it
survives across devices, which needs an account.

| Method | Path | Notes |
|---|---|---|
| GET | `/wishlist` | Paginated, with live pricing and price-drop deltas |
| POST | `/wishlist` | `{"product": "slug-or-uuid", "variant_uuid": null}` |
| GET | `/wishlist/contains?product=…` | For the heart icon on a product page |
| PATCH | `/wishlist/{uuid}` | `variant_uuid`, `notify_on_offer`, `notify_on_price_drop`, `notes` |
| DELETE | `/wishlist/{uuid}` | Returns the new count |
| POST | `/wishlist/{uuid}/move-to-cart` | `{"variant_uuid": "…", "quantity": 1}` |

Wishlists are per **product** with an optional preferred pack size. A nullable
column in a unique key would let MySQL store unlimited duplicates, since NULLs
never collide — so uniqueness is `(user_id, product_id)`.

`price_at_add` is captured so the Phase 9 price-drop notification has a baseline.
Each item reports `price_drop_since_added` when the current price is lower.

**409** on adding a product already saved, or when the wishlist is full (200 by
default). Moving to cart needs a pack size: if the entry has no preference, the
client must supply `variant_uuid` (**422** otherwise).

---

## Checkout readiness

`data.checkout` lists **every** blocker at once rather than one error per
attempt:

```json
{
  "is_ready": false,
  "blockers": [
    "2 item(s) in your cart are no longer available. Remove them to continue.",
    "The minimum order value is ₹199.00. Add ₹50.00 more to continue."
  ],
  "minimum_order_value": 199.00,
  "payment_modes": ["upi"],
  "prepaid_only": true
}
```

Drive the checkout button off `is_ready` and render `blockers` verbatim.

`payment_modes` is always `["upi"]` and `prepaid_only` always `true` per BR-004.
Do not render a cash-on-delivery option; the platform has no code path for one.

---

## Verifying the arithmetic

```bash
php bin/test_pricing.php      # no database or server needed
php bin/smoke_test_cart.php   # end-to-end, needs the dev server
```

`test_pricing.php` is worth running on every change to money handling. It checks
GST extraction across 20,000 amounts, proves 3,000 random discount allocations
leak zero paise, and verifies a 17-line cart with mixed GST rates and an awkward
coupon still reconciles to the paisa.
