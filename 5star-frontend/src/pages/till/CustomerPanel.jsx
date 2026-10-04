import { useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { digitsOnly } from './tillMath';

/**
 * Customer lookup/attach panel — ported from the customer-lookup portion of
 * renderSellTab() in admin/assets/page-till.js. A sale defaults to walk-in;
 * finding a registered customer by mobile (GET /admin/pos/customers)
 * attaches their account instead, which is what lets the sale draw on (or
 * add to) their wallet. A 404 on that lookup quick-registers them inline
 * (name + mobile only) rather than dead-ending — most walk-in shoppers a
 * small store extends credit to have never signed up online.
 */
export default function CustomerPanel({
  selectedCustomer,
  onAttach,
  onClear,
  walkInName,
  walkInMobile,
  onWalkInNameChange,
  onWalkInMobileChange,
}) {
  const [mobile, setMobile] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [quickAdd, setQuickAdd] = useState(null); // mobile string while the quick-add form is open
  const [quickAddName, setQuickAddName] = useState('');
  const [quickAddBusy, setQuickAddBusy] = useState(false);

  async function attachCustomer(customer) {
    // Best-effort: shows what this customer already owes so the cashier
    // sees it before deciding to add more debt. Not fatal if it fails.
    let withDues = customer;
    try {
      const dues = await api.get(`/admin/customers/${encodeURIComponent(customer.uuid)}/dues`);
      withDues = { ...customer, totalOutstanding: dues.data.total_outstanding };
    } catch { /* the "already owes" line just stays hidden */ }

    onAttach(withDues);
    setQuickAdd(null);
    setError(null);
  }

  async function findCustomer() {
    const trimmed = mobile.trim();
    if (!trimmed) return;

    setBusy(true);
    setError(null);
    setQuickAdd(null);

    try {
      const response = await api.get('/admin/pos/customers', { mobile: trimmed });
      await attachCustomer(response.data);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setQuickAdd(trimmed);
        setQuickAddName('');
      } else {
        setError(err instanceof ApiError ? err.message : 'Lookup failed.');
      }
    } finally {
      setBusy(false);
    }
  }

  async function submitQuickAdd() {
    const fullName = quickAddName.trim();
    if (fullName.length < 2) {
      setError('Enter the customer’s name first.');
      return;
    }

    setQuickAddBusy(true);
    setError(null);

    try {
      const response = await api.post('/admin/pos/customers', { full_name: fullName, mobile: quickAdd });
      await attachCustomer(response.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not add that customer.');
    } finally {
      setQuickAddBusy(false);
    }
  }

  if (selectedCustomer) {
    const balance = Number(selectedCustomer.wallet.balance) || 0;
    const owed = Number(selectedCustomer.totalOutstanding) || 0;
    const loyalty = selectedCustomer.loyalty || null;

    return (
      <div className={`till-customer-card ${selectedCustomer.wallet.is_frozen ? 'till-customer-card--warning' : 'till-customer-card--success'}`}>
        <div>
          <div className="till-customer-card__name">{selectedCustomer.full_name} <span className="till-customer-card__mobile">{selectedCustomer.mobile}</span></div>
          <div>Wallet balance: <strong>{formatMoney(balance)}</strong>
            {selectedCustomer.wallet.is_frozen && <span className="till-text-danger"> — frozen, cannot be spent</span>}
          </div>
          {loyalty && (loyalty.balance > 0 || loyalty.lifetime_earned > 0) && (
            <div>Loyalty points: <strong>{loyalty.balance}</strong>
              {loyalty.is_frozen && <span className="till-text-danger"> — on hold</span>}
              {' '}— this sale will earn more, redeemable on the Loyalty page.
            </div>
          )}
          {owed > 0 && (
            <div className="till-text-warning">Already owes <strong>{formatMoney(owed)}</strong> from an earlier sale.</div>
          )}
        </div>
        <button type="button" className="till-btn till-btn--sm" onClick={onClear}>Remove</button>
      </div>
    );
  }

  return (
    <div className="till-customer-panel">
      <label className="till-field till-field--inline">
        <span>Registered customer (optional)</span>
        <div className="till-input-group">
          <input
            placeholder="Mobile number"
            inputMode="numeric"
            autoComplete="off"
            maxLength={10}
            value={mobile}
            onChange={(event) => setMobile(digitsOnly(event.target.value))}
            onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); findCustomer(); } }}
          />
          <button type="button" className="till-btn" disabled={busy} onClick={findCustomer}>
            {busy ? 'Looking up…' : 'Find'}
          </button>
        </div>
        <div className="till-field__hint">Attaching a registered customer lets this sale use, or add to, their wallet.</div>
      </label>

      {error && <div className="till-alert till-alert--danger till-alert--sm">{error}</div>}

      {quickAdd && (
        <div className="till-alert till-alert--warning">
          <div className="till-alert__text">No customer account has {quickAdd} yet. Add them now so this sale (and any future due payment) can be tracked against them.</div>
          <div className="till-input-group">
            <input
              placeholder="Customer's name"
              maxLength={120}
              value={quickAddName}
              autoFocus
              onChange={(event) => setQuickAddName(event.target.value)}
              onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); submitQuickAdd(); } }}
            />
            <button type="button" className="till-btn till-btn--primary" disabled={quickAddBusy} onClick={submitQuickAdd}>
              {quickAddBusy ? 'Adding…' : 'Add & attach'}
            </button>
          </div>
        </div>
      )}

      <div className="till-walkin-fields">
        <label className="till-field">
          <span>Walk-in customer name</span>
          <input placeholder="Optional" value={walkInName} onChange={(event) => onWalkInNameChange(event.target.value)} />
        </label>
        <label className="till-field">
          <span>Walk-in mobile</span>
          <input
            placeholder="Optional"
            inputMode="numeric"
            maxLength={10}
            value={walkInMobile}
            onChange={(event) => onWalkInMobileChange(digitsOnly(event.target.value))}
          />
          <div className="till-field__hint">With their mobile, the customer can review what they bought once they sign in on the website.</div>
        </label>
      </div>
    </div>
  );
}
