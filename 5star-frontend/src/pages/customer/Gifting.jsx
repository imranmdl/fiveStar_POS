import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { cardFromListItem } from '../../lib/store';
import ProductCard from '../../components/customer/ProductCard';

const EMPTY_FORM = { business_name: '', contact_name: '', contact_mobile: '', estimated_quantity: '', requirements: '' };

export default function Gifting() {
  const [gifts, setGifts] = useState([]);
  const [form, setForm] = useState(EMPTY_FORM);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [reference, setReference] = useState(null);

  useEffect(() => {
    document.title = 'Gift boxes & bulk orders · 5 Star';
    api.get('/products', { category: 'gift-packs', per_page: 12, sort: 'popularity' })
      .then((payload) => setGifts((payload.data || []).map(cardFromListItem)))
      .catch(() => setGifts([]));
  }, []);

  const set = (field) => (event) => setForm((f) => ({ ...f, [field]: event.target.value }));

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    const payload = {};
    Object.entries(form).forEach(([key, value]) => {
      if (String(value).trim() !== '') payload[key] = value;
    });
    try {
      const response = await api.post('/bulk-orders/enquiries', payload);
      setReference(response.data.enquiry.enquiry_number);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  const fieldMessages = error && typeof error.fieldMessages === 'function' ? error.fieldMessages() : [];

  return (
    <>
      <section className="sf-hero-dark">
        <span className="sf-eyebrow">Gift boxes</span>
        <h1>Gifting &amp; bulk orders</h1>
        <p>
          Diwali hampers for a team, wedding favours, a standing order for an office pantry. Tell us what you need and we
          will send a price — usually the same working day.
        </p>
      </section>

      {gifts.length > 0 && (
        <section className="sf-panel">
          <div className="sf-panel__head"><h2 className="sf-h2">Ready-made gift boxes</h2></div>
          <div className="sf-giftgrid">
            {gifts.map((product) => <ProductCard key={product.uuid} product={product} />)}
          </div>
        </section>
      )}

      <section className="sf-panel sf-panel--pad" id="enquiry">
        <div className="sf-enquiry">
          <div className="sf-enquiry__aside">
            <h2 className="sf-h2">Ordering 25 or more?</h2>
            <ol>
              <li>You tell us what you need.</li>
              <li>We send a written quotation, valid for a set period.</li>
              <li>You accept it, and it becomes a normal order.</li>
              <li>Same OTP confirmation, same prepaid UPI, same tracking.</li>
            </ol>
          </div>
          <div className="sf-enquiry__form">
            {reference ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10, padding: '12px 0' }}>
                <span className="sf-h2">Thank you — we have your enquiry</span>
                <span style={{ font: '400 15px/1.55 var(--sf-text)', color: 'var(--sf-ink-2)' }}>
                  Reference <b>{reference}</b>. We will send a quotation to the mobile number you gave us, usually the same working day.
                </span>
              </div>
            ) : (
              <form className="sf-form-grid" onSubmit={handleSubmit}>
                {error && (
                  <div className="sf-error sf-field--full">
                    {error.message}
                    {fieldMessages.length > 0 && <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{fieldMessages.map((m) => <li key={m}>{m}</li>)}</ul>}
                  </div>
                )}
                <label className="sf-field">Business or organisation<input required minLength={2} value={form.business_name} onChange={set('business_name')} /></label>
                <label className="sf-field">Your name<input required minLength={2} autoComplete="name" value={form.contact_name} onChange={set('contact_name')} /></label>
                <label className="sf-field">Mobile<input required inputMode="numeric" maxLength={10} placeholder="9876543210" autoComplete="tel-national" value={form.contact_mobile} onChange={set('contact_mobile')} /></label>
                <label className="sf-field">Roughly how many<input type="number" min="1" value={form.estimated_quantity} onChange={set('estimated_quantity')} /></label>
                <label className="sf-field sf-field--full">
                  What do you need?
                  <textarea
                    rows={3}
                    required
                    minLength={10}
                    placeholder="200 gift boxes with almonds, cashews and anjeer. Company logo on the sleeve. Needed by 15 October."
                    value={form.requirements}
                    onChange={set('requirements')}
                  />
                </label>
                <div className="sf-field--full" style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
                  <button type="submit" className="sf-btn sf-btn--red sf-btn--lg" disabled={busy}>{busy ? 'Sending…' : 'SEND ENQUIRY'}</button>
                  <span className="sf-muted" style={{ fontSize: 14 }}>No account needed.</span>
                </div>
              </form>
            )}
          </div>
        </div>
      </section>
    </>
  );
}
