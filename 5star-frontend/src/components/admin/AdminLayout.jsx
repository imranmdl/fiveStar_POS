import { useEffect, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { api, storeTokens, signOut, bootstrapSession, isSignedIn, ApiError } from '../../lib/api';
import { BRAND_LOGO_ALT, BRAND_LOGO_URL, BRAND_NAME } from '../../lib/brand';
import './AdminLayout.css';

/** Falls back to the text wordmark if the logo image 404s, same as admin/console.js's brandMarkup(). */
function BrandMark() {
  const [imageFailed, setImageFailed] = useState(false);

  if (imageFailed) return <span>{BRAND_NAME}</span>;

  return <img src={BRAND_LOGO_URL} alt={BRAND_LOGO_ALT} style={{ height: 32, width: 'auto' }} onError={() => setImageFailed(true)} />;
}

/** Screens, grouped for scanability now that the list has grown past the original 17 (ported from admin/console.js). */
const NAV_GROUPS = [
  {
    section: 'Overview',
    items: [['/admin', 'Dashboard', 'What needs attention now']],
  },
  {
    section: 'Catalog & Inventory',
    items: [
      ['/admin/products', 'Products', 'Catalogue'],
      ['/admin/inventory', 'Inventory', 'Stock levels and the movement ledger'],
      ['/admin/purchase-inward', 'Purchase Inward', 'Record purchases from vendors'],
      ['/admin/mobile', 'Mobile Scan', 'Scan barcodes to record inward on the move'],
      ['/admin/pricing', 'Pricing', 'Markup/margin rules and price-change behaviour'],
      ['/admin/vendors', 'Vendors', 'Suppliers stock is bought from'],
      ['/admin/warehouses', 'Warehouses', 'Locations stock is held at'],
      ['/admin/stock-audit', 'Stock Audit', 'Landing cost, selling value and stock-movement checks'],
      ['/admin/barcode-generator', 'Barcode Generator', 'Generate and print barcode labels'],
    ],
  },
  {
    section: 'Sales & POS',
    items: [
      ['/admin/orders', 'Orders', 'Confirm, pack, ship'],
      ['/admin/payments', 'Payments', 'Manual UPI verification'],
      ['/admin/cashiers', 'Cashiers (POS)', 'Till logins and sales history'],
      ['/admin/invoices', 'Invoice Tracking', 'Till invoices and WhatsApp follow-ups'],
      ['/admin/customer-dues', 'Customer Dues', 'POS credit sales still owed, and recording payments'],
      ['/admin/wallets', 'Wallets', 'Customer wallet balances, credits, debits, freezes'],
    ],
  },
  {
    section: 'Marketing',
    items: [
      ['/admin/promotions', 'Promotions', 'Coupons and offers'],
      ['/admin/content', 'Shopfront', 'Categories and adverts'],
      ['/admin/bulk', 'Wholesale', 'Gifting and bulk enquiries'],
      ['/admin/festivals', 'Festival Calendar', "Set up a festival's gift page in one click"],
    ],
  },
  {
    section: 'Customers & Support',
    items: [
      ['/admin/customers', 'Customers', 'Who buys, and how to reach them'],
      ['/admin/support', 'Support', 'Customer tickets'],
      ['/admin/reviews', 'Reviews', 'Moderation queue'],
    ],
  },
  {
    section: 'Reports',
    items: [
      ['/admin/reports', 'Reports', 'Takings, tax and refunds'],
      ['/admin/profit-loss', 'Profit & Loss', 'Margins, invoice loss and damage/loss'],
    ],
  },
  {
    section: 'Admin',
    items: [
      ['/admin/access-control', 'Admin Privilege', 'Staff accounts, roles, approvals, audit log'],
      ['/admin/backups', 'Backups', 'Database backup, restore and old-data cleanup'],
    ],
  },
];

const STAFF_ROLES = ['administrator', 'supervisor', 'executive', 'manager', 'inventory_staff', 'cashier'];

function SignInScreen({ message, onSignedIn }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const response = await api.post('/auth/login', Object.fromEntries(new FormData(event.currentTarget).entries()));
      storeTokens(response.data.tokens);
      onSignedIn();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not reach the API.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="admin-signin">
      <div className="admin-signin__card">
        <div className="admin-signin__brand">
          <BrandMark />
        </div>
        <h1>Staff sign-in</h1>
        <p className="admin-signin__hint">Console</p>
        {message && <div className="admin-alert admin-alert--warning">{message}</div>}
        {error && <div className="admin-alert admin-alert--danger">{error}</div>}
        <form onSubmit={handleSubmit}>
          <label htmlFor="identifier">Mobile or email</label>
          <input id="identifier" name="identifier" required autoComplete="username" />
          <label htmlFor="password">Password</label>
          <input id="password" name="password" type="password" required autoComplete="current-password" />
          <button type="submit" disabled={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  );
}

/**
 * Boots the admin console: restores the session, confirms the account is
 * staff, and renders either the sign-in screen or the sidebar shell. Ported
 * from admin/console.js's mountConsole — role is checked here as a courtesy
 * (a nicer error than a wall of 403s), the server remains the real authority.
 */
export default function AdminLayout() {
  const [state, setState] = useState({ status: 'loading', user: null, message: null });
  const [bootVersion, setBootVersion] = useState(0);
  const navigate = useNavigate();

  useEffect(() => {
    let mounted = true;

    async function boot() {
      await bootstrapSession();

      if (!isSignedIn()) {
        if (mounted) setState({ status: 'signedOut', user: null, message: null });
        return;
      }

      try {
        const response = await api.get('/auth/me');
        const user = response.data.user;

        if (!STAFF_ROLES.includes(String(user.role))) {
          await signOut();
          if (mounted) setState({ status: 'signedOut', user: null, message: 'That account is not a staff account. Use a staff sign-in.' });
          return;
        }

        if (mounted) setState({ status: 'ready', user, message: null });
      } catch (err) {
        await signOut();
        if (mounted) {
          setState({
            status: 'signedOut',
            user: null,
            message: err instanceof ApiError && err.status === 401
              ? 'Your session has ended. Please sign in again.'
              : 'Could not confirm your account.',
          });
        }
      }
    }

    boot();
    return () => {
      mounted = false;
    };
  }, [bootVersion]);

  if (state.status === 'loading') {
    return (
      <div className="admin-signin">
        <div className="admin-signin__spinner" role="status" aria-label="Loading" />
      </div>
    );
  }

  if (state.status === 'signedOut') {
    return (
      <SignInScreen
        message={state.message}
        onSignedIn={() => {
          setState({ status: 'loading', user: null, message: null });
          setBootVersion((v) => v + 1);
        }}
      />
    );
  }

  async function handleSignOut() {
    await signOut();
    navigate('/admin');
    setState({ status: 'signedOut', user: null, message: null });
  }

  return (
    <div className="admin-shell">
      <nav className="admin-sidebar">
        <div className="admin-sidebar__brand">
          <BrandMark />
        </div>
        <div className="admin-sidebar__scroll">
          {NAV_GROUPS.map((group) => (
            <div key={group.section} className="admin-sidebar__group">
              <div className="admin-sidebar__section">{group.section}</div>
              <ul>
                {group.items.map(([to, label, hint]) => (
                  <li key={to}>
                    <NavLink to={to} end={to === '/admin'} className={({ isActive }) => (isActive ? 'active' : '')}>
                      {label}
                      <span className="admin-sidebar__hint">{hint}</span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
        <hr />
        <div className="admin-sidebar__user">
          Signed in as
          <br />
          <strong>{state.user.full_name}</strong>
          <br />
          <span className="admin-sidebar__role">{String(state.user.role || '').replace('_', ' ')}</span>
        </div>
        <button type="button" className="admin-sidebar__signout" onClick={handleSignOut}>
          Sign out
        </button>
      </nav>
      <main className="admin-main">
        <Outlet context={{ user: state.user }} />
      </main>
    </div>
  );
}
