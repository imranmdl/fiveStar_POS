import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, Navigate, useNavigate, useOutletContext, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import {
  CATEGORY_TINTS, TRUST_POINTS, cardFromListItem, recentlyViewed, rupees,
} from '../../lib/store';
import { useStorefrontTheme } from '../../hooks/useStorefrontTheme';
import BannerSlide from '../../components/BannerSlide';
import ProductCard, { AddControl, ProductMedia, Rating } from '../../components/customer/ProductCard';
import SafeImage from '../../components/customer/SafeImage';

/** Colours for the offer cards under the banner, as in the design. */
const OFFER_STYLES = [
  { bg: '#2a2829', fg: '#fff' },
  { bg: '#f3e2b3', fg: '#2a2829' },
  { bg: '#c62d1f', fg: '#fff' },
];

const OFFER_TAGS = {
  flash_sale: 'Flash sale',
  festival: 'Festive sale',
  category: 'Category offer',
  combo: 'Combo offer',
  free_shipping: 'Free delivery',
  buy_x_get_y: 'Buy more, save more',
  bogo: 'Buy more, save more',
  first_order: 'First order',
};

/** Shown only when the store has no live home banner yet. */
const FALLBACK_SLIDE = {
  uuid: 'fallback',
  eyebrow: 'Since 1984',
  title: 'Pure spices & dry fruits, delivered fresh',
  subtitle: 'Hand-picked, stone-ground and never compromised.',
  cta_label: 'Shop now',
  bg_color: '#2a2829',
  text_color: '#ffffff',
  link: { type: 'url', value: '/shop' },
};

function totalProducts(category) {
  return Number(category.product_count || 0)
    + (category.children || []).reduce((sum, child) => sum + totalProducts(child), 0);
}

function parseDate(value) {
  if (!value) return null;
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? null : date;
}

async function listProducts(params) {
  try {
    const response = await api.get('/products', params);
    return (response.data || []).map(cardFromListItem);
  } catch {
    return [];
  }
}

/** Where a banner or offer card goes when tapped. */
function bannerHref(link) {
  if (!link || !link.value) return '/shop';
  const value = encodeURIComponent(link.value);
  switch (link.type) {
    case 'category': return `/shop?category=${value}`;
    case 'product': return `/product/${value}`;
    case 'collection': return `/collection/${value}`;
    case 'offer': return `/shop?offer=${value}`;
    case 'search': return `/shop?q=${value}`;
    default: return link.value;
  }
}

// ---------------------------------------------------------------------------

function CategoryStrip({ categories }) {
  if (categories.length === 0) return null;
  return (
    <nav className="sf-catstrip" aria-label="Categories">
      <Link to="/shop">
        <span className="sf-catstrip__circle" style={{ background: '#e2ded8' }}><span>★</span></span>
        <span className="sf-catstrip__name">Everything</span>
      </Link>
      {categories.map((category, index) => (
        <Link key={category.slug} to={`/shop?category=${encodeURIComponent(category.slug)}`}>
          <span className="sf-catstrip__circle" style={{ background: CATEGORY_TINTS[index % CATEGORY_TINTS.length] }}>
            <SafeImage src={category.image_url} alt="" loading="lazy" fallback={<span>{category.name.charAt(0)}</span>} />
          </span>
          <span className="sf-catstrip__name">{category.name}</span>
        </Link>
      ))}
    </nav>
  );
}

function BannerCarousel({ banners, seconds, accent }) {
  const navigate = useNavigate();
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const slides = banners.length > 0 ? banners : [FALLBACK_SLIDE];
  const count = slides.length;
  const slide = slides[index % count];

  const go = useCallback((step) => setIndex((i) => (i + step + count) % count), [count]);

  useEffect(() => {
    if (count < 2 || paused) return undefined;
    const timer = setInterval(() => go(1), Math.max(3, seconds) * 1000);
    return () => clearInterval(timer);
  }, [count, paused, seconds, go]);

  function open() {
    if (slide.uuid !== 'fallback') api.post(`/banners/${slide.uuid}/click`).catch(() => {});
    const href = bannerHref(slide.link);
    if (/^https?:\/\//.test(href)) window.location.href = href;
    else navigate(href);
  }

  const fg = slide.text_color || '#ffffff';

  return (
    <section
      className="sf-banner"
      data-screen-label="Banner"
      aria-roledescription="carousel"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <BannerSlide banner={slide} accent={accent} onClick={open} />
      {count > 1 && (
        <>
          <button type="button" className="sf-banner__nav sf-banner__nav--prev" aria-label="Previous banner" onClick={() => go(-1)}>‹</button>
          <button type="button" className="sf-banner__nav sf-banner__nav--next" aria-label="Next banner" onClick={() => go(1)}>›</button>
          <div className="sf-banner__dots">
            {slides.map((s, i) => (
              <button
                key={s.uuid}
                type="button"
                aria-label={`Banner ${i + 1}`}
                aria-current={i === index % count}
                onClick={() => setIndex(i)}
                style={{ width: i === index % count ? 22 : 8, background: fg, opacity: i === index % count ? 1 : 0.45 }}
              />
            ))}
          </div>
        </>
      )}
    </section>
  );
}

function useCountdown(endsAt) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!endsAt) return undefined;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [endsAt]);
  if (!endsAt) return null;
  const left = Math.max(0, Math.floor((endsAt.getTime() - now) / 1000));
  const days = Math.floor(left / 86400);
  const hms = [Math.floor((left % 86400) / 3600), Math.floor((left % 3600) / 60), left % 60]
    .map((n) => String(n).padStart(2, '0')).join(':');
  return { left, text: days > 0 ? `${days}d ${hms}` : hms };
}

