import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import './Home.css';

function ProductCard({ product }) {
  const { slug, name, brand, short_description, pricing, primary_image, flags } = product;
  const discount = Math.round(Number(pricing.max_discount_percentage) || 0);

  return (
    <Link to={`/product/${slug}`} className="product-card">
      <div className="product-card__image">
        {primary_image ? (
          <img src={primary_image.url} alt={primary_image.alt_text || name} loading="lazy" />
        ) : (
          <div className="product-card__image-placeholder" aria-hidden="true" />
        )}
        {flags?.is_featured && <span className="product-card__badge">Featured</span>}
        {discount > 0 && <span className="product-card__discount">{discount}% OFF</span>}
      </div>
      <div className="product-card__body">
        <p className="product-card__brand">{brand}</p>
        <h2 className="product-card__name">{name}</h2>
        <p className="product-card__description">{short_description}</p>
        <div className="product-card__price">
          <span className="product-card__price-now">{formatMoney(pricing.min_price)}</span>
          {discount > 0 && <span className="product-card__price-mrp">{formatMoney(pricing.min_mrp)}</span>}
        </div>
      </div>
    </Link>
  );
}

const TRUST_POINTS = [
  { icon: '🌿', label: 'Fresh & Pure', hint: 'Sourced straight from the farm', color: 'var(--brand-teal)' },
  { icon: '🚚', label: 'Fast Delivery', hint: 'Dispatched within 24 hours', color: 'var(--brand-marigold)' },
  { icon: '🔒', label: 'Secure Payments', hint: '100% safe UPI checkout', color: 'var(--brand-forest)' },
  { icon: '↩️', label: 'Easy Returns', hint: 'Hassle-free, no questions asked', color: 'var(--brand-terracotta)' },
];

function Hero() {
  return (
    <section className="home-hero">
      <div className="home-hero__text">
        <span className="home-hero__eyebrow">Straight from the source</span>
        <h1 className="home-hero__title">Pure Spices &amp; Dry Fruits, Delivered Fresh</h1>
        <p className="home-hero__subtitle">
          Hand-picked, stone-ground and never compromised — the same spices your grandmother would trust.
        </p>
        <a href="#shop" className="home-hero__cta">
          Shop Now
        </a>
      </div>
    </section>
  );
}

function TrustStrip() {
  return (
    <div className="trust-strip">
      {TRUST_POINTS.map((point) => (
        <div key={point.label} className="trust-strip__item">
          <span className="trust-strip__icon" aria-hidden="true" style={{ background: point.color }}>
            {point.icon}
          </span>
          <div>
            <div className="trust-strip__label">{point.label}</div>
            <div className="trust-strip__hint">{point.hint}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

export default function Home() {
  const [searchParams] = useSearchParams();
  const [products, setProducts] = useState([]);
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);

  const category = searchParams.get('category') || '';
  const q = searchParams.get('q') || '';

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');

    api
      .get('/products', { per_page: 24, category, q })
      .then((payload) => {
        if (cancelled) return;
        setProducts(payload.data || []);
        setStatus('ready');
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message);
        setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [category, q]);

  const showHero = !q && !category;
  const HeadingTag = showHero ? 'h2' : 'h1';

  return (
    <div>
      {showHero && <Hero />}
      {showHero && <TrustStrip />}

      <div className="page" id="shop">
        <HeadingTag className="page-title">{q ? `Results for "${q}"` : showHero ? 'Everyday Favourites' : 'All products'}</HeadingTag>

        {status === 'loading' && <p className="state-message">Loading products…</p>}
        {status === 'error' && <p className="state-message state-message--error">Couldn't load products: {error}</p>}

        {status === 'ready' && products.length === 0 && <p className="state-message">No products found.</p>}

        {status === 'ready' && products.length > 0 && (
          <div className="product-grid">
            {products.map((product) => (
              <ProductCard key={product.uuid} product={product} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
