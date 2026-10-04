/**
 * Reports: what came in, what it cost, and where it went.
 *
 * The question a shop owner asks at the end of a day is not "what is my
 * revenue" — it is "how much money actually reached me, and how much of what
 * I am holding is not mine". Tax collected and refunds owed are both in that
 * second category, so they are shown next to the takings rather than buried.
 *
 * Ported from admin/assets/page-reports.js. Field names come straight from
 * the API's own sales series — not guessed.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, LoadingState, ErrorState, StatCard, StatusBadge } from '../../components/admin/shared';
import './Reports.css';

const RANGE_OPTIONS = [[1, 'Today'], [7, 'Last 7 days'], [30, 'Last 30 days'], [90, 'Last 90 days']];

const TODAY_DRILLDOWN_TITLE = {
  sales: "Today's orders & sales",
  delivered: 'Delivered today',
  cancelled: 'Cancelled today',
  collection: 'Taken today — every transaction',
};

function money(value) {
  return formatMoney(Number(value) || 0);
}

function total(series, key) {
  return series.reduce((sum, row) => sum + (Number(row[key]) || 0), 0);
}

function isoDate(date) {
  return date.toISOString().slice(0, 10);
}

/* ---------------------------------------------------------------------- */
/* Sub-tables                                                              */
/* ---------------------------------------------------------------------- */

