import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import { rupees } from '../../lib/store';
import { BRAND_NAME } from '../../lib/brand';
import { openRazorpay } from '../../lib/razorpay';

/**
 * "Pay ₹X" — the UPI intent link / QR for one payment attempt, and a watcher
 * that reports back once the payment lands. Used at the end of checkout and
 * from an order's page ("Complete payment").
 *
 * Razorpay: opens Razorpay's UPI payment screen straight away, sends the
 * signed result to the server, and still watches the order in case the
 * screen was closed after paying (the webhook confirms it then).
 *
 * `onPaid(order)` runs once the order shows as paid.
 */
export default function PaymentPanel({ order, payment, onPaid, heading = true }) {
  const [pollStatus, setPollStatus] = useState('waiting');
  const onPaidRef = useRef(onPaid);
  onPaidRef.current = onPaid;

  const isManual = payment.gateway === 'manual';
  const isRazorpay = payment.gateway === 'razorpay';
  const isTestKey = isRazorpay && String(payment.public_key || '').startsWith('rzp_test_');
  const [rzpState, setRzpState] = useState(isRazorpay ? 'opening' : null); // opening | open | closed | verifying
  const [rzpError, setRzpError] = useState(null);
  const autoOpened = useRef(null);

  async function payWithRazorpay() {
    setRzpError(null);
    setRzpState('open');
    try {
      const result = await openRazorpay({ payment, order, brand: BRAND_NAME });
      if (!result) {
        setRzpState('closed');
        return;
      }
      setRzpState('verifying');
      await api.post(`/checkout/orders/${order.uuid}/payment/callback`, result);
      const response = await api.get(`/orders/${order.uuid}`);
      if (response.data.order.payment_status === 'paid') {
        onPaidRef.current(response.data.order);
      }
      // Not paid yet (e.g. bank still processing): the watcher below picks it up.
    } catch (err) {
      setRzpError(err.message);
      setRzpState('closed');
    }
  }

  useEffect(() => {
    if (!isRazorpay || autoOpened.current === payment.gateway_order_id) return;
    autoOpened.current = payment.gateway_order_id;
    payWithRazorpay();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isRazorpay, payment.gateway_order_id]);
  const isQrImageUrl = isManual && typeof payment.qr_payload === 'string' && /^https?:\/\//i.test(payment.qr_payload);
  // Manual mode with no QR picture and no UPI ID: there is nothing to pay with.
  const manualNotSetUp = isManual && !isQrImageUrl && !payment.upi_intent_url;

  useEffect(() => {
    let stopped = false;
    let timer = null;
    const maxAttempts = isManual ? 40 : isRazorpay ? 150 : 15;

    function poll(attempt) {
      const delay = attempt === 0 ? 0 : (isManual ? 5000 : isRazorpay ? 4000 : 2000);
      timer = setTimeout(async () => {
        if (stopped) return;
        try {
          const response = await api.get(`/orders/${order.uuid}`);
          const o = response.data.order;
          if (o.payment_status === 'paid') {
            onPaidRef.current(o);
            return;
          }
          if (o.status === 'cancelled') {
            setPollStatus('cancelled');
            return;
          }
        } catch {
          // keep polling
        }
        if (stopped) return;
        if (attempt >= maxAttempts) {
          setPollStatus(isManual ? 'timeout-manual' : 'timeout-auto');
          return;
        }
        poll(attempt + 1);
      }, delay);
    }

    poll(0);
    return () => {
      stopped = true;
      clearTimeout(timer);
    };
  }, [order.uuid, isManual, isRazorpay, payment.uuid]);

  return (
    <div className="sf-pay">
      {heading && (
        <>
          <h1 className="sf-h1">Pay {rupees(payment.amount)}</h1>
          <p>Order <b>{order.order_number}</b></p>
        </>
      )}

      {manualNotSetUp && (
        <div className="sf-error">
          Online payment isn&apos;t set up for this shop yet, so there is no QR code or UPI ID to pay to.
          Please contact the shop to complete this order.
        </div>
      )}

      {isRazorpay && (
        <>
          <p className="sf-small">Pay with GPay, PhonePe, Paytm, BHIM or any UPI app — or another online method.</p>
          {isTestKey && <div className="sf-note">Test mode — no real money is taken.</div>}
          {rzpError && <div className="sf-error">{rzpError}</div>}
          <button
            type="button"
            className="sf-btn sf-btn--red sf-btn--lg sf-btn--block"
            onClick={payWithRazorpay}
            disabled={rzpState === 'opening' || rzpState === 'open' || rzpState === 'verifying'}
          >
            {rzpState === 'verifying' ? 'Confirming your payment…' : rzpState === 'closed' ? `Pay ${rupees(payment.amount)}` : 'Opening payment…'}
          </button>
        </>
      )}

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

      {!manualNotSetUp && <div className="sf-status">
        {pollStatus === 'cancelled'
          ? 'This order was cancelled because payment was not completed in time.'
          : pollStatus === 'timeout-manual'
            ? <>We have not confirmed your payment yet. We review manual payments within a few hours and will message you. <Link to={`/orders/${order.uuid}`}>Check this order</Link></>
            : pollStatus === 'timeout-auto'
              ? <>We have not seen your payment yet. If money has left your account it will be matched within a few minutes. <Link to={`/orders/${order.uuid}`}>Check this order</Link></>
              : 'Waiting for your payment to be confirmed…'}
      </div>}
    </div>
  );
}
