import { useEffect, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import AccessControlUsers from './AccessControlUsers';
import AccessControlRoles from './AccessControlRoles';
import AccessControlApprovals from './AccessControlApprovals';
import AccessControlAuditLog from './AccessControlAuditLog';
import AccessControlWelcomeBonus from './AccessControlWelcomeBonus';
import './AccessControl.css';

/**
 * Admin Privilege Management — a console sidebar page, not a separate panel.
 * Ported from admin/assets/page-access-control.js.
 *
 * This used to be its own site (admin-privilege/) with its own login and its
 * own idle timeout — deliberately isolated so a compromised console session
 * couldn't reach role/permission editing. That panel has been retired:
 * everything it did now lives here, inside the ordinary console, using the
 * console's own session (the same `user` every other /admin/* page gets from
 * AdminLayout via useOutletContext).
 *
 * Two things from the old panel are DELIBERATELY kept even though this is no
 * longer a separate login:
 *   1. The server-side gate. Every call below still goes through
 *      AdminPrivilegeMiddleware ('adminPrivilege:<permission>') — nothing
 *      about moving the HTML here loosened who can actually do what.
 *   2. The step-up confirmation. Clicking this sidebar link does not drop
 *      straight into role editing — it shows a one-button "confirm your
 *      password" screen first (POST /admin-privilege/auth/confirm), so an
 *      unattended console tab left open cannot be clicked straight into this
 *      section by whoever walks up to it. Confirmed once, it stays confirmed
 *      for the rest of this browser tab (sessionStorage) — a fresh tab, or a
 *      different account signing in on this one, asks again.
 */

const CONFIRMED_KEY = 'spice.access_control_confirmed_uuid';

function isConfirmed(userUuid) {
  try {
    return sessionStorage.getItem(CONFIRMED_KEY) === userUuid;
  } catch {
    return false;
  }
}

function markConfirmed(userUuid) {
  try {
    sessionStorage.setItem(CONFIRMED_KEY, userUuid);
  } catch {
    /* private browsing: just asks again next click, no worse than that */
  }
}

function ShieldIcon() {
  return (
    <span className="ac-shield">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 3.5 4.5 6.2v5.3c0 4.6 3.1 8.4 7.5 9.8 4.4-1.4 7.5-5.2 7.5-9.8V6.2z" />
        <path d="m9 12 2 2 4-4" />
      </svg>
    </span>
  );
}

function ConfirmGate({ user, onConfirmed }) {
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const res = await api.post('/admin-privilege/auth/confirm', { password });
      markConfirmed(user.uuid);
      onConfirmed(res.data.permissions || []);
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <div className="page">
      <div className="ac-gate">
        <div className="ac-gate__card">
          <ShieldIcon />
          <h1 className="ac-gate__title">Admin Privilege</h1>
          <p className="ac-gate__hint">
            Signed in as <strong>{user.full_name}</strong>. Confirm your password to open this section.
          </p>
          <form onSubmit={handleSubmit}>
            <label className="ac-field">
              <span>Password</span>
              <input
                type="password"
                required
                autoFocus
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </label>
            {error && <ErrorState error={error} />}
            <button type="submit" className="admin-btn admin-btn--primary" style={{ width: '100%' }} disabled={busy}>
              {busy ? 'Signing in…' : 'Sign In'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}

const TABS = [
  { key: 'roles', label: 'Roles & Permissions', perm: 'role_management.view', Component: AccessControlRoles },
  { key: 'users', label: 'Staff Users', perm: 'user_management.view', Component: AccessControlUsers },
  { key: 'approvals', label: 'Approvals', perm: null, Component: AccessControlApprovals },
  { key: 'audit', label: 'Audit Log', perm: 'audit_logs.view', Component: AccessControlAuditLog },
  { key: 'wallet', label: 'Welcome Bonus', perm: 'system_settings.view', Component: AccessControlWelcomeBonus },
];

function Tabs({ perms, currentUser }) {
  const has = (code) => perms.indexOf(code) !== -1;
  const visibleTabs = TABS.filter((t) => !t.perm || has(t.perm));
  const [activeKey, setActiveKey] = useState(visibleTabs[0] && visibleTabs[0].key);

  if (visibleTabs.length === 0) {
    return <EmptyState title="Nothing to show" hint="Your role has no module permissions under Admin Privilege yet." />;
  }

  const active = visibleTabs.find((t) => t.key === activeKey) || visibleTabs[0];
  const ActiveComponent = active.Component;

  return (
    <>
      <div className="ac-header">
        <ShieldIcon />
        <h1 className="admin-page-title" style={{ margin: 0 }}>Admin Privilege</h1>
      </div>
      <p className="ac-subtitle">Roles, staff accounts, sensitive-action approvals and the audit trail.</p>

      <div className="ac-tabs">
        {visibleTabs.map((t) => (
          <button
            key={t.key}
            type="button"
            className={`ac-tabs__btn ${t.key === active.key ? 'ac-tabs__btn--active' : ''}`}
            onClick={() => setActiveKey(t.key)}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div className="ac-tab-panel">
        <ActiveComponent perms={perms} has={has} currentUser={currentUser} />
      </div>
    </>
  );
}

export default function AccessControl() {
  const { user } = useOutletContext();
  const [me, setMe] = useState({ loading: true, authorised: null, permissions: [] });
  const [confirmed, setConfirmed] = useState(() => isConfirmed(user.uuid));
  const [confirmedPermissions, setConfirmedPermissions] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.get('/admin-privilege/auth/me')
      .then((res) => {
        if (cancelled) return;
        setMe({ loading: false, authorised: true, permissions: res.data.permissions || [] });
      })
      .catch((error) => {
        if (cancelled) return;
        setMe({ loading: false, authorised: false, permissions: [], error });
      });
    return () => { cancelled = true; };
  }, []);

  if (me.loading) {
    return <div className="page"><LoadingState /></div>;
  }

  if (!me.authorised) {
    return (
      <div className="page">
        <EmptyState
          title="Not authorised"
          hint="This account is not authorised for Admin Privilege Management. Ask an administrator to grant the admin_privilege.access permission."
        />
      </div>
    );
  }

  if (!confirmed) {
    return (
      <ConfirmGate
        user={user}
        onConfirmed={(permissions) => {
          setConfirmedPermissions(permissions);
          setConfirmed(true);
        }}
      />
    );
  }

  const permissions = confirmedPermissions || me.permissions;

  return (
    <div className="page">
      <Tabs perms={permissions} currentUser={user} />
    </div>
  );
}
