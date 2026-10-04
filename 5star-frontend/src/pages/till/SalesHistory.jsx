import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import SaleDetail from './SaleDetail';
import { ErrorBanner, LoadingState } from './TillShared';

const TODAY = () => new Date().toISOString().slice(0, 10);

/**
 * Best-effort only — ported from renderTodayStats() in
 * admin/assets/page-till.js. A cashier-only account can't read
 * /admin/reports/pos (supervisory-only), so a 403 here just means no stat
 * row renders, not a broken page.
 */
function TodayStats({ user }) {
  const [stats, setStats] = useState(null);

  useEffect(() => {
    let active = true;

    async function load() {
      try {
        const today = TODAY();
        const isAdmin = String(user.role) === 'administrator';
        let saleCount = 0;
        let revenue = 0;

        const voidedResponse = await api.get('/admin/pos/sales', { status: 'voided', from: today, to: today, per_page: 1 });

        if (isAdmin) {
          const byCashier = (await api.get('/admin/reports/pos', { from: today, to: today })).data.by_cashier || [];
          saleCount = byCashier.reduce((sum, row) => sum + Number(row.sale_count || 0), 0);
          revenue = byCashier.reduce((sum, row) => sum + Number(row.gross_sales || 0), 0);
        } else {
          for (let page = 1; page <= 20; page += 1) {
            const response = await api.get('/admin/pos/sales', { status: 'completed', from: today, to: today, per_page: 50, page });
            (response.data || []).forEach((sale) => { saleCount += 1; revenue += Number(sale.grand_total || 0); });
            if (page >= (response.meta?.total_pages ?? 1)) break;
          }
        }

        if (active) {
          setStats({ isAdmin, saleCount, revenue, voidedCount: voidedResponse.meta?.total ?? 0 });
        }
      } catch {
        if (active) setStats(null);
      }
    }

    load();
    return () => { active = false; };
  }, [user.role]);

  if (!stats) return null;

  return (
    <div className="till-stats-row">
      <div className="till-stat-card">
        <div className="till-stat-card__label">{stats.isAdmin ? "Today's sales" : 'Your sales today'}</div>
        <div className="till-stat-card__value">{stats.saleCount}</div>
      </div>
      <div className="till-stat-card till-stat-card--gold">
        <div className="till-stat-card__label">{stats.isAdmin ? "Today's takings" : 'Your takings today'}</div>
        <div className="till-stat-card__value">{formatMoney(stats.revenue)}</div>
      </div>
      <div className={`till-stat-card ${stats.voidedCount > 0 ? 'till-stat-card--danger' : ''}`}>
        <div className="till-stat-card__label">Voided today</div>
        <div className="till-stat-card__value">{stats.voidedCount}</div>
        <div className="till-stat-card__hint">Excluded from takings above</div>
      </div>
    </div>
  );
}

function HistoryRow({ sale, onOpen }) {
  return (
    <tr onClick={() => onOpen(sale.uuid)} className="till-history-row">
      <td>
        <div className="till-history-row__number">{sale.sale_number}</div>
        <div className="till-cart__meta">
          {String(sale.created_date || '').slice(0, 16).replace('T', ' ')} · {sale.cashier_name}
          {sale.shop_label ? ` · ${sale.shop_label}` : ''}
        </div>
      </td>
      <td className="till-history-row__method">{sale.payment_method}</td>
      <td>
        {sale.status === 'completed'
          ? <span className="till-badge till-badge--success">Completed</span>
          : <span className="till-badge till-badge--muted">Voided</span>}
        {sale.status === 'completed' && sale.delivery_status === 'pending' && (
          <span className="till-badge till-badge--warning">Not delivered</span>
        )}
      </td>
      <td className="till-cart__num">{formatMoney(sale.grand_total)}</td>
    </tr>
  );
}

/** Ported from renderHistoryTab() in admin/assets/page-till.js. */
export default function SalesHistory({ user, notify }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const openUuid = searchParams.get('uuid');

  const [rows, setRows] = useState([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (openUuid) return;
    let active = true;

    async function load() {
      setLoading(true);
      setError(null);

      try {
        const response = await api.get('/admin/pos/sales', { page, per_page: 30, direction: 'DESC' });
        if (!active) return;
        setRows(response.data || []);
        setTotalPages(response.meta?.total_pages ?? 1);
      } catch (err) {
        if (active) setError(err);
      } finally {
        if (active) setLoading(false);
      }
    }

    load();
    return () => { active = false; };
  }, [page, openUuid]);

  function openSale(uuid) {
    setSearchParams((params) => {
      const next = new URLSearchParams(params);
      next.set('uuid', uuid);
      return next;
    });
  }

  function closeSale() {
    setSearchParams((params) => {
      const next = new URLSearchParams(params);
      next.delete('uuid');
      return next;
    });
  }

  if (openUuid) {
    return <SaleDetail uuid={openUuid} onBack={closeSale} notify={notify} />;
  }

  return (
    <div className="till-history">
      <TodayStats user={user} />

      {loading && <LoadingState />}
      {error && <ErrorBanner error={error} />}

      {!loading && !error && rows.length === 0 && (
        <div className="till-empty-state">
          <p>No sales yet</p>
          <p className="till-cart__meta">Use the Sell tab to ring up your first sale.</p>
        </div>
      )}

      {!loading && !error && rows.length > 0 && (
        <>
          <table className="till-cart__table till-history__table">
            <thead><tr><th>Sale</th><th>Payment</th><th>Status</th><th className="till-cart__num">Total</th></tr></thead>
            <tbody>{rows.map((sale) => <HistoryRow key={sale.uuid} sale={sale} onOpen={openSale} />)}</tbody>
          </table>

          {totalPages > 1 && (
            <div className="till-pagination">
              <button type="button" className="till-btn till-btn--sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
              <span>Page {page} of {totalPages}</span>
              <button type="button" className="till-btn till-btn--sm" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>Next</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
