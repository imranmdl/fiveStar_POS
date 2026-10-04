/**
 * Categories tab. Ported from page-content.js's renderCategories(). Create
 * and rename only — the live source never wires up delete, reorder or an
 * image upload for categories, so none is added here.
 */
import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import './Content.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const EMPTY_FORM = { name: '', parent_slug: '', description: '', is_featured: false };

/** Flattens the category tree into rows with an indent depth, same as the source's recursive categoryRow(). */
function flattenRows(categories, depth = 0) {
  return categories.flatMap((category) => [
    { category, depth },
    ...flattenRows(category.children || [], depth + 1),
  ]);
}

export default function ContentCategories() {
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [adding, setAdding] = useState(false);
  const [busyUuid, setBusyUuid] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/categories');
      setCategories(response.data.categories || response.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const set = (name) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    setForm((f) => ({ ...f, [name]: value }));
  };

  async function handleAdd(event) {
    event.preventDefault();
    const name = form.name.trim();
    if (!name) return;

    const payload = { name, is_featured: form.is_featured };
    if (form.parent_slug) payload.parent_slug = form.parent_slug;
    if (form.description.trim()) payload.description = form.description.trim();

    setAdding(true);
    try {
      await api.post('/admin/categories', payload);
      toast(`Category "${name}" added.`);
      setForm(EMPTY_FORM);
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setAdding(false);
    }
  }

  async function handleRename(category) {
    const name = window.prompt('New name for this category', category.name);
    if (!name || name === category.name) return;

    setBusyUuid(category.uuid);
    try {
      await api.patch(`/admin/categories/${encodeURIComponent(category.uuid)}`, { name });
      toast('Category renamed.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  const rows = flattenRows(categories);

  return (
    <div className="content-grid">
      <div className="content-card content-card--wide">
        <div className="content-card__header">Categories</div>
        {loading ? (
          <LoadingState />
        ) : error ? (
          <div className="content-card__body"><ErrorState error={error} /></div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Products</th>
                  <th>Home</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {rows.map(({ category, depth }) => (
                  <tr key={category.uuid}>
                    <td>
                      <span style={{ paddingLeft: `${depth * 1.25}rem` }}>
                        {depth > 0 && <span className="content-muted">&#x2514;&nbsp;</span>}
                        {category.name}
                      </span>
                      <div className="content-subtext" style={{ paddingLeft: `${depth * 1.25}rem` }}>
                        {category.slug}
                      </div>
                    </td>
                    <td>{category.product_count ?? 0}</td>
                    <td>{category.is_featured ? 'Featured' : '—'}</td>
                    <td>
                      <button
                        className="admin-btn"
                        disabled={busyUuid === category.uuid}
                        onClick={() => handleRename(category)}
                      >
                        Rename
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="content-card">
        <div className="content-card__header">Add a category</div>
        <div className="content-card__body">
          <form onSubmit={handleAdd}>
            <div className="content-field">
              <label htmlFor="cat_name">Name *</label>
              <input id="cat_name" required minLength={2} value={form.name} onChange={set('name')} />
            </div>

            <div className="content-field">
              <label htmlFor="cat_parent">Sits under</label>
              <select id="cat_parent" value={form.parent_slug} onChange={set('parent_slug')}>
                <option value="">Nothing — a top-level category</option>
                {categories.map((category) => (
                  <option key={category.slug} value={category.slug}>{category.name}</option>
                ))}
              </select>
              <div className="content-hint">
                Only top-level categories appear in the shop's navigation strip.
              </div>
            </div>

            <div className="content-field">
              <label htmlFor="cat_description">Description</label>
              <textarea id="cat_description" rows={2} value={form.description} onChange={set('description')} />
            </div>

            <label className="content-checkbox">
              <input type="checkbox" checked={form.is_featured} onChange={set('is_featured')} />
              Show on the home page
            </label>

            <button className="admin-btn admin-btn--primary" type="submit" disabled={adding}>
              {adding ? 'Adding…' : 'Add category'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
