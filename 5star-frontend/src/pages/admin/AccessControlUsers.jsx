import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import { Modal } from './AccessControlShared';

/**
 * Admin Privilege Management — Staff Users.
 * Ported from renderUsers() in admin/assets/page-access-control.js.
 *
 * list/create/activate/deactivate/unlock/force-logout/reset-password/
 * change-role, each a thin call onto AdminUserManagementController.
 *
 * ADDED (not in the live page, a deliberate safety improvement for this
 * React port): the signed-in user's own row cannot deactivate itself or
 * force-logout itself from this table — doing either would instantly lock
 * the person performing the action out of their own session. The server
 * does not special-case this either way; this is a client-side guard only.
 */

function NewUserModal({ roles, onClose, onCreated }) {
  const [fullName, setFullName] = useState('');
  const [mobile, setMobile] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [roleUuid, setRoleUuid] = useState(roles[0] ? roles[0].uuid : '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.post('/admin-privilege/users', {
        full_name: fullName,
        mobile,
        email: email.trim() === '' ? null : email,
        password,
        role_uuid: roleUuid,
      });
      toast('User created');
      onCreated();
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title="New staff user" onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <label className="ac-field">
          <span>Full name</span>
          <input required minLength={3} maxLength={120} value={fullName} onChange={(e) => setFullName(e.target.value)} />
        </label>
        <label className="ac-field">
          <span>Mobile (10 digits)</span>
          <input required inputMode="numeric" maxLength={10} value={mobile} onChange={(e) => setMobile(e.target.value)} />
        </label>
        <label className="ac-field">
          <span>Email (optional)</span>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label className="ac-field">
          <span>Temporary password (min 8 characters)</span>
          <input type="text" required minLength={8} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <label className="ac-field">
          <span>Role</span>
          <select required value={roleUuid} onChange={(e) => setRoleUuid(e.target.value)}>
            {roles.map((r) => <option key={r.uuid} value={r.uuid}>{r.name} ({r.code})</option>)}
          </select>
        </label>
        {error && <ErrorState error={error} />}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Creating…' : 'Create user'}</button>
        </div>
      </form>
    </Modal>
  );
}

