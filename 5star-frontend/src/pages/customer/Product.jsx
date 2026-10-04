import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { useCart } from '../../hooks/useCart';
import {
  cardFromListItem, discountPercent, perHundredGrams, rememberViewed, rupees, tintFor, variantLabel,
} from '../../lib/store';
import ProductCard, { ProductMedia } from '../../components/customer/ProductCard';

function shelfLife(days) {
  const d = Number(days || 0);
  if (!d) return null;
  if (d >= 60) return `${Math.round(d / 30)} months`;
  return `${d} days`;
}

function ReviewItem({ review }) {
  return (
    <div className="sf-review">
      <div className="sf-review__head">
        <span>
          {review.author || 'Customer'}
          {review.is_verified_purchase && <span className="sf-small" style={{ marginLeft: 8 }}>Verified purchase</span>}
        </span>
        <span className="sf-review__stars" aria-label={`${review.rating} out of 5`}>
          {'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}
        </span>
      </div>
      {review.title && <b>{review.title}</b>}
      {review.body && <span className="sf-muted">{review.body}</span>}
      {review.merchant_reply && <div className="sf-review__reply"><b>Our reply:</b> {review.merchant_reply}</div>}
    </div>
  );
}

export default function Product() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { add, busy } = useCart();

  const [status, setStatus] = useState('loading');
  const [product, setProduct] = useState(null);
  const [offer, setOffer] = useState(null);
  const [selected, setSelected] = useState(null);
  const [quantity, setQuantity] = useState(1);
  const [imageIndex, setImageIndex] = useState(0);
  const [reviews, setReviews] = useState({ status: 'loading', items: [], summary: null });

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    setOffer(null);
    setQuantity(1);
    setImageIndex(0);

    api.get(`/products/${encodeURIComponent(slug)}`)
      .then(async (response) => {
        if (cancelled) return;
        const p = response.data.product;
        setProduct(p);
        const variants = p.variants || [];
        setSelected((variants.find((v) => v.is_default) || variants[0] || {}).uuid || null);
        setStatus('ready');
        document.title = `${p.name} · 5 Star`;
        rememberViewed(p);

        try {
          const lookup = await api.get('/offers/product-lookup', { product_uuids: p.uuid });
          if (!cancelled) setOffer((lookup.data && lookup.data.offers && lookup.data.offers[p.uuid]) || null);
        } catch {
          // No offer note this time — not fatal.
        }
      })
      .catch((err) => {
        if (cancelled) return;
        setStatus(err instanceof ApiError && err.status === 404 ? 'missing' : 'error');
      });

    api.get(`/products/${encodeURIComponent(slug)}/reviews`, { per_page: 10 })
      .then((response) => {
        if (!cancelled) setReviews({ status: 'ready', items: response.data || [], summary: (response.meta && response.meta.summary) || null });
      })
      .catch(() => {
        if (!cancelled) setReviews({ status: 'error', items: [], summary: null });
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  if (status === 'loading') {
    return <div className="sf-page"><p className="sf-muted">Loading product…</p></div>;
  }

  if (status === 'missing') {
    return (
      <div className="sf-center">
        <h1 className="sf-h1">That product is no longer available</h1>
        <Link to="/shop" className="sf-btn sf-btn--ink sf-btn--xl">Back to the shop</Link>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="sf-page"><div className="sf-error">This product could not be loaded. Please try again.</div></div>;
  }

  const variants = product.variants || [];
  const variant = variants.find((v) => v.uuid === selected) || variants[0] || null;
  const price = Number(variant?.effective_price || 0);
  const mrp = Number(variant?.mrp || 0);
  const off = discountPercent(mrp, price);
  const maxQty = Number(variant?.max_order_quantity || 20);
  const tint = tintFor(product.slug);
  const images = (product.media || []).filter((m) => m.media_type === 'image' && m.url);
  const mainImage = images[imageIndex]?.url || product.primary_image?.url || null;
  const rating = product.rating || {};
  const origin = product.origin?.region || product.origin?.country || '';
  const metaLine = [
    Number(rating.count) > 0 ? `★ ${Number(rating.average).toFixed(1)} · ${rating.count} reviews` : null,
    origin,
  ].filter(Boolean).join(' · ');

  const specs = [
    origin && ['Origin', origin],
    ...(product.attributes || []).map((a) => [a.attribute_name, a.attribute_value]),
    shelfLife(product.shelf_life_days) && ['Shelf life', shelfLife(product.shelf_life_days)],
    product.storage_instructions && ['Storage', product.storage_instructions],
    product.ingredients && ['Ingredients', product.ingredients],
  ].filter(Boolean);

  const related = (product.similar_products && product.similar_products.length > 0
    ? product.similar_products
    : product.other_products || []).slice(0, 4).map(cardFromListItem);

  async function addToCart() {
    if (!variant) return false;
    return add(variant.uuid, quantity, product.name);
  }

  async function buyNow() {
    if (await addToCart()) navigate('/checkout');
  }

  return (
    <div className="sf-page" style={{ paddingTop: 24, gap: 64 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
        <nav className="sf-crumbs" aria-label="Breadcrumb">
          <Link to="/">Home</Link><span>/</span>
          {product.category && (
            <>
              <Link to={`/shop?category=${encodeURIComponent(product.category.slug)}`}>{product.category.name}</Link><span>/</span>
            </>
          )}
          <span>{product.name}</span>
        </nav>

        <div className="sf-pdp">
          <div className="sf-gallery">
            <ProductMedia
              image={mainImage}
              tint={tint}
              label={`Product photo · ${product.name}`}
              alt={product.name}
              className="sf-media--main"
            />
            {images.length > 1 && (
              <div className="sf-thumbs">
                {images.slice(0, 4).map((item, index) => (
                  <button
                    key={item.url}
                    type="button"
                    className={index === imageIndex ? 'is-active' : ''}
                    style={{ background: tint }}
                    onClick={() => setImageIndex(index)}
                    aria-label={`Photo ${index + 1}`}
                  >
                    <img src={item.url} alt="" />
                  </button>
                ))}
              </div>
            )}
          </div>

          <div className="sf-pdp__info">
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                {product.category && <span className="sf-muted" style={{ font: "600 13px var(--sf-text)" }}>{product.category.name}</span>}
                {product.flags?.is_organic && <span className="sf-pill sf-pill--organic">Organic</span>}
              </div>
              <h1 className="sf-pdp__title">{product.name}</h1>
              {metaLine && <span className="sf-muted" style={{ fontSize: 14, fontWeight: 500 }}>{metaLine}</span>}
            </div>

            {product.short_description && <p className="sf-pdp__short">{product.short_description}</p>}

            {variant && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                <div className="sf-pdp__price">
                  <b>{rupees(price)}</b>
                  {off > 0 && <s>MRP {rupees(mrp)}</s>}
                  {off > 0 && <em>{off}% off</em>}
                </div>
                <span className="sf-small">
                  {[perHundredGrams(variant), 'Inclusive of all taxes'].filter(Boolean).join(' · ')}
                </span>
              </div>
            )}

            {variants.length > 0 && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                <span style={{ font: '600 14px var(--sf-text)' }}>{product.has_size_options ? 'Size' : 'Pack size'}</span>
                <div className="sf-variants" role="radiogroup" aria-label="Pack size">
                  {variants.map((v) => (
                    <button
                      key={v.uuid}
                      type="button"
                      role="radio"
                      aria-checked={v.uuid === selected}
                      className={`sf-variant ${v.uuid === selected ? 'is-active' : ''}`}
                      onClick={() => {
                        setSelected(v.uuid);
                        setQuantity((q) => Math.min(q, Number(v.max_order_quantity || 20)));
                      }}
                    >
                      <b>{variantLabel(v)}</b>
                      <span>{rupees(v.effective_price)}</span>
                    </button>
                  ))}
                </div>
              </div>
            )}

            {offer && (
              <div className="sf-offer-box">
                <b>{offer.summary}</b>
                <span>{offer.title}{offer.ends_date ? ` · valid till ${new Date(String(offer.ends_date).replace(' ', 'T')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}` : ''}</span>
              </div>
            )}

            {variant ? (
              <div className="sf-buy-row">
                <div className="sf-stepper sf-stepper--light sf-stepper--lg">
                  <button type="button" aria-label="Fewer" disabled={quantity <= 1} onClick={() => setQuantity((q) => Math.max(1, q - 1))}>−</button>
                  <span>{quantity}</span>
                  <button type="button" aria-label="More" disabled={quantity >= maxQty} onClick={() => setQuantity((q) => Math.min(maxQty, q + 1))}>+</button>
                </div>
                <button type="button" className="sf-btn sf-btn--red sf-btn--lg" disabled={busy} onClick={addToCart}>
                  Add to cart · {rupees(price * quantity)}
                </button>
                <button type="button" className="sf-btn sf-btn--outline sf-btn--lg" disabled={busy} onClick={buyNow}>
                  Buy now
                </button>
              </div>
            ) : (
              <div className="sf-note">This product is not available to buy right now.</div>
            )}

            <div className="sf-note">Dispatched within 24 hours. Delivery charges depend on your pincode — see them in your cart.</div>

            {specs.length > 0 && (
              <dl className="sf-specs" style={{ margin: 0 }}>
                {specs.map(([k, v]) => (
                  <div key={k}><dt>{k}</dt><dd>{v}</dd></div>
                ))}
              </dl>
            )}

            {product.description && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <span style={{ font: '600 15px var(--sf-text)' }}>About this product</span>
                <p style={{ margin: 0, font: '400 15px/1.6 var(--sf-text)', color: 'var(--sf-ink-2)', textWrap: 'pretty' }}>{product.description}</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {related.length > 0 && (
        <section style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
          <h2 className="sf-h2" style={{ fontSize: 28 }}>You may also like</h2>
          <div className="sf-grid sf-grid--tight">
            {related.map((item) => <ProductCard key={item.uuid} product={item} />)}
          </div>
        </section>
      )}

      <section className="sf-reviews">
        <h2 className="sf-h2" style={{ fontSize: 28 }}>Customer reviews</h2>
        {reviews.status === 'loading' && <p className="sf-muted">Loading reviews…</p>}
        {reviews.status === 'error' && <p className="sf-muted">Reviews could not be loaded.</p>}
        {reviews.status === 'ready' && reviews.items.length === 0 && (
          <p className="sf-muted">No reviews yet. Customers can review a product once their order has been delivered.</p>
        )}
        {reviews.status === 'ready' && reviews.items.length > 0 && (
          <>
            <span className="sf-muted">
              <b style={{ color: 'var(--sf-ink)', fontSize: 20 }}>{Number(reviews.summary?.rating_average || 0).toFixed(1)} ★</b>
              {' '}· {reviews.summary?.review_count || reviews.items.length} reviews
            </span>
            {reviews.items.map((review) => <ReviewItem key={review.uuid || review.id} review={review} />)}
          </>
        )}
      </section>
    </div>
  );
}
