import { useCallback, useEffect, useState } from 'react';
import { api, ApiError, formatMoney } from '../../lib/api';
import { ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Rewards.css';

/**
 * Refer & earn — the reward rules and every referral.
 *
 * A customer shares their code or link; a new customer signs up with it
 * (status "pending"). When that new customer's first paid order reaches the
 * minimum, the referral qualifies and both get wallet credit automatically
 * ("rewarded"). Staff can qualify one by hand (e.g. a first order paid at the
 * shop) or cancel a suspicious one.
 */

const TABS = [
  ['', 'All'],
  ['pending', 'Waiting for first order'],
  ['qualified', 'Qualified'],
  ['rewarded', 'Rewarded'],
  ['cancelled', 'Cancelled'],
];

const LABEL = { pending: 'Waiting for first order', qualified: 'Qualified', rewarded: 'Rewarded', cancelled: 'Cancelled' };

function errorText(error, fallback) {
  if (error instanceof ApiError) {
    const fields = error.fieldMessages ? error.fieldMessages() : [];
    return fields.length ? fields.join(' ') : error.message;
  }
  return fallback;
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

function Rules() {
  const [form, setForm] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get('/admin/referrals/settings').then((r) => setForm(r.data.settings)).catch(setError);
  }, []);

  if (error && !form) return <div className="rw-card"><div className="rw-card__body"><ErrorState error={error} /></div></div>;
  if (!form) return <div className="rw-card"><div className="rw-card__body"><LoadingState /></div></div>;

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  async function save(event) {
    event.preventDefault();
    setBusy(true);
    try {
      const r = await api.patch('/admin/referrals/settings', {
        referrer_reward: Number(form.referrer_reward),
        referee_reward: Number(form.referee_reward),
        min_order_value: Number(form.min_order_value),
        reward_expiry_days: Number(form.reward_expiry_days),
      });
      setForm(r.data.settings);
      toast('Refer & earn rules saved.', 'success');
    } catch (e) {
      toast(errorText(e, 'Could not save the rules.'), 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="rw-card" onSubmit={save}>
      <div className="rw-card__head"><h2>Rewards</h2></div>
      <div className="rw-card__body">
        <div className="rw-form">
          <label className="rw-field">Customer who shares the code gets
            <span className="rw-input-unit"><span>₹</span><input type="number" min="0" step="1" required value={form.referrer_reward} onChange={set('referrer_reward')} /></span>
            <small>Wallet credit.</small>
          </label>
          <label className="rw-field">New customer (friend) gets
            <span className="rw-input-unit"><span>₹</span><input type="number" min="0" step="1" required value={form.referee_reward} onChange={set('referee_reward')} /></span>
            <small>Wallet credit.</small>
          </label>
          <label className="rw-field">Friend’s first order must be at least
            <span className="rw-input-unit"><span>₹</span><input type="number" min="0" step="1" required value={form.min_order_value} onChange={set('min_order_value')} /></span>
            <small>0 = any first order.</small>
          </label>
          <label className="rw-field">Credit expires after
            <span className="rw-input-unit"><input type="number" min="0" step="1" required value={form.reward_expiry_days} onChange={set('reward_expiry_days')} /><span>days</span></span>
            <small>0 = never.</small>
          </label>
        </div>
        <div className="rw-example">
          A friend who signs up with a code and places a first order of {formatMoney(Number(form.min_order_value) || 0)} or more gets {formatMoney(Number(form.referee_reward) || 0)}; the customer who shared it gets {formatMoney(Number(form.referrer_reward) || 0)}. Both are paid into their wallets automatically once the order is paid.
        </div>
        <p className="rw-sub" style={{ margin: 0 }}>New amounts apply to referrals that qualify from now on. Bonus loyalty points for referrals are set on the Loyalty page.</p>
        <div className="rw-actions">
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Save rewards'}</button>
        </div>
      </div>
    </form>
  );
}

export default function Referrals() {
  const [status, setStatus] = useState('');
  const [rows, setRows] = useState(null);
  const [error, setError] = useState(null);
  const [qualify, setQualify] = useState(null);
  const [cancel, setCancel] = useState(null);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({ order_reference: '', order_value: '', reason: '' });

  const load = useCallback(() => {
    setRows(null);
    api.get('/admin/referrals', { status: status || undefined, per_page: 100 })
      .then((r) => setRows(r.data || []))
      .catch(setError);
  }, [status]);

  useEffect(() => { load(); }, [load]);

  async function submitQualify(event) {
    event.preventDefault();
    setBusy(true);
    try {
      await api.post(`/admin/referrals/${qualify.uuid}/qualify`, { order_reference: form.order_reference, order_value: Number(form.order_value) });
      toast('Referral qualified — rewards paid.', 'success');
      setQualify(null);
      load();
    } catch (e) {
      toast(errorText(e, 'Could not qualify this referral.'), 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function submitCancel(event) {
    event.preventDefault();
    setBusy(true);
    try {
      await api.post(`/admin/referrals/${cancel.uuid}/cancel`, { reason: form.reason });
      toast('Referral cancelled.', 'success');
      setCancel(null);
      load();
    } catch (e) {
      toast(errorText(e, 'Could not cancel this referral.'), 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="page rw-page">
      <div className="rw-head"><h1 className="admin-page-title">Refer &amp; earn</h1></div>
      <p className="rw-lead">
        Every customer has a referral code and share link under My account. When a friend signs up with it and their first paid order reaches the minimum,
        both get wallet credit automatically.
      </p>

      <Rules />

      <div className="rw-card">
        <div className="rw-card__head">
          <h2>Referrals</h2>
          <div className="rw-tabs">
            {TABS.map(([key, label]) => (
              <button key={key || 'all'} type="button" className={`rw-tab${status === key ? ' is-on' : ''}`} onClick={() => setStatus(key)}>{label}</button>
            ))}
          </div>
        </div>
        <div className="rw-card__body">
          {error ? <ErrorState error={error} /> : rows === null ? <LoadingState /> : rows.length === 0 ? (
            <p className="rw-sub">No referrals {status ? 'in this list' : 'yet'}.</p>
          ) : (
            <div className="admin-table-scroll">
              <table className="admin-table">
                <thead><tr><th>Signed up</th><th>Shared by</th><th>New customer</th><th>Status</th><th>First order</th><th className="rw-num">Rewards</th><th></th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.uuid}>
                      <td>{String(r.created_date || '').slice(0, 10)}</td>
                      <td>{r.referrer_name}<div className="rw-sub">Code {r.referral_code_used}</div></td>
                      <td>{r.referee_name}</td>
                      <td><span className={`rw-pill rw-pill--${r.status}`}>{LABEL[r.status] || r.status}</span>{r.cancelled_reason && <div className="rw-sub">{r.cancelled_reason}</div>}</td>
                      <td>{r.qualifying_order_reference ? <>{r.qualifying_order_reference}<div className="rw-sub">{formatMoney(r.qualifying_order_value)}</div></> : '—'}</td>
                      <td className="rw-num">{Number(r.referrer_reward_amount) > 0 || Number(r.referee_reward_amount) > 0 ? <>{formatMoney(r.referrer_reward_amount)} + {formatMoney(r.referee_reward_amount)}</> : '—'}</td>
                      <td className="rw-num">
                        {r.status === 'pending' && (
                          <span className="rw-actions" style={{ justifyContent: 'flex-end' }}>
                            <button type="button" className="admin-btn" onClick={() => { setForm({ order_reference: '', order_value: '', reason: '' }); setQualify(r); }}>Qualify</button>
                            <button type="button" className="admin-btn" onClick={() => { setForm({ order_reference: '', order_value: '', reason: '' }); setCancel(r); }}>Cancel</button>
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {qualify && (
        <Modal title={`Qualify referral — ${qualify.referee_name}`} onClose={() => setQualify(null)}>
          <p className="rw-sub" style={{ margin: 0 }}>Use this when the friend’s first order wasn’t picked up automatically (for example, it was paid at the shop). Both customers get their wallet credit now.</p>
          <form className="rw-form" onSubmit={submitQualify}>
            <label className="rw-field">Order / bill number<input required minLength={3} maxLength={50} value={form.order_reference} onChange={(e) => setForm({ ...form, order_reference: e.target.value })} /></label>
            <label className="rw-field">Order value (₹)<input type="number" min="1" step="0.01" required value={form.order_value} onChange={(e) => setForm({ ...form, order_value: e.target.value })} /></label>
            <div className="rw-actions rw-field--full"><button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Qualify and pay rewards'}</button></div>
          </form>
        </Modal>
      )}

      {cancel && (
        <Modal title={`Cancel referral — ${cancel.referee_name}`} onClose={() => setCancel(null)}>
          <form className="rw-form" onSubmit={submitCancel}>
            <label className="rw-field rw-field--full">Reason<input required minLength={5} maxLength={255} placeholder="e.g. Same person, second account" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></label>
            <div className="rw-actions rw-field--full"><button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Cancel referral'}</button></div>
          </form>
        </Modal>
      )}
    </div>
  );
}
