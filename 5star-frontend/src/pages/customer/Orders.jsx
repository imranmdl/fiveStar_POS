import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import OrderCard from '../../components/customer/OrderCard';

const TABS = [
  ['all', 'All orders'],
  ['active', 'On the way'],
  ['delivered', 'Delivered'],
  ['cancelled', 'Cancelled'],
];

const PER_PAGE = 10;

/** Order history: every order, newest first, with tabs and paging. */
export default function Orders() {
  const { signedIn, ready } = useAuth();
  const [params, setParams] = useSearchParams();
  const group = TABS.some(([key]) => key === params.get('tab')) ? params.get('tab') : 'all';
  const [status, setStatus] = useState('loading');
  const [orders, setOrders] = useState([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [error, setError] = useState(null);

  useEffect(() => {
    document.title = 'My orders · 5 Star';
  }, []);

  useEffect(() => {
    setPage(1);
  }, [group]);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setStatus('signed-out');
      return;
    }

    let live = true;
    if (page === 1) setStatus('loading');
    api
      .get('/orders', { per_page: PER_PAGE, page, group: group === 'all' ? undefined : group })
      .then((response) => {
        if (!live) return;
        setOrders((current) => (page === 1 ? response.data || [] : [...current, ...(response.data || [])]));
        setTotalPages(response.meta?.total_pages || 1);
        setStatus('ready');
      })
      .catch((err) => {
        if (!live) return;
        setError(err.message);
        setStatus('error');
      });
    return () => {
      live = false;
    };
  }, [ready, signedIn, group, page]);

  if (!ready || (status === 'loading' && page === 1 && orders.length === 0)) {
    return <div className="sf-panel sf-panel--pad sf-muted">Loading your orders…</div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">Sign in to see your orders</h1>
        <p>Track deliveries, download invoices and reorder your favourites.</p>
        <Link className="sf-btn sf-btn--red sf-btn--lg" to="/account?next=/orders">SIGN IN</Link>
      </div>
    );
  }

  return (
    <div className="sf-acct">
      <div className="sf-crumbs"><Link to="/account">My account</Link><span>›</span><span>My orders</span></div>
      <div className="sf-section__head">
        <h1 className="sf-h1">My orders</h1>
      </div>

      <div className="sf-tabs" role="tablist">
        {TABS.map(([key, label]) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={group === key}
            className={`sf-chip${group === key ? ' is-on' : ''}`}
            onClick={() => setParams(key === 'all' ? {} : { tab: key }, { replace: true })}
          >
            {label}
          </button>
        ))}
      </div>

      {status === 'error' && <div className="sf-error">Couldn’t load your orders: {error}</div>}

      {status !== 'error' && (status === 'loading' && page === 1 ? (
        <div className="sf-panel sf-panel--pad sf-muted">Loading…</div>
      ) : orders.length === 0 ? (
        <div className="sf-panel sf-empty">
          <b>{group === 'all' ? 'No orders yet' : 'Nothing here'}</b>
          <span className="sf-small">{group === 'all' ? 'Your orders will appear here once you place one.' : 'No orders in this list.'}</span>
          <Link className="sf-btn sf-btn--red" to="/shop">START SHOPPING</Link>
        </div>
      ) : (
        <div className="sf-olist">
          {orders.map((order) => <OrderCard key={order.uuid} order={order} />)}
          {page < totalPages && (
            <button type="button" className="sf-btn sf-btn--outline" onClick={() => setPage((p) => p + 1)} disabled={status === 'loading'}>
              {status === 'loading' ? 'Loading…' : 'SHOW MORE ORDERS'}
            </button>
          )}
        </div>
      ))}
    </div>
  );
}
