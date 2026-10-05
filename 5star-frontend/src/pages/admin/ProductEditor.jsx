import { useEffect, useRef, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { StatusBadge, LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import './ProductEditor.css';

/**
 * Product editor: adding and editing what the shop sells.
 * Ported from admin/assets/product-editor.js.
 *
 * A PRODUCT AND ITS FIRST PACK SIZE ARE CREATED TOGETHER — the API refuses to
 * create a product with no pack size, so the new-product form asks for the
 * first pack size (SKU, weight, MRP, selling price) in the same submission.
 * Further pack sizes are added afterwards, one at a time, on the edit page.
 *
 * Packs that already exist — most come from Purchase Inward's "New item" —
 * keep their pack name, SKU and weight; the edit page only changes their
 * pricing and limits, so nothing typed at inward is asked for twice.
 */

const PACK_TYPES = ['pouch', 'jar', 'box', 'tin', 'gift_box', 'refill', 'other'];

/**
 * GST rates that actually apply to this catalogue. Offered as a list rather
 * than a free number because picking the wrong rate is a tax problem, not a
 * typo. Whole spices are 5%, most processed foods 12%.
 */
const GST_RATES = [
  ['5', '5% — whole and ground spices, most dry fruits'],
  ['12', '12% — processed foods, blends, gift packs'],
  ['18', '18% — confectionery, most clothing and footwear'],
  ['28', '28% — footwear over ₹1000, luxury and sin goods'],
  ['0', '0% — exempt (fresh milk, unbranded staples)'],
];

/** Only the fields the person actually filled in, so a PATCH stays a patch. */
function collectForm(form) {
  const payload = {};
  const data = new FormData(form);

  for (const [key, value] of data.entries()) {
    const element = form.elements.namedItem(key);
    if (element && element.type === 'checkbox') continue; // handled below
    if (value === '') continue;
    payload[key] = value;
  }

  // Unchecked boxes never appear in FormData at all.
  Array.from(form.elements)
    .filter((element) => element.type === 'checkbox')
    .forEach((element) => {
      payload[element.name] = element.checked;
    });

  return payload;
}

function sizeSuggestions(sizeType) {
  const values = (sizeType && sizeType.values) || [];
  return (
    <datalist id="size-suggestions">
      {values.map((v) => (
        <option key={v.id} value={v.value} />
      ))}
    </datalist>
  );
}

/**
 * Mirrors ProductService::publish()'s own three checks exactly (pack size,
 * image, short description) so staff see what's missing before clicking
 * Publish, not after.
 */
function publishReadiness(product) {
  const missing = [];
  if ((product.variants || []).length === 0) missing.push('at least one pack size');
  if ((product.images || product.media || []).length === 0) missing.push('at least one photograph');
  if (!product.short_description || !product.short_description.trim()) missing.push('a short description');
  return missing;
}

function GstField({ formRef, initial }) {
  const current = String(initial ?? '5');
  const isPreset = GST_RATES.some(([rate]) => rate === current);
  const [picker, setPicker] = useState(isPreset ? current : '');

  function onPickerChange(event) {
    const value = event.target.value;
    setPicker(value);
    if (value !== '' && formRef.current) {
      const input = formRef.current.elements.namedItem('gst_rate');
      if (input) input.value = value;
    }
  }

  return (
    <div className="col-3">
      <label className="admin-label" htmlFor="gst_rate">GST rate</label>
      <select className="admin-input mb-1" value={picker} onChange={onPickerChange}>
        {GST_RATES.map(([rate, label]) => (
          <option key={rate} value={rate}>{label}</option>
        ))}
        <option value="">Other % (not in the usual list)…</option>
      </select>
      <input
        className="admin-input"
        id="gst_rate"
        name="gst_rate"
        type="number"
        step="0.01"
        min="0"
        max="28"
        defaultValue={current}
        placeholder="Exact GST %"
      />
    </div>
  );
}

function ProductForm({ product, categories, sizeType, onSubmit, saving, onCancel }) {
  const editing = Boolean(product);
  const p = product || {};
  const formRef = useRef(null);

  function handleSubmit(event) {
    event.preventDefault();
    onSubmit(formRef.current);
  }

  return (
    <form className="admin-card mb-4" ref={formRef} data-product-form onSubmit={handleSubmit}>
      <div className="admin-card__header">{editing ? 'Product details' : 'New product'}</div>
      <div className="admin-card__body">
        <div className="admin-grid">
          <div className="col-8">
            <label className="admin-label" htmlFor="name">
              Product name {!editing && <span className="text-danger">*</span>}
            </label>
            <input className="admin-input" id="name" name="name" defaultValue={p.name || ''} required={!editing} />
            <div className="admin-hint">What customers see. &quot;Organic Turmeric Powder&quot;, not &quot;TURM-01&quot;.</div>
          </div>

          <div className="col-4">
            <label className="admin-label" htmlFor="product_code">
              Internal code {!editing && <span className="text-danger">*</span>}
            </label>
            <input className="admin-input" id="product_code" name="product_code" defaultValue={p.product_code || ''} required={!editing} />
            <div className="admin-hint">Your own reference. Not shown to customers.</div>
          </div>

          <div className="col-6">
            <label className="admin-label" htmlFor="category_slug">
              Category {!editing && <span className="text-danger">*</span>}
            </label>
            <select className="admin-input" id="category_slug" name="category_slug" defaultValue={p.category_slug || ''} required={!editing}>
              <option value="">Choose a category…</option>
              {categories.map((category) => (
                <option key={category.slug} value={category.slug}>{category.name}</option>
              ))}
            </select>
          </div>

          <GstField formRef={formRef} initial={p.gst_rate} />

          <div className="col-3">
            <label className="admin-label" htmlFor="hsn_code">HSN code</label>
            <input className="admin-input" id="hsn_code" name="hsn_code" defaultValue={p.hsn_code || ''} />
            <div className="admin-hint">Required on GST invoices.</div>
          </div>

          <div className="col-4">
            <label className="admin-label" htmlFor="brand">Brand</label>
            <input className="admin-input" id="brand" name="brand" defaultValue={p.brand || ''} />
          </div>

          <div className="col-4">
            <label className="admin-label" htmlFor="origin_region">Origin</label>
            <input className="admin-input" id="origin_region" name="origin_region" defaultValue={p.origin_region || ''} />
            <div className="admin-hint">e.g. Kerala, Kashmir.</div>
          </div>

          <div className="col-4">
            <label className="admin-label" htmlFor="shelf_life_days">Shelf life (days)</label>
            <input
              className="admin-input"
              id="shelf_life_days"
              name="shelf_life_days"
              type="number"
              min="1"
              max="3650"
              defaultValue={p.shelf_life_days || ''}
            />
          </div>

          <div className="col-12">
            <label className="admin-label" htmlFor="short_description">Short description</label>
            <input
              className="admin-input"
              id="short_description"
              name="short_description"
              maxLength={320}
              defaultValue={p.short_description || ''}
            />
            <div className="admin-hint">One line, shown on the product card.</div>
          </div>

          <div className="col-12">
            <label className="admin-label" htmlFor="description">Full description</label>
            <textarea className="admin-input" id="description" name="description" rows={4} defaultValue={p.description || ''} />
          </div>

          <div className="col-12">
            <label className="admin-label" htmlFor="ingredients">Ingredients</label>
            <textarea className="admin-input" id="ingredients" name="ingredients" rows={2} defaultValue={p.ingredients || ''} />
            <div className="admin-hint">Food labelling law requires this on packaged food. Fill it in.</div>
          </div>

          <div className="col-6">
            <label className="admin-label" htmlFor="fssai_license_no">FSSAI licence number</label>
            <input className="admin-input" id="fssai_license_no" name="fssai_license_no" defaultValue={p.fssai_license_no || ''} />
            <div className="admin-hint">Legally required for packaged food sold in India.</div>
          </div>

          <div className="col-6 admin-checkbox-row">
            <label>
              <input type="checkbox" name="is_organic" defaultChecked={Boolean(p.is_organic)} /> Organic
            </label>
            <label>
              <input type="checkbox" name="is_vegetarian" defaultChecked={p.is_vegetarian !== false} /> Vegetarian
            </label>
            <label>
              <input type="checkbox" name="is_featured" defaultChecked={Boolean(p.is_featured)} /> Featured
            </label>
          </div>
        </div>
      </div>

      {!editing && (
        <div className="admin-card__body admin-card__body--bordered">
          <h2 className="h6">First pack size</h2>
          <p className="text-muted small">
            Every product needs at least one, so it is created with the product. You can add more afterwards.
          </p>
          <div className="admin-grid">
            <div className="col-3">
              <label className="admin-label" htmlFor="v_variant_name">Pack name <span className="text-danger">*</span></label>
              <input className="admin-input" id="v_variant_name" name="v_variant_name" placeholder="250 g pouch" required />
            </div>
            <div className="col-3">
              <label className="admin-label" htmlFor="v_sku">SKU</label>
              <input className="admin-input" id="v_sku" name="v_sku" minLength={3} placeholder="Auto" />
              <div className="admin-hint">Blank = a scannable barcode is made for you.</div>
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="v_weight_grams">Weight (g) <span className="text-danger">*</span></label>
              <input className="admin-input" id="v_weight_grams" name="v_weight_grams" type="number" min="1" max="100000" required />
              <div className="admin-hint">Always required — used for courier weight.</div>
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="v_size_value">Size (optional)</label>
              <input className="admin-input" id="v_size_value" name="v_size_value" list="size-suggestions" placeholder="e.g. M, or 8" />
              <div className="admin-hint">For clothing, footwear, anything sized rather than weighed.</div>
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="v_mrp">MRP <span className="text-danger">*</span></label>
              <input className="admin-input" id="v_mrp" name="v_mrp" type="number" step="0.01" min="1" required />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="v_selling_price">Selling <span className="text-danger">*</span></label>
              <input className="admin-input" id="v_selling_price" name="v_selling_price" type="number" step="0.01" min="1" required />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="v_max_order_quantity">Max per order</label>
              <input className="admin-input" id="v_max_order_quantity" name="v_max_order_quantity" type="number" min="1" max="500" placeholder="20" />
              <div className="admin-hint">Blank means 20. The cart refuses more than this.</div>
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="v_expiry_date">Expiry date</label>
              <input className="admin-input" id="v_expiry_date" name="v_expiry_date" type="date" />
            </div>
            <div className="col-12">
              <div className="admin-hint">
                Prices INCLUDE GST. Indian MRP is tax-inclusive, so the tax is extracted from this figure rather than added to it.
              </div>
            </div>
          </div>
          {sizeSuggestions(sizeType)}
        </div>
      )}

      <div className="admin-card__footer">
        <button className="admin-btn admin-btn--primary" type="submit" disabled={saving}>
          {saving ? 'Saving…' : editing ? 'Save changes' : 'Create product'}
        </button>
        <button className="admin-btn" type="button" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

/** Inline editor for one existing pack: pricing and limits only. */
function VariantEditRow({ variant, onSave, onCancel }) {
  const [form, setForm] = useState({
    mrp: String(variant.mrp ?? ''),
    selling_price: String(variant.selling_price ?? ''),
    max_order_quantity: Number(variant.max_order_quantity) > 0 ? String(variant.max_order_quantity) : '',
    expiry_date: variant.expiry_date ? String(variant.expiry_date).slice(0, 10) : '',
    is_default: Boolean(variant.is_default),
  });
  const [saving, setSaving] = useState(false);
  const set = (field) => (event) => setForm((f) => ({ ...f, [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));

  async function save() {
    if (Number(form.selling_price) > Number(form.mrp)) {
      toast('The selling price cannot be more than the MRP.', 'danger');
      return;
    }

    // Only what actually changed, so the price history records real changes.
    const changes = {};
    if (Number(form.mrp) !== Number(variant.mrp)) changes.mrp = form.mrp;
    if (Number(form.selling_price) !== Number(variant.selling_price)) changes.selling_price = form.selling_price;
    const oldMax = Number(variant.max_order_quantity) > 0 ? String(variant.max_order_quantity) : '';
    if (form.max_order_quantity !== oldMax && form.max_order_quantity !== '') changes.max_order_quantity = Number(form.max_order_quantity);
    const oldExpiry = variant.expiry_date ? String(variant.expiry_date).slice(0, 10) : '';
    if (form.expiry_date !== oldExpiry && form.expiry_date !== '') changes.expiry_date = form.expiry_date;
    if (form.is_default && !variant.is_default) changes.is_default = true;

    if (Object.keys(changes).length === 0) {
      onCancel();
      return;
    }

    setSaving(true);
    try {
      await onSave(variant.uuid, changes);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="admin-card__body admin-card__body--bordered variant-edit-row">
        <div className="variant-edit">
          <div className="variant-edit__fixed">
            <b>{variant.variant_name}</b>
            <span>SKU {variant.sku}</span>
            <span>{variant.weight_grams} g</span>
            <span className="text-muted small">Pack name, SKU and weight come from Purchase Inward and stay as they are.</span>
          </div>
          <div className="admin-grid">
            <div className="col-2">
              <label className="admin-label">MRP</label>
              <input className="admin-input" type="number" step="0.01" min="1" value={form.mrp} onChange={set('mrp')} />
            </div>
            <div className="col-2">
              <label className="admin-label">Selling price</label>
              <input className="admin-input" type="number" step="0.01" min="1" value={form.selling_price} onChange={set('selling_price')} />
            </div>
            <div className="col-2">
              <label className="admin-label">Max per order</label>
              <input className="admin-input" type="number" min="1" max="500" placeholder="20" value={form.max_order_quantity} onChange={set('max_order_quantity')} />
            </div>
            <div className="col-2">
              <label className="admin-label">Expiry date</label>
              <input className="admin-input" type="date" value={form.expiry_date} onChange={set('expiry_date')} />
            </div>
            <div className="col-2 admin-checkbox-row" style={{ alignSelf: 'flex-end' }}>
              <label>
                <input type="checkbox" checked={form.is_default} disabled={variant.is_default} onChange={set('is_default')} /> Default pack
              </label>
            </div>
            <div className="col-2" style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
              <button className="admin-btn admin-btn--primary" type="button" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save'}</button>
              <button className="admin-btn" type="button" disabled={saving} onClick={onCancel}>Cancel</button>
            </div>
            <div className="col-12">
              <div className="admin-hint">
                Prices include GST. A new selling price shows on the website, the app and the counter straight away, and is
                recorded in the price history like a change made on Purchase Inward.
              </div>
            </div>
          </div>
        </div>
    </div>
  );
}

function VariantsPanel({ product, sizeType, onAdded, onUpdated, onRemoved }) {
  const variants = product.variants || [];
  const formRef = useRef(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState(null);
  const [adding, setAdding] = useState(variants.length === 0);

  async function handleSubmit(event) {
    event.preventDefault();
    const form = formRef.current;
    const payload = collectForm(form);
    const sizeValue = payload.size_value;
    delete payload.size_value;

    // Caught here rather than by the server, because "selling price above MRP"
    // is a mistake with a clear explanation and no reason to make a round trip.
    if (Number(payload.selling_price) > Number(payload.mrp)) {
      toast('The selling price cannot be more than the MRP.', 'danger');
      return;
    }

    setSaving(true);

    try {
      await onAdded(payload, sizeValue);
      form.reset();
      setAdding(false);
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdate(uuid, changes) {
    await onUpdated(uuid, changes);
    setEditing(null);
  }

  async function handleRemove(variant) {
    if (!window.confirm('Remove this pack size?')) return;
    await onRemoved(variant.uuid);
  }

  return (
    <div className="admin-card mb-4">
      <div className="admin-card__header admin-card__header--flex">
        <span className="fw-semibold">Pack sizes</span>
        <span className="small text-muted">{variants.length} defined</span>
      </div>

      {variants.length === 0 ? (
        <div className="admin-card__body">
          <div className="admin-alert admin-alert--warning">
            This product has no pack sizes, so it cannot be sold. Add one below, or receive it on Purchase Inward.
          </div>
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="admin-table">
            <thead>
              <tr>
                <th>Pack</th>
                <th>SKU</th>
                <th className="text-end">Weight</th>
                <th className="text-end">MRP</th>
                <th className="text-end">Selling</th>
                <th className="text-center">Max/order</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {variants.map((variant) => (
                  <tr key={variant.uuid} className={editing === variant.uuid ? 'variant-row--editing' : undefined}>
                    <td>
                      {variant.variant_name}
                      {variant.is_default && <span className="review-chip" style={{ background: '#7a7a7a', marginLeft: 6 }}>Default</span>}
                    </td>
                    <td className="small" style={{ fontFamily: 'monospace' }}>{variant.sku}</td>
                    <td className="text-end small">
                      {variant.weight_grams} g
                      {variant.size_label && <div className="text-muted">Size {variant.size_label}</div>}
                    </td>
                    <td className="text-end small">{formatMoney(variant.mrp)}</td>
                    <td className="text-end">{formatMoney(variant.selling_price)}</td>
                    <td className="text-center small">
                      {Number(variant.max_order_quantity) > 0 ? variant.max_order_quantity : <span className="text-muted">—</span>}
                    </td>
                    <td className="text-end" style={{ whiteSpace: 'nowrap' }}>
                      <button className="admin-btn" type="button" disabled={editing === variant.uuid} onClick={() => setEditing(variant.uuid)}>
                        Edit price &amp; limits
                      </button>{' '}
                      <button className="admin-btn admin-btn--danger-outline" type="button" onClick={() => handleRemove(variant)}>
                        Remove
                      </button>
                    </td>
                  </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && variants.some((v) => v.uuid === editing) && (
        <VariantEditRow
          key={editing}
          variant={variants.find((v) => v.uuid === editing)}
          onSave={handleUpdate}
          onCancel={() => setEditing(null)}
        />
      )}

      <div className="admin-card__body admin-card__body--bordered">
        {!adding ? (
          <button className="admin-btn" type="button" onClick={() => setAdding(true)}>+ Add another pack size</button>
        ) : (
          <form ref={formRef} className="admin-grid" onSubmit={handleSubmit}>
            <div className="col-12">
              <b>New pack size</b>
              <div className="admin-hint">
                Only for a pack this product doesn&apos;t have yet. Stock you receive goes on Purchase Inward, which creates
                packs too — you don&apos;t need to add them here as well.
              </div>
            </div>
            <div className="col-3">
              <label className="admin-label" htmlFor="variant_name">Pack name <span className="text-danger">*</span></label>
              <input className="admin-input" id="variant_name" name="variant_name" placeholder="250 g pouch" required />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="weight_grams">Weight (g) <span className="text-danger">*</span></label>
              <input className="admin-input" id="weight_grams" name="weight_grams" type="number" min="1" max="100000" required />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="sku">SKU</label>
              <input className="admin-input" id="sku" name="sku" minLength={3} placeholder="Auto" />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="size_value">Size (optional)</label>
              <input className="admin-input" id="size_value" name="size_value" list="size-suggestions" placeholder="e.g. M, or 8" />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="mrp">MRP <span className="text-danger">*</span></label>
              <input className="admin-input" id="mrp" name="mrp" type="number" step="0.01" min="1" required />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="selling_price">Selling price <span className="text-danger">*</span></label>
              <input className="admin-input" id="selling_price" name="selling_price" type="number" step="0.01" min="1" required />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="max_order_quantity">Max per order</label>
              <input className="admin-input" id="max_order_quantity" name="max_order_quantity" type="number" min="1" max="500" placeholder="20" />
            </div>
            <div className="col-2">
              <label className="admin-label" htmlFor="expiry_date">Expiry date</label>
              <input className="admin-input" id="expiry_date" name="expiry_date" type="date" />
            </div>
            <div className="col-2" style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
              <button className="admin-btn admin-btn--primary" type="submit" disabled={saving}>{saving ? '…' : 'Add'}</button>
              {variants.length > 0 && <button className="admin-btn" type="button" onClick={() => setAdding(false)}>Cancel</button>}
            </div>
            <div className="col-12">
              <div className="admin-hint">
                Prices INCLUDE GST. Leave SKU blank to get a scannable barcode automatically, as on Purchase Inward.
              </div>
            </div>
            {sizeSuggestions(sizeType)}
          </form>
        )}
      </div>
    </div>
  );
}

/**
 * Product photographs. A product cannot be published without at least one —
 * the API refuses, and it is right to: an unillustrated item in a food shop
 * does not sell.
 */
function ImagesPanel({ product, onUploaded, onRemoved }) {
  const images = product.images || product.media || [];
  const fileRef = useRef(null);
  const altRef = useRef(null);
  const [uploading, setUploading] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    const file = fileRef.current.files[0];

    if (!file) {
      toast('Choose a file first.', 'danger');
      return;
    }

    const body = new FormData();
    body.append('image', file);
    body.append('alt_text', altRef.current.value || product.name);
    body.append('is_primary', images.length === 0 ? '1' : '0');

    setUploading(true);

    try {
      await onUploaded(body);
      fileRef.current.value = '';
      altRef.current.value = '';
    } finally {
      setUploading(false);
    }
  }

  async function handleRemove(image) {
    if (!window.confirm('Remove this photograph?')) return;
    await onRemoved(image.uuid);
  }

  return (
    <div className="admin-card mb-4">
      <div className="admin-card__header">Photographs</div>

      {images.length === 0 ? (
        <div className="admin-card__body">
          <div className="admin-alert admin-alert--warning">
            No photographs yet. At least one is required before this product can go on sale.
          </div>
        </div>
      ) : (
        <div className="admin-card__body">
          <div className="admin-image-grid">
            {images.map((image) => (
              <div className="admin-image-tile" key={image.uuid}>
                <img src={image.url || image.file_path || ''} alt={image.alt_text || product.name} />
                <div className="admin-image-tile__row">
                  {image.is_primary ? (
                    <span className="review-chip" style={{ background: '#7a7a7a' }}>Main</span>
                  ) : (
                    <span />
                  )}
                  <button type="button" className="admin-link-danger" onClick={() => handleRemove(image)}>Remove</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="admin-card__body admin-card__body--bordered">
        <form className="admin-grid" onSubmit={handleSubmit}>
          <div className="col-6">
            <label className="admin-label" htmlFor="image">Add a photograph</label>
            <input className="admin-input" id="image" type="file" accept="image/jpeg,image/png,image/webp" ref={fileRef} required />
          </div>
          <div className="col-4">
            <label className="admin-label" htmlFor="alt_text">Description for screen readers</label>
            <input className="admin-input" id="alt_text" placeholder="Whole green cardamom pods" ref={altRef} />
          </div>
          <div className="col-2" style={{ display: 'flex', alignItems: 'flex-end' }}>
            <button className="admin-btn admin-btn--primary" type="submit" disabled={uploading} style={{ width: '100%' }}>
              {uploading ? '…' : 'Upload'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function PublishSection({ product, onPublish }) {
  const missing = publishReadiness(product);
  const [publishing, setPublishing] = useState(false);
  const [error, setError] = useState(null);

  if (missing.length > 0) {
    return (
      <div className="admin-alert" style={{ background: '#f1f1ef' }}>
        Before this can go on sale it needs {missing.join(', ')}.
      </div>
    );
  }

  async function handleClick() {
    setPublishing(true);
    setError(null);

    try {
      await onPublish();
    } catch (err) {
      setError(err);
    } finally {
      setPublishing(false);
    }
  }

  return (
    <div>
      <button className="admin-btn admin-btn--success" type="button" disabled={publishing} onClick={handleClick}>
        {publishing ? 'Publishing…' : 'Put this product on sale'}
      </button>
      {error && <div className="mt-2"><ErrorState error={error} /></div>}
    </div>
  );
}

export default function ProductEditor({ identifier, onClose, onCreated }) {
  const [categories, setCategories] = useState([]);
  const [sizeType, setSizeType] = useState(null);
  const [product, setProduct] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  // Bumped on every successful reload so ProductForm's uncontrolled inputs
  // (defaultValue) remount with the server's latest values instead of
  // keeping whatever was last typed — mirrors the source rebuilding the
  // whole form's HTML fresh on every renderEditor() call.
  const [formVersion, setFormVersion] = useState(0);
  const sizeTypeRef = useRef(null);

  async function loadSizeType() {
    if (sizeTypeRef.current) return sizeTypeRef.current;

    try {
      const response = await api.get('/admin/inventory/option-types');
      sizeTypeRef.current = (response.data || []).find((t) => t.code === 'size') || null;
    } catch {
      sizeTypeRef.current = null;
    }

    setSizeType(sizeTypeRef.current);
    return sizeTypeRef.current;
  }

  /**
   * Finds the id of an existing size value matching `text` (case-insensitive),
   * or creates one. Returns null for blank input — sizing is optional, most
   * pack sizes (spices, dry fruits) never use this at all.
   */
  async function resolveSizeValueId(text) {
    const typed = (text || '').trim();
    if (typed === '') return null;

    const type = await loadSizeType();
    if (!type) return null;

    const existing = (type.values || []).find((v) => v.value.toLowerCase() === typed.toLowerCase());
    if (existing) return { typeId: type.id, valueId: existing.id };

    const created = await api.post(`/admin/inventory/option-types/${encodeURIComponent(type.uuid)}/values`, { value: typed });
    type.values = type.values || [];
    type.values.push(created.data);
    setSizeType({ ...type });

    return { typeId: type.id, valueId: created.data.id };
  }

  async function attachSizeIfProvided(variantUuid, sizeTextValue) {
    const resolved = await resolveSizeValueId(sizeTextValue);
    if (!resolved) return;

    await api.put(`/admin/variants/${encodeURIComponent(variantUuid)}/options`, {
      options: [{ option_type_id: resolved.typeId, option_value_id: resolved.valueId }],
    });
  }

  async function loadEverything() {
    setLoading(true);
    setError(null);

    try {
      const [categoriesResponse] = await Promise.all([
        api.get('/admin/categories').catch(() => ({ data: [] })),
        loadSizeType(),
      ]);
      setCategories(categoriesResponse.data.categories || categoriesResponse.data || []);

      if (identifier) {
        const response = await api.get(`/admin/products/${encodeURIComponent(identifier)}`);
        setProduct(response.data.product);
      } else {
        setProduct(null);
      }
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadEverything();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [identifier]);

  async function reload(newIdentifier) {
    setLoading(true);

    try {
      const response = await api.get(`/admin/products/${encodeURIComponent(newIdentifier)}`);
      setProduct(response.data.product);
      setFormVersion((v) => v + 1);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }

  async function handleProductSubmit(form) {
    setSaving(true);

    try {
      if (product) {
        await api.patch(`/admin/products/${encodeURIComponent(product.uuid)}`, collectForm(form));
        toast('Product saved.');
        await reload(product.uuid);
        return;
      }

      // Split the `v_`-prefixed fields back out into the variants array the
      // API expects. The prefix exists only so the two sets of inputs can
      // share one form without their ids colliding.
      const all = collectForm(form);
      const payload = {};
      const variant = {};

      Object.entries(all).forEach(([key, value]) => {
        if (key.startsWith('v_')) variant[key.slice(2)] = value;
        else payload[key] = value;
      });

      if (Number(variant.selling_price) > Number(variant.mrp)) {
        toast('The selling price cannot be more than the MRP.', 'danger');
        return;
      }

      const sizeValue = variant.size_value;
      delete variant.size_value;

      variant.is_default = true;
      payload.variants = [variant];

      const response = await api.post('/admin/products', payload);

      if (sizeValue) {
        const created = response.data.product.variants[0];
        await attachSizeIfProvided(created.uuid, sizeValue);
      }

      toast('Product created. Add more pack sizes, then put it on sale.');
      await reload(response.data.product.uuid);
      // Swap the URL to ?edit=<uuid>, matching the source's redirect from the
      // "new" form to the edit page.
      if (onCreated) onCreated(response.data.product.uuid);
    } catch (err) {
      toast(err.message || 'Could not save the product.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  async function handleVariantAdd(payload, sizeValue) {
    try {
      const before = new Set((product.variants || []).map((v) => v.uuid));
      const response = await api.post(`/admin/products/${encodeURIComponent(product.uuid)}/variants`, payload);
      // The new pack is the one that wasn't there before (its SKU may have
      // been generated by the server).
      const created = (response.data.product.variants || []).find((v) => !before.has(v.uuid));

      if (created && sizeValue) {
        await attachSizeIfProvided(created.uuid, sizeValue);
      }

      toast('Pack size added.');
      await reload(product.uuid);
    } catch (err) {
      toast(err.message || 'Could not add the pack size.', 'danger');
      throw err;
    }
  }

  async function handleVariantUpdate(uuid, changes) {
    try {
      await api.patch(`/admin/variants/${encodeURIComponent(uuid)}`, changes);
      toast('Pack size updated.');
      await reload(product.uuid);
    } catch (err) {
      toast(err.message || 'Could not update the pack size.', 'danger');
      throw err;
    }
  }

  async function handleVariantRemove(uuid) {
    try {
      await api.delete(`/admin/variants/${encodeURIComponent(uuid)}`);
      toast('Deleted successfully. View in Recycle Bin.');
      await reload(product.uuid);
    } catch (err) {
      toast(err.message || 'Could not remove the pack size.', 'danger');
    }
  }

  async function handleImageUpload(body) {
    try {
      await api.upload(`/admin/products/${encodeURIComponent(product.uuid)}/images`, body);
      toast('Photograph added.');
      await reload(product.uuid);
    } catch (err) {
      toast(err.message || 'Could not upload the photograph.', 'danger');
      throw err;
    }
  }

  async function handleImageRemove(uuid) {
    try {
      await api.delete(`/admin/media/${encodeURIComponent(uuid)}`);
      toast('Photograph removed.');
      await reload(product.uuid);
    } catch (err) {
      toast(err.message || 'Could not remove the photograph.', 'danger');
    }
  }

  async function handlePublish() {
    // "Save changes" and "Put this product on sale" are two separate actions —
    // publish always acts on what's on screen, so any uncommitted edit is
    // saved first rather than left stale on the server.
    const form = document.querySelector('[data-product-form]');
    if (form) {
      const changes = collectForm(form);
      if (Object.keys(changes).length > 0) {
        await api.patch(`/admin/products/${encodeURIComponent(product.uuid)}`, changes);
      }
    }

    await api.post(`/admin/products/${encodeURIComponent(product.uuid)}/publish`, {});
    toast('This product is now on sale.');
    await reload(product.uuid);
  }

  if (loading) return <LoadingState />;
  if (error) {
    return (
      <div>
        <button className="admin-link" onClick={onClose}>← All products</button>
        <ErrorState error={error} />
      </div>
    );
  }

  return (
    <div>
      <button className="admin-link" onClick={onClose}>← All products</button>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between', marginTop: 8 }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>{product ? product.name : 'Add a product'}</h1>
        {product && <StatusBadge status={product.status === 'published' ? 'approved' : 'pending'} label={product.status} />}
      </div>

      <ProductForm
        key={`${identifier || 'new'}-${formVersion}`}
        product={product}
        categories={categories}
        sizeType={sizeType}
        onSubmit={handleProductSubmit}
        saving={saving}
        onCancel={onClose}
      />

      {product && (
        <VariantsPanel product={product} sizeType={sizeType} onAdded={handleVariantAdd} onUpdated={handleVariantUpdate} onRemoved={handleVariantRemove} />
      )}

      {product && (
        <ImagesPanel product={product} onUploaded={handleImageUpload} onRemoved={handleImageRemove} />
      )}

      {product && product.status !== 'published' && (
        <PublishSection product={product} onPublish={handlePublish} />
      )}
    </div>
  );
}
