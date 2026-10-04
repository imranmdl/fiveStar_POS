/**
 * Campaign pages tab. Ported from page-content.js's renderCollections() and
 * renderCollectionEditor() — a festival or sale page built from products
 * already in the catalogue, then pointed at by an advert.
 */
import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState, EmptyState } from '../../components/admin/shared.jsx';
import './Content.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const TEMPLATES = [
  ['grid', 'Grid — a plain row of products'],
  ['spotlight', 'Spotlight — one product large, then the rest'],
  ['story', 'Story — introduction, products, a closing button'],
  ['gift', 'Gift — hamper framing with gifting reassurances'],
];

const EMPTY_FORM = { title: '', subtitle: '', template: 'grid', intro: '', starts_date: '', ends_date: '' };

function statusBadge(collection) {
  if (collection.is_live) return <span className="status-badge status-badge--success">Live</span>;
  if (collection.has_expired) return <span className="status-badge status-badge--secondary">Ended</span>;
  return <span className="status-badge status-badge--warning">{collection.status}</span>;
}

function CollectionsList({ onOpen }) {
  const [collections, setCollections] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/collections');
      setCollections(response.data.collections || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const set = (name) => (event) => setForm((f) => ({ ...f, [name]: event.target.value }));

  async function handleCreate(event) {
    event.preventDefault();
    const payload = {};
    Object.entries(form).forEach(([key, value]) => {
      if (value !== '') payload[key] = value;
    });
    if (payload.starts_date) payload.starts_date = `${payload.starts_date} 00:00:00`;
    if (payload.ends_date) payload.ends_date = `${payload.ends_date} 23:59:59`;

    setCreating(true);
    try {
      const response = await api.post('/admin/collections', payload);
      toast('Page created. Now choose what goes on it.');
      setForm(EMPTY_FORM);
      onOpen(response.data.collection.slug);
    } catch (err) {
      reportError(err);
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="content-grid">
      <div className="content-card content-card--wide">
        <div className="content-card__header">Campaign pages</div>
        {loading ? (
          <LoadingState />
        ) : error ? (
          <div className="content-card__body"><ErrorState error={error} /></div>
        ) : collections.length === 0 ? (
          <div className="content-card__body">
            <EmptyState
              title="No campaign pages yet"
              hint="Build one for a festival or a sale, then point an advert at it."
            />
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Page</th><th>Layout</th><th>Items</th><th>Views</th><th>Status</th><th></th>
                </tr>
              </thead>
              <tbody>
                {collections.map((collection) => (
                  <tr key={collection.slug}>
                    <td>
                      <div style={{ fontWeight: 600 }}>{collection.title}</div>
                      <div className="content-subtext">/{collection.slug}</div>
                    </td>
                    <td>{collection.template}</td>
                    <td>
                      {collection.purchasable_count}
                      {collection.item_count !== collection.purchasable_count && (
                        <span className="content-warning"> of {collection.item_count}</span>
                      )}
                    </td>
                    <td>{collection.view_count}</td>
                    <td>{statusBadge(collection)}</td>
                    <td>
                      <button className="admin-btn" onClick={() => onOpen(collection.slug)}>Edit</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="content-note" style={{ padding: '0 16px 16px' }}>
          "3 of 5" under Items means two products on the page are not on sale, so shoppers
          will not see them. A page cannot be published with none.
        </p>
      </div>

      <div className="content-card">
        <div className="content-card__header">New campaign page</div>
        <div className="content-card__body">
          <form onSubmit={handleCreate}>
            <div className="content-field">
              <label htmlFor="col_title">Title *</label>
              <input id="col_title" required minLength={3} placeholder="Diwali Gifting 2026"
                     value={form.title} onChange={set('title')} />
            </div>

            <div className="content-field">
              <label htmlFor="col_subtitle">Supporting line</label>
              <input id="col_subtitle" placeholder="Hampers hand-packed to order"
                     value={form.subtitle} onChange={set('subtitle')} />
            </div>

            <div className="content-field">
              <label htmlFor="col_template">Layout</label>
              <select id="col_template" value={form.template} onChange={set('template')}>
                {TEMPLATES.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            <div className="content-field">
              <label htmlFor="col_intro">Introduction</label>
              <textarea id="col_intro" rows={2} placeholder="A short paragraph above the products."
                        value={form.intro} onChange={set('intro')} />
            </div>

            <div className="content-row-form">
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="col_start">Runs from</label>
                <input id="col_start" type="date" value={form.starts_date} onChange={set('starts_date')} />
              </div>
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="col_end">Until</label>
                <input id="col_end" type="date" value={form.ends_date} onChange={set('ends_date')} />
              </div>
            </div>
            <div className="content-hint" style={{ marginTop: -8, marginBottom: 12 }}>
              Set an end date. A Diwali page still live in January is the mistake that actually happens.
            </div>

            <button className="admin-btn admin-btn--primary" type="submit" disabled={creating}>
              {creating ? 'Creating…' : 'Create page'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}

function CollectionEditor({ slug, onBack }) {
  const [collection, setCollection] = useState(null);
  const [items, setItems] = useState([]);
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [statusBusy, setStatusBusy] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [itemProduct, setItemProduct] = useState('');
  const [itemHeadline, setItemHeadline] = useState('');
  const [addingItem, setAddingItem] = useState(false);
  const [removingUuid, setRemovingUuid] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [detail, productsResponse] = await Promise.all([
        api.get(`/admin/collections/${encodeURIComponent(slug)}`),
        api.get('/admin/products', { per_page: 200 }),
      ]);
      setCollection(detail.data.collection);
      setItems(detail.data.items || []);
      setProducts(productsResponse.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => {
    load();
  }, [load]);

  async function handleHeroUpload(event) {
    event.preventDefault();
    const file = event.currentTarget.elements.image.files[0];
    if (!file) {
      toast('Choose a picture first.', 'danger');
      return;
    }

    const body = new FormData();
    body.append('image', file);
    setUploading(true);
    try {
      await api.upload(`/admin/collections/${encodeURIComponent(slug)}/image`, body);
      toast('Picture updated.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setUploading(false);
    }
  }

  async function handleStatus(status) {
    setStatusBusy(true);
    try {
      await api.post(`/admin/collections/${encodeURIComponent(slug)}/status`, { status });
      toast(status === 'published' ? 'Page is live.' : 'Page unpublished.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setStatusBusy(false);
    }
  }

  async function handleAddItem(event) {
    event.preventDefault();
    if (!itemProduct) return;

    const payload = { product: itemProduct, display_order: (items.length + 1) * 10 };
    const headline = itemHeadline.trim();
    if (headline) payload.headline = headline;

    setAddingItem(true);
    try {
      await api.post(`/admin/collections/${encodeURIComponent(slug)}/items`, payload);
      toast('Added to the page.');
      setItemProduct('');
      setItemHeadline('');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setAddingItem(false);
    }
  }

  async function handleRemoveItem(itemUuid) {
    setRemovingUuid(itemUuid);
    try {
      await api.delete(`/admin/collections/${encodeURIComponent(slug)}/items/${encodeURIComponent(itemUuid)}`);
      toast('Removed from the page.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setRemovingUuid(null);
    }
  }

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;
  if (!collection) return null;

  const chosen = new Set(items.map((item) => item.slug));
  const live = items.filter((item) => item.is_purchasable).length;
  const choosable = products.filter((product) => !chosen.has(product.slug));

  return (
    <div>
      <button className="content-back" type="button" onClick={onBack}>&larr; All campaign pages</button>

      <div className="content-editor-top">
        <div>
          <h2>{collection.title}</h2>
          <div className="content-subtext">
            /{collection.slug} &middot; {collection.template} layout &middot; {collection.view_count} view(s)
          </div>
        </div>
        <div className="content-editor-actions">
          <a className="admin-btn" target="_blank" rel="noopener noreferrer" href={`/collection/${encodeURIComponent(collection.slug)}`}>
            Preview
          </a>
          {collection.status === 'published' ? (
            <button className="admin-btn" disabled={statusBusy} onClick={() => handleStatus('draft')}>Unpublish</button>
          ) : (
            <button className="admin-btn admin-btn--primary" disabled={statusBusy} onClick={() => handleStatus('published')}>
              Publish
            </button>
          )}
        </div>
      </div>

      <div className="content-card">
        <div className="content-card__header">Picture</div>
        <div className="content-card__body content-row-form">
          {collection.hero_image_url ? (
            <img className="content-thumb" src={collection.hero_image_url} alt="" />
          ) : (
            <div className="content-muted" style={{ fontSize: 13 }}>
              No picture yet — shown as a plain tile on the home page until one is added.
            </div>
          )}
          <form className="content-row-form" onSubmit={handleHeroUpload}>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required />
            <button className="admin-btn" type="submit" disabled={uploading}>
              {uploading ? 'Uploading…' : (collection.hero_image_url ? 'Replace' : 'Upload')}
            </button>
          </form>
        </div>
      </div>

      {live === 0 && (
        <div className="admin-alert admin-alert--warning" style={{ marginBottom: 16 }}>
          Nothing on this page is on sale, so it cannot be published. Add products that are
          published in the catalogue.
        </div>
      )}

      <div className="content-grid">
        <div className="content-card content-card--wide">
          <div className="content-card__header">On this page</div>
          {items.length === 0 ? (
            <div className="content-card__body">
              <EmptyState title="Nothing chosen yet" hint="Pick products from the list on the right." />
            </div>
          ) : (
            <ul className="content-list">
              {items.map((item) => (
                <li key={item.item_uuid}>
                  <span>
                    {item.name}
                    {item.headline && <div className="content-headline">{item.headline}</div>}
                    {!item.is_purchasable && (
                      <div className="content-warning">Not on sale — shoppers will not see this</div>
                    )}
                  </span>
                  <button
                    className="admin-btn"
                    disabled={removingUuid === item.item_uuid}
                    onClick={() => handleRemoveItem(item.item_uuid)}
                  >
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="content-card">
          <div className="content-card__header">Add a product</div>
          <div className="content-card__body">
            <form onSubmit={handleAddItem}>
              <div className="content-field">
                <label htmlFor="item_product">Product</label>
                <select id="item_product" required value={itemProduct} onChange={(e) => setItemProduct(e.target.value)}>
                  <option value="">Choose…</option>
                  {choosable.map((product) => (
                    <option key={product.slug} value={product.slug}>
                      {product.name}{product.status === 'published' ? '' : ' (not on sale)'}
                    </option>
                  ))}
                </select>
              </div>

              <div className="content-field">
                <label htmlFor="item_headline">Label above it</label>
                <input id="item_headline" maxLength={120} placeholder="Our pick for gifting"
                       value={itemHeadline} onChange={(e) => setItemHeadline(e.target.value)} />
                <div className="content-hint">Shown only on this page — the product itself is unchanged.</div>
              </div>

              <button className="admin-btn admin-btn--primary" type="submit" disabled={addingItem}>
                {addingItem ? 'Adding…' : 'Add to page'}
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function ContentCollections({ initialOpenSlug }) {
  const [openSlug, setOpenSlug] = useState(initialOpenSlug || null);

  if (openSlug) {
    return <CollectionEditor slug={openSlug} onBack={() => setOpenSlug(null)} />;
  }

  return <CollectionsList onOpen={setOpenSlug} />;
}
