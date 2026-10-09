import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { LABEL_LAYOUTS, barcodeSvg, labelCode, printLabels, tooLongForRoll } from '../../lib/barcodeLabels.js';
import { Pagination, reportError } from './InventoryShared';

/**
 * Inventory → Barcodes. Every pack with its SKU and barcode.
 *
 * "Generate Barcode" (POST …/generate-barcode) gives a pack its SKU and
 * barcode in one go and saves them permanently:
 *   - no SKU, no barcode -> a new unique EAN-13 is saved as both;
 *   - SKU only           -> the SKU becomes the barcode;
 *   - barcode only       -> the barcode becomes the SKU.
 * Nothing existing is ever overwritten. Labels show the product name, SKU
 * and a scannable barcode, one at a time or in bulk.
 */

export function needsBarcode(row) {
  return !row.sku || !row.barcode;
}

export function GenerateBarcodeButton({ variantUuid, onDone, className = 'admin-btn admin-btn--primary', label = 'Generate Barcode' }) {
  const [busy, setBusy] = useState(false);
  async function run() {
    setBusy(true);
    try {
      const response = await api.post(`/admin/inventory/variants/${encodeURIComponent(variantUuid)}/generate-barcode`, {});
      const v = response.data.variant;
      toast(`Saved: SKU and barcode ${v.sku} for ${v.product_name} — ${v.variant_name}.`);
      onDone(v);
    } catch (error) {
      toast(reportError(error), 'danger');
      setBusy(false);
    }
  }
  return (
    <button type="button" className={className} onClick={run} disabled={busy}>
      {busy ? 'Generating…' : label}
    </button>
  );
}

