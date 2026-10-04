# API v1 — Catalog

Base URL: `{APP_URL}/api/v1`. Same response envelope and status codes as
[API_V1_AUTH.md](API_V1_AUTH.md).

Public endpoints need no token. Every `/admin/*` endpoint requires a Bearer
token belonging to the **administrator** role; anything else gets 403.

---

## Public

### GET /categories

Nested tree with a live published-product count per node. One query, nested in
PHP — not N+1.

```json
{
  "data": {
    "categories": [
      {
        "uuid": "…", "slug": "spices", "name": "Spices",
        "product_count": 2, "is_featured": true, "image_url": null,
        "children": [
          { "uuid": "…", "slug": "whole-spices", "name": "Whole Spices", "product_count": 1, "children": [] }
        ]
      }
    ]
  }
}
```

### GET /categories/{slug}

Single category with SEO metadata.

### GET /products

Paginated listing. All parameters optional.

| Parameter | Notes |
|---|---|
| `search` | Full-text over name, short description and keywords. Matches regional synonyms — "haldi" finds turmeric. Terms under 3 characters fall back to LIKE rather than silently returning nothing. |
| `category` | Slug. **Includes descendants** — `category=spices` returns products filed under Ground Spices too. |
| `min_price`, `max_price` | Compared against the product's cheapest live price. |
| `min_weight_grams`, `max_weight_grams` | Matches if any pack size overlaps the range. |
| `min_rating` | 0–5. |
| `has_offer`, `is_organic`, `is_featured` | Booleans. |
| `brand` | Exact match. See `/products/filters` for the list. |
| `sort` | `relevance` (default), `popularity`, `newest`, `price_low`, `price_high`, `discount`, `rating`, `name_asc`, `name_desc`. |
| `page`, `per_page` | `per_page` caps at 48. |

Only `published` products are ever returned here.

Each item carries a `pricing` block (`min_price`, `max_price`, `min_mrp`,
`max_discount_percentage`, `has_live_offer`, `variant_count`), a `weight_grams`
range, a `rating` block, `flags`, and `primary_image`.

**422** for an inverted price range or an unknown `sort`. **404** for an unknown
`category`.

### GET /products/filters

Bounds for building filter UI without guessing: actual price range, maximum
weight, the brand list, and the sort options with display labels.

### GET /products/{identifier}

Slug or UUID. Returns everything the product page needs in one call: all pack
sizes with resolved pricing, media, nutrition, specifications, compliance
(HSN/GST/FSSAI), and up to 8 related products. Increments the view counter.

Each variant includes `effective_price` (offer price when the offer window is
live, otherwise selling price), `discount_percentage`, `price_per_kg` and
`shipping_weight_grams`.

**404** for drafts and archived products — they are invisible here by design.

### GET /banners?placement=home_hero

Live banners for a placement, filtered by schedule window in SQL so an expired
banner can never be served. Placements: `home_hero`, `home_strip`,
`category_top`, `app_home`, `checkout`. Serving a set counts an impression.

### POST /banners/{uuid}/click

Click-through telemetry.

---

## Administration

### Categories

| Method | Path |
|---|---|
| GET | `/admin/categories` — flat paginated list with product counts |
| POST | `/admin/categories` |
| PATCH | `/admin/categories/{uuid}` |
| POST | `/admin/categories/{uuid}/image` — multipart, field `image` |
| DELETE | `/admin/categories/{uuid}` |

`slug` is generated from `name` when omitted, and de-duplicated automatically.
`parent_slug` nests a category; send it empty to promote a category to the top
level.

**409** on deleting a category that still holds products or subcategories —
move the contents first rather than orphaning them. **422** on a reparent that
would put a category inside its own subtree.

### Products

| Method | Path |
|---|---|
| GET | `/admin/products` — includes drafts; extra `status` filter |
| GET | `/admin/products/{identifier}` |
| POST | `/admin/products` |
| PATCH | `/admin/products/{uuid}` |
| POST | `/admin/products/{uuid}/publish` |
| POST | `/admin/products/{uuid}/archive` |
| DELETE | `/admin/products/{uuid}` |

Create requires `name`, `product_code`, `category_slug` and a `variants` array
with at least one entry:

