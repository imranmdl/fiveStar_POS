import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, ApiError, formatMoney } from '../../lib/api';
import { useCartCount } from '../../hooks/useCartCount';
import './Product.css';

function offerCategoryLabel(offer) {
  return offer.discount_type === 'free_items' ? 'Combo offer' : 'Special price';
}

function OffersBox({ offer }) {
  return (
    <div className="offers-box">
      <div className="offers-box__title">Available offers</div>
      <div className="offers-box__row">
        <span className="tag tag--offer">{offerCategoryLabel(offer)}</span>
        <div className="offers-box__text">
          <span className="offers-box__summary">{offer.summary}</span>
          <span className="offers-box__title2"> — {offer.title}</span>
          {offer.ends_date && (
            <div className="offers-box__valid">
              Valid till {new Date(offer.ends_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function VariantOption({ variant, selected, onSelect }) {
  const onOffer = Number(variant.mrp) > Number(variant.effective_price);
  const isSized = variant.size_label !== null && variant.size_label !== undefined && variant.size_label !== '';
  const weight = Number(variant.weight_grams) >= 1000
    ? `${Number(variant.weight_grams) / 1000} kg`
    : `${variant.weight_grams} g`;

  return (
    <label className={`pack-option ${selected ? 'pack-option--selected' : ''}`}>
      <input
        type="radio"
        name="variant"
        value={variant.uuid}
        checked={selected}
        onChange={() => onSelect(variant.uuid)}
      />
      <span className="pack-option__size">{isSized ? variant.size_label : weight}</span>
      <span className="pack-option__price">{formatMoney(variant.effective_price)}</span>
      {onOffer && <span className="pack-option__was">{formatMoney(variant.mrp)}</span>}
      {Number(variant.discount_percentage) > 0 && (
        <span className="pack-option__badge">{variant.discount_percentage}% off</span>
      )}
    </label>
  );
}

function RelatedCard({ item }) {
  const pricing = item.pricing || {};
  const weight = item.weight_grams || {};
  const image = item.primary_image && item.primary_image.url;
  const packLabel = item.has_size_options
    ? ''
    : (weight.min ? (weight.min >= 1000 ? `${weight.min / 1000}kg` : `${weight.min}g`) : '');

  return (
    <Link className="related-card" to={`/product/${item.slug}`}>
      <div className="related-card__media">
        {image ? <img src={image} alt={item.name} loading="lazy" /> : <span>{(item.name || '?').charAt(0)}</span>}
      </div>
      <div className="related-card__name">{item.name}</div>
      {packLabel && <div className="related-card__pack">{packLabel}</div>}
      <div className="related-card__price">{formatMoney(pricing.min_price)}</div>
    </Link>
  );
}

function RelatedSection({ title, items }) {
  if (!items || items.length === 0) return null;
  return (
    <section className="related-section">
      <h2 className="related-section__title">{title}</h2>
      <div className="related-section__row">
        {items.map((item) => (
          <RelatedCard key={item.uuid} item={item} />
        ))}
      </div>
    </section>
  );
}

function ReviewItem({ review }) {
  return (
    <div className="review-item">
      <div className="review-item__head">
        <div>
          <span className="review-item__author">{review.author || 'Customer'}</span>
          {review.is_verified_purchase && <span className="review-item__verified">Verified purchase</span>}
        </div>
        <div className="review-item__stars">{'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}</div>
      </div>
      {review.title && <div className="review-item__title">{review.title}</div>}
      {review.body && <p className="review-item__body">{review.body}</p>}
      {review.merchant_reply && (
        <div className="review-item__reply">
          <span className="review-item__reply-label">Our reply:</span> {review.merchant_reply}
        </div>
      )}
    </div>
  );
}

export default function Product() {
  const { slug } = useParams();
  const { refresh: refreshCartCount } = useCartCount();

  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);
  const [notFound, setNotFound] = useState(false);
  const [product, setProduct] = useState(null);
  const [offer, setOffer] = useState(null);
  const [selectedVariant, setSelectedVariant] = useState(null);
  const [quantity, setQuantity] = useState(1);
  const [mainImage, setMainImage] = useState(null);
  const [adding, setAdding] = useState(false);
  const [toast, setToastMsg] = useState(null);
  const [confirmOffer, setConfirmOffer] = useState(null);

  const [reviews, setReviews] = useState([]);
  const [reviewSummary, setReviewSummary] = useState(null);
  const [reviewsStatus, setReviewsStatus] = useState('loading');

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    setNotFound(false);

    async function load() {
      try {
        const response = await api.get(`/products/${encodeURIComponent(slug)}`);
        if (cancelled) return;
        const p = response.data.product;
        setProduct(p);
        document.title = `${p.name} · 5Star Spices`;

        const variants = p.variants || [];
        const defaultVariant = variants.find((v) => v.is_default) || variants[0] || null;
        setSelectedVariant(defaultVariant ? defaultVariant.uuid : null);

        const image = p.primary_image && p.primary_image.url;
        setMainImage(image || null);

        setStatus('ready');

        try {
          const lookup = await api.get('/offers/product-lookup', { product_uuids: p.uuid });
          if (cancelled) return;
          setOffer((lookup.data && lookup.data.offers && lookup.data.offers[p.uuid]) || null);
        } catch {
          // No badge this time — not fatal.
        }
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) {
          setNotFound(true);
          setStatus('ready');
          return;
        }
        setError(err.message);
        setStatus('error');
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, [slug]);

  useEffect(() => {
    if (!product) return;
    let cancelled = false;
    setReviewsStatus('loading');

    api
      .get(`/products/${encodeURIComponent(slug)}/reviews`, { per_page: 10 })
      .then((response) => {
        if (cancelled) return;
        setReviews(response.data || []);
        setReviewSummary((response.meta && response.meta.summary) || null);
        setReviewsStatus('ready');
      })
      .catch(() => {
        if (cancelled) return;
        setReviewsStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [product, slug]);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToastMsg(null), 4000);
    return () => clearTimeout(timer);
  }, [toast]);

  async function doAddToCart() {
    if (!selectedVariant) {
      setToastMsg({ text: 'Choose a pack size first.', variant: 'danger' });
      return;
    }

    setAdding(true);
    try {
      await api.post('/cart/items', { variant_uuid: selectedVariant, quantity });
      setToastMsg({
        text: offer ? `Added to your cart — ${offer.summary}.` : 'Added to your cart.',
        variant: 'success',
      });
      refreshCartCount();
    } catch (err) {
      setToastMsg({ text: err.message, variant: 'danger' });
    } finally {
      setAdding(false);
    }
  }

  function handleAddClick() {
    if (offer) {
      setConfirmOffer(offer);
      return;
    }
    doAddToCart();
  }

  if (status === 'loading') {
    return <div className="page"><p className="state-message">Loading product…</p></div>;
  }

  if (notFound) {
    return (
      <div className="page">
        <p className="state-message">
          That product is no longer available. <Link to="/">Back to the shop</Link>.
        </p>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load this product: {error}</p></div>;
  }

  const variants = product.variants || [];
  const flags = product.flags || {};
  const origin = product.origin || {};
  const rating = product.rating || {};
  const gallery = (product.media || []).filter((item) => item.media_type === 'image');

  return (
    <div className="page product-page">
      <nav className="breadcrumb">
        <Link to="/">Shop</Link>
        {product.category && (
          <>
            {' / '}
            <Link to={`/?category=${encodeURIComponent(product.category.slug)}`}>{product.category.name}</Link>
          </>
        )}
        {' / '}
        <span>{product.name}</span>
      </nav>

      <div className="product-layout">
        <div className="product-gallery">
          <div className="product-gallery__main">
            {mainImage ? <img src={mainImage} alt={product.name} /> : <span>{product.name.charAt(0)}</span>}
          </div>
          {gallery.length > 1 && (
            <div className="product-gallery__thumbs">
              {gallery.map((item) => (
                <button
                  key={item.url}
                  type="button"
                  className="product-gallery__thumb"
                  onClick={() => setMainImage(item.url)}
                  aria-label="Show this photograph"
                >
                  <img src={item.url} alt="" />
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="product-info">
          {product.brand && <div className="product-info__brand">{product.brand}</div>}
          <h1 className="product-info__name">{product.name}</h1>

          <div className="product-info__meta">
            {Number(rating.count) > 0 ? (
              <span className="rating-line">
                <span className="rating-star">★</span> <b>{Number(rating.average).toFixed(1)}</b>
                <span className="text-muted"> ({rating.count} reviews)</span>
              </span>
            ) : (
              <span className="text-muted">No reviews yet</span>
            )}
            {flags.is_organic && <span className="tag tag--organic">Organic</span>}
            {origin.region && <span className="text-muted">From {origin.region}</span>}
          </div>

          {product.short_description && <p className="text-muted">{product.short_description}</p>}

          <div className="eyebrow">{product.has_size_options ? 'Choose a size' : 'Choose a pack'}</div>
          <div className="pack-option-grid">
            {variants.map((variant) => (
              <VariantOption
                key={variant.uuid}
                variant={variant}
                selected={selectedVariant === variant.uuid}
                onSelect={setSelectedVariant}
              />
            ))}
          </div>

          {offer && <OffersBox offer={offer} />}

          <div className="product-actions">
            <div className="qty-stepper">
              <button type="button" aria-label="Fewer" onClick={() => setQuantity((q) => Math.max(1, q - 1))}>−</button>
              <input type="number" value={quantity} min="1" max="20" readOnly />
              <button type="button" aria-label="More" onClick={() => setQuantity((q) => Math.min(20, q + 1))}>+</button>
            </div>
            <button type="button" className="btn-marigold btn-lg" disabled={adding} onClick={handleAddClick}>
              {adding ? 'Adding…' : 'Add to cart'}
            </button>
          </div>

          <div className="trust-strip">
            <span><b>Same-day dispatch</b> before 2pm</span>
            <span><b>Prepaid UPI</b> · GST included</span>
            {product.shelf_life_days && <span><b>{product.shelf_life_days} days</b> shelf life</span>}
          </div>
        </div>
      </div>

      {(product.description || product.ingredients) && (
        <section className="product-details">
          {product.description && (
            <div className="product-details__block">
              <h2>About this product</h2>
              <p>{product.description}</p>
            </div>
          )}
          {product.ingredients && (
            <div className="product-details__block">
              <h2>Ingredients</h2>
              <p className="text-muted">{product.ingredients}</p>
            </div>
          )}
        </section>
      )}

      <RelatedSection title="More of this kind" items={product.similar_products} />
      <RelatedSection title="You might also like" items={product.other_products} />

      <section className="product-reviews">
        <h2>Customer reviews</h2>
        {reviewsStatus === 'loading' && <p className="text-muted">Loading reviews…</p>}
        {reviewsStatus === 'error' && <p className="text-muted">Reviews could not be loaded.</p>}
        {reviewsStatus === 'ready' && reviews.length === 0 && (
          <p className="text-muted">
            No reviews yet. Reviews can be written by customers once an order containing this product has been
            delivered to them.
          </p>
        )}
        {reviewsStatus === 'ready' && reviews.length > 0 && (
          <>
            <div className="product-reviews__summary">
              <span className="product-reviews__average">{Number(reviewSummary?.rating_average || 0).toFixed(1)}</span>
              <span className="text-muted">
                {reviewSummary?.review_count || 0} review(s), {reviewSummary?.verified_count || 0} from verified
                purchases
              </span>
            </div>
            {reviews.map((review) => (
              <ReviewItem key={review.uuid || review.id} review={review} />
            ))}
          </>
        )}
      </section>

      {confirmOffer && (
        <div className="modal-overlay" role="dialog" aria-modal="true">
          <div className="modal-box">
            <h2 className="modal-box__title">Offer available — {product.name}</h2>
            <p>
              <span className="tag tag--offer">{confirmOffer.summary}</span>
              <span className="modal-box__subtitle">{confirmOffer.title}</span>
            </p>
            <div className="modal-box__actions">
              <button type="button" className="btn-quiet-light" onClick={() => setConfirmOffer(null)}>
                Cancel
              </button>
              <button
                type="button"
                className="btn-marigold"
                onClick={() => {
                  setConfirmOffer(null);
                  doAddToCart();
                }}
              >
                Add to cart &amp; avail offer
              </button>
            </div>
          </div>
        </div>
      )}

      {toast && (
        <div className={`toast-pop toast-pop--${toast.variant}`}>{toast.text}</div>
      )}
    </div>
  );
}
