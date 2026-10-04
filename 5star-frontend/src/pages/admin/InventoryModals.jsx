import { useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState } from '../../components/admin/shared';
import { fmtDate, fmtDateTime, qty, canManageStock, reportError } from './InventoryShared';

/** A plain overlay "modal" — this app has no Bootstrap, so these are just fixed-position panels (same approach Orders.jsx uses for its slide-over). */
function Overlay({ title, onClose, children, wide }) {
  return (
    <div className="inv-overlay" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className={`inv-modal ${wide ? 'inv-modal--wide' : ''}`}>
        <div className="inv-modal__header">
          <h2 className="inv-modal__title">{title}</h2>
          <button type="button" className="admin-btn" aria-label="Close" onClick={onClose}>&times;</button>
        </div>
        <div className="inv-modal__body">{children}</div>
      </div>
    </div>
  );
}

/**
 * "Trace this item" — current stock per warehouse, the batches on hand (only
 * populated when a movement carried a batch_no) and the full purchase
 * history (always available, regardless of batch tracking) — ported from
 * openTraceModal() in page-inventory.js.
 */
export function TraceModal({ variantUuid, label, onClose }) {
  const [state, setState] = useState({ loading: true, error: null, detail: null });

  useEffect(() => {
    let cancelled = false;
    setState({ loading: true, error: null, detail: null });
    api.get(`/admin/inventory/stock/${encodeURIComponent(variantUuid)}`)
      .then((response) => { if (!cancelled) setState({ loading: false, error: null, detail: response.data }); })
      .catch((error) => { if (!cancelled) setState({ loading: false, error, detail: null }); });
    return () => { cancelled = true; };
  }, [variantUuid]);

  const detail = state.detail;
  const newestBatchNo = detail && detail.batches && detail.batches.length
    ? [...detail.batches].sort((a, b) => new Date(b.created_date) - new Date(a.created_date))[0].batch_no
    : null;

  return (
    <Overlay title={`Trace — ${label}`} onClose={onClose} wide>
      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : (
        <>
          <h3 className="inv-modal__h3">Current stock</h3>
          <table className="admin-table inv-modal__table">
            <thead><tr><th>Warehouse</th><th style={{ textAlign: 'right' }}>Quantity</th></tr></thead>
            <tbody>
              {(detail.warehouses || []).length ? detail.warehouses.map((w, i) => (
                <tr key={i}><td>{w.warehouse_name}</td><td style={{ textAlign: 'right' }}>{qty(w.quantity)}</td></tr>
              )) : <tr><td colSpan={2} className="small-muted" style={{ textAlign: 'center' }}>No stock anywhere.</td></tr>}
            </tbody>
          </table>

          <h3 className="inv-modal__h3">Batches on hand</h3>
          <p className="small-muted">Only shows when a batch number was recorded on an inward or adjustment — not every restock is batch-tracked.</p>
          <table className="admin-table inv-modal__table">
            <thead><tr><th>Batch</th><th style={{ textAlign: 'right' }}>Remaining</th><th style={{ textAlign: 'right' }}>Unit cost</th><th>Expiry</th></tr></thead>
            <tbody>
              {(detail.batches || []).length ? detail.batches.map((b, i) => (
                <tr key={i}>
                  <td>{b.batch_no}{b.batch_no === newestBatchNo ? <> <span className="status-badge status-badge--success">Newest</span></> : null}</td>
                  <td style={{ textAlign: 'right' }}>{qty(b.quantity)}</td>
                  <td style={{ textAlign: 'right' }}>{b.unit_cost !== null ? formatMoney(b.unit_cost) : '—'}</td>
                  <td>{b.expiry_date || '—'}</td>
                </tr>
              )) : <tr><td colSpan={4} className="small-muted" style={{ textAlign: 'center' }}>No batches tracked for this item.</td></tr>}
            </tbody>
          </table>

          <h3 className="inv-modal__h3">Purchase history</h3>
          <table className="admin-table inv-modal__table">
            <thead><tr><th>Date</th><th>Vendor</th><th>PO</th><th style={{ textAlign: 'right' }}>Qty</th><th style={{ textAlign: 'right' }}>Unit cost</th><th style={{ textAlign: 'right' }}>Landing cost</th></tr></thead>
            <tbody>
              {(detail.purchase_history || []).length ? detail.purchase_history.map((h, i) => (
                <tr key={i}>
                  <td>{fmtDate(h.purchase_date)}</td>
                  <td>{h.vendor_name}</td>
                  <td>{h.po_number}</td>
                  <td style={{ textAlign: 'right' }}>{qty(h.quantity)}</td>
                  <td style={{ textAlign: 'right' }}>{formatMoney(h.unit_cost)}</td>
                  <td style={{ textAlign: 'right' }}>{formatMoney(h.landing_cost)}</td>
                </tr>
              )) : <tr><td colSpan={6} className="small-muted" style={{ textAlign: 'center' }}>Never purchased through a recorded purchase order.</td></tr>}
            </tbody>
          </table>
        </>
      )}
    </Overlay>
  );
}

