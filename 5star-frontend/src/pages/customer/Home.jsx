import { useEffect, useState } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { BRAND_LOGO_URL } from '../../lib/brand';
import {
  CATEGORY_TINTS, TRUST_POINTS, cardFromListItem, recentlyViewed, rupees,
} from '../../lib/store';
import ProductCard, { AddControl, ProductMedia } from '../../components/customer/ProductCard';

const OFFER_STYLES = [
  { bg: '#2a2829', fg: '#fff' },
  { bg: '#f3e2b3', fg: '#2a2829' },
  { bg: '#c62d1f', fg: '#fff' },
];

const OFFER_TAGS = {
  deal_of_day: "Today's deals",
  flash_sale: 'Flash sale',
  festival: 'Festive sale',
  category: 'Category offer',
  combo: 'Combo offer',
  free_shipping: 'Free delivery',
  buy_x_get_y: 'Buy more, save more',
  bogo: 'Buy more, save more',
  first_order: 'First order',
};

function offerTag(offer) {
  return OFFER_TAGS[offer.offer_type] || 'Offer';
}

function shortDate(value) {
  if (!value) return null;
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'long' });
}

function totalProducts(category) {
  return Number(category.product_count || 0)
    + (category.children || []).reduce((sum, child) => sum + totalProducts(child), 0);
}

async function listProducts(params) {
  try {
    const response = await api.get('/products', params);
    return (response.data || []).map(cardFromListItem);
  } catch {
    return [];
  }
}

function Section({ title, aside, children, label }) {
  return (
    <section className="sf-wrap" data-screen-label={label} style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      <div className="sf-section-head">
        <h2 className="sf-h2">{title}</h2>
        {aside}
      </div>
      {children}
    </section>
  );
}

function Hero() {
  return (
    <section className="sf-wrap sf-hero">
      <div className="sf-hero__text">
        <span className="sf-eyebrow">Since 1984</span>
        <h1 className="sf-hero__title">Pure spices &amp; dry fruits, delivered fresh</h1>
        <p className="sf-lead">Hand-picked, stone-ground and never compromised — the same spices your grandmother would trust.</p>
        <div className="sf-hero__ctas">
          <Link to="/shop" className="sf-btn sf-btn--red sf-btn--xl">Shop now</Link>
          <Link to="/gifting" className="sf-btn sf-btn--outline sf-btn--xl">Gift boxes</Link>
        </div>
      </div>
      <div className="sf-hero__art">
        <img src="/brand/logo-512.png" alt="5 Star — since 1984" width="340" height="340" />
      </div>
    </section>
  );
}

function Offers({ offers }) {
  if (offers.length === 0) return null;
  const ends = shortDate(offers[0].schedule?.ends_date);

  return (
    <Section title="Offers" label="Offers" aside={ends && <span className="sf-small" style={{ fontSize: 14 }}>Valid till {ends}</span>}>
      <div className="sf-offers">
        {offers.map((offer, index) => {
          const style = OFFER_STYLES[index % OFFER_STYLES.length];
          const automatic = offer.discount && offer.discount.type !== 'none';
          return (
            <Link key={offer.uuid} to="/shop" className="sf-offer" style={{ background: style.bg, color: style.fg }}>
              <span className="sf-offer__tag">{offerTag(offer)}</span>
              <span className="sf-offer__title">{offer.title}</span>
              <span className="sf-offer__body">{offer.subtitle || offer.description}</span>
              <div className="sf-offer__foot">
                <span className="sf-offer__code">{automatic ? 'AUTO-APPLIED' : offer.code}</span>
                <span className="sf-offer__cta">Shop now →</span>
              </div>
            </Link>
          );
        })}
      </div>
    </Section>
  );
}

function Spotlight({ product }) {
  if (!product) return null;

  return (
    <section className="sf-wrap" data-screen-label="Featured product">
      <div className="sf-spot">
        <Link to={`/product/${product.slug}`} aria-label={product.name}>
          <ProductMedia image={product.image} tint={product.tint} label={`Product photo · ${product.name}`} alt={product.name} style={{ height: '100%' }} />
        </Link>
        <div className="sf-spot__body">
          <span className="sf-eyebrow">Product of the month</span>
          <h2 className="sf-spot__title">{product.name}</h2>
          {product.short && <p className="sf-lead" style={{ fontSize: 16, color: '#4a4647' }}>{product.short}</p>}
          <div className="sf-spot__price">
            <b>{rupees(product.price)}</b>
            {product.off > 0 && <s className="sf-price__mrp" style={{ fontSize: 15 }}>{rupees(product.mrp)}</s>}
            {product.off > 0 && <span className="sf-good" style={{ fontWeight: 700, fontSize: 14 }}>{product.off}% off</span>}
          </div>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', paddingTop: 4, alignItems: 'center' }}>
            <SpotlightAdd product={product} />
            <Link to={`/product/${product.slug}`} className="sf-btn sf-btn--outline">View details</Link>
          </div>
        </div>
      </div>
    </section>
  );
}

function SpotlightAdd({ product }) {
  return <AddControl product={product} size="md" tone="red" label="Add to cart" />;
}

