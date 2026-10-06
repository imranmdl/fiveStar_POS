import { useEffect, useState } from 'react';
import { api, ApiError, formatMoney } from '../../lib/api';
import { LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Rewards.css';

function errorText(error, fallback) {
  if (error instanceof ApiError) {
    const fields = error.fieldMessages ? error.fieldMessages() : [];
    return fields.length ? fields.join(' ') : error.message;
  }
  return fallback;
}

/**
 * Wallet rules on the Wallets page: whether customers can spend wallet credit
 * at checkout and how much of an order it may cover, plus the welcome bonus
 * a new customer gets on verifying their number.
 */
export default function WalletRules() {
  const [rules, setRules] = useState(null);
  const [bonus, setBonus] = useState(null);
  const [bonusError, setBonusError] = useState(null);
  const [busy, setBusy] = useState(null);

  useEffect(() => {
    api.get('/admin/wallet/settings').then((r) => setRules(r.data.settings)).catch(() => setRules(false));
    api.get('/admin-privilege/wallet/welcome-bonus').then((r) => setBonus(r.data)).catch((e) => setBonusError(e));
  }, []);

  async function saveRules(event) {
    event.preventDefault();
    setBusy('rules');
    try {
      const r = await api.patch('/admin/wallet/settings', {
        enabled: !!rules.enabled,
        max_redeem_percent: Number(rules.max_redeem_percent),
        min_redeem_amount: Number(rules.min_redeem_amount),
      });
      setRules(r.data.settings);
      toast('Wallet rules saved.', 'success');
    } catch (e) {
      toast(errorText(e, 'Could not save the wallet rules.'), 'danger');
    } finally {
      setBusy(null);
    }
  }

  async function saveBonus(event) {
    event.preventDefault();
    setBusy('bonus');
    try {
      const r = await api.patch('/admin-privilege/wallet/welcome-bonus', {
        enabled: !!bonus.enabled,
        amount: Number(bonus.amount),
        expiry_days: Number(bonus.expiry_days || 0),
      });
      setBonus(r.data);
      toast('Welcome bonus saved.', 'success');
    } catch (e) {
      toast(errorText(e, 'Could not save the welcome bonus.'), 'danger');
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="rw-tiles" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', marginBottom: 16 }}>
      <form className="rw-card" onSubmit={saveRules}>
        <div className="rw-card__head"><h2>Using wallet credit</h2></div>
        <div className="rw-card__body">
          {rules === null ? <LoadingState /> : rules === false ? <p className="rw-sub">Couldn’t load the wallet rules.</p> : (
            <>
              <label className="rw-switch">
                <input type="checkbox" checked={!!rules.enabled} onChange={(e) => setRules({ ...rules, enabled: e.target.checked })} />
                Customers can pay with wallet credit at checkout
              </label>
              <div className="rw-form">
                <label className="rw-field">Wallet can cover up to
                  <span className="rw-input-unit"><input type="number" min="0" max="100" step="1" required value={rules.max_redeem_percent} onChange={(e) => setRules({ ...rules, max_redeem_percent: e.target.value })} /><span>% of the order</span></span>
                </label>
                <label className="rw-field">Smallest amount that can be used
                  <span className="rw-input-unit"><span>₹</span><input type="number" min="0" step="1" required value={rules.min_redeem_amount} onChange={(e) => setRules({ ...rules, min_redeem_amount: e.target.value })} /></span>
                </label>
              </div>
              <div className="rw-example">On a ₹1,000 order a customer can use up to {formatMoney(Math.min(1000, 1000 * (Number(rules.max_redeem_percent) || 0) / 100))} from their wallet.</div>
              <div className="rw-actions"><button type="submit" className="admin-btn admin-btn--primary" disabled={busy === 'rules'}>{busy === 'rules' ? 'Saving…' : 'Save'}</button></div>
            </>
          )}
        </div>
      </form>

      <form className="rw-card" onSubmit={saveBonus}>
        <div className="rw-card__head"><h2>Welcome bonus</h2></div>
        <div className="rw-card__body">
          {bonusError ? (
            <p className="rw-sub">{bonusError instanceof ApiError && bonusError.status === 403 ? 'Only a super admin can change the welcome bonus.' : 'Couldn’t load the welcome bonus.'}</p>
          ) : bonus === null ? <LoadingState /> : (
            <>
              <label className="rw-switch">
                <input type="checkbox" checked={!!bonus.enabled} onChange={(e) => setBonus({ ...bonus, enabled: e.target.checked })} />
                Give new customers wallet credit when they verify their number
              </label>
              <div className="rw-form">
                <label className="rw-field">Amount
                  <span className="rw-input-unit"><span>₹</span><input type="number" min="0" step="1" required value={bonus.amount} onChange={(e) => setBonus({ ...bonus, amount: e.target.value })} /></span>
                </label>
                <label className="rw-field">Expires after
                  <span className="rw-input-unit"><input type="number" min="0" step="1" value={bonus.expiry_days ?? 0} onChange={(e) => setBonus({ ...bonus, expiry_days: e.target.value })} /><span>days (0 = never)</span></span>
                </label>
              </div>
              <p className="rw-sub" style={{ margin: 0 }}>Paid once per customer. Customers who signed up before you switch this on don’t get it.</p>
              <div className="rw-actions"><button type="submit" className="admin-btn admin-btn--primary" disabled={busy === 'bonus'}>{busy === 'bonus' ? 'Saving…' : 'Save'}</button></div>
            </>
          )}
        </div>
      </form>
    </div>
  );
}
