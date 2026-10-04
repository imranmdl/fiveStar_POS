import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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

function OrderRow({ order }) {
  return (
    <div className="order-row">
      <div className="order-row__info">
        <div className="order-row__number">{order.order_number}</div>
        <div className="order-row__meta">
          {(order.placed_date || '').slice(0, 10)} · {order.item_count} item(s)
        </div>
      </div>
      <span className={`status ${STATUS_LABEL_CLASS[order.status] || 'status--primary'}`}>{order.status_label}</span>
      <div className="order-row__total">{formatMoney(order.grand_total)}</div>
      <Link className="btn-outline" to={`/orders/${order.uuid}`}>Details</Link>
    </div>
  );
}

export default function Orders() {
  const { signedIn, ready } = useAuth();
  const [status, setStatus] = useState('loading');
  const [orders, setOrders] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setStatus('signed-out');
      return;
    }

    setStatus('loading');
    api
      .get('/orders', { per_page: 20 })
      .then((response) => {
        setOrders(response.data || []);
        setStatus('ready');
      })
      .catch((err) => {
        setError(err.message);
        setStatus('error');
      });
  }, [ready, signedIn]);

  if (!ready || status === 'loading') {
    return <div className="page"><p className="state-message">Loading your orders…</p></div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="page orders-empty">
        <h1 className="page-title">Sign in to see your orders</h1>
        <Link className="btn-marigold" to="/account?next=/orders">Sign in</Link>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load your orders: {error}</p></div>;
  }

  return (
    <div className="page">
      <h1 className="page-title">Your orders</h1>

      {orders.length === 0 ? (
        <div className="orders-empty">
          <p className="text-muted">No orders yet.</p>
          <Link className="btn-marigold" to="/">Start shopping</Link>
        </div>
      ) : (
        orders.map((order) => <OrderRow key={order.uuid} order={order} />)
      )}
    </div>
  );
}
