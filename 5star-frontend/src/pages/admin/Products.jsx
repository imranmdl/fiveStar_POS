import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { StatusBadge, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import ProductEditor from './ProductEditor.jsx';
import ProductCsvImport from './ProductCsvImport.jsx';
import './Products.css';

/** Catalogue: what is on sale, and switching things on and off. Ported from admin/assets/page-products.js. */

const DISCOUNT_TYPES = [
  ['percentage', 'Percentage off'],
  ['flat', 'Flat amount off'],
  ['free_items', 'Buy X get Y free'],
];

const AUDIENCES = [
  ['all', 'Every customer'],
  ['new_customers', 'New customers only'],
  ['specific_customer', 'One specific customer'],
];

function defaultEndsDate() {
  return new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

/**
 * Quick "attach an offer to this one product" panel — a shortcut into the same
 * offers/offer_targets campaign system the full Promotions screen manages, so
 * anything created here shows up there too (and, once live, in POS billing).
 */
function AddOfferPanel({ product, onClose, onCreated }) {
  const [discountType, setDiscountType] = useState('percentage');
  const [audience, setAudience] = useState('all');
  const [title, setTitle] = useState(`${product.name} offer`);
  const [discountValue, setDiscountValue] = useState('');
  const [maxDiscountAmount, setMaxDiscountAmount] = useState('');
  const [buyQuantity, setBuyQuantity] = useState('1');
  const [getQuantity, setGetQuantity] = useState('1');
  const [endsDate, setEndsDate] = useState(defaultEndsDate());
  const [channel, setChannel] = useState('all');
  const [customerIdentifier, setCustomerIdentifier] = useState('');
  const [saving, setSaving] = useState(false);

  const isPercentage = discountType === 'percentage';
  const isBogo = discountType === 'free_items';

  async function handleSubmit(event) {
    event.preventDefault();
    setSaving(true);

    let createdUuid = null;

    try {
      const code = `OFFER-${product.slug}-${Date.now().toString(36)}`.toUpperCase().slice(0, 40);
      const payload = {
        code,
        title,
        discount_type: discountType,
        discount_value: isBogo ? 0 : Number(discountValue),
        ends_date: endsDate,
        channel,
        audience,
      };
      if (isPercentage) payload.max_discount_amount = Number(maxDiscountAmount);
      if (isBogo) {
        payload.buy_quantity = Number(buyQuantity);
        payload.get_quantity = Number(getQuantity);
        payload.free_item_scope = 'same_variant';
      }
      if (audience === 'specific_customer') payload.customer_identifier = customerIdentifier;

      const created = await api.post('/admin/offers', payload);
      createdUuid = created.data.offer.uuid;

      await api.put(`/admin/offers/${encodeURIComponent(createdUuid)}/targets`, {
        product_slugs: [product.slug],
      });
      await api.post(`/admin/offers/${encodeURIComponent(createdUuid)}/status`, { status: 'active' });

      toast(`Offer added to ${product.name}.`);
      onCreated();
    } catch (error) {
      // A later step failed — don't leave an orphan draft offer behind.
      if (createdUuid) {
        try {
          await api.delete(`/admin/offers/${encodeURIComponent(createdUuid)}`);
        } catch {
          /* best effort */
        }
      }
      setSaving(false);
      toast(error.message || 'Could not add the offer.', 'danger');
    }
  }

  return (
    <div className="admin-overlay" onClick={onClose}>
      <div className="admin-overlay__panel" onClick={(event) => event.stopPropagation()}>
        <form onSubmit={handleSubmit}>
          <div className="admin-overlay__header">
            <h2 className="h6" style={{ margin: 0 }}>Add an offer — {product.name}</h2>
            <button type="button" className="admin-overlay__close" aria-label="Cancel" onClick={onClose}>&times;</button>
          </div>

          <div className="admin-overlay__body">
            <div className="admin-field">
              <label>Title</label>
              <input className="admin-input" maxLength={160} value={title} onChange={(e) => setTitle(e.target.value)} required />
            </div>

            <div className="admin-field-row">
              <div className="admin-field">
                <label>Discount type</label>
                <select className="admin-input" value={discountType} onChange={(e) => setDiscountType(e.target.value)}>
                  {DISCOUNT_TYPES.map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </div>

              {!isBogo && (
                <div className="admin-field">
                  <label>Value</label>
                  <input
                    className="admin-input"
                    type="number"
                    min="0"
                    step="0.01"
                    max={isPercentage ? 15 : undefined}
                    value={discountValue}
                    onChange={(e) => setDiscountValue(e.target.value)}
                    required
                  />
                  {isPercentage && (
                    <div className="admin-hint">Capped at 15%, whatever the margin check below allows.</div>
                  )}
                </div>
              )}
            </div>

            {isPercentage && (
              <div className="admin-field">
                <label>Maximum discount amount</label>
                <input
                  className="admin-input"
                  type="number"
                  min="1"
                  step="0.01"
                  value={maxDiscountAmount}
                  onChange={(e) => setMaxDiscountAmount(e.target.value)}
                  required
                />
                <div className="admin-hint">Required for a percentage discount, so it can never exceed this amount.</div>
              </div>
            )}

            {isBogo && (
              <div className="admin-field-row">
                <div className="admin-field">
                  <label>Buy</label>
                  <input className="admin-input" type="number" min="1" step="1" value={buyQuantity} onChange={(e) => setBuyQuantity(e.target.value)} />
                </div>
                <div className="admin-field">
                  <label>Get free</label>
                  <input className="admin-input" type="number" min="1" step="1" value={getQuantity} onChange={(e) => setGetQuantity(e.target.value)} />
                </div>
              </div>
            )}

            <div className="admin-field">
              <label>Offer ends</label>
              <input className="admin-input" type="date" value={endsDate} onChange={(e) => setEndsDate(e.target.value)} required />
            </div>

            <div className="admin-field">
              <label>Where it applies</label>
              <select className="admin-input" value={channel} onChange={(e) => setChannel(e.target.value)}>
                <option value="all">Online and POS (counter)</option>
                <option value="online">Online only</option>
                <option value="pos">POS (counter) only</option>
              </select>
            </div>

            <div className="admin-field">
              <label>Who it&apos;s for</label>
              <select className="admin-input" value={audience} onChange={(e) => setAudience(e.target.value)}>
                {AUDIENCES.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            {audience === 'specific_customer' && (
              <div className="admin-field">
                <label>Customer&apos;s mobile or email</label>
                <input
                  className="admin-input"
                  maxLength={150}
                  placeholder="9999999999 or name@example.com"
                  value={customerIdentifier}
                  onChange={(e) => setCustomerIdentifier(e.target.value)}
                  required
                />
              </div>
            )}
          </div>

          <div className="admin-overlay__footer">
            <button type="button" className="admin-btn" onClick={onClose}>Cancel</button>
            <button type="submit" className="admin-btn admin-btn--success" disabled={saving}>
              {saving ? 'Adding…' : 'Add offer'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function sourcingCell(sourcing) {
  if (!sourcing) return <span className="text-muted">—</span>;

  const added = String(sourcing.added_date || '').slice(0, 10);
  const vendors = sourcing.vendors || [];

  return (
    <>
      <div>
        Added {added}
        {sourcing.added_by && <span className="text-muted"> by {sourcing.added_by}</span>}
      </div>
      <div className={vendors.length ? '' : 'text-muted'}>
        {vendors.length ? (
          vendors.map((v, i) => (
            <span key={i}>
              {i > 0 && <br />}
              {v.name} <span className="text-muted">({String(v.last_purchase_date || '').slice(0, 10)})</span>
            </span>
          ))
        ) : (
          'No vendor yet'
        )}
      </div>
    </>
  );
}

function ProductRow({ product, offer, sourcing, onEdit, onToggle, onDelete, onAddOffer, busy }) {
  const pricing = product.pricing || {};
  const rating = product.rating || {};
  const categoryName = (product.category && product.category.name) || product.category_name;

  return (
    <tr>
      <td>
        <div className="fw-semibold">{product.name}</div>
        <div className="small text-muted">{product.slug}</div>
      </td>
      <td className="small">{categoryName || '—'}</td>
      <td className="small">{sourcingCell(sourcing)}</td>
      <td className="text-end">{formatMoney(pricing.min_price)}</td>
      <td className="text-center small">{pricing.variant_count ?? '—'}</td>
      <td className="text-center small">
        {Number(rating.count) > 0 ? (
          <>★ {Number(rating.average).toFixed(1)} ({rating.count})</>
        ) : (
          <span className="text-muted">—</span>
        )}
      </td>
      <td>
        <StatusBadge status={product.status === 'published' ? 'approved' : 'pending'} label={product.status} />
      </td>
      <td className="text-nowrap">
        {offer ? (
          <span className="review-chip review-chip--success" title={offer.title}>{offer.summary}</span>
        ) : (
          <button className="admin-btn" type="button" onClick={() => onAddOffer(product)}>+ Add offer</button>
        )}
      </td>
      <td className="text-end text-nowrap">
        <button className="admin-btn" type="button" onClick={() => onEdit(product.uuid)}>Edit</button>{' '}
        <button className="admin-btn" type="button" disabled={busy} onClick={() => onToggle(product)}>
          {product.status === 'published' ? 'Unpublish' : 'Publish'}
        </button>{' '}
        <button className="admin-btn admin-btn--danger-outline" type="button" disabled={busy} onClick={() => onDelete(product)}>
          Delete
        </button>
      </td>
    </tr>
  );
}

function ProductList() {
  const [, setSearchParams] = useSearchParams();
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [products, setProducts] = useState([]);
  const [offersByProduct, setOffersByProduct] = useState({});
  const [sourcingByProduct, setSourcingByProduct] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyUuid, setBusyUuid] = useState(null);
  const [offerFor, setOfferFor] = useState(null);
  const [showImport, setShowImport] = useState(false);
  const [importSetup, setImportSetup] = useState(null);
  const [importBusy, setImportBusy] = useState(false);
  const [exportBusy, setExportBusy] = useState(false);
  const [deleteAllBusy, setDeleteAllBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await api.get('/admin/products', { search, per_page: 50 });
      const list = response.data || [];
      setProducts(list);

      if (list.length > 0) {
        // Best effort: a product list with no offer badges is still useful, so a
        // failed lookup here should not block the page from rendering.
        try {
          const offerResponse = await api.get('/admin/offers/product-lookup', {
            product_uuids: list.map((p) => p.uuid).join(','),
          });
          setOffersByProduct(offerResponse.data || {});
        } catch {
          setOffersByProduct({});
        }

        try {
          const sourcingResponse = await api.get('/admin/products/sourcing', {
            product_uuids: list.map((p) => p.uuid).join(','),
          });
          setSourcingByProduct(sourcingResponse.data || {});
        } catch {
          setSourcingByProduct({});
        }
      }
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => {
    load();
  }, [load]);

  function submitSearch(event) {
    event.preventDefault();
    setSearch(searchDraft);
  }

  function goToEdit(uuid) {
    setSearchParams({ edit: uuid });
  }

  function goToNew() {
    setSearchParams({ new: '' });
  }

  async function toggleStatus(product) {
    // `status` is not a field /admin/products/{uuid} (PATCH) accepts — published/
    // draft/archived have their own dedicated actions, /publish and /archive.
    const publishing = product.status !== 'published';
    setBusyUuid(product.uuid);

    try {
      await api.post(`/admin/products/${encodeURIComponent(product.uuid)}/${publishing ? 'publish' : 'archive'}`);
      toast(publishing ? 'Product is now on sale.' : 'Product hidden from the shop.');
      await load();
    } catch (error) {
      toast(error.message || 'Could not update the product.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function deleteProduct(product) {
    if (!window.confirm(`Delete "${product.name}" and all its pack sizes? This removes it from the shop.`)) return;

    setBusyUuid(product.uuid);

    try {
      await api.delete(`/admin/products/${encodeURIComponent(product.uuid)}`);
      toast('Deleted successfully. View in Recycle Bin.');
      await load();
    } catch (error) {
      toast(error.message || 'Could not delete the product.', 'danger');
    } finally {
      setBusyUuid(null);
    }
  }

  async function deleteAll() {
    const typed = window.prompt(
      'This deletes EVERY product (published and draft) with all their pack sizes and photos, and removes them from the shop.\n\nType DELETE to confirm.',
    );
    if (typed !== 'DELETE') return;

    setDeleteAllBusy(true);
    let deleted = 0;
    let failed = 0;

    // The list is paged, so keep going until nothing is left (or nothing more can be deleted).
    for (let round = 0; round < 40; round += 1) {
      const batch = (await api.get('/admin/products', { per_page: 50 })).data || [];
      if (batch.length === 0) break;

      let progressed = 0;

      for (const p of batch) {
        try {
          await api.delete(`/admin/products/${encodeURIComponent(p.uuid)}`);
          deleted += 1;
          progressed += 1;
        } catch {
          failed += 1;
        }
      }

      if (progressed === 0) break;
    }

    setDeleteAllBusy(false);
    toast(
      failed ? `${deleted} deleted, ${failed} could not be deleted.` : `${deleted} product(s) deleted.`,
      failed ? 'warning' : 'success',
    );
    await load();
  }

  async function openImport() {
    if (showImport) {
      setShowImport(false);
      return;
    }

    setImportBusy(true);

    try {
      const setup = (await api.get('/admin/inventory/setup')).data;
      setImportSetup(setup);
      setShowImport(true);
    } catch (error) {
      toast(error.message || 'Could not open the importer.', 'danger');
    } finally {
      setImportBusy(false);
    }
  }

  async function exportCsv() {
    setExportBusy(true);

    try {
      const blob = await api.downloadFile('/admin/export/products');
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'products.csv';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast(error.message || 'Could not export products.', 'danger');
    } finally {
      setExportBusy(false);
    }
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Products</h1>
        <div className="admin-toolbar" style={{ margin: 0 }}>
          <button className="admin-btn admin-btn--primary" type="button" onClick={goToNew}>Add a product</button>
          <button className="admin-btn" type="button" disabled={importBusy} onClick={openImport}>
            {importBusy ? 'Opening…' : 'Import products'}
          </button>
          {!search && (
            <button className="admin-btn admin-btn--danger-outline" type="button" disabled={deleteAllBusy} onClick={deleteAll}>
              Delete all
            </button>
          )}
          <button className="admin-btn" type="button" disabled={exportBusy} onClick={exportCsv}>
            {exportBusy ? 'Preparing…' : 'Export CSV'}
          </button>
          <form onSubmit={submitSearch} style={{ display: 'flex', gap: 8 }}>
            <input
              className="admin-input"
              style={{ width: '18rem' }}
              placeholder="Search by product, category or pack/SKU"
              value={searchDraft}
              onChange={(e) => setSearchDraft(e.target.value)}
            />
            <button className="admin-btn" type="submit">Search</button>
          </form>
        </div>
      </div>

      {showImport && importSetup && (
        <div className="mb-3">
          <ProductCsvImport
            setup={importSetup}
            onClose={() => setShowImport(false)}
            onDone={() => load()}
          />
        </div>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} />
      ) : products.length === 0 ? (
        <EmptyState
          title={search ? 'Nothing matched' : 'No products yet'}
          hint={search ? 'Try a different term.' : 'Use "Add a product" to list your first item.'}
        />
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="admin-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Added / Vendor</th>
                <th className="text-end">From</th>
                <th className="text-center">Packs</th>
                <th className="text-center">Rating</th>
                <th>Status</th>
                <th>Offer</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {products.map((product) => (
                <ProductRow
                  key={product.uuid}
                  product={product}
                  offer={offersByProduct[product.uuid]}
                  sourcing={sourcingByProduct[product.uuid]}
                  busy={busyUuid === product.uuid}
                  onEdit={goToEdit}
                  onToggle={toggleStatus}
                  onDelete={deleteProduct}
                  onAddOffer={setOfferFor}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="text-muted small mt-3">
        Publishing controls whether a product is shown in the shop. Stock levels are tracked separately — see Inventory.
      </p>

      {offerFor && (
        <AddOfferPanel
          product={offerFor}
          onClose={() => setOfferFor(null)}
          onCreated={() => {
            setOfferFor(null);
            load();
          }}
        />
      )}
    </div>
  );
}

export default function Products() {
  const [searchParams, setSearchParams] = useSearchParams();
  const edit = searchParams.get('edit');
  const isNew = searchParams.has('new');

  // The editor takes over the page when ?edit= or ?new is present, so the list
  // and the form never fight over the same container — mirrors mountProductEditor.
  if (edit || isNew) {
    return (
      <ProductEditor
        identifier={edit}
        onClose={() => setSearchParams({})}
        onCreated={(uuid) => setSearchParams({ edit: uuid })}
      />
    );
  }

  return <ProductList />;
}
