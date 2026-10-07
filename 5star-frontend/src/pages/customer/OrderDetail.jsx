import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { rupees, tintFor } from '../../lib/store';
import { useAuth } from '../../hooks/useAuth';
import { ProductMedia } from '../../components/customer/ProductCard';
import { StatusBadge, formatDate } from '../../components/customer/OrderCard';
import PaymentPanel from '../../components/customer/PaymentPanel';

const OFF_PATH = ['cancelled', 'returned', 'refunded'];

const PAYMENT_TONE = { paid: 'good', refunded: 'muted', partially_refunded: 'muted', failed: 'warn', pending: 'warn', processing: 'info' };

// ---------------------------------------------------------------------------
// "Confirm your order" — verify the delivery mobile with an SMS code.
// ---------------------------------------------------------------------------
function VerifyOrder({ order, onVerified }) {
  const [challenge, setChallenge] = useState(null);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function run(action) {
    setBusy(true);
    setError(null);
    try {
      await action();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const sendCode = () => run(async () => {
    const response = await api.post(`/checkout/orders/${order.uuid}/resend-otp`, {});
    setChallenge(response.data);
  });

  const submitCode = (event) => {
    event.preventDefault();
    run(async () => {
      await api.post(`/checkout/orders/${order.uuid}/verify-otp`, { otp: code, reference_token: challenge?.reference_token });
      await onVerified();
    });
  };

  const notSent = challenge && challenge.delivery === 'not_sent' && !challenge.debug_otp;

  return (
    <>
      <p className="sf-small" style={{ margin: 0 }}>
        Confirm the delivery mobile number for this order so we can process it.
      </p>
      {error && <div className="sf-error">{error}</div>}
      {!challenge ? (
        <button type="button" className="sf-btn sf-btn--outline sf-btn--sm" onClick={sendCode} disabled={busy}>
          {busy ? 'Sending…' : 'TEXT ME A CODE'}
        </button>
      ) : notSent ? (
        <div className="sf-note">We couldn’t text you a code — text messages aren’t switched on for this shop yet. Please contact the shop for your code, or try again later.</div>
      ) : challenge.delivery === 'failed' ? (
        <div className="sf-error">
          We couldn’t send the code just now.{' '}
          <button type="button" className="sf-link-btn" onClick={sendCode} disabled={busy}>{busy ? 'Sending…' : 'Try again'}</button>
        </div>
      ) : (
        <form className="sf-otp-inline" onSubmit={submitCode}>
          <label className="sf-field">
            Code sent to {challenge.sent_to || 'your mobile'}
            <input inputMode="numeric" maxLength={6} autoComplete="one-time-code" required value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
          </label>
          <button type="submit" className="sf-btn sf-btn--red" disabled={busy}>{busy ? 'Checking…' : 'CONFIRM'}</button>
          {challenge.debug_otp && <span className="sf-small">Test mode: your code is <b>{challenge.debug_otp}</b>.</span>}
        </form>
      )}
    </>
  );
}

// ---------------------------------------------------------------------------
// Shipment tracking: courier, AWB, scan history.
// ---------------------------------------------------------------------------
function Tracking({ order, shipments, shipping }) {
  const list = shipments || [];

  if (list.length === 0) {
    const legacy = shipping.tracking_number;
    let message = 'We’ll share tracking details here as soon as your parcel is handed to the courier — usually within 24 hours of confirmation.';
    if (OFF_PATH.includes(order.status)) message = 'This order was not shipped.';
    else if (['created', 'awaiting_payment'].includes(order.status)) message = 'Tracking starts once your order is confirmed and packed.';
    return (
      <div className="sf-track">
        {legacy ? (
          <div className="sf-track__head">
            <div>
              <b>{shipping.courier_name || 'Courier'}</b>
              <span>AWB {shipping.tracking_number}</span>
            </div>
            {shipping.tracking_url && (
              <a className="sf-btn sf-btn--outline sf-btn--sm" href={shipping.tracking_url} target="_blank" rel="noopener noreferrer">TRACK ON COURIER SITE</a>
            )}
          </div>
        ) : (
          <p className="sf-small" style={{ margin: 0 }}>{message}</p>
        )}
      </div>
    );
  }

  return (
    <div className="sf-track">
      {list.map((s, index) => {
        const events = [...(s.events || [])].reverse();
        return (
          <div key={s.uuid} className="sf-track">
            {list.length > 1 && <b className="sf-small">Parcel {index + 1} of {list.length}</b>}
            <div className="sf-track__head">
              <div>
                <b>{s.courier_name || 'Courier'}{s.awb_number ? ` · AWB ${s.awb_number}` : ''}</b>
                <span>
                  {s.delivered_date
                    ? `Delivered ${formatDate(s.delivered_date, true)}`
                    : s.estimated_delivery_date
                      ? `Expected by ${formatDate(s.estimated_delivery_date)}`
                      : 'Delivery date to be confirmed'}
                </span>
                {s.last_scan_status && (
                  <span className="sf-small">
                    Latest: {s.last_scan_status}{s.last_scan_location ? `, ${s.last_scan_location}` : ''}{s.last_scan_date ? ` · ${formatDate(s.last_scan_date, true)}` : ''}
                  </span>
                )}
              </div>
              {s.tracking_url && (
                <a className="sf-btn sf-btn--outline sf-btn--sm" href={s.tracking_url} target="_blank" rel="noopener noreferrer">TRACK ON COURIER SITE</a>
              )}
            </div>
            {events.length > 0 ? (
              <ul className="sf-events">
                {events.map((e, i) => (
                  <li key={i}>
                    <b>{e.title || e.status}</b>
                    {e.description && <span>{e.description}</span>}
                    <small>{[e.location, formatDate(e.occurred_date, true)].filter(Boolean).join(' · ')}</small>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="sf-small" style={{ margin: 0 }}>Booked with the courier. Scans will appear here once the parcel is picked up.</p>
            )}
          </div>
        );
      })}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Bill: MRP → discounts (product, offer, coupon) → delivery → total → paid.
// ---------------------------------------------------------------------------
function Bill({ pricing, order }) {
  const [showTax, setShowTax] = useState(false);
  const p = pricing;
  const offer = Number(p.offer_discount || 0);
  const coupon = Number(p.coupon_discount || 0);
  const orderDiscount = Number(p.order_discount || 0);
  // A free-delivery coupon waives delivery rather than reducing the items,
  // so it is shown on the delivery line, not as a second discount.
  const couponIsDelivery = coupon > 0 && offer + coupon > orderDiscount + 0.01 && Number(p.delivery_discount) > 0;
  const otherDiscount = orderDiscount - offer - (couponIsDelivery ? 0 : coupon);
  const deliveryBefore = Number(p.delivery_charge_before_waiver || p.delivery_charge);
  const paid = ['paid', 'refunded', 'partially_refunded'].includes(order.payment_status);

  return (
    <div className="sf-bill">
      <div className="sf-bill__row"><span>Items (MRP)</span><span>{rupees(p.items_mrp_total)}</span></div>
      {p.product_discount > 0 && (
        <div className="sf-bill__row sf-bill__row--saving"><span>Product discount</span><span>−{rupees(p.product_discount)}</span></div>
      )}
      {offer > 0 && (
        <div className="sf-bill__row sf-bill__row--saving">
          <span>Offer{p.offer_title ? `: ${p.offer_title}` : ''}{p.offer_code ? ` (${p.offer_code})` : ''}</span>
          <span>−{rupees(offer)}</span>
        </div>
      )}
      {coupon > 0 && !couponIsDelivery && (
        <div className="sf-bill__row sf-bill__row--saving">
          <span>Coupon {p.coupon_code}{p.coupon_title ? ` — ${p.coupon_title}` : ''}</span>
          <span>−{rupees(coupon)}</span>
        </div>
      )}
      {otherDiscount > 0.01 && (
        <div className="sf-bill__row sf-bill__row--saving"><span>Other discounts</span><span>−{rupees(otherDiscount)}</span></div>
      )}
      {p.order_surcharge > 0 && (
        <div className="sf-bill__row"><span>Packing &amp; handling</span><span>{rupees(p.order_surcharge)}</span></div>
      )}
      <div className="sf-bill__row">
        <span>Delivery{couponIsDelivery ? ` (coupon ${p.coupon_code})` : ''}</span>
        <span>
          {deliveryBefore > Number(p.delivery_charge) && <s className="sf-faint">{rupees(deliveryBefore)}</s>}{' '}
          {Number(p.delivery_charge) > 0 ? rupees(p.delivery_charge) : <b className="sf-good">FREE</b>}
        </span>
      </div>
      <div className="sf-bill__row sf-bill__row--total"><span>Order total</span><span>{rupees(p.grand_total)}</span></div>
      {p.wallet_applied > 0 && (
        <div className="sf-bill__row"><span>Paid from wallet</span><span>−{rupees(p.wallet_applied)}</span></div>
      )}
      <div className="sf-bill__row">
        <b>{paid ? 'Paid' : order.payment_method === 'cod' ? 'To pay on delivery' : 'To pay'}{order.payment_method === 'cod' ? ' (cash)' : ' (UPI)'}</b>
        <b>{rupees(p.amount_payable)}</b>
      </div>
      {p.amount_refunded > 0 && (
        <div className="sf-bill__row"><span>Refunded</span><span>{rupees(p.amount_refunded)}</span></div>
      )}
      <button type="button" className="sf-bill__row" style={{ background: 'none', border: 0, padding: 0, cursor: 'pointer' }} onClick={() => setShowTax((v) => !v)}>
        <span>Includes GST {showTax ? '▴' : '▾'}</span><span>{rupees(p.tax_total)}</span>
      </button>
      {showTax && (p.tax_breakdown || []).map((t) => (
        <div key={t.gst_rate} className="sf-bill__row sf-small">
          <span>GST {t.gst_rate}% on {rupees(t.taxable_value)}</span>
          <span>{t.igst_amount > 0 ? `IGST ${rupees(t.igst_amount)}` : `CGST ${rupees(t.cgst_amount)} + SGST ${rupees(t.sgst_amount)}`}</span>
        </div>
      ))}
      {p.total_savings > 0 && <div className="sf-bill__save">You saved {rupees(p.total_savings)} on this order</div>}
    </div>
  );
}

export default function OrderDetail() {
  const { uuid } = useParams();
  const location = useLocation();
  const [search] = useSearchParams();
  const { signedIn, ready } = useAuth();
  const [status, setStatus] = useState('loading');
  const [detail, setDetail] = useState(null);
  const [shipments, setShipments] = useState(null);
  const [error, setError] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [payment, setPayment] = useState(null);
  const [starting, setStarting] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [showCancelForm, setShowCancelForm] = useState(false);

  const load = useCallback(async (quiet = false) => {
    if (!quiet) setStatus('loading');
    try {
      const response = await api.get(`/orders/${encodeURIComponent(uuid)}`);
      setDetail(response.data);
      setStatus('ready');
      api.get(`/orders/${encodeURIComponent(uuid)}/shipments`)
        .then((r) => setShipments(r.data.shipments || []))
        .catch(() => setShipments([]));
      return response.data;
    } catch (err) {
      setError(err.message);
      setStatus('error');
      return null;
    }
  }, [uuid]);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setStatus('signed-out');
      return;
    }
    load();
  }, [ready, signedIn, load]);

  useEffect(() => {
    if (detail) document.title = `${detail.order.order_number} · 5 Star`;
  }, [detail]);

  // Arriving from a "Track" link: bring the tracking panel into view.
  useEffect(() => {
    if (status === 'ready' && location.hash === '#tracking') {
      document.getElementById('tracking')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }, [status, location.hash]);

  async function startPayment() {
    setStarting(true);
    setActionError(null);
    try {
      const response = await api.post(`/checkout/orders/${uuid}/payment`, {});
      if (response.data.fully_paid_by_wallet) {
        await load(true);
      } else {
        setPayment(response.data.payment);
        load(true);
      }
    } catch (err) {
      setActionError(err.message);
    } finally {
      setStarting(false);
    }
  }

  // Straight from "Pay now" on the order list / account page.
  useEffect(() => {
    if (status === 'ready' && search.get('pay') === '1' && detail?.order.can_pay && !payment && !starting) {
      startPayment();
    }
  }, [status]); // eslint-disable-line react-hooks/exhaustive-deps

  async function afterVerified() {
    const fresh = await load(true);
    if (!fresh) return;
    if (fresh.order.payment_method === 'cod') {
      await api.post(`/checkout/orders/${uuid}/cod`, {});
      await load(true);
    } else if (fresh.order.can_pay) {
      await startPayment();
    }
  }

  async function handleCancel(event) {
    event.preventDefault();
    if (!cancelReason) return;
    setCancelling(true);
    setActionError(null);
    try {
      await api.post(`/orders/${encodeURIComponent(uuid)}/cancel`, { reason: cancelReason });
      setShowCancelForm(false);
      setCancelReason('');
      setPayment(null);
      await load(true);
    } catch (err) {
      setActionError(err.message);
    } finally {
      setCancelling(false);
    }
  }

  if (!ready || status === 'loading') {
    return <div className="sf-panel sf-panel--pad sf-muted">Loading order…</div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">Sign in to see this order</h1>
        <Link className="sf-btn sf-btn--red sf-btn--lg" to={`/account?next=/orders/${uuid}`}>SIGN IN</Link>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">Couldn’t load this order</h1>
        <p>{error}</p>
        <Link className="sf-btn sf-btn--outline" to="/orders">BACK TO MY ORDERS</Link>
      </div>
    );
  }

  const { order, items, timeline, progress, pricing, shipping, invoice, payments } = detail;
  const offPath = OFF_PATH.includes(order.status);
  const expired = order.payment_window_closed;
  const codWaiting = order.payment_method === 'cod' && order.status === 'created' && order.otp_verified;
  const doneDates = {};
  (timeline || []).forEach((t) => { if (t.status && !doneDates[t.status]) doneDates[t.status] = t.date; });

  return (
    <div className="sf-acct">
      <div className="sf-crumbs">
        <Link to="/account">My account</Link><span>›</span><Link to="/orders">My orders</Link><span>›</span><span>{order.order_number}</span>
      </div>

      {/* Header */}
      <div className="sf-panel sf-ohead">
        <div className="sf-ohead__row">
          <h1 className="sf-h1" style={{ marginRight: 'auto' }}>Order {order.order_number}</h1>
          <StatusBadge status={order.status} label={order.status_label} />
          {!(offPath && order.payment_status === 'pending') && (
            <span className={`sf-badge-status sf-badge-status--${PAYMENT_TONE[order.payment_status] || 'info'}`}>{order.payment_status_label}</span>
          )}
        </div>
        <span className="sf-ohead__meta">
          Placed {formatDate(order.placed_date, true)} · {order.unit_count || order.item_count} item(s) · {rupees(order.grand_total)}
          {order.payment_method === 'cod' ? ' · Cash on delivery' : ' · UPI'}
        </span>
        {order.delivered_date ? (
          <span className="sf-ohead__meta sf-good"><b>Delivered on {formatDate(order.delivered_date)}</b></span>
        ) : order.expected_delivery_date && !offPath ? (
          <span className="sf-ohead__meta">Expected delivery by <b>{formatDate(order.expected_delivery_date)}</b></span>
        ) : null}
        {order.cancelled_date && (
          <span className="sf-ohead__meta">Cancelled {formatDate(order.cancelled_date, true)}{order.cancellation_reason ? ` — ${order.cancellation_reason}` : ''}</span>
        )}
      </div>

      {actionError && <div className="sf-error">{actionError}</div>}

      {/* What the customer needs to do next */}
      {payment ? (
        <div className="sf-panel sf-center" style={{ padding: '32px 20px' }}>
          <PaymentPanel order={order} payment={payment} onPaid={() => { setPayment(null); load(true); }} />
          <button type="button" className="sf-btn sf-btn--ghost" onClick={() => setPayment(null)}>Close</button>
        </div>
      ) : order.needs_verification ? (
        <div className="sf-panel sf-action"><div className="sf-section">
          <div className="sf-section__head"><h2>Confirm your order</h2></div>
          <VerifyOrder order={order} onVerified={afterVerified} />
        </div></div>
      ) : order.can_pay ? (
        <div className="sf-panel sf-action"><div className="sf-section">
          <div className="sf-section__head"><h2>Payment pending</h2></div>
          <p className="sf-small" style={{ margin: 0 }}>
            We haven’t received payment for this order yet.
            {order.expires_date && ` Pay by ${formatDate(order.expires_date, true)} or the order is cancelled automatically.`}
            {(payments || []).some((p) => p.gateway === 'manual') && ' Already paid by UPI? We check manual payments and confirm within a few hours — no need to pay again.'}
          </p>
          <button type="button" className="sf-btn sf-btn--red" onClick={startPayment} disabled={starting}>
            {starting ? 'Opening…' : `PAY ${rupees(order.amount_payable)} NOW`}
          </button>
        </div></div>
      ) : codWaiting ? (
        <div className="sf-panel sf-action"><div className="sf-section">
          <div className="sf-section__head"><h2>Waiting for approval</h2></div>
          <p className="sf-small" style={{ margin: 0 }}>Our team reviews cash-on-delivery orders before packing — usually within a few hours. You’ll pay {rupees(order.amount_payable)} in cash on delivery.</p>
        </div></div>
      ) : expired ? (
        <div className="sf-panel sf-action"><div className="sf-section">
          <div className="sf-section__head"><h2>Payment window closed</h2></div>
          <p className="sf-small" style={{ margin: 0 }}>This order wasn’t paid in time and will be cancelled. Add the items to your cart again to reorder.</p>
        </div></div>
      ) : null}

      <div className="sf-acct__grid">
        <div className="sf-acct__col">
          {/* Progress */}
          {!offPath && (
            <div className="sf-panel sf-section">
              <div className="sf-section__head"><h2>Order status</h2></div>
              <ol className="sf-stepper">
                {progress.map((step) => (
                  <li key={step.status} className={step.state === 'complete' ? 'is-done' : step.state === 'current' ? 'is-current' : ''}>
                    <span>
                      {step.label}
                      {doneDates[step.status] && <small>{formatDate(doneDates[step.status], true)}</small>}
                    </span>
                  </li>
                ))}
              </ol>
            </div>
          )}

          {/* Tracking */}
          <div className="sf-panel sf-section" id="tracking">
            <div className="sf-section__head"><h2>Shipment tracking</h2></div>
            {shipments === null ? <p className="sf-small">Loading…</p> : <Tracking order={order} shipments={shipments} shipping={shipping} />}
          </div>

          {/* Items */}
          <div className="sf-panel sf-section">
            <div className="sf-section__head"><h2>Items in this order</h2></div>
            <div>
              {items.map((item) => (
                <div key={item.uuid} className="sf-oline">
                  <ProductMedia image={item.image_url} tint={tintFor(item.product_name)} label={item.product_name.slice(0, 1)} />
                  <div className="sf-oline__body">
                    {item.product_slug ? <Link to={`/product/${item.product_slug}`}>{item.product_name}</Link> : <b>{item.product_name}</b>}
                    <span>{item.variant_name}</span>
                    <span>{item.quantity} × {rupees(item.unit_price)}{item.is_gift ? ' · Gift' : ''}</span>
                    {item.product_slug && (order.status === 'delivered' || offPath) && (
                      <Link className="sf-small" to={`/product/${item.product_slug}`}>Buy again ›</Link>
                    )}
                  </div>
                  <div className="sf-oline__price">
                    <span>{rupees(item.line_payable)}</span>
                    {item.unit_mrp > item.unit_price && <s>{rupees(item.unit_mrp * item.quantity)}</s>}
                  </div>
                </div>
              ))}
            </div>
            {order.is_gift && order.gift_message && (
              <div className="sf-note">Gift message: “{order.gift_message}”</div>
            )}
          </div>

          {/* Timeline */}
          {timeline.length > 0 && (
            <div className="sf-panel sf-section">
              <div className="sf-section__head"><h2>Order history</h2></div>
              <ul className="sf-events">
                {[...timeline].reverse().map((entry, i) => (
                  <li key={i}>
                    <b>{entry.title}</b>
                    {entry.note && <span>{entry.note}</span>}
                    <small>{formatDate(entry.date, true)}</small>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        <div className="sf-acct__col">
          {/* Price details incl. offers */}
          <div className="sf-panel sf-section">
            <div className="sf-section__head"><h2>Price details</h2></div>
            {(pricing.offer_discount > 0 || pricing.coupon_discount > 0) && (
              <div className="sf-offer-applied">
                <span aria-hidden="true">%</span>
                <span>
                  {pricing.offer_discount > 0 && <><b>{pricing.offer_title || pricing.offer_code}</b> applied{pricing.offer_subtitle ? ` — ${pricing.offer_subtitle}` : ''}. </>}
                  {pricing.coupon_discount > 0 && <>Coupon <b>{pricing.coupon_code}</b> applied{pricing.coupon_title ? ` — ${pricing.coupon_title}` : ''}.</>}
                </span>
              </div>
            )}
            <Bill pricing={pricing} order={order} />
            {invoice ? (
              <Link className="sf-btn sf-btn--outline sf-btn--sm" to={`/invoice/${order.uuid}`}>VIEW INVOICE {invoice.number}</Link>
            ) : (
              <span className="sf-small">The tax invoice is available once payment is confirmed.</span>
            )}
          </div>

          {/* Delivery address */}
          <div className="sf-panel sf-section">
            <div className="sf-section__head"><h2>Delivery address</h2></div>
            <dl className="sf-kv">
              <dt>Name</dt><dd>{shipping.name}</dd>
              <dt>Mobile</dt><dd>+91 {shipping.mobile}</dd>
              <dt>Address</dt><dd>{shipping.address}</dd>
              {shipping.slot && (<><dt>Slot</dt><dd>{shipping.slot}</dd></>)}
              {shipping.instructions && (<><dt>Notes</dt><dd>{shipping.instructions}</dd></>)}
            </dl>
          </div>

          {/* Payments */}
          {payments && payments.length > 0 && (
            <div className="sf-panel sf-section">
              <div className="sf-section__head"><h2>Payment</h2></div>
              <div className="sf-rows">
                {payments.map((p) => (
                  <div key={p.uuid} className="sf-row">
                    <div className="sf-row__body">
                      <b>{rupees(p.amount)} · {p.method ? p.method.toUpperCase() : 'UPI'}</b>
                      <span>Attempt {p.attempt} · {p.status.replace(/_/g, ' ')}{p.upi_vpa ? ` · ${p.upi_vpa}` : ''}</span>
                      {p.failure_reason && <span className="sf-small">{p.failure_reason}</span>}
                      <span className="sf-small">{formatDate(p.created_date, true)}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Help + cancel */}
          <div className="sf-panel sf-section">
            <div className="sf-section__head"><h2>Need help?</h2></div>
            <div className="sf-linklist">
              <Link to={`/support?order=${encodeURIComponent(order.uuid)}&number=${encodeURIComponent(order.order_number)}`}><span>Get help with this order</span><span aria-hidden="true">›</span></Link>
              <Link to="/page/returns-and-refunds"><span>Returns &amp; refunds policy</span><span aria-hidden="true">›</span></Link>
            </div>
            {order.can_cancel && (
              !showCancelForm ? (
                <button type="button" className="sf-btn sf-btn--outline-red sf-btn--sm" onClick={() => setShowCancelForm(true)}>CANCEL THIS ORDER</button>
              ) : (
                <form onSubmit={handleCancel} className="sf-form-grid">
                  <label className="sf-field sf-field--full">Why are you cancelling?
                    <textarea rows={3} value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} required />
                  </label>
                  <div className="sf-field--full" style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                    <button type="submit" className="sf-btn sf-btn--red sf-btn--sm" disabled={cancelling}>{cancelling ? 'Cancelling…' : 'CONFIRM CANCELLATION'}</button>
                    <button type="button" className="sf-btn sf-btn--ghost sf-btn--sm" onClick={() => setShowCancelForm(false)}>KEEP ORDER</button>
                  </div>
                  {order.payment_status === 'paid' && <p className="sf-small sf-field--full" style={{ margin: 0 }}>Paid orders are refunded to your original payment method or wallet.</p>}
                </form>
              )
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
