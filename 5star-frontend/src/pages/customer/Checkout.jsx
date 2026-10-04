import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError, formatMoney } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import { useCartCount } from '../../hooks/useCartCount';
import './Checkout.css';

function AddressCard({ address, selected, onSelect }) {
  return (
    <label className="address-card">
      <input type="radio" name="address" checked={selected} onChange={() => onSelect(address.uuid)} />
      <span>
        <span className="address-card__name">
          {address.contact_name}
          {address.is_default && <span className="address-card__badge">Default</span>}
        </span>
        <span className="address-card__line">
          {[address.address_line1, address.address_line2, address.city, address.state, address.pincode]
            .filter(Boolean)
            .join(', ')}
        </span>
        <span className="address-card__line">{address.contact_mobile}</span>
      </span>
    </label>
  );
}

function NewAddressForm({ onSaved }) {
  const [form, setForm] = useState({
    contact_name: '', contact_mobile: '', address_line1: '', address_line2: '',
    city: '', state: '', pincode: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = await api.post('/addresses', form);
      onSaved(response.data.address.uuid);
      setForm({ contact_name: '', contact_mobile: '', address_line1: '', address_line2: '', city: '', state: '', pincode: '' });
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  function set(field) {
    return (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  return (
    <form className="address-form" onSubmit={handleSubmit}>
      <h3>Add a delivery address</h3>
      {error && <div className="form-error">{error}</div>}
      <div className="address-form__grid">
        <input placeholder="Full name" required value={form.contact_name} onChange={set('contact_name')} />
        <input placeholder="Mobile number" required value={form.contact_mobile} onChange={set('contact_mobile')} />
        <input className="full" placeholder="Address line 1" required value={form.address_line1} onChange={set('address_line1')} />
        <input className="full" placeholder="Address line 2 (optional)" value={form.address_line2} onChange={set('address_line2')} />
        <input placeholder="City" required value={form.city} onChange={set('city')} />
        <input placeholder="State" required value={form.state} onChange={set('state')} />
        <input placeholder="Pincode" required inputMode="numeric" value={form.pincode} onChange={set('pincode')} />
      </div>
      <button type="submit" className="btn-outline" disabled={busy}>{busy ? 'Saving…' : 'Save address'}</button>
    </form>
  );
}

function SummaryPanel({ review, paymentMethod, onPaymentMethodChange, onPlace, placing }) {
  const pricing = review.cart.pricing.summary;
  const payment = review.cart.payment;
  const checkout = review.checkout;

  return (
    <div className="order-summary">
      <h2>Order summary</h2>
      <dl className="order-summary__rows">
        <dt>Items</dt>
        <dd>{formatMoney(pricing.items_subtotal)}</dd>
        {Number(pricing.order_discount) > 0 && (
          <>
            <dt className="order-summary__discount-label">Discount</dt>
            <dd className="order-summary__discount-value">−{formatMoney(pricing.order_discount)}</dd>
          </>
        )}
        <dt>Delivery</dt>
        <dd>{Number(pricing.delivery_charge) === 0 ? <span className="text-success">Free</span> : formatMoney(pricing.delivery_charge)}</dd>
      </dl>
      <hr />
      <div className="order-summary__total">
        <span>Total</span><span>{formatMoney(pricing.grand_total)}</span>
      </div>
      <div className="order-summary__tax">Includes {formatMoney(pricing.tax_total)} GST</div>

      {Number(payment.wallet_applied) > 0 && (
        <>
          <div className="order-summary__wallet">
            <span>Wallet credit</span><span>−{formatMoney(payment.wallet_applied)}</span>
          </div>
          <div className="order-summary__total">
            <span>To pay by UPI</span><span>{formatMoney(payment.amount_payable)}</span>
          </div>
        </>
      )}

      {checkout.cod_available && (
        <div className="payment-method-choice">
          <label className="payment-method-choice__label">How would you like to pay?</label>
          <label className="payment-option">
            <input type="radio" name="payment_method" value="upi" checked={paymentMethod === 'upi'} onChange={() => onPaymentMethodChange('upi')} />
            <span>
              <span className="payment-option__title">Pay by UPI</span>
              <span className="payment-option__note">Scan a QR code or pay with any UPI app.</span>
            </span>
          </label>
          <label className="payment-option">
            <input type="radio" name="payment_method" value="cod" checked={paymentMethod === 'cod'} onChange={() => onPaymentMethodChange('cod')} />
            <span>
              <span className="payment-option__title">Cash on Delivery</span>
              <span className="payment-option__note">
                Pay {formatMoney(pricing.grand_total)} in cash when your order arrives. We confirm COD orders within a
                few hours.
              </span>
            </span>
          </label>
        </div>
      )}

      {checkout.blockers.length > 0 && (
        <div className="alert alert-warning">
          <ul>{checkout.blockers.map((b) => <li key={b}>{b}</li>)}</ul>
        </div>
      )}

      <button type="button" className="btn-marigold btn-block" disabled={checkout.blockers.length > 0 || placing} onClick={onPlace}>
        {placing ? 'Placing your order…' : 'Place order'}
      </button>
      <p className="payment-method-note">
        {paymentMethod === 'cod'
          ? 'You will confirm with an OTP. Our team approves Cash on Delivery orders within a few hours; pay in cash when your order arrives.'
          : 'You will confirm with an OTP, then pay by UPI. Your order is not confirmed until payment is received.'}
      </p>
    </div>
  );
}

function OtpStep({ order, otp, onResend, onVerify, resending }) {
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await onVerify(code);
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <div className="checkout-centered">
      <div className="checkout-card">
        <h1>Confirm your order</h1>
        <p className="text-muted">
          Order <span className="fw-semibold">{order.order_number}</span>. We have sent a code to{' '}
          {(otp && otp.sent_to) || 'your mobile'}.
        </p>

        {otp && otp.debug_otp && (
          <div className="account-info">Development mode: your code is <span className="fw-semibold">{otp.debug_otp}</span>.</div>
        )}

        {error && <div className="form-error">{error}</div>}

        <form onSubmit={handleSubmit}>
          <label htmlFor="otp">Verification code</label>
          <input
            id="otp"
            className="otp-input"
            inputMode="numeric"
            maxLength={6}
            autoComplete="one-time-code"
            required
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
          <button type="submit" className="btn-marigold btn-block" disabled={busy}>
            {busy ? 'Verifying…' : 'Confirm and continue'}
          </button>
        </form>

        <div className="checkout-centered__footer">
          <button type="button" className="btn-link" onClick={onResend} disabled={resending}>
            {resending ? 'Sending…' : 'Resend the code'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function Checkout() {
  const { signedIn, ready } = useAuth();
  const { refresh: refreshCartCount } = useCartCount();

  const [review, setReview] = useState(null);
  const [addresses, setAddresses] = useState([]);
  const [selectedAddress, setSelectedAddress] = useState(null);
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

  async function handleAddressChange(uuid) {
    setSelectedAddress(uuid);
    setStatus('loading');
    try {
      await loadReview(uuid);
      setStatus('ready');
    } catch (err) {
      setError(err.message);
      setStatus('error');
    }
  }

  async function handlePlaceOrder() {
    setPlacing(true);
    try {
      const response = await api.post('/checkout/place', {
        address_uuid: selectedAddress,
        expected_grand_total: review.cart.pricing.summary.grand_total,
        payment_method: paymentMethod,
      });

      setOrder(response.data.order);
      setOtpReference(response.data.otp && response.data.otp.reference_token);
      setOtp(response.data.otp);
      refreshCartCount();
      setPhase('otp');
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setError(err.message);
        await loadReview(selectedAddress);
      } else {
        setError(err.message);
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
          refreshCartCount();
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
      refreshCartCount();
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
          refreshCartCount();
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
    return <div className="page"><p className="state-message">Loading checkout…</p></div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="page checkout-centered">
        <div className="checkout-card checkout-card--center">
          <h1>Please sign in to check out</h1>
          <p className="text-muted">Your cart will be waiting.</p>
          <Link className="btn-marigold" to="/account?next=/checkout">Sign in or create an account</Link>
        </div>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load checkout: {error}</p></div>;
  }

  if (phase === 'otp') {
    return (
      <div className="page">
        <OtpStep order={order} otp={otp} onResend={handleResendOtp} onVerify={handleVerifyOtp} resending={resending} />
      </div>
    );
  }

  if (phase === 'cod-wait') {
    return (
      <div className="page checkout-centered">
        <div className="checkout-card checkout-card--center">
          <h1>Cash on Delivery selected</h1>
          <p className="text-muted">Order {order.order_number}</p>
          <div className="alert alert-light">
            Our team reviews Cash on Delivery orders before they are prepared — usually within a few hours. You will
            pay <strong>{formatMoney(order.amount_payable)}</strong> in cash when your order is delivered. No payment
            is due now.
          </div>
          <div className="alert alert-light">
            {pollStatus === 'cancelled' ? 'This order was cancelled.' : pollStatus === 'timeout' ? (
              <>Still waiting on our team. We will message you as soon as it is approved. <Link to="/orders">Check your orders</Link></>
            ) : 'Waiting for approval…'}
          </div>
          <p className="text-muted small">
            You can close this page. We will message you as soon as your order is approved, and you can always check
            its status under My Orders.
          </p>
        </div>
      </div>
    );
  }

  if (phase === 'pay' && payment) {
    const isManual = payment.gateway === 'manual';
    const isQrImageUrl = isManual && typeof payment.qr_payload === 'string' && /^https?:\/\//i.test(payment.qr_payload);

    return (
      <div className="page checkout-centered">
        <div className="checkout-card checkout-card--center">
          <h1>Pay {formatMoney(payment.amount)}</h1>
          <p className="text-muted">Order {order.order_number}</p>

          {!isManual && payment.upi_intent_url && (
            <a className="btn-marigold btn-block" href={payment.upi_intent_url}>Pay with a UPI app</a>
          )}

          {isQrImageUrl && (
            <>
              <p className="text-muted small">Scan this QR code with any UPI app to pay.</p>
              <img src={payment.qr_payload} alt="Payment QR code" className="payment-qr" />
              {payment.upi_intent_url && (
                <a className="btn-outline btn-block" href={payment.upi_intent_url}>Or pay with a UPI app</a>
              )}
              <p className="text-muted small">
                After paying, keep your payment reference handy — our team verifies manual payments and confirms your
                order, usually within a few hours.
              </p>
            </>
          )}

          {!isManual && payment.qr_payload && !isQrImageUrl && (
            <>
              <p className="text-muted small">Or scan this with any UPI app.</p>
              <div className="payment-qr-text">{payment.qr_payload}</div>
            </>
          )}

          <div className="alert alert-light">
            {pollStatus === 'cancelled'
              ? 'This order was cancelled because payment was not completed in time.'
              : pollStatus === 'timeout-manual'
                ? <>We have not confirmed your payment yet. Our team reviews manual payments within a few hours and will message you as soon as it is verified. <Link to="/orders">Check your orders</Link></>
                : pollStatus === 'timeout-auto'
                  ? <>We have not seen your payment yet. If money has left your account it will be matched automatically within a few minutes and we will message you. <Link to="/orders">Check your orders</Link></>
                  : 'Waiting for your payment to be confirmed…'}
          </div>

          <p className="text-muted small">
            You can close this page. Your order will be confirmed as soon as the payment reaches us, and we will send
            you a message.
          </p>
        </div>
      </div>
    );
  }

  if (phase === 'confirmed') {
    return (
      <div className="page checkout-centered">
        <div className="checkout-card checkout-card--center">
          <div className="checkout-check">✓</div>
          <h1>Your order is confirmed</h1>
          <p className="text-muted">
            Order {order.order_number}{order.invoice_number ? ` · Invoice ${order.invoice_number}` : ''}
          </p>
          <p className="text-muted small">We are preparing it now and will send tracking details as soon as it ships.</p>
          <Link className="btn-marigold" to="/orders">View your orders</Link>
          <Link className="btn-link" to="/">Continue shopping</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="page checkout-page">
      <h1 className="page-title">Checkout</h1>
      {error && <div className="form-error">{error}</div>}
      <div className="checkout-layout">
        <div className="checkout-main">
          <div className="checkout-panel">
            <h2>Deliver to</h2>
            <div className="address-list">
              {addresses.length === 0 && <div className="text-muted small">No saved addresses yet.</div>}
              {addresses.map((address) => (
                <AddressCard
                  key={address.uuid}
                  address={address}
                  selected={selectedAddress === address.uuid}
                  onSelect={handleAddressChange}
                />
              ))}
            </div>
            <NewAddressForm onSaved={handleAddressChange} />
          </div>
        </div>

        <div className="checkout-summary">
          <SummaryPanel
            review={review}
            paymentMethod={paymentMethod}
            onPaymentMethodChange={setPaymentMethod}
            onPlace={handlePlaceOrder}
            placing={placing}
          />
        </div>
      </div>
    </div>
  );
}
