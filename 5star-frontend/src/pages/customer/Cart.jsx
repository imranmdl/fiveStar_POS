import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import { useCart } from '../../hooks/useCart';
import { cardFromListItem, packLabel, rupees, tintFor } from '../../lib/store';
import { AddControl, ProductMedia } from '../../components/customer/ProductCard';

/** A missing pincode is resolved at checkout, so it never blocks the button. */
function hardBlockers(cart) {
  return ((cart.checkout && cart.checkout.blockers) || [])
    .filter((b) => !String(b).toLowerCase().includes('enter a delivery pincode'));
}

function FreeDelivery({ cart, onPincode, pincodeBusy }) {
  const delivery = cart.pricing.delivery || {};
  const summary = cart.pricing.summary;
  const [pincode, setPincode] = useState(delivery.pincode || '');
  const known = delivery.is_serviceable;
  const spendMore = Number(delivery.spend_more_for_free_delivery || 0);

  if (!known) {
    return (
      <form
        className="sf-progress"
        onSubmit={(event) => {
          event.preventDefault();
          onPincode(pincode);
        }}
      >
        <span>
          {delivery.pincode
            ? 'We do not deliver to that pincode yet. Try another one.'
            : 'Enter your pincode to see delivery charges and timing.'}
        </span>
        <div className="sf-inline-form">
          <input
            className="sf-input"
            inputMode="numeric"
            maxLength={6}
            pattern="\d{6}"
            placeholder="560001"
            aria-label="Delivery pincode"
            value={pincode}
            onChange={(event) => setPincode(event.target.value.replace(/\D/g, ''))}
            style={{ maxWidth: 180 }}
          />
          <button type="submit" className="sf-btn sf-btn--ink" disabled={pincodeBusy}>{pincodeBusy ? '…' : 'Check'}</button>
        </div>
      </form>
    );
  }

  const free = Number(summary.delivery_charge) === 0;
  const subtotal = Number(summary.items_subtotal || 0);
  const pct = free ? 100 : spendMore > 0 ? Math.min(100, (subtotal / (subtotal + spendMore)) * 100) : 0;
  const days = delivery.estimated_days && delivery.estimated_days.max
    ? ` · arrives in ${delivery.estimated_days.min}–${delivery.estimated_days.max} days`
    : '';

  return (
    <div className="sf-progress">
      <span>
        {free ? 'You get free delivery' : spendMore > 0 ? `Add ${rupees(spendMore)} more for free delivery` : `Delivery to ${delivery.pincode}: ${rupees(summary.delivery_charge)}`}
        <span className="sf-muted">{days}</span>
      </span>
      {(free || spendMore > 0) && (
        <div className="sf-progress__track"><div className="sf-progress__bar" style={{ width: `${pct}%` }} /></div>
      )}
    </div>
  );
}

function CartLine({ item, offer, busy, onQuantity }) {
  const max = Number(item.variant.max_order_quantity || 500);
  const href = `/product/${item.product.slug}`;
  const size = packLabel(item.variant.weight_grams) || item.variant.name;

  return (
    <div className="sf-line">
      <Link to={href} aria-label={item.product.name}>
        <ProductMedia tint={tintFor(item.product.slug)} className="sf-line__thumb" />
      </Link>
      <div className="sf-line__body">
        <div className="sf-line__top">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
            <Link to={href} className="sf-line__name">{item.product.name}</Link>
            <span className="sf-small">{size} · {rupees(item.unit_price)} each</span>
            {offer && <span className="sf-small sf-good" style={{ fontWeight: 600 }}>{offer.summary}</span>}
            {!item.is_purchasable && <span className="sf-line__warn">{item.unavailable_reason || 'Unavailable'}</span>}
            {item.price_changed_date && <span className="sf-line__warn">Price changed since you added this.</span>}
          </div>
          <span className="sf-line__total">{rupees(item.line_total)}</span>
        </div>
        <div className="sf-line__ctrls">
          <div className="sf-stepper sf-stepper--light">
            <button type="button" aria-label="One fewer" disabled={busy} onClick={() => onQuantity(item.uuid, item.quantity - 1)}>−</button>
            <span>{item.quantity}</span>
            <button type="button" aria-label="One more" disabled={busy || item.quantity >= max} onClick={() => onQuantity(item.uuid, item.quantity + 1)}>+</button>
          </div>
          <button type="button" className="sf-line__remove" disabled={busy} onClick={() => onQuantity(item.uuid, 0)}>Remove</button>
        </div>
      </div>
    </div>
  );
}

