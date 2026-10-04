import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { SORTS, cardFromListItem } from '../../lib/store';
import ProductCard from '../../components/customer/ProductCard';

const PER_PAGE = 24;

export default function Shop() {
  const [params, setParams] = useSearchParams();
  const category = params.get('category') || '';
  const q = params.get('q') || '';
  const organic = params.get('organic') === '1';
  const sort = params.get('sort') || 'relevance';

  const [products, setProducts] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('loading');
  const [categoryName, setCategoryName] = useState('');

  useEffect(() => {
    if (!category) {
      setCategoryName('');
      return;
    }
    api.get(`/categories/${encodeURIComponent(category)}`)
      .then((response) => {
        const c = response.data.category || response.data;
        setCategoryName((c && c.name) || '');
      })
      .catch(() => setCategoryName(''));
  }, [category]);

  useEffect(() => {
    setPage(1);
  }, [category, q, organic, sort]);

  useEffect(() => {
    let cancelled = false;
    setStatus(page === 1 ? 'loading' : 'more');

    const query = { per_page: PER_PAGE, page, sort };
    if (category) query.category = category;
    if (q) query.search = q;
    if (organic) query.is_organic = 1;

    api.get('/products', query)
      .then((payload) => {
        if (cancelled) return;
        const cards = (payload.data || []).map(cardFromListItem);
        setProducts((existing) => (page === 1 ? cards : [...existing, ...cards]));
        setMeta(payload.meta || null);
        setStatus('ready');
      })
      .catch(() => {
        if (cancelled) return;
        setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [category, q, organic, sort, page]);

  useEffect(() => {
    document.title = `${q ? `“${q}”` : categoryName || 'Shop'} · 5 Star`;
  }, [q, categoryName]);

  function update(changes) {
    const next = new URLSearchParams(params);
    Object.entries(changes).forEach(([key, value]) => {
      if (value === null || value === '' || value === false) next.delete(key);
      else next.set(key, value);
    });
    setParams(next, { replace: true });
  }

  const title = q ? `Results for “${q}”` : categoryName || (category ? 'Products' : 'All products');
  const total = meta ? meta.total : products.length;
  const hasMore = meta && meta.page < meta.total_pages;

  return (
    <div className="sf-page" style={{ gap: 24 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        <h1 className="sf-h1">{title}</h1>
        {status !== 'loading' && status !== 'error' && (
          <span className="sf-muted" style={{ fontSize: 15 }}>{total} {total === 1 ? 'product' : 'products'}</span>
        )}
      </div>

      <div className="sf-toolbar">
        <button
          type="button"
          className={`sf-chip sf-chip--lg ${organic ? 'sf-chip--active' : ''}`}
          aria-pressed={organic}
          onClick={() => update({ organic: organic ? null : '1' })}
        >
          Organic only
        </button>
        {q && (
          <button type="button" className="sf-chip sf-chip--lg" style={{ background: 'var(--sf-sand)' }} onClick={() => update({ q: null })}>
            “{q}” ×
          </button>
        )}
        <label className="sf-sort">
          Sort
          <select value={sort} onChange={(event) => update({ sort: event.target.value === 'relevance' ? null : event.target.value })}>
            {SORTS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
      </div>

      {status === 'loading' && <p className="sf-muted">Loading products…</p>}
      {status === 'error' && <div className="sf-error">Products could not be loaded. Please try again.</div>}

      {status !== 'loading' && status !== 'error' && products.length === 0 && (
        <div className="sf-empty">
          <b>No products match</b>
          <button type="button" className="sf-btn sf-btn--outline" style={{ height: 44 }} onClick={() => setParams({}, { replace: true })}>
            Clear filters
          </button>
        </div>
      )}

      {products.length > 0 && status !== 'loading' && (
        <div className="sf-grid" style={{ gap: '32px 20px' }}>
          {products.map((product) => <ProductCard key={product.uuid} product={product} />)}
        </div>
      )}

      {hasMore && (
        <div style={{ display: 'flex', justifyContent: 'center' }}>
          <button type="button" className="sf-btn sf-btn--outline" disabled={status === 'more'} onClick={() => setPage((p) => p + 1)}>
            {status === 'more' ? 'Loading…' : 'Show more'}
          </button>
        </div>
      )}
    </div>
  );
}
