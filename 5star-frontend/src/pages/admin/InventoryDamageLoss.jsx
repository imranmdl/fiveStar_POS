import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatCard } from '../../components/admin/shared';
import { WarehouseSelect, Tag, MOVEMENT_TONE, fmtDateTime } from './InventoryShared';

const BLANK = { warehouse_uuid: '', sku: '', from: '', to: '' };

/** Damage & loss report — ported from renderDamageLossTab(). */
export default function InventoryDamageLoss({ warehouses }) {
  const [filters, setFilters] = useState(BLANK);
  const [draft, setDraft] = useState(BLANK);
  const [state, setState] = useState({ loading: true, error: null, rows: [], summary: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/reports/damage-loss', filters);
      setState({ loading: false, error: null, rows: response.data.rows || [], summary: response.data.summary });
    } catch (error) {
      setState({ loading: false, error, rows: [], summary: null });
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
        <WarehouseSelect warehouses={warehouses} value={draft.warehouse_uuid} onChange={(e) => setDraft({ ...draft, warehouse_uuid: e.target.value })} />
        <input className="inv-input" placeholder="SKU" value={draft.sku} onChange={(e) => setDraft({ ...draft, sku: e.target.value })} />
        <input className="inv-input" type="date" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} />
        <input className="inv-input" type="date" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} />
        <button type="submit" className="admin-btn">Filter</button>
      </form>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : (
        <>
          {state.summary && (
            <div className="stat-grid">
              <StatCard label="Incidents" value={state.summary.incident_count} />
              <StatCard label="Damaged" value={state.summary.damage_count} tone="warning" />
              <StatCard label="Lost" value={state.summary.lost_count} tone="danger" />
              <StatCard label="Estimated value lost" value={formatMoney(state.summary.total_value)} hint={`${state.summary.total_quantity} unit(s) total`} tone="danger" />
            </div>
          )}

          {state.rows.length === 0 ? (
            <EmptyState title="No damage or loss recorded" hint='Nothing was written off in this range — use "Adjust" on the Stock tab to record one.' />
          ) : (
            <>
              <div style={{ overflowX: 'auto' }}>
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>When</th><th>Item</th><th>Type</th><th style={{ textAlign: 'right' }}>Qty</th>
                      <th style={{ textAlign: 'right' }}>Value</th><th>Reason</th><th>Batch</th><th>Source</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.rows.map((row, i) => (
                      <tr key={i}>
                        <td className="small-muted">{fmtDateTime(row.created_date)}</td>
                        <td>
                          <div style={{ fontWeight: 600 }}>{row.sku}</div>
                          <div className="small-muted">{row.variant_name} · {row.warehouse_name}</div>
                        </td>
                        <td><Tag tone={MOVEMENT_TONE[row.movement_type]}>{row.movement_type}</Tag></td>
                        <td style={{ textAlign: 'right' }}>{row.quantity}</td>
                        <td style={{ textAlign: 'right' }} className="small-muted">
                          {row.line_value !== null ? `${formatMoney(row.line_value)}${row.value_source === 'current_average_cost' ? ' *' : ''}` : '—'}
                        </td>
                        <td className="small-muted">{row.reason || '—'}</td>
                        <td className="small-muted">{row.batch_no || '—'}</td>
                        <td className="small-muted">{row.vendor_name ? <>{row.vendor_name} <span>({row.po_number})</span></> : 'Not traced'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="small-muted" style={{ padding: '10px 0' }}>
                * Value estimated from the item's current average cost — this system does not snapshot cost per movement, only per inward.
              </p>
            </>
          )}
        </>
      )}
    </div>
  );
}
