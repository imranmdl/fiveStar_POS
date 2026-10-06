import { useCallback, useEffect, useState } from 'react';
import { api, ApiError, formatMoney } from '../../lib/api';
import { ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Rewards.css';

/**
 * Loyalty points — the rules (switch on, earn rate, redeem value, limits,
 * expiry, bonus points for reviews and referrals), store-wide totals, every
 * customer's balance and ledger, and manual add/remove with a reason.
 *
 * Points are earned automatically when an online order's payment is
 * confirmed or a till sale completes (LoyaltyService::earnForPurchase), for an
 * approved review and for a rewarded referral, and spent by the customer at
 * checkout. This page only edits the rules and makes manual adjustments.
 */

const SOURCE = {
  purchase_online: 'Online order',
  purchase_pos: 'Shop purchase',
  review: 'Review',
  referral: 'Referral',
  redemption: 'Used on an order',
  expiry: 'Expired',
  admin_adjustment: 'Adjusted by admin',
};

function errorText(error, fallback) {
  if (error instanceof ApiError) {
    const fields = error.fieldMessages ? error.fieldMessages() : [];
    return fields.length ? fields.join(' ') : error.message;
  }
  return fallback;
}

function when(value) {
  return value ? String(value).slice(0, 16).replace('T', ' ') : '—';
}

function Modal({ title, onClose, children }) {
  return (
    <div className="rw-backdrop" onClick={onClose}>
      <div className="rw-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-label={title}>
        <div className="rw-modal__head"><h2>{title}</h2><button type="button" onClick={onClose} aria-label="Close">&times;</button></div>
        <div className="rw-modal__body">{children}</div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------
function Rules({ onSaved }) {
  const [form, setForm] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get('/admin/loyalty/settings')
      .then((r) => setForm(r.data.settings))
      .catch((e) => setError(e));
  }, []);

  if (error && !form) return <div className="rw-card"><div className="rw-card__body"><ErrorState error={error} /></div></div>;
  if (!form) return <div className="rw-card"><div className="rw-card__body"><LoadingState /></div></div>;

  const set = (key, numeric = true) => (e) => {
    const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    setForm((f) => ({ ...f, [key]: e.target.type === 'checkbox' || !numeric ? value : value }));
  };

  async function save(event) {
    event.preventDefault();
    setBusy(true);
    try {
      const body = {
        enabled: !!form.enabled,
        rupees_per_point: Number(form.rupees_per_point),
        redeem_value_per_point: Number(form.redeem_value_per_point),
        min_redeem_points: Number(form.min_redeem_points),
        max_redeem_points_per_order: Number(form.max_redeem_points_per_order),
        points_expiry_days: Number(form.points_expiry_days),
        review_points_enabled: !!form.review_points_enabled,
        points_per_review: Number(form.points_per_review),
        referral_points_enabled: !!form.referral_points_enabled,
        points_per_referral: Number(form.points_per_referral),
      };
      const r = await api.patch('/admin/loyalty/settings', body);
      setForm(r.data.settings);
      toast('Loyalty rules saved.', 'success');
      onSaved();
    } catch (e) {
      toast(errorText(e, 'Could not save the loyalty rules.'), 'danger');
    } finally {
      setBusy(false);
    }
  }

  const per = Number(form.rupees_per_point) || 0;
  const value = Number(form.redeem_value_per_point) || 0;
  const examplePoints = per > 0 ? Math.floor(1000 / per) : 0;

  return (
    <form className="rw-card" onSubmit={save}>
      <div className="rw-card__head">
        <h2>Rules</h2>
        <span className={`rw-pill ${form.enabled ? 'rw-pill--on' : 'rw-pill--off'}`}>{form.enabled ? 'Switched on' : 'Switched off'}</span>
      </div>
      <div className="rw-card__body">
        <label className="rw-switch">
          <input type="checkbox" checked={!!form.enabled} onChange={set('enabled')} />
          Customers earn and use loyalty points
        </label>
        {!form.enabled && <div className="rw-note rw-note--warn">Points are off — customers earn nothing until you switch this on and save.</div>}

        <div className="rw-form">
          <label className="rw-field">Earn 1 point for every
            <span className="rw-input-unit"><span>₹</span><input type="number" min="1" step="1" required value={form.rupees_per_point} onChange={set('rupees_per_point')} /></span>
            <small>Spent on a paid online order or a shop (till) sale.</small>
          </label>
          <label className="rw-field">Each point is worth
            <span className="rw-input-unit"><span>₹</span><input type="number" min="0.01" step="0.01" required value={form.redeem_value_per_point} onChange={set('redeem_value_per_point')} /></span>
            <small>Taken off the bill when the customer uses points.</small>
          </label>
          <label className="rw-field">Minimum points to use
            <input type="number" min="1" step="1" required value={form.min_redeem_points} onChange={set('min_redeem_points')} />
          </label>
          <label className="rw-field">Most points per order
            <input type="number" min="0" step="1" required value={form.max_redeem_points_per_order} onChange={set('max_redeem_points_per_order')} />
            <small>0 = no limit.</small>
          </label>
          <label className="rw-field">Points expire after
            <span className="rw-input-unit"><input type="number" min="0" step="1" required value={form.points_expiry_days} onChange={set('points_expiry_days')} /><span>days</span></span>
            <small>0 = never expire.</small>
          </label>
        </div>

        {per > 0 && (
          <div className="rw-example">
            Example: a ₹1,000 order earns {examplePoints} points, worth {formatMoney(examplePoints * value)} on a later order.
          </div>
        )}

        <div className="rw-form">
          <div className="rw-field">
            <label className="rw-switch"><input type="checkbox" checked={!!form.review_points_enabled} onChange={set('review_points_enabled')} /> Bonus points for a product review</label>
            <span className="rw-input-unit"><input type="number" min="0" step="1" value={form.points_per_review} onChange={set('points_per_review')} disabled={!form.review_points_enabled} /><span>points per approved review</span></span>
          </div>
          <div className="rw-field">
            <label className="rw-switch"><input type="checkbox" checked={!!form.referral_points_enabled} onChange={set('referral_points_enabled')} /> Bonus points for a referral</label>
            <span className="rw-input-unit"><input type="number" min="0" step="1" value={form.points_per_referral} onChange={set('points_per_referral')} disabled={!form.referral_points_enabled} /><span>points when a friend’s first order qualifies</span></span>
          </div>
        </div>

        <div className="rw-actions">
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Save rules'}</button>
        </div>
      </div>
    </form>
  );
}

// ---------------------------------------------------------------------------
// One customer's ledger + manual adjustment
// ---------------------------------------------------------------------------
function CustomerModal({ account, onClose, onChanged }) {
  const [ledger, setLedger] = useState(null);
  const [error, setError] = useState(null);
  const [form, setForm] = useState({ direction: 'credit', points: '', reason: '' });
  const [busy, setBusy] = useState(false);
  const [balance, setBalance] = useState(account.points_balance);

  const load = useCallback(() => {
    api.get(`/admin/loyalty/accounts/${encodeURIComponent(account.user_uuid)}/ledger`, { per_page: 100 })
      .then((r) => setLedger(r.data || []))
      .catch((e) => setError(e));
  }, [account.user_uuid]);

  useEffect(() => { load(); }, [load]);

  async function adjust(event) {
    event.preventDefault();
    setBusy(true);
    try {
      const r = await api.post(`/admin/loyalty/accounts/${encodeURIComponent(account.user_uuid)}/adjust`, {
        direction: form.direction,
        points: Number(form.points),
        reason: form.reason,
      });
      const pts = Number(r.data?.points ?? form.points);
      setBalance((b) => Number(b) + (form.direction === 'credit' ? pts : -pts));
      toast(`${form.direction === 'credit' ? 'Added' : 'Removed'} ${form.points} points.`, 'success');
      setForm({ direction: form.direction, points: '', reason: '' });
      load();
      onChanged();
    } catch (e) {
      toast(errorText(e, 'Could not adjust points.'), 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal title={`${account.full_name} · ${account.mobile}`} onClose={onClose}>
      <div className="rw-tiles">
        <div className="rw-tile"><span>Balance</span><b>{balance}</b></div>
        <div className="rw-tile"><span>Earned</span><b>{account.lifetime_earned}</b></div>
        <div className="rw-tile"><span>Used</span><b>{account.lifetime_redeemed}</b></div>
      </div>

      <form className="rw-form" onSubmit={adjust}>
        <label className="rw-field">Action
          <select value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}>
            <option value="credit">Add points</option>
            <option value="debit">Remove points</option>
          </select>
        </label>
        <label className="rw-field">Points
          <input type="number" min="1" step="1" required value={form.points} onChange={(e) => setForm({ ...form, points: e.target.value })} />
        </label>
        <label className="rw-field rw-field--full">Reason (the customer sees this in their statement)
          <input required minLength={3} maxLength={255} placeholder="e.g. Goodwill for a delayed delivery" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
        </label>
        <div className="rw-actions rw-field--full">
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : form.direction === 'credit' ? 'Add points' : 'Remove points'}</button>
        </div>
      </form>

      <div>
        <b style={{ fontSize: 14 }}>Points history</b>
        {error ? <ErrorState error={error} /> : ledger === null ? <LoadingState /> : ledger.length === 0 ? (
          <p className="rw-sub">No points earned or used yet.</p>
        ) : (
          <div className="admin-table-scroll">
            <table className="admin-table">
              <thead><tr><th>Date</th><th>What</th><th className="rw-num">Points</th><th className="rw-num">Balance</th></tr></thead>
              <tbody>
                {ledger.map((row) => (
                  <tr key={row.uuid}>
                    <td>{when(row.created_date)}</td>
                    <td>{SOURCE[row.source] || row.source}{row.narration && <div className="rw-sub">{row.narration}</div>}{row.expires_date && row.direction === 'credit' && <div className="rw-sub">Expires {String(row.expires_date).slice(0, 10)}</div>}</td>
                    <td className={`rw-num ${row.direction === 'credit' ? 'rw-credit' : 'rw-debit'}`}>{row.direction === 'credit' ? '+' : '−'}{row.points}</td>
                    <td className="rw-num">{row.balance_after}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </Modal>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export default function Loyalty() {
  const [summary, setSummary] = useState(null);
  const [accounts, setAccounts] = useState(null);
  const [error, setError] = useState(null);
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(null);
  const [findMobile, setFindMobile] = useState('');
  const [finding, setFinding] = useState(false);

  const loadSummary = useCallback(() => {
    api.get('/admin/loyalty/summary').then((r) => setSummary(r.data.summary)).catch(() => {});
  }, []);

  const loadAccounts = useCallback(() => {
    api.get('/admin/loyalty/accounts', { search: query || undefined, per_page: 50 })
      .then((r) => setAccounts(r.data || []))
      .catch((e) => setError(e));
  }, [query]);

  useEffect(() => { loadSummary(); }, [loadSummary]);
  useEffect(() => { loadAccounts(); }, [loadAccounts]);

  // Give points to a customer who has never earned any (so has no account row yet).
  async function openByMobile(event) {
    event.preventDefault();
    setFinding(true);
    try {
      const r = await api.get('/admin/pos/customers', { mobile: findMobile.trim() });
      const c = r.data;
      setOpen({ user_uuid: c.uuid, full_name: c.full_name, mobile: c.mobile, points_balance: 0, lifetime_earned: 0, lifetime_redeemed: 0, ...(accounts || []).find((a) => a.user_uuid === c.uuid) });
    } catch (e) {
      toast(errorText(e, 'No customer with that mobile number.'), 'danger');
    } finally {
      setFinding(false);
    }
  }

  return (
    <div className="page rw-page">
      <div className="rw-head">
        <h1 className="admin-page-title">Loyalty points</h1>
      </div>
      <p className="rw-lead">
        Customers earn points automatically when an online order is paid or a shop sale is completed, and use them for money off at checkout.
        Set the rules here, and add or remove points for any customer.
      </p>

      <div className="rw-tiles">
        <div className="rw-tile"><span>Status</span><b>{summary ? (summary.enabled ? 'On' : 'Off') : '…'}</b></div>
        <div className="rw-tile"><span>Points issued</span><b>{summary ? summary.total_issued : '…'}</b></div>
        <div className="rw-tile"><span>Points used</span><b>{summary ? summary.total_redeemed : '…'}</b></div>
        <div className="rw-tile"><span>Points outstanding</span><b>{summary ? summary.total_remaining : '…'}</b><small>Held by customers</small></div>
        <div className="rw-tile"><span>Customers</span><b>{summary ? summary.account_count : '…'}</b><small>With a points account</small></div>
      </div>

      <Rules onSaved={loadSummary} />

      <div className="rw-card">
        <div className="rw-card__head"><h2>Customers</h2></div>
        <div className="rw-card__body">
          <form className="rw-search" onSubmit={(e) => { e.preventDefault(); setQuery(search.trim()); }}>
            <input placeholder="Search name or mobile" value={search} onChange={(e) => setSearch(e.target.value)} />
            <button type="submit" className="admin-btn">Search</button>
          </form>
          <form className="rw-search" onSubmit={openByMobile}>
            <input inputMode="numeric" maxLength={10} placeholder="Give points to a customer by mobile number" value={findMobile} onChange={(e) => setFindMobile(e.target.value)} />
            <button type="submit" className="admin-btn" disabled={finding || findMobile.trim().length < 10}>{finding ? 'Finding…' : 'Open'}</button>
          </form>
          {error ? <ErrorState error={error} /> : accounts === null ? <LoadingState /> : accounts.length === 0 ? (
            <p className="rw-sub">{query ? 'No customer matches.' : 'No customer has earned points yet.'}</p>
          ) : (
            <div className="admin-table-scroll">
              <table className="admin-table">
                <thead><tr><th>Customer</th><th className="rw-num">Balance</th><th className="rw-num">Earned</th><th className="rw-num">Used</th><th></th></tr></thead>
                <tbody>
                  {accounts.map((a) => (
                    <tr key={a.uuid}>
                      <td>{a.full_name}<div className="rw-sub">{a.mobile}{Number(a.is_frozen) ? ' · frozen' : ''}</div></td>
                      <td className="rw-num"><b>{a.points_balance}</b></td>
                      <td className="rw-num">{a.lifetime_earned}</td>
                      <td className="rw-num">{a.lifetime_redeemed}</td>
                      <td className="rw-num"><button type="button" className="admin-btn" onClick={() => setOpen(a)}>History / adjust</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {open && <CustomerModal account={open} onClose={() => setOpen(null)} onChanged={() => { loadAccounts(); loadSummary(); }} />}
    </div>
  );
}
