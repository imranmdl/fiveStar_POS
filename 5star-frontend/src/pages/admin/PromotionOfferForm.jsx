/**
 * Create/edit form for an automatic offer, plus its "applies to" scope
 * section — split out of Promotions.jsx because of how much the fields
 * shift depending on discount type (percentage / flat / free delivery /
 * buy-X-get-Y) and whether an offer already exists (scope only applies to a
 * saved offer).
 *
 * Ported from admin/assets/page-promotions.js's offer editor + scope code.
 */
import { useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const BLANK = {
  code: '',
  title: '',
  discount_type: 'free_items',
  discount_value: '',
  max_discount_amount: '',
  buy_quantity: '1',
  get_quantity: '1',
  free_item_scope: 'cheapest_eligible',
  max_free_items_per_order: '4',
  min_order_value: '',
  min_quantity: '',
  usage_limit: '',
  audience: 'all',
  offer_type: 'festival',
  starts_date: '',
  starts_time: '00:00',
  ends_date: '',
  ends_time: '23:59',
};

/** How an offer is presented on the storefront (BOGO is set by its benefit). */
const OFFER_KINDS = [
  ['festival', 'Regular offer'],
  ['deal_of_day', 'Deal of the Day — on the home page with a countdown'],
  ['flash_sale', 'Flash sale'],
];

/**
 * offer comes straight from OfferService::present() — discount_type/value,
 * min_order_value etc. live nested under offer.discount, and the dates
 * under offer.schedule, not as flat top-level properties.
 */
function fieldsFromOffer(offer) {
  const discount = offer.discount || {};
  const schedule = offer.schedule || {};
  const usage = offer.usage || {};

  return {
    code: offer.code || '',
    title: offer.title || '',
    discount_type: discount.type || 'percentage',
    discount_value: discount.value ?? '',
    max_discount_amount: discount.max_amount ?? '',
    buy_quantity: offer.buy_quantity ?? 1,
    get_quantity: offer.get_quantity ?? 1,
    free_item_scope: offer.free_item_scope || 'cheapest_eligible',
    max_free_items_per_order: offer.max_free_items_per_order ?? 4,
    min_order_value: discount.min_order_value ?? '',
    min_quantity: discount.min_quantity ?? '',
    usage_limit: usage.limit ?? '',
    audience: offer.audience || 'all',
    offer_type: offer.offer_type === 'bogo' ? 'festival' : (offer.offer_type || 'festival'),
    starts_date: String(schedule.starts_date || '').slice(0, 10),
    starts_time: String(schedule.starts_date || '').slice(11, 16) || '00:00',
    ends_date: String(schedule.ends_date || '').slice(0, 10),
    ends_time: String(schedule.ends_date || '').slice(11, 16) || '23:59',
  };
}

export default function OfferForm({ offer, prefill, onCancel, onSaved }) {
  const editing = Boolean(offer);

  const [fields, setFields] = useState(() => {
    if (offer) return fieldsFromOffer(offer);
    if (prefill) {
      return {
        ...BLANK,
        discount_type: 'percentage',
        title: prefill.title || '',
        discount_value: prefill.discount_value || '',
        max_discount_amount: prefill.discount_value ? '500' : '',
      };
    }
    return BLANK;
  });
  const [busy, setBusy] = useState(false);

  const set = (name) => (event) => setFields((f) => ({ ...f, [name]: event.target.value }));

  const isBogo = fields.discount_type === 'free_items';
  const needsValue = fields.discount_type === 'percentage' || fields.discount_type === 'flat';
  const isPercentage = fields.discount_type === 'percentage';
  // updateOffer() doesn't accept these — they can only be set at creation, so
  // editing an existing BOGO offer shows them (staff should see what's
  // configured) but disabled, rather than silently ignoring a change.
  const bogoLocked = editing && isBogo;

  async function handleSubmit(event) {
    event.preventDefault();
    const payload = {};

    Object.entries(fields).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) payload[key] = value;
    });
    // Buy-X-get-Y is its own type; otherwise keep the kind chosen above, so
    // editing a Deal of the Day no longer turns it into a regular offer.
    payload.offer_type = fields.discount_type === 'free_items' ? 'bogo' : (fields.offer_type || 'festival');
    delete payload.starts_time;
    delete payload.ends_time;

    payload.code = String(payload.code || fields.code || '').toUpperCase();

    // Date + time inputs; the API expects a datetime. Deals of the Day count
    // down to the exact end time shown here.
    if (payload.starts_date) payload.starts_date = `${payload.starts_date} ${fields.starts_time || '00:00'}:00`;
    if (payload.ends_date) payload.ends_date = `${payload.ends_date} ${fields.ends_time || '23:59'}:${fields.ends_time && fields.ends_time !== '23:59' ? '00' : '59'}`;

    if (payload.discount_type !== 'free_items') {
      delete payload.buy_quantity;
      delete payload.get_quantity;
      delete payload.free_item_scope;
      delete payload.max_free_items_per_order;
    }

    if (editing) {
      // The update endpoint doesn't accept these — sending them is harmless
      // (silently ignored server-side) but omitting them here is clearer
      // about what this save actually changes.
      delete payload.code;
      delete payload.buy_quantity;
      delete payload.get_quantity;
      delete payload.free_item_scope;
      delete payload.max_free_items_per_order;
    }

    setBusy(true);
    try {
      if (editing) {
        await api.patch(`/admin/offers/${encodeURIComponent(offer.uuid)}`, payload);
        toast('Offer updated.');
      } else {
        await api.post('/admin/offers', payload);
        toast(`Offer ${payload.code} created. Activate it when you are ready.`);
      }
      onSaved();
    } catch (error) {
      reportError(error);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="promo-card">
      <div className="promo-card__header">{editing ? `Edit offer: ${offer.title}` : 'New automatic offer'}</div>
      <div className="promo-card__body">
        <p className="promo-table-note">
          An offer applies on its own, with no code to type. One coupon and one offer can
          both apply to an order — whichever offer is best for the customer wins.
        </p>

        <form className="promo-form-grid" onSubmit={handleSubmit}>
          <div className="promo-field">
            <label htmlFor="offer_code">Reference *</label>
            <input id="offer_code" required minLength={3} maxLength={40} placeholder="DIWALIBOGO"
                   style={{ textTransform: 'uppercase' }} disabled={editing}
                   value={fields.code} onChange={set('code')} />
            <div className="promo-hint">{editing ? "Can't be changed after an offer is created." : 'Internal. Customers never type it.'}</div>
          </div>

          <div className="promo-field">
            <label htmlFor="offer_title">What the customer sees *</label>
            <input id="offer_title" required minLength={3} placeholder="Buy one get one free on spices"
                   value={fields.title} onChange={set('title')} />
          </div>

          {!isBogo && (
            <div className="promo-field">
              <label htmlFor="offer_kind">Show as</label>
              <select id="offer_kind" value={fields.offer_type} onChange={set('offer_type')}>
                {OFFER_KINDS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
              <div className="promo-hint">
                A Deal of the Day appears in the home page's "Deals of the Day" row with a live countdown to its end
                time. Choose its products under "Which products" after saving.
              </div>
            </div>
          )}

          <div className="promo-field">
            <label htmlFor="offer_discount_type">Benefit *</label>
            <select id="offer_discount_type" required value={fields.discount_type} onChange={set('discount_type')}>
              <option value="free_items">Buy X get Y free</option>
              <option value="percentage">Percentage off</option>
              <option value="flat">Fixed amount off</option>
              <option value="free_delivery">Free delivery</option>
            </select>
          </div>

          {needsValue && (
            <div className="promo-field">
              <label htmlFor="offer_discount_value">Value</label>
              <input id="offer_discount_value" type="number" step="0.01" min="0" required={needsValue}
                     value={fields.discount_value} onChange={set('discount_value')} />
            </div>
          )}

          {isPercentage && (
            <div className="promo-field">
              <label htmlFor="offer_max_discount">Maximum discount *</label>
              <input id="offer_max_discount" type="number" step="0.01" min="1" required={isPercentage}
                     value={fields.max_discount_amount} onChange={set('max_discount_amount')} />
              <div className="promo-hint">Required for a percentage offer — caps what an uncapped % could take off a large order.</div>
            </div>
          )}

          {isBogo && (
            <div className="promo-field">
              <label htmlFor="buy_quantity">Buy *</label>
              <input id="buy_quantity" type="number" min="1" max="100" disabled={bogoLocked}
                     required={isBogo && !bogoLocked} value={fields.buy_quantity} onChange={set('buy_quantity')} />
            </div>
          )}

          {isBogo && (
            <div className="promo-field">
              <label htmlFor="get_quantity">Get free *</label>
              <input id="get_quantity" type="number" min="1" max="100" disabled={bogoLocked}
                     required={isBogo && !bogoLocked} value={fields.get_quantity} onChange={set('get_quantity')} />
            </div>
          )}

          {isBogo && (
            <div className="promo-field">
              <label htmlFor="free_item_scope">Which items are free</label>
              <select id="free_item_scope" disabled={bogoLocked} value={fields.free_item_scope} onChange={set('free_item_scope')}>
                <option value="cheapest_eligible">The cheapest in the basket</option>
                <option value="same_variant">Same pack the customer bought</option>
              </select>
              <div className="promo-hint">
                Cheapest is the usual choice — giving away the dearest item on a mixed basket costs far more than intended.
              </div>
            </div>
          )}

          {isBogo && (
            <div className="promo-field">
              <label htmlFor="max_free_items_per_order">Free items per order</label>
              <input id="max_free_items_per_order" type="number" min="1" max="1000" disabled={bogoLocked}
                     value={fields.max_free_items_per_order} onChange={set('max_free_items_per_order')} />
              <div className="promo-hint">Without a limit, a fifty-unit order claims twenty-five free.</div>
            </div>
          )}

          <div className="promo-field">
            <label htmlFor="offer_min_order">Minimum order value</label>
            <input id="offer_min_order" type="number" step="0.01" min="0"
                   value={fields.min_order_value} onChange={set('min_order_value')} />
          </div>

          {!isBogo && (
            <div className="promo-field">
              <label htmlFor="offer_min_quantity">Minimum quantity</label>
              <input id="offer_min_quantity" type="number" step="1" min="1"
                     value={fields.min_quantity} onChange={set('min_quantity')} />
              <div className="promo-hint">Of eligible items combined, e.g. 3 to require "buy 3 or more".</div>
            </div>
          )}

          <div className="promo-field">
            <label htmlFor="offer_usage_limit">Usage limit</label>
            <input id="offer_usage_limit" type="number" step="1" min="1"
                   value={fields.usage_limit} onChange={set('usage_limit')} />
            <div className="promo-hint">Total uses allowed, online + POS combined. Blank = unlimited.</div>
          </div>

          <div className="promo-field">
            <label htmlFor="offer_audience">Who can use it</label>
            <select id="offer_audience" value={fields.audience} onChange={set('audience')}>
              <option value="all">Anyone</option>
              <option value="new_customers">First-time customers only</option>
            </select>
          </div>

          <div className="promo-field">
            <label htmlFor="offer_starts">Starts</label>
            <div style={{ display: 'flex', gap: 8 }}>
              <input id="offer_starts" type="date" value={fields.starts_date} onChange={set('starts_date')} />
              <input aria-label="Start time" type="time" value={fields.starts_time} onChange={set('starts_time')} />
            </div>
          </div>

          <div className="promo-field">
            <label htmlFor="offer_ends">Ends *</label>
            <div style={{ display: 'flex', gap: 8 }}>
              <input id="offer_ends" type="date" required value={fields.ends_date} onChange={set('ends_date')} />
              <input aria-label="End time" type="time" value={fields.ends_time} onChange={set('ends_time')} />
            </div>
            <div className="promo-hint">Required to activate — an automatic discount with no end date runs forever.</div>
          </div>

          <div className="promo-actions">
            <button className="admin-btn admin-btn--primary" type="submit" disabled={busy}>
              {busy ? (editing ? 'Saving…' : 'Creating…') : (editing ? 'Save changes' : 'Create offer')}
            </button>
            <button className="admin-btn" type="button" onClick={onCancel}>Cancel</button>
            {!editing && <span className="promo-table-note">Created as a draft. Activate it when ready.</span>}
          </div>
        </form>

        {editing && <OfferScope offer={offer} />}
      </div>
    </div>
  );
}

