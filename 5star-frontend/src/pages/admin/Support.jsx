/**
 * Support tickets: the queue and the thread. Ported faithfully from the live
 * admin/assets/page-support.js — filters, reply/internal-note, assignment and
 * the resolve flow all follow the same endpoints and gating as the source.
 *
 * The thread view is in-page state (a query param), not a nested route, so
 * no change to adminRoutes.jsx is needed.
 */
import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { StatusBadge, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Support.css';

function reportError(error, target, fallback) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || fallback || 'Something went wrong.');
  if (target) target(text);
  else toast(text, 'danger');
}

function fmtDate(value) {
  return String(value || '').slice(0, 16).replace('T', ' ');
}

function statusLabel(status) {
  return String(status || '').replace(/_/g, ' ');
}

const STATUS_TABS = [
  ['open', 'Open'],
  ['in_progress', 'In progress'],
  ['awaiting_customer', 'Waiting on customer'],
  ['resolved', 'Resolved'],
  ['closed', 'Closed'],
  ['', 'All'],
];

/* ----------------------------------------------------------------------- */
/* List                                                                     */
/* ----------------------------------------------------------------------- */

function TicketList({ filter, onFilter, onOpen }) {
  const [state, setState] = useState({ loading: true, error: null, tickets: [] });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/support/tickets', { status: filter, per_page: 50 });
      setState({ loading: false, error: null, tickets: response.data || [] });
    } catch (error) {
      setState({ loading: false, error, tickets: [] });
    }
  }, [filter]);

  useEffect(() => { load(); }, [load]);

  return (
    <>
      <div className="admin-toolbar">
        <h1 className="admin-page-title" style={{ margin: 0 }}>Support</h1>
        <div className="support-tabs">
          {STATUS_TABS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              className={`admin-btn ${filter === value ? 'admin-btn--primary' : ''}`}
              onClick={() => onFilter(value)}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.tickets.length === 0 ? (
        <EmptyState title="No tickets here" hint="Nothing currently has that status." />
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="admin-table">
            <thead>
              <tr><th>Subject</th><th>About</th><th>Status</th><th>Priority</th><th>SLA</th></tr>
            </thead>
            <tbody>
              {state.tickets.map((ticket) => (
                <tr key={ticket.uuid} className={ticket.first_response_breached ? 'support-row--breached' : ''}>
                  <td>
                    <button type="button" className="support-link" onClick={() => onOpen(ticket.uuid)}>{ticket.subject}</button>
                    <div className="support-subtext">{ticket.ticket_number} &middot; {ticket.contact_name || ''}</div>
                  </td>
                  <td>{ticket.category}</td>
                  <td><StatusBadge status={ticket.status} label={statusLabel(ticket.status)} /></td>
                  <td>
                    {ticket.priority === 'urgent' || ticket.priority === 'high'
                      ? <StatusBadge status="failed" label={ticket.priority} />
                      : <span className="support-subtext">{ticket.priority}</span>}
                  </td>
                  <td className="support-subtext">
                    {ticket.first_response_breached
                      ? <span style={{ color: '#c0392b', fontWeight: 600 }}>Response overdue</span>
                      : ticket.first_response_date
                        ? <span style={{ color: '#2e7d32' }}>Responded</span>
                        : <span>Awaiting first reply</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

/* ----------------------------------------------------------------------- */
/* Thread                                                                   */
/* ----------------------------------------------------------------------- */

function AssignForm({ uuid, ticket, onAssigned }) {
  const [staff, setStaff] = useState(null); // null while loading, [] on failure, else list
  const [staffError, setStaffError] = useState(false);
  const [assignee, setAssignee] = useState(ticket.assigned_to_uuid || '');
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState(null);

  useEffect(() => {
    let mounted = true;
    api.get('/admin/support/staff')
      .then((response) => { if (mounted) setStaff(response.data.staff || []); })
      .catch(() => { if (mounted) { setStaff([]); setStaffError(true); } });
    return () => { mounted = false; };
  }, []);

  async function handleSubmit(event) {
    event.preventDefault();
    if (!assignee) return;
    setBusy(true);
    setFeedback(null);
    try {
      await api.post(`/admin/support/tickets/${encodeURIComponent(uuid)}/assign`, { assignee_uuid: assignee });
      toast('Ticket assigned.');
      onAssigned();
    } catch (error) {
      setBusy(false);
      reportError(error, setFeedback);
    }
  }

  return (
    <div className="support-card">
      <div className="support-card__body">
        <h2 className="support-card__title">Assigned to</h2>
        <p className="support-subtext" style={{ marginBottom: 8 }}>
          {ticket.assigned_to_name ? ticket.assigned_to_name : <span>Nobody yet</span>}
        </p>
        <form onSubmit={handleSubmit}>
          <select value={assignee} onChange={(e) => setAssignee(e.target.value)} required disabled={staff === null}>
            {staff === null ? (
              <option value="">Loading staff…</option>
            ) : staffError ? (
              <option value="">Could not load staff</option>
            ) : (
              <>
                <option value="">Select a staff member…</option>
                {staff.map((s) => <option key={s.uuid} value={s.uuid}>{s.full_name}</option>)}
              </>
            )}
          </select>
          {feedback && <div className="admin-alert admin-alert--danger" style={{ marginTop: 8 }}>{feedback}</div>}
          <button className="admin-btn" type="submit" disabled={busy} style={{ width: '100%', marginTop: 8 }}>
            {busy ? 'Assigning…' : 'Assign'}
          </button>
        </form>
      </div>
    </div>
  );
}

function ReplyForm({ uuid, onSent }) {
  const [body, setBody] = useState('');
  const [internal, setInternal] = useState(false);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setFeedback(null);
    try {
      await api.post(`/admin/support/tickets/${encodeURIComponent(uuid)}/reply`, {
        body,
        internal_note: internal,
      });
      toast(internal ? 'Note added.' : 'Reply sent.');
      setBody('');
      setInternal(false);
      onSent();
    } catch (error) {
      setBusy(false);
      reportError(error, setFeedback);
    }
  }

  return (
    <div className="support-card">
      <div className="support-card__body">
        <form onSubmit={handleSubmit}>
          <label htmlFor="reply-body" style={{ fontWeight: 600, fontSize: 13 }}>Reply</label>
          <textarea
            id="reply-body"
            rows={4}
            required
            value={body}
            onChange={(e) => setBody(e.target.value)}
            style={{ width: '100%', marginTop: 4 }}
          />
          <div className="support-checkbox">
            <input
              id="internal-note"
              type="checkbox"
              checked={internal}
              onChange={(e) => setInternal(e.target.checked)}
            />
            <label htmlFor="internal-note" className="support-subtext">
              Internal note — visible to staff only, and it does NOT count as a first response for SLA purposes
            </label>
          </div>
          {feedback && <div className="admin-alert admin-alert--danger" style={{ marginTop: 8 }}>{feedback}</div>}
          <button className="admin-btn admin-btn--primary" type="submit" disabled={busy} style={{ marginTop: 8 }}>
            {busy ? 'Sending…' : 'Send'}
          </button>
        </form>
      </div>
    </div>
  );
}

function ResolveForm({ uuid, onResolved }) {
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setFeedback(null);
    try {
      await api.post(`/admin/support/tickets/${encodeURIComponent(uuid)}/resolve`, { note });
      toast('Ticket resolved.');
      onResolved();
    } catch (error) {
      setBusy(false);
      reportError(error, setFeedback);
    }
  }

  return (
    <div className="support-card">
      <div className="support-card__body">
        <h2 className="support-card__title">Resolve</h2>
        <form onSubmit={handleSubmit}>
          <textarea
            rows={3}
            required
            minLength={5}
            placeholder="What was done? The customer sees this."
            value={note}
            onChange={(e) => setNote(e.target.value)}
            style={{ width: '100%' }}
          />
          {feedback && <div className="admin-alert admin-alert--danger" style={{ marginTop: 8 }}>{feedback}</div>}
          <button className="admin-btn" type="submit" disabled={busy} style={{ width: '100%', marginTop: 8, background: '#2e7d32', color: '#fff', borderColor: '#2e7d32' }}>
            {busy ? 'Resolving…' : 'Mark resolved'}
          </button>
        </form>
      </div>
    </div>
  );
}

function TicketThread({ uuid, onBack }) {
  const [state, setState] = useState({ loading: true, error: null, ticket: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get(`/admin/support/tickets/${encodeURIComponent(uuid)}`);
      setState({ loading: false, error: null, ticket: response.data });
    } catch (error) {
      setState({ loading: false, error, ticket: null });
    }
  }, [uuid]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <button type="button" className="support-link" onClick={onBack}>&larr; All tickets</button>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : (() => {
        const ticket = state.ticket;
        return (
          <>
            <h1 className="admin-page-title" style={{ margin: '8px 0 4px' }}>{ticket.subject}</h1>
            <div style={{ marginBottom: 16 }}>
              <StatusBadge status={ticket.status} label={statusLabel(ticket.status)} />
              <span className="support-subtext" style={{ marginLeft: 8 }}>
                {ticket.ticket_number} &middot; {ticket.contact_name} &middot; {ticket.contact_mobile}
              </span>
              {ticket.first_response_breached && (
                <span style={{ marginLeft: 8 }}><StatusBadge status="failed" label="First response overdue" /></span>
              )}
            </div>

            <div className="support-thread-grid">
              <div>
                <div className="support-messages">
                  {ticket.messages.map((message, index) => (
                    <div key={index} className={`support-message ${message.is_internal_note ? 'support-message--internal' : ''}`}>
                      <div className="support-message__author">
                        {message.author_name || message.author_type}
                        {message.is_internal_note && (
                          <span className="support-message__note-flag">Internal note — not shown to the customer</span>
                        )}
                      </div>
                      <div>{message.body}</div>
                      <div className="support-subtext">{fmtDate(message.created_date)}</div>
                    </div>
                  ))}
                </div>

                {ticket.status === 'closed' ? (
                  <p className="support-subtext">This ticket is closed.</p>
                ) : (
                  <ReplyForm uuid={uuid} onSent={load} />
                )}
              </div>

              <div>
                <AssignForm uuid={uuid} ticket={ticket} onAssigned={load} />

                {['resolved', 'closed'].includes(ticket.status) ? (
                  <div className="support-card">
                    <div className="support-card__body">
                      <div style={{ fontWeight: 600 }}>Resolved</div>
                      <p style={{ margin: 0 }}>{ticket.resolution_note || ''}</p>
                      {ticket.satisfaction_rating && (
                        <div style={{ marginTop: 8 }}>Customer rated this {ticket.satisfaction_rating}/5</div>
                      )}
                    </div>
                  </div>
                ) : (
                  <ResolveForm uuid={uuid} onResolved={load} />
                )}
              </div>
            </div>
          </>
        );
      })()}
    </div>
  );
}

/* ----------------------------------------------------------------------- */
/* Top level                                                               */
/* ----------------------------------------------------------------------- */

export default function Support() {
  const [params, setParams] = useSearchParams();

  // Default filter is 'open' (an absent param), and the "All" tab needs a
  // value distinct from that default, so it is stored as the sentinel
  // 'all' in the URL and mapped to '' (no status filter) for the API call.
  const rawStatus = params.get('status');
  const filter = rawStatus === null ? 'open' : (rawStatus === 'all' ? '' : rawStatus);
  const uuid = params.get('uuid');

  function patch(partial) {
    const next = new URLSearchParams(params);
    Object.entries(partial).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') next.delete(key);
      else next.set(key, String(value));
    });
    setParams(next);
  }

  function handleFilter(value) {
    if (value === 'open') patch({ status: null, uuid: null });
    else if (value === '') patch({ status: 'all', uuid: null });
    else patch({ status: value, uuid: null });
  }

  if (uuid) {
    return <TicketThread uuid={uuid} onBack={() => patch({ uuid: null })} />;
  }

  return <TicketList filter={filter} onFilter={handleFilter} onOpen={(ticketUuid) => patch({ uuid: ticketUuid })} />;
}
