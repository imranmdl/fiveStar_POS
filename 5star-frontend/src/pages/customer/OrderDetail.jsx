import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import './Orders.css';

const STATUS_LABEL_CLASS = {
  delivered: 'status--success',
  cancelled: 'status--muted',
  returned: 'status--warning',
  refunded: 'status--muted',
  shipped: 'status--info',
  out_for_delivery: 'status--info',
};

function TimelineItem({ entry }) {
  return (
    <li className="timeline-item">
      <div className="timeline-item__title">{entry.title}</div>
      {entry.note && <div className="timeline-item__note">{entry.note}</div>}
      <div className="timeline-item__date">{(entry.date || '').replace('T', ' ').slice(0, 16)}</div>
    </li>
  );
}

export default function OrderDetail() {
  const { uuid } = useParams();
  const { signedIn, ready } = useAuth();
  const [status, setStatus] = useState('loading');
  const [detail, setDetail] = useState(null);
  const [error, setError] = useState(null);
  const [cancelling, setCancelling] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [showCancelForm, setShowCancelForm] = useState(false);

  const load = useCallback(() => {
    setStatus('loading');
    api
      .get(`/orders/${encodeURIComponent(uuid)}`)
      .then((response) => {
        setDetail(response.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(err.message);
        setStatus('error');
      });
  }, [uuid]);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setStatus('signed-out');
      return;
    }
    load();
  }, [ready, signedIn, load]);

  async function handleCancel(event) {
    event.preventDefault();
    if (!cancelReason) return;

    setCancelling(true);
    try {
      await api.post(`/orders/${encodeURIComponent(uuid)}/cancel`, { reason: cancelReason });
      setShowCancelForm(false);
      setCancelReason('');
      load();
    } catch (err) {
      setError(err.message);
    } finally {
      setCancelling(false);
    }
  }

  if (!ready || status === 'loading') {
    return <div className="page"><p className="state-message">Loading order…</p></div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="page orders-empty">
        <h1 className="page-title">Sign in to see your orders</h1>
        <Link className="btn-marigold" to={`/account?next=/orders/${uuid}`}>Sign in</Link>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load this order: {error}</p></div>;
  }

  const { order, items, timeline, pricing, shipping, invoice } = detail;

  return (
    <div className="page order-detail">
      <Link className="small" to="/orders">← All orders</Link>
      <h1 className="page-title">{order.order_number}</h1>
      <p className="order-detail__status-row">
        <span className={`status ${STATUS_LABEL_CLASS[order.status] || 'status--primary'}`}>{order.status_label}</span>
        <span>{order.payment_status_label}</span>
      </p>

      <div className="order-detail-layout">
        <div className="order-detail-main">
          <div className="checkout-panel">
            <h2>Items</h2>
            <ul className="item-list">
              {items.map((item, index) => (
                <li key={index} className="item-list__row">
                  <span>
                    {item.product_name}
                    <span className="item-list__variant">{item.variant_name} × {item.quantity}</span>
                  </span>
                  <span>{formatMoney(item.line_payable)}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="checkout-panel">
            <h2>Progress</h2>
            <ul className="timeline">
              {timeline.map((entry, index) => <TimelineItem key={index} entry={entry} />)}
            </ul>
          </div>
        </div>

        <div className="order-detail-side">
          <div className="checkout-panel">
            <h2>Payment</h2>
            <dl className="order-summary__rows">
              <dt>Total</dt><dd>{formatMoney(pricing.grand_total)}</dd>
              {Number(pricing.wallet_applied) > 0 && (
                <>
                  <dt>Wallet credit</dt><dd>−{formatMoney(pricing.wallet_applied)}</dd>
                </>
              )}
              <dt>Includes GST</dt><dd>{formatMoney(pricing.tax_total)}</dd>
            </dl>
            {invoice && (
              <Link className="btn-outline" to={`/invoice/${order.uuid}`}>View invoice</Link>
            )}
          </div>

          <div className="checkout-panel">
            <h2>Delivery</h2>
            <p className="small">{shipping.address}</p>
            {shipping.tracking_number ? (
              <p className="small">
                {shipping.courier_name || 'Courier'} ·{' '}
                {shipping.tracking_url ? (
                  <a href={shipping.tracking_url} rel="noopener noreferrer" target="_blank">{shipping.tracking_number}</a>
                ) : shipping.tracking_number}
              </p>
            ) : (
              <p className="small text-muted">Not dispatched yet.</p>
            )}
          </div>

          {order.can_cancel && (
            <div className="checkout-panel">
              {!showCancelForm ? (
                <button type="button" className="btn-outline btn-block" onClick={() => setShowCancelForm(true)}>
                  Cancel this order
                </button>
              ) : (
                <form onSubmit={handleCancel}>
                  <label htmlFor="cancel-reason" className="small">Why are you cancelling? This helps us improve.</label>
                  <textarea
                    id="cancel-reason"
                    rows={3}
                    value={cancelReason}
                    onChange={(e) => setCancelReason(e.target.value)}
                    required
                  />
                  <button type="submit" className="btn-outline btn-block" disabled={cancelling}>
                    {cancelling ? 'Cancelling…' : 'Confirm cancellation'}
                  </button>
                </form>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