```json
{
  "name": "Malabar Black Pepper",
  "product_code": "SPC-PEPPER",
  "category_slug": "whole-spices",
  "short_description": "Sharp, resinous Malabar peppercorns",
  "search_keywords": "kali mirch, milagu, peppercorn",
  "gst_rate": 5,
  "variants": [
    { "sku": "SPC-PEPPER-100", "variant_name": "100 g pouch", "weight_grams": 100, "mrp": 199, "selling_price": 179, "is_default": true },
    { "sku": "SPC-PEPPER-250", "variant_name": "250 g pouch", "weight_grams": 250, "mrp": 449, "selling_price": 399, "offer_price": 349 }
  ]
}
```

**A new product is always a draft.** Publishing is deliberate and gated: the API
returns **422** listing what is missing unless the product has at least one pack
size, at least one image, and a short description. Failing here is much cheaper
than discovering an unshippable product at checkout.

`archive` withdraws a product from sale; `DELETE` is a soft delete. Neither
touches historical orders, which is why both exist.

PATCH applies only the fields actually present in the request body, so omitting
a field never blanks it.

### Pack sizes (variants)

| Method | Path |
|---|---|
| POST | `/admin/products/{uuid}/variants` |
| PATCH | `/admin/variants/{uuid}` |
| DELETE | `/admin/variants/{uuid}` |

`weight_grams` is mandatory — courier selection (BR-007) and delivery charges
(BR-006) both depend on it, so a product cannot be sold without a shippable
weight. Set `packed_weight_grams` when the gross shipping weight differs.

Enforced pricing rules (**422**, with the offending field named):
`selling_price` ≤ `mrp`, `offer_price` < `selling_price`, offer end after offer
start, `weight_grams` > 0. The same rules exist as database CHECK constraints,
so they hold even against direct SQL.

Exactly one variant is the default. Setting a new one clears the old, and
deleting the default automatically promotes the cheapest remaining pack size.
Removing the last pack size from a *published* product returns **409** — archive
the product instead.

### Media

| Method | Path |
|---|---|
| POST | `/admin/products/{uuid}/images` — multipart, field `image` |
| POST | `/admin/products/{uuid}/videos` — JSON, `external_url` (https only) |
| DELETE | `/admin/media/{uuid}` |

Accepts JPEG, PNG, WebP and GIF up to 5 MB, minimum 200 px per edge, maximum
6000 px, 10 images per product. Optional fields: `alt_text`, `caption`,
`is_primary`, `display_order`.

The first image uploaded becomes primary automatically; deleting the primary
promotes another. Deleting media soft-deletes the row and removes the file, so
storage does not leak while the audit trail keeps a record of what was there.

**How uploads are validated** (all of it, in order): PHP's upload error code,
then size, then MIME type read from the file's *contents* via finfo — never the
browser-supplied type — then `getimagesize()` must independently agree it is a
decodable raster image of sane dimensions. SVG is rejected outright because it
is XML, and XML means script and XXE. The stored filename is randomly generated,
so path traversal and double-extension tricks have nothing to work with. A
renamed PHP file is rejected with **422**.

### Nutrition and specifications

| Method | Path |
|---|---|
| PUT | `/admin/products/{uuid}/nutrition` |
| PUT | `/admin/products/{uuid}/attributes` |

Nutrition is per 100 g as printed on the label, stored in typed columns rather
than a JSON blob so it stays queryable ("all products above 20 g protein").

`attributes` replaces the whole specification set with a list of
`{attribute_name, attribute_value}`. Maximum 30; duplicate names return **422**.

### Banners

| Method | Path |
|---|---|
| GET | `/admin/banners` — optional `placement` filter, includes impression/click/CTR |
| POST | `/admin/banners` — multipart: `image` required, `mobile_image` optional |
| PATCH | `/admin/banners/{uuid}` |
| DELETE | `/admin/banners/{uuid}` |

`link_type` is `none`, `category`, `product`, `url` or `offer`. Category and
product targets are **verified to exist at save time**, so a banner cannot ship
pointing at a deleted product. URLs must be absolute.

---

## Notes for client developers

**Prices come from the server, always.** `effective_price` already accounts for
the offer window. Do not reimplement the offer-window comparison in Dart or
JavaScript: the pricing view is the single source of truth, and a client that
computes its own price will eventually disagree with checkout.

**Address everything by `uuid` or `slug`.** Internal integer ids are never
exposed.

**There is no stock field, anywhere.** Per BR-001/BR-002 the platform holds no
inventory. Availability is `status`, and a published product is orderable.
Do not build "out of stock" UI against a field that does not exist.

**Listing vs detail.** The listing payload is deliberately lean for grid views;
`variants`, `media`, `nutrition` and `attributes` come only from the detail
endpoint. Fetch detail when the customer opens a product, not for every tile.
