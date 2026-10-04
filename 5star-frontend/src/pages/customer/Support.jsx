import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import './Support.css';

const CATEGORIES = [
  ['order', 'An order'],
  ['delivery', 'Delivery'],
  ['payment', 'Payment'],
  ['refund', 'A refund'],
  ['product', 'A product'],
  ['account', 'My account'],
  ['wholesale', 'Wholesale enquiry'],
  ['other', 'Something else'],
];

export default function Support() {
  const { signedIn, ready } = useAuth();
  const navigate = useNavigate();
  const [tickets, setTickets] = useState([]);
  const [ticketsLoaded, setTicketsLoaded] = useState(false);

  const [form, setForm] = useState({
    subject: '', category: 'other', contact_name: '', contact_mobile: '', message: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setTicketsLoaded(true);
      return;
    }

    api
      .get('/support/tickets')
      .then((response) => setTickets(response.data.tickets || []))
      .catch(() => {})
      .finally(() => setTicketsLoaded(true));
  }, [ready, signedIn]);

  function set(field) {
    return (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const response = await api.post('/support/tickets', form);
      navigate(`/support/${response.data.ticket.uuid}`);
    } catch (err) {
      setError(err);
      setBusy(false);
    }
  }

  return (
    <div className="page support-page">
      <div className="support-layout">
        <div className="support-main">
          <h1 className="page-title">Raise a support ticket</h1>
          <form className="checkout-panel" onSubmit={handleSubmit}>
            {error && (
              <div className="form-error">
                <div>{error.message}</div>
                {error.fieldMessages && error.fieldMessages().length > 0 && (
                  <ul>{error.fieldMessages().map((m) => <li key={m}>{m}</li>)}</ul>
                )}
              </div>
            )}

            <div className="field">
              <label htmlFor="subject">Subject</label>
              <input id="subject" required value={form.subject} onChange={set('subject')} />
            </div>

            <div className="field">
              <label htmlFor="category">What is it about?</label>
              <select id="category" value={form.category} onChange={set('category')}>
                {CATEGORIES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </div>

            <div className="field-row">
              <div className="field">
                <label htmlFor="contact_name">Your name</label>
                <input id="contact_name" required value={form.contact_name} onChange={set('contact_name')} />
              </div>
              <div className="field">
                <label htmlFor="contact_mobile">Mobile</label>
                <input id="contact_mobile" required inputMode="numeric" value={form.contact_mobile} onChange={set('contact_mobile')} />
              </div>
            </div>

            <div className="field">
              <label htmlFor="message">How can we help?</label>
              <textarea id="message" rows={4} required minLength={10} value={form.message} onChange={set('message')} />
            </div>

            <button type="submit" className="btn-marigold" disabled={busy}>{busy ? 'Sending…' : 'Send'}</button>
          </form>
        </div>

        <div className="support-side">
          <h2>Your tickets</h2>
          {!signedIn ? (
            <p className="text-muted small"><Link to="/account?next=/support">Sign in</Link> to see your tickets.</p>
          ) : !ticketsLoaded ? (
            <p className="text-muted small">Loading…</p>
          ) : tickets.length === 0 ? (
            <p className="text-muted small">No tickets yet.</p>
          ) : (
            tickets.map((ticket) => (
              <Link key={ticket.uuid} className="ticket-link" to={`/support/${ticket.uuid}`}>
                <span className="ticket-link__subject">{ticket.subject}</span>
                <span className="ticket-link__meta">{ticket.ticket_number} · {ticket.status}</span>
              </Link>
            ))
          )}
        </div>
      </div>
    </div>
  );
}
