import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import CategoryPicker, { leafOf, splitCategory } from '../../components/admin/CategoryPicker';
import './ItemReview.css';

/**
 * Item review (administrators only) — the queue of items staff added or
 * changed, and one screen to correct an item's name, category, packs,
 * units, prices and purchase costs. The API (PATCH /admin/item-review/{uuid})
 * carries each correction into purchases, the vendor balance, returns,
 * stock batches and average stock cost; customer invoices already issued are
 * never changed.
 */
export default function ItemReview() {
  const [status, setStatus] = useState('pending');
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [list, setList] = useState({ loading: true, error: null, data: null });
  const [selected, setSelected] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setList((s) => ({ ...s, loading: true, error: null }));
    api.get('/admin/item-review', { status, q: search, page })
      .then((r) => { if (!cancelled) setList({ loading: false, error: null, data: r.data }); })
      .catch((error) => { if (!cancelled) setList({ loading: false, error, data: null }); });
    return () => { cancelled = true; };
  }, [status, search, page, reloadKey]);

  const items = list.data?.items || [];
  const pagination = list.data?.pagination;

  return (
    <div className="ir-page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title">
          Item review
          {list.data && list.data.pending_count > 0 && <span className="ir-count">{list.data.pending_count} to check</span>}
        </h1>
      </div>
      <p className="ir-lead">
        Items added or changed by staff wait here for an administrator. Correct a mistake once — name, category,
        pack, unit, price or purchase cost — and it is fixed in purchases, the vendor balance, stock and the shop.
        Customer bills already issued are not changed.
      </p>

      <div className="ir-layout">
        <section className="ir-list" aria-label="Items">
          <div className="ir-tabs" role="tablist">
            {[['pending', 'To check'], ['reviewed', 'Reviewed'], ['all', 'All items']].map(([key, label]) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={status === key}
                className={status === key ? 'active' : ''}
                onClick={() => { setStatus(key); setPage(1); }}
              >
                {label}
              </button>
            ))}
          </div>
          <form
            className="ir-search"
            onSubmit={(e) => { e.preventDefault(); setSearch(q.trim()); setPage(1); }}
          >
            <input
              type="search"
              placeholder="Name, SKU or barcode"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              aria-label="Search items"
            />
            <button type="submit" className="admin-btn">Search</button>
          </form>

          {list.loading && <LoadingState />}
          {list.error && <ErrorState error={list.error} />}
          {!list.loading && !list.error && items.length === 0 && (
            <EmptyState
              title={status === 'pending' ? 'Nothing waiting for review.' : 'No items found.'}
              hint={status === 'pending' ? 'Items staff add or re-price appear here.' : undefined}
            />
          )}

          <ul className="ir-items">
            {items.map((it) => (
              <li key={it.uuid}>
                <button
                  type="button"
                  className={`ir-item${selected === it.uuid ? ' is-on' : ''}`}
                  onClick={() => setSelected(it.uuid)}
                >
                  <span className="ir-item__top">
                    <strong>{it.name}</strong>
                    {it.admin_reviewed
                      ? <span className="ir-tag ir-tag--ok">Reviewed</span>
                      : <span className="ir-tag ir-tag--wait">To check</span>}
                  </span>
                  <span className="ir-item__meta">
                    {it.category_path || 'No category'} · {it.pack_count} pack{it.pack_count === 1 ? '' : 's'}
                    {it.price_from != null && <> · from {formatMoney(it.price_from)}</>}
                  </span>
                  {!it.admin_reviewed && it.review_reason && <span className="ir-item__why">{it.review_reason}</span>}
                </button>
              </li>
            ))}
          </ul>

          {pagination && pagination.total_pages > 1 && (
            <div className="ir-pager">
              <button type="button" className="admin-btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
              <span>Page {pagination.page} of {pagination.total_pages}</span>
              <button type="button" className="admin-btn" disabled={page >= pagination.total_pages} onClick={() => setPage(page + 1)}>Next</button>
            </div>
          )}
        </section>

        <section className="ir-editor" aria-label="Correct item">
          {selected
            ? (
              <ItemEditor
                key={selected}
                uuid={selected}
                onClose={() => setSelected(null)}
                onSaved={() => setReloadKey((k) => k + 1)}
              />
            )
            : <EmptyState title="Choose an item to check or correct." />}
        </section>
      </div>
    </div>
  );
}

const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
const differs = (a, b) => Math.abs(Number(a || 0) - Number(b || 0)) > 0.0049;