function DealsOfTheDay({ deal }) {
  const countdown = useCountdown(parseDate(deal.offer.schedule?.ends_date));
  if (countdown && countdown.left === 0) return null;

  return (
    <section className="sf-panel" data-screen-label="Deals of the day">
      <div className="sf-panel__head" style={{ justifyContent: 'flex-start' }}>
        <h2 className="sf-h2">{deal.offer.title || 'Deals of the Day'}</h2>
        {countdown && <span className="sf-timer">Ends in <b>{countdown.text}</b></span>}
        <Link to={`/shop?offer=${encodeURIComponent(deal.offer.code)}`} className="sf-btn sf-btn--red sf-btn--sm" style={{ marginLeft: 'auto' }}>VIEW ALL</Link>
      </div>
      <div className="sf-deals-row">
        {deal.products.map((product) => (
          <div key={product.uuid} className="sf-deal">
            <Link to={`/product/${product.slug}`} aria-label={product.name}>
              <ProductMedia image={product.image} tint={product.tint} alt={product.name} className="sf-media--square">
                {product.off > 0 && <span className="sf-badge sf-badge--off">{product.off}% off</span>}
              </ProductMedia>
            </Link>
            <Link to={`/product/${product.slug}`} className="sf-pcard__name">{product.name}</Link>
            <div className="sf-price">
              <span className="sf-price__now">{rupees(product.price)}</span>
              {product.off > 0 && <span className="sf-price__mrp">{rupees(product.mrp)}</span>}
            </div>
            <span className="sf-pcard__size">{product.size || ' '}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

function OfferCards({ offers }) {
  if (offers.length === 0) return null;
  return (
    <section className="sf-offers" data-screen-label="Offers">
      {offers.map((offer, index) => {
        const style = OFFER_STYLES[index % OFFER_STYLES.length];
        const automatic = offer.discount && offer.discount.type !== 'none';
        return (
          <Link
            key={offer.uuid}
            to={`/shop?offer=${encodeURIComponent(offer.code)}`}
            className="sf-offer"
            style={{ background: style.bg, color: style.fg }}
          >
            <span className="sf-offer__tag">{OFFER_TAGS[offer.offer_type] || 'Offer'}</span>
            <span className="sf-offer__title">{offer.title}</span>
            {offer.subtitle && <span style={{ font: '500 14px/1.4 var(--sf-text)', opacity: 0.85 }}>{offer.subtitle}</span>}
            <div className="sf-offer__foot">
              <span className="sf-offer__code">{automatic ? 'AUTO-APPLIED' : offer.code}</span>
              <span className="sf-offer__cta">Shop now ›</span>
            </div>
          </Link>
        );
      })}
    </section>
  );
}

function Shelf({ title, to, products, label }) {
  if (!products || products.length === 0) return null;
  return (
    <section className="sf-panel" data-screen-label={label || title}>
      <div className="sf-panel__head">
        <h2 className="sf-h2">{title}</h2>
        {to && <Link to={to} className="sf-btn sf-btn--outline-red sf-btn--sm">VIEW ALL</Link>}
      </div>
      <div className="sf-pgrid">
        {products.map((product) => <ProductCard key={product.uuid} product={product} />)}
      </div>
    </section>
  );
}

function Spotlight({ product }) {
  if (!product) return null;
  const href = `/product/${product.slug}`;

  return (
    <section className="sf-panel sf-spot" data-screen-label="Featured product">
      <Link to={href} aria-label={product.name}>
        <ProductMedia image={product.image} tint={product.tint} label={`Product photo · ${product.name}`} alt={product.name} />
      </Link>
      <div className="sf-spot__body">
        <span className="sf-eyebrow">Product of the month</span>
        <h2 className="sf-big">{product.name}</h2>
        <Rating rating={product.rating} reviews={product.reviews} />
        {product.short && <p>{product.short}</p>}
        <div className="sf-spot__price">
          <b>{rupees(product.price)}</b>
          {product.off > 0 && <s className="sf-price__mrp" style={{ fontSize: 14 }}>{rupees(product.mrp)}</s>}
          {product.off > 0 && <span className="sf-price__off" style={{ fontSize: 14 }}>{product.off}% off</span>}
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', paddingTop: 6 }}>
          <AddControl product={product} variant="yellow" size="md" />
          <Link to={href} className="sf-btn sf-btn--outline">VIEW DETAILS</Link>
        </div>
      </div>
    </section>
  );
}

function GiftBand({ items }) {
  return (
    <section className="sf-panel sf-panel--pad sf-band" data-screen-label="Gifting">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <span className="sf-eyebrow">Gifting &amp; bulk orders</span>
        <h2 className="sf-big">Festive boxes, wedding favours, office pantries</h2>
        <p>Tell us what you need and we will send a price — usually the same working day.</p>
        <div style={{ paddingTop: 6 }}>
          <Link to="/gifting" className="sf-btn sf-btn--ink">SEE GIFT BOXES</Link>
        </div>
      </div>
      {items.length > 0 && (
        <div className="sf-peek">
          {items.map((item) => (
            <Link key={item.uuid} to={`/product/${item.slug}`}>
              <ProductMedia image={item.image} tint={item.tint} alt={item.name} />
              <b>{item.name}</b>
              <span>{rupees(item.price)}</span>
            </Link>
          ))}
        </div>
      )}
    </section>
  );
}

// ---------------------------------------------------------------------------

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
  const theme = useStorefrontTheme();
  const { categories: rawCategories = [] } = useOutletContext() || {};
  const categories = rawCategories.map((c) => ({ ...c, total: totalProducts(c) })).filter((c) => c.total > 0);

  const [banners, setBanners] = useState([]);
  const [offers, setOffers] = useState([]);
  const [deal, setDeal] = useState(null);
  const [shelves, setShelves] = useState([]);
  const [favourites, setFavourites] = useState(null);
  const [gifts, setGifts] = useState([]);
  const [recs, setRecs] = useState([]);
  const shelvesFor = useRef('');

  useEffect(() => {
    document.title = '5 Star — Spices & Dry Fruits since 1984';

    api.get('/banners', { placement: 'home_hero' })
      .then((response) => setBanners(response.data.banners || []))
      .catch(() => setBanners([]));

    api.get('/offers')
      .then((response) => {
        const all = (response.data && response.data.offers) || [];
        setOffers(all.filter((o) => o.offer_type !== 'deal_of_day').slice(0, 3));
        const today = all.find((o) => o.offer_type === 'deal_of_day');
        if (!today) return;
        api.get(`/offers/${encodeURIComponent(today.code)}/products`, { per_page: 12 })
          .then((r) => {
            const products = (r.data.products || []).map(cardFromListItem);
            if (products.length > 0) setDeal({ offer: r.data.offer || today, products });
          })
          .catch(() => {});
      })
      .catch(() => setOffers([]));

    listProducts({ per_page: 8, is_featured: 1, sort: 'popularity' }).then(async (featured) => {
      setFavourites(featured.length >= 4 ? featured : await listProducts({ per_page: 8, sort: 'popularity' }));
    });

    listProducts({ per_page: 4, category: 'gift-packs', sort: 'popularity' }).then(setGifts);

    const viewed = recentlyViewed();
    if (viewed.length > 0) {
      const seen = new Set(viewed.map((v) => v.slug));
      const category = viewed.find((v) => v.category)?.category;
      listProducts({ per_page: 12, sort: 'popularity', ...(category ? { category } : {}) })
        .then((list) => setRecs(list.filter((p) => !seen.has(p.slug)).slice(0, 6)));
    }
  }, []);

  // "Best of <category>" shelves for the two biggest categories.
  const topKey = categories.slice().sort((a, b) => b.total - a.total).slice(0, 2).map((c) => c.slug).join(',');
  useEffect(() => {
    if (!topKey || shelvesFor.current === topKey) return;
    shelvesFor.current = topKey;
    const top = topKey.split(',');
    Promise.all(top.map((slug) => listProducts({ per_page: 6, category: slug, sort: 'popularity' })))
      .then((lists) => setShelves(top.map((slug, i) => ({
        slug,
        name: rawCategories.find((c) => c.slug === slug)?.name || slug,
        products: lists[i],
      }))));
  }, [topKey, rawCategories]);

  const spotlight = favourites && favourites.length > 0
    ? [...favourites].sort((a, b) => b.off - a.off || b.reviews - a.reviews)[0]
    : null;
  const peek = gifts.length > 0 ? gifts : (favourites || []).slice(0, 4);

  return (
    <>
      <CategoryStrip categories={categories} />
      <BannerCarousel banners={banners} seconds={Number(theme.banner_seconds) || 5} accent={theme.accent} />
      {theme.show_deals && deal && <DealsOfTheDay deal={deal} />}
      <OfferCards offers={offers} />

      {favourites === null && <div className="sf-panel sf-panel--pad sf-muted">Loading products…</div>}
      <Shelf title="Everyday favourites" to="/shop" products={favourites && favourites.slice(0, 6)} />
      {shelves.map((shelf) => (
        <Shelf key={shelf.slug} title={`Best of ${shelf.name}`} to={`/shop?category=${encodeURIComponent(shelf.slug)}`} products={shelf.products} />
      ))}

      <Spotlight product={spotlight} />
      <Shelf title="Recommended for you" products={recs} label="Recommended" />
      <GiftBand items={peek} />

      <section className="sf-trust" data-screen-label="Why 5 Star">
        {TRUST_POINTS.map((point) => (
          <div key={point.label}>
            <b>{point.label}</b>
            <span>{point.hint}</span>
          </div>
        ))}
      </section>
    </>
  );
}
