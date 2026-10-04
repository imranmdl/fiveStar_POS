import { useCallback, useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { Pagination, qty, fmtDateTime, canManageStock, isAdministrator, reportError } from './InventoryShared';

const BLANK = { product: '', sku: '', deleted_from: '', deleted_to: '' };

/**
 * Recycle Bin — ported from renderDeletedTab(). Restoring is $manager-gated
 * (POST .../restore); permanently deleting is administrator-only (DELETE
 * .../deleted/{uuid}) — both confirmed against routes/api_v1.php, not
 * assumed from the button existing in the old markup.
 */
export default function InventoryDeleted({ role }) {
  const [filters, setFilters] = useState(BLANK);
  const [draft, setDraft] = useState(BLANK);
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, rows: [], meta: null });
  const [busyUuid, setBusyUuid] = useState(null);

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/deleted', { ...filters, page, per_page: 30 });
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

  async function handleRestore(row) {
    if (!window.confirm(`Restore "${row.product_name}" back into Active Inventory?`)) return;
    setBusyUuid(row.variant_uuid);
    try {
      await api.post(`/admin/inventory/deleted/${encodeURIComponent(row.variant_uuid)}/restore`);
      toast('Item restored.');
      load();
    } catch (error) {
      toast(reportError(error), 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function handlePurge(row) {
    if (!window.confirm(
      `Permanently delete "${row.product_name}"? This CANNOT be recovered — it will be gone for good, not just moved to the recycle bin. Continue?`
    )) return;
    setBusyUuid(row.variant_uuid);
    try {
      await api.delete(`/admin/inventory/deleted/${encodeURIComponent(row.variant_uuid)}`, { confirm: 'yes' });
      toast('Item permanently deleted.', 'warning');
      load();
    } catch (error) {
      toast(reportError(error), 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  return (
    <div>
      <form className="inv-filter-row" onSubmit={handleFilterSubmit}>
        <input className="inv-input" placeholder="Product name" value={draft.product} onChange={(e) => setDraft({ ...draft, product: e.target.value })} />
        <input className="inv-input" placeholder="SKU" value={draft.sku} onChange={(e) => setDraft({ ...draft, sku: e.target.value })} />
        <input className="inv-input" type="date" title="Deleted from" value={draft.deleted_from} onChange={(e) => setDraft({ ...draft, deleted_from: e.target.value })} />
        <input className="inv-input" type="date" title="Deleted to" value={draft.deleted_to} onChange={(e) => setDraft({ ...draft, deleted_to: e.target.value })} />
        <button type="submit" className="admin-btn">Filter</button>
      </form>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.rows.length === 0 ? (
        <EmptyState title="Recycle bin is empty" hint="Products and pack sizes you delete will show up here, never in Active Inventory." />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Product</th><th>SKU</th><th style={{ textAlign: 'right' }}>Quantity</th>
                  <th>Deleted on</th><th>Deleted by</th><th></th>
                </tr>
              </thead>
              <tbody>
                {state.rows.map((row) => (
                  <tr key={row.variant_uuid}>
                    <td>
                      <div style={{ fontWeight: 600 }}>{row.product_name}</div>
                      {row.variant_name && <div className="small-muted">{row.variant_name}</div>}
                    </td>
                    <td className="small-muted">{row.sku}</td>
                    <td style={{ textAlign: 'right' }}>{qty(row.quantity)}</td>
                    <td className="small-muted">{fmtDateTime(row.deleted_date)}</td>
                    <td className="small-muted">{row.deleted_by_name || '—'}</td>
                    <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                      {canManageStock(role) && (
                        <button type="button" className="admin-btn" disabled={busyUuid === row.variant_uuid} onClick={() => handleRestore(row)}>
                          {busyUuid === row.variant_uuid ? 'Restoring…' : 'Restore'}
                        </button>
                      )}
                      {' '}
                      {isAdministrator(role) && (
                        <button type="button" className="admin-btn" disabled={busyUuid === row.variant_uuid} onClick={() => handlePurge(row)}>
                          {busyUuid === row.variant_uuid ? 'Deleting…' : 'Permanent delete'}
                        </button>
                      )}
                      {!canManageStock(role) && !isAdministrator(role) && <span className="small-muted">View only</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={state.meta} page={page} onPrev={() => setPage((p) => p - 1)} onNext={() => setPage((p) => p + 1)} />
        </>
      )}
    </div>
  );
}
