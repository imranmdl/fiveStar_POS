import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { WarehouseSelect, Pagination, Tag, fmtDateTime, MOVEMENT_TYPES, MOVEMENT_TONE } from './InventoryShared';

const BLANK = { warehouse_uuid: '', sku: '', movement_type: '' };

/** Every stock change, audit-trail style — ported from renderMovementsTab(). */
export default function InventoryMovements({ warehouses }) {
  const [filters, setFilters] = useState(BLANK);
  const [draft, setDraft] = useState(BLANK);
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, rows: [], meta: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/movements', {
        ...filters, page, per_page: 30, sort: 'created_date', direction: 'DESC',
      });
      setState({ loading: false, error: null, rows: response.data || [], meta: response.meta || null });
    } catch (error) {
      setState({ loading: false, error, rows: [], meta: null });
    }
  }, [filters, page]);

  useEffect(() => { load(); }, [load]);

  function handleFilterSubmit(event) {
    event.preventDefault();
    setFilters(draft);
    setPage(1);
  }

  return (
    <div>
      <form className="inv-filter-row" onSubmit={handleFilterSubmit}>
        <WarehouseSelect warehouses={warehouses} value={draft.warehouse_uuid} onChange={(e) => setDraft({ ...draft, warehouse_uuid: e.target.value })} />
        <input className="inv-input" placeholder="SKU" value={draft.sku} onChange={(e) => setDraft({ ...draft, sku: e.target.value })} />
        <select className="inv-select" value={draft.movement_type} onChange={(e) => setDraft({ ...draft, movement_type: e.target.value })}>
          <option value="">All movement types</option>
          {MOVEMENT_TYPES.map((type) => <option key={type} value={type}>{type}</option>)}
        </select>
        <button type="submit" className="admin-btn">Filter</button>
      </form>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.rows.length === 0 ? (
        <EmptyState title="No movements yet" hint="Sales, inward stock and adjustments will show up here as they happen." />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>When</th><th>SKU</th><th>Warehouse</th><th>Type</th>
                  <th style={{ textAlign: 'right' }}>Change</th><th style={{ textAlign: 'right' }}>Balance</th>
                  <th style={{ textAlign: 'right' }}>Cost</th><th>Customer</th><th>Reason / reference</th>
                </tr>
              </thead>
              <tbody>
                {state.rows.map((row, i) => {
                  const delta = Number(row.quantity_delta);
                  return (
                    <tr key={i}>
                      <td className="small-muted">{fmtDateTime(row.created_date)}</td>
                      <td>
                        <div style={{ fontWeight: 600 }}>{row.sku}</div>
                        <div className="small-muted">{row.variant_name}</div>
                      </td>
                      <td className="small-muted">{row.warehouse_name}</td>
                      <td><Tag tone={MOVEMENT_TONE[row.movement_type]}>{row.movement_type}</Tag></td>
                      <td style={{ textAlign: 'right', color: delta < 0 ? '#c0392b' : '#2e7d32' }}>{delta > 0 ? '+' : ''}{delta}</td>
                      <td style={{ textAlign: 'right' }} className="small-muted">{Number(row.quantity_after)}</td>
                      <td style={{ textAlign: 'right' }} className="small-muted">{row.unit_cost !== null ? formatMoney(row.unit_cost) : '—'}</td>
                      <td className="small-muted">{row.customer_name || '—'}</td>
                      <td className="small-muted">{row.reason || (row.reference_type ? `${row.reference_type}${row.reference_id ? ` #${row.reference_id}` : ''}` : '—')}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <Pagination meta={state.meta} page={page} onPrev={() => setPage((p) => p - 1)} onNext={() => setPage((p) => p + 1)} />
        </>
      )}
    </div>
  );
}
