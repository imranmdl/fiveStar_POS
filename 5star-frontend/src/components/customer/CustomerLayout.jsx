import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { api, signOut } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import { useCartCount } from '../../hooks/useCartCount';
import { BRAND_LOGO_ALT, BRAND_LOGO_HEIGHT, BRAND_LOGO_URL, BRAND_NAME } from '../../lib/brand';
import './CustomerLayout.css';

const CATEGORY_COLORS = ['var(--brand-marigold)', 'var(--brand-teal)', 'var(--brand-terracotta)', 'var(--brand-saffron)'];

/** Falls back to the text wordmark if the logo image 404s, same as the live site's brandMarkup(). */
function BrandMark() {
  const [imageFailed, setImageFailed] = useState(false);

  if (imageFailed) return <span>{BRAND_NAME}</span>;

  return (
    <img
      src={BRAND_LOGO_URL}
      alt={BRAND_LOGO_ALT}
      height={BRAND_LOGO_HEIGHT}
      style={{ height: BRAND_LOGO_HEIGHT, width: 'auto' }}
      onError={() => setImageFailed(true)}
    />
  );
}

const NAV = [
  ['/', 'Shop'],
  ['/gifting', 'Gifting'],
  ['/orders', 'My orders'],
  ['/loyalty', 'Loyalty'],
  ['/support', 'Support'],
];

export default function CustomerLayout() {
  const { signedIn } = useAuth();
  const { count } = useCartCount(signedIn);
  const [categories, setCategories] = useState([]);
  const navigate = useNavigate();

  useEffect(() => {
    api
      .get('/categories')
      .then((response) => setCategories(response.data.categories || response.data || []))
      .catch(() => setCategories(null));
  }, []);

  function handleSearch(event) {
    event.preventDefault();
    const term = new FormData(event.currentTarget).get('q');
    navigate(`/?q=${encodeURIComponent(String(term || ''))}`);
  }

  async function handleSignOut() {
    await signOut();
    navigate('/');
  }

  return (
    <div className="customer-shell">
      <header className="site-header">
        <div className="site-header__row">
          <Link className="site-header__brand" to="/">
            <BrandMark />
          </Link>

          <form className="header-search" onSubmit={handleSearch}>
            <input
              type="search"
              name="q"
              placeholder="Search for haldi, cardamom, almonds…"
              autoComplete="off"
            />
          </form>

          <nav className="site-header__nav">
            {NAV.map(([to, label]) => (
              <NavLink key={to} to={to} className="site-header__link" end={to === '/'}>
                {label}
              </NavLink>
            ))}
          </nav>

          <div className="site-header__actions">
            <Link className="cart-pill" to="/cart">
              Cart
              {count > 0 && <span className="cart-pill__badge">{count}</span>}
            </Link>
            {signedIn ? (
              <button type="button" className="btn-quiet" onClick={handleSignOut}>
                Sign out
              </button>
            ) : (
              <Link className="btn-marigold" to="/account">
                Sign in
              </Link>
            )}
          </div>
        </div>

        {categories !== null && (
          <div className="category-rail">
            <Link to="/" className="category-chip">
              Everything
            </Link>
            {categories.map((category, index) => (
              <Link key={category.uuid || category.slug} to={`/?category=${encodeURIComponent(category.slug)}`} className="category-chip">
                <span className="category-chip__dot" style={{ background: CATEGORY_COLORS[index % CATEGORY_COLORS.length] }} />
                {category.name}
              </Link>
            ))}
          </div>
        )}
      </header>

      <main className="customer-main">
        <Outlet />
      </main>

      <footer className="site-footer">
        <div className="site-footer__links">
          <Link to="/page/shipping-policy">Shipping</Link>
          <Link to="/page/returns-and-refunds">Returns</Link>
          <Link to="/page/privacy-policy">Privacy</Link>
          <Link to="/page/terms-of-service">Terms</Link>
          <Link to="/faq">FAQ</Link>
        </div>
        <div className="site-footer__note">Prepaid UPI only · All prices include GST</div>
      </footer>
    </div>
  );
}
