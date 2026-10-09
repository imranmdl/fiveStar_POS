import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { useCart } from '../../hooks/useCart';
import {
  cardFromListItem, discountPercent, perHundredGrams, rememberViewed, rupees, tintFor, variantLabel,
} from '../../lib/store';
import ProductCard, { ProductMedia } from '../../components/customer/ProductCard';
import SafeImage from '../../components/customer/SafeImage';

const PIN_KEY = 'spice.pincode';

function shelfLife(days) {
  const d = Number(days || 0);
  if (!d) return null;
  if (d >= 60) return `${Math.round(d / 30)} months`;
  return `${d} days`;
}

function shortDate(value) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

/**
 * Size / colour pickers to draw: dimensions every variant carries, so each
 * full choice points at exactly one variant. Products whose variants only
 * have names fall back to one chip per variant.
 */
function pickerDimensions(product) {
  const variants = product.variants || [];
  return (product.option_dimensions || []).filter(
    (d) => variants.length > 0 && variants.every((v) => v.options && v.options[d.code]),
  );
}

function variantForChoice(variants, dims, choice) {
  if (dims.some((d) => !choice[d.code])) return null;
  return variants.find((v) => dims.every((d) => v.options[d.code] === choice[d.code])) || null;
}

/** Whether `value` for `code` still leads to a real variant, given the other picks. */
function valueAvailable(variants, dims, choice, code, value) {
  return variants.some((v) => v.options && v.options[code] === value
    && dims.every((d) => d.code === code || !choice[d.code] || v.options[d.code] === choice[d.code]));
}

/** Dimensions with a single value (e.g. one colour) are picked automatically. */
function autoChoice(dims, variants) {
  const picked = {};
  dims.forEach((d) => {
    const values = [...new Set(variants.map((v) => v.options[d.code]))];
    if (values.length === 1) [picked[d.code]] = values;
  });
  return picked;
}

function ReviewItem({ review }) {
  return (
    <div className="sf-review">
      <div className="sf-review__head">
        <span className="sf-rating"><b>{review.rating} ★</b></span>
        {review.title && <span>{review.title}</span>}
      </div>
      {review.body && <span>{review.body}</span>}
      <span className="sf-small">
        {review.author || 'Customer'}{review.is_verified_purchase ? ' · Certified buyer' : ''}
      </span>
      {review.merchant_reply && <div className="sf-review__reply"><b>Our reply:</b> {review.merchant_reply}</div>}
    </div>
  );
}

/** Pincode check — read-only, nothing is saved on the server. */
function DeliveryCheck() {
  const [pin, setPin] = useState(() => {
    try { return localStorage.getItem(PIN_KEY) || ''; } catch { return ''; }
  });
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);

  async function check(event) {
    event?.preventDefault();
    if (!/^\d{6}$/.test(pin)) {
      setResult({ ok: false, text: 'Enter a 6-digit pincode.' });
      return;
    }
    setBusy(true);
    try {
      const response = await api.get('/delivery/serviceability', { pincode: pin });
      const d = response.data;
      try { localStorage.setItem(PIN_KEY, pin); } catch { /* not saved — fine */ }
      setResult(d.is_serviceable
        ? { ok: true, text: `${d.message}${d.free_delivery_above ? ` Free delivery above ${rupees(d.free_delivery_above)}.` : ''}` }
        : { ok: false, text: d.message || 'We do not deliver to this pincode yet.' });
    } catch (err) {
      const messages = err instanceof ApiError ? err.fieldMessages() : [];
      setResult({ ok: false, text: messages[0] || err.message });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, flex: 1, minWidth: 220 }}>
      <form className="sf-pin" onSubmit={check}>
        <input inputMode="numeric" maxLength={6} placeholder="Enter delivery pincode" aria-label="Delivery pincode"
               value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} />
        <button type="submit" disabled={busy}>{busy ? '…' : 'Check'}</button>
      </form>
      {result
        ? <span className={result.ok ? 'sf-good' : 'sf-line__warn'} style={{ font: '600 13px/1.45 var(--sf-text)' }}>{result.text}</span>
        : <span className="sf-small">Dispatched within 24 hours.</span>}
    </div>
  );
}

