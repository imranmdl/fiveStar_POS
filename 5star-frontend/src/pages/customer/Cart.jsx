import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../../lib/api';
import { useCart } from '../../hooks/useCart';
import { cardFromListItem, discountPercent, packLabel, rupees, tintFor } from '../../lib/store';
import ProductCard, { ProductMedia } from '../../components/customer/ProductCard';

/** A missing pincode is resolved at checkout, so it never blocks the button. */
function hardBlockers(cart) {
  return ((cart.checkout && cart.checkout.blockers) || [])
    .filter((b) => !String(b).toLowerCase().includes('enter a delivery pincode'));
}

function DeliveryBar({ cart, onPincode, pincodeBusy }) {
  const delivery = cart.pricing.delivery || {};
  const summary = cart.pricing.summary;
  const [pincode, setPincode] = useState(delivery.pincode || '');
  const known = delivery.is_serviceable;
  const spendMore = Number(delivery.spend_more_for_free_delivery || 0);

  if (!known) {
    return (
      <form
        className="sf-step__body"
        style={{ borderBottom: '1px solid var(--sf-line)', flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}
        onSubmit={(event) => {
          event.preventDefault();
          onPincode(pincode);
        }}
      >
        <span style={{ font: '500 14px var(--sf-text)' }}>
          {delivery.pincode
            ? 'We do not deliver to that pincode yet. Try another one.'
            : 'Enter your pincode to see delivery charges and timing.'}
        </span>
        <div className="sf-inline-form">
          <input className="sf-input" inputMode="numeric" maxLength={6} pattern="\d{6}" placeholder="Pincode"
                 aria-label="Delivery pincode" value={pincode} style={{ width: 130 }}
                 onChange={(event) => setPincode(event.target.value.replace(/\D/g, ''))} />
          <button type="submit" className="sf-btn sf-btn--outline-red" disabled={pincodeBusy}>{pincodeBusy ? '…' : 'CHECK'}</button>
        </div>
      </form>
    );
  }

  const free = Number(summary.delivery_charge) === 0;
  const subtotal = Number(summary.items_subtotal || 0);
  const pct = free ? 100 : spendMore > 0 ? Math.min(100, (subtotal / (subtotal + spendMore)) * 100) : 0;
  const days = delivery.estimated_days && delivery.estimated_days.max
    ? `Delivery to ${delivery.pincode} in ${delivery.estimated_days.min}–${delivery.estimated_days.max} days`
    : `Delivery to ${delivery.pincode}`;

  return (
    <>
      <div style={{ padding: '14px 20px', display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', font: '500 14px var(--sf-text)' }}>
        <span>
          {free
            ? <b className="sf-good">Yay! You get FREE delivery on this order</b>
            : spendMore > 0
              ? <>Add <b>{rupees(spendMore)}</b> more for <b className="sf-good">FREE delivery</b></>
              : <>Delivery charge {rupees(summary.delivery_charge)}</>}
        </span>
        <span className="sf-muted">{days}</span>
      </div>
      {(free || spendMore > 0) && <div className="sf-progress-line"><div style={{ width: `${pct}%` }} /></div>}
    </>
  );
}

function CartLine({ item, offer, busy, onQuantity }) {
  const max = Number(item.variant.max_order_quantity || 500);
  const href = `/product/${item.product.slug}`;
  const size = item.variant.label || (Number(item.variant.weight_grams) > 1 && packLabel(item.variant.weight_grams)) || item.variant.name;
  const off = Number(item.discount_percentage) || discountPercent(item.line_mrp, item.line_total);

  return (
    <div className="sf-line">
      <div className="sf-line__left">
        <Link to={href} aria-label={item.product.name}>
          <ProductMedia tint={tintFor(item.product.slug)} />
        </Link>
        <div className="sf-qty-round">
          <button type="button" aria-label="One fewer" disabled={busy} onClick={() => onQuantity(item.uuid, item.quantity - 1)}>−</button>
          <span>{item.quantity}</span>
          <button type="button" aria-label="One more" disabled={busy || item.quantity >= max} onClick={() => onQuantity(item.uuid, item.quantity + 1)}>+</button>
        </div>
      </div>
      <div className="sf-line__body">
        <Link to={href} className="sf-line__name">{item.product.name}</Link>
        <span className="sf-small">{size}{item.quantity > 1 ? ` · ${rupees(item.unit_price)} each` : ''}</span>
        <div className="sf-line__prices">
          {Number(item.line_mrp) > Number(item.line_total) && <s>{rupees(item.line_mrp)}</s>}
          <b>{rupees(item.line_total)}</b>
          {off > 0 && <em>{off}% Off</em>}
        </div>
        {offer && <span className="sf-small sf-good" style={{ fontWeight: 600 }}>{offer.summary}</span>}
        {!item.is_purchasable && <span className="sf-line__warn">{item.unavailable_reason || 'Unavailable'}</span>}
        {item.price_changed_date && <span className="sf-line__warn">Price changed since you added this.</span>}
        <button type="button" className="sf-line__remove" disabled={busy} onClick={() => onQuantity(item.uuid, 0)}>REMOVE</button>
      </div>
    </div>
  );
}

export function PriceDetails({ cart, count, children }) {
  const summary = cart.pricing.summary;
  const payment = cart.payment || {};
  const appliedOffer = cart.promotions && cart.promotions.applied_offer;
  const savings = Number(summary.product_discount || 0) + Number(summary.order_discount || 0);
  const deliveryKnown = cart.pricing.delivery && cart.pricing.delivery.is_serviceable;

  return (
    <aside className="sf-panel sf-split__side" aria-label="Price details">
      <div className="sf-panel__head"><span className="sf-panel__title">Price details</span></div>
      <div className="sf-prices">
        <div className="sf-prices__row"><span>Price ({count} {count === 1 ? 'item' : 'items'})</span><span>{rupees(summary.items_mrp_total)}</span></div>
        {savings > 0 && <div className="sf-prices__row"><span>Discount</span><span className="sf-good">− {rupees(savings)}</span></div>}
        {appliedOffer && <span className="sf-small sf-good">{appliedOffer.title} — {appliedOffer.summary}</span>}
        <div className="sf-prices__row">
          <span>Delivery charges</span>
          <span>{!deliveryKnown ? 'At checkout' : Number(summary.delivery_charge) === 0 ? <span className="sf-good">Free</span> : rupees(summary.delivery_charge)}</span>
        </div>
        {Number(payment.wallet_applied || 0) > 0 && (
          <div className="sf-prices__row"><span>Wallet credit</span><span className="sf-good">− {rupees(payment.wallet_applied)}</span></div>
        )}
        <div className="sf-prices__row sf-prices__total">
          <span>Total amount</span>
          <span>{rupees(Number(payment.wallet_applied || 0) > 0 ? payment.amount_payable : summary.grand_total)}</span>
        </div>
        {savings > 0 && <b className="sf-good" style={{ font: '700 15px var(--sf-text)' }}>You will save {rupees(savings)} on this order</b>}
        <span className="sf-small">Inclusive of {rupees(summary.tax_total)} GST</span>
        {children}
      </div>
    </aside>
  );
}

export default function Cart() {
  const navigate = useNavigate();
  const { cart, lines, count, busy, refresh, setQuantity, showToast } = useCart();
  const [offers, setOffers] = useState({});
  const [suggestions, setSuggestions] = useState([]);
  const [coupon, setCoupon] = useState('');
  const [couponBusy, setCouponBusy] = useState(false);
  const [pincodeBusy, setPincodeBusy] = useState(false);

  useEffect(() => {
    document.title = 'My Cart · 5 Star';
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
    api.get('/products', { per_page: 12, sort: 'popularity' })
      .then((payload) => setSuggestions((payload.data || [])
        .filter((p) => !inCart.has(p.uuid))
        .slice(0, 5)
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
    return <div className="sf-panel sf-panel--pad sf-muted">Loading your cart…</div>;
  }

  if (lines.length === 0) {
    return (
      <div className="sf-panel sf-center">
        <b style={{ font: '700 20px var(--sf-text)' }}>Your cart is empty!</b>
        <p>Add spices and dry fruits to it now.</p>
        <Link to="/shop" className="sf-btn sf-btn--red sf-btn--lg">SHOP NOW</Link>
      </div>
    );
  }

  const blockers = hardBlockers(cart);

  return (
    <div className="sf-split">
      <div className="sf-split__main">
        <section className="sf-panel">
          <div className="sf-panel__head"><h1 className="sf-h1" style={{ fontSize: 18 }}>My Cart ({count})</h1></div>
          <DeliveryBar cart={cart} onPincode={checkPincode} pincodeBusy={pincodeBusy} />
          {lines.map((item) => (
            <CartLine key={item.uuid} item={item} offer={offers[item.product.uuid]} busy={busy} onQuantity={setQuantity} />
          ))}
          {blockers.length > 0 && (
            <div style={{ padding: '14px 20px' }}>
              <div className="sf-error">
                <b>Before you can check out</b>
                <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{blockers.map((b) => <li key={b}>{b}</li>)}</ul>
              </div>
            </div>
          )}
          <div className="sf-placebar">
            <button type="button" className="sf-btn sf-btn--red sf-btn--lg" disabled={blockers.length > 0} onClick={() => navigate('/checkout')}>
              PLACE ORDER
            </button>
          </div>
        </section>

        {suggestions.length > 0 && (
          <section className="sf-panel">
            <div className="sf-panel__head"><h2 className="sf-h2" style={{ fontSize: 18 }}>Frequently bought together</h2></div>
            <div className="sf-pgrid">
              {suggestions.map((p) => <ProductCard key={p.uuid} product={p} />)}
            </div>
          </section>
        )}
      </div>

      <PriceDetails cart={cart} count={count}>
        <form className="sf-inline-form" onSubmit={applyCoupon}>
          <input className="sf-input" placeholder="Coupon code" aria-label="Coupon code" value={coupon}
                 onChange={(event) => setCoupon(event.target.value)} />
          <button type="submit" className="sf-btn sf-btn--outline-red" disabled={couponBusy}>{couponBusy ? '…' : 'APPLY'}</button>
        </form>
      </PriceDetails>
    </div>
  );
}
