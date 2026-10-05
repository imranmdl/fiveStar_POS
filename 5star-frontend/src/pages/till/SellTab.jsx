import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import ScanInput from './ScanInput';
import LinkBarcode from '../../components/LinkBarcode';
import CustomerPanel from './CustomerPanel';
import CartTable from './CartTable';
import PaymentPanel from './PaymentPanel';
import OfferPicker from './OfferPicker';
import Receipt from './Receipt';
import { computeTotals, remainderDue, round2, roundToRupee } from './tillMath';
import { ErrorBanner } from './TillShared';

/**
 * The Sell tab — ported from renderSellTab() in admin/assets/page-till.js.
 * Composes the scan input (HID/camera/manual — see ScanInput.jsx), the
 * customer-attach panel, the editable cart, and the payment/complete-sale
 * panel. All three scan sources feed the same addVariantToCart(), so the
 * cart updates identically no matter which one was used.
 */
export default function SellTab({ defaultWarehouseUuid, shopLabel, onShopLabelChange, notify }) {
  const [cart, setCart] = useState([]);
  const [selectedCustomer, setSelectedCustomer] = useState(null);
  const [walletApplied, setWalletApplied] = useState(0);
  const [walkInName, setWalkInName] = useState('');
  const [walkInMobile, setWalkInMobile] = useState('');

  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [amountTendered, setAmountTendered] = useState('');
  const [delivery, setDelivery] = useState('delivered');

  const [feedback, setFeedback] = useState(null);
  const [unknownCode, setUnknownCode] = useState(null); // a scanned code no item has yet
  const [offerPrompt, setOfferPrompt] = useState(null); // { candidates, itemLabel }
  const offerResolverRef = useRef(null);

  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState(null);
  const [receipt, setReceipt] = useState(null);

  const cartRef = useRef(cart);
  cartRef.current = cart;

  const totals = computeTotals(cart);

  // The bill may have shrunk (a line removed or a qty cut) below what was
  // already earmarked from the wallet — clamp it back down rather than let
  // a stale amount silently exceed the new total.
  useEffect(() => {
    if (walletApplied > totals.grandTotal) setWalletApplied(round2(totals.grandTotal));
  }, [totals.grandTotal, walletApplied]);

  function promptOfferPicker(candidates, itemLabel) {
    return new Promise((resolve) => {
      offerResolverRef.current = resolve;
      setOfferPrompt({ candidates, itemLabel });
    });
  }

  function resolveOfferPrompt(picked) {
    setOfferPrompt(null);
    const resolve = offerResolverRef.current;
    offerResolverRef.current = null;
    if (resolve) resolve(picked);
  }

  /**
   * Adds an already-resolved variant to the bill — shared by the fast
   * scan/exact-code path (HID scanner, camera, or an exact typed SKU) and
   * the "typed a name instead" search-pick path, so every input source
   * checks offers and merges into an existing line the same way.
   */
  async function addVariantToCart(variant) {
    const currentCart = cartRef.current;
    const existing = currentCart.find((l) => l.variant_uuid === variant.uuid);

    let discountAmount = 0;
    let appliedOfferCode = null;
    let replaceDiscount = false;
    let extraQuantity = 0;

    try {
      const offersResponse = await api.get('/admin/pos/offers', {
        variant_uuid: variant.uuid,
        subtotal_so_far: computeTotals(currentCart).subtotal,
        existing_quantity: existing ? existing.quantity : 0,
        // The whole bill, so basket-wide offers (minimum spend, cheapest one free) can be judged.
        cart: JSON.stringify(currentCart.map((l) => ({ variant_uuid: l.variant_uuid, quantity: Number(l.quantity) || 0, unit_price: l.unit_price }))),
      });
      const candidates = offersResponse.data.offers || [];

      if (candidates.length > 0) {
        const picked = await promptOfferPicker(candidates, `${variant.product_name} — ${variant.variant_name}`);

        if (picked) {
          discountAmount = Number(picked.discount_amount) || 0;
          appliedOfferCode = picked.code;
          replaceDiscount = Boolean(picked.replace_discount);
          extraQuantity = Number(picked.extra_quantity) || 0;
        }
      }
    } catch {
      // An offer-lookup failure must never block ringing up the item — same
      // "an advert must never cost the customer anything" rule applied to
      // the till: the item still gets added, just without a discount
      // prompt this one time.
    }

    setCart((current) => {
      const index = current.findIndex((l) => l.variant_uuid === variant.uuid);

      if (index >= 0) {
        const next = [...current];
        const line = { ...next[index] };
        // BOGO's discount is recomputed from the line's new TOTAL quantity
        // each time (it earns in whole buy+get blocks, not linearly per
        // unit), so it replaces the line's discount rather than adding to
        // it — unlike a percentage/flat offer, which is genuinely one more
        // unit's worth of discount on top of what was already there.
        line.quantity = String((Number(line.quantity) || 0) + 1 + extraQuantity);
        if (appliedOfferCode) {
          line.discount_amount = String(replaceDiscount ? discountAmount : (Number(line.discount_amount) || 0) + discountAmount);
          line.applied_offer_code = appliedOfferCode;
        }
        next[index] = line;
        return next;
      }

      return [
        ...current,
        {
          variant_uuid: variant.uuid,
          sku: variant.sku,
          product_name: variant.product_name,
          variant_name: variant.variant_name,
          gst_rate: variant.gst_rate ?? 0,
          quantity: String(1 + extraQuantity),
          unit_price: String(variant.selling_price ?? '0'),
          discount_amount: String(discountAmount),
          applied_offer_code: appliedOfferCode,
        },
      ];
    });

    setFeedback(appliedOfferCode
      ? { tone: 'success', text: `Added: ${variant.product_name} — ${variant.variant_name} (${appliedOfferCode} applied${extraQuantity ? `, ${extraQuantity} free unit added` : ''})` }
      : { tone: 'success', text: `Added: ${variant.product_name} — ${variant.variant_name}` });
  }

  // Fast path: a scanner (or someone typing the exact SKU/barcode) sends
  // the full code then Enter almost instantly — resolved by an exact
  // match. The camera path calls this exact function too.
  async function addByExactCode(code) {
    setFeedback({ tone: 'muted', text: 'Looking up…' });
    setUnknownCode(null);

    try {
      const response = await api.get('/admin/inventory/lookup', { sku: code });
      await addVariantToCart(response.data);
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        // Usually an item whose real barcode was never recorded — offer to
        // link this code to it, instead of a dead end.
        setFeedback(null);
        setUnknownCode(code);
        return;
      }
      setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Not found.' });
    }
  }

  // Fallback when there's no scanner/camera match: typing an item NAME
  // shows matches to pick from, one at a time, rather than a hard "not found".
  async function searchByName(text) {
    const response = await api.get('/admin/inventory/search', { q: text });
    const matches = response.data || [];

    // Two entries with the same product name and pack weight are a data
    // problem (someone created the pack twice), not a real choice —
    // flagged so it's spotted here instead of at the till, mid-sale.
    const seen = {};
    matches.forEach((m) => {
      const key = `${m.product_name}|${m.weight_grams}`;
      seen[key] = (seen[key] || 0) + 1;
    });

    return matches.map((m) => ({ ...m, isDuplicate: seen[`${m.product_name}|${m.weight_grams}`] > 1 }));
  }

  // Any item with stock and a price can be sold at the counter. "Published"
  // only controls whether it shows on the website and app.
  async function handlePickMatch(match) {
    await addVariantToCart(match);
  }

  function handleLineChange(index, field, value) {
    setCart((current) => {
      const next = [...current];
      next[index] = { ...next[index], [field]: value };
      return next;
    });
  }

  function handleRemoveLine(index) {
    setCart((current) => current.filter((_, i) => i !== index));
  }

  function handleAttachCustomer(customer) {
    setSelectedCustomer(customer);
    setWalletApplied(0);
  }

  function handleClearCustomer() {
    setSelectedCustomer(null);
    setWalletApplied(0);
  }

  const hasDueContact = Boolean(selectedCustomer) || walkInMobile.trim() !== '';

  async function completeSale() {
    if (cart.length === 0) {
      notify('Add at least one item first.', 'danger');
      return;
    }

    if (!defaultWarehouseUuid) {
      notify('No warehouse is set up yet — add one on the Warehouses screen first.', 'danger');
      return;
    }

    const due = remainderDue(totals.grandTotal, walletApplied);

    if (paymentMethod === 'cash' && amountTendered === '' && due > 0.001) {
      notify('Enter the amount tendered.', 'danger');
      return;
    }

    // What's actually collected in cash is rounded to the nearest rupee —
    // this is what gets recorded and what change/balance was calculated
    // from, so the receipt and the till drawer always agree. An empty
    // field for a non-cash method still means "paid in full by that method".
    const amountTenderedValue = amountTendered === ''
      ? null
      : (paymentMethod === 'cash' ? roundToRupee(amountTendered) : (Number(amountTendered) || 0));
    const shortBeforeSubmit = amountTenderedValue === null ? 0 : round2(due - amountTenderedValue);
    const acceptPartial = shortBeforeSubmit > 0.005;

    if (acceptPartial && !selectedCustomer && !walkInMobile.trim()) {
      notify('Enter the walk-in mobile number so there is someone to collect the rest from later.', 'danger');
      return;
    }

    setSaving(true);
    setSaveError(null);

    try {
      const response = await api.post('/admin/pos/sales', {
        warehouse_uuid: defaultWarehouseUuid,
        customer_uuid: selectedCustomer?.uuid || null,
        walk_in_name: selectedCustomer ? null : (walkInName || null),
        walk_in_mobile: selectedCustomer ? null : (walkInMobile || null),
        payment_method: paymentMethod,
        amount_tendered: amountTenderedValue,
        accept_partial: acceptPartial,
        delivered: delivery === 'delivered',
        shop_label: shopLabel.trim() || null,
        wallet_applied: walletApplied > 0 ? walletApplied : null,
        lines: cart.map((l) => ({
          variant_uuid: l.variant_uuid,
          quantity: l.quantity,
          unit_price: l.unit_price,
          discount_amount: l.discount_amount || 0,
          applied_offer_code: l.applied_offer_code || null,
        })),
      });

      notify('Sale completed.');
      setReceipt(response.data);
      setCart([]);
      setSelectedCustomer(null);
      setWalletApplied(0);
      setWalkInName('');
      setWalkInMobile('');
      setAmountTendered('');
      setFeedback(null);
    } catch (error) {
      setSaveError(error);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="till-sell">
      <label className="till-field">
        <span>Shop name</span>
        <input
          placeholder="e.g. Shivaji Circle counter"
          autoComplete="off"
          value={shopLabel}
          onChange={(event) => onShopLabelChange(event.target.value)}
        />
        <div className="till-field__hint">Printed on the receipt and shown in sales history — type wherever you're selling from.</div>
      </label>

      <CustomerPanel
        selectedCustomer={selectedCustomer}
        onAttach={handleAttachCustomer}
        onClear={handleClearCustomer}
        walkInName={walkInName}
        walkInMobile={walkInMobile}
        onWalkInNameChange={setWalkInName}
        onWalkInMobileChange={setWalkInMobile}
      />

      <ScanInput
        onCode={addByExactCode}
        onSearch={searchByName}
        onPickMatch={handlePickMatch}
        feedback={feedback}
      />

      {unknownCode && (
        <LinkBarcode
          code={unknownCode}
          onCancel={() => setUnknownCode(null)}
          onLinked={async (variant) => {
            setUnknownCode(null);
            await addVariantToCart(variant);
          }}
        />
      )}

      <CartTable cart={cart} onLineChange={handleLineChange} onRemoveLine={handleRemoveLine} />

      {saveError && <ErrorBanner error={saveError} />}

      <PaymentPanel
        totals={totals}
        selectedCustomer={selectedCustomer}
        walletApplied={walletApplied}
        onWalletAppliedChange={setWalletApplied}
        paymentMethod={paymentMethod}
        onPaymentMethodChange={setPaymentMethod}
        amountTendered={amountTendered}
        onAmountTenderedChange={setAmountTendered}
        delivery={delivery}
        onDeliveryChange={setDelivery}
        hasDueContact={hasDueContact}
        onCompleteSale={completeSale}
        busy={saving}
        disabled={cart.length === 0}
      />

      {receipt && (
        <div className="till-receipt-host">
          <Receipt sale={receipt} />
        </div>
      )}

      {offerPrompt && (
        <OfferPicker
          candidates={offerPrompt.candidates}
          itemLabel={offerPrompt.itemLabel}
          onResolve={resolveOfferPrompt}
        />
      )}
    </div>
  );
}
