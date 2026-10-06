import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import { useCart } from '../../hooks/useCart';
import { packLabel, rupees, tintFor } from '../../lib/store';
import { ProductMedia } from '../../components/customer/ProductCard';
import { PriceDetails } from './Cart';
import PhoneEmailButton, { usePhoneEmail } from '../../components/PhoneEmailButton';

const EMPTY_ADDRESS = {
  contact_name: '', contact_mobile: '', address_line1: '', address_line2: '', city: '', state: '', pincode: '',
};

function AddressChoice({ address, selected, onSelect }) {
  const line = [address.address_line1, address.address_line2, address.city, address.state, address.pincode]
    .filter(Boolean).join(', ');
  return (
    <button type="button" className={`sf-choice${selected ? ' is-on' : ''}`} onClick={() => onSelect(address.uuid)} aria-pressed={selected}>
      <span className="sf-choice__text">
        <b>{address.contact_name}{address.is_default ? ' · Default' : ''}</b>
        <span>{line}</span>
        <span>{address.contact_mobile}</span>
      </span>
    </button>
  );
}

function NewAddressForm({ onSaved, onCancel }) {
  const [form, setForm] = useState(EMPTY_ADDRESS);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const set = (field) => (event) => setForm((f) => ({ ...f, [field]: event.target.value }));

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = await api.post('/addresses', form);
      setForm(EMPTY_ADDRESS);
      onSaved(response.data.address.uuid);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="sf-form-grid" onSubmit={handleSubmit}>
      {error && <div className="sf-error sf-field--full">{error}</div>}
      <label className="sf-field">Full name<input required placeholder="Priya Sharma" autoComplete="name" value={form.contact_name} onChange={set('contact_name')} /></label>
      <label className="sf-field">Mobile<input required inputMode="numeric" maxLength={10} placeholder="9876543210" autoComplete="tel-national" value={form.contact_mobile} onChange={set('contact_mobile')} /></label>
      <label className="sf-field sf-field--full">Address<input required placeholder="House no., street, area" autoComplete="address-line1" value={form.address_line1} onChange={set('address_line1')} /></label>
      <label className="sf-field sf-field--full">Landmark (optional)<input placeholder="Near…" autoComplete="address-line2" value={form.address_line2} onChange={set('address_line2')} /></label>
      <label className="sf-field">Pincode<input required inputMode="numeric" maxLength={6} placeholder="560001" autoComplete="postal-code" value={form.pincode} onChange={set('pincode')} /></label>
      <label className="sf-field">City<input required placeholder="Bengaluru" autoComplete="address-level2" value={form.city} onChange={set('city')} /></label>
      <label className="sf-field">State<input required placeholder="Karnataka" autoComplete="address-level1" value={form.state} onChange={set('state')} /></label>
      <div className="sf-field--full" style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <button type="submit" className="sf-btn sf-btn--red" disabled={busy}>{busy ? 'Saving…' : 'SAVE AND DELIVER HERE'}</button>
        {onCancel && <button type="button" className="sf-btn sf-btn--ghost" onClick={onCancel}>CANCEL</button>}
      </div>
    </form>
  );
}

