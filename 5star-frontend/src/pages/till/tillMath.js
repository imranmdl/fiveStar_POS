/**
 * Pure arithmetic shared across the Sell tab and the receipt — ported from
 * admin/assets/page-till.js (estimateTax, totals, roundToRupee, round2,
 * remainderDue). Kept dependency-free so CartTable, PaymentPanel and
 * Receipt can all import the exact same numbers instead of recomputing
 * slightly-different versions of the same formula.
 */

/**
 * Same tax-inclusive extraction PosSaleService::create() does server-side
 * (Money::extractInclusiveTax) — an estimate for live display, not the
 * authoritative figure (the server recomputes and is the source of truth).
 */
export function estimateTax(netLineValue, gstRate) {
  const rate = Number(gstRate) || 0;
  if (rate <= 0) return 0;
  return (netLineValue * rate) / (100 + rate);
}

export function round2(value) {
  return Math.round(value * 100) / 100;
}

/**
 * Cash in hand doesn't come in paise — rounded to the nearest rupee the way
 * a till drawer actually works: 12.49 is treated as ₹12, 12.50 as ₹13.
 */
export function roundToRupee(value) {
  return Math.round(Number(value) || 0);
}

export function lineNet(line) {
  const gross = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
  return Math.max(0, gross - (Number(line.discount_amount) || 0));
}

export function lineTax(line) {
  return estimateTax(lineNet(line), line.gst_rate);
}

export function computeTotals(cart) {
  const subtotal = cart.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const discount = cart.reduce((sum, l) => sum + (Number(l.discount_amount) || 0), 0);
  const tax = cart.reduce((sum, l) => sum + lineTax(l), 0);
  const grandTotal = Math.max(0, subtotal - discount);

  return { subtotal, discount, tax, grandTotal };
}

/** What's left after wallet credit covers its share — what cash/UPI/card actually needs to cover. */
export function remainderDue(grandTotal, walletApplied) {
  return Math.max(0, round2(grandTotal - walletApplied));
}

/** A mobile number field: digits only, capped at 10. */
export function digitsOnly(value) {
  return String(value || '').replace(/\D/g, '').slice(0, 10);
}
