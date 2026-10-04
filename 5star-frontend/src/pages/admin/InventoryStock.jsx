import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { WarehouseSelect, Pagination, qty, canManageStock, reportError } from './InventoryShared';
import { TraceModal, PriceHistoryModal, AdjustModal } from './InventoryModals';

/** The inline "Set…" / threshold-value link every stock table shares. Manager-tier only — PATCH /admin/inventory/reorder-threshold is $manager-gated. */
function ReorderCell({ role, variantUuid, warehouseUuid, current, onSaved }) {
  const [busy, setBusy] = useState(false);

  if (!canManageStock(role)) {
    return <span>{current !== null ? current : '—'}</span>;
  }

  async function handleClick() {
    const entered = window.prompt('Minimum stock level (leave blank to clear):', current !== null ? String(current) : '');
    if (entered === null) return;

    const threshold = entered.trim() === '' ? null : Number(entered);
    if (threshold !== null && (Number.isNaN(threshold) || threshold < 0)) {
      toast('Enter a number of zero or more.', 'danger');
      return;
    }

    setBusy(true);
    try {
      await api.patch('/admin/inventory/reorder-threshold', {
        variant_uuid: variantUuid,
        warehouse_uuid: warehouseUuid,
        reorder_threshold: threshold,
      });
      toast('Minimum stock level saved.');
      onSaved();
    } catch (error) {
      setBusy(false);
      toast(reportError(error), 'danger');
    }
  }

  return (
    <button type="button" className="inv-link" onClick={handleClick} disabled={busy}>
      {current !== null ? current : 'Set…'}
    </button>
  );
}

function QuantityCell({ row }) {
  const q = Number(row.quantity);
  const low = row.reorder_threshold !== null && q <= Number(row.reorder_threshold);
  const cls = q < 0 ? 'inv-qty inv-qty--danger' : (low ? 'inv-qty inv-qty--warning' : 'inv-qty');
  return (
    <span>
      <span className={cls}>{qty(q)}</span>
      {low && <span className="status-badge status-badge--warning" style={{ marginLeft: 6 }}>Low</span>}
    </span>
  );
}

const BLANK_FILTERS = { warehouse_uuid: '', category_slug: '', sku: '', stock_status: '' };

export default function InventoryStock({ role, warehouses }) {
  const [filters, setFilters] = useState(BLANK_FILTERS);
  const [draft, setDraft] = useState(BLANK_FILTERS);
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, rows: [], meta: null });
  const [modal, setModal] = useState(null); // { kind: 'trace'|'price'|'adjust', ... }

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/stock', { ...filters, page, per_page: 30 });
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
        <input className="inv-input" placeholder="Category slug" value={draft.category_slug} onChange={(e) => setDraft({ ...draft, category_slug: e.target.value })} />
        <input className="inv-input" placeholder="SKU" value={draft.sku} onChange={(e) => setDraft({ ...draft, sku: e.target.value })} />
        <select className="inv-select" value={draft.stock_status} onChange={(e) => setDraft({ ...draft, stock_status: e.target.value })}>
          <option value="">All stock</option>
          <option value="low">Low stock</option>
          <option value="negative">Negative</option>
          <option value="out">Out of stock</option>
        </select>
        <button type="submit" className="admin-btn">Filter</button>
      </form>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.rows.length === 0 ? (
        <EmptyState title="No stock matches these filters" hint="Try widening the filters, or record an inward movement." />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Product</th><th>Warehouse</th><th style={{ textAlign: 'right' }}>Quantity</th>
                  <th style={{ textAlign: 'right' }}>Reorder at</th><th style={{ textAlign: 'right' }}>Avg. cost</th>
                  <th style={{ textAlign: 'right' }}>Selling price</th><th></th>
                </tr>
              </thead>
              <tbody>
                {state.rows.map((row) => (
                  <tr key={`${row.variant_uuid}:${row.warehouse_uuid}`}>
                    <td>
                      <div style={{ fontWeight: 600 }}>{row.product_name}</div>
                      <div className="small-muted">{row.variant_name} · {row.sku}{row.barcode ? ` · ${row.barcode}` : ''}</div>
                    </td>
                    <td className="small-muted">{row.warehouse_name}</td>
                    <td style={{ textAlign: 'right' }}><QuantityCell row={row} /></td>
                    <td style={{ textAlign: 'right' }}>
                      <ReorderCell role={role} variantUuid={row.variant_uuid} warehouseUuid={row.warehouse_uuid} current={row.reorder_threshold} onSaved={load} />
                    </td>
                    <td style={{ textAlign: 'right' }} className="small-muted">{row.average_cost !== null ? formatMoney(row.average_cost) : '—'}</td>
                    <td style={{ textAlign: 'right' }} className="small-muted">
                      {row.selling_price !== null ? formatMoney(row.selling_price) : '—'}
                      {' '}
                      <button type="button" className="inv-link" title="Price history" onClick={() => setModal({ kind: 'price', variantUuid: row.variant_uuid, label: `${row.product_name} — ${row.variant_name}` })}>
                        history
                      </button>
                    </td>
                    <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                      <button type="button" className="admin-btn" onClick={() => setModal({ kind: 'trace', variantUuid: row.variant_uuid, label: `${row.product_name} — ${row.variant_name}` })}>
                        Trace
                      </button>
                      {' '}
                      <button
                        type="button"
                        className="admin-btn"
                        onClick={() => setModal({
                          kind: 'adjust',
                          variantUuid: row.variant_uuid,
                          warehouseUuid: row.warehouse_uuid,
                          label: `${row.product_name} — ${row.variant_name} @ ${row.warehouse_name}`,
                        })}
                      >
                        Adjust
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={state.meta} page={page} onPrev={() => setPage((p) => p - 1)} onNext={() => setPage((p) => p + 1)} />
        </>
      )}

      {modal && modal.kind === 'trace' && (
        <TraceModal variantUuid={modal.variantUuid} label={modal.label} onClose={() => setModal(null)} />
      )}
      {modal && modal.kind === 'price' && (
        <PriceHistoryModal variantUuid={modal.variantUuid} label={modal.label} onClose={() => setModal(null)} />
      )}
      {modal && modal.kind === 'adjust' && (
        <AdjustModal
          role={role}
          variantUuid={modal.variantUuid}
          warehouseUuid={modal.warehouseUuid}
          label={modal.label}
          onClose={() => setModal(null)}
          onSaved={load}
        />
      )}
    </div>
  );
}
