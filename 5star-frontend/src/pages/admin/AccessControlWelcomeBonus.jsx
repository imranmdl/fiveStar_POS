import { useEffect, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';

/**
 * Admin Privilege Management — Welcome Bonus setting.
 * Ported from renderWelcomeBonus() in admin/assets/page-access-control.js.
 *
 * When a new customer's mobile number is verified for the first time, this
 * can credit a one-time amount straight to their wallet. Off by default;
 * nothing is credited to anyone until this is turned on.
 */

export default function AccessControlWelcomeBonus({ has }) {
  const canEdit = has('system_settings.edit');
  const [state, setState] = useState({ loading: true, error: null, config: null });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get('/admin-privilege/wallet/welcome-bonus')
      .then((res) => setState({ loading: false, error: null, config: res.data }))
      .catch((error) => setState({ loading: false, error, config: null }));
  }, []);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState error={state.error} />;

  const cfg = state.config;

  async function handleSave(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const enabled = form.enabled.checked;
    const amount = Number(form.amount.value);
    const expiryDays = Number(form.expiry_days.value);
    setSaving(true);

    try {
      const res = await api.patch('/admin-privilege/wallet/welcome-bonus', { enabled, amount, expiry_days: expiryDays });
      toast(enabled ? `Welcome bonus enabled — new customers now receive ₹${amount}.` : 'Welcome bonus disabled.');
      setState({ loading: false, error: null, config: res.data });
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not save this setting.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <p className="ac-sub" style={{ maxWidth: '40rem', marginBottom: 16 }}>
        When a new customer's mobile number is verified for the first time — at the end of
        registration, or the first time they sign in by OTP — this can credit a one-time amount
        straight to their wallet. Off by default; nothing is credited to anyone until this is
        turned on.
      </p>

      <div className="ac-wb-grid">
        <form className="ac-card" onSubmit={handleSave}>
          <div className="ac-card__header">Settings</div>
          <div className="ac-card__body">
            <label className="ac-switch">
              <input type="checkbox" name="enabled" defaultChecked={cfg.enabled} disabled={!canEdit} />
              <span>Credit new customers a welcome bonus</span>
            </label>
            <label className="ac-field">
              <span>Amount (₹)</span>
              <input type="number" name="amount" min="0" max="100000" step="1" defaultValue={cfg.amount} disabled={!canEdit} />
            </label>
            <label className="ac-field">
              <span>Expires after (days, 0 = never)</span>
              <input type="number" name="expiry_days" min="0" max="3650" step="1" defaultValue={cfg.expiry_days} disabled={!canEdit} />
            </label>
            {canEdit ? (
              <button type="submit" className="admin-btn admin-btn--primary" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
            ) : (
              <p className="ac-sub">Your role can view this but not edit it.</p>
            )}
          </div>
        </form>

        <div className="ac-card">
          <div className="ac-card__header">How it works</div>
          <div className="ac-card__body">
            <ul className="ac-list">
              <li>Applies once per customer, automatically — nothing for a cashier or the customer to do to claim it.</li>
              <li>Cannot be paid out twice, even if a customer gets verified more than once (e.g. registers, then later also verifies by OTP).</li>
              <li>An expiry of 0 days means the credit never expires.</li>
              <li>Turning this off does not take back credits already given — it only stops new ones.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}
