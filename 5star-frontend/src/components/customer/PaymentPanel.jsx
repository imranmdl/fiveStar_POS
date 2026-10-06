import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import { rupees } from '../../lib/store';

/**
 * "Pay ₹X" — the UPI intent link / QR for one payment attempt, and a watcher
 * that reports back once the payment lands. Used at the end of checkout and
 * from an order's page ("Complete payment").
 *
 * `onPaid(order)` runs once the order shows as paid.
 */
export default function PaymentPanel({ order, payment, onPaid, heading = true }) {
  const [pollStatus, setPollStatus] = useState('waiting');
  const onPaidRef = useRef(onPaid);
  onPaidRef.current = onPaid;

  const isManual = payment.gateway === 'manual';
  const isQrImageUrl = isManual && typeof payment.qr_payload === 'string' && /^https?:\/\//i.test(payment.qr_payload);

  useEffect(() => {
    let stopped = false;
    let timer = null;
    const maxAttempts = isManual ? 40 : 15;

    function poll(attempt) {
      const delay = attempt === 0 ? 0 : (isManual ? 5000 : 2000);
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
  }, [order.uuid, isManual, payment.uuid]);

  return (
    <div className="sf-pay">
      {heading && (
        <>
          <h1 className="sf-h1">Pay {rupees(payment.amount)}</h1>
          <p>Order <b>{order.order_number}</b></p>
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

      <div className="sf-status">
        {pollStatus === 'cancelled'
          ? 'This order was cancelled because payment was not completed in time.'
          : pollStatus === 'timeout-manual'
            ? <>We have not confirmed your payment yet. We review manual payments within a few hours and will message you. <Link to={`/orders/${order.uuid}`}>Check this order</Link></>
            : pollStatus === 'timeout-auto'
              ? <>We have not seen your payment yet. If money has left your account it will be matched within a few minutes. <Link to={`/orders/${order.uuid}`}>Check this order</Link></>
              : 'Waiting for your payment to be confirmed…'}
      </div>
    </div>
  );
}
