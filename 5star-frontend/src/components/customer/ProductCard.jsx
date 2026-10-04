import { Link } from 'react-router-dom';
import { useCart } from '../../hooks/useCart';
import { rupees } from '../../lib/store';

/**
 * Product picture tile. Shows the product photo when there is one; until the
 * catalogue has photos it is the design's warm tinted block with a caption.
 */
export function ProductMedia({ image, tint, label, className = '', children, style, alt = '' }) {
  return (
    <div className={`sf-media ${className}`} style={{ background: tint, ...style }}>
      {image && <img src={image} alt={alt} loading="lazy" />}
      {!image && label && <span className="sf-media__label">{label}</span>}
      {children}
    </div>
  );
}

/** The stepper or Add button for a product, kept in sync with the cart. */
export function AddControl({ product, size = 'sm', tone = 'add', label }) {
  const { lineForProduct, addProduct, setQuantity, busy } = useCart();
  const line = lineForProduct(product.uuid);

  if (line) {
    return (
      <div className="sf-stepper" aria-label={`${product.name} in cart`}>
        <button type="button" aria-label="One fewer" disabled={busy} onClick={() => setQuantity(line.uuid, line.quantity - 1)}>−</button>
        <span>{line.quantity}</span>
        <button type="button" aria-label="One more" disabled={busy} onClick={() => setQuantity(line.uuid, line.quantity + 1)}>+</button>
      </div>
    );
  }

  return (
    <button
      type="button"
      className={`sf-btn sf-btn--${tone} sf-btn--${size}`}
      disabled={busy}
      onClick={() => addProduct(product.slug, product.name)}
    >
      {label || (size === 'xs' ? '+ Add' : 'Add')}
    </button>
  );
}

/** Product card used on Home, Shop and Gifts — card view-model from lib/store. */
export default function ProductCard({ product }) {
  const href = `/product/${product.slug}`;
  const meta = [product.size, product.sub].filter(Boolean).join(' · ');

  return (
    <div className="sf-card">
      <Link to={href} className="sf-card__media" aria-label={product.name}>
        <ProductMedia
          image={product.image}
          tint={product.tint}
          label={product.sub}
          alt={product.name}
          className="sf-media--square"
        >
          <div className="sf-card__badges">
            {product.off > 0 && <span className="sf-pill sf-pill--off">{product.off}% off</span>}
            {product.organic && <span className="sf-pill sf-pill--organic">Organic</span>}
          </div>
        </ProductMedia>
      </Link>
      <Link to={href} className="sf-card__text">
        <span className="sf-card__name">{product.name}</span>
        {meta && <span className="sf-card__meta">{meta}</span>}
      </Link>
      <div className="sf-card__foot">
        <div className="sf-price">
          <span className="sf-price__now">{rupees(product.price)}</span>
          {product.off > 0 && product.mrp > product.price && <span className="sf-price__mrp">{rupees(product.mrp)}</span>}
        </div>
        <AddControl product={product} />
      </div>
    </div>
  );
}
