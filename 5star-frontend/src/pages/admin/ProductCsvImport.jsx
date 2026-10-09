import { useState } from 'react';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import './ProductCsvImport.css';

/**
 * Bulk product import, ported from admin/assets/inward-csv.js in "products"
 * mode only (the same widget also drives Purchase Inward's vendor-bill
 * upload in "purchase" mode — that half belongs to the Purchase Inward page,
 * ported separately, so it is intentionally left out here).
 *
 * Upload an Excel/CSV/ZIP -> the server works out what each row is (existing
 * item, skipped, or a new one to create) and matches categories. This screen
 * shows the result for review: every field can be corrected in place, and
 * rows with problems are held back until fixed. "Create ready items" then
 * creates the new products (with their first variant) in one batch.
 */

/** Products sent per request while importing; the whole file is still imported. */
const BATCH_SIZE = 25;
/** Rows shown per page in the review table (all rows are imported). */
const PAGE_SIZE = 100;

const GST_BY_TYPE = { grocery: 5, oils: 5, clothing: 12, footwear: 12, toys: 12, stationery: 12, general: 18 };

export default function ProductCsvImport({ setup, onClose, onDone }) {
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState(null);
  const [imageToken, setImageToken] = useState(null);
  const [feedback, setFeedback] = useState(null);
  const [reading, setReading] = useState(false);
  const [adding, setAdding] = useState(false);
  const [file, setFile] = useState(null);
  const [progress, setProgress] = useState(null); // { total, processed, created, skipped, failed }
  const [result, setResult] = useState(null);     // final summary after a run
  const [page, setPage] = useState(0);

  const cats = () => setup.categories || [];
  const byUuid = (u) => cats().find((c) => c.uuid === u);
  const tops = () => cats().filter((c) => !c.parent_uuid);
  const kids = (u) => cats().filter((c) => c.parent_uuid === u);
  const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

  function typeOf(row) {
    if (row.kind === 'existing') return row.item_type || 'general';
    const leaf = byUuid(row.category_uuid);
    return leaf ? leaf.item_type || 'general' : 'general';
  }

  function revalidate(row) {
    const errors = [];
    const type = typeOf(row);

    if (row.kind === 'new') {
      if (!row.category_uuid) errors.push('Choose a category and sub-category.');
      if (!String(row.product_name || '').trim()) errors.push('Product name is required.');
      if (['grocery', 'oils'].includes(type) && !row.loose && !(num(row.weight_grams) > 0)) {
        errors.push('Weight (g) is required for this item.');
      }
      if (!(num(row.selling_price) > 0) || !(num(row.mrp) > 0)) errors.push('Enter the MRP and the selling price.');
    }

    if (num(row.selling_price) > num(row.mrp)) errors.push('Selling price is above the MRP.');
    (row.serverErrors || []).filter((e) => e.startsWith('Duplicate')).forEach((e) => errors.push(e));

    row.errors = errors;
    return row;
  }

  const usable = (r) => r.include && r.errors.length === 0;
  /** A row held back only because it repeats another row / an existing item. */
  const isDuplicateOnly = (r) => r.errors.length > 0 && r.errors.every((e) => String(e).startsWith('Duplicate'));

  async function handleUpload() {
    if (!file) {
      setFeedback({ tone: 'danger', text: 'Choose a file first.' });
      return;
    }

    setReading(true);
    setFeedback(null);

    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('mode', 'products');
      const response = await api.upload('/admin/purchase-orders/items/parse-bill', formData);
      setImageToken(response.data.image_token || null);

      const nextRows = (response.data.rows || []).map((r) =>
        revalidate({ ...r, include: r.status !== 'error' && r.kind !== 'existing', serverErrors: r.errors }),
      );
      setRows(nextRows);
      setSummary(response.data.summary);
      setResult(null);
      setPage(0);

      if (response.data.image_token) {
        setFeedback({ tone: 'muted', text: `${response.data.images_found} photo(s) found in the ZIP.` });
      }
    } catch (error) {
      setFeedback({ tone: 'danger', text: error.message || 'Could not read that file.' });
    } finally {
      setReading(false);
    }
  }

  function updateRow(index, patch) {
    setRows((prev) => {
      const next = [...prev];
      const row = { ...next[index], ...patch };
      next[index] = revalidate(row);
      if (next[index].errors.length === 0 && !next[index].include) next[index].include = true;
      return next;
    });
  }

  function onCategoryTopChange(index, uuid) {
    setRows((prev) => {
      const next = [...prev];
      const row = { ...next[index] };

      if (uuid && kids(uuid).length) {
        row.category_uuid = null;
        row.category_pending_top = uuid;
      } else {
        row.category_uuid = uuid || null;
        row.category_pending_top = null;
      }

      const leaf = byUuid(row.category_uuid);
      if (leaf && !row.gst_edited) row.gst_rate = GST_BY_TYPE[leaf.item_type || 'general'];

      next[index] = revalidate(row);
      if (next[index].errors.length === 0 && !next[index].include) next[index].include = true;
      return next;
    });
  }

  function onCategorySubChange(index, uuid) {
    setRows((prev) => {
      const next = [...prev];
      const row = { ...next[index], category_uuid: uuid || null };
      const leaf = byUuid(row.category_uuid);
      if (leaf && !row.gst_edited) row.gst_rate = GST_BY_TYPE[leaf.item_type || 'general'];
      next[index] = revalidate(row);
      if (next[index].errors.length === 0 && !next[index].include) next[index].include = true;
      return next;
    });
  }

  /** One product (with all its pack sizes) as sent to quick-create-batch. */
  function buildItem(g) {
    return {
      category_uuid: g[0].category_uuid,
      product_name: g[0].product_name.trim(),
      brand: g[0].brand || undefined,
      hsn_code: g[0].hsn_code || undefined,
      gst_rate: num(g[0].gst_rate) ?? undefined,
      publish: g.some((r) => r.publish),
      short_description: (g.find((r) => r.short_description) || {}).short_description,
      image: (g.find((r) => r.image_ok) || {}).image,
      image_token: g.some((r) => r.image_ok) ? imageToken : undefined,
      variants: g.map((r) => {
        const v = {
          variant_name: r.variant_name || 'Standard',
          mrp: num(r.mrp),
          selling_price: num(r.selling_price),
          options: {},
        };
        if (r.sku) v.sku = r.sku;
        if (r.barcode) v.barcode = r.barcode;
        if (num(r.weight_grams) > 0) v.weight_grams = Number(r.weight_grams);
        if (r.pack_type) v.pack_type = r.pack_type;
        if (r.loose) {
          v.stock_unit_type = 'weight';
          v.unit_label = r.unit || 'kg';
          v.pack_type = 'other';
        }
        if (r.size) v.options.size = r.size;
        if (r.colour) v.options.color = r.colour;
        if (!Object.keys(v.options).length) delete v.options;
        return v;
      }),
    };
  }

  /**
   * Creates every ready row, BATCH_SIZE products per request, one batch after
   * another. Each product is saved in its own transaction on the server, so a
   * bad row (or a failed batch) never leaves half a product behind and never
   * stops the rest of the file. Progress is shown as it goes; the final
   * summary and the failed-rows download cover the whole file.
   */
  async function addAll() {
    const readyRows = rows.filter(usable);
    if (!readyRows.length) {
      toast('Nothing ready to add — fix the rows marked with an error.', 'warning');
      return;
    }

    setAdding(true);
    setFeedback(null);

    const groups = new Map();
    readyRows.forEach((r) => {
      const key = `${r.category_uuid}|${r.product_name.trim().toLowerCase()}|${(r.brand || '').toLowerCase()}`;
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(r);
    });
    const list = [...groups.values()];

    const outcome = new Map(); // row -> { state: 'created'|'skipped'|'failed', reason }
    const counts = { total: readyRows.length, processed: 0, created: 0, skipped: 0, failed: 0 };
    const notes = new Set();
    let published = 0;
    let photos = 0;
    setProgress({ ...counts });

    for (let start = 0; start < list.length; start += BATCH_SIZE) {
      const batch = list.slice(start, start + BATCH_SIZE);
      let results;
      try {
        const response = await api.post('/admin/inventory/quick-create-batch', { items: batch.map(buildItem) });
        results = response.data.results || [];
      } catch (error) {
        // The whole request failed (network, timeout): mark just this
        // batch's rows failed and carry on with the next batch.
        results = batch.map(() => ({ ok: false, error: `Not saved — ${error.message || 'the request failed'}. Try these rows again.` }));
      }

      results.forEach((res, gi) => {
        const groupRows = batch[gi] || [];
        groupRows.forEach((r) => {
          if (res.ok) {
            outcome.set(r, { state: 'created' });
            counts.created += 1;
          } else if (res.skipped) {
            outcome.set(r, { state: 'skipped', reason: res.error });
            counts.skipped += 1;
          } else {
            outcome.set(r, { state: 'failed', reason: res.error || 'Could not be created.' });
            counts.failed += 1;
          }
          counts.processed += 1;
        });
        if (res.ok && res.published) published += 1;
        if (res.ok && res.image_attached) photos += 1;
        if (res.publish_note) notes.add(res.publish_note);
      });
      setProgress({ ...counts });
    }

    // Created rows leave the table; skipped/failed stay with their reason.
    const remaining = rows.filter((r) => {
      const o = outcome.get(r);
      if (!o) return true;
      if (o.state === 'created') return false;
      r.serverErrors = [o.reason];
      r.errors = [o.reason];
      r.include = false;
      r.result = o.state;
      return true;
    });
    setRows(remaining);

    const notAttempted = rows.filter((r) => !outcome.has(r));
    const existing = notAttempted.filter((r) => r.kind === 'existing').length;
    const inFileDuplicates = notAttempted.filter((r) => r.kind !== 'existing' && isDuplicateOnly(r)).length;
    const invalid = notAttempted.filter((r) => r.kind !== 'existing' && r.errors.length && !isDuplicateOnly(r)).length;
    const finalSummary = {
      fileRows: rows.length,
      created: counts.created,
      skipped: counts.skipped + existing + inFileDuplicates,
      skippedExisting: existing,
      skippedDuplicates: counts.skipped + inFileDuplicates,
      failed: counts.failed + invalid,
      failedValidation: invalid,
      failedCreate: counts.failed,
      products: list.filter((g) => g.every((r) => outcome.get(r)?.state === 'created')).length,
      published,
      photos,
      notes: [...notes],
    };
    setResult(finalSummary);
    setProgress(null);
    setAdding(false);
    toast(`${counts.created} row(s) imported.`);
    if (onDone && counts.created) onDone();
  }

  /** Every row that wasn't imported, with the reason — re-importable after fixing. */
  function downloadProblemRows() {
    const problem = rows.filter((r) => r.errors.length || r.kind === 'existing' || r.result);
    const header = ['Row', 'Status', 'Reason', 'Category', 'Sub Category', 'Product Name', 'Variant', 'SKU', 'Barcode',
      'Weight (g)', 'MRP', 'Selling Price', 'GST %', 'Brand', 'HSN Code'];
    const cell = (v) => {
      const t = String(v ?? '');
      return /[",\n\r]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
    };
    const lines = problem.map((r) => {
      const leaf = byUuid(r.category_uuid);
      const parent = leaf && leaf.parent_uuid ? byUuid(leaf.parent_uuid) : null;
      const status = r.kind === 'existing' ? 'skipped (already exists)'
        : (r.result === 'skipped' || isDuplicateOnly(r)) ? 'skipped (duplicate)' : 'failed';
      const reason = r.kind === 'existing' ? 'Already in your products — not changed.' : (r.errors || []).join(' ');
      return [r.row_number, status, reason,
        parent ? parent.name : (leaf ? leaf.name : r.category_label || ''), parent && leaf ? leaf.name : '',
        r.product_name, r.variant_name, r.sku, r.barcode, r.weight_grams, r.mrp, r.selling_price, r.gst_rate, r.brand, r.hsn_code,
      ].map(cell).join(',');
    });
    const blob = new Blob(['﻿' + [header.join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `import-problems-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  const ready = rows.filter(usable);
  const errorCount = rows.filter((r) => r.errors.length).length;
  const withPhoto = ready.filter((r) => r.image_ok).length;
  const existingCount = rows.filter((r) => r.kind === 'existing').length;

  return (
    <div className="csv-import">
      <div className="csv-import__head">
        <h3 className="h6">Import products (Excel, CSV or ZIP with photos)</h3>
        <button className="admin-btn" type="button" onClick={onClose}>Close</button>
      </div>

      <p className="text-muted small">
        One row per pack size. Give a <b>Category, Sub Category, Product Name</b> and a <b>Selling Price</b> (or MRP, or
        Markup % with a Unit Cost). Put the photo file name in the <b>Image</b> column and upload everything as one{' '}
        <b>ZIP</b> (the Excel plus the pictures). Quantity and cost are not needed here — stock comes in through Purchase
        Inward. You review and fix everything on screen before anything is saved.
      </p>

      <div className="csv-import__toolbar">
        <input
          type="file"
          accept=".csv,.xlsx,.zip"
          onChange={(e) => setFile(e.target.files[0] || null)}
        />
        <button className="admin-btn admin-btn--primary" type="button" disabled={reading} onClick={handleUpload}>
          {reading ? 'Reading…' : 'Review file'}
        </button>
      </div>

      {feedback && (
        <div className={`csv-import__feedback csv-import__feedback--${feedback.tone}`}>
          {feedback.text}
          {feedback.notes && feedback.notes.map((n, i) => <div key={i}>{n}</div>)}
        </div>
      )}

      {rows.length === 0 ? null : (
        <div className="mt-3">
          <div className="csv-import__totals">
            <span><b>{ready.length}</b> of {rows.length} rows ready</span>
            <span><b>{withPhoto}</b> with a photo</span>
            {errorCount > 0 && <span className="text-danger"><b>{errorCount}</b> need fixing</span>}
            <span>{existingCount} already exist (skipped)</span>
          </div>

          {progress && (
            <div className="csv-import__progress" role="status" aria-live="polite">
              <div className="csv-import__bar"><span style={{ width: `${Math.round((progress.processed / Math.max(1, progress.total)) * 100)}%` }} /></div>
              <div className="small">
                Importing… <b>{progress.processed}</b> of {progress.total} rows processed ·{' '}
                <span className="text-success">{progress.created} created</span> ·{' '}
                <span>{progress.skipped} skipped</span> ·{' '}
                <span className="text-danger">{progress.failed} failed</span>
              </div>
            </div>
          )}

          {result && (
            <div className={`csv-import__summary ${result.failed ? 'csv-import__summary--warn' : ''}`}>
              <div className="csv-import__summary-title">Import finished — {result.fileRows} rows in the file</div>
              <div className="csv-import__summary-grid">
                <div><b className="text-success">{result.created}</b><span>imported ({result.products} products)</span></div>
                <div><b>{result.skipped}</b><span>skipped ({result.skippedExisting} already existed, {result.skippedDuplicates} duplicates)</span></div>
                <div><b className="text-danger">{result.failed}</b><span>failed ({result.failedValidation} need fixing, {result.failedCreate} not saved)</span></div>
                <div><b>{result.photos}</b><span>with photos · {result.published} published</span></div>
              </div>
              {result.notes.map((n, k) => <div key={k} className="small text-muted">{n}</div>)}
              {(result.skipped + result.failed) > 0 && (
                <div className="mt-2">
                  <button type="button" className="admin-btn" onClick={downloadProblemRows}>Download skipped &amp; failed rows (CSV)</button>
                  <span className="small text-muted"> — with the reason for each row; fix and import that file again.</span>
                </div>
              )}
            </div>
          )}

          <div className="csv-import__table-wrap">
            <table className="admin-table">
              <thead>
                <tr>
                  <th></th>
                  <th>Row</th>
                  <th>Category</th>
                  <th>Item</th>
                  <th>GST %</th>
                  <th>MRP</th>
                  <th>Selling</th>
                  <th>Photo</th>
                </tr>
              </thead>
              <tbody>
                {rows.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE).map((row, pi) => {
                  const i = page * PAGE_SIZE + pi;
                  const type = typeOf(row);
                  const needsWeight = row.kind === 'new' && ['grocery', 'oils'].includes(type) && !row.loose;
                  const leaf = byUuid(row.category_uuid);
                  const top = leaf ? (leaf.parent_uuid ? byUuid(leaf.parent_uuid) : leaf) : byUuid(row.category_pending_top);
                  const subs = top ? kids(top.uuid) : [];
                  const bad = row.errors.length > 0;

                  return (
                    <tr key={i} className={bad ? 'csv-row--bad' : ''}>
                      <td>
                        <input
                          type="checkbox"
                          checked={row.include}
                          disabled={bad}
                          onChange={(e) => updateRow(i, { include: e.target.checked })}
                        />
                      </td>
                      <td className="small text-nowrap">
                        {row.row_number}
                        <div>
                          {row.kind === 'existing' ? (
                            <span className="csv-badge csv-badge--secondary">Existing</span>
                          ) : (
                            <span className="csv-badge csv-badge--info">New item</span>
                          )}
                        </div>
                      </td>
                      <td>
                        {row.kind === 'existing' ? (
                          <span className="text-muted small">existing item</span>
                        ) : (
                          <>
                            <select
                              className="admin-input mb-1"
                              style={{ minWidth: '9rem' }}
                              value={top ? top.uuid : ''}
                              onChange={(e) => onCategoryTopChange(i, e.target.value)}
                            >
                              <option value="">Category…</option>
                              {tops().map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
                            </select>
                            {subs.length > 0 && (
                              <select
                                className="admin-input"
                                style={{ minWidth: '9rem' }}
                                value={leaf ? leaf.uuid : ''}
                                onChange={(e) => onCategorySubChange(i, e.target.value)}
                              >
                                <option value="">Sub-category…</option>
                                {subs.map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
                              </select>
                            )}
                          </>
                        )}
                      </td>
                      <td style={{ minWidth: '11rem' }}>
                        {row.kind === 'existing' ? (
                          <>
                            <span className="fw-semibold">{row.product_name}</span>
                            <div className="small text-muted">{row.variant_name} · {row.sku}</div>
                          </>
                        ) : (
                          <>
                            <input
                              className="admin-input mb-1"
                              placeholder="Product name"
                              value={row.product_name || ''}
                              onChange={(e) => updateRow(i, { product_name: e.target.value })}
                            />
                            <input
                              className="admin-input"
                              placeholder="Variant / pack"
                              value={row.variant_name || ''}
                              onChange={(e) => updateRow(i, { variant_name: e.target.value })}
                            />
                            {needsWeight && (
                              <input
                                className="admin-input mt-1"
                                placeholder="Weight (g)"
                                value={row.weight_grams ?? ''}
                                onChange={(e) => updateRow(i, { weight_grams: e.target.value === '' ? null : Number(e.target.value) })}
                              />
                            )}
                          </>
                        )}
                      </td>
                      <td>
                        <input
                          className="admin-input"
                          style={{ width: '4.5rem' }}
                          type="number"
                          step="0.01"
                          min="0"
                          max="28"
                          value={row.gst_rate ?? ''}
                          onChange={(e) => updateRow(i, { gst_rate: e.target.value, gst_edited: true })}
                        />
                      </td>
                      <td>
                        <input
                          className="admin-input"
                          style={{ width: '5.5rem' }}
                          type="number"
                          step="0.01"
                          min="0"
                          value={row.mrp ?? ''}
                          onChange={(e) => updateRow(i, { mrp: e.target.value })}
                        />
                      </td>
                      <td>
                        <input
                          className="admin-input"
                          style={{ width: '5.5rem' }}
                          type="number"
                          step="0.01"
                          min="0"
                          value={row.selling_price ?? ''}
                          onChange={(e) => updateRow(i, { selling_price: e.target.value })}
                        />
                      </td>
                      <td className="small text-nowrap">
                        {row.image ? (
                          row.image_ok ? (
                            <span className="text-success">📷 {row.image}</span>
                          ) : (
                            <span className="text-warning">📷 missing</span>
                          )
                        ) : (
                          <span className="text-muted">no photo</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {rows.length > PAGE_SIZE && (
            <div className="csv-import__pager small">
              <button type="button" className="admin-btn" disabled={page === 0} onClick={() => setPage((p) => p - 1)}>Previous</button>
              <span>Rows {page * PAGE_SIZE + 1}–{Math.min(rows.length, (page + 1) * PAGE_SIZE)} of {rows.length}</span>
              <button type="button" className="admin-btn" disabled={(page + 1) * PAGE_SIZE >= rows.length} onClick={() => setPage((p) => p + 1)}>Next</button>
            </div>
          )}

          <div className="csv-import__actions">
            <button className="admin-btn admin-btn--primary" type="button" disabled={adding} onClick={addAll}>
              {adding ? 'Importing…' : `Import ${ready.length} ready row${ready.length === 1 ? '' : 's'}`}
            </button>
            <button className="admin-btn" type="button" disabled={adding} onClick={() => { setRows([]); setFeedback(null); setResult(null); setPage(0); }}>
              Clear
            </button>
            <span className="small text-muted">
              Rows with an error are skipped until fixed. Items are created as products; receive stock through Purchase Inward.
            </span>
          </div>
        </div>
      )}
    </div>
  );
}