export default function Product() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { add, busy } = useCart();

  const [status, setStatus] = useState('loading');
  const [product, setProduct] = useState(null);
  const [offers, setOffers] = useState([]);
  const [selected, setSelected] = useState(null);
  const [quantity, setQuantity] = useState(1);
  const [choice, setChoice] = useState({});
  const [chooseError, setChooseError] = useState('');
  const [imageIndex, setImageIndex] = useState(0);
  const [reviews, setReviews] = useState({ status: 'loading', items: [], summary: null });

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    setOffers([]);
    setQuantity(1);
    setImageIndex(0);

    api.get(`/products/${encodeURIComponent(slug)}`)
      .then(async (response) => {
        if (cancelled) return;
        const p = response.data.product;
        setProduct(p);
        const variants = p.variants || [];
        // Clothing, footwear and anything with size / colour options: the
        // shopper picks — nothing is chosen for them. Other products keep the
        // default pack pre-selected.
        const mustChoose = Boolean(p.requires_choice) && variants.length > 1;
        setSelected(mustChoose ? null : ((variants.find((v) => v.is_default) || variants[0] || {}).uuid || null));
        setChoice(mustChoose ? autoChoice(pickerDimensions(p), variants) : {});
        setChooseError('');
        setStatus('ready');
        document.title = `${p.name} · 5 Star`;
        rememberViewed(p);

        const [lookup, storewide] = await Promise.all([
          api.get('/offers/product-lookup', { product_uuids: p.uuid }).catch(() => null),
          api.get('/offers').catch(() => null),
        ]);
        if (cancelled) return;
        const list = [];
        const own = lookup?.data?.offers?.[p.uuid];
        if (own) list.push({ key: 'own', title: own.title, text: own.summary, ends: own.ends_date });
        ((storewide?.data?.offers) || [])
          .filter((o) => o.applies_to === 'all' && o.discount && o.discount.type !== 'none' && o.title !== own?.title)
          .slice(0, 3)
          .forEach((o) => list.push({ key: o.uuid, title: o.title, text: o.discount.summary, ends: o.schedule?.ends_date, code: o.code }));
        setOffers(list);
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
    return <div className="sf-panel sf-panel--pad sf-muted">Loading product…</div>;
  }

  if (status === 'missing') {
    return (
      <div className="sf-panel sf-center">
        <h1 className="sf-h1">That product is no longer available</h1>
        <Link to="/shop" className="sf-btn sf-btn--red">BACK TO THE SHOP</Link>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="sf-panel sf-panel--pad"><div className="sf-error">This product could not be loaded. Please try again.</div></div>;
  }

  const variants = product.variants || [];
  const mustChoose = Boolean(product.requires_choice) && variants.length > 1;
  const dims = mustChoose ? pickerDimensions(product) : [];
  // The variant actually chosen. With size / colour pickers it is the one
  // matching every picked value; otherwise the chip clicked. A product that
  // needs a choice has none until the shopper makes it.
  const variant = dims.length > 0
    ? variantForChoice(variants, dims, choice)
    : (variants.find((v) => v.uuid === selected) || (mustChoose ? null : variants[0]) || null);
  const cheapest = [...variants].sort((a, b) => Number(a.effective_price) - Number(b.effective_price))[0] || null;
  const shown = variant || cheapest;
  const price = Number(shown?.effective_price || 0);
  const mrp = Number(shown?.mrp || 0);
  const off = discountPercent(mrp, price);
  const maxQty = Number(variant?.max_order_quantity || 20);
  const tint = tintFor(product.slug);
  const images = (product.media || []).filter((m) => m.media_type === 'image' && m.url);
  const mainImage = images[imageIndex]?.url || product.primary_image?.url || null;
  const rating = product.rating || {};
  const origin = product.origin?.region || product.origin?.country || '';

  const specs = [
    product.brand && ['Brand', product.brand],
    variant && [product.requires_choice || product.has_size_options ? 'Selected' : 'Net quantity', variantLabel(variant)],
    origin && ['Origin', origin],
    ...(product.attributes || []).map((a) => [a.attribute_name, a.attribute_value]),
    shelfLife(product.shelf_life_days) && ['Shelf life', shelfLife(product.shelf_life_days)],
    product.storage_instructions && ['Storage', product.storage_instructions],
    product.ingredients && ['Ingredients', product.ingredients],
  ].filter(Boolean);

  const highlights = [
    product.short_description,
    product.flags?.is_organic && 'Certified organic',
    product.flags?.is_vegetarian && '100% vegetarian',
    origin && `Sourced from ${origin}`,
    !product.requires_choice && !product.has_size_options && perHundredGrams(variant),
  ].filter(Boolean);

  const related = (product.similar_products && product.similar_products.length > 0
    ? product.similar_products
    : product.other_products || []).slice(0, 6).map(cardFromListItem);

  function missingChoiceMessage() {
    const missing = dims.filter((d) => !choice[d.code]).map((d) => d.name.toLowerCase());
    if (missing.length > 0) return `Please select a ${missing.join(' and ')}.`;
    if (dims.length > 0) return 'That combination is not available. Please choose another.';
    return product.has_size_options ? 'Please select a size.' : 'Please select an option.';
  }

  async function addToCart() {
    if (!variant) {
      setChooseError(missingChoiceMessage());
      document.getElementById('sf-choose')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    setChooseError('');
    return add(variant.uuid, quantity, variant.option_label ? `${product.name} (${variant.option_label})` : product.name);
  }

  async function buyNow() {
    if (await addToCart()) navigate('/checkout');
  }

  return (
    <>
      <section className="sf-panel sf-pdp">
        <div className="sf-pdp__left">
          <div className="sf-gallery">
            {images.length > 1 && (
              <div className="sf-thumbs">
                {images.slice(0, 5).map((item, index) => (
                  <button
                    key={item.url}
                    type="button"
                    className={index === imageIndex ? 'is-on' : ''}
                    style={{ background: tint }}
                    onClick={() => setImageIndex(index)}
                    onMouseEnter={() => setImageIndex(index)}
                    aria-label={`Photo ${index + 1}`}
                  >
                    <SafeImage src={item.url} alt="" />
                  </button>
                ))}
              </div>
            )}
            <ProductMedia image={mainImage} tint={tint} label={`Product photo · ${product.name}`} alt={product.name} className="sf-media--main" />
          </div>
          {variants.length > 0 && (
            <div className="sf-pdp__buy">
              <button type="button" className="sf-btn sf-btn--yellow sf-btn--xl" disabled={busy} onClick={addToCart}>ADD TO CART</button>
              <button type="button" className="sf-btn sf-btn--red sf-btn--xl" disabled={busy} onClick={buyNow}>BUY NOW</button>
            </div>
          )}
        </div>

        <div className="sf-pdp__info">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <nav className="sf-crumbs" aria-label="Breadcrumb">
              <Link to="/">Home</Link><span>›</span>
              {product.category && (
                <><Link to={`/shop?category=${encodeURIComponent(product.category.slug)}`}>{product.category.name}</Link><span>›</span></>
              )}
              <span>{product.name}</span>
            </nav>
            <h1 className="sf-pdp__title">{product.name}{variant ? `, ${variantLabel(variant)}` : ''}</h1>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              {Number(rating.count) > 0 && (
                <span className="sf-rating">
                  <b>{Number(rating.average).toFixed(1)} ★</b>
                  <span>{rating.count} ratings</span>
                </span>
              )}
              {product.flags?.is_organic && <span className="sf-organic-tag">CERTIFIED ORGANIC</span>}
            </div>
          </div>

          {shown ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              {off > 0 && <span className="sf-good" style={{ font: '700 14px var(--sf-text)' }}>Special price</span>}
              <div className="sf-pdp__price">
                <b>{!variant && mustChoose && variants.some((v) => Number(v.effective_price) !== price) ? `From ${rupees(price)}` : rupees(price)}</b>
                {off > 0 && <s>{rupees(mrp)}</s>}
                {off > 0 && <em>{off}% off</em>}
              </div>
              <span className="sf-small">Inclusive of all taxes</span>
            </div>
          ) : (
            <div className="sf-note">This product is not available to buy right now.</div>
          )}

          {offers.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              <b style={{ font: '700 16px var(--sf-text)' }}>Available offers</b>
              <div className="sf-offerlist">
                {offers.map((o) => (
                  <div key={o.key}>
                    <i>✓</i>
                    <span>
                      <b>{o.title}</b> — {o.text}
                      {o.code && <> · code <b>{o.code}</b></>}
                      {o.ends && <span className="sf-faint"> · till {shortDate(o.ends)}</span>}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {dims.length > 0 && (
            <div id="sf-choose" className={`sf-choose${chooseError ? ' sf-choose--error' : ''}`}>
              {dims.map((d) => (
                <div className="sf-pdp__row" key={d.code}>
                  <span className="sf-pdp__label">
                    {d.name}
                    {choice[d.code] ? <span className="sf-choose__picked">: {choice[d.code]}</span> : null}
                  </span>
                  <div className="sf-variants" role="radiogroup" aria-label={d.name}>
                    {d.values.map((value) => {
                      const on = choice[d.code] === value;
                      const possible = valueAvailable(variants, dims, choice, d.code, value);
                      return (
                        <button
                          key={value}
                          type="button"
                          role="radio"
                          aria-checked={on}
                          aria-disabled={!possible}
                          title={possible ? undefined : 'Not available with your other choice'}
                          className={`sf-variant sf-variant--opt${on ? ' is-on' : ''}${possible ? '' : ' is-off'}`}
                          onClick={() => {
                            const next = { ...choice, [d.code]: on ? undefined : value };
                            // Picking a value that rules out the other choice clears that one.
                            dims.forEach((other) => {
                              if (other.code !== d.code && next[other.code]
                                && !valueAvailable(variants, dims, { ...next, [other.code]: undefined }, other.code, next[other.code])) {
                                next[other.code] = undefined;
                              }
                            });
                            setChoice(next);
                            setChooseError('');
                            const match = variantForChoice(variants, dims, next);
                            if (match) setQuantity((q) => Math.min(q, Number(match.max_order_quantity || 20)));
                          }}
                        >
                          <b>{value}</b>
                        </button>
                      );
                    })}
                  </div>
                </div>
              ))}
              {chooseError && <div className="sf-choose__error" role="alert">{chooseError}</div>}
            </div>
          )}

          {dims.length === 0 && variants.length > 0 && (
            <div id="sf-choose" className={`sf-pdp__row${chooseError ? ' sf-choose--error' : ''}`}>
              <span className="sf-pdp__label">
                {product.has_size_options ? 'Size' : mustChoose ? 'Option' : 'Pack size'}
              </span>
              <div className="sf-variants" role="radiogroup" aria-label={product.has_size_options ? 'Size' : 'Pack size'}>
                {variants.map((v) => (
                  <button
                    key={v.uuid}
                    type="button"
                    role="radio"
                    aria-checked={v.uuid === selected}
                    className={`sf-variant${v.uuid === selected ? ' is-on' : ''}`}
                    onClick={() => {
                      setSelected(v.uuid);
                      setChooseError('');
                      setQuantity((q) => Math.min(q, Number(v.max_order_quantity || 20)));
                    }}
                  >
                    <b>{variantLabel(v)}</b>
                    <span>{rupees(v.effective_price)}</span>
                  </button>
                ))}
              </div>
              {chooseError && <div className="sf-choose__error" role="alert">{chooseError}</div>}
            </div>
          )}

          {variant && (
            <div className="sf-pdp__row" style={{ alignItems: 'center' }}>
              <span className="sf-pdp__label">Quantity</span>
              <div className="sf-qty sf-qty--outline">
                <button type="button" aria-label="Fewer" disabled={quantity <= 1} onClick={() => setQuantity((q) => Math.max(1, q - 1))}>−</button>
                <span>{quantity}</span>
                <button type="button" aria-label="More" disabled={quantity >= maxQty} onClick={() => setQuantity((q) => Math.min(maxQty, q + 1))}>+</button>
              </div>
              <span style={{ font: '600 14px var(--sf-text)' }}>Total {rupees(price * quantity)}</span>
            </div>
          )}

          <div className="sf-pdp__row">
            <span className="sf-pdp__label">Delivery</span>
            <DeliveryCheck />
          </div>

          {highlights.length > 0 && (
            <div className="sf-pdp__row">
              <span className="sf-pdp__label sf-pdp__label--mid">Highlights</span>
              <ul className="sf-highlights">
                {highlights.map((h) => <li key={h}>{h}</li>)}
              </ul>
            </div>
          )}

          {(specs.length > 0 || product.description) && (
            <div className="sf-specbox">
              <div className="sf-specbox__head">Specifications</div>
              {specs.length > 0 && (
                <dl>
                  {specs.map(([k, v]) => (
                    <div key={k}><dt>{k}</dt><dd>{v}</dd></div>
                  ))}
                </dl>
              )}
              {product.description && (
                <div className="sf-specbox__desc">
                  <b>Description</b>
                  <p>{product.description}</p>
                </div>
              )}
            </div>
          )}
        </div>
      </section>

      {related.length > 0 && (
        <section className="sf-panel">
          <div className="sf-panel__head"><h2 className="sf-h2">Similar products</h2></div>
          <div className="sf-pgrid">
            {related.map((item) => <ProductCard key={item.uuid} product={item} />)}
          </div>
        </section>
      )}

      <section className="sf-panel">
        <div className="sf-panel__head">
          <h2 className="sf-h2">Ratings &amp; reviews</h2>
          {reviews.status === 'ready' && reviews.items.length > 0 && (
            <span className="sf-rating">
              <b style={{ fontSize: 14 }}>{Number(reviews.summary?.rating_average || rating.average || 0).toFixed(1)} ★</b>
              <span>{reviews.summary?.review_count || reviews.items.length} reviews</span>
            </span>
          )}
        </div>
        {reviews.status === 'loading' && <div className="sf-review sf-muted">Loading reviews…</div>}
        {reviews.status === 'error' && <div className="sf-review sf-muted">Reviews could not be loaded.</div>}
        {reviews.status === 'ready' && reviews.items.length === 0 && (
          <div className="sf-review sf-muted">No reviews yet. Customers can review a product once their order has been delivered.</div>
        )}
        {reviews.items.map((review) => <ReviewItem key={review.uuid || review.id} review={review} />)}
      </section>
    </>
  );
}
