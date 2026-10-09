import { Link, useNavigate } from 'react-router-dom';
import { useCart } from '../../hooks/useCart';
import { rupees } from '../../lib/store';
import SafeImage from './SafeImage';

/**
 * Product picture tile. Shows the product photo when there is one; until the
 * catalogue has photos it is the design's warm tinted block with a caption.
 */
export function ProductMedia({ image, tint, label, className = '', children, style, alt = '' }) {
  return (
    <div className={`sf-media ${className}`} style={{ background: tint, ...style }}>
      <SafeImage
        src={image}
        alt={alt}
        loading="lazy"
        fallback={label ? <span className="sf-media__label">{label}</span> : null}
      />
      {children}
    </div>
  );
}

/**
 * ADD TO CART, or the red − qty + stepper once the product is in the cart.
 * `variant` picks the button style (outline-red on cards, yellow on the
 * product-of-the-month panel).
 */
export function AddControl({ product, variant = 'outline-red', size = 'sm', label = 'ADD TO CART', block = false }) {
  const { lineForProduct, addProduct, setQuantity, busy } = useCart();
  const navigate = useNavigate();
  const line = lineForProduct(product.uuid);

  // Clothing, footwear and other sized items: the shopper picks the size /
  // colour on the product page. A card never adds one on their behalf, and
  // never shows a −/+ stepper that would not say which size it changes.
  if (product.requiresChoice) {
    return (
      <Link
        to={`/product/${product.slug}`}
        className={`sf-btn sf-btn--${variant} sf-btn--${size}${block ? ' sf-btn--block' : ''}`}
      >
        {line ? 'IN CART · ADD ANOTHER' : 'SELECT SIZE'}
      </Link>
    );
  }

  if (line) {
    return (
      <div className="sf-qty" aria-label={`${product.name} in cart`} style={block ? undefined : { minWidth: 140 }}>
        <button type="button" aria-label="One fewer" disabled={busy} onClick={() => setQuantity(line.uuid, line.quantity - 1)}>−</button>
        <span>{line.quantity}</span>
        <button type="button" aria-label="One more" disabled={busy} onClick={() => setQuantity(line.uuid, line.quantity + 1)}>+</button>
      </div>
    );
  }

  return (
    <button
      type="button"
      className={`sf-btn sf-btn--${variant} sf-btn--${size}${block ? ' sf-btn--block' : ''}`}
      disabled={busy}
      onClick={async () => {
        if ((await addProduct(product.slug, product.name)) === 'choose') navigate(`/product/${product.slug}`);
      }}
    >
      {label}
    </button>
  );
}

export function Rating({ rating, reviews }) {
  if (!reviews) return null;
  return (
    <div className="sf-rating">
      <b>{Number(rating).toFixed(1)} ★</b>
      <span>({reviews.toLocaleString('en-IN')})</span>
    </div>
  );
}

export function Price({ price, mrp, off }) {
  return (
    <div className="sf-price">
      <span className="sf-price__now">{rupees(price)}</span>
      {off > 0 && mrp > price && (
        <>
          <span className="sf-price__mrp">{rupees(mrp)}</span>
          <span className="sf-price__off">{off}% off</span>
        </>
      )}
    </div>
  );
}

/** Product card used in every grid and shelf — card view-model from lib/store. */
export default function ProductCard({ product, showAdd = true }) {
  const href = `/product/${product.slug}`;

  return (
    <div className="sf-pcard">
      <Link to={href} aria-label={product.name}>
        <ProductMedia image={product.image} tint={product.tint} alt={product.name} className="sf-media--square">
          {product.organic && <span className="sf-badge sf-badge--organic">ORGANIC</span>}
        </ProductMedia>
      </Link>
      <Link to={href} className="sf-pcard__name">{product.name}</Link>
      <Rating rating={product.rating} reviews={product.reviews} />
      <Price price={product.price} mrp={product.mrp} off={product.off} />
      <span className="sf-pcard__size">{product.size || (product.multiple ? 'Multiple sizes' : ' ')}</span>
      {showAdd && (
        <div className="sf-pcard__cta">
          <AddControl product={product} block />
        </div>
      )}
    </div>
  );
}
