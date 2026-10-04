import { useEffect, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatusBadge } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import { Modal, when } from './AccessControlShared';

/**
 * Admin Privilege Management — sensitive-action Approvals queue.
 * Ported from renderApprovals() in admin/assets/page-access-control.js.
 *
 * Large discounts, refunds, wallet adjustments, stock adjustments, price
 * changes, customer credit/dues and payment adjustments land here for
 * review (ApprovalController). Approving records the decision — the
 * approver still carries out the underlying action through that module's
 * own screen; nothing here performs the action itself.
 */

const STATUS_OPTIONS = [
  ['pending', 'Pending'],
  ['approved', 'Approved'],
  ['rejected', 'Rejected'],
  ['', 'All'],
];

function DecisionModal({ request, decision, onClose, onDone }) {
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const isReject = decision === 'reject';

  async function handleSubmit(event) {
    event.preventDefault();
    if (isReject && note.trim().length < 3) {
      setError({ message: 'A reason is required to reject this request.' });
      return;
    }
    setBusy(true);
    setError(null);

    try {
      await api.post(`/admin-privilege/approvals/${encodeURIComponent(request.uuid)}/${isReject ? 'reject' : 'approve'}`, { note });
      toast(isReject ? 'Request rejected' : 'Request approved');
      onDone();
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title={`${isReject ? 'Reject' : 'Approve'} — ${request.title}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <p className="ac-sub">{request.reason}</p>
        <label className="ac-field">
          <span>{isReject ? 'Reason for rejecting (required)' : 'Note (optional)'}</span>
          <input maxLength={500} required={isReject} minLength={isReject ? 3 : undefined} value={note} onChange={(e) => setNote(e.target.value)} />
        </label>
        {error && (error instanceof ApiError ? <ErrorState error={error} /> : <div className="admin-alert admin-alert--danger">{error.message}</div>)}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className={`admin-btn ${isReject ? 'admin-btn--danger' : 'admin-btn--success'}`} disabled={busy}>
            {busy ? 'Saving…' : isReject ? 'Reject' : 'Approve'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

export default function AccessControlApprovals() {
  const [status, setStatus] = useState('pending');
  const [state, setState] = useState({ loading: true, error: null, rows: [] });
  const [modal, setModal] = useState(null);

  function load(statusValue) {
    setState((prev) => ({ ...prev, loading: true, error: null }));
    api.get('/admin-privilege/approvals', { per_page: 50, ...(statusValue ? { status: statusValue } : {}) })
      .then((res) => setState({ loading: false, error: null, rows: res.data || [] }))
      .catch((error) => setState({ loading: false, error, rows: [] }));
  }

  useEffect(() => { load(status); }, [status]);

  return (
    <div>
      <div className="ac-toolbar">
        <p className="ac-sub" style={{ margin: 0, maxWidth: '38rem' }}>
          Large discounts, refunds, wallet adjustments, stock adjustments, price changes, customer
          credit/dues and payment adjustments land here for review. Approving records the decision —
          the approver still carries out the underlying action through that module's own screen.
        </p>
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          {STATUS_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      {state.loading && <LoadingState />}
      {state.error && <ErrorState error={state.error} />}

      {!state.loading && !state.error && (
        state.rows.length === 0 ? (
          <EmptyState title="Nothing here" hint="No requests match this filter." />
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr><th>When</th><th>Module</th><th>Action</th><th>Title</th><th>Amount</th><th>Requested by</th><th>Status</th><th></th></tr>
              </thead>
              <tbody>
                {state.rows.map((r) => (
                  <tr key={r.uuid}>
                    <td className="ac-nowrap">{when(r.created_date)}</td>
                    <td>{r.module}</td>
                    <td>{r.action_type}</td>
                    <td>
                      {r.title}
                      <div className="ac-sub">{r.reason}</div>
                    </td>
                    <td>{r.amount != null ? formatMoney(r.amount) : '—'}</td>
                    <td>{r.requested_by_name || ''}</td>
                    <td><StatusBadge status={r.status} /></td>
                    <td>
                      {r.status === 'pending' ? (
                        <div className="ac-row-actions">
                          <button type="button" className="admin-btn admin-btn--success" onClick={() => setModal({ request: r, decision: 'approve' })}>Approve</button>
                          <button type="button" className="admin-btn admin-btn--danger" onClick={() => setModal({ request: r, decision: 'reject' })}>Reject</button>
                        </div>
                      ) : (r.decision_note || '')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {modal && (
        <DecisionModal
          request={modal.request}
          decision={modal.decision}
          onClose={() => setModal(null)}
          onDone={() => load(status)}
        />
      )}
    </div>
  );
}
