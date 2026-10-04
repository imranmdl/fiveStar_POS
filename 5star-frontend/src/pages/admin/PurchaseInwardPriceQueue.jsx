import { useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast.js';

/**
 * The §7 price-change prompt — ported from admin/assets/pricing-decisions.js
 * (resolvePriceDecisionQueue). Shown one flagged line at a time after saving
 * a purchase order whose cost change raises a pricing question for a human:
 * keep the old selling price, move to one of the suggested prices, or set
 * one manually. "Skip for now" just moves on — nothing is lost, the old
 * price simply stands.
 *
 * Same mechanics are used by Mobile Scan (ported separately, same shape of
 * props and the same POST body) and read by the Pricing page's own decision
 * queue — this component is intentionally self-contained per page rather
 * than shared, matching how Mobile Scan already ported it.
 */
export default function PurchaseInwardPriceQueue({ pending, purchaseOrderId, onDone }) {
  const [index, setIndex] = useState(0);
  const [manualPrice, setManualPrice] = useState('');
  const [busy, setBusy] = useState(false);

  const item = pending[index];
  if (!item) return null;

  function advance() {
    setManualPrice('');
    if (index + 1 >= pending.length) onDone();
    else setIndex(index + 1);
  }

  async function decide(decision) {
    if (decision === 'manual' && !manualPrice) {
      toast('Enter a price first.', 'danger');
      return;
    }

    setBusy(true);

    try {
      await api.post('/admin/pricing/decisions', {
        variant_uuid: item.variant_uuid,
        decision,
        manual_price: decision === 'manual' ? manualPrice : null,
        purchase_price: item.purchase_price,
        average_cost: item.average_cost,
        reference_type: 'purchase_order',
        reference_id: purchaseOrderId,
        reason: `Purchase inward decision (${decision})`,
      });
      toast('Selling price updated.');
      setBusy(false);
      advance();
    } catch (error) {
      setBusy(false);
      toast(error instanceof ApiError ? error.message : 'Could not save that decision.', 'danger');
    }
  }

  return (
    <div className="pi-overlay">
      <div className="pi-sheet">
        <h2 className="pi-sheet__title">Selling price for {item.sku}</h2>

        <dl className="pi-sheet__facts">
          <div><dt>Current selling price</dt><dd>{formatMoney(item.current_price)}</dd></div>
          <div><dt>This purchase&rsquo;s cost</dt><dd>{formatMoney(item.purchase_price)}</dd></div>
          <div><dt>Resulting average cost</dt><dd>{formatMoney(item.average_cost)}</dd></div>
        </dl>

        <div className="pi-sheet__choices">
          <button type="button" className="admin-btn pi-choice" disabled={busy} onClick={() => decide('keep_old')}>
            Keep the current price — {formatMoney(item.current_price)}
          </button>

          {item.suggested_use_average !== null && item.suggested_use_average !== undefined && (
            <button type="button" className="admin-btn pi-choice" disabled={busy} onClick={() => decide('use_average')}>
              Use the average-cost price — {formatMoney(item.suggested_use_average)}
            </button>
          )}

          {item.suggested_use_new !== null && item.suggested_use_new !== undefined && (
            <button type="button" className="admin-btn pi-choice" disabled={busy} onClick={() => decide('use_new')}>
              Use the new-cost price — {formatMoney(item.suggested_use_new)}
            </button>
          )}

          <div className="pi-manual-row">
            <input
              className="admin-input"
              type="number"
              step="0.01"
              min="0.01"
              inputMode="decimal"
              placeholder="Set manually"
              value={manualPrice}
              onChange={(event) => setManualPrice(event.target.value)}
            />
            <button type="button" className="admin-btn admin-btn--primary" disabled={busy} onClick={() => decide('manual')}>
              Use this
            </button>
          </div>
        </div>

        <div className="pi-sheet__footer">
          <button type="button" className="admin-btn" disabled={busy} onClick={advance}>Skip for now</button>
          <span className="pi-sheet__progress">{index + 1} of {pending.length}</span>
        </div>
      </div>
    </div>
  );
}
