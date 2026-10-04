import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import { CartProvider, useCart } from '../../hooks/useCart';
import { BRAND_LOGO_ALT, BRAND_LOGO_URL } from '../../lib/brand';
import { FREE_DELIVERY_ABOVE, rupees } from '../../lib/store';
import '../../styles/customer-legacy.css';
import '../../styles/storefront.css';

const NAV = [
  ['/shop', 'Shop'],
  ['/gifting', 'Gift boxes'],
  ['/about', 'About'],
];

function totalProducts(category) {
  return Number(category.product_count || 0)
    + (category.children || []).reduce((sum, child) => sum + totalProducts(child), 0);
}

function Brand() {
  return (
    <Link to="/" className="sf-brand" aria-label="5 Star — home">
      <img src={BRAND_LOGO_URL} alt={BRAND_LOGO_ALT} width="44" height="44" />
      <span className="sf-brand__words">
        <span className="sf-brand__name">5 Star</span>
        <span className="sf-brand__tag">Spices &amp; Dry Fruits</span>
      </span>
    </Link>
  );
}

function SearchBox() {
  const navigate = useNavigate();
  const location = useLocation();
  const [params] = useSearchParams();
  const current = location.pathname === '/shop' ? params.get('q') || '' : '';
  const [value, setValue] = useState(current);

  useEffect(() => {
    setValue(current);
  }, [current]);

  function submit(event) {
    event.preventDefault();
    const term = value.trim();
    navigate(term ? `/shop?q=${encodeURIComponent(term)}` : '/shop');
  }

  return (
    <form className="sf-search" role="search" onSubmit={submit}>
      <input
        type="search"
        name="q"
        value={value}
        onChange={(event) => setValue(event.target.value)}
        placeholder="Search for haldi, cardamom, almonds…"
        aria-label="Search products"
        autoComplete="off"
      />
    </form>
  );
}

function CategoryRail({ categories }) {
  const location = useLocation();
  const [params] = useSearchParams();
  const onShop = location.pathname === '/shop';
  const active = onShop ? params.get('category') || 'all' : null;

  return (
    <nav className="sf-rail" aria-label="Categories">
      <Link to="/shop" className={`sf-chip ${active === 'all' && !params.get('q') ? 'sf-chip--active' : ''}`}>
        Everything
      </Link>
      {categories.map((category) => (
        <Link
          key={category.slug}
          to={`/shop?category=${encodeURIComponent(category.slug)}`}
          className={`sf-chip ${active === category.slug ? 'sf-chip--active' : ''}`}
        >
          {category.name}
        </Link>
      ))}
    </nav>
  );
}

function CartToast() {
  const { toast } = useCart();
  if (!toast) return null;

  return (
    <div className={`sf-toast ${toast.kind === 'error' ? 'sf-toast--error' : ''}`} role="status" aria-live="polite">
      <span>{toast.text}</span>
      {toast.kind !== 'error' && <Link to="/cart">View cart</Link>}
    </div>
  );
}

function Shell({ signedIn }) {
  const { count } = useCart();
  const [categories, setCategories] = useState([]);

  useEffect(() => {
    api
      .get('/categories')
      .then((response) => {
        const list = response.data.categories || response.data || [];
        setCategories(list.filter((category) => totalProducts(category) > 0));
      })
      .catch(() => setCategories([]));
  }, []);

  const announcement = [
    FREE_DELIVERY_ABOVE ? `Free delivery above ${rupees(FREE_DELIVERY_ABOVE)}` : null,
    'Dispatched within 24 hours',
    'All prices include GST',
  ].filter(Boolean).join(' · ');

  return (
    <div className="sf">
      <div className="sf-announce">{announcement}</div>

      <header className="sf-header">
        <div className="sf-header__row">
          <Brand />
          <SearchBox />
          <div className="sf-header__actions">
            <nav className="sf-nav" aria-label="Main">
              {NAV.map(([to, label]) => (
                <NavLink key={to} to={to}>{label}</NavLink>
              ))}
            </nav>
            <Link className="sf-account-link" to={signedIn ? '/account' : '/account?next=/'}>
              {signedIn ? 'Account' : 'Sign in'}
            </Link>
            <Link className="sf-cart-btn" to="/cart" aria-label={`Cart, ${count} items`}>
              Cart<span className="sf-cart-btn__count">{count}</span>
            </Link>
          </div>
        </div>
        <CategoryRail categories={categories} />
      </header>

      <main className="sf-main">
        <Outlet />
      </main>

      <footer className="sf-footer">
        <div className="sf-footer__row">
          <div className="sf-footer__brand">
            <img src={BRAND_LOGO_URL} alt="" width="40" height="40" />
            <span>5 Star Spices &amp; Dry Fruits<br />Since 1984</span>
          </div>
          <div className="sf-footer__links">
            <Link to="/shop">Shop</Link>
            <Link to="/gifting">Gift boxes</Link>
            <Link to="/about">About</Link>
            <Link to="/orders">My orders</Link>
            <Link to="/support">Support</Link>
            <Link className="sf-footer__quiet" to="/page/shipping-policy">Shipping</Link>
            <Link className="sf-footer__quiet" to="/page/returns-and-refunds">Returns</Link>
            <Link className="sf-footer__quiet" to="/page/privacy-policy">Privacy</Link>
            <Link className="sf-footer__quiet" to="/page/terms-of-service">Terms</Link>
            <Link className="sf-footer__quiet" to="/faq">FAQ</Link>
          </div>
          <span className="sf-footer__note">Secure UPI payments · All prices include GST</span>
        </div>
      </footer>

      <CartToast />
    </div>
  );
}

export default function CustomerLayout() {
  const { signedIn, ready } = useAuth();
  const { pathname } = useLocation();

  // New page, start at the top (the design's go() does the same).
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);

  return (
    // Reload the cart once the session is restored and any guest cart merged
    // (useAuth's `ready`), and again whenever the person signs in or out.
    <CartProvider refreshKey={ready ? (signedIn ? 'signed-in' : 'guest') : 'starting'}>
      <Shell signedIn={signedIn} />
    </CartProvider>
  );
}
