import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import './Gifting.css';

const EMPTY_FORM = {
  business_name: '', contact_name: '', contact_mobile: '', contact_email: '',
  requirements: '', estimated_quantity: '', estimated_budget: '', expected_delivery_date: '',
  delivery_pincode: '', gstin: '',
};

export default function Gifting() {
  const [form, setForm] = useState(EMPTY_FORM);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [enquiryNumber, setEnquiryNumber] = useState(null);

  function set(field) {
    return (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    const payload = {};
    Object.entries(form).forEach(([key, value]) => {
      if (value !== '') payload[key] = value;
    });

    try {
      const response = await api.post('/bulk-orders/enquiries', payload);
      setEnquiryNumber(response.data.enquiry.enquiry_number);
    } catch (err) {
      setBusy(false);
      if (err instanceof ApiError && err.status === 422) {
        setError(err);
      } else {
        setError(err);
      }
    }
  }

  if (enquiryNumber) {
    return (
      <div className="page">
        <div className="gifting-thanks">
          <h1>Thank you — we have your enquiry</h1>
          <p>Reference <b>{enquiryNumber}</b></p>
          <p className="text-muted">
            We will send a quotation to the mobile number you gave us, usually the same working day. Keep the
            reference handy if you call.
          </p>
          <Link className="btn-marigold" to="/">Back to the shop</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="page gifting-page">
      <div className="gifting-layout">
        <div className="gifting-main">
          <h1 className="page-title">Gifting &amp; bulk orders</h1>
          <p className="text-muted">
            Diwali hampers for a team, wedding favours, a standing order for an office pantry. Tell us what you need
            and we will send a price — usually the same working day.
          </p>

          <form className="checkout-panel" onSubmit={handleSubmit}>
            {error && (
              <div className="form-error">
                <div>{error.message}</div>
                {error.fieldMessages && error.fieldMessages().length > 0 && (
                  <ul>{error.fieldMessages().map((m) => <li key={m}>{m}</li>)}</ul>
                )}
              </div>
            )}

            <div className="field-grid">
              <div className="field">
                <label htmlFor="business_name">Business or organisation *</label>
                <input id="business_name" required minLength={2} value={form.business_name} onChange={set('business_name')} />
              </div>
              <div className="field">
                <label htmlFor="contact_name">Your name *</label>
                <input id="contact_name" required minLength={2} value={form.contact_name} onChange={set('contact_name')} />
              </div>
              <div className="field">
                <label htmlFor="contact_mobile">Mobile *</label>
                <input id="contact_mobile" required inputMode="numeric" maxLength={10} placeholder="9876543210" value={form.contact_mobile} onChange={set('contact_mobile')} />
              </div>
              <div className="field">
                <label htmlFor="contact_email">Email</label>
                <input id="contact_email" type="email" value={form.contact_email} onChange={set('contact_email')} />
              </div>
              <div className="field field--full">
                <label htmlFor="requirements">What do you need? *</label>
                <textarea
                  id="requirements"
                  rows={4}
                  required
                  minLength={10}
                  placeholder="200 gift boxes with almonds, cashews and anjeer. Company logo on the sleeve. Needed by 15 October."
                  value={form.requirements}
                  onChange={set('requirements')}
                />
                <div className="field-hint">The more specific you are, the more accurate the quote.</div>
              </div>
              <div className="field">
                <label htmlFor="estimated_quantity">Roughly how many</label>
                <input id="estimated_quantity" type="number" min="1" value={form.estimated_quantity} onChange={set('estimated_quantity')} />
              </div>
              <div className="field">
                <label htmlFor="estimated_budget">Budget (₹)</label>
                <input id="estimated_budget" type="number" min="0" step="0.01" value={form.estimated_budget} onChange={set('estimated_budget')} />
              </div>
              <div className="field">
                <label htmlFor="expected_delivery_date">Needed by</label>
                <input id="expected_delivery_date" type="date" value={form.expected_delivery_date} onChange={set('expected_delivery_date')} />
              </div>
              <div className="field">
                <label htmlFor="delivery_pincode">Delivery pincode</label>
                <input id="delivery_pincode" inputMode="numeric" maxLength={6} value={form.delivery_pincode} onChange={set('delivery_pincode')} />
              </div>
              <div className="field">
                <label htmlFor="gstin">GSTIN</label>
                <input id="gstin" maxLength={15} value={form.gstin} onChange={set('gstin')} />
                <div className="field-hint">For a GST invoice in your company's name.</div>
              </div>
            </div>

            <button type="submit" className="btn-marigold" disabled={busy}>{busy ? 'Sending…' : 'Send enquiry'}</button>
            <span className="text-muted small gifting-note">No account needed.</span>
          </form>
        </div>

        <div className="gifting-side">
          <div className="checkout-panel">
            <h2>How it works</h2>
            <ol className="gifting-steps">
              <li>You tell us what you need.</li>
              <li>We send a written quotation, valid for a set period.</li>
              <li>You accept it, and it becomes a normal order.</li>
              <li>Same OTP confirmation, same prepaid UPI, same tracking.</li>
            </ol>
            <p className="text-muted small">
              A wholesale order follows exactly the same rules as any other. Nothing ships before payment is
              confirmed — which matters most here, because these are the largest amounts.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
