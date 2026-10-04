import { useEffect, useMemo, useState } from 'react';
import { Link, useOutletContext, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { PRICE_BANDS, SORTS, cardFromListItem } from '../../lib/store';
import ProductCard from '../../components/customer/ProductCard';

const PER_PAGE = 24;

function totalProducts(category) {
  return Number(category.product_count || 0)
    + (category.children || []).reduce((sum, child) => sum + totalProducts(child), 0);
}

/** Flattens the category tree to [{slug, name, count, depth, parent}]. */
function flatten(list, depth = 0, parent = null) {
  return list.flatMap((c) => {
    const count = totalProducts(c);
    if (count === 0) return [];
    return [{ slug: c.slug, name: c.name, count, depth, parent }, ...flatten(c.children || [], depth + 1, c.slug)];
  });
}

function Check({ on, round, label, count, onClick }) {
  return (
    <button type="button" className={`sf-check${on ? ' is-on' : ''}`} aria-pressed={on} onClick={onClick}>
      <span className={`sf-check__box${round ? ' sf-check__box--round' : ''}`}>{on ? '✓' : ''}</span>
      <span className="sf-check__label">{label}</span>
      {count != null && <span className="sf-check__count">{count}</span>}
    </button>
  );
}

export default function Shop() {
  const [params, setParams] = useSearchParams();
  const { categories: tree = [] } = useOutletContext() || {};
  const category = params.get('category') || '';
  const q = params.get('q') || '';
  const offerCode = params.get('offer') || '';
  const organic = params.get('organic') === '1';
  const onOffer = params.get('deals') === '1';
  const rated = params.get('rating') === '4';
  const band = params.get('price') || '';
  const sort = params.get('sort') || 'relevance';

  const [products, setProducts] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('loading');
  const [offer, setOffer] = useState(null);

  const categories = useMemo(() => flatten(tree), [tree]);
  const current = categories.find((c) => c.slug === category);
  const parent = current && current.parent ? categories.find((c) => c.slug === current.parent) : null;

  useEffect(() => {
    setPage(1);
  }, [category, q, organic, onOffer, rated, band, sort, offerCode]);

  useEffect(() => {
    let cancelled = false;
    setStatus(page === 1 ? 'loading' : 'more');

    const query = { per_page: PER_PAGE, page, sort };
    if (category) query.category = category;
    if (q) query.search = q;
    if (organic) query.is_organic = 1;
    if (onOffer) query.has_offer = 1;
    if (rated) query.min_rating = 4;
    const priceBand = PRICE_BANDS.find(([key]) => key === band);
    if (priceBand) {
      if (priceBand[2] != null) query.min_price = priceBand[2];
      if (priceBand[3] != null) query.max_price = priceBand[3];
    }

    // An offer's own product list (Deals of the Day → VIEW ALL, offer cards).
    const request = offerCode
      ? api.get(`/offers/${encodeURIComponent(offerCode)}/products`, query).then((payload) => ({
        list: payload.data.products || [],
        meta: payload.meta || null,
        offer: payload.data.offer || null,
      }))
      : api.get('/products', query).then((payload) => ({ list: payload.data || [], meta: payload.meta || null, offer: null }));

    request
      .then(({ list, meta: m, offer: o }) => {
        if (cancelled) return;
        const cards = list.map(cardFromListItem);
        setProducts((existing) => (page === 1 ? cards : [...existing, ...cards]));
        setMeta(m);
        setOffer(o);
        setStatus('ready');
      })
      .catch(() => {
        if (!cancelled) setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [category, q, organic, onOffer, rated, band, sort, page, offerCode]);

  const title = offer ? offer.title
    : q ? `Results for “${q}”`
      : current ? current.name : 'All products';

  useEffect(() => {
    document.title = `${title} · 5 Star`;
  }, [title]);

  function update(changes) {
    const next = new URLSearchParams(params);
    // An offer's list is fixed by the offer; any filter goes back to the full catalogue.
    next.delete('offer');
    Object.entries(changes).forEach(([key, value]) => {
      if (value === null || value === '' || value === false) next.delete(key);
      else next.set(key, value);
    });
    setParams(next, { replace: true });
  }

  const total = meta ? meta.total : products.length;
  const hasMore = meta && meta.page < meta.total_pages;
  const filtered = Boolean(category || q || organic || onOffer || rated || band || offerCode);

  return (
    <div className="sf-shop">
      <aside className="sf-panel sf-filters" aria-label="Filters">
        <div className="sf-filters__head">
          <span>Filters</span>
          {filtered && <button type="button" className="sf-link-btn" onClick={() => setParams({}, { replace: true })}>CLEAR ALL</button>}
        </div>
        <div className="sf-filters__group">
          <span className="sf-filters__title">Categories</span>
          {categories.map((c) => (
            <div key={c.slug} style={{ paddingLeft: c.depth * 16 }}>
              <Check
                on={category === c.slug}
                label={c.name}
                count={c.count}
                onClick={() => update({ category: category === c.slug ? null : c.slug })}
              />
            </div>
          ))}
        </div>
        <div className="sf-filters__group">
          <span className="sf-filters__title">Price</span>
          {PRICE_BANDS.map(([key, label]) => (
            <Check key={key} round on={band === key} label={label} onClick={() => update({ price: band === key ? null : key })} />
          ))}
        </div>
        <div className="sf-filters__group">
          <span className="sf-filters__title">More</span>
          <Check on={onOffer} label="On offer" onClick={() => update({ deals: onOffer ? null : '1' })} />
          <Check on={organic} label="Certified organic" onClick={() => update({ organic: organic ? null : '1' })} />
          <Check on={rated} label="4★ & above" onClick={() => update({ rating: rated ? null : '4' })} />
        </div>
      </aside>

      <div className="sf-panel sf-shop__main">
        <div className="sf-shop__top">
          <div className="sf-crumbs">
            <Link to="/">Home</Link><span>›</span>
            {parent && <><Link to={`/shop?category=${encodeURIComponent(parent.slug)}`}>{parent.name}</Link><span>›</span></>}
            <span>{title}</span>
          </div>
          <h1 className="sf-h1">
            {title}{' '}
            {status === 'ready' && (
              <span className="sf-faint" style={{ font: '500 13px var(--sf-text)' }}>
                (Showing {products.length} of {total} {total === 1 ? 'product' : 'products'})
              </span>
            )}
          </h1>
          {offer && offer.subtitle && <span className="sf-small">{offer.subtitle}</span>}

          <div className="sf-chips" aria-label="Quick filters">
            {categories.filter((c) => c.depth === 0).map((c) => (
              <button key={c.slug} type="button" className={`sf-chip${category === c.slug ? ' is-on' : ''}`}
                      onClick={() => update({ category: category === c.slug ? null : c.slug })}>{c.name}</button>
            ))}
            <button type="button" className={`sf-chip${onOffer ? ' is-on' : ''}`} onClick={() => update({ deals: onOffer ? null : '1' })}>On offer</button>
            <button type="button" className={`sf-chip${organic ? ' is-on' : ''}`} onClick={() => update({ organic: organic ? null : '1' })}>Organic</button>
            {q && <button type="button" className="sf-chip is-on" onClick={() => update({ q: null })}>“{q}” ×</button>}
          </div>

          <div className="sf-sorttabs" role="tablist" aria-label="Sort by">
            <b>Sort by</b>
            {SORTS.map(([value, label]) => (
              <button key={value} type="button" role="tab" aria-selected={sort === value}
                      className={sort === value ? 'is-on' : ''}
                      onClick={() => update({ sort: value === 'relevance' ? null : value })}>
                {label}
              </button>
            ))}
          </div>
        </div>

        {status === 'loading' && <div className="sf-empty"><span className="sf-muted">Loading products…</span></div>}
        {status === 'error' && <div className="sf-empty"><b>Products could not be loaded</b><span className="sf-muted">Please try again.</span></div>}

        {status !== 'loading' && status !== 'error' && products.length === 0 && (
          <div className="sf-empty">
            <b>No products match</b>
            <span className="sf-muted">Try removing a filter.</span>
            <button type="button" className="sf-btn sf-btn--outline-red" onClick={() => setParams({}, { replace: true })}>CLEAR ALL</button>
          </div>
        )}

        {products.length > 0 && status !== 'loading' && (
          <div className="sf-pgrid" style={{ marginTop: 1 }}>
            {products.map((product) => <ProductCard key={product.uuid} product={product} />)}
          </div>
        )}

        {hasMore && (
          <div style={{ display: 'flex', justifyContent: 'center', padding: 20 }}>
            <button type="button" className="sf-btn sf-btn--outline-red" disabled={status === 'more'} onClick={() => setPage((p) => p + 1)}>
              {status === 'more' ? 'Loading…' : 'SHOW MORE'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
