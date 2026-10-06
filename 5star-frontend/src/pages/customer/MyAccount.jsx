import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, signOut } from '../../lib/api';
import { rupees } from '../../lib/store';
import AddressForm from '../../components/customer/AddressForm';
import OrderCard, { formatDate, orderAction } from '../../components/customer/OrderCard';

function initials(name) {
  return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '?';
}

/** Loads one API path, keeping the rest of the page usable if it fails. */
function useLoad(path, pick) {
  const [state, setState] = useState({ data: undefined, error: null });
  const reload = useCallback(() => {
    api.get(path)
      .then((response) => setState({ data: pick(response), error: null }))
      .catch((err) => setState({ data: null, error: err.message }));
  }, [path]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { reload(); }, [reload]);
  return [state, reload];
}

function ProfileCard({ user, onSaved }) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ full_name: user.full_name || '', email: user.email || '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function save(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = await api.patch('/auth/me', form);
      onSaved(response.data.user);
      setEditing(false);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="sf-panel">
      <div className="sf-hello">
        <div className="sf-hello__avatar" aria-hidden="true">{initials(user.full_name)}</div>
        <div className="sf-hello__who">
          <b>{user.full_name}</b>
          <span>
            +91 {user.mobile} {user.mobile_verified && <span className="sf-verified">· Verified</span>}
          </span>
          <span>{user.email || 'No email added'}</span>
          <span className="sf-small">Member since {formatDate(user.created_date)}</span>
        </div>
        {!editing && (
          <div className="sf-hello__actions">
            <button type="button" className="sf-btn sf-btn--outline sf-btn--sm" onClick={() => setEditing(true)}>Edit profile</button>
          </div>
        )}
      </div>
      {editing && (
        <form className="sf-section sf-form-grid" onSubmit={save} style={{ paddingTop: 0 }}>
          {error && <div className="sf-error sf-field--full">{error}</div>}
          <label className="sf-field">Your name
            <input required minLength={2} maxLength={120} autoComplete="name" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          </label>
          <label className="sf-field">Email (optional)
            <input type="email" autoComplete="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </label>
          <p className="sf-small sf-field--full" style={{ margin: 0 }}>Your mobile number is how you sign in, so it can’t be changed here. Contact us if you need to move to a new number.</p>
          <div className="sf-field--full" style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            <button type="submit" className="sf-btn sf-btn--red" disabled={busy}>{busy ? 'Saving…' : 'SAVE'}</button>
            <button type="button" className="sf-btn sf-btn--ghost" onClick={() => { setEditing(false); setError(null); }}>CANCEL</button>
          </div>
        </form>
      )}
    </div>
  );
}