/** Price-change log for one pack size — ported from openPriceHistoryModal(). */
export function PriceHistoryModal({ variantUuid, label, onClose }) {
  const [state, setState] = useState({ loading: true, error: null, rows: [] });

  useEffect(() => {
    let cancelled = false;
    setState({ loading: true, error: null, rows: [] });
    api.get(`/admin/pricing/history/${encodeURIComponent(variantUuid)}`)
      .then((response) => { if (!cancelled) setState({ loading: false, error: null, rows: response.data || [] }); })
      .catch((error) => { if (!cancelled) setState({ loading: false, error, rows: [] }); });
    return () => { cancelled = true; };
  }, [variantUuid]);

  return (
    <Overlay title={`Price history — ${label}`} onClose={onClose}>
      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.rows.length === 0 ? (
        <p className="small-muted">No price changes logged for this pack size yet.</p>
      ) : (
        <table className="admin-table inv-modal__table">
          <thead><tr><th>Date</th><th style={{ textAlign: 'right' }}>Old</th><th style={{ textAlign: 'right' }}>New</th><th>Decision</th><th>Reason</th></tr></thead>
          <tbody>
            {state.rows.map((r, i) => (
              <tr key={i}>
                <td>{fmtDateTime(r.created_date)}</td>
                <td style={{ textAlign: 'right' }}>{formatMoney(r.old_selling_price)}</td>
                <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatMoney(r.new_selling_price)}</td>
                <td>{r.decision}</td>
                <td>{r.reason || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Overlay>
  );
}

const ADJUST_TYPES = [
  ['adjustment', 'Count adjustment'],
  ['inward', 'Inward (received stock)'],
  ['damage', 'Damage / write-off'],
  ['lost', 'Lost (missing, unaccounted)'],
  ['return', 'Return to stock'],
];

/**
 * Adjust stock — ported from openAdjustModal().
 *
 * CRITICAL BUSINESS RULE (confirmed against InventoryController +
 * routes/api_v1.php, not assumed): POST /admin/inventory/adjust — which
 * changes `quantity` immediately — is gated to $manager (administrator,
 * supervisor, manager). It is NOT exposed to inventory_staff or executive.
 * Those two roles only have POST /admin/inventory/adjustments/request-approval,
 * which raises a `pending` row in the approval queue (ApprovalService) and
 * does not touch stock itself — a manager/administrator/supervisor reviews
 * and performs the actual adjustment later from Admin Privilege Management
 * (access-control.html's React port), a different screen. So: this modal
 * calls the direct endpoint only when the signed-in role can use it; every
 * other viewer of this page gets the request-only form instead, and is told
 * so in the UI rather than just having fields silently vanish.
 */
export function AdjustModal({ role, variantUuid, warehouseUuid, label, onClose, onSaved }) {
  const direct = canManageStock(role);

  const [movementType, setMovementType] = useState('adjustment');
  const [quantityDelta, setQuantityDelta] = useState('');
  const [unitCost, setUnitCost] = useState('');
  const [batchNo, setBatchNo] = useState('');
  const [expiryDate, setExpiryDate] = useState('');
  const [reason, setReason] = useState('');
  const [batches, setBatches] = useState([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!direct) return; // batch picker is decoration for the direct-adjust form only — the request-approval endpoint has no batch_no field to fill
    let cancelled = false;
    api.get(`/admin/inventory/stock/${encodeURIComponent(variantUuid)}`)
      .then((response) => { if (!cancelled) setBatches(response.data.batches || []); })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [variantUuid, direct]);

  async function handleSubmit(event) {
    event.preventDefault();

    const delta = Number(quantityDelta);
    if (quantityDelta === '' || Number.isNaN(delta)) {
      toast('Enter a quantity change.', 'danger');
      return;
    }
    if (reason.trim().length < 3) {
      toast('Reason must be at least 3 characters.', 'danger');
      return;
    }

    setSaving(true);

    try {
      if (direct) {
        const payload = {
          variant_uuid: variantUuid,
          warehouse_uuid: warehouseUuid,
          movement_type: movementType,
          quantity_delta: delta,
          reason: reason.trim(),
        };
        if (unitCost.trim() !== '') payload.unit_cost = Number(unitCost);
        if (batchNo.trim() !== '') payload.batch_no = batchNo.trim();
        if (expiryDate.trim() !== '') payload.expiry_date = expiryDate;

        await api.post('/admin/inventory/adjust', payload);
        toast('Stock adjusted.');
      } else {
        await api.post('/admin/inventory/adjustments/request-approval', {
          variant_uuid: variantUuid,
          warehouse_uuid: warehouseUuid,
          movement_type: movementType,
          quantity_delta: delta,
          reason: reason.trim(),
        });
        toast('Adjustment request submitted — a manager or administrator must approve it from Admin Privilege Management before stock changes.', 'warning');
      }
      onSaved();
      onClose();
    } catch (error) {
      setSaving(false);
      toast(reportError(error), 'danger');
    }
  }

  function handleBatchPick(event) {
    const value = event.target.value;
    setBatchNo(value);
  }

  return (
    <Overlay title="Adjust stock" onClose={onClose}>
      <p className="small-muted">{label}</p>

      {!direct && (
        <div className="admin-alert admin-alert--warning">
          Your role ({role}) cannot change stock directly here. Submitting this raises a request in the
          approval queue — nothing changes until a manager or administrator approves it from Admin Privilege
          Management.
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <label className="inv-field">
          <span>Type</span>
          <select value={movementType} onChange={(e) => setMovementType(e.target.value)} required>
            {ADJUST_TYPES.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
          </select>
        </label>

        <label className="inv-field">
          <span>Quantity change</span>
          <input
            type="number"
            step="0.001"
            required
            placeholder="Positive to add, negative to remove"
            value={quantityDelta}
            onChange={(e) => setQuantityDelta(e.target.value)}
          />
        </label>

        {direct && movementType === 'inward' && (
          <>
            <label className="inv-field">
              <span>Unit cost (₹)</span>
              <input type="number" step="0.0001" min="0" value={unitCost} onChange={(e) => setUnitCost(e.target.value)} />
              <span className="small-muted">Recalculates the weighted-average cost. Leave blank if this isn't an inward at a known cost.</span>
            </label>
            <label className="inv-field">
              <span>Expiry date</span>
              <input type="date" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} />
            </label>
          </>
        )}

        {direct && (
          <label className="inv-field">
            <span>Batch</span>
            {batches.length > 0 && (
              <select className="inv-batch-select" value="" onChange={handleBatchPick}>
                <option value="">New / untracked batch…</option>
                {batches.map((b, i) => (
                  <option key={i} value={b.batch_no}>
                    {b.batch_no} — {qty(b.quantity)} left{b.unit_cost !== null ? ` @ ${formatMoney(b.unit_cost)}` : ''}
                  </option>
                ))}
              </select>
            )}
            <input maxLength={60} placeholder="Optional — type a new batch number" value={batchNo} onChange={(e) => setBatchNo(e.target.value)} />
            <span className="small-muted">For damage/loss: picking an existing batch is what lets this be traced back to the vendor/purchase order it came from.</span>
          </label>
        )}

        <label className="inv-field">
          <span>Reason</span>
          <input required minLength={3} maxLength={255} placeholder="e.g. Physical count correction, damaged in transit" value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>

        <div className="inv-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={saving}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--primary" disabled={saving}>
            {saving ? 'Saving…' : direct ? 'Save adjustment' : 'Submit for approval'}
          </button>
        </div>
      </form>
    </Overlay>
  );
}