function ChangeRoleModal({ user, roles, onClose, onSaved }) {
  const [roleUuid, setRoleUuid] = useState(() => {
    const current = roles.find((r) => r.code === user.role_code);
    return current ? current.uuid : (roles[0] ? roles[0].uuid : '');
  });
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.patch(`/admin-privilege/users/${encodeURIComponent(user.uuid)}/role`, { role_uuid: roleUuid, reason });
      toast('Role updated');
      onSaved();
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title={`Change role — ${user.full_name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <label className="ac-field">
          <span>Role</span>
          <select required value={roleUuid} onChange={(e) => setRoleUuid(e.target.value)}>
            {roles.map((r) => <option key={r.uuid} value={r.uuid}>{r.name} ({r.code})</option>)}
          </select>
        </label>
        <label className="ac-field">
          <span>Reason (optional)</span>
          <input maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
        {error && <ErrorState error={error} />}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </Modal>
  );
}

function ResetPasswordModal({ user, onClose }) {
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.post(`/admin-privilege/users/${encodeURIComponent(user.uuid)}/reset-password`, { password });
      toast('Password reset. Every session for this account was signed out.');
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title={`Reset password — ${user.full_name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <label className="ac-field">
          <span>New password (min 8 characters)</span>
          <input type="text" required minLength={8} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        {error && <ErrorState error={error} />}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Reset password'}</button>
        </div>
      </form>
    </Modal>
  );
}

function DeactivateModal({ user, onClose, onSaved }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.post(`/admin-privilege/users/${encodeURIComponent(user.uuid)}/deactivate`, { reason });
      toast('User deactivated');
      onSaved();
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title={`Deactivate — ${user.full_name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <p className="ac-sub">This signs the account out and blocks sign-in until reactivated.</p>
        <label className="ac-field">
          <span>Reason (required)</span>
          <input required minLength={3} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
        {error && <ErrorState error={error} />}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--danger" disabled={busy}>{busy ? 'Saving…' : 'Deactivate'}</button>
        </div>
      </form>
    </Modal>
  );
}

export default function AccessControlUsers({ has, currentUser }) {
  const canAdd = has('user_management.add');
  const canEdit = has('user_management.edit');
  const canDeactivate = has('user_management.delete');

  const [search, setSearch] = useState('');
  const [roles, setRoles] = useState([]);
  const [state, setState] = useState({ loading: true, error: null, users: [] });
  const [modal, setModal] = useState(null);
  const debounceRef = useRef(null);
  const busyRef = useRef({});
  const [, setBusyTick] = useState(0);

  function setRowBusy(uuid, value) {
    busyRef.current = { ...busyRef.current, [uuid]: value };
    setBusyTick((n) => n + 1);
  }

  function loadUsers(searchValue) {
    setState((prev) => ({ ...prev, loading: true, error: null }));
    api.get('/admin-privilege/users', { per_page: 50, ...(searchValue ? { search: searchValue } : {}) })
      .then((res) => setState({ loading: false, error: null, users: res.data || [] }))
      .catch((error) => setState({ loading: false, error, users: [] }));
  }

  useEffect(() => {
    api.get('/admin-privilege/roles').then((res) => setRoles(res.data.roles || [])).catch(() => {});
    loadUsers('');
  }, []);

  function handleSearchChange(value) {
    setSearch(value);
    clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => loadUsers(value.trim()), 300);
  }

  async function handleUnlock(user) {
    setRowBusy(user.uuid, true);
    try {
      await api.post(`/admin-privilege/users/${encodeURIComponent(user.uuid)}/unlock`, {});
      toast('Account unlocked');
      loadUsers(search.trim());
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not unlock this account.', 'danger');
    } finally {
      setRowBusy(user.uuid, false);
    }
  }

  async function handleForceLogout(user) {
    setRowBusy(user.uuid, true);
    try {
      const res = await api.post(`/admin-privilege/users/${encodeURIComponent(user.uuid)}/force-logout`, {});
      toast(`${res.data.sessions_revoked} session(s) signed out`);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not sign out this account.', 'danger');
    } finally {
      setRowBusy(user.uuid, false);
    }
  }

  async function handleActivate(user) {
    setRowBusy(user.uuid, true);
    try {
      await api.post(`/admin-privilege/users/${encodeURIComponent(user.uuid)}/activate`, {});
      toast('User activated');
      loadUsers(search.trim());
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not activate this account.', 'danger');
    } finally {
      setRowBusy(user.uuid, false);
    }
  }

  return (
    <div>
      <div className="ac-toolbar">
        <p className="ac-sub" style={{ margin: 0 }}>Staff accounts, role assignment and account recovery.</p>
        <div className="ac-toolbar__right">
          <input
            type="search"
            placeholder="Search name/mobile/email"
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
          />
          {canAdd && (
            <button type="button" className="admin-btn admin-btn--primary" onClick={() => setModal({ type: 'new' })}>
              + New user
            </button>
          )}
        </div>
      </div>

      {state.loading && <LoadingState />}
      {state.error && <ErrorState error={state.error} />}

      {!state.loading && !state.error && (
        state.users.length === 0 ? (
          <EmptyState title="No users found" hint="Try a different search, or add a new user." />
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Name</th><th>Mobile</th><th>Email</th><th>Role</th><th>Status</th><th>Locked</th><th></th>
                </tr>
              </thead>
              <tbody>
                {state.users.map((u) => {
                  const isSelf = currentUser && currentUser.uuid === u.uuid;
                  const busy = Boolean(busyRef.current[u.uuid]);
                  return (
                    <tr key={u.uuid}>
                      <td>{u.full_name}{isSelf && <span className="ac-sub"> (you)</span>}</td>
                      <td>{u.mobile}</td>
                      <td>{u.email || '—'}</td>
                      <td><span className="status-badge status-badge--secondary">{u.role_code}</span></td>
                      <td>{u.status}</td>
                      <td>{u.locked_until_date ? <span style={{ color: 'var(--danger)' }}>until {u.locked_until_date}</span> : '—'}</td>
                      <td>
                        <div className="ac-row-actions">
                          {canEdit && (
                            <>
                              <button type="button" className="admin-btn" disabled={busy} onClick={() => setModal({ type: 'role', user: u })}>Change role</button>
                              <button type="button" className="admin-btn" disabled={busy} onClick={() => handleUnlock(u)}>Unlock</button>
                              <button
                                type="button"
                                className="admin-btn"
                                disabled={busy || isSelf}
                                title={isSelf ? "You can't force-logout your own session from here." : undefined}
                                onClick={() => handleForceLogout(u)}
                              >
                                Force logout
                              </button>
                              <button type="button" className="admin-btn" disabled={busy} onClick={() => setModal({ type: 'reset', user: u })}>Reset password</button>
                            </>
                          )}
                          {canDeactivate && (
                            u.is_active
                              ? (
                                <button
                                  type="button"
                                  className="admin-btn admin-btn--danger"
                                  disabled={busy || isSelf}
                                  title={isSelf ? "You can't deactivate your own account." : undefined}
                                  onClick={() => setModal({ type: 'deactivate', user: u })}
                                >
                                  Deactivate
                                </button>
                              )
                              : (
                                <button type="button" className="admin-btn admin-btn--success" disabled={busy} onClick={() => handleActivate(u)}>Activate</button>
                              )
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )
      )}

      {modal && modal.type === 'new' && (
        <NewUserModal roles={roles} onClose={() => setModal(null)} onCreated={() => loadUsers('')} />
      )}
      {modal && modal.type === 'role' && (
        <ChangeRoleModal user={modal.user} roles={roles} onClose={() => setModal(null)} onSaved={() => loadUsers(search.trim())} />
      )}
      {modal && modal.type === 'reset' && (
        <ResetPasswordModal user={modal.user} onClose={() => setModal(null)} />
      )}
      {modal && modal.type === 'deactivate' && (
        <DeactivateModal user={modal.user} onClose={() => setModal(null)} onSaved={() => loadUsers(search.trim())} />
      )}
    </div>
  );
}
