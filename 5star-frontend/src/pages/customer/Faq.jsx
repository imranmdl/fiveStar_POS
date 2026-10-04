import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import './Faq.css';

function FaqGroup({ group, openId, onToggle }) {
  return (
    <section className="faq-group">
      <h2>{group.label}</h2>
      {group.entries.map((entry) => (
        <FaqEntry key={entry.uuid} entry={entry} open={openId === entry.uuid} onToggle={() => onToggle(entry.uuid)} />
      ))}
    </section>
  );
}

function FaqEntry({ entry, open, onToggle }) {
  const [helpful, setHelpful] = useState(false);
  const [sending, setSending] = useState(false);

  async function markHelpful() {
    setSending(true);
    try {
      await api.post(`/content/faq/${entry.uuid}/helpful`, {});
      setHelpful(true);
    } catch {
      // A vote failing is not worth interrupting anyone over.
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="faq-entry">
      <button type="button" className="faq-entry__question" onClick={onToggle} aria-expanded={open}>
        {entry.question}
        <span className="faq-entry__chevron">{open ? '−' : '+'}</span>
      </button>
      {open && (
        <div className="faq-entry__answer">
          <p>{entry.answer}</p>
          <button type="button" className="btn-outline" disabled={helpful || sending} onClick={markHelpful}>
            {helpful ? 'Thank you' : 'This helped'}
          </button>
        </div>
      )}
    </div>
  );
}

export default function Faq() {
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('loading');
  const [groups, setGroups] = useState([]);
  const [error, setError] = useState(null);
  const [openId, setOpenId] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');

    api
      .get('/content/faq', { q: query })
      .then((response) => {
        if (cancelled) return;
        setGroups(response.data.groups || []);
        setStatus('ready');
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message);
        setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [query]);

  function handleSearch(event) {
    event.preventDefault();
    setQuery(search);
  }

  return (
    <div className="page faq-page">
      <h1 className="page-title">Frequently asked questions</h1>

      <form className="faq-search" onSubmit={handleSearch}>
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search…"
          aria-label="Search the FAQ"
        />
        <button type="submit" className="btn-marigold">Search</button>
      </form>

      {status === 'loading' && <p className="state-message">Loading…</p>}
      {status === 'error' && <p className="state-message state-message--error">Couldn't load the FAQ: {error}</p>}

      {status === 'ready' && groups.length === 0 && (
        <div className="faq-empty">
          Nothing matched that. <Link to="/support">Ask us directly</Link> and we will help.
        </div>
      )}

      {status === 'ready' && groups.map((group) => (
        <FaqGroup key={group.code} group={group} openId={openId} onToggle={(id) => setOpenId(openId === id ? null : id)} />
      ))}

      <div className="faq-footer">
        <p className="text-muted small">Still stuck?</p>
        <Link className="btn-outline" to="/support">Raise a support ticket</Link>
      </div>
    </div>
  );
}
