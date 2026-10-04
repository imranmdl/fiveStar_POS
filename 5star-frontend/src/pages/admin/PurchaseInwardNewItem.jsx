import { useEffect, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast.js';

/**
 * "New item" form for Purchase Inward — ported from
 * admin/assets/inward-new-item.js (renderNewItemForm).
 *
 * The shape of the form follows the business type of the chosen top-level
 * category (set once and inherited by everything under it):
 *   grocery / oils           packed (fixed pack) or loose (weighed/measured
 *                             at the counter, priced per kg / litre / piece)
 *   clothing / footwear       one item, then a size x colour grid -> one
 *                             variant each
 *   toys / stationery / general   plain item with an optional variant label
 *
 * Category -> Sub-category -> Item: two cascading pickers, with inline
 * "add a category / sub-category" so staff never leave the screen.
 */

const PROFILES = {
  grocery: { label: 'Grocery', mode: 'weighed', units: ['kg', 'g', 'piece', 'dozen'], gst: 5 },
  oils: { label: 'Oils', mode: 'weighed', units: ['litre', 'ml', 'kg'], gst: 5 },
  clothing: { label: 'Clothing', mode: 'grid', gst: 12 },
  footwear: { label: 'Footwear', mode: 'grid', gst: 12 },
  toys: { label: 'Toys', mode: 'plain', gst: 12 },
  stationery: { label: 'Stationery', mode: 'plain', gst: 12 },
  general: { label: 'General', mode: 'plain', gst: 18 },
};

/** Grams that one stock unit represents — weight_grams is NOT NULL and > 0 on every variant. */
const UNIT_GRAMS = { kg: 1000, g: 1, litre: 1000, ml: 1, piece: 1, dozen: 1 };
const UNIT_NAMES = { kg: 'kg', g: 'gram', litre: 'litre', ml: 'ml', piece: 'piece', dozen: 'dozen' };

export default function PurchaseInwardNewItem({ setup, onCategoryCreated, onAddLine, onClose }) {
  const categories = setup.categories || [];
  const tops = categories.filter((c) => !c.parent_uuid);
  const kids = (uuid) => categories.filter((c) => c.parent_uuid === uuid);
  const optionValues = (code) => ((setup.option_types || []).find((t) => t.code === code)?.values) || [];

  const [topUuid, setTopUuid] = useState('');
  const [subUuid, setSubUuid] = useState('');
  const [mode, setMode] = useState('packed'); // weighed sub-mode: packed | loose
  const [unit, setUnit] = useState('kg');
  const [fields, setFields] = useState({});
  const [sizes, setSizes] = useState([]);
  const [colours, setColours] = useState([]);
  const [customSizes, setCustomSizes] = useState([]);
  const [customColours, setCustomColours] = useState([]);
  const [chipInput, setChipInput] = useState({ size: '', color: '' });
  const [catAdd, setCatAdd] = useState(null); // { isTop, name, itemType }
  const [catSaving, setCatSaving] = useState(false);
  const [feedback, setFeedback] = useState(null); // { tone, text }
  const [creating, setCreating] = useState(false);

  const top = tops.find((c) => c.uuid === topUuid);
  const type = top ? (top.item_type || 'general') : null;
  const profile = type ? (PROFILES[type] || PROFILES.general) : null;
  const subs = topUuid ? kids(topUuid) : [];
  const leafUuid = !topUuid ? '' : (subs.length ? subUuid : topUuid);

  // Reset the type-specific detail whenever the top-level category changes —
  // mirrors buildDetail()'s rebuild, keeping only the business fields that
  // don't depend on item type (brand, HSN, cost) and resetting GST % to the
  // new category's default.
  useEffect(() => {
    const t = tops.find((c) => c.uuid === topUuid);
    const tType = t ? (t.item_type || 'general') : 'general';
    const prof = PROFILES[tType] || PROFILES.general;
    setMode('packed');
    setUnit(tType === 'oils' ? 'litre' : 'kg');
    setSubUuid('');
    setSizes([]);
    setColours([]);
    setCustomSizes([]);
    setCustomColours([]);
    setFields((f) => ({
      brand: f.brand || '',
      hsn_code: f.hsn_code || '',
      unit_cost: f.unit_cost || '',
      gst_rate: String(prof.gst),
    }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [topUuid]);

  function setField(name, value) {
    setFields((f) => ({ ...f, [name]: value }));
  }
  const val = (name) => (fields[name] ?? '').toString().trim();

  const baseSizes = profile?.mode === 'grid'
    ? optionValues('size').filter((v) => (type === 'footwear' ? /^\d+$/.test(v) : !/^\d+$/.test(v)))
    : [];
  const baseColours = profile?.mode === 'grid' ? optionValues('color') : [];
  const sizeChips = [...baseSizes, ...customSizes.filter((c) => !baseSizes.some((b) => b.toLowerCase() === c.toLowerCase()))];
  const colourChips = [...baseColours, ...customColours.filter((c) => !baseColours.some((b) => b.toLowerCase() === c.toLowerCase()))];

  function toggleChip(kind, value) {
    const ticked = kind === 'size' ? sizes : colours;
    const setTicked = kind === 'size' ? setSizes : setColours;
    setTicked(ticked.includes(value) ? ticked.filter((v) => v !== value) : [...ticked, value]);
  }

  function addChip(kind) {
    const value = chipInput[kind].trim();
    if (!value) return;
    const chips = kind === 'size' ? sizeChips : colourChips;
    const existing = chips.find((c) => c.toLowerCase() === value.toLowerCase());
    const canonical = existing || value;

    if (!existing) {
      if (kind === 'size') setCustomSizes((c) => [...c, value]);
      else setCustomColours((c) => [...c, value]);
    }

    const ticked = kind === 'size' ? sizes : colours;
    const setTicked = kind === 'size' ? setSizes : setColours;
    if (!ticked.some((v) => v.toLowerCase() === canonical.toLowerCase())) setTicked([...ticked, canonical]);

    setChipInput((c) => ({ ...c, [kind]: '' }));
  }

  function openCategoryAdd(isTop) {
    setFeedback(null);
    setCatAdd({ isTop, name: '', itemType: Object.keys(PROFILES)[0] });
  }

  async function saveCategoryAdd() {
    const name = catAdd.name.trim();
    if (name.length < 2) {
      setFeedback({ tone: 'danger', text: 'Enter a name (at least 2 letters).' });
      return;
    }

    const body = { name };
    if (catAdd.isTop) body.item_type = catAdd.itemType;
    else body.parent_uuid = topUuid;

    setCatSaving(true);

    try {
      const response = await api.post('/admin/inventory/quick-category', body);
      const created = response.data.category;
      const parentType = catAdd.isTop ? created.item_type : type;

      onCategoryCreated({
        uuid: created.uuid,
        slug: created.slug,
        name: created.name,
        parent_uuid: catAdd.isTop ? null : topUuid,
        item_type: parentType || 'general',
        has_children: false,
      });

      if (catAdd.isTop) setTopUuid(created.uuid);
      else setSubUuid(created.uuid);

      setCatAdd(null);
      setFeedback({ tone: 'success', text: `${catAdd.isTop ? 'Category' : 'Sub-category'} "${created.name}" added.` });
    } catch (error) {
      setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not save that.' });
    } finally {
      setCatSaving(false);
    }
  }

  async function handleCreate() {
    if (!leafUuid) { setFeedback({ tone: 'danger', text: 'Choose a sub-category for this item.' }); return; }
    if (!val('product_name')) { setFeedback({ tone: 'danger', text: 'Enter the item name.' }); return; }
    if (!val('mrp') || !val('selling_price')) { setFeedback({ tone: 'danger', text: 'Enter the MRP and the selling price.' }); return; }
    if (Number(val('selling_price')) > Number(val('mrp'))) {
      setFeedback({ tone: 'danger', text: 'Selling price cannot be above the MRP.' });
      return;
    }

    const name = val('product_name');
    const mrp = val('mrp');
    const selling = val('selling_price');
    let variants = [];

    if (profile.mode === 'weighed') {
      if (mode === 'loose') {
        variants = [{
          variant_name: `Loose (per ${UNIT_NAMES[unit]})`,
          weight_grams: UNIT_GRAMS[unit],
          stock_unit_type: 'weight',
          unit_label: unit,
          pack_type: 'other',
          barcode: val('barcode'),
          mrp,
          selling_price: selling,
        }];
      } else {
        if (!val('variant_name') || !val('weight_grams')) {
          setFeedback({ tone: 'danger', text: 'Enter the pack size and its weight.' });
          return;
        }
        variants = [{
          variant_name: val('variant_name'),
          weight_grams: Number(val('weight_grams')),
          stock_unit_type: 'weight',
          pack_type: val('pack_type') || 'pouch',
          barcode: val('barcode'),
          mrp,
          selling_price: selling,
        }];
      }
    } else if (profile.mode === 'grid') {
      if (!sizes.length) { setFeedback({ tone: 'danger', text: 'Tick at least one size.' }); return; }

      for (const size of sizes) {
        for (const colour of (colours.length ? colours : [''])) {
          variants.push({
            variant_name: colour ? `${size} / ${colour}` : size,
            stock_unit_type: 'quantity',
            unit_label: 'pcs',
            mrp,
            selling_price: selling,
            options: colour ? { size, color: colour } : { size },
          });
        }
      }
    } else {
      variants = [{
        variant_name: val('variant_name') || 'Standard',
        weight_grams: val('weight_grams') ? Number(val('weight_grams')) : undefined,
        stock_unit_type: 'quantity',
        unit_label: 'pcs',
        barcode: val('barcode'),
        mrp,
        selling_price: selling,
      }];
    }

    variants = variants.map((v) => Object.fromEntries(Object.entries(v).filter(([, x]) => x !== '' && x !== undefined)));

    const payload = { category_uuid: leafUuid, product_name: name, variants };
    if (val('brand')) payload.brand = val('brand');
    if (val('hsn_code')) payload.hsn_code = val('hsn_code');
    if (val('gst_rate') !== '') payload.gst_rate = Number(val('gst_rate'));

    setCreating(true);

    try {
      const response = await api.post('/admin/inventory/quick-create', payload);
      const created = response.data.variants || [response.data];
      const cost = val('unit_cost');

      created.forEach((v) => {
        onAddLine({
          variant_uuid: v.uuid,
          sku: v.sku,
          barcode: v.barcode || null,
          product_name: name,
          variant_name: v.variant_name,
          quantity: '1',
          invoiced_quantity: '',
          unit_cost: cost,
          batch_no: '',
          expiry_date: '',
          mrp: String(v.mrp ?? ''),
          selling_price: String(v.selling_price ?? ''),
          gst_rate: val('gst_rate'),
          discount_amount: '',
          is_new: true,
        });
      });

      toast(`${created.length === 1 ? 'Item' : `${created.length} variants`} created and added — set the quantity${cost ? '' : ' and cost'} in the table below.`);
      onClose();
    } catch (error) {
      setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not create that item.' });
    } finally {
      setCreating(false);
    }
  }

  const badgeText = profile ? `${profile.label} item` : 'Pick a category';
  const gridCount = sizes.length
    ? `${sizes.length * Math.max(colours.length, 1)} variant${sizes.length * Math.max(colours.length, 1) === 1 ? '' : 's'} will be created (${sizes.length} size${sizes.length === 1 ? '' : 's'}${colours.length ? ` × ${colours.length} colour${colours.length === 1 ? '' : 's'}` : ''}), each with its own barcode and stock.`
    : 'Tick at least one size.';

  return (
    <div className="pi-card pi-card--accent">
      <div className="pi-card__body">
        <div className="pi-row-between">
          <h3 className="pi-h6">New item</h3>
          <span className="pi-type-badge">{badgeText}</span>
        </div>

        <div className="pi-grid pi-grid-2">
          <label className="pi-field">
            <span>Category</span>
            <div className="pi-inline-group">
              <select value={topUuid} onChange={(e) => setTopUuid(e.target.value)}>
                <option value="">Select category…</option>
                {tops.map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
              </select>
              <button type="button" className="admin-btn" onClick={() => openCategoryAdd(true)}>+ New</button>
            </div>
          </label>

          <label className="pi-field">
            <span>Sub-category</span>
            <div className="pi-inline-group">
              <select
                value={subUuid}
                disabled={!topUuid || subs.length === 0}
                onChange={(e) => setSubUuid(e.target.value)}
              >
                {!topUuid && <option value="">Select a category first</option>}
                {topUuid && subs.length === 0 && <option value="">No sub-categories — item goes directly in this category</option>}
                {topUuid && subs.length > 0 && (
                  <>
                    <option value="">Select sub-category…</option>
                    {subs.map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
                  </>
                )}
              </select>
              <button type="button" className="admin-btn" disabled={!topUuid} onClick={() => openCategoryAdd(false)}>+ New</button>
            </div>
          </label>
        </div>

        {catAdd && (
          <div className="pi-cat-add">
            <label className="pi-field pi-field-grow">
              <span>{catAdd.isTop ? 'New category name' : 'New sub-category name'}</span>
              <input
                autoFocus
                maxLength={120}
                value={catAdd.name}
                onChange={(e) => setCatAdd({ ...catAdd, name: e.target.value })}
              />
            </label>
            {catAdd.isTop && (
              <label className="pi-field">
                <span>What kind of items?</span>
                <select value={catAdd.itemType} onChange={(e) => setCatAdd({ ...catAdd, itemType: e.target.value })}>
                  {Object.entries(PROFILES).map(([k, p]) => <option key={k} value={k}>{p.label}</option>)}
                </select>
              </label>
            )}
            <button type="button" className="admin-btn admin-btn--primary" disabled={catSaving} onClick={saveCategoryAdd}>
              {catSaving ? 'Saving…' : 'Save'}
            </button>
            <button type="button" className="admin-btn" onClick={() => setCatAdd(null)}>Cancel</button>
          </div>
        )}

        <hr className="pi-hr" />

        {!profile ? (
          <div className="pi-muted">Choose a category to see the right fields for that kind of item.</div>
        ) : (
          <div className="pi-grid">
            {profile.mode === 'weighed' && (
              <>
                <div className="pi-field pi-field-full">
                  <div className="pi-radio-group">
                    <label><input type="radio" checked={mode === 'packed'} onChange={() => setMode('packed')} /> Packed — fixed pack sizes</label>
                    <label><input type="radio" checked={mode === 'loose'} onChange={() => setMode('loose')} /> Loose — weighed / measured for the customer</label>
                  </div>
                </div>
                <label className="pi-field pi-field-lg">
                  <span>Item name</span>
                  <input required maxLength={180} value={val('product_name')} onChange={(e) => setField('product_name', e.target.value)} />
                </label>

                {mode === 'loose' ? (
                  <>
                    <label className="pi-field">
                      <span>Sold by</span>
                      <select value={unit} onChange={(e) => setUnit(e.target.value)}>
                        {profile.units.map((u) => <option key={u} value={u}>{UNIT_NAMES[u]}</option>)}
                      </select>
                    </label>
                    <label className="pi-field">
                      <span>Barcode</span>
                      <input placeholder="Auto" value={val('barcode')} onChange={(e) => setField('barcode', e.target.value)} />
                    </label>
                    <label className="pi-field">
                      <span>MRP per {UNIT_NAMES[unit]}</span>
                      <input type="number" step="0.01" min="0.01" value={val('mrp')} onChange={(e) => setField('mrp', e.target.value)} />
                    </label>
                    <label className="pi-field">
                      <span>Selling price per {UNIT_NAMES[unit]}</span>
                      <input type="number" step="0.01" min="0.01" value={val('selling_price')} onChange={(e) => setField('selling_price', e.target.value)} />
                    </label>
                    <div className="pi-field pi-field-lg pi-muted">
                      Stock is counted in {UNIT_NAMES[unit]}s. Enter the quantity received below in the same unit; the counter can then sell any amount at this price.
                    </div>
                  </>
                ) : (
                  <>
                    <label className="pi-field pi-field-lg">
                      <span>{type === 'oils' ? 'Pack size (e.g. "1 L pouch")' : 'Pack size (e.g. "500 g pouch")'}</span>
                      <input required maxLength={80} value={val('variant_name')} onChange={(e) => setField('variant_name', e.target.value)} />
                    </label>
                    <label className="pi-field">
                      <span>{type === 'oils' ? 'Weight (g) — approx.' : 'Weight (g)'}</span>
                      <input type="number" min="1" max="100000" required value={val('weight_grams')} onChange={(e) => setField('weight_grams', e.target.value)} />
                    </label>
                    <label className="pi-field">
                      <span>Pack type</span>
                      <select value={val('pack_type') || 'pouch'} onChange={(e) => setField('pack_type', e.target.value)}>
                        <option value="pouch">Pouch</option>
                        <option value="jar">Jar</option>
                        <option value="box">Box</option>
                        <option value="tin">Tin</option>
                        <option value="gift_box">Gift box</option>
                        <option value="refill">Refill</option>
                        <option value="other">Bottle / other</option>
                      </select>
                    </label>
                    <label className="pi-field">
                      <span>Barcode</span>
                      <input placeholder="Auto" value={val('barcode')} onChange={(e) => setField('barcode', e.target.value)} />
                    </label>
                    <label className="pi-field">
                      <span>MRP</span>
                      <input type="number" step="0.01" min="0.01" value={val('mrp')} onChange={(e) => setField('mrp', e.target.value)} />
                    </label>
                    <label className="pi-field">
                      <span>Selling price</span>
                      <input type="number" step="0.01" min="0.01" value={val('selling_price')} onChange={(e) => setField('selling_price', e.target.value)} />
                    </label>
                  </>
                )}
              </>
            )}

            {profile.mode === 'grid' && (
              <>
                <label className="pi-field pi-field-lg">
                  <span>Item name</span>
                  <input required maxLength={180} placeholder="e.g. Cotton round-neck T-shirt" value={val('product_name')} onChange={(e) => setField('product_name', e.target.value)} />
                </label>
                <label className="pi-field">
                  <span>MRP (same for all)</span>
                  <input type="number" step="0.01" min="0.01" value={val('mrp')} onChange={(e) => setField('mrp', e.target.value)} />
                </label>
                <label className="pi-field">
                  <span>Selling price (same for all)</span>
                  <input type="number" step="0.01" min="0.01" value={val('selling_price')} onChange={(e) => setField('selling_price', e.target.value)} />
                </label>

                <div className="pi-field pi-field-full">
                  <span>Sizes received (tick every size)</span>
                  <div className="pi-chip-row">
                    {sizeChips.map((v) => (
                      <button type="button" key={v} className={`pi-chip ${sizes.includes(v) ? 'pi-chip--on' : ''}`} onClick={() => toggleChip('size', v)}>{v}</button>
                    ))}
                  </div>
                  <div className="pi-inline-group pi-mt4">
                    <input placeholder="Other size…" maxLength={60} value={chipInput.size} onChange={(e) => setChipInput((c) => ({ ...c, size: e.target.value }))} />
                    <button type="button" className="admin-btn" onClick={() => addChip('size')}>Add</button>
                  </div>
                </div>

                <div className="pi-field pi-field-full">
                  <span>Colours (optional — one variant per size × colour)</span>
                  <div className="pi-chip-row">
                    {colourChips.map((v) => (
                      <button type="button" key={v} className={`pi-chip ${colours.includes(v) ? 'pi-chip--on' : ''}`} onClick={() => toggleChip('color', v)}>{v}</button>
                    ))}
                  </div>
                  <div className="pi-inline-group pi-mt4">
                    <input placeholder="Other colour…" maxLength={60} value={chipInput.color} onChange={(e) => setChipInput((c) => ({ ...c, color: e.target.value }))} />
                    <button type="button" className="admin-btn" onClick={() => addChip('color')}>Add</button>
                  </div>
                </div>

                <div className="pi-field pi-field-full pi-muted">{gridCount}</div>
              </>
            )}

            {profile.mode === 'plain' && (
              <>
                <label className="pi-field pi-field-lg">
                  <span>Item name</span>
                  <input required maxLength={180} value={val('product_name')} onChange={(e) => setField('product_name', e.target.value)} />
                </label>
                <label className="pi-field pi-field-lg">
                  <span>Variant / pack (optional, e.g. "Pack of 10")</span>
                  <input maxLength={80} value={val('variant_name')} onChange={(e) => setField('variant_name', e.target.value)} />
                </label>
                <label className="pi-field">
                  <span>Barcode</span>
                  <input placeholder="Auto" value={val('barcode')} onChange={(e) => setField('barcode', e.target.value)} />
                </label>
                <label className="pi-field">
                  <span>MRP</span>
                  <input type="number" step="0.01" min="0.01" value={val('mrp')} onChange={(e) => setField('mrp', e.target.value)} />
                </label>
                <label className="pi-field">
                  <span>Selling price</span>
                  <input type="number" step="0.01" min="0.01" value={val('selling_price')} onChange={(e) => setField('selling_price', e.target.value)} />
                </label>
                <label className="pi-field">
                  <span>Weight (g) (optional)</span>
                  <input type="number" min="1" max="100000" value={val('weight_grams')} onChange={(e) => setField('weight_grams', e.target.value)} />
                </label>
              </>
            )}

            <label className="pi-field">
              <span>Brand (optional)</span>
              <input maxLength={120} value={val('brand')} onChange={(e) => setField('brand', e.target.value)} />
            </label>
            <label className="pi-field">
              <span>GST %</span>
              <input type="number" step="0.01" min="0" max="28" value={val('gst_rate')} onChange={(e) => setField('gst_rate', e.target.value)} />
            </label>
            <label className="pi-field">
              <span>HSN code (optional)</span>
              <input maxLength={15} value={val('hsn_code')} onChange={(e) => setField('hsn_code', e.target.value)} />
            </label>
            <label className="pi-field">
              <span>Cost price (each)</span>
              <input type="number" step="0.01" min="0" placeholder="Optional" value={val('unit_cost')} onChange={(e) => setField('unit_cost', e.target.value)} />
            </label>
          </div>
        )}

        {feedback && <div className={`pi-feedback pi-feedback--${feedback.tone}`}>{feedback.text}</div>}

        <div className="pi-toolbar">
          <button type="button" className="admin-btn admin-btn--primary" disabled={!profile || creating} onClick={handleCreate}>
            {creating ? 'Creating…' : 'Create and add'}
          </button>
          <button type="button" className="admin-btn" onClick={onClose}>Cancel</button>
        </div>
      </div>
    </div>
  );
}