export default function InventoryBarcodes() {
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [missingOnly, setMissingOnly] = useState(true);
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, rows: [], meta: null });
  const [selected, setSelected] = useState({}); // uuid -> row
  const [layout, setLayout] = useState(() => {
    try { return localStorage.getItem('inv.labelLayout') === 'sheet' ? 'sheet' : 'roll'; } catch { return 'roll'; }
  });
  const [copies, setCopies] = useState(1);
  const [bulkBusy, setBulkBusy] = useState(false);

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/inventory/barcodes', { q: query, missing: missingOnly ? 1 : 0, page, per_page: 50 });
      setState({ loading: false, error: null, rows: response.data || [], meta: response.meta || null });
    } catch (error) {
      setState({ loading: false, error, rows: [], meta: null });
    }
  }, [query, missingOnly, page]);

  useEffect(() => { load(); }, [load]);

  function chooseLayout(value) {
    setLayout(value);
    try { localStorage.setItem('inv.labelLayout', value); } catch { /* ignore */ }
  }

  // Update one row in place right after its barcode is saved, so the screen
  // shows the new SKU/barcode immediately (it stays visible even under the
  // "missing only" filter until the next refresh).
  function applySaved(v) {
    setState((s) => ({ ...s, rows: s.rows.map((r) => (r.uuid === v.uuid ? { ...r, sku: v.sku, barcode: v.barcode } : r)) }));
    setSelected((sel) => (sel[v.uuid] ? { ...sel, [v.uuid]: { ...sel[v.uuid], sku: v.sku, barcode: v.barcode } } : sel));
  }

  const selectedRows = Object.values(selected);
  const allOnPageSelected = state.rows.length > 0 && state.rows.every((r) => selected[r.uuid]);

  function toggle(row) {
    setSelected((sel) => {
      const next = { ...sel };
      if (next[row.uuid]) delete next[row.uuid]; else next[row.uuid] = row;
      return next;
    });
  }

  function togglePage() {
    setSelected((sel) => {
      const next = { ...sel };
      if (allOnPageSelected) state.rows.forEach((r) => delete next[r.uuid]);
      else state.rows.forEach((r) => { next[r.uuid] = r; });
      return next;
    });
  }

  async function bulkGenerate() {
    const targets = selectedRows.filter(needsBarcode);
    if (targets.length === 0) {
      toast('The selected items already have a SKU and barcode.', 'warning');
      return;
    }
    setBulkBusy(true);
    try {
      const response = await api.post('/admin/inventory/barcodes/generate', { variant_uuids: targets.map((r) => r.uuid) });
      const results = response.data.results || [];
      results.filter((r) => r.ok).forEach((r) => applySaved(r.variant));
      const failed = results.filter((r) => !r.ok);
      toast(`${results.length - failed.length} barcode(s) saved.${failed.length ? ` ${failed.length} could not be done: ${failed[0].error}` : ''}`, failed.length ? 'warning' : 'success');
    } catch (error) {
      toast(reportError(error), 'danger');
    } finally {
      setBulkBusy(false);
    }
  }

  async function print(rows) {
    const printable = rows.filter((r) => labelCode(r));
    const skipped = rows.length - printable.length;
    if (printable.length === 0) {
      toast('Generate a barcode first — these items have no SKU or barcode yet.', 'warning');
      return;
    }
    const tooLong = layout === 'roll' ? printable.filter(tooLongForRoll) : [];
    if (tooLong.length) {
      toast(`${tooLong.length} code(s) like "${labelCode(tooLong[0])}" are too long to scan reliably on a 50 mm label — print those on the A4 sheet.`, 'warning');
    }
    await printLabels(printable, { layout, copies: Math.max(1, Math.min(50, Number(copies) || 1)) });
    if (skipped) toast(`${skipped} item(s) without a barcode were left out.`, 'warning');
  }

  return (
    <div>
      <form className="inv-filter-row" onSubmit={(e) => { e.preventDefault(); setQuery(q.trim()); setPage(1); }}>
        <input className="inv-input" placeholder="Search name, SKU or barcode" value={q} onChange={(e) => setQ(e.target.value)} />
        <label className="inv-check">
          <input type="checkbox" checked={missingOnly} onChange={(e) => { setMissingOnly(e.target.checked); setPage(1); }} />
          {' '}Only items without a SKU/barcode
        </label>
        <button type="submit" className="admin-btn">Search</button>
      </form>

      <div className="inv-barcode-bulk">
        <span className="small-muted">{selectedRows.length} selected</span>
        <button type="button" className="admin-btn admin-btn--primary" disabled={bulkBusy || selectedRows.length === 0} onClick={bulkGenerate}>
          {bulkBusy ? 'Generating…' : 'Generate barcodes for selected'}
        </button>
        <span className="inv-barcode-bulk__sep" />
        <select className="inv-select" value={layout} onChange={(e) => chooseLayout(e.target.value)} aria-label="Label size">
          {Object.entries(LABEL_LAYOUTS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </select>
        <label className="small-muted">
          Copies{' '}
          <input className="inv-input inv-input--xs" type="number" min="1" max="50" value={copies} onChange={(e) => setCopies(e.target.value)} />
        </label>
        <button type="button" className="admin-btn" disabled={selectedRows.length === 0} onClick={() => print(selectedRows)}>
          Print labels for selected
        </button>
        {selectedRows.length > 0 && (
          <button type="button" className="inv-link" onClick={() => setSelected({})}>Clear selection</button>
        )}
      </div>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.rows.length === 0 ? (
        <EmptyState
          title={missingOnly ? 'Every item has a SKU and barcode' : 'No items match'}
          hint={missingOnly ? 'Untick the filter to see all items and reprint labels.' : 'Try a different search.'}
        />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th style={{ width: 32 }}><input type="checkbox" aria-label="Select all on this page" checked={allOnPageSelected} onChange={togglePage} /></th>
                  <th>Product</th><th>SKU</th><th>Barcode</th>
                  <th style={{ textAlign: 'right' }}>Price</th><th></th>
                </tr>
              </thead>
              <tbody>
                {state.rows.map((row) => {
                  const code = labelCode(row);
                  return (
                    <tr key={row.uuid}>
                      <td><input type="checkbox" aria-label={`Select ${row.product_name}`} checked={Boolean(selected[row.uuid])} onChange={() => toggle(row)} /></td>
                      <td>
                        <div style={{ fontWeight: 600 }}>{row.product_name}</div>
                        <div className="small-muted">{row.variant_name}</div>
                      </td>
                      <td className="inv-mono">{row.sku || <span className="status-badge status-badge--warning">No SKU</span>}</td>
                      <td>
                        {row.barcode ? (
                          <div className="inv-barcode-cell">
                            {/* barcodeSvg builds the SVG from the code itself. */}
                            <span className="inv-barcode-svg" dangerouslySetInnerHTML={{ __html: barcodeSvg(code, { height: 30, moduleWidth: 1.4 }) }} />
                            <span className="inv-mono small-muted">{row.barcode}</span>
                          </div>
                        ) : <span className="status-badge status-badge--warning">No barcode</span>}
                      </td>
                      <td style={{ textAlign: 'right' }} className="small-muted">{row.selling_price != null ? formatMoney(row.selling_price) : '—'}</td>
                      <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                        {needsBarcode(row) && <GenerateBarcodeButton variantUuid={row.uuid} onDone={applySaved} />}
                        {' '}
                        <button type="button" className="admin-btn" disabled={!code} onClick={() => print([row])} title={code ? 'Print this label' : 'Generate a barcode first'}>
                          Print label
                        </button>
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