function DailyTable({ series }) {
  if (series.length === 0) {
    return <EmptyState title="No sales in this period" hint="Try a longer range." />;
  }

  // Newest first: the day someone wants is almost always today or yesterday.
  const rows = [...series].reverse();

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead>
          <tr>
            <th>Date</th><th>Orders</th><th>Taken</th><th>Of which GST</th>
            <th>Discounts</th><th>Delivery</th><th>Refunded</th><th>Yours</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            // What the shop actually keeps: money in, less the tax it is
            // holding for the government, less anything refunded.
            const kept = (Number(row.gross_sales) || 0) - (Number(row.tax_collected) || 0) - (Number(row.refunded) || 0);
            return (
              <tr key={row.date}>
                <td style={{ fontWeight: 600 }}>{row.date}</td>
                <td>{row.orders}</td>
                <td>{money(row.gross_sales)}</td>
                <td className="reports-muted">{money(row.tax_collected)}</td>
                <td className="reports-muted">{money(row.discount_given)}</td>
                <td className="reports-muted">{money(row.delivery_collected)}</td>
                <td className={Number(row.refunded) > 0 ? 'reports-danger' : 'reports-muted'}>{money(row.refunded)}</td>
                <td style={{ fontWeight: 600 }}>{money(kept)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function PipelineTable({ pipeline }) {
  if (!pipeline || pipeline.length === 0) {
    return <p className="reports-muted" style={{ padding: 16 }}>No paid orders in progress.</p>;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead><tr><th>Status</th><th>Orders</th><th>Value</th></tr></thead>
        <tbody>
          {pipeline.map((row) => (
            <tr key={row.status}>
              <td><StatusBadge status={row.status} label={String(row.status).replace(/_/g, ' ')} /></td>
              <td>{row.count}</td>
              <td>{money(row.value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CashierTable({ rows }) {
  if (!rows || rows.length === 0) {
    return <p className="reports-muted" style={{ padding: 16 }}>No counter sales in this period.</p>;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead><tr><th>Cashier</th><th>Sales</th><th>Gross</th><th>Discounts</th><th>Tax</th></tr></thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.cashier_name}>
              <td>{row.cashier_name}</td>
              <td>{row.sale_count}</td>
              <td>{money(row.gross_sales)}</td>
              <td className="reports-muted">{money(row.discount_given)}</td>
              <td className="reports-muted">{money(row.tax_collected)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PaymentMethodTable({ rows }) {
  if (!rows || rows.length === 0) {
    return <p className="reports-muted" style={{ padding: 16 }}>No counter sales in this period.</p>;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead><tr><th>Method</th><th>Sales</th><th>Gross</th></tr></thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.payment_method}>
              <td style={{ textTransform: 'uppercase' }}>{row.payment_method}</td>
              <td>{row.sale_count}</td>
              <td>{money(row.gross_sales)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Today drill-down (reuses GET /admin/dashboard/today/{kind}, same as the  */
/* Dashboard page's own stat-tile drill-down)                              */
/* ---------------------------------------------------------------------- */

function saleRow(row) {
  return (
    <tr key={row.reference}>
      <td style={{ fontWeight: 600 }}>{row.reference}</td>
      <td>{row.channel === 'pos' ? 'POS' : 'Online order'}</td>
      <td>
        {row.customer_name || 'Walk-in customer'}
        <div className="reports-muted">{row.customer_mobile || ''}</div>
      </td>
      <td><StatusBadge status={row.status} label={row.status} /></td>
      <td>{String(row.date || '').slice(0, 16).replace('T', ' ')}</td>
      <td>{formatMoney(row.amount)}</td>
    </tr>
  );
}

function TodayDrilldownModal({ kind, onClose }) {
  const [items, setItems] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setItems(null);
    setError(null);

    api.get(`/admin/dashboard/today/${encodeURIComponent(kind)}`)
      .then((response) => { if (!cancelled) setItems((response.data && response.data.items) || []); })
      .catch((err) => { if (!cancelled) setError(err); });

    return () => { cancelled = true; };
  }, [kind]);

  return (
    <div className="reports-modal-backdrop" onClick={onClose}>
      <div className="reports-modal" onClick={(event) => event.stopPropagation()}>
        <div className="reports-modal__header">
          <h2>{TODAY_DRILLDOWN_TITLE[kind] || kind}</h2>
          <button className="admin-btn" type="button" onClick={onClose}>Close</button>
        </div>
        <div className="reports-modal__body">
          {error ? <ErrorState error={error} /> : items === null ? <LoadingState /> : items.length === 0 ? (
            <p className="reports-muted">Nothing here yet today.</p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="admin-table">
                <thead>
                  <tr><th>Reference</th><th>Channel</th><th>Customer</th><th>Status</th><th>Time</th><th>Amount</th></tr>
                </thead>
                <tbody>{items.map(saleRow)}</tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Page                                                                     */
/* ---------------------------------------------------------------------- */

export default function Reports() {
  const [days, setDays] = useState(30);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [data, setData] = useState(null);
  const [drilldownKind, setDrilldownKind] = useState(null);
  const dailyCardRef = useRef(null);
  const [flash, setFlash] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    const to = new Date();
    const from = new Date(to.getTime() - (days - 1) * 86400000);

    try {
      const [sales, dashboard, cancellations, pos] = await Promise.all([
        api.get('/admin/reports/sales', { from: isoDate(from), to: isoDate(to) }),
        api.get('/admin/dashboard'),
        api.get('/admin/reports/cancellations', { from: isoDate(from), to: isoDate(to) }),
        api.get('/admin/reports/pos', { from: isoDate(from), to: isoDate(to) }),
      ]);

      setData({
        series: (sales.data && sales.data.series) || [],
        today: (dashboard.data && dashboard.data.today) || {},
        pipeline: (dashboard.data && dashboard.data.pipeline) || [],
        refunds: (cancellations.data && cancellations.data.refunds) || {},
        byCashier: (pos.data && pos.data.by_cashier) || [],
        byPaymentMethod: (pos.data && pos.data.by_payment_method) || [],
      });
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [days]);

  useEffect(() => { load(); }, [load]);

  function openDrilldown(kind) {
    if (kind.startsWith('cash-')) {
      if (dailyCardRef.current) {
        dailyCardRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setFlash(false);
        // Force reflow so the animation restarts if clicked twice in a row.
        void dailyCardRef.current.offsetWidth;
        setFlash(true);
        setTimeout(() => setFlash(false), 1200);
      }
      return;
    }
    setDrilldownKind(kind);
  }

  if (loading) return <div><h1 className="admin-page-title">Reports</h1><LoadingState /></div>;
  if (error) return <div><h1 className="admin-page-title">Reports</h1><ErrorState error={error} /></div>;

  const { series, today, pipeline, refunds, byCashier, byPaymentMethod } = data;
  const taken = total(series, 'gross_sales');
  const tax = total(series, 'tax_collected');
  const refunded = total(series, 'refunded');
  const orders = total(series, 'orders');
  const kept = taken - tax - refunded;

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Reports</h1>
        <div className="reports-range-group">
          {RANGE_OPTIONS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              className={`admin-btn ${days === value ? 'admin-btn--primary' : ''}`}
              onClick={() => setDays(value)}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      <div className="stat-grid">
        <div className="reports-clickable" onClick={() => openDrilldown('sales')}>
          <StatCard label="Orders today" value={today.orders ?? 0} />
        </div>
        <div className="reports-clickable" onClick={() => openDrilldown('collection')}>
          <StatCard label="Taken today" value={money(today.revenue)} />
        </div>
        <div className="reports-clickable" onClick={() => openDrilldown('delivered')}>
          <StatCard label="Delivered today" value={today.delivered ?? 0} />
        </div>
        <div className="reports-clickable" onClick={() => openDrilldown('cancelled')}>
          <StatCard label="Cancelled today" value={today.cancelled ?? 0} tone={Number(today.cancelled) > 0 ? 'danger' : undefined} />
        </div>
      </div>

      <div className="pricing-card">
        <div className="pricing-card__header">Cash flow — last {days} day(s)</div>
        <div className="pricing-card__body">
          <div className="stat-grid">
            <div className="reports-clickable" onClick={() => openDrilldown('cash-money-in')}>
              <StatCard label="Money in" value={money(taken)} hint={`${orders} order(s)`} />
            </div>
            <div className="reports-clickable" onClick={() => openDrilldown('cash-gst')}>
              <StatCard label="GST collected" value={money(tax)} hint="held for the government" />
            </div>
            <div className="reports-clickable" onClick={() => openDrilldown('cash-refunded')}>
              <StatCard label="Refunded" value={money(refunded)} tone={refunded > 0 ? 'danger' : undefined} />
            </div>
            <div className="reports-clickable" onClick={() => openDrilldown('cash-kept')}>
              <StatCard label="Yours to keep" value={money(kept)} hint="after tax and refunds" tone="success" />
            </div>
          </div>

          <p className="reports-muted" style={{ marginTop: 12, marginBottom: 0 }}>
            <b>GST is not income.</b> Indian prices include tax, so a share of every rupee taken is
            money you are holding on behalf of the government until you file. &quot;Yours to keep&quot;
            is takings less that tax and less anything refunded — the figure to plan against.
          </p>
        </div>
      </div>

      <div className="reports-grid">
        <div className={`pricing-card reports-daily-card ${flash ? 'reports-flash' : ''}`} ref={dailyCardRef}>
          <div className="pricing-card__header">Day by day</div>
          <div className="pricing-card__body" style={{ padding: 0 }}>
            <DailyTable series={series} />
          </div>
        </div>

        <div className="reports-side">
          <div className="pricing-card">
            <div className="pricing-card__header">Orders in progress</div>
            <PipelineTable pipeline={pipeline} />
          </div>

          <div className="pricing-card">
            <div className="pricing-card__header">Refunds</div>
            <div className="pricing-card__body">
              <dl className="reports-dl">
                <dt>Refunds issued</dt><dd>{refunds.count ?? 0}</dd>
                <dt>Back to the payer</dt><dd>{money(refunds.to_gateway)}</dd>
                <dt>Back to wallet</dt><dd>{money(refunds.to_wallet)}</dd>
                {Number(refunds.failed) > 0 && (
                  <>
                    <dt className="reports-danger">Failed</dt>
                    <dd className="reports-danger">{refunds.failed}</dd>
                  </>
                )}
              </dl>
              {Number(refunds.failed) > 0 && (
                <div className="admin-alert admin-alert--danger" style={{ marginTop: 8 }}>
                  A failed refund is a customer waiting for money. Chase these first.
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      <div className="reports-grid">
        <div className="pricing-card">
          <div className="pricing-card__header">Counter sales by cashier — last {days} day(s)</div>
          <CashierTable rows={byCashier} />
        </div>
        <div className="pricing-card">
          <div className="pricing-card__header">Counter sales by payment method — last {days} day(s)</div>
          <PaymentMethodTable rows={byPaymentMethod} />
        </div>
      </div>

      <p className="reports-muted">
        Only confirmed, non-cancelled orders count. An order placed but never paid for is not revenue
        and never appears here. Counter-sale figures above exclude voided sales.
      </p>

      {drilldownKind && <TodayDrilldownModal kind={drilldownKind} onClose={() => setDrilldownKind(null)} />}
    </div>
  );
}
