import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { useCartCount } from '../../hooks/useCartCount';
import './Cart.css';

function offerCategoryLabel(offer) {
  return offer.discount_type === 'free_items' ? 'Combo offer' : 'Special price';
}

/**
 * Which blockers actually block checkout vs. are resolved by picking an
 * address later — same rule as the vanilla cart page: a missing pincode is
 * a convenience, not a hard stop.
 */
function classifyBlockers(blockers) {
  const soft = [];
  const hard = [];

  blockers.forEach((blocker) => {
    if (String(blocker).toLowerCase().includes('enter a delivery pincode')) {
      soft.push(blocker);
    } else {
      hard.push(blocker);
    }
  });

  return { soft, hard };
}

function CartLine({ item, offer, busy, onQuantityChange, onRemove }) {
  return (
    <div className="cart-line">
      <div className="cart-line__info">
        <div className="cart-line__name">{item.product.name}</div>
        <div className="cart-line__variant">{item.variant.name} · {item.variant.sku}</div>
        {offer && (
          <div className="cart-line__offer">
            <span className="tag tag--offer">{offerCategoryLabel(offer)}</span>
            <span title={offer.title}>{offer.summary}</span>
          </div>
        )}
        {!item.is_purchasable && (
          <div className="cart-line__warning">{item.unavailable_reason || 'Unavailable'}</div>
        )}
        {item.price_changed && <div className="cart-line__price-changed">Price changed since you added this.</div>}
      </div>

      <div className="cart-line__controls">
        <input
          type="number"
          min="0"
          max={item.variant.max_order_quantity || 500}
          defaultValue={item.quantity}
          disabled={busy}
          onBlur={(event) => onQuantityChange(item.uuid, Number(event.currentTarget.value))}
          aria-label="Quantity"
        />
        <div className="cart-line__price">
          <div className="cart-line__price-now">{formatMoney(item.line_total)}</div>
          <div className="cart-line__price-each">{formatMoney(item.unit_price)} each</div>
        </div>
        <button type="button" className="btn-outline" disabled={busy} onClick={() => onRemove(item.uuid)}>
          Remove
        </button>
      </div>
    </div>
  );
}

function OrderSummary({ cart, pincodeInput, onPincodeChange, onPincodeSubmit, pincodeBusy }) {
  const pricing = cart.pricing.summary;
  const payment = cart.payment || {};
  const allBlockers = (cart.checkout && cart.checkout.blockers) || [];
  const { soft, hard } = classifyBlockers(allBlockers);
  const delivery = cart.pricing.delivery || {};

  return (
    <div className="order-summary">
      <h2>Order summary</h2>

      <dl className="order-summary__rows">
        <dt>Items</dt>
        <dd>{formatMoney(pricing.items_subtotal)}</dd>

        {Number(pricing.order_discount) > 0 && (
          <>
            <dt className="order-summary__discount-label">
              {cart.promotions.applied_offer ? cart.promotions.applied_offer.title : 'Discount'}
            </dt>
            <dd className="order-summary__discount-value">−{formatMoney(pricing.order_discount)}</dd>
          </>
        )}

        <dt>Delivery</dt>
        <dd>{Number(pricing.delivery_charge) === 0 ? <span className="text-success">Free</span> : formatMoney(pricing.delivery_charge)}</dd>
      </dl>

      {cart.promotions.applied_offer && (
        <div className="order-summary__offer-note">{cart.promotions.applied_offer.summary}</div>
      )}

      <hr />
      <div className="order-summary__total">
        <span>Total</span><span>{formatMoney(pricing.grand_total)}</span>
      </div>
      <div className="order-summary__tax">Includes {formatMoney(pricing.tax_total)} GST</div>

      {Number(pricing.total_savings) > 0 && (
        <div className="order-summary__savings">You saved {formatMoney(pricing.total_savings)} on this order</div>
      )}

      {Number(payment.wallet_applied || 0) > 0 && (
        <>
          <div className="order-summary__wallet">
            <span>Wallet credit</span><span>−{formatMoney(payment.wallet_applied)}</span>
          </div>
          <div className="order-summary__total">
            <span>To pay</span><span>{formatMoney(payment.amount_payable)}</span>
          </div>
        </>
      )}

      <form className="pincode-form" onSubmit={onPincodeSubmit}>
        <label htmlFor="pincode">Delivery pincode</label>
        <div className="pincode-form__row">
          <input
            id="pincode"
            inputMode="numeric"
            maxLength={6}
            pattern="\d{6}"
            placeholder="560001"
            value={pincodeInput}
            onChange={(event) => onPincodeChange(event.target.value)}
          />
          <button type="submit" className="btn-outline" disabled={pincodeBusy}>
            {pincodeBusy ? '…' : 'Check'}
          </button>
        </div>
        {delivery.pincode && delivery.is_serviceable === false ? (
          <div className="form-text form-text--error">We do not deliver to that pincode yet.</div>
        ) : delivery.estimated_days ? (
          <div className="form-text form-text--success">
            Delivers in {delivery.estimated_days.min}–{delivery.estimated_days.max} days
          </div>
        ) : (
          <div className="form-text">
            Optional — enter it to see the delivery charge now. You can also just continue and pick your address at
            checkout.
          </div>
        )}
      </form>

      {hard.length > 0 && (
        <div className="alert alert-warning">
          <div className="alert__title">Before you can check out</div>
          <ul>
            {hard.map((b) => <li key={b}>{b}</li>)}
          </ul>
        </div>
      )}

      {soft.length > 0 && hard.length === 0 && (
        <p className="text-muted small">Delivery is worked out once we know where it is going.</p>
      )}

      {hard.length === 0 ? (
        <Link className="btn-marigold checkout-cta" to="/checkout">Checkout</Link>
      ) : (
        <span className="btn-marigold checkout-cta checkout-cta--disabled" aria-disabled="true">Checkout</span>
      )}
      <div className="text-center text-muted small">Prepaid UPI only</div>
    </div>
  );
}

