import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../../lib/api';
import './Support.css';

export default function SupportTicket() {
  const { uuid } = useParams();
  const [status, setStatus] = useState('loading');
  const [ticket, setTicket] = useState(null);
  const [error, setError] = useState(null);
  const [reply, setReply] = useState('');
  const [sending, setSending] = useState(false);

  const load = useCallback(() => {
    setStatus('loading');
    api
      .get(`/support/tickets/${encodeURIComponent(uuid)}`)
      .then((response) => {
        setTicket(response.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(err.message);
        setStatus('error');
      });
  }, [uuid]);

  useEffect(() => {
    load();
  }, [load]);

  async function handleReply(event) {
    event.preventDefault();
    setSending(true);
    try {
      await api.post(`/support/tickets/${encodeURIComponent(uuid)}/reply`, { body: reply });
      setReply('');
      load();
    } catch (err) {
      setError(err.message);
    } finally {
      setSending(false);
    }
  }

  if (status === 'loading') {
    return <div className="page"><p className="state-message">Loading ticket…</p></div>;
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load this ticket: {error}</p></div>;
  }

  return (
    <div className="page support-ticket">
      <Link className="small" to="/support">← All tickets</Link>
      <h1 className="page-title">{ticket.subject}</h1>
      <p className="text-muted small">
        {ticket.ticket_number} · <span className="status">{ticket.status}</span>
      </p>

      <div className="message-list">
        {ticket.messages.map((message, index) => (
          <div key={index} className={`message-bubble ${message.author_type === 'staff' ? 'message-bubble--staff' : ''}`}>
            <div className="message-bubble__author">{message.author_type === 'staff' ? 'Support team' : 'You'}</div>
            <div>{message.body}</div>
            <div className="message-bubble__date">{(message.created_date || '').replace('T', ' ').slice(0, 16)}</div>
          </div>
        ))}
      </div>

      {ticket.status === 'closed' ? (
        <p className="text-muted small">This ticket is closed.</p>
      ) : (
        <form onSubmit={handleReply}>
          <label htmlFor="body">Add a reply</label>
          <textarea id="body" rows={3} required value={reply} onChange={(e) => setReply(e.target.value)} />
          <button type="submit" className="btn-marigold" disabled={sending}>{sending ? 'Sending…' : 'Send'}</button>
        </form>
      )}
    </div>
  );
}