/**
 * "Applies to" scope: item/category targeting for an existing offer. Only
 * meaningful once an offer exists (setTargets() operates on a saved offer),
 * so this whole section never shows while creating a new one.
 */
function OfferScope({ offer }) {
  const [mode, setMode] = useState('all');
  const [loaded, setLoaded] = useState(false);
  const [hint, setHint] = useState('');
  const [saving, setSaving] = useState(false);

  const [categories, setCategories] = useState(null); // flattened {slug,name,depth}[]
  const [products, setProducts] = useState(null);
  const [productFilter, setProductFilter] = useState('');
  const [selectedCategories, setSelectedCategories] = useState(new Set());
  const [selectedProducts, setSelectedProducts] = useState(new Set());
  const [knownProductNames, setKnownProductNames] = useState(new Map());

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const response = await api.get(`/admin/offers/${encodeURIComponent(offer.uuid)}/targets`);
        if (cancelled) return;
        const targets = response.data;

        setSelectedCategories(new Set(targets.categories.map((c) => c.slug)));
        setSelectedProducts(new Set(targets.products.map((p) => p.slug)));
        setKnownProductNames(new Map(targets.products.map((p) => [p.slug, p.name])));

        const nextMode = targets.applies_to === 'categories' || targets.applies_to === 'products'
          ? targets.applies_to
          : 'all';
        setMode(nextMode);
        setLoaded(true);
      } catch (error) {
        if (!cancelled) reportError(error);
      }
    }

    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offer.uuid]);

  useEffect(() => {
    if (mode === 'categories' && categories === null) {
      const flatten = (list, depth = 0) => list.flatMap((item) => [
        { slug: item.slug, name: item.name, depth },
        ...flatten(item.children || [], depth + 1),
      ]);

      api.get('/admin/categories', { per_page: 200 }).then((response) => {
        setCategories(flatten(response.data.categories || response.data || []));
      }).catch(() => setCategories([]));
    }

    // /admin/products doesn't actually filter on a search param server-side,
    // so the whole catalogue is fetched once and filtered client-side.
    if (mode === 'products' && products === null) {
      api.get('/admin/products', { per_page: 200 }).then((response) => {
        const list = response.data || [];
        setProducts(list);
        setKnownProductNames((prev) => {
          const next = new Map(prev);
          list.forEach((p) => next.set(p.slug, p.name));
          return next;
        });
      }).catch(() => setProducts([]));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode]);

  function toggleCategory(slug) {
    setSelectedCategories((prev) => {
      const next = new Set(prev);
      if (next.has(slug)) next.delete(slug); else next.add(slug);
      return next;
    });
  }

  function toggleProduct(slug) {
    setSelectedProducts((prev) => {
      const next = new Set(prev);
      if (next.has(slug)) next.delete(slug); else next.add(slug);
      return next;
    });
  }

  const visibleProducts = useMemo(() => {
    if (!products) return [];
    const needle = productFilter.trim().toLowerCase();
    const filtered = needle === '' ? products : products.filter((p) => p.name.toLowerCase().includes(needle));

    // A checked product stays visible even once the typed filter no longer
    // matches it — unchecking it should be a deliberate click, not an
    // accident of what the current search text happens to match.
    const checkedElsewhere = Array.from(selectedProducts)
      .filter((slug) => !filtered.some((p) => p.slug === slug))
      .map((slug) => ({ slug, name: knownProductNames.get(slug) || slug }));

    return [...filtered, ...checkedElsewhere];
  }, [products, productFilter, selectedProducts, knownProductNames]);

  async function saveScope() {
    if (mode === 'categories' && selectedCategories.size === 0) {
      toast('Pick at least one category, or switch to "All items".', 'danger');
      return;
    }
    if (mode === 'products' && selectedProducts.size === 0) {
      toast('Pick at least one product, or switch to "All items".', 'danger');
      return;
    }

    const payload = {
      category_slugs: mode === 'categories' ? Array.from(selectedCategories) : [],
      product_slugs: mode === 'products' ? Array.from(selectedProducts) : [],
    };

    setSaving(true);
    try {
      await api.put(`/admin/offers/${encodeURIComponent(offer.uuid)}/targets`, payload);
      toast('Offer scope updated.');
      setHint('Saved.');
    } catch (error) {
      reportError(error);
    } finally {
      setSaving(false);
    }
  }

  if (!loaded) return <div className="promo-scope"><p className="promo-table-note">Loading scope…</p></div>;

  return (
    <div className="promo-scope">
      <h2 style={{ fontSize: 15, margin: '0 0 6px' }}>Applies to</h2>
      <p className="promo-table-note" style={{ marginBottom: 10 }}>
        Which items this offer discounts. Saves separately from the form above, and takes effect immediately.
      </p>

      <div className="promo-scope__modes">
        {[['all', 'All items'], ['categories', 'Specific categories'], ['products', 'Specific products']].map(([value, label]) => (
          <button key={value} type="button"
                  className={`promo-scope__mode-btn${mode === value ? ' promo-scope__mode-btn--active' : ''}`}
                  onClick={() => setMode(value)}>{label}</button>
        ))}
      </div>

      {mode === 'categories' && (
        <div className="promo-checklist">
          {categories === null ? 'Loading…' : categories.length === 0 ? (
            <div className="promo-table-note">No categories yet.</div>
          ) : categories.map((c) => (
            <label key={c.slug}>
              <input type="checkbox" checked={selectedCategories.has(c.slug)} onChange={() => toggleCategory(c.slug)} />
              {' '}{'— '.repeat(c.depth)}{c.name}
            </label>
          ))}
        </div>
      )}

      {mode === 'products' && (
        <>
          <input className="promo-scope__filter" type="search" placeholder="Filter products by name…"
                 value={productFilter} onChange={(e) => setProductFilter(e.target.value)} />
          <div className="promo-checklist">
            {products === null ? 'Loading…' : visibleProducts.length === 0 ? (
              <div className="promo-table-note">No products match.</div>
            ) : visibleProducts.map((p) => (
              <label key={p.slug}>
                <input type="checkbox" checked={selectedProducts.has(p.slug)} onChange={() => toggleProduct(p.slug)} />
                {' '}{p.name}
              </label>
            ))}
          </div>
        </>
      )}

      <button className="admin-btn admin-btn--primary" type="button" disabled={saving} onClick={saveScope}>
        {saving ? 'Saving…' : 'Save scope'}
      </button>
      <span className="promo-table-note" style={{ marginLeft: 10 }}>{hint}</span>
    </div>
  );
}
