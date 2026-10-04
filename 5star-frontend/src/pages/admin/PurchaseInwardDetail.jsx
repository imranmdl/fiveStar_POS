import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast.js';
import { LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import { printBarcodeLabels, printPurchaseOrder } from './purchaseInwardPrint.js';

const TODAY = () => new Date().toISOString().slice(0, 10);

function paymentTone(status) {
  return status === 'paid' ? 'success' : status === 'partial' ? 'warning' : 'secondary';
}

/**
 * Purchase order detail — ported from admin/assets/page-purchase-inward.js
 * (renderHistoryDetail + itemRow + renderReturnForm). Line corrections call
 * PATCH .../items/{uuid}, which does not rewrite history in place — the
 * server reverses the line's original inventory effect and posts the
 * corrected one as new movements, so stock/average-cost stay consistent.
 */
export default function PurchaseInwardDetail({ uuid, onBack }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [po, setPo] = useState(null);
  const [editingUuid, setEditingUuid] = useState(null);
  const [busyUuid, setBusyUuid] = useState(null);
  const [showReturnForm, setShowReturnForm] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get(`/admin/purchase-orders/${encodeURIComponent(uuid)}`);
      setPo(response.data);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [uuid]);

  useEffect(() => { load(); }, [load]);

  async function togglePublish(item) {
    const action = item.product_status === 'published' ? 'archive' : 'publish';
    setBusyUuid(item.uuid);

    try {
      await api.post(`/admin/products/${encodeURIComponent(item.product_uuid)}/${action}`);
      toast(action === 'publish' ? 'Now on sale — visible on the shop.' : 'Taken off the shop.');
      await load();
    } catch (error) {
      // publish() can refuse (no image, no short description, no pack size)
      // — the goal of this button, but not always achievable from here alone.
      toast(error instanceof ApiError ? error.message : 'Could not update that product.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function generateBarcode(item) {
    setBusyUuid(item.uuid);
    try {
      await api.post(`/admin/inventory/variants/${encodeURIComponent(item.variant_uuid)}/barcode`, {});
      await load();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not generate a barcode.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function deleteItem(item) {
    if (!window.confirm(
      `Delete ${item.sku} — ${item.variant_name} from ${po.po_number}? The stock it brought in will be reversed. This cannot be undone.`,
    )) return;

    setBusyUuid(item.uuid);

    try {
      await api.delete(`/admin/purchase-orders/${encodeURIComponent(uuid)}/items/${encodeURIComponent(item.uuid)}`);
      toast('Line deleted. Stock has been reversed.');
      await load();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not delete that line.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function saveEdit(itemUuid, fields) {
    setBusyUuid(itemUuid);
    try {
      await api.patch(`/admin/purchase-orders/${encodeURIComponent(uuid)}/items/${encodeURIComponent(itemUuid)}`, {
        quantity: fields.quantity,
        invoiced_quantity: fields.invoiced_quantity || null,
        unit_cost: fields.unit_cost,
        batch_no: fields.batch_no || null,
        expiry_date: fields.expiry_date || null,
      });
      toast('Line corrected.');
      setEditingUuid(null);
      await load();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not save that correction.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function submitPayment(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    setBusyUuid('payment');

    try {
      await api.post(`/admin/purchase-orders/${encodeURIComponent(uuid)}/payments`, data);
      toast('Payment recorded.');
      form.reset();
      await load();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not record that payment.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  if (loading) return <LoadingState />;

  if (error) {
    return (
      <div>
        <button type="button" className="pi-link-btn" onClick={onBack}>← Purchase orders</button>
        <ErrorState error={error} />
      </div>
    );
  }

  const balanceDue = Number(po.grand_total) - Number(po.amount_paid) - Number(po.amount_returned);

  return (
    <div>
      <button type="button" className="pi-link-btn" onClick={onBack}>← Purchase orders</button>

      <div className="pi-row-between pi-mt2 pi-mb3">
        <div>
          <h2 className="pi-h5">{po.po_number}</h2>
          <span className="pi-small pi-muted">{po.vendor_name} · {po.warehouse_name} · {String(po.purchase_date || '').slice(0, 10)}</span>
        </div>
        <span className={`status-badge status-badge--${paymentTone(po.payment_status)}`}>{po.payment_status}</span>
      </div>

      <div className="pi-detail-grid">
        <div className="pi-detail-main">
          <div className="pi-card">
            <div className="pi-card__header">Items</div>
            <div className="pi-lines-table-wrap">
              <table className="admin-table pi-lines-table">
                <thead>
                  <tr><th>Item</th><th>Barcode</th><th className="pi-right">Qty</th><th className="pi-right">Unit cost</th><th className="pi-right">Landing cost</th><th></th></tr>
                </thead>
                <tbody>
                  {po.items.map((item) => (
                    <ItemRow
                      key={item.uuid}
                      item={item}
                      editing={editingUuid === item.uuid}
                      busy={busyUuid === item.uuid}
                      onStartEdit={() => setEditingUuid(item.uuid)}
                      onCancelEdit={() => setEditingUuid(null)}
                      onSaveEdit={(fields) => saveEdit(item.uuid, fields)}
                      onDelete={() => deleteItem(item)}
                      onTogglePublish={() => togglePublish(item)}
                      onGenerateBarcode={() => generateBarcode(item)}
                      onPrintBarcode={() => printBarcodeLabels([{ barcode: item.barcode, title: item.sku, subtitle: item.variant_name }])}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div className="pi-detail-side">
          <div className="pi-card">
            <div className="pi-card__header">Charges</div>
            <div className="pi-card__body">
              <dl className="pi-dl">
                <div><dt>Items subtotal</dt><dd>{formatMoney(po.items_subtotal)}</dd></div>
                <div><dt>Discount</dt><dd>{formatMoney(po.discount_amount)}</dd></div>
                <div><dt>Transportation</dt><dd>{formatMoney(po.transport_charge)}{po.transport_percent ? ` (${Number(po.transport_percent).toFixed(2)}%)` : ''}</dd></div>
                <div><dt>Other charges</dt><dd>{formatMoney(po.other_charges)}</dd></div>
                <div><dt>Tax</dt><dd>{formatMoney(po.tax_amount)}</dd></div>
                <div className="pi-dl-strong"><dt>Grand total</dt><dd>{formatMoney(po.grand_total)}</dd></div>
              </dl>
            </div>
          </div>

          <div className="pi-card">
            <div className="pi-card__header pi-row-between">
              <span>Payments</span>
              <button type="button" className="admin-btn pi-btn-xs" onClick={() => printPurchaseOrder(po)}>Print</button>
            </div>
            <div className="pi-card__body">
              <dl className="pi-dl pi-mb2">
                <div><dt>Paid so far</dt><dd>{formatMoney(po.amount_paid)}</dd></div>
                <div><dt>Returned</dt><dd>{formatMoney(po.amount_returned)}</dd></div>
                <div><dt>Balance due</dt><dd>{formatMoney(balanceDue)}</dd></div>
              </dl>

              {(po.payments_list || []).length > 0 && (
                <table className="admin-table pi-mb2">
                  <tbody>
                    {po.payments_list.map((p, i) => (
                      <tr key={i}>
                        <td className="pi-small">{p.payment_date}<div className="pi-muted pi-uppercase">{p.payment_method}{p.reference_number ? ` · ${p.reference_number}` : ''}</div></td>
                        <td className="pi-right">{formatMoney(p.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}

              <form className="pi-grid pi-grid-2" onSubmit={submitPayment}>
                <label className="pi-field">
                  <span>Amount</span>
                  <input name="amount" type="number" step="0.01" min="0.01" required />
                </label>
                <label className="pi-field">
                  <span>Method</span>
                  <select name="payment_method" required defaultValue="cash">
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="pos">POS</option>
                  </select>
                </label>
                <label className="pi-field">
                  <span>Date</span>
                  <input name="payment_date" type="date" defaultValue={TODAY()} required />
                </label>
                <label className="pi-field">
                  <span>Reference</span>
                  <input name="reference_number" maxLength={100} placeholder="Optional" />
                </label>
                <div className="pi-field-full">
                  <button type="submit" className="admin-btn admin-btn--primary pi-full-btn" disabled={busyUuid === 'payment'}>
                    {busyUuid === 'payment' ? 'Saving…' : 'Record payment'}
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div className="pi-card">
            <div className="pi-card__header">Returns</div>
            <div className="pi-card__body">
              {(po.returns_list || []).length > 0 ? (
                <table className="admin-table pi-mb2">
                  <tbody>
                    {po.returns_list.map((r, i) => (
                      <tr key={i}>
                        <td className="pi-small">{r.return_number}<div className="pi-muted">{r.reason}</div></td>
                        <td className="pi-right">{formatMoney(r.total_amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <p className="pi-muted pi-small">No returns recorded.</p>
              )}
              <button type="button" className="admin-btn pi-full-btn" onClick={() => setShowReturnForm((s) => !s)}>Return items to vendor</button>
            </div>
          </div>
        </div>
      </div>

      {showReturnForm && (
        <ReturnForm
          po={po}
          purchaseOrderUuid={uuid}
          onDone={() => { setShowReturnForm(false); load(); }}
          onCancel={() => setShowReturnForm(false)}
        />
      )}
    </div>
  );
}

function ItemRow({ item, editing, busy, onStartEdit, onCancelEdit, onSaveEdit, onDelete, onTogglePublish, onGenerateBarcode, onPrintBarcode }) {
  const [quantity, setQuantity] = useState(item.quantity);
  const [invoicedQuantity, setInvoicedQuantity] = useState(item.invoiced_quantity ?? '');
  const [unitCost, setUnitCost] = useState(item.unit_cost);
  const [batchNo, setBatchNo] = useState(item.batch_no || '');
  const [expiryDate, setExpiryDate] = useState(item.expiry_date || '');

  if (!editing) {
    const invoiceShort = item.invoiced_quantity !== null && Number(item.invoiced_quantity) > Number(item.quantity);

    return (
      <tr>
        <td>
          {item.sku}<div className="pi-muted">{item.variant_name}</div>
          <div className="pi-small">
            <span className="pi-muted">{item.product_name || ''}</span>
            {item.product_status === 'published' ? (
              <span className="status-badge status-badge--success pi-ml4">On sale</span>
            ) : (
              <span className="status-badge status-badge--secondary pi-ml4">{item.product_status || 'draft'}</span>
            )}
            {item.product_uuid && (
              <button type="button" className="pi-link-btn pi-ml4" disabled={busy} onClick={onTogglePublish}>
                {item.product_status === 'published' ? 'Unpublish' : 'Publish to shop'}
              </button>
            )}
          </div>
          {(item.loss_events || []).map((event, i) => (
            <div key={i} className="pi-small pi-text-danger pi-mt4">
              {Math.abs(Number(event.quantity_delta))} unit(s) marked {event.movement_type} on {String(event.created_date || '').slice(0, 10)} — {event.reason || 'no reason given'}
            </div>
          ))}
          {invoiceShort && (
            <div className="pi-small pi-text-danger pi-mt4">
              Invoice loss: billed {item.invoiced_quantity}, received {item.quantity} — {(Number(item.invoiced_quantity) - Number(item.quantity)).toFixed(3).replace(/\.?0+$/, '')} unit(s) short
            </div>
          )}
        </td>
        <td className="pi-small">
          {item.barcode ? (
            <>
              {item.barcode}<br />
              <button type="button" className="admin-btn pi-btn-xs" onClick={onPrintBarcode}>Print</button>
            </>
          ) : (
            <button type="button" className="admin-btn pi-btn-xs" disabled={busy} onClick={onGenerateBarcode}>
              {busy ? 'Generating…' : 'Generate'}
            </button>
          )}
        </td>
        <td className="pi-right">{Number(item.quantity)}</td>
        <td className="pi-right">{formatMoney(item.unit_cost)}</td>
        <td className={`pi-right ${Number(item.landing_cost) !== Number(item.unit_cost) ? 'pi-text-warning pi-fw-semibold' : ''}`}>{formatMoney(item.landing_cost)}</td>
        <td className="pi-right pi-nowrap">
          <button type="button" className="admin-btn pi-btn-xs" disabled={busy} onClick={onStartEdit}>Edit</button>{' '}
          <button type="button" className="admin-btn admin-btn--danger-outline pi-btn-xs" disabled={busy} onClick={onDelete}>Delete</button>
        </td>
      </tr>
    );
  }

  const lossTotal = (item.loss_events || []).reduce((sum, e) => sum + Math.abs(Number(e.quantity_delta)), 0);

  return (
    <tr>
      <td colSpan={6}>
        {lossTotal > 0 && (
          <div className="admin-alert admin-alert--warning pi-small pi-mb2">
            {lossTotal} unit(s) already marked damaged/lost against this line — correcting the quantity won&rsquo;t change that.
          </div>
        )}
        <div className="pi-grid pi-grid-edit">
          <label className="pi-field-sm"><span>Quantity</span>
            <input type="number" step="0.001" min="0.001" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
          </label>
          <label className="pi-field-sm"><span>Invoiced qty</span>
            <input type="number" step="0.001" min="0" placeholder="Same" title="Fill in once you have the vendor's invoice in hand, if it differs from what arrived" value={invoicedQuantity} onChange={(e) => setInvoicedQuantity(e.target.value)} />
          </label>
          <label className="pi-field-sm"><span>Unit cost</span>
            <input type="number" step="0.0001" min="0" value={unitCost} onChange={(e) => setUnitCost(e.target.value)} />
          </label>
          <label className="pi-field-sm"><span>Batch</span>
            <input maxLength={60} value={batchNo} onChange={(e) => setBatchNo(e.target.value)} />
          </label>
          <label className="pi-field-sm"><span>Expiry</span>
            <input type="date" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} />
          </label>
          <div className="pi-field-sm pi-edit-actions">
            <button type="button" className="admin-btn admin-btn--primary pi-btn-xs" disabled={busy} onClick={() => onSaveEdit({ quantity, invoiced_quantity: invoicedQuantity, unit_cost: unitCost, batch_no: batchNo, expiry_date: expiryDate })}>
              {busy ? 'Saving…' : 'Save'}
            </button>
            <button type="button" className="admin-btn pi-btn-xs" onClick={onCancelEdit}>Cancel</button>
          </div>
        </div>
      </td>
    </tr>
  );
}

/**
 * Select items/quantities from this PO to send back to the vendor. The
 * per-line max is only the ORIGINAL purchased quantity here — the server is
 * the real authority on what's still available to return (it also
 * subtracts anything already returned), so an over-return attempt still
 * surfaces as a clear error on submit rather than being silently capped
 * wrong client-side.
 */
function ReturnForm({ po, purchaseOrderUuid, onDone, onCancel }) {
  const [checked, setChecked] = useState({});
  const [quantities, setQuantities] = useState({});
  const [returnDate, setReturnDate] = useState(TODAY());
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  function toggle(item) {
    const isChecked = !checked[item.uuid];
    setChecked((c) => ({ ...c, [item.uuid]: isChecked }));
    if (isChecked && !quantities[item.uuid]) {
      setQuantities((q) => ({ ...q, [item.uuid]: String(item.quantity) }));
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();

    const lines = (po.items || [])
      .filter((item) => checked[item.uuid])
      .map((item) => ({ purchase_order_item_uuid: item.uuid, quantity: quantities[item.uuid] }));

    if (lines.length === 0) {
      toast('Select at least one item to return.', 'danger');
      return;
    }

    setSaving(true);

    try {
      await api.post('/admin/purchase-returns', {
        purchase_order_uuid: purchaseOrderUuid,
        return_date: returnDate,
        reason,
        lines,
      });
      toast('Return recorded. Stock and vendor balance are updated.');
      onDone();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not record that return.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="pi-card pi-mt3">
      <div className="pi-card__body">
        <h3 className="pi-h6">Return items to {po.vendor_name}</h3>
        <form onSubmit={handleSubmit}>
          <div className="pi-lines-table-wrap pi-mb3">
            <table className="admin-table">
              <thead><tr><th></th><th>Item</th><th>Purchased</th><th>Return qty</th></tr></thead>
              <tbody>
                {(po.items || []).map((item) => (
                  <tr key={item.uuid}>
                    <td><input type="checkbox" checked={Boolean(checked[item.uuid])} onChange={() => toggle(item)} /></td>
                    <td className="pi-small">{item.sku}<div className="pi-muted">{item.batch_no || ''}</div></td>
                    <td className="pi-small">{Number(item.quantity)}</td>
                    <td>
                      <input
                        type="number"
                        step="0.001"
                        min="0.001"
                        max={item.quantity}
                        disabled={!checked[item.uuid]}
                        value={quantities[item.uuid] || ''}
                        onChange={(e) => setQuantities((q) => ({ ...q, [item.uuid]: e.target.value }))}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="pi-grid pi-grid-2">
            <label className="pi-field">
              <span>Return date</span>
              <input type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} required />
            </label>
            <label className="pi-field pi-field-lg">
              <span>Reason</span>
              <input maxLength={500} required placeholder="e.g. damaged in transit" value={reason} onChange={(e) => setReason(e.target.value)} />
            </label>
          </div>

          <div className="pi-toolbar">
            <button type="submit" className="admin-btn admin-btn--primary" disabled={saving}>{saving ? 'Saving…' : 'Record return'}</button>
            <button type="button" className="admin-btn" onClick={onCancel}>Cancel</button>
          </div>
        </form>
      </div>
    </div>
  );
}