export default function Home() {
  const [params] = useSearchParams();
  // Old links (/?category=… and /?q=…) now live on the Shop page.
  if (params.get('category') || params.get('q')) {
    const next = new URLSearchParams();
    if (params.get('category')) next.set('category', params.get('category'));
    if (params.get('q')) next.set('q', params.get('q'));
    return <Navigate to={`/shop?${next.toString()}`} replace />;
  }
  return <HomeContent />;
}

function HomeContent() {
  const [offers, setOffers] = useState([]);
  const [categories, setCategories] = useState([]);
  const [favourites, setFavourites] = useState(null);
  const [gifts, setGifts] = useState([]);
  const [recs, setRecs] = useState([]);

  useEffect(() => {
    document.title = '5 Star — Spices & Dry Fruits since 1984';

    api.get('/offers')
      .then((response) => setOffers(((response.data && response.data.offers) || []).slice(0, 3)))
      .catch(() => setOffers([]));

    api.get('/categories')
      .then((response) => {
        const list = response.data.categories || response.data || [];
        setCategories(list.map((c) => ({ ...c, total: totalProducts(c) })).filter((c) => c.total > 0));
      })
      .catch(() => setCategories([]));

    listProducts({ per_page: 8, is_featured: 1, sort: 'popularity' }).then(async (featured) => {
      const list = featured.length >= 4 ? featured : await listProducts({ per_page: 8, sort: 'popularity' });
      setFavourites(list);
    });

    listProducts({ per_page: 4, category: 'gift-packs', sort: 'popularity' }).then(setGifts);

    const viewed = recentlyViewed();
    if (viewed.length > 0) {
      const seen = new Set(viewed.map((v) => v.slug));
      const category = viewed.find((v) => v.category)?.category;
      listProducts({ per_page: 12, sort: 'popularity', ...(category ? { category } : {}) })
        .then((list) => setRecs(list.filter((p) => !seen.has(p.slug)).slice(0, 4)));
    }
  }, []);

  const spotlight = favourites && favourites.length > 0
    ? [...favourites].sort((a, b) => b.off - a.off)[0]
    : null;
  const peek = gifts.length > 0 ? gifts : (favourites || []).slice(0, 4);

  return (
    <div className="sf-stack-72">
      <Hero />

      <section className="sf-wrap">
        <div className="sf-trust">
          {TRUST_POINTS.map((point) => (
            <div key={point.label}>
              <b>{point.label}</b>
              <span>{point.hint}</span>
            </div>
          ))}
        </div>
      </section>

      <Offers offers={offers} />

      {categories.length > 0 && (
        <Section title="Shop by category">
          <div className="sf-cats">
            {categories.map((category, index) => (
              <Link
                key={category.slug}
                to={`/shop?category=${encodeURIComponent(category.slug)}`}
                className="sf-cat"
                style={{ background: CATEGORY_TINTS[index % CATEGORY_TINTS.length] }}
              >
                <span className="sf-cat__count">{category.total} {category.total === 1 ? 'product' : 'products'}</span>
                <span className="sf-cat__name">{category.name}</span>
              </Link>
            ))}
          </div>
        </Section>
      )}

      <Section title="Everyday favourites" aside={<Link to="/shop" className="sf-link-btn">View all</Link>}>
        {favourites === null && <p className="sf-muted">Loading products…</p>}
        {favourites && favourites.length === 0 && <p className="sf-muted">Products will appear here soon.</p>}
        {favourites && favourites.length > 0 && (
          <div className="sf-grid">
            {favourites.map((product) => <ProductCard key={product.uuid} product={product} />)}
          </div>
        )}
      </Section>

      <Spotlight product={spotlight} />

      {recs.length > 0 && (
        <Section title="Recommended for you" label="Recommended">
          <div className="sf-grid sf-grid--tight">
            {recs.map((product) => <ProductCard key={product.uuid} product={product} />)}
          </div>
        </Section>
      )}

      <section className="sf-wrap">
        <div className="sf-band">
          <div className="sf-band__copy">
            <span className="sf-eyebrow">Gifting &amp; bulk orders</span>
            <h2 className="sf-band__title">Festive boxes, wedding favours, office pantries</h2>
            <p className="sf-lead" style={{ fontSize: 16, maxWidth: '48ch' }}>
              Tell us what you need and we will send a price — usually the same working day.
            </p>
            <div style={{ paddingTop: 6 }}>
              <Link to="/gifting" className="sf-btn sf-btn--ink">See gift boxes</Link>
            </div>
          </div>
          {peek.length > 0 ? (
            <div className="sf-peek">
              {peek.map((item) => (
                <Link key={item.uuid} to={`/product/${item.slug}`} style={{ background: item.tint }}>
                  {item.image && <img src={item.image} alt="" loading="lazy" />}
                  <b>{item.name}</b>
                  <span>{rupees(item.price)}</span>
                </Link>
              ))}
            </div>
          ) : (
            <div className="sf-hero__art" style={{ aspectRatio: '4 / 3' }}>
              <img src={BRAND_LOGO_URL} alt="" />
            </div>
          )}
        </div>
      </section>
    </div>
  );
}
