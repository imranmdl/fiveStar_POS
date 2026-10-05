import { useEffect, useRef, useState } from 'react';
import { api, ApiError, formatMoney } from '../lib/api';
import './LinkBarcode.css';

/**
 * "This barcode isn't on any item — which item is it?"
 *
 * Shown by the Till, Purchase Inward and Mobile Scan when a scanned code
 * matches nothing. Staff search the item by name, pick it, and the scanned
 * code is saved as that pack's barcode (POST /admin/inventory/variants/{uuid}/barcode).
 * From then on the code scans everywhere. Typical case: an item first created
 * with an auto-generated barcode, so the code printed on the pack was never
 * recorded. The pack's SKU is untouched, so labels already printed with the
 * old code keep working too.
 */
export default function LinkBarcode({ code, onLinked, onCancel }) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const inputRef = useRef(null);
  const timer = useRef(null);

  useEffect(() => {
    inputRef.current?.focus();
    return () => clearTimeout(timer.current);
  }, []);

  function onChange(event) {
    const text = event.target.value;
    setQuery(text);
    clearTimeout(timer.current);
    if (text.trim().length < 2) {
      setResults(null);
      return;
    }
    timer.current = setTimeout(async () => {
      try {
        const response = await api.get('/admin/inventory/search', { q: text.trim() });
        setResults(response.data || []);
      } catch {
        setResults([]);
      }
    }, 250);
  }

  async function link(match) {
    setBusy(true);
    setError(null);
    try {
      const response = await api.post(`/admin/inventory/variants/${encodeURIComponent(match.uuid)}/barcode`, { barcode: code });
      onLinked(response.data.variant || match);
    } catch (err) {
      setError(err instanceof ApiError
        ? (err.status === 403 ? 'Only a manager or administrator can link barcodes. Ask one to scan it once on Mobile Scan or Purchase Inward.' : err.message)
        : 'Could not link the barcode.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="link-barcode" role="group" aria-label="Link barcode to an item">
      <div className="link-barcode__head">
        <b>Barcode {code} isn&apos;t on any item yet.</b>
        <span>Which item is it? Search by name — the code is saved to that item and scans everywhere from now on.</span>
      </div>
      <input
        ref={inputRef}
        className="link-barcode__input"
        placeholder="Type the item name…"
        value={query}
        onChange={onChange}
        autoComplete="off"
      />
      {error && <div className="link-barcode__error">{error}</div>}
      {results !== null && (
        <div className="link-barcode__results">
          {results.length === 0 ? (
            <div className="link-barcode__empty">No item matches — create it as a new item instead.</div>
          ) : (
            results.slice(0, 8).map((m) => (
              <button key={m.uuid} type="button" className="link-barcode__result" disabled={busy} onClick={() => link(m)}>
                <span>
                  <b>{m.product_name}</b>
                  <small>{m.variant_name} · {m.barcode || m.sku}</small>
                </span>
                <span>{formatMoney(m.selling_price)}</span>
              </button>
            ))
          )}
        </div>
      )}
      <div className="link-barcode__actions">
        <button type="button" className="link-barcode__cancel" onClick={onCancel} disabled={busy}>Cancel</button>
      </div>
    </div>
  );
}
