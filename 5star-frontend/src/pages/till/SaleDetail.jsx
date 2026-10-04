import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import Receipt from './Receipt';
import { ErrorBanner, LoadingState } from './TillShared';

/**
 * A single sale's receipt plus its actions — ported from
 * renderHistoryDetail() in admin/assets/page-till.js. Void/refund still use
 * window.prompt/confirm, faithfully matching the source rather than
 * inventing a new modal flow for a page already carrying three new scan
 * surfaces (HID/camera/manual) — see Till.jsx's report notes.
 */
export default function SaleDetail({ uuid, onBack, notify }) {
  const [sale, setSale] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busyAction, setBusyAction] = useState(null);

  async function load() {
    setLoading(true);
    setError(null);

    try {
      const response = await api.get(`/admin/pos/sales/${encodeURIComponent(uuid)}`);
      setSale(response.data);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, [uuid]);

  async function markDelivered() {
    setBusyAction('deliver');
    try {
      await api.post(`/admin/pos/sales/${encodeURIComponent(uuid)}/deliver`, {});
      notify('Marked as delivered.');
      await load();
    } catch (err) {
      notify(err.message || 'Could not mark as delivered.', 'danger');
    } finally {
      setBusyAction(null);
    }
  }

  async function voidSale() {
    const reason = window.prompt('Reason for voiding this sale?');
    if (!reason) return;

    setBusyAction('void');
    try {
      await api.post(`/admin/pos/sales/${encodeURIComponent(uuid)}/void`, { reason });
      notify('Sale voided.');
      await load();
    } catch (err) {
      notify(err.message || 'Could not void this sale.', 'danger');
    } finally {
      setBusyAction(null);
    }
  }

  async function refundLine() {
    const skuList = sale.items.map((item, index) => `${index + 1}. ${item.sku} (sold ${Number(item.quantity)}, refunded ${Number(item.refunded_quantity)})`).join('\n');
    const choice = window.prompt(`Which line number to refund?\n${skuList}`);
    const index = Number(choice) - 1;
    if (!sale.items[index]) return;

    const qty = window.prompt('Quantity to refund?', '1');
    if (!qty) return;

    const reason = window.prompt('Reason for the refund?');
    if (!reason) return;

    // Only a sale linked to a registered customer has anywhere to credit a
    // wallet refund to — a walk-in always gets cash/card handed back.
    const refundMethod = sale.customer_id
      && window.confirm('Credit this refund to the customer’s wallet instead of handing it back at the counter?\n\nOK = wallet, Cancel = handed back as usual.')
      ? 'wallet'
      : 'original';

    setBusyAction('refund');
    try {
      await api.post(`/admin/pos/sales/${encodeURIComponent(uuid)}/refund`, {
        reason,
        refund_method: refundMethod,
        items: [{ pos_sale_item_uuid: sale.items[index].uuid, quantity: qty }],
      });
      notify(refundMethod === 'wallet' ? 'Refund credited to the customer’s wallet.' : 'Refund recorded.');
      await load();
    } catch (err) {
      notify(err.message || 'Could not record that refund.', 'danger');
    } finally {
      setBusyAction(null);
    }
  }

  return (
    <div className="till-sale-detail">
      <button type="button" className="till-link-btn" onClick={onBack}>← Sales</button>

      {loading && <LoadingState />}
      {error && <ErrorBanner error={error} />}

      {sale && (
        <>
          <Receipt sale={sale} />

          {sale.status === 'completed' && (
            <div className="till-sale-detail__actions till-no-print">
              {sale.delivery_status === 'pending' ? (
                <>
                  <span className="till-badge till-badge--warning">Not delivered yet</span>
                  <button type="button" className="till-btn till-btn--primary" disabled={Boolean(busyAction)} onClick={markDelivered}>
                    {busyAction === 'deliver' ? 'Saving…' : 'Mark as delivered'}
                  </button>
                </>
              ) : (
                <span className="till-badge till-badge--success">
                  Delivered{sale.delivered_date ? ` ${String(sale.delivered_date).slice(0, 16).replace('T', ' ')}` : ''}
                </span>
              )}
              <button type="button" className="till-btn till-btn--danger-outline" disabled={Boolean(busyAction)} onClick={voidSale}>
                {busyAction === 'void' ? 'Saving…' : 'Void sale'}
              </button>
              <button type="button" className="till-btn till-btn--warning-outline" disabled={Boolean(busyAction)} onClick={refundLine}>
                {busyAction === 'refund' ? 'Saving…' : 'Refund a line'}
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
