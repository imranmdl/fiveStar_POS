import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, ApiError, formatMoney } from '../../lib/api';
import './Collection.css';
import SafeImage from '../../components/customer/SafeImage';

function perKilo(price, grams) {
  if (!price || !grams || grams <= 0) return '';
  return `${formatMoney((Number(price) / Number(grams)) * 1000)} per kg`;
}

function Card({ item, wide }) {
  const pricing = item.pricing || {};
  const rating = item.rating || {};
  const weight = item.weight_grams || {};
  const flags = item.flags || {};
  const image = item.primary_image && item.primary_image.url;
  const saving = Number(pricing.max_discount_percentage) || 0;

  const packs = [];
  if (!item.has_size_options) {
    if (weight.min) packs.push(weight.min >= 1000 ? `${weight.min / 1000}kg` : `${weight.min}g`);
    if (weight.max && weight.max !== weight.min) {
      packs.push(weight.max >= 1000 ? `${weight.max / 1000}kg` : `${weight.max}g`);
    }
  }

  return (
    <article className={`collection-card ${wide ? 'collection-card--wide' : ''}`}>
      <Link className="collection-card__media" to={`/product/${item.slug}`} aria-label={item.name}>
        <SafeImage src={image} alt={item.name} loading="lazy" fallback={<span>{(item.name || '?').charAt(0)}</span>} />
        <div className="collection-card__badges">
          {saving > 0 && <span className="tag tag--save">{saving}% off</span>}
          {pricing.has_live_offer && <span className="tag tag--offer">Offer</span>}
          {flags.is_organic && <span className="tag tag--organic">Organic</span>}
        </div>
      </Link>

      <div className="collection-card__body">
        {item.headline ? (
          <div className="collection-card__eyebrow">{item.headline}</div>
        ) : item.category ? (
          <div className="collection-card__eyebrow">{item.category.name}</div>
        ) : null}

        <Link className="collection-card__name" to={`/product/${item.slug}`}>{item.name}</Link>

        {packs.length > 0 && (
          <div className="collection-card__packs">
            {packs.map((pack) => <span key={pack} className="pack-pill">{pack}</span>)}
          </div>
        )}

        <div className="collection-card__rating">
          {Number(rating.count) > 0 ? (
            <><span className="rating-star">★</span> {Number(rating.average).toFixed(1)} <span className="text-muted">({rating.count})</span></>
          ) : (
            <span className="text-muted">No reviews yet</span>
          )}
        </div>

        <div className="collection-card__price-row">
          <span className="price">{formatMoney(pricing.min_price)}</span>
          {pricing.min_mrp && Number(pricing.min_mrp) > Number(pricing.min_price) && (
            <span className="price-was">{formatMoney(pricing.min_mrp)}</span>
          )}
        </div>
        {!item.has_size_options && (
          <div className="price-per-kg">{perKilo(pricing.min_price, weight.min)}</div>
        )}
      </div>
    </article>
  );
}

function Layout({ collection, items }) {
  if (items.length === 0) {
    return (
      <div className="collection-empty">
        <p className="collection-empty__title">Nothing here just now</p>
        <p className="text-muted">This selection is being put together.</p>
        <Link className="btn-marigold" to="/">Browse the shop</Link>
      </div>
    );
  }

  const grid = (list, wide) => (
    <div className={`collection-grid ${wide ? 'collection-grid--wide' : ''}`}>
      {list.map((item) => <Card key={item.uuid} item={item} wide={wide} />)}
    </div>
  );

  if (collection.template === 'spotlight') {
    return (
      <>
        {grid(items.slice(0, 1), true)}
        {items.length > 1 && <div className="collection-spotlight-rest">{grid(items.slice(1))}</div>}
      </>
    );
  }

  if (collection.template === 'story') {
    return (
      <>
        {grid(items)}
        {collection.cta_label && (
          <div className="collection-cta">
            <Link className="btn-marigold" to="/">{collection.cta_label}</Link>
          </div>
        )}
      </>
    );
  }

  if (collection.template === 'gift') {
    return (
      <>
        <div className="trust-strip">
          <span><b>Hand-packed</b> to order</span>
          <span><b>No prices</b> on anything we send as a gift</span>
          <span><b>Same-day dispatch</b> before 2pm</span>
        </div>
        {grid(items)}
        <p className="text-muted small collection-gift-note">
          Sending to more than a few addresses, or want your logo on the box? <Link to="/gifting">Ask for a bulk quote</Link> instead.
        </p>
      </>
    );
  }

  return grid(items);
}

export default function Collection() {
  const { slug } = useParams();
  const [status, setStatus] = useState('loading');
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState(null);
  const [collection, setCollection] = useState(null);
  const [items, setItems] = useState([]);

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    setNotFound(false);

    api
      .get(`/collections/${encodeURIComponent(slug)}`)
      .then((response) => {
        if (cancelled) return;
        setCollection(response.data.collection);
        setItems(response.data.items || []);
        document.title = `${response.data.collection.meta_title || response.data.collection.title} · 5Star Spices`;
        setStatus('ready');
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) {
          setNotFound(true);
          setStatus('ready');
          return;
        }
        setError(err.message);
        setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  if (status === 'loading') {
    return <div className="page"><p className="state-message">Loading…</p></div>;
  }

  if (notFound) {
    return (
      <div className="page">
        <div className="collection-empty">
          <p className="collection-empty__title">This page is no longer available</p>
          <p className="text-muted">The offer may have ended.</p>
          <Link className="btn-marigold" to="/">Browse the shop</Link>
        </div>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load this page: {error}</p></div>;
  }

  return (
    <div className="page collection-page">
      <header className={`collection-header ${collection.hero_image_url ? 'collection-header--hero' : ''}`}>
        {collection.hero_image_url && (
          <SafeImage src={collection.hero_image_url} alt={collection.hero_alt_text || collection.title} className="collection-header__image" />
        )}
        <div className="collection-header__body">
          <h1>{collection.title}</h1>
          {collection.subtitle && <p className="collection-header__subtitle">{collection.subtitle}</p>}
          {collection.intro && <p className="text-muted">{collection.intro}</p>}
        </div>
      </header>

      <Layout collection={collection} items={items} />
    </div>
  );
}
