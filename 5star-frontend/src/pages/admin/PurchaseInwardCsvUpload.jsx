import { Fragment, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast.js';

/**
 * Vendor bill upload for Purchase Inward — ported from
 * admin/assets/inward-csv.js (renderCsvUpload) in its "purchase" mode. The
 * same server endpoint and widget also drives the Products page's bulk
 * import in "products" mode (ProductCsvImport.jsx, ported separately) —
 * that half is intentionally left out here.
 *
 * Upload a CSV/XLSX -> the server works out what each row is (existing item
 * or a new one to create), matches categories, and does the arithmetic
 * (discount, GST, line total, weight from "500 g", price from markup ...).
 * This screen shows the result for review: every field can be corrected in
 * place, totals update as you type, and rows with problems are held back
 * until fixed. "Add to purchase" then creates the new items (with their
 * variants) and drops every line into the purchase order's items table.
 */

const GST_BY_TYPE = { grocery: 5, oils: 5, clothing: 12, footwear: 12, toys: 12, stationery: 12, general: 18 };

const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
const round2 = (n) => Math.round(n * 100) / 100;

export default function PurchaseInwardCsvUpload({ setup, onAddLine, onClose }) {
  const [rows, setRows] = useState([]);
  const [imageToken, setImageToken] = useState(null);
  const [feedback, setFeedback] = useState(null);
  const [reading, setReading] = useState(false);
  const [adding, setAdding] = useState(false);
  const [file, setFile] = useState(null);
  const [sampleRows, setSampleRows] = useState('30');
  const [sampleErrors, setSampleErrors] = useState(false);
  const [downloading, setDownloading] = useState(false);

  const cats = () => setup.categories || [];
  const byUuid = (u) => cats().find((c) => c.uuid === u);
  const tops = () => cats().filter((c) => !c.parent_uuid);
  const kids = (u) => cats().filter((c) => c.parent_uuid === u);

  function typeOf(row) {
    if (row.kind === 'existing') return row.item_type || 'general';
    const leaf = byUuid(row.category_uuid);
    return leaf ? leaf.item_type || 'general' : 'general';
  }

  function recalc(row) {
    const qty = num(row.quantity) || 0;
    const cost = num(row.unit_cost) || 0;
    const disc = num(row.discount_percent) || 0;
    const discount_amount = round2((qty * cost * disc) / 100);
    const line_total = round2(qty * cost - discount_amount);
    const net_unit_cost = cost * (1 - disc / 100);
    const gst_amount = round2((line_total * (num(row.gst_rate) || 0)) / 100);
    return { ...row, discount_amount, line_total, net_unit_cost, gst_amount };
  }

  function revalidate(row) {
    row = recalc(row);
    const errors = [];
    const warnings = [];
    const type = typeOf(row);
    const today = new Date().toISOString().slice(0, 10);

    if (!(num(row.quantity) > 0)) errors.push('Quantity must be above 0.');
    if (!(num(row.unit_cost) > 0)) errors.push('Unit cost must be above 0.');
    if ((num(row.discount_percent) || 0) < 0 || (num(row.discount_percent) || 0) > 100) errors.push('Discount % must be 0–100.');
    if (row.expiry_date && row.expiry_date < today) errors.push('Expiry date is in the past.');

    if (row.kind === 'new') {
      if (!row.category_uuid) errors.push('Choose a category and sub-category.');
      if (!String(row.product_name || '').trim()) errors.push('Product name is required.');
      if (['grocery', 'oils'].includes(type) && !row.loose && !(num(row.weight_grams) > 0)) errors.push('Weight (g) is required for this item.');
      if (!(num(row.selling_price) > 0) || !(num(row.mrp) > 0)) errors.push('Enter the MRP and the selling price.');
    }

    if (num(row.selling_price) > num(row.mrp)) errors.push('Selling price is above the MRP.');

    let margin_percent;
    if (num(row.selling_price) > 0 && row.net_unit_cost > 0) {
      const margin = ((num(row.selling_price) - row.net_unit_cost) / num(row.selling_price)) * 100;
      margin_percent = Math.round(margin * 10) / 10;
      if (num(row.selling_price) < row.net_unit_cost) warnings.push('Selling below cost — a loss on every sale.');
      else if (margin < 5) warnings.push('Margin is under 5%.');
    }

    (row.serverErrors || []).filter((e) => e.startsWith('Duplicate')).forEach((e) => errors.push(e));

    return { ...row, errors, warnings, margin_percent };
  }

  const usable = (r) => r.include && r.errors.length === 0;

  async function handleUpload() {
    if (!file) { setFeedback({ tone: 'danger', text: 'Choose a file first.' }); return; }

    setReading(true);
    setFeedback(null);

    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('mode', 'purchase');
      const response = await api.upload('/admin/purchase-orders/items/parse-bill', formData);
      setImageToken(response.data.image_token || null);

      const nextRows = (response.data.rows || []).map((r) =>
        revalidate({ ...r, include: r.status !== 'error', serverErrors: r.errors }),
      );
      setRows(nextRows);

      if (response.data.image_token) {
        setFeedback({ tone: 'muted', text: `${response.data.images_found} photo(s) found in the ZIP.` });
      }
    } catch (error) {
      setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not read that file.' });
    } finally {
      setReading(false);
    }
  }

  async function download(path, params, fallbackName) {
    setDownloading(true);
    try {
      const query = params ? `?${new URLSearchParams(params).toString()}` : '';
      const blob = await api.downloadFile(path + query);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = fallbackName;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not download that file.' });
    } finally {
      setDownloading(false);
    }
  }

  function updateRow(index, patch) {
    setRows((prev) => {
      const next = [...prev];
      const row = revalidate({ ...next[index], ...patch });
      if (row.errors.length === 0 && !row.include) row.include = true;
      next[index] = row;
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

      const revalidated = revalidate(row);
      if (revalidated.errors.length === 0 && !revalidated.include) revalidated.include = true;
      next[index] = revalidated;
      return next;
    });
  }

  function onCategorySubChange(index, uuid) {
    setRows((prev) => {
      const next = [...prev];
      const row = { ...next[index], category_uuid: uuid || null };
      const leaf = byUuid(row.category_uuid);
      if (leaf && !row.gst_edited) row.gst_rate = GST_BY_TYPE[leaf.item_type || 'general'];

      const revalidated = revalidate(row);
      if (revalidated.errors.length === 0 && !revalidated.include) revalidated.include = true;
      next[index] = revalidated;
      return next;
    });
  }

  async function addAll() {
    const ready = rows.filter(usable);
    if (!ready.length) { toast('Nothing ready to add — fix the rows marked with an error.', 'warning'); return; }

    setAdding(true);

    try {
      const groups = new Map();
      ready.filter((r) => r.kind === 'new').forEach((r) => {
        const key = `${r.category_uuid}|${r.product_name.trim().toLowerCase()}|${(r.brand || '').toLowerCase()}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(r);
      });

      const created = new Map();
      const failed = new Map();

      if (groups.size) {
        const list = [...groups.values()];
        const items = list.map((g) => ({
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
            if (r.loose) { v.stock_unit_type = 'weight'; v.unit_label = r.unit || 'kg'; v.pack_type = 'other'; }
            if (r.size) v.options.size = r.size;
            if (r.colour) v.options.color = r.colour;
            if (!Object.keys(v.options).length) delete v.options;
            return v;
          }),
        }));

        const response = await api.post('/admin/inventory/quick-create-batch', { items });
        const results = response.data.results || [];

        results.forEach((res, gi) => {
          list[gi].forEach((r, vi) => {
            if (res.ok) created.set(r, res.variants[vi]);
            else failed.set(r, res.error);
          });
        });
      }

      let added = 0;
      let already = 0;

      ready.forEach((r) => {
        if (failed.has(r)) return;
        const v = r.kind === 'new' ? created.get(r) : null;

        const ok = onAddLine({
          variant_uuid: v ? v.uuid : r.variant_uuid,
          sku: v ? v.sku : r.sku,
          barcode: (v ? v.barcode : r.barcode) || null,
          product_name: r.product_name,
          variant_name: v ? v.variant_name : r.variant_name,
          quantity: String(r.quantity),
          invoiced_quantity: '',
          unit_cost: String(r.unit_cost),
          batch_no: r.batch_no || '',
          expiry_date: r.expiry_date || '',
          mrp: String(r.mrp ?? ''),
          selling_price: String(r.selling_price ?? ''),
          gst_rate: String(r.gst_rate ?? ''),
          discount_amount: r.discount_amount ? String(r.discount_amount) : '',
          is_new: r.kind === 'new',
        });

        if (ok) added += 1; else already += 1;
        r.done = true;
      });

      ready.forEach((r) => { if (failed.has(r)) { r.serverErrors = [failed.get(r)]; r.errors = [failed.get(r)]; r.include = false; } });

      const newCount = created.size;
      setRows((prev) => prev.filter((r) => !r.done));
      setFeedback({
        tone: failed.size ? 'warning' : 'success',
        text: `${added} line(s) added to the purchase${newCount ? ` — ${newCount} new item(s) created` : ''}${already ? `, ${already} already on this order` : ''}.${failed.size ? ` ${failed.size} row(s) could not be created — see below.` : ''}`,
      });
      toast(`${added} line(s) added.`);
    } catch (error) {
      setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not add these rows.' });
    } finally {
      setAdding(false);
    }
  }

  const ready = rows.filter(usable);
  const errorCount = rows.filter((r) => r.errors.length).length;
  const warnCount = rows.filter((r) => r.warnings.length && !r.errors.length).length;
  const taxable = ready.reduce((s, r) => s + r.line_total, 0);
  const gst = ready.reduce((s, r) => s + r.gst_amount, 0);
  const disc = ready.reduce((s, r) => s + r.discount_amount, 0);

  return (
    <div className="pi-card pi-card--accent">
      <div className="pi-card__body">
        <div className="pi-row-between">
          <h3 className="pi-h6">Upload a vendor bill (CSV, Excel or ZIP with photos)</h3>
          <button type="button" className="admin-btn" onClick={onClose}>Close</button>
        </div>

        <p className="pi-muted pi-small">
          One row per item. Only <b>Quantity</b> and <b>Unit Cost</b> are compulsory, plus either a <b>SKU</b> (existing
          item) or a <b>Category, Product Name</b> and a price (new item). Leave out anything you want worked out for
          you: weight from the pack size, GST by type, selling price from <b>Markup %</b>, MRP, line totals and
          discounts. To attach photos, put the file name in the <b>Image</b> column and upload a ZIP holding the Excel
          and the pictures. You review and fix everything on screen before anything is saved.
        </p>

        <div className="pi-toolbar">
          <button type="button" className="admin-btn" disabled={downloading} onClick={() => download('/admin/purchase-orders/items/bill-template', undefined, 'purchase-bill-template.csv')}>
            Download template
          </button>
          <span className="pi-small pi-muted">or test data:</span>
          <select value={sampleRows} onChange={(e) => setSampleRows(e.target.value)}>
            <option value="15">15 rows</option>
            <option value="30">30 rows</option>
            <option value="60">60 rows</option>
            <option value="100">100 rows</option>
          </select>
          <label className="pi-checkbox-inline">
            <input type="checkbox" checked={sampleErrors} onChange={(e) => setSampleErrors(e.target.checked)} />
            include a few mistakes to fix
          </label>
          <button
            type="button"
            className="admin-btn"
            disabled={downloading}
            onClick={() => download('/admin/purchase-orders/items/bill-sample', { rows: sampleRows, ...(sampleErrors ? { errors: 1 } : {}) }, `purchase-bill-test-${sampleRows}.csv`)}
          >
            Generate test CSV
          </button>
        </div>

        <div className="pi-inline-group">
          <input type="file" accept=".csv,.xlsx,.zip" onChange={(e) => setFile(e.target.files[0] || null)} />
          <button type="button" className="admin-btn admin-btn--primary" disabled={reading} onClick={handleUpload}>
            {reading ? 'Reading…' : 'Review file'}
          </button>
        </div>

        {feedback && <div className={`pi-feedback pi-feedback--${feedback.tone}`}>{feedback.text}</div>}

        {rows.length > 0 && (
          <div className="pi-mt3">
            <div className="pi-csv-totals">
              <span><b>{ready.length}</b> of {rows.length} rows ready ({ready.filter((r) => r.kind === 'new').length} new, {ready.filter((r) => r.kind === 'existing').length} existing)</span>
              {errorCount > 0 && <span className="pi-text-danger"><b>{errorCount}</b> need fixing</span>}
              {warnCount > 0 && <span className="pi-text-warning"><b>{warnCount}</b> to double-check</span>}
              <span>Discount <b>{formatMoney(disc)}</b></span>
              <span>Taxable <b>{formatMoney(taxable)}</b></span>
              <span>GST <b>{formatMoney(gst)}</b></span>
              <span>Bill total <b>{formatMoney(taxable + gst)}</b></span>
            </div>

            <div className="pi-csv-table-wrap">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th></th><th>Row</th><th>Category</th><th>Item</th><th>Qty</th><th>Unit cost</th><th>Disc %</th>
                    <th>GST %</th><th>MRP</th><th>Selling</th><th>Batch</th><th>Expiry</th><th className="pi-right">Line</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, i) => {
                    const type = typeOf(row);
                    const needsWeight = row.kind === 'new' && ['grocery', 'oils'].includes(type) && !row.loose;
                    const leaf = byUuid(row.category_uuid);
                    const top = leaf ? (leaf.parent_uuid ? byUuid(leaf.parent_uuid) : leaf) : byUuid(row.category_pending_top);
                    const subs = top ? kids(top.uuid) : [];
                    const bad = row.errors.length > 0;
                    const extras = [row.size && `Size ${row.size}`, row.colour && `Colour ${row.colour}`, row.loose && `Loose per ${row.unit || 'kg'}`, row.brand]
                      .filter(Boolean).join(' · ');

                    return (
                      <Fragment key={i}>
                        <tr className={bad ? 'pi-row-bad' : row.warnings.length ? 'pi-row-warn' : ''}>
                          <td>
                            <input type="checkbox" checked={row.include} disabled={bad} onChange={(e) => updateRow(i, { include: e.target.checked })} />
                          </td>
                          <td className="pi-small pi-nowrap">
                            {row.row_number}
                            <div>
                              {row.kind === 'existing'
                                ? <span className="status-badge status-badge--secondary">Existing</span>
                                : <span className="status-badge status-badge--info">New item</span>}
                            </div>
                          </td>
                          <td>
                            {row.kind === 'existing' ? (
                              <span className="pi-muted pi-small">existing item</span>
                            ) : (
                              <>
                                <select className="pi-mb4" value={top ? top.uuid : ''} onChange={(e) => onCategoryTopChange(i, e.target.value)}>
                                  <option value="">Category…</option>
                                  {tops().map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
                                </select>
                                {subs.length > 0 && (
                                  <select value={leaf ? leaf.uuid : ''} onChange={(e) => onCategorySubChange(i, e.target.value)}>
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
                                <span className="pi-fw-semibold">{row.product_name}</span>
                                <div className="pi-small pi-muted">{row.variant_name} · {row.sku}</div>
                              </>
                            ) : (
                              <>
                                <input className="pi-mb4" placeholder="Product name" value={row.product_name || ''} onChange={(e) => updateRow(i, { product_name: e.target.value })} />
                                <input placeholder="Variant / pack" value={row.variant_name || ''} onChange={(e) => updateRow(i, { variant_name: e.target.value })} />
                                {needsWeight && (
                                  <input className="pi-mt4" placeholder="Weight (g)" value={row.weight_grams ?? ''} onChange={(e) => updateRow(i, { weight_grams: e.target.value === '' ? null : Number(e.target.value) })} />
                                )}
                                {extras && <div className="pi-small pi-muted">{extras}</div>}
                              </>
                            )}
                          </td>
                          <td><input style={{ width: '5.5rem' }} type="number" step="0.001" min="0" value={row.quantity ?? ''} onChange={(e) => updateRow(i, { quantity: e.target.value })} /></td>
                          <td><input style={{ width: '6rem' }} type="number" step="0.01" min="0" value={row.unit_cost ?? ''} onChange={(e) => updateRow(i, { unit_cost: e.target.value })} /></td>
                          <td><input style={{ width: '4.5rem' }} type="number" step="0.01" min="0" max="100" placeholder="0" value={row.discount_percent ?? ''} onChange={(e) => updateRow(i, { discount_percent: e.target.value })} /></td>
                          <td><input style={{ width: '4.5rem' }} type="number" step="0.01" min="0" max="28" value={row.gst_rate ?? ''} onChange={(e) => updateRow(i, { gst_rate: e.target.value, gst_edited: true })} /></td>
                          <td><input style={{ width: '5.5rem' }} type="number" step="0.01" min="0" value={row.mrp ?? ''} onChange={(e) => updateRow(i, { mrp: e.target.value })} /></td>
                          <td><input style={{ width: '5.5rem' }} type="number" step="0.01" min="0" value={row.selling_price ?? ''} onChange={(e) => updateRow(i, { selling_price: e.target.value })} /></td>
                          <td><input style={{ width: '6rem' }} placeholder="Auto" value={row.batch_no ?? ''} onChange={(e) => updateRow(i, { batch_no: e.target.value })} /></td>
                          <td><input style={{ width: '8.5rem' }} type="date" value={row.expiry_date ?? ''} onChange={(e) => updateRow(i, { expiry_date: e.target.value })} /></td>
                          <td className="pi-right pi-small pi-nowrap">
                            {formatMoney(row.line_total)}
                            <div className="pi-muted">{row.discount_amount ? `disc −${formatMoney(row.discount_amount)} · ` : ''}GST {formatMoney(row.gst_amount)}</div>
                            {row.margin_percent !== undefined && (
                              <div className={row.margin_percent < 5 ? 'pi-text-danger' : 'pi-text-success'}>margin {row.margin_percent}%</div>
                            )}
                          </td>
                        </tr>
                        {(row.errors.length > 0 || row.warnings.length > 0) && (
                          <tr className={row.errors.length ? 'pi-row-bad' : ''}>
                            <td></td>
                            <td colSpan={12} className="pi-row-msg">
                              {row.errors.map((e, ei) => <div key={ei} className="pi-text-danger">✖ {e}</div>)}
                              {row.warnings.map((w, wi) => <div key={wi} className="pi-text-warning">⚠ {w}</div>)}
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="pi-toolbar">
              <button type="button" className="admin-btn admin-btn--primary" disabled={adding} onClick={addAll}>
                {adding ? 'Adding…' : 'Add ready rows to this purchase'}
              </button>
              <button type="button" className="admin-btn" onClick={() => { setRows([]); setFeedback(null); }}>Clear</button>
              <span className="pi-small pi-muted">Rows with a ✖ are skipped until fixed. Nothing is saved to stock until you save the purchase.</span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
