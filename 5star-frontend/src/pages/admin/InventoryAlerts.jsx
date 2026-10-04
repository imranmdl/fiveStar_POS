import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { WarehouseSelect, Pagination, qty, canManageStock, reportError } from './InventoryShared';

const STATUS_OPTIONS = [['all', 'All'], ['low', 'Low Stock'], ['out', 'Out of Stock']];

function alertStatus(row) {
  const q = Number(row.quantity);
  if (q <= 0) return { label: 'Out of Stock', tone: 'danger' };
  if (row.reorder_threshold !== null && q <= Number(row.reorder_threshold)) return { label: 'Low Stock', tone: 'warning' };
  return { label: 'OK', tone: 'success' };
}

/**
 * Low Stock Alerts — same reorder_threshold/quantity comparison the Stock
 * tab's own stock_status filter already uses (just `stock_status=alert`
 * instead of `low`/`out`/`negative`), laid out for the one job of "what
 * needs reordering" with a straight line to creating a purchase. Ported
 * from renderAlertsTab(). `status` lives in the URL (?tab=alerts&status=out)
 * because Dashboard deep-links here with exactly that query string.
 */
export default function InventoryAlerts({ role, warehouses }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const status = searchParams.get('status') || 'all';
  const [warehouseUuid, setWarehouseUuid] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, rows: [], meta: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/stock', {
        warehouse_uuid: warehouseUuid,
        stock_status: status === 'all' ? 'alert' : status,
        page,
        per_page: 50,
      });
      setState({ loading: false, error: null, rows: response.data || [], meta: response.meta || null });
    } catch (error) {
      setState({ loading: false, error, rows: [], meta: null });
    }
  }, [warehouseUuid, status, page]);

  useEffect(() => { load(); }, [load]);

  function setStatus(next) {
    const params = new URLSearchParams(searchParams);
    if (next === 'all') params.delete('status'); else params.set('status', next);
    setSearchParams(params);
    setPage(1);
  }

  async function handleSetReorder(row) {
    const entered = window.prompt('Minimum stock level (leave blank to clear):', row.reorder_threshold !== null ? String(row.reorder_threshold) : '');
    if (entered === null) return;
    const threshold = entered.trim() === '' ? null : Number(entered);
    if (threshold !== null && (Number.isNaN(threshold) || threshold < 0)) {
      toast('Enter a number of zero or more.', 'danger');
      return;
    }
    try {
      await api.patch('/admin/inventory/reorder-threshold', {
        variant_uuid: row.variant_uuid,
        warehouse_uuid: row.warehouse_uuid,
        reorder_threshold: threshold,
      });
      toast('Minimum stock level saved.');
      load();
    } catch (error) {
      toast(reportError(error), 'danger');
    }
  }

  return (
    <div>
      <div className="inv-filter-row">
        <div className="inv-btn-group" role="group" aria-label="Status">
          {STATUS_OPTIONS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              className={`admin-btn ${status === value ? 'admin-btn--primary' : ''}`}
              onClick={() => setStatus(value)}
            >
              {label}
            </button>
          ))}
        </div>
        <WarehouseSelect warehouses={warehouses} value={warehouseUuid} onChange={(e) => { setWarehouseUuid(e.target.value); setPage(1); }} />
      </div>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.rows.length === 0 ? (
        <EmptyState
          title={status === 'all' ? 'Nothing needs reordering' : 'Nothing matches this filter'}
          hint="Every pack size with a minimum stock level set is at or above it."
        />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Product</th><th>SKU</th><th style={{ textAlign: 'right' }}>Current Stock</th>
                  <th style={{ textAlign: 'right' }}>Minimum Stock</th><th>Status</th><th></th>
                </tr>
              </thead>
              <tbody>
                {state.rows.map((row) => {
                  const st = alertStatus(row);
                  const createPurchaseUrl = `/admin/purchase-inward?tab=record&sku=${encodeURIComponent(row.sku)}&warehouse=${encodeURIComponent(row.warehouse_uuid)}`;
                  return (
                    <tr key={`${row.variant_uuid}:${row.warehouse_uuid}`}>
                      <td>
                        <div style={{ fontWeight: 600 }}>{row.product_name}</div>
                        <div className="small-muted">{row.variant_name} · {row.warehouse_name}</div>
                      </td>
                      <td className="small-muted">{row.sku}</td>
                      <td style={{ textAlign: 'right' }} className={st.tone === 'danger' || st.tone === 'warning' ? `inv-qty inv-qty--${st.tone}` : ''}>
                        {qty(row.quantity)}
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {canManageStock(role) ? (
                          <button type="button" className="inv-link" onClick={() => handleSetReorder(row)}>
                            {row.reorder_threshold !== null ? row.reorder_threshold : 'Set…'}
                          </button>
                        ) : (row.reorder_threshold !== null ? row.reorder_threshold : '—')}
                      </td>
                      <td><span className={`status-badge status-badge--${st.tone}`}>{st.label}</span></td>
                      <td style={{ textAlign: 'right' }}>
                        <a className="admin-btn admin-btn--primary" href={createPurchaseUrl}>Create Purchase</a>
                      </td>
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
