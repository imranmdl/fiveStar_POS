import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import { CartProvider, useCart } from '../../hooks/useCart';
import { StorefrontThemeProvider, themeVars, useStorefrontTheme } from '../../hooks/useStorefrontTheme';
import { BRAND_LOGO_ALT, BRAND_LOGO_URL } from '../../lib/brand';
import '../../styles/customer-legacy.css';
import '../../styles/storefront.css';

const NAV = [
  ['/shop', 'All products'],
  ['/gifting', 'Gift boxes'],
  ['/about', 'About'],
];

function totalProducts(category) {
  return Number(category.product_count || 0)
    + (category.children || []).reduce((sum, child) => sum + totalProducts(child), 0);
}

function Brand({ tagline }) {
  return (
    <Link to="/" className="sf-brand" aria-label="5 Star — home">
      <span className="sf-brand__logo"><img src={BRAND_LOGO_URL} alt={BRAND_LOGO_ALT} width="38" height="38" /></span>
      <span className="sf-brand__words">
        <span className="sf-brand__name">5 Star</span>
        {tagline && <span className="sf-brand__tag">{tagline}</span>}
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
        placeholder="Search for spices, dry fruits, seeds and more"
        aria-label="Search products"
        autoComplete="off"
      />
      <button type="submit">Search</button>
    </form>
  );
}

function CartToast() {
  const { toast } = useCart();
  if (!toast) return null;

  if (toast.kind === 'error') {
    return <div className="sf-toast sf-toast--error" role="alert"><span>{toast.text}</span></div>;
  }
  return (
    <div className="sf-toast" role="status" aria-live="polite">
      <span><span className="sf-toast__tick">✓</span> {toast.text}</span>
      <Link to="/cart">VIEW CART</Link>
    </div>
  );
}

function Footer({ categories }) {
  return (
    <footer className="sf-footer">
      <div className="sf-footer__cols">
        <div className="sf-footer__col">
          <span className="sf-footer__title">Shop</span>
          <Link to="/shop">All products</Link>
          {categories.slice(0, 5).map((category) => (
            <Link key={category.slug} to={`/shop?category=${encodeURIComponent(category.slug)}`}>{category.name}</Link>
          ))}
        </div>
        <div className="sf-footer__col">
          <span className="sf-footer__title">Company</span>
          <Link to="/about">Our story</Link>
          <Link to="/gifting">Gift boxes</Link>
          <Link to="/gifting#enquiry">Bulk orders</Link>
        </div>
        <div className="sf-footer__col">
          <span className="sf-footer__title">Help</span>
          <Link to="/orders">My orders</Link>
          <Link to="/support">Support</Link>
          <Link to="/page/shipping-policy">Shipping</Link>
          <Link to="/page/returns-and-refunds">Returns</Link>
          <Link to="/faq">FAQ</Link>
          <Link to="/page/privacy-policy">Privacy</Link>
          <Link to="/page/terms-of-service">Terms</Link>
          <Link to="/admin">Staff sign-in</Link>
        </div>
        <div className="sf-footer__col">
          <div className="sf-footer__brand">
            <span className="sf-brand__logo"><img src={BRAND_LOGO_URL} alt="" width="36" height="36" /></span>
            <span>5 Star Spices &amp; Dry Fruits<br />Since 1984</span>
          </div>
        </div>
      </div>
      <div className="sf-footer__bar">
        <div>
          <span>© {new Date().getFullYear()} 5 Star Spices &amp; Dry Fruits</span>
          <span>Secure UPI payments · All prices include GST</span>
        </div>
      </div>
    </footer>
  );
}

function Shell({ signedIn }) {
  const { count } = useCart();
  const theme = useStorefrontTheme();
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

  return (
    <div className="sf" style={themeVars(theme)}>
      <header className="sf-header">
        <div className="sf-header__row">
          <Brand tagline={theme.tagline} />
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
      </header>
      {theme.announcement && <div className="sf-announce">{theme.announcement}</div>}

      <main className="sf-main">
        <Outlet context={{ categories }} />
      </main>

      <Footer categories={categories} />
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
    <StorefrontThemeProvider>
      {/* Reload the cart once the session is restored and any guest cart merged
          (useAuth's `ready`), and again whenever the person signs in or out. */}
      <CartProvider refreshKey={ready ? (signedIn ? 'signed-in' : 'guest') : 'starting'}>
        <Shell signedIn={signedIn} />
      </CartProvider>
    </StorefrontThemeProvider>
  );
}
