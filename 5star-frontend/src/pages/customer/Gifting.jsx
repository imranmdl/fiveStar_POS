import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import { cardFromListItem, rupees } from '../../lib/store';
import { AddControl, ProductMedia } from '../../components/customer/ProductCard';

const EMPTY_FORM = { business_name: '', contact_name: '', contact_mobile: '', estimated_quantity: '', requirements: '' };

function GiftCard({ product }) {
  const href = `/product/${product.slug}`;
  return (
    <div className="sf-gift">
      <Link to={href} aria-label={product.name}>
        <ProductMedia image={product.image} tint={product.tint} label={product.size} alt={product.name} />
      </Link>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <Link to={href} className="sf-gift__name">{product.name}</Link>
        {product.short && <span className="sf-gift__short">{product.short}</span>}
      </div>
      <div className="sf-card__foot">
        <div className="sf-price">
          <span className="sf-price__now" style={{ fontSize: 18 }}>{rupees(product.price)}</span>
          {product.off > 0 && <span className="sf-price__mrp">{rupees(product.mrp)}</span>}
        </div>
        <AddControl product={product} />
      </div>
    </div>
  );
}

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
    <div style={{ display: 'flex', flexDirection: 'column', gap: 64, paddingBottom: 80 }}>
      <section className="sf-wrap" style={{ paddingTop: 44, display: 'flex', flexDirection: 'column', gap: 14 }}>
        <span className="sf-eyebrow">Gift boxes</span>
        <h1 className="sf-hero__title" style={{ fontSize: 'clamp(36px, 6vw, 60px)', maxWidth: '15ch' }}>Gifting &amp; bulk orders</h1>
        <p className="sf-lead" style={{ fontSize: 17, maxWidth: '56ch' }}>
          Diwali hampers for a team, wedding favours, a standing order for an office pantry. Tell us what you need and we
          will send a price — usually the same working day.
        </p>
      </section>

      {gifts.length > 0 && (
        <section className="sf-wrap sf-gifts-grid">
          {gifts.map((product) => <GiftCard key={product.uuid} product={product} />)}
        </section>
      )}

      <section className="sf-wrap">
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
                <span className="sf-h3" style={{ fontSize: 24 }}>Thank you — we have your enquiry</span>
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
                  <button type="submit" className="sf-btn sf-btn--red sf-btn--xl" disabled={busy}>{busy ? 'Sending…' : 'Send enquiry'}</button>
                  <span className="sf-muted" style={{ fontSize: 14 }}>No account needed.</span>
                </div>
              </form>
            )}
          </div>
        </div>
      </section>
    </div>
  );
}
