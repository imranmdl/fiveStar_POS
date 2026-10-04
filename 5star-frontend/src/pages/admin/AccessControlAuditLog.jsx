import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { Badge, when } from './AccessControlShared';

/**
 * Admin Privilege Management — Audit Log.
 * Ported from renderAudit() in admin/assets/page-access-control.js.
 *
 * Two sub-tabs: the audit trail (before/after values recorded on every
 * sensitive write, AdminAuditLogController::auditLogs) and request activity
 * (every API call this middleware gate saw, auditLogs::activityLogs).
 */

function AuditRow({ r }) {
  return (
    <tr>
      <td className="ac-nowrap">{when(r.created_date)}</td>
      <td>{r.performed_by_name || r.performed_by_role || 'System'}</td>
      <td>{r.action}</td>
      <td>{r.entity_name || ''} {r.entity_id ? `#${r.entity_id}` : ''}</td>
      <td><code className="ac-code">{r.old_values || ''}</code></td>
      <td><code className="ac-code">{r.new_values || ''}</code></td>
      <td>{r.notes || ''}</td>
      <td>{r.ip_address || ''}</td>
    </tr>
  );
}

function ActivityRow({ r }) {
  const tone = r.status_code < 300 ? 'success' : r.status_code < 500 ? 'warning' : 'danger';
  return (
    <tr>
      <td className="ac-nowrap">{when(r.created_date)}</td>
      <td>{r.user_name || 'Guest'} {r.user_role ? `(${r.user_role})` : ''}</td>
      <td>{r.module}</td>
      <td>{r.http_method} {r.endpoint}</td>
      <td><Badge tone={tone}>{r.status_code}</Badge></td>
      <td>{r.duration_ms} ms</td>
      <td>{r.ip_address || ''}</td>
    </tr>
  );
}

export default function AccessControlAuditLog() {
  const [subtab, setSubtab] = useState('audit');
  const [state, setState] = useState({ loading: true, error: null, rows: [] });

  useEffect(() => {
    setState({ loading: true, error: null, rows: [] });
    const path = subtab === 'audit' ? '/admin-privilege/audit-logs' : '/admin-privilege/activity-logs';
    api.get(path, { per_page: 100 })
      .then((res) => setState({ loading: false, error: null, rows: res.data || [] }))
      .catch((error) => setState({ loading: false, error, rows: [] }));
  }, [subtab]);

  return (
    <div>
      <div className="ac-subtabs">
        <button type="button" className={`ac-subtabs__btn ${subtab === 'audit' ? 'ac-subtabs__btn--active' : ''}`} onClick={() => setSubtab('audit')}>
          Audit trail (before/after values)
        </button>
        <button type="button" className={`ac-subtabs__btn ${subtab === 'activity' ? 'ac-subtabs__btn--active' : ''}`} onClick={() => setSubtab('activity')}>
          Request activity
        </button>
      </div>

      {state.loading && <LoadingState />}
      {state.error && <ErrorState error={state.error} />}

      {!state.loading && !state.error && (
        state.rows.length === 0 ? (
          <EmptyState title="No entries" />
        ) : (
          <div style={{ overflowX: 'auto' }}>
            {subtab === 'audit' ? (
              <table className="admin-table">
                <thead>
                  <tr><th>When</th><th>Admin/User</th><th>Action</th><th>Entity</th><th>Old value</th><th>New value</th><th>Reason</th><th>IP</th></tr>
                </thead>
                <tbody>{state.rows.map((r, i) => <AuditRow key={i} r={r} />)}</tbody>
              </table>
            ) : (
              <table className="admin-table">
                <thead>
                  <tr><th>When</th><th>User</th><th>Module</th><th>Endpoint</th><th>Status</th><th>Duration</th><th>IP</th></tr>
                </thead>
                <tbody>{state.rows.map((r, i) => <ActivityRow key={i} r={r} />)}</tbody>
              </table>
            )}
          </div>
        )
      )}
    </div>
  );
}
