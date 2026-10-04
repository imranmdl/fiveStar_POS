import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatCard } from '../../components/admin/shared';
import { WarehouseSelect, Tag, qty, fmtDate } from './InventoryShared';

const BLANK = { status: 'all', warehouse_uuid: '', sku: '' };

const EXPIRY_TONE = { expiring_soon: 'warning', expired: 'danger' };

/** Expiring/expired batches — ported from renderExpiryTab(). */
export default function InventoryExpiry({ warehouses }) {
  const [filters, setFilters] = useState(BLANK);
  const [draft, setDraft] = useState(BLANK);
  const [state, setState] = useState({ loading: true, error: null, items: [], summary: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/reports/expiry', filters);
      setState({ loading: false, error: null, items: response.data.items || [], summary: response.data.summary });
    } catch (error) {
      setState({ loading: false, error, items: [], summary: null });
    }
  }, [filters]);

  useEffect(() => { load(); }, [load]);

  function handleFilterSubmit(event) {
    event.preventDefault();
    setFilters(draft);
  }

  return (
    <div>
      <form className="inv-filter-row" onSubmit={handleFilterSubmit}>
        <select className="inv-select" value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
          <option value="all">All</option>
          <option value="expiring_soon">Expiring soon</option>
          <option value="expired">Expired</option>
        </select>
        <WarehouseSelect warehouses={warehouses} value={draft.warehouse_uuid} onChange={(e) => setDraft({ ...draft, warehouse_uuid: e.target.value })} />
        <input className="inv-input" placeholder="SKU" value={draft.sku} onChange={(e) => setDraft({ ...draft, sku: e.target.value })} />
        <button type="submit" className="admin-btn">Filter</button>
      </form>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : (
        <>
          {state.summary && (
            <div className="stat-grid">
              <StatCard label="Expiring soon (≤15 days)" value={state.summary.expiring_soon_count} tone="warning" />
              <StatCard label="Expired" value={state.summary.expired_count} tone="danger" />
              <StatCard label="Value at risk" value={formatMoney(state.summary.total_value)} tone="danger" />
            </div>
          )}

          {state.items.length === 0 ? (
            <EmptyState title="Nothing to show" hint="No batches match this filter — declare an expiry date on the Purchase Inward or Adjust screen to track one." />
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Item</th><th>Batch</th><th style={{ textAlign: 'right' }}>Qty</th><th>Expiry date</th>
                    <th>Time left</th><th style={{ textAlign: 'right' }}>Value</th><th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {state.items.map((row, i) => {
                    const daysLabel = row.status === 'expired'
                      ? `Expired ${Math.abs(row.days_remaining)} day(s) ago`
                      : `${row.days_remaining} day(s) left`;
                    return (
                      <tr key={i}>
                        <td>
                          <div style={{ fontWeight: 600 }}>{row.sku}</div>
                          <div className="small-muted">{row.product_name} · {row.variant_name} · {row.warehouse_name}</div>
                        </td>
                        <td className="small-muted">{row.batch_no}</td>
                        <td style={{ textAlign: 'right' }}>{qty(row.quantity)}</td>
                        <td className="small-muted">{fmtDate(row.expiry_date)}</td>
                        <td className="small-muted">{daysLabel}</td>
                        <td style={{ textAlign: 'right' }} className="small-muted">{row.line_value !== null ? formatMoney(row.line_value) : '—'}</td>
                        <td><Tag tone={EXPIRY_TONE[row.status] || 'success'}>{row.status.replace(/_/g, ' ')}</Tag></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
