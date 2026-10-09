import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, clearTokens } from '../../lib/api';
import TillSignIn, { ALLOWED_ROLES } from './TillSignIn';
import SellTab from './SellTab';
import SalesHistory from './SalesHistory';
import { ErrorBanner, LoadingState, Toast } from './TillShared';
import './Till.css';

/**
 * Point of Sale (till) — ported from admin/assets/page-till.js.
 *
 * This is NOT nested under AdminLayout (see App.jsx's comment on the /till
 * route and lib/api.js's IS_TILL check) and does not reuse an admin
 * console session at all — see TillSignIn.jsx (ported from
 * requireTillSignIn()). Whoever opens /till, admin included, signs in with
 * their own staff login every time this tab hasn't already done so, so a
 * console session left open on someone else's screen can't be walked into
 * as a till just by guessing/bookmarking the URL.
 *
 * Built entirely on top of the same inventory/pricing engine every other
 * phase already uses — ringing up a sale calls the same stock a purchase
 * or mobile scan already deducts from; there is no separate POS stock
 * balance. Payment is cashier-attested (cash/UPI/card/other recorded by
 * whoever is at the counter), not gateway-verified.
 */
export default function Till() {
  const [checkingSession, setCheckingSession] = useState(true);
  const [user, setUser] = useState(null);

  const [loadingSetup, setLoadingSetup] = useState(false);
  const [setupError, setSetupError] = useState(null);
  const [defaultWarehouseUuid, setDefaultWarehouseUuid] = useState(null);
  const [shopLabel, setShopLabel] = useState('');

  const [toast, setToast] = useState(null);
  const toastTimer = useRef(null);

  const [searchParams, setSearchParams] = useSearchParams();
  const tab = searchParams.get('tab') === 'history' ? 'history' : 'sell';

  const notify = useCallback((text, tone = 'success') => {
    clearTimeout(toastTimer.current);
    setToast({ text, tone });
    toastTimer.current = setTimeout(() => setToast(null), 4000);
  }, []);

  // Cached in sessionStorage (per-tab, cleared on sign-out and when the tab
  // closes) so switching tabs doesn't re-prompt every click; a shift
  // change or a closed tab does.
  useEffect(() => {
    let active = true;

    async function restore() {
      let cached = null;

      try {
        const raw = sessionStorage.getItem('till_session_user');
        if (raw) cached = JSON.parse(raw);
      } catch {
        cached = null;
      }

      if (!cached) {
        if (active) setCheckingSession(false);
        return;
      }

      try {
        // The name on screen must be the account the requests really run
        // as. If the saved login is missing or belongs to someone else,
        // sign in again.
        const me = await api.get('/auth/me');
        if (me.data.user && me.data.user.uuid === cached.uuid && active) {
          setUser(cached);
          setCheckingSession(false);
          return;
        }
      } catch {
        // fall through to a fresh sign-in
      }

      try { sessionStorage.removeItem('till_session_user'); } catch { /* ignore */ }
      if (active) setCheckingSession(false);
    }

    restore();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!user) return;
    let active = true;

    async function loadSetup() {
      setLoadingSetup(true);
      setSetupError(null);

      try {
        const response = await api.get('/admin/warehouses', { active_only: true });
        const warehouses = response.data || [];
        // Which warehouse stock is deducted from is resolved silently — the
        // cashier is never asked to pick one; "Shop name" is a free-text
        // label for the receipt, not a warehouse choice.
        const uuid = (warehouses.find((w) => w.is_default) || warehouses[0] || {}).uuid || null;
        if (!active) return;
        setDefaultWarehouseUuid(uuid);

        try {
          setShopLabel(sessionStorage.getItem('till_shop_label') || '');
        } catch {
          setShopLabel('');
        }
      } catch (error) {
        if (active) setSetupError(error);
      } finally {
        if (active) setLoadingSetup(false);
      }
    }

    loadSetup();
    return () => { active = false; };
  }, [user]);

  function handleShopLabelChange(value) {
    setShopLabel(value);
    try { sessionStorage.setItem('till_shop_label', value); } catch { /* remembered value is a convenience only */ }
  }

  function handleSignedIn(signedInUser) {
    setUser(signedInUser);
  }

  function handleSignOut() {
    clearTokens();
    try { sessionStorage.removeItem('till_session_user'); } catch { /* ignore */ }
    setUser(null);
  }

  function setTab(nextTab) {
    setSearchParams((params) => {
      const next = new URLSearchParams(params);
      if (nextTab === 'sell') { next.delete('tab'); next.delete('uuid'); } else { next.set('tab', 'history'); }
      return next;
    });
  }

  if (checkingSession) {
    return <div className="till-app till-app--centered"><LoadingState label="Loading till…" /></div>;
  }

  if (!user || !ALLOWED_ROLES.includes(String(user.role))) {
    return <TillSignIn onSignedIn={handleSignedIn} />;
  }

  return (
    <div className="till-app">
      <header className="till-topbar">
        <div className="till-topbar__title">Point of Sale</div>
        <div className="till-topbar__user">
          <span>{user.full_name || user.name || user.email}</span>
          <button type="button" className="till-btn till-btn--sm till-btn--ghost" onClick={handleSignOut}>Sign out</button>
        </div>
      </header>

      <nav className="till-tabs till-no-print">
        <button type="button" className={`till-tab ${tab === 'sell' ? 'till-tab--active' : ''}`} onClick={() => setTab('sell')}>Sell</button>
        <button type="button" className={`till-tab ${tab === 'history' ? 'till-tab--active' : ''}`} onClick={() => setTab('history')}>Sales history</button>
      </nav>

      <main className="till-main">
        {loadingSetup && <LoadingState label="Loading warehouse setup…" />}
        {setupError && <ErrorBanner error={setupError} />}

        {!loadingSetup && !setupError && (
          tab === 'history'
            ? <SalesHistory user={user} notify={notify} />
            : (
              <SellTab
                cashierName={user.full_name || user.name || ''}
                defaultWarehouseUuid={defaultWarehouseUuid}
                shopLabel={shopLabel}
                onShopLabelChange={handleShopLabelChange}
                notify={notify}
              />
            )
        )}
      </main>

      <Toast toast={toast} />
    </div>
  );
}