function OtpStep({ order, otp, onResend, onVerify, onVerifyPhone, resending }) {
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const phoneEmailClient = usePhoneEmail();
  const notSent = otp && otp.delivery === 'not_sent' && !otp.debug_otp;

  async function run(action) {
    setBusy(true);
    setError(null);
    try {
      await action();
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  function handleSubmit(event) {
    event.preventDefault();
    run(() => onVerify(code));
  }

  const sentTo = (otp && otp.sent_to) || 'your mobile';

  return (
    <div className="sf-panel sf-center">
      <h1 className="sf-h1">Confirm your order</h1>
      {phoneEmailClient ? (
        <p>
          Order <b>{order.order_number}</b>. Verify the delivery mobile number {otp && otp.sent_to ? <b>{otp.sent_to}</b> : null} to confirm it.
        </p>
      ) : notSent ? (
        <p>
          Order <b>{order.order_number}</b>. We couldn&apos;t text you a code — this shop hasn&apos;t switched on text
          messages yet. Please contact the shop for your code, or try again later.
        </p>
      ) : (
        <p>Order <b>{order.order_number}</b>. We have sent a code to {sentTo}.</p>
      )}
      {otp && otp.debug_otp && <div className="sf-status">Test mode: your code is <b>{otp.debug_otp}</b>.</div>}
      {error && <div className="sf-error">{error}</div>}

      {phoneEmailClient && (
        <PhoneEmailButton clientId={phoneEmailClient} onVerified={(url) => run(() => onVerifyPhone(url))} disabled={busy} />
      )}

      {!(phoneEmailClient && notSent) && (
        <form className="sf-otp" onSubmit={handleSubmit}>
          {phoneEmailClient && <p className="sf-small">Or enter the code we texted to {sentTo}.</p>}
          <label className="sf-field">
            Verification code
            <input inputMode="numeric" maxLength={6} autoComplete="one-time-code" required value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
          </label>
          <button type="submit" className="sf-btn sf-btn--red sf-btn--lg" disabled={busy}>{busy ? 'Verifying…' : 'CONFIRM AND CONTINUE'}</button>
          <button type="button" className="sf-btn sf-btn--ghost" onClick={onResend} disabled={resending}>
            {resending ? 'Sending…' : 'Resend the code'}
          </button>
        </form>
      )}
    </div>
  );
}

function StepHead({ num, title }) {
  return (
    <div className="sf-step__head"><span className="sf-step__num">{num}</span>{title}</div>
  );
}

function OrderSummary({ items }) {
  return (
    <div>
      {items.map((item) => (
        <div key={item.uuid} className="sf-mini-line">
          <ProductMedia tint={tintFor(item.product.slug)} />
          <div className="sf-mini-line__text">
            <b>{item.product.name}</b>
            <span>{packLabel(item.variant.weight_grams) || item.variant.name} × {item.quantity}</span>
          </div>
          <span>{rupees(item.line_total)}</span>
        </div>
      ))}
    </div>
  );
}

export default function Checkout() {
  const { signedIn, ready } = useAuth();
  const { refresh: refreshCart } = useCart();

  const [review, setReview] = useState(null);
  const [addresses, setAddresses] = useState([]);
  const [selectedAddress, setSelectedAddress] = useState(null);
  const [addingAddress, setAddingAddress] = useState(false);
  const [paymentMethod, setPaymentMethod] = useState('upi');
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);

  const [phase, setPhase] = useState('review'); // review | otp | cod-wait | pay | confirmed
  const [order, setOrder] = useState(null);
  const [otp, setOtp] = useState(null);
  const [otpReference, setOtpReference] = useState(null);
  const [placing, setPlacing] = useState(false);
  const [resending, setResending] = useState(false);
  const [payment, setPayment] = useState(null);
  const [pollStatus, setPollStatus] = useState(null);

  const loadReview = useCallback(async (addressUuid) => {
    const response = await api.get('/checkout/review', { address_uuid: addressUuid || undefined });
    const data = response.data;
    setReview(data);
    setAddresses(data.addresses || []);
    if (!addressUuid && data.selected_address) {
      setSelectedAddress(data.selected_address.uuid);
    } else if (addressUuid) {
      setSelectedAddress(addressUuid);
    }
  }, []);

  useEffect(() => {
    document.title = 'Checkout · 5 Star';
  }, []);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setStatus('signed-out');
      return;
    }

    setStatus('loading');
    loadReview()
      .then(() => setStatus('ready'))
      .catch((err) => {
        setError(err.message);
        setStatus('error');
      });
  }, [ready, signedIn, loadReview]);

  useEffect(() => {
    if (review && !review.checkout.cod_available && paymentMethod === 'cod') setPaymentMethod('upi');
  }, [review, paymentMethod]);

  async function handleAddressChange(uuid) {
    setSelectedAddress(uuid);
    setAddingAddress(false);
    try {
      await loadReview(uuid);
    } catch (err) {
      setError(err.message);
    }
  }

  async function handlePlaceOrder() {
    setPlacing(true);
    setError(null);
    try {
      const response = await api.post('/checkout/place', {
        address_uuid: selectedAddress,
        expected_grand_total: review.cart.pricing.summary.grand_total,
        payment_method: paymentMethod,
      });

      setOrder(response.data.order);
      setOtpReference(response.data.otp && response.data.otp.reference_token);
      setOtp(response.data.otp);
      refreshCart();
      setPhase('otp');
      window.scrollTo(0, 0);
    } catch (err) {
      setError(err.message);
      if (err instanceof ApiError && err.status === 409) {
        await loadReview(selectedAddress);
      }
    } finally {
      setPlacing(false);
    }
  }

  async function handleVerifyOtp(code) {
    await api.post(`/checkout/orders/${order.uuid}/verify-otp`, { otp: code, reference_token: otpReference });

    if (order.payment_method === 'cod') {
      await chooseCod();
    } else {
      await startPayment();
    }
  }

  async function handleVerifyPhone(userJsonUrl) {
    await api.post(`/checkout/orders/${order.uuid}/verify-phone`, { user_json_url: userJsonUrl });

    if (order.payment_method === 'cod') {
      await chooseCod();
    } else {
      await startPayment();
    }
  }

  async function handleResendOtp() {
    setResending(true);
    try {
      const response = await api.post(`/checkout/orders/${order.uuid}/resend-otp`, {});
      setOtpReference(response.data.reference_token);
    } finally {
      setResending(false);
    }
  }

  async function chooseCod() {
    const response = await api.post(`/checkout/orders/${order.uuid}/cod`, {});
    setOrder(response.data.order);
    setPhase('cod-wait');
    setPollStatus('waiting');
    pollCodApproval(0, response.data.order.uuid);
  }

  function pollCodApproval(attempt, uuid) {
    setTimeout(async () => {
      try {
        const response = await api.get(`/orders/${uuid}`);
        const o = response.data.order;
        if (o.status === 'confirmed') {
          setOrder(o);
          setPhase('confirmed');
          refreshCart();
          return;
        }
        if (o.status === 'cancelled') {
          setPollStatus('cancelled');
          return;
        }
      } catch {
        // keep polling
      }

      if (attempt >= 40) {
        setPollStatus('timeout');
        return;
      }
      pollCodApproval(attempt + 1, uuid);
    }, attempt === 0 ? 0 : 5000);
  }

  async function startPayment() {
    const response = await api.post(`/checkout/orders/${order.uuid}/payment`, {});

    if (response.data.fully_paid_by_wallet) {
      setPhase('confirmed');
      refreshCart();
      return;
    }

    setPayment(response.data.payment);
    setPhase('pay');
    setPollStatus('waiting');
    pollPayment(0, response.data.payment.gateway === 'manual');
  }

  function pollPayment(attempt, isManual) {
    const delay = attempt === 0 ? 0 : (isManual ? 5000 : 2000);
    setTimeout(async () => {
      try {
        const response = await api.get(`/orders/${order.uuid}`);
        const o = response.data.order;
        if (o.payment_status === 'paid') {
          setOrder(o);
          setPhase('confirmed');
          refreshCart();
          return;
        }
        if (o.status === 'cancelled') {
          setPollStatus('cancelled');
          return;
        }
      } catch {
        // keep polling
      }

      const maxAttempts = isManual ? 40 : 15;
      if (attempt >= maxAttempts) {
        setPollStatus(isManual ? 'timeout-manual' : 'timeout-auto');
        return;
      }
      pollPayment(attempt + 1, isManual);
    }, delay);
  }

  if (!ready || status === 'loading') {
    return <div className="sf-panel sf-panel--pad sf-muted">Loading checkout…</div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">Sign in to check out</h1>
        <p>Your cart will be waiting. Signing in lets us send your order updates and keep your addresses.</p>
        <Link className="sf-btn sf-btn--red sf-btn--lg" to="/account?next=/checkout">SIGN IN OR CREATE AN ACCOUNT</Link>
        <Link className="sf-btn sf-btn--ghost" to="/cart">Back to cart</Link>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="sf-panel sf-panel--pad"><div className="sf-error">Checkout could not be loaded: {error}</div></div>;
  }

  if (phase === 'otp') {
    return <OtpStep order={order} otp={otp} onResend={handleResendOtp} onVerify={handleVerifyOtp} onVerifyPhone={handleVerifyPhone} resending={resending} />;
  }

  if (phase === 'cod-wait') {
    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">Cash on delivery selected</h1>
        <p>Order <b>{order.order_number}</b>. Our team reviews cash-on-delivery orders before they are prepared — usually within a few hours. You will pay <b>{rupees(order.amount_payable)}</b> in cash when it arrives.</p>
        <div className="sf-status">
          {pollStatus === 'cancelled' ? 'This order was cancelled.' : pollStatus === 'timeout' ? (
            <>Still waiting on our team. We will message you once it is approved. <Link to="/orders">Check your orders</Link></>
          ) : 'Waiting for approval…'}
        </div>
        <p className="sf-small">You can close this page — you can always check its status under My orders.</p>
      </div>
    );
  }

  if (phase === 'pay' && payment) {
    const isManual = payment.gateway === 'manual';
    const isQrImageUrl = isManual && typeof payment.qr_payload === 'string' && /^https?:\/\//i.test(payment.qr_payload);

    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">Pay {rupees(payment.amount)}</h1>
        <p>Order <b>{order.order_number}</b></p>

        {!isManual && payment.upi_intent_url && (
          <a className="sf-btn sf-btn--red sf-btn--lg sf-btn--block" href={payment.upi_intent_url}>Pay with a UPI app</a>
        )}

        {isQrImageUrl && (
          <>
            <p className="sf-small">Scan this QR code with GPay, PhonePe, Paytm or any UPI app.</p>
            <img src={payment.qr_payload} alt="UPI payment QR code" className="sf-qr" />
            {payment.upi_intent_url && (
              <a className="sf-btn sf-btn--outline sf-btn--block" href={payment.upi_intent_url}>Or pay with a UPI app</a>
            )}
            <p className="sf-small">After paying, keep your payment reference handy — we verify manual payments and confirm your order, usually within a few hours.</p>
          </>
        )}

        {!isManual && payment.qr_payload && !isQrImageUrl && (
          <>
            <p className="sf-small">Or scan this with any UPI app.</p>
            <div className="sf-status" style={{ wordBreak: 'break-all' }}>{payment.qr_payload}</div>
          </>
        )}

        <div className="sf-status">
          {pollStatus === 'cancelled'
            ? 'This order was cancelled because payment was not completed in time.'
            : pollStatus === 'timeout-manual'
              ? <>We have not confirmed your payment yet. We review manual payments within a few hours and will message you. <Link to="/orders">Check your orders</Link></>
              : pollStatus === 'timeout-auto'
                ? <>We have not seen your payment yet. If money has left your account it will be matched within a few minutes. <Link to="/orders">Check your orders</Link></>
                : 'Waiting for your payment to be confirmed…'}
        </div>
      </div>
    );
  }

  if (phase === 'confirmed') {
    return (
      <div className="sf-panel sf-center">
        <div className="sf-tick" aria-hidden="true">✓</div>
        <h1 className="sf-big">Order placed successfully</h1>
        <p>
          Order <b>{order.order_number}</b>
          {order.grand_total ? ` · ${rupees(order.grand_total)}` : ''}
          {order.invoice_number ? ` · Invoice ${order.invoice_number}` : ''}.
          {' '}We'll send tracking details by SMS once it's dispatched, usually within 24 hours.
        </p>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', justifyContent: 'center' }}>
          <Link className="sf-btn sf-btn--red sf-btn--lg" to="/shop">CONTINUE SHOPPING</Link>
          <Link className="sf-btn sf-btn--outline sf-btn--lg" to="/orders">VIEW YOUR ORDERS</Link>
        </div>
      </div>
    );
  }

  const cart = review.cart;
  const summary = cart.pricing.summary;
  const delivery = cart.pricing.delivery || {};
  const deliveryCharge = Number(summary.delivery_charge || 0);
  const codAvailable = Boolean(review.checkout.cod_available);
  const showForm = addingAddress || addresses.length === 0;
  const items = (cart.items || []).filter((item) => !item.is_saved_for_later);
  const units = items.reduce((total, item) => total + Number(item.quantity || 0), 0);
  const toPay = Number(cart.payment?.amount_payable ?? summary.grand_total);
  const blockers = review.checkout.blockers || [];
  const chosen = addresses.find((a) => a.uuid === selectedAddress);

  return (
    <div className="sf-split">
      <div className="sf-split__main">
        {error && <div className="sf-error">{error}</div>}

        <section className="sf-panel">
          <div className="sf-step__done">
            <span className="sf-step__num">1</span>
            <span style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1 }}>
              <span className="sf-panel__title" style={{ fontSize: 13 }}>Login <span className="sf-good">✓</span></span>
              <span style={{ font: '600 14px var(--sf-text)' }}>Signed in</span>
            </span>
            <Link to="/cart" className="sf-link-btn">BACK TO CART</Link>
          </div>
        </section>

        <section className="sf-panel">
          <StepHead num="2" title="DELIVERY ADDRESS" />
          <div className="sf-step__body">
            {addresses.length > 0 && (
              <div className="sf-choices">
                {addresses.map((address) => (
                  <AddressChoice key={address.uuid} address={address} selected={selectedAddress === address.uuid} onSelect={handleAddressChange} />
                ))}
              </div>
            )}
            {chosen && delivery.is_serviceable && delivery.estimated_days && delivery.estimated_days.max && (
              <span className="sf-small">
                Standard delivery in {delivery.estimated_days.min}–{delivery.estimated_days.max} days ·{' '}
                {deliveryCharge === 0 ? <b className="sf-good">FREE</b> : rupees(deliveryCharge)}
              </span>
            )}
            {showForm ? (
              <NewAddressForm onSaved={handleAddressChange} onCancel={addresses.length > 0 ? () => setAddingAddress(false) : null} />
            ) : (
              <div><button type="button" className="sf-link-btn" onClick={() => setAddingAddress(true)}>+ ADD A NEW ADDRESS</button></div>
            )}
          </div>
        </section>

        <section className="sf-panel">
          <StepHead num="3" title={`ORDER SUMMARY (${units} ${units === 1 ? 'ITEM' : 'ITEMS'})`} />
          <OrderSummary items={items} />
        </section>

        <section className="sf-panel">
          <StepHead num="4" title="PAYMENT OPTIONS" />
          <div role="radiogroup" aria-label="Payment method">
            <button type="button" role="radio" aria-checked={paymentMethod === 'upi'}
                    className={`sf-radio-row${paymentMethod === 'upi' ? ' is-on' : ''}`} onClick={() => setPaymentMethod('upi')}>
              <span className="sf-radio" />
              <span className="sf-choice__text"><b>UPI</b><span>Google Pay, PhonePe, Paytm, BHIM or any UPI app</span></span>
            </button>
            {codAvailable && (
              <button type="button" role="radio" aria-checked={paymentMethod === 'cod'}
                      className={`sf-radio-row${paymentMethod === 'cod' ? ' is-on' : ''}`} onClick={() => setPaymentMethod('cod')}>
                <span className="sf-radio" />
                <span className="sf-choice__text"><b>Cash on delivery</b><span>Pay in cash when your order arrives</span></span>
              </button>
            )}
          </div>
          <div className="sf-step__body">
            {blockers.length > 0 && (
              <div className="sf-error"><ul style={{ margin: 0, paddingLeft: 18 }}>{blockers.map((b) => <li key={b}>{b}</li>)}</ul></div>
            )}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 14, flexWrap: 'wrap' }}>
              <span className="sf-small" style={{ flex: '1 1 260px' }}>
                {paymentMethod === 'cod'
                  ? 'You will confirm with an OTP. We approve cash-on-delivery orders within a few hours; pay in cash on arrival.'
                  : 'You will confirm with an OTP, then pay by UPI. Nothing ships before payment is confirmed.'}
              </span>
              <button type="button" className="sf-btn sf-btn--red sf-btn--lg" disabled={blockers.length > 0 || placing || !selectedAddress} onClick={handlePlaceOrder}>
                {placing ? 'Placing your order…' : paymentMethod === 'upi' ? `PAY ${rupees(toPay)}` : `PLACE ORDER · ${rupees(toPay)}`}
              </button>
            </div>
          </div>
        </section>
      </div>

      <PriceDetails cart={cart} count={units} />
    </div>
  );
}