function ItemEditor({ uuid, onClose, onSaved }) {
  const [state, setState] = useState({ loading: true, error: null, data: null });
  const [categories, setCategories] = useState([]);
  const [form, setForm] = useState(null);
  const [saving, setSaving] = useState(false);
  const [applied, setApplied] = useState(null);
  const [showErrors, setShowErrors] = useState(false);

  const fill = useCallback((data, cats) => {
    setForm({
      name: data.product.name,
      category: splitCategory(cats, data.product.category_uuid),
      variants: data.variants.map((v) => ({
        ...v,
        mrp: String(v.mrp),
        selling_price: String(v.selling_price),
        weight_grams: String(v.weight_grams),
        unit_label: v.unit_label || '',
      })),
      costs: Object.fromEntries(data.purchase_lines.map((l) => [l.uuid, String(l.unit_cost)])),
      markReviewed: true,
      note: '',
    });
  }, []);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      api.get(`/admin/item-review/${uuid}`),
      api.get('/admin/inventory/setup').catch(() => ({ data: { categories: [] } })),
    ]).then(([detail, setup]) => {
      if (cancelled) return;
      const cats = setup.data.categories || [];
      setCategories(cats);
      setState({ loading: false, error: null, data: detail.data });
      fill(detail.data, cats);
    }).catch((error) => { if (!cancelled) setState({ loading: false, error, data: null }); });
    return () => { cancelled = true; };
  }, [uuid, fill]);

  const data = state.data;

  // What is about to change, so the admin sees it before saving.
  const changes = useMemo(() => {
    if (!data || !form) return null;
    const leaf = leafOf(categories, form.category.topUuid, form.category.subUuid);
    const variants = [];
    form.variants.forEach((v) => {
      const orig = data.variants.find((o) => o.uuid === v.uuid);
      const row = { uuid: v.uuid };
      if (v.variant_name.trim() !== orig.variant_name) row.variant_name = v.variant_name.trim();
      if (v.stock_unit_type !== orig.stock_unit_type) row.stock_unit_type = v.stock_unit_type;
      if ((v.unit_label.trim() || null) !== (orig.unit_label || null)) row.unit_label = v.unit_label.trim();
      if (Number(v.weight_grams) !== orig.weight_grams) row.weight_grams = Number(v.weight_grams);
      if (differs(v.mrp, orig.mrp)) row.mrp = v.mrp;
      if (differs(v.selling_price, orig.selling_price)) row.selling_price = v.selling_price;
      if (Object.keys(row).length > 1) variants.push(row);
    });
    const purchaseLines = data.purchase_lines
      .filter((l) => form.costs[l.uuid] !== '' && Math.abs(Number(form.costs[l.uuid]) - l.unit_cost) > 0.00005)
      .map((l) => ({ uuid: l.uuid, unit_cost: form.costs[l.uuid] }));
    const body = { variants, purchase_lines: purchaseLines };
    if (form.name.trim() !== data.product.name) body.name = form.name;
    if (leaf && leaf !== data.product.category_uuid) body.category_uuid = leaf;
    const count = (body.name ? 1 : 0) + (body.category_uuid ? 1 : 0) + variants.length + purchaseLines.length;
    return { body, count, leaf };
  }, [data, form, categories]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState error={state.error} />;
  if (!form) return null;

  const p = data.product;
  const setVariant = (i, patch) => setForm((f) => ({ ...f, variants: f.variants.map((v, j) => (j === i ? { ...v, ...patch } : v)) }));

  const problems = [];
  if (!form.name.trim()) problems.push('Enter the item name.');
  if (!changes.leaf) problems.push('Choose the category and sub-category.');
  form.variants.forEach((v) => {
    if (!v.variant_name.trim()) problems.push('Every pack needs a name.');
    if (!(num(v.mrp) > 0) || !(num(v.selling_price) > 0)) problems.push(`${v.variant_name || 'A pack'}: enter the MRP and selling price.`);
    else if (num(v.selling_price) > num(v.mrp)) problems.push(`${v.variant_name}: selling price is above the MRP.`);
    if (!(num(v.weight_grams) > 0)) problems.push(`${v.variant_name || 'A pack'}: weight must be more than 0 g.`);
  });
  Object.values(form.costs).forEach((c) => { if (c === '' || Number(c) < 0) problems.push('A purchase cost is empty or negative.'); });
  const uniqueProblems = [...new Set(problems)];
  const nothing = changes.count === 0 && !(form.markReviewed && !p.admin_reviewed);

  async function save() {
    setShowErrors(true);
    if (uniqueProblems.length) { toast(uniqueProblems[0], 'danger'); return; }
    setSaving(true);
    try {
      const r = await api.patch(`/admin/item-review/${uuid}`, {
        ...changes.body,
        mark_reviewed: form.markReviewed,
        note: form.note.trim() || undefined,
      });
      setState({ loading: false, error: null, data: r.data });
      fill(r.data, categories);
      setApplied(r.data.applied || []);
      setShowErrors(false);
      toast(r.message || 'Saved.');
      onSaved();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not save the correction.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="ir-form">
      <div className="ir-form__head">
        <div>
          <h2>{p.name}</h2>
          <div className="ir-sub">
            {p.product_code} · added {p.created_date?.slice(0, 10)}{p.created_by_name ? ` by ${p.created_by_name}` : ''}
          </div>
        </div>
        <button type="button" className="admin-btn" onClick={onClose} aria-label="Close">Close</button>
      </div>

      {p.admin_reviewed
        ? (
          <div className="ir-banner ir-banner--ok">
            Reviewed{p.reviewed_by_name ? ` by ${p.reviewed_by_name}` : ''}{p.reviewed_date ? ` on ${p.reviewed_date.slice(0, 10)}` : ''}.
          </div>
        )
        : <div className="ir-banner ir-banner--wait"><strong>Why it is here:</strong> {p.review_reason || 'Not yet checked by an administrator.'}</div>}

      {applied && applied.length > 0 && (
        <div className="ir-applied" role="status">
          <strong>Saved — corrected everywhere:</strong>
          <ul>{applied.map((line) => <li key={line}>{line}</li>)}</ul>
        </div>
      )}

      <fieldset className="ir-section">
        <legend>Item</legend>
        <label className="ir-field">
          <span>Item name</span>
          <input
            value={form.name}
            aria-invalid={showErrors && !form.name.trim() ? 'true' : 'false'}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
          />
        </label>
        <CategoryPicker
          categories={categories}
          name={form.name}
          value={form.category}
          showErrors={showErrors}
          idPrefix="ir"
          onChange={(category) => setForm((f) => ({ ...f, category }))}
          onCreated={(node) => setCategories((list) => [...list, node])}
        />
      </fieldset>

      <fieldset className="ir-section">
        <legend>Packs, units and prices</legend>
        <div className="ir-table-wrap">
          <table className="admin-table ir-table">
            <thead>
              <tr>
                <th>Pack name</th>
                <th>Sold by</th>
                <th>Unit</th>
                <th>Weight (g)</th>
                <th>MRP ₹</th>
                <th>Selling ₹</th>
                <th>In stock</th>
                <th>Avg cost</th>
              </tr>
            </thead>
            <tbody>
              {form.variants.map((v, i) => {
                const orig = data.variants[i];
                return (
                  <tr key={v.uuid}>
                    <td data-label="Pack name">
                      <input value={v.variant_name} onChange={(e) => setVariant(i, { variant_name: e.target.value })} />
                      <small className="ir-muted">{v.sku || v.barcode || ''}</small>
                    </td>
                    <td data-label="Sold by">
                      <select value={v.stock_unit_type} onChange={(e) => setVariant(i, { stock_unit_type: e.target.value })}>
                        <option value="weight">Weight</option>
                        <option value="quantity">Count</option>
                      </select>
                    </td>
                    <td data-label="Unit">
                      <input value={v.unit_label} placeholder="pouch, pc, box…" maxLength={30} onChange={(e) => setVariant(i, { unit_label: e.target.value })} />
                    </td>
                    <td data-label="Weight (g)">
                      <input type="number" min="1" step="1" inputMode="numeric" value={v.weight_grams} onChange={(e) => setVariant(i, { weight_grams: e.target.value })} />
                    </td>
                    <td data-label="MRP ₹">
                      <input type="number" min="0" step="0.01" inputMode="decimal" className={differs(v.mrp, orig.mrp) ? 'is-changed' : ''} value={v.mrp} onChange={(e) => setVariant(i, { mrp: e.target.value })} />
                    </td>
                    <td data-label="Selling ₹">
                      <input type="number" min="0" step="0.01" inputMode="decimal" className={differs(v.selling_price, orig.selling_price) ? 'is-changed' : ''} value={v.selling_price} onChange={(e) => setVariant(i, { selling_price: e.target.value })} />
                    </td>
                    <td data-label="In stock">{orig.stock_quantity}</td>
                    <td data-label="Avg cost">{orig.average_cost == null ? '—' : formatMoney(orig.average_cost)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </fieldset>

      <fieldset className="ir-section">
        <legend>Purchase costs</legend>
        {data.purchase_lines.length === 0
          ? <p className="ir-muted">No purchases recorded for this item.</p>
          : (
            <>
              <p className="ir-hint">
                Correcting a cost re-works that purchase's total and transport share, the vendor balance, any return to
                the vendor, and the average stock cost.
              </p>
              <div className="ir-table-wrap">
                <table className="admin-table ir-table">
                  <thead>
                    <tr>
                      <th>Purchase</th>
                      <th>Vendor</th>
                      <th>Pack</th>
                      <th>Qty</th>
                      <th>Cost ₹ / unit</th>
                      <th>Landing</th>
                      <th>Purchase total · paid</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.purchase_lines.map((l) => {
                      const value = form.costs[l.uuid];
                      const changed = value !== '' && Math.abs(Number(value) - l.unit_cost) > 0.00005;
                      return (
                        <tr key={l.uuid}>
                          <td data-label="Purchase">
                            <strong>{l.po_number}</strong>
                            <small className="ir-muted">{l.purchase_date}{l.invoice_reference ? ` · inv ${l.invoice_reference}` : ''}</small>
                          </td>
                          <td data-label="Vendor">{l.vendor_name || '—'}</td>
                          <td data-label="Pack">{l.variant_name}</td>
                          <td data-label="Qty">
                            {l.quantity}
                            {l.returned_quantity > 0 && <small className="ir-muted">{l.returned_quantity} returned</small>}
                          </td>
                          <td data-label="Cost ₹ / unit">
                            <input
                              type="number"
                              min="0"
                              step="0.01"
                              inputMode="decimal"
                              className={changed ? 'is-changed' : ''}
                              value={value}
                              onChange={(e) => setForm((f) => ({ ...f, costs: { ...f.costs, [l.uuid]: e.target.value } }))}
                            />
                          </td>
                          <td data-label="Landing">{l.landing_cost == null ? '—' : formatMoney(l.landing_cost)}</td>
                          <td data-label="Purchase total · paid">
                            {formatMoney(l.po_grand_total)}
                            <small className="ir-muted">paid {formatMoney(l.po_amount_paid)}</small>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </>
          )}
      </fieldset>

      <fieldset className="ir-section">
        <legend>Save</legend>
        <label className="ir-field">
          <span>Note (optional, kept in the audit log)</span>
          <input
            value={form.note}
            maxLength={500}
            placeholder="e.g. cost typed as 2000 instead of 200"
            onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))}
          />
        </label>
        <label className="ir-check">
          <input type="checkbox" checked={form.markReviewed} onChange={(e) => setForm((f) => ({ ...f, markReviewed: e.target.checked }))} />
          <span>Mark as reviewed by an administrator</span>
        </label>
        {showErrors && uniqueProblems.length > 0 && (
          <ul className="ir-problems">{uniqueProblems.map((m) => <li key={m}>{m}</li>)}</ul>
        )}
        <div className="ir-actions">
          <span className="ir-muted">
            {changes.count === 0 ? 'No corrections yet.' : `${changes.count} correction${changes.count === 1 ? '' : 's'} to save.`}
          </span>
          <button type="button" className="admin-btn" disabled={saving} onClick={() => { fill(data, categories); setShowErrors(false); }}>
            Undo changes
          </button>
          <button type="button" className="admin-btn admin-btn--primary" disabled={saving || nothing} onClick={save}>
            {saving ? 'Saving…' : changes.count > 0 ? 'Save corrections' : p.admin_reviewed ? 'Nothing to save' : 'Mark reviewed'}
          </button>
        </div>
      </fieldset>

      {data.history.length > 0 && (
        <fieldset className="ir-section">
          <legend>Earlier corrections</legend>
          <ul className="ir-history">
            {data.history.map((h) => (
              <li key={`${h.date}-${h.notes || ''}`}>
                <div className="ir-muted">{h.date}{h.by ? ` · ${h.by}` : ''}{h.notes ? ` — ${h.notes}` : ''}</div>
                <ul>{(h.changes?.summary || []).map((line) => <li key={line}>{line}</li>)}</ul>
              </li>
            ))}
          </ul>
        </fieldset>
      )}
    </div>
  );
}
