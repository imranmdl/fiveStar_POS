import { useEffect } from 'react';
import { formatMoney } from '../../lib/api';

/**
 * The "which offer, if any" prompt a cashier sees when a scanned item has
 * live offers — ported from offerPickerModal() in admin/assets/page-till.js.
 * Resolves to the picked candidate, or null for "no discount" — including
 * when dismissed without a pick, so the item still gets added at full
 * price rather than the scan silently going nowhere. Number keys (1/2/3,
 * 0 for no discount) work as shortcuts, since this runs at a busy till
 * where reaching for the mouse/screen is the slow way.
 *
 * @param {Array<{code:string,title:string,summary:string,discount_amount:number}>} candidates
 * @param {string} itemLabel
 * @param {(picked: object|null) => void} onResolve
 */
export default function OfferPicker({ candidates, itemLabel, onResolve }) {
  useEffect(() => {
    function onKey(event) {
      if (event.key === '0') { onResolve(null); return; }
      const n = Number(event.key);
      if (n >= 1 && n <= candidates.length) onResolve(candidates[n - 1]);
    }

    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [candidates, onResolve]);

  return (
    <div className="till-overlay" role="dialog" aria-modal="true">
      <div className="till-sheet">
        <div className="till-sheet__header">
          <h2>Offer available — {itemLabel}</h2>
          <button type="button" className="till-icon-btn" aria-label="No discount" onClick={() => onResolve(null)}>×</button>
        </div>

        <div className="till-sheet__body">
          <div className="till-offer-list">
            {candidates.map((candidate, index) => (
              <button
                type="button"
                key={candidate.code || index}
                className="till-offer-choice"
                onClick={() => onResolve(candidate)}
              >
                <span><b>{index + 1}.</b> {candidate.title} — {candidate.summary}</span>
                <span className="till-offer-choice__badge">-{formatMoney(candidate.discount_amount)}</span>
              </button>
            ))}
            <button type="button" className="till-offer-choice till-offer-choice--none" onClick={() => onResolve(null)}>
              <b>0.</b> No discount
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