export default function Cart() {
  const { refresh: refreshCartCount } = useCartCount();
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);
  const [cart, setCart] = useState(null);
  const [items, setItems] = useState([]);
  const [offersByProduct, setOffersByProduct] = useState({});
  const [busyItem, setBusyItem] = useState(null);
  const [pincodeInput, setPincodeInput] = useState('');
  const [pincodeBusy, setPincodeBusy] = useState(false);
  const [couponInput, setCouponInput] = useState('');
  const [couponBusy, setCouponBusy] = useState(false);
  const [toast, setToastMsg] = useState(null);

  const load = useCallback(async () => {
    setStatus('loading');
    try {
      const response = await api.get('/cart');
      const c = response.data;
      const activeItems = (c.items || []).filter((item) => !item.is_saved_for_later);

      setCart(c);
      setItems(activeItems);
      setPincodeInput((c.pricing.delivery && c.pricing.delivery.pincode) || '');
      setCouponInput((c.promotions.applied_coupon || {}).code || '');
      setStatus('ready');
      refreshCartCount();

      if (activeItems.length > 0) {
        try {
          const uuids = [...new Set(activeItems.map((item) => item.product.uuid))];
          const lookup = await api.get('/offers/product-lookup', { product_uuids: uuids.join(',') });
          setOffersByProduct((lookup.data && lookup.data.offers) || {});
        } catch {
          setOffersByProduct({});
        }
      }
    } catch (err) {
      setError(err.message);
      setStatus('error');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToastMsg(null), 4000);
    return () => clearTimeout(timer);
  }, [toast]);

  async function handleQuantityChange(uuid, quantity) {
    setBusyItem(uuid);
    try {
      if (quantity <= 0) {
        await api.delete(`/cart/items/${uuid}`);
      } else {
        await api.patch(`/cart/items/${uuid}`, { quantity });
      }
      await load();
    } catch (err) {
      setToastMsg({ text: err.message, variant: 'danger' });
      await load();
    } finally {
      setBusyItem(null);
    }
  }

  async function handleRemove(uuid) {
    setBusyItem(uuid);
    try {
      await api.delete(`/cart/items/${uuid}`);
      setToastMsg({ text: 'Removed from your cart.', variant: 'success' });
      await load();
    } catch (err) {
      setToastMsg({ text: err.message, variant: 'danger' });
    } finally {
      setBusyItem(null);
    }
  }

  async function handlePincodeSubmit(event) {
    event.preventDefault();
    if (!/^\d{6}$/.test(pincodeInput)) {
      setToastMsg({ text: 'An Indian pincode is six digits.', variant: 'danger' });
      return;
    }

    setPincodeBusy(true);
    try {
      await api.post('/cart/pincode', { pincode: pincodeInput });
      await load();
    } catch (err) {
      setToastMsg({ text: err.message, variant: 'danger' });
    } finally {
      setPincodeBusy(false);
    }
  }

  async function handleCouponSubmit(event) {
    event.preventDefault();
    setCouponBusy(true);
    try {
      if (!couponInput) {
        await api.delete('/cart/coupon');
        setToastMsg({ text: 'Coupon removed.', variant: 'success' });
      } else {
        const result = await api.post('/cart/coupon', { coupon_code: couponInput });
        setToastMsg({ text: result.message || 'Coupon applied.', variant: 'success' });
      }
      await load();
    } catch (err) {
      setToastMsg({ text: err.message, variant: 'danger' });
    } finally {
      setCouponBusy(false);
    }
  }

  if (status === 'loading') {
    return <div className="page"><p className="state-message">Loading your cart…</p></div>;
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load your cart: {error}</p></div>;
  }

  if (items.length === 0) {
    return (
      <div className="page cart-empty">
        <h1 className="page-title">Your cart is empty</h1>
        <p className="text-muted">Nothing added yet.</p>
        <Link className="btn-marigold" to="/">Browse the shop</Link>
      </div>
    );
  }

  return (
    <div className="page cart-page">
      <h1 className="page-title">Your cart</h1>
      <div className="cart-layout">
        <div className="cart-items">
          {items.map((item) => (
            <CartLine
              key={item.uuid}
              item={item}
              offer={offersByProduct[item.product.uuid]}
              busy={busyItem === item.uuid}
              onQuantityChange={handleQuantityChange}
              onRemove={handleRemove}
            />
          ))}

          <form className="coupon-form" onSubmit={handleCouponSubmit}>
            <label htmlFor="coupon">Coupon code</label>
            <div className="coupon-form__row">
              <input
                id="coupon"
                value={couponInput}
                onChange={(event) => setCouponInput(event.target.value)}
                placeholder="e.g. WELCOME10"
              />
              <button type="submit" className="btn-outline" disabled={couponBusy}>
                {couponBusy ? '…' : 'Apply'}
              </button>
            </div>
          </form>
        </div>

        <div className="cart-summary">
          <OrderSummary
            cart={cart}
            pincodeInput={pincodeInput}
            onPincodeChange={setPincodeInput}
            onPincodeSubmit={handlePincodeSubmit}
            pincodeBusy={pincodeBusy}
          />
        </div>
      </div>

      {toast && <div className={`toast-pop toast-pop--${toast.variant}`}>{toast.text}</div>}
    </div>
  );
}
