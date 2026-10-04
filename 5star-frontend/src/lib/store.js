/**
 * Storefront presentation helpers for the Claude Design storefront.
 * Pure functions and constants only — all data still comes from the API.
 */
import { formatMoney } from './api';

/** Warm product-tile tints from the design, used when a product has no photo. */
export const TINTS = [
  '#f3e2b3', '#dfe8cf', '#f2d3c9', '#e2ded8', '#ece2cf', '#e8d8c8', '#e9e6cf', '#e3d6cf',
  '#efdfca', '#f3ead6', '#e4ead0', '#e7e4c6', '#e7d6c8', '#eadfce', '#ead8d2', '#e3e2de',
  '#dfe6d3', '#e8dccb', '#f0e3c9', '#ede2d1', '#efe0cc', '#f0dcd0', '#e9e4d3', '#e2e4dc',
];

/** Category tile colours, in the design's order (spices, dry fruits, seeds, combos, gifts…). */
export const CATEGORY_TINTS = ['#f3e2b3', '#efdfca', '#dfe6d3', '#f2d3c9', '#e2e4dc', '#e9e6cf', '#e8d8c8'];

/** Same product → same tint everywhere (card, cart line, product page). */
export function tintFor(key) {
  const text = String(key || '');
  let hash = 0;
  for (let i = 0; i < text.length; i += 1) hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
  return TINTS[hash % TINTS.length];
}

/** Whole rupees, Indian grouping: ₹1,599. */
export function rupees(value) {
  const amount = Number(value || 0);
  if (Number.isInteger(amount)) return `₹${amount.toLocaleString('en-IN')}`;
  return formatMoney(amount);
}

export function packLabel(grams) {
  const g = Number(grams || 0);
  if (!g) return '';
  if (g >= 1000) return `${Number((g / 1000).toFixed(2))} kg`;
  return `${g} g`;
}

/** Label for a variant: its size label (clothing etc.) or its weight. */
export function variantLabel(variant) {
  if (!variant) return '';
  if (variant.size_label) return variant.size_label;
  return packLabel(variant.weight_grams) || variant.variant_name || variant.name || '';
}

/** "₹129 per 100 g" from a variant, when weight is known. */
export function perHundredGrams(variant) {
  const grams = Number(variant?.weight_grams || 0);
  const price = Number(variant?.effective_price ?? variant?.selling_price ?? 0);
  if (!grams || !price) return '';
  return `${rupees(Math.round((price / grams) * 100))} per 100 g`;
}

export function discountPercent(mrp, price) {
  const m = Number(mrp || 0);
  const p = Number(price || 0);
  if (!m || p >= m) return 0;
  return Math.round((1 - p / m) * 100);
}

/** Card view-model for a product-list item (GET /products). */
export function cardFromListItem(item) {
  const pricing = item.pricing || {};
  // Discount shown on the card is the smallest pack's, to match the price beside it.
  const off = discountPercent(pricing.min_mrp, pricing.min_price);
  const weight = item.weight_grams || {};
  return {
    uuid: item.uuid,
    slug: item.slug,
    name: item.name,
    sub: item.category?.name || '',
    categorySlug: item.category?.slug || '',
    short: item.short_description || '',
    tint: tintFor(item.slug),
    image: item.primary_image?.url || null,
    organic: Boolean(item.flags?.is_organic),
    price: Number(pricing.min_price || 0),
    mrp: Number(pricing.min_mrp || 0),
    off,
    size: item.has_size_options ? '' : packLabel(weight.min),
    multiple: Number(pricing.variant_count || 1) > 1,
    rating: Number(item.rating?.average || 0),
    reviews: Number(item.rating?.count || 0),
  };
}

export const TRUST_POINTS = [
  { label: 'Fresh & pure', hint: 'Sourced straight from the farm' },
  { label: 'Fast delivery', hint: 'Dispatched within 24 hours' },
  { label: 'Secure payments', hint: '100% safe UPI checkout' },
  { label: 'Easy returns', hint: 'Hassle-free, no questions asked' },
];

export const SORTS = [
  ['relevance', 'Relevance'],
  ['popularity', 'Popularity'],
  ['price_low', 'Price — Low to High'],
  ['price_high', 'Price — High to Low'],
  ['discount', 'Discount'],
];

/** Price bands for the Shop filters (min, max; null = open-ended). */
export const PRICE_BANDS = [
  ['u200', 'Under ₹200', null, 200],
  ['200-500', '₹200 – ₹500', 200, 500],
  ['500-1000', '₹500 – ₹1,000', 500, 1000],
  ['o1000', 'Over ₹1,000', 1000, null],
];

/**
 * Optional "Free delivery above ₹X" for the announcement bar. The threshold
 * depends on the delivery zone, so it is only shown when the store sets
 * VITE_FREE_DELIVERY_ABOVE at build time.
 */
export const FREE_DELIVERY_ABOVE = Number(import.meta.env.VITE_FREE_DELIVERY_ABOVE || 0) || null;

// ---- Recently viewed (for "Recommended for you") -------------------------
const VIEWED_KEY = 'spice.recently_viewed';

export function rememberViewed(product) {
  try {
    const list = JSON.parse(localStorage.getItem(VIEWED_KEY) || '[]').filter((x) => x.slug !== product.slug);
    list.unshift({ slug: product.slug, category: product.category?.slug || '' });
    localStorage.setItem(VIEWED_KEY, JSON.stringify(list.slice(0, 6)));
  } catch {
    // Storage unavailable (private mode) — recommendations just stay hidden.
  }
}

export function recentlyViewed() {
  try {
    return JSON.parse(localStorage.getItem(VIEWED_KEY) || '[]');
  } catch {
    return [];
  }
}