export default function Cart() {
  const { cart, lines, count, busy, refresh, setQuantity, showToast } = useCart();
  const [offers, setOffers] = useState({});
  const [suggestions, setSuggestions] = useState([]);
  const [coupon, setCoupon] = useState('');
  const [couponBusy, setCouponBusy] = useState(false);
  const [pincodeBusy, setPincodeBusy] = useState(false);

  useEffect(() => {
    document.title = 'Your cart · 5 Star';
    refresh();
  }, [refresh]);

  const productKey = useMemo(() => [...new Set(lines.map((l) => l.product.uuid))].sort().join(','), [lines]);

  useEffect(() => {
    if (!productKey) {
      setOffers({});
      return;
    }
    api.get('/offers/product-lookup', { product_uuids: productKey })
      .then((lookup) => setOffers((lookup.data && lookup.data.offers) || {}))
      .catch(() => setOffers({}));
  }, [productKey]);

  useEffect(() => {
    const inCart = new Set(productKey.split(','));
    api.get('/products', { per_page: 12, sort: 'relevance' })
      .then((payload) => setSuggestions((payload.data || [])
        .filter((p) => !inCart.has(p.uuid))
        .slice(0, 4)
        .map(cardFromListItem)))
      .catch(() => setSuggestions([]));
  }, [productKey]);

  useEffect(() => {
    setCoupon((cart && cart.promotions && cart.promotions.applied_coupon && cart.promotions.applied_coupon.code) || '');
  }, [cart]);

  async function applyCoupon(event) {
    event.preventDefault();
    setCouponBusy(true);
    try {
      if (!coupon) {
        await api.delete('/cart/coupon');
        showToast('Coupon removed');
      } else {
        const result = await api.post('/cart/coupon', { coupon_code: coupon.trim().toUpperCase() });
        showToast(result.message || 'Coupon applied');
      }
      await refresh();
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      setCouponBusy(false);
    }
  }

  async function checkPincode(pincode) {
    if (!/^\d{6}$/.test(pincode)) {
      showToast('An Indian pincode is six digits.', 'error');
      return;
    }
    setPincodeBusy(true);
    try {
      await api.post('/cart/pincode', { pincode });
      await refresh();
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      setPincodeBusy(false);
    }
  }

  if (!cart) {
    return <div className="sf-page"><h1 className="sf-h1">Your cart</h1><p className="sf-muted">Loading your cart…</p></div>;
  }

  if (lines.length === 0) {
    return (
      <div className="sf-page">
        <h1 className="sf-h1">Your cart</h1>
        <div className="sf-empty-box">
          <b>Your cart is empty</b>
          <span className="sf-muted" style={{ fontSize: 15 }}>Start with the everyday favourites.</span>
          <Link to="/shop" className="sf-btn sf-btn--red">Shop now</Link>
        </div>
      </div>
    );
  }

  const summary = cart.pricing.summary;
  const payment = cart.payment || {};
  const appliedOffer = cart.promotions && cart.promotions.applied_offer;
  const savings = Number(summary.product_discount || 0) + Number(summary.order_discount || 0);
  const blockers = hardBlockers(cart);
  const deliveryKnown = cart.pricing.delivery && cart.pricing.delivery.is_serviceable;

  return (
    <div className="sf-page">
      <h1 className="sf-h1">Your cart</h1>
      <div className="sf-split">
        <div className="sf-split__main">
          <FreeDelivery cart={cart} onPincode={checkPincode} pincodeBusy={pincodeBusy} />

          <div className="sf-lines">
            {lines.map((item) => (
              <CartLine key={item.uuid} item={item} offer={offers[item.product.uuid]} busy={busy} onQuantity={setQuantity} />
            ))}
          </div>

          {suggestions.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14, paddingTop: 12 }}>
              <span className="sf-h3">Frequently bought together</span>
              <div className="sf-fbt">
                {suggestions.map((p) => (
                  <div key={p.uuid} className="sf-fbt__item">
                    <Link to={`/product/${p.slug}`} aria-label={p.name}>
                      <ProductMedia image={p.image} tint={p.tint} className="sf-media--square" alt={p.name} />
                    </Link>
                    <Link to={`/product/${p.slug}`} className="sf-fbt__name">{p.name}</Link>
                    <div className="sf-fbt__foot">
                      <span>{rupees(p.price)}</span>
                      <AddControl product={p} size="xs" />
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <aside className="sf-summary" aria-label="Order summary">
          <span className="sf-h3">Order summary</span>
          <div className="sf-summary__row"><span>Items ({count})</span><span>{rupees(summary.items_mrp_total)}</span></div>
          {savings > 0 && (
            <div className="sf-summary__row"><span>Discount</span><span className="sf-good">− {rupees(savings)}</span></div>
          )}
          {appliedOffer && <span className="sf-small sf-good">{appliedOffer.title} — {appliedOffer.summary}</span>}
          <div className="sf-summary__row">
            <span>Delivery</span>
            <span>{!deliveryKnown ? 'At checkout' : Number(summary.delivery_charge) === 0 ? 'Free' : rupees(summary.delivery_charge)}</span>
          </div>

          <form className="sf-inline-form" onSubmit={applyCoupon}>
            <input
              className="sf-input"
              placeholder="Coupon code"
              aria-label="Coupon code"
              value={coupon}
              onChange={(event) => setCoupon(event.target.value)}
            />
            <button type="submit" className="sf-btn sf-btn--outline" disabled={couponBusy} style={{ padding: '0 16px' }}>
              {couponBusy ? '…' : 'Apply'}
            </button>
          </form>

          <div className="sf-summary__row sf-summary__row--total"><span>Total</span><span>{rupees(summary.grand_total)}</span></div>
          <span className="sf-small">Inclusive of {rupees(summary.tax_total)} GST</span>

          {Number(payment.wallet_applied || 0) > 0 && (
            <>
              <div className="sf-summary__row"><span>Wallet credit</span><span className="sf-good">− {rupees(payment.wallet_applied)}</span></div>
              <div className="sf-summary__row" style={{ fontWeight: 700 }}><span>To pay</span><span>{rupees(payment.amount_payable)}</span></div>
            </>
          )}

          {blockers.length > 0 && (
            <div className="sf-error">
              <b>Before you can check out</b>
              <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{blockers.map((b) => <li key={b}>{b}</li>)}</ul>
            </div>
          )}

          {blockers.length === 0 ? (
            <Link to="/checkout" className="sf-btn sf-btn--red sf-btn--lg">Checkout</Link>
          ) : (
            <span className="sf-btn sf-btn--red sf-btn--lg" aria-disabled="true">Checkout</span>
          )}
          <Link to="/shop" className="sf-btn sf-btn--ghost" style={{ color: 'var(--sf-ink)' }}>Continue shopping</Link>
        </aside>
      </div>
    </div>
  );
}
