import { useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from './toast';
import './ManualPaymentForm.css';

/**
 * Online-order payment states as staff should read them. The API's raw
 * values ("pending", "failed"…) are shown through this everywhere so the
 * Payments page, the orders list and the order page say the same thing.
 */
export const ORDER_PAYMENT_LABEL = {
  pending: 'Not paid',
  processing: 'Payment in progress',
  paid: 'Paid',
  failed: 'Payment failed',
  refunded: 'Refunded',
  partially_refunded: 'Partly refunded',
};

export function orderPaymentLabel(status) {
  return ORDER_PAYMENT_LABEL[status] || String(status || '').replace(/_/g, ' ');
}

/** Same rule as the server (ManualPaymentService::normaliseUtr). */
export function normaliseUtr(value) {
  return String(value || '').replace(/[\s-]+/g, '').toUpperCase();
}

export function utrProblem(value) {
  const utr = normaliseUtr(value);
  if (utr === '') return 'Enter the UTR / UPI reference number of the transfer.';
  if (!/^[A-Z0-9]{6,30}$/.test(utr)) {
    return 'Use the UTR exactly as the bank or UPI app shows it — 6 to 30 letters and digits (a UPI UTR is usually 12 digits).';
  }
  return null;
}

function messageOf(error, fallback) {
  if (error instanceof ApiError) {
    const fields = error.fieldMessages();
    // "Validation failed" alone tells staff nothing — show what to fix.
    if (fields.length > 0) return fields.join(' ');
    return error.message || fallback;
  }
  if (error && error.message === 'Failed to fetch') {
    return 'Could not reach the server — check the internet connection and try again. Nothing was saved.';
  }
  return (error && error.message) || fallback;
}

/**
 * Staff confirmation of a manual UPI QR payment: the amount that arrived, the
 * UTR / UPI reference of that transfer (required — it is saved on the payment
 * and one UTR can pay for only one order), and optionally when the customer
 * paid. "Payment not received" rejects the attempt instead.
 *
 * A UTR on its own proves nothing; this form is the administrator's own
 * statement that they found the transfer in the bank account.
 *
 * props.payment: { uuid, amount, order_number, order_status, order_expired }
 * props.onDone(): called after a successful confirm / reject, and after a
 *   "someone already resolved this" answer, so the caller re-loads.
 */
export default function ManualPaymentForm({ payment, onDone, compact = false }) {
  const [amount, setAmount] = useState(String(payment.amount ?? ''));
  const [utr, setUtr] = useState('');
  const [paidAt, setPaidAt] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [utrError, setUtrError] = useState('');

  const cancelledForGood = payment.order_status === 'cancelled' && !payment.order_expired;

  async function handleConfirm(event) {
    event.preventDefault();
    setError('');

    const problem = utrProblem(utr);
    setUtrError(problem || '');
    if (problem) return;

    if (!(Number(amount) > 0)) {
      setError('Enter the amount that reached the account.');
      return;
    }

    const cleanUtr = normaliseUtr(utr);
    const ok = window.confirm(
      `Mark order ${payment.order_number} as PAID?\n\n`
      + `Amount received: ₹${amount}\nUTR: ${cleanUtr}\n\n`
      + 'Only confirm after you have found this transfer in the bank / UPI account.',
    );
    if (!ok) return;

    setBusy(true);
    try {
      const response = await api.post(`/admin/payments/${encodeURIComponent(payment.uuid)}/verify`, {
        confirmed_amount: amount,
        utr_or_reference: cleanUtr,
        ...(paidAt ? { paid_at: paidAt.replace('T', ' ') } : {}),
      });
      const order = (response.data && response.data.order) || {};
      toast(`Order ${order.order_number || payment.order_number} is now ${orderPaymentLabel(order.payment_status || 'paid')} — UTR ${cleanUtr} saved.`);
      onDone();
    } catch (err) {
      setBusy(false);
      const text = messageOf(err, 'Could not confirm this payment. Nothing was changed — try again.');
      toast(text, 'danger');
      const fieldUtr = err instanceof ApiError && err.errors && !Array.isArray(err.errors) && err.errors.utr_or_reference;
      // A UTR problem is shown under the UTR box; anything else in the form's error box.
      if (fieldUtr) setUtrError([].concat(fieldUtr)[0]);
      else setError(text);
      // Already confirmed / closed by someone else: show the real state.
      if (err instanceof ApiError && err.status === 409 && /already been|already paid/i.test(err.message || '')) {
        onDone();
      }
    }
  }

  async function handleReject() {
    const reason = window.prompt(
      'Why is this payment not accepted? (kept in the audit log, e.g. "no matching transfer found")',
    );
    if (!reason || reason.trim().length < 3) return;

    setBusy(true);
    setError('');
    try {
      await api.post(`/admin/payments/${encodeURIComponent(payment.uuid)}/reject`, { reason: reason.trim() });
      toast('Payment marked as not received. The customer can pay again.');
      onDone();
    } catch (err) {
      setBusy(false);
      const text = messageOf(err, 'Could not update this payment.');
      setError(text);
      toast(text, 'danger');
      if (err instanceof ApiError && err.status === 409) onDone();
    }
  }

  return (
    <form className={`mp-form${compact ? ' mp-form--compact' : ''}`} onSubmit={handleConfirm} noValidate>
      {payment.order_expired && (
        <div className="mp-note mp-note--info">
          This order was closed automatically because the payment was not verified within the payment window.
          If the money has arrived, confirming it reopens the order and moves it to packing.
        </div>
      )}
      {cancelledForGood && (
        <div className="mp-note mp-note--warn">
          This order has been cancelled, so it cannot be marked paid. Use “Payment not received” to clear it; if the
          customer did pay, refund them.
        </div>
      )}

      <div className="mp-fields">
        <label className="mp-field">
          <span>Amount received (₹)</span>
          <input
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            inputMode="decimal"
            required
            disabled={busy}
          />
        </label>
        <label className="mp-field mp-field--utr">
          <span>UTR / UPI reference <b aria-hidden="true">*</b></span>
          <input
            value={utr}
            onChange={(e) => { setUtr(e.target.value); if (utrError) setUtrError(''); }}
            placeholder="e.g. 412345678901"
            autoComplete="off"
            spellCheck={false}
            required
            aria-invalid={utrError ? 'true' : 'false'}
            disabled={busy}
          />
          {utrError && <span className="mp-error">{utrError}</span>}
        </label>
        <label className="mp-field">
          <span>Paid on (optional)</span>
          <input
            type="datetime-local"
            value={paidAt}
            onChange={(e) => setPaidAt(e.target.value)}
            disabled={busy}
          />
        </label>
      </div>

      {error && <div className="mp-note mp-note--error" role="alert">{error}</div>}

      <div className="mp-actions">
        <button type="submit" className="admin-btn admin-btn--primary" disabled={busy || cancelledForGood}>
          {busy ? 'Saving…' : 'Confirm payment'}
        </button>
        <button type="button" className="admin-btn" onClick={handleReject} disabled={busy}>
          Payment not received
        </button>
      </div>
    </form>
  );
}