function Addresses() {
  const [{ data: addresses, error }, reload] = useLoad('/addresses', (r) => r.data.addresses || []);
  const [editing, setEditing] = useState(null); // null | 'new' | uuid
  const [busy, setBusy] = useState(null);
  const [actionError, setActionError] = useState(null);

  async function run(uuid, action) {
    setBusy(uuid);
    setActionError(null);
    try {
      await action();
      reload();
    } catch (err) {
      setActionError(err.message);
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="sf-panel sf-section" id="addresses">
      <div className="sf-section__head">
        <h2>Saved addresses</h2>
        {editing !== 'new' && <button type="button" className="sf-link" onClick={() => setEditing('new')}>+ Add address</button>}
      </div>
      {error && <div className="sf-error">{error}</div>}
      {actionError && <div className="sf-error">{actionError}</div>}
      {editing === 'new' && (
        <AddressForm onSaved={() => { setEditing(null); reload(); }} onCancel={() => setEditing(null)} />
      )}
      {addresses === undefined ? (
        <p className="sf-small">Loading…</p>
      ) : addresses && addresses.length === 0 && editing !== 'new' ? (
        <p className="sf-small">No saved addresses yet. Add one now, or at checkout.</p>
      ) : (
        <div className="sf-rows">
          {(addresses || []).map((a) => (
            <div className="sf-row" key={a.uuid}>
              {editing === a.uuid ? (
                <div style={{ flex: 1 }}>
                  <AddressForm address={a} onSaved={() => { setEditing(null); reload(); }} onCancel={() => setEditing(null)} />
                </div>
              ) : (
                <div className="sf-row__body">
                  <span>
                    <b>{a.contact_name}</b>{' '}
                    {a.label && <span className="sf-tag">{a.label}</span>}{' '}
                    {a.is_default && <span className="sf-tag sf-tag--good">Default</span>}
                  </span>
                  <span>{[a.address_line1, a.address_line2, a.landmark, a.city, a.state, a.pincode].filter(Boolean).join(', ')}</span>
                  <span>+91 {a.contact_mobile}</span>
                  <span className="sf-row__actions">
                    <button type="button" onClick={() => setEditing(a.uuid)}>Edit</button>
                    {!a.is_default && (
                      <button type="button" disabled={busy === a.uuid} onClick={() => run(a.uuid, () => api.post(`/addresses/${a.uuid}/default`, {}))}>Make default</button>
                    )}
                    <button
                      type="button"
                      disabled={busy === a.uuid}
                      onClick={() => { if (window.confirm('Remove this address?')) run(a.uuid, () => api.delete(`/addresses/${a.uuid}`)); }}
                    >
                      Remove
                    </button>
                  </span>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function Coupons() {
  const [{ data: coupons }] = useLoad('/cart/coupons', (r) => r.data.coupons || []);
  const [copied, setCopied] = useState(null);

  if (!coupons || coupons.length === 0) return null;

  function copy(code) {
    navigator.clipboard?.writeText(code).then(() => setCopied(code)).catch(() => {});
    setTimeout(() => setCopied(null), 1800);
  }

  return (
    <div className="sf-panel sf-section" id="offers">
      <div className="sf-section__head">
        <h2>Offers for you</h2>
        <Link to="/shop">Shop now</Link>
      </div>
      {coupons.map((c) => (
        <div className="sf-coupon" key={c.code}>
          <button type="button" className="sf-coupon__code" onClick={() => copy(c.code)} title="Copy code">
            {copied === c.code ? 'COPIED' : c.code}
          </button>
          <div className="sf-coupon__body">
            <b>{c.title}</b>
            <span>{c.description || c.summary}</span>
            {c.terms && <span>{c.terms}</span>}
            {c.valid_to && <span>Valid till {formatDate(c.valid_to)}</span>}
          </div>
        </div>
      ))}
      <p className="sf-small" style={{ margin: 0 }}>Apply a code in your cart. Automatic offers are taken off at checkout — no code needed.</p>
    </div>
  );
}

function Refer() {
  const [{ data: referral }] = useLoad('/referrals', (r) => r.data.referral);
  const [copied, setCopied] = useState(false);

  if (!referral) return null;

  async function share() {
    const text = referral.share_message;
    if (navigator.share) {
      try {
        await navigator.share({ text, url: referral.share_url });
        return;
      } catch {
        // fall back to copying
      }
    }
    navigator.clipboard?.writeText(`${text} ${referral.share_url}`).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1800);
    }).catch(() => {});
  }

  return (
    <div className="sf-panel sf-section" id="refer">
      <div className="sf-section__head"><h2>Refer &amp; earn</h2></div>
      <p className="sf-small" style={{ margin: 0 }}>
        Your friend gets {rupees(referral.rewards.friend_gets)} and you get {rupees(referral.rewards.you_get)} in wallet credit after their first order of {rupees(referral.rewards.minimum_first_order)} or more.
      </p>
      <div className="sf-refer">
        <span className="sf-refer__code">{referral.referral_code}</span>
        <button type="button" className="sf-btn sf-btn--outline-red sf-btn--sm" onClick={share}>{copied ? 'COPIED' : 'SHARE'}</button>
      </div>
      <span className="sf-small">
        Invited {referral.progress.total_invited} · Rewarded {referral.progress.rewarded} · Earned {rupees(referral.progress.total_earned)}
      </span>
    </div>
  );
}

function ChangePassword() {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState(null);
  const [error, setError] = useState(null);

  async function save(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.post('/auth/password/change', form);
      setMessage('Password changed.');
      setForm({ current_password: '', password: '', password_confirmation: '' });
      setOpen(false);
    } catch (err) {
      setError(err.fieldMessages && err.fieldMessages().length ? err.fieldMessages().join(' ') : err.message);
    } finally {
      setBusy(false);
    }
  }

  if (!open) {
    return (
      <button type="button" onClick={() => { setOpen(true); setMessage(null); }}>
        <span>Change password{message ? ` — ${message}` : ''}</span><span aria-hidden="true">›</span>
      </button>
    );
  }

  return (
    <form className="sf-form-grid" onSubmit={save} style={{ padding: '12px 0', borderTop: '1px solid var(--sf-line)' }}>
      {error && <div className="sf-error sf-field--full">{error}</div>}
      <label className="sf-field sf-field--full">Current password
        <input type="password" required autoComplete="current-password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} />
      </label>
      <label className="sf-field">New password
        <input type="password" required autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
      </label>
      <label className="sf-field">Confirm new password
        <input type="password" required autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />
      </label>
      <p className="sf-small sf-field--full" style={{ margin: 0 }}>Signed up with “Sign in with Phone”? You don’t need a password — keep signing in with your phone.</p>
      <div className="sf-field--full" style={{ display: 'flex', gap: 12 }}>
        <button type="submit" className="sf-btn sf-btn--red sf-btn--sm" disabled={busy}>{busy ? 'Saving…' : 'CHANGE PASSWORD'}</button>
        <button type="button" className="sf-btn sf-btn--ghost sf-btn--sm" onClick={() => setOpen(false)}>CANCEL</button>
      </div>
    </form>
  );
}

/**
 * My account — what a signed-in customer sees at /account: their details,
 * what needs their attention, recent orders, wallet / points / referral,
 * saved addresses, offers, and account settings.
 */
export default function MyAccount() {
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [userError, setUserError] = useState(null);
  const [{ data: recent }] = useLoad('/orders?per_page=5', (r) => ({ items: r.data || [], total: r.meta?.total ?? (r.data || []).length }));
  const [{ data: wallet }] = useLoad('/wallet', (r) => r.data.wallet);
  const [{ data: loyalty }] = useLoad('/loyalty', (r) => r.data.loyalty);

  useEffect(() => {
    document.title = 'My account · 5 Star';
    api.get('/auth/me').then((r) => setUser(r.data.user)).catch((err) => setUserError(err.message));
  }, []);

  async function handleSignOut() {
    await signOut();
    navigate('/', { replace: true });
  }

  if (userError) return <div className="sf-panel sf-panel--pad"><div className="sf-error">Couldn’t load your account: {userError}</div></div>;
  if (!user) return <div className="sf-panel sf-panel--pad sf-muted">Loading your account…</div>;

  const orders = recent?.items || [];
  const attention = orders.filter((o) => orderAction(o));
  const activeCount = orders.filter((o) => !['delivered', 'cancelled', 'returned', 'refunded'].includes(o.status)).length;

  return (
    <div className="sf-acct">
      <ProfileCard user={user} onSaved={setUser} />

      <div className="sf-tiles">
        <Link className="sf-tile" to="/orders">
          <span>Orders</span>
          <b>{recent ? recent.total : '…'}</b>
          <small>{activeCount > 0 ? `${activeCount} on the way` : 'View history'}</small>
        </Link>
        <a className="sf-tile" href="#wallet">
          <span>Wallet</span>
          <b>{wallet ? rupees(wallet.balance) : '…'}</b>
          <small>Use at checkout</small>
        </a>
        <Link className="sf-tile" to="/loyalty">
          <span>Points</span>
          <b>{loyalty ? loyalty.balance : '…'}</b>
          <small>{loyalty ? `Worth ${rupees(loyalty.balance * loyalty.redeem_value_per_point)}` : 'Loyalty'}</small>
        </Link>
        <a className="sf-tile" href="#offers">
          <span>Offers</span>
          <b>%</b>
          <small>Coupons for you</small>
        </a>
      </div>

      {attention.length > 0 && (
        <div className="sf-panel sf-action sf-section">
          <div className="sf-section__head"><h2>Needs your attention</h2></div>
          {attention.map((o) => {
            const action = orderAction(o);
            return (
              <div key={o.uuid} className="sf-row">
                <div className="sf-row__body">
                  <b>{o.order_number} · {rupees(o.grand_total)}</b>
                  <span>{action.kind === 'verify' ? 'Confirm your mobile number so we can process this order.' : 'Payment not received yet.'}</span>
                </div>
                <Link className="sf-btn sf-btn--red sf-btn--sm" to={`/orders/${o.uuid}?${action.kind}=1`}>{action.kind === 'verify' ? 'CONFIRM' : 'PAY NOW'}</Link>
              </div>
            );
          })}
        </div>
      )}

      <div className="sf-acct__grid">
        <div className="sf-acct__col">
          <div className="sf-panel sf-section">
            <div className="sf-section__head">
              <h2>Recent orders</h2>
              <Link to="/orders">See all orders</Link>
            </div>
            {recent === undefined ? (
              <p className="sf-small">Loading…</p>
            ) : orders.length === 0 ? (
              <div className="sf-empty" style={{ padding: '24px 0' }}>
                <b>No orders yet</b>
                <Link className="sf-btn sf-btn--red" to="/shop">START SHOPPING</Link>
              </div>
            ) : (
              <div className="sf-olist">
                {orders.slice(0, 3).map((o) => <OrderCard key={o.uuid} order={o} />)}
              </div>
            )}
          </div>

          <Addresses />
        </div>

        <div className="sf-acct__col">
          <div className="sf-panel sf-section" id="wallet">
            <div className="sf-section__head">
              <h2>Wallet</h2>
            </div>
            <div className="sf-bill">
              <div className="sf-bill__row sf-bill__row--total" style={{ borderTop: 0, paddingTop: 0 }}>
                <span>Balance</span><span>{wallet ? rupees(wallet.balance) : '…'}</span>
              </div>
              {wallet && (
                <>
                  <div className="sf-bill__row"><span>Total credited</span><span>{rupees(wallet.lifetime_credited)}</span></div>
                  <div className="sf-bill__row"><span>Total used</span><span>{rupees(wallet.lifetime_debited)}</span></div>
                  {wallet.redemption?.enabled && (
                    <p className="sf-small" style={{ margin: 0 }}>
                      Use up to {wallet.redemption.max_percent_of_order}% of an order’s value from your wallet at checkout.
                    </p>
                  )}
                  {wallet.is_frozen && <div className="sf-error">Your wallet is on hold{wallet.frozen_reason ? `: ${wallet.frozen_reason}` : ''}. Contact support.</div>}
                </>
              )}
            </div>
          </div>

          <Refer />
          <Coupons />

          <div className="sf-panel sf-section">
            <div className="sf-section__head"><h2>Account</h2></div>
            <div className="sf-linklist">
              <Link to="/orders"><span>My orders</span><span aria-hidden="true">›</span></Link>
              <Link to="/loyalty"><span>Loyalty points</span><span aria-hidden="true">›</span></Link>
              <Link to="/support"><span>Help &amp; support tickets</span><span aria-hidden="true">›</span></Link>
              <Link to="/faq"><span>FAQ</span><span aria-hidden="true">›</span></Link>
              <ChangePassword />
              <button type="button" onClick={handleSignOut}><span>Sign out</span><span aria-hidden="true">›</span></button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
