import { useEffect, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import { Modal } from './AccessControlShared';

/**
 * Admin Privilege Management — Roles & Permissions.
 * Ported from renderRoles() in admin/assets/page-access-control.js.
 *
 * A role is a code/name/description plus a set of permission_codes; each
 * permission is "<module>.<action>" (view/add/edit/delete/approve/export).
 * Saving the matrix replaces the role's whole permission set in one call —
 * RoleManagementController::update, not a per-checkbox toggle endpoint.
 */

const ACTIONS = ['view', 'add', 'edit', 'delete', 'approve', 'export'];

// Modules rarely change at runtime (they come from a fixed permission
// catalogue), so this is fetched once per page session, not once per click —
// same as modulesCache in the original page.
let modulesCache = null;

async function loadModules() {
  if (modulesCache) return modulesCache;
  const res = await api.get('/admin-privilege/permissions');
  modulesCache = res.data.modules || [];
  return modulesCache;
}

function NewRoleModal({ onClose, onCreated }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const res = await api.post('/admin-privilege/roles', {
        code: code.trim().toLowerCase(),
        name: name.trim() || code.trim(),
        description: description.trim() === '' ? null : description.trim(),
        permission_codes: [],
      });
      toast('Role created');
      onCreated(res.data.role.uuid);
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title="New role" onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <label className="ac-field">
          <span>Role code (lowercase, no spaces — e.g. store_manager)</span>
          <input required pattern="[a-z][a-z0-9_]*" maxLength={50} value={code} onChange={(e) => setCode(e.target.value)} />
        </label>
        <label className="ac-field">
          <span>Display name</span>
          <input required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} placeholder={code} />
        </label>
        <label className="ac-field">
          <span>Description (optional)</span>
          <input maxLength={255} value={description} onChange={(e) => setDescription(e.target.value)} />
        </label>
        {error && <ErrorState error={error} />}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>{busy ? 'Creating…' : 'Create role'}</button>
        </div>
      </form>
    </Modal>
  );
}

function DeleteRoleModal({ role, onClose, onDeleted }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.delete(`/admin-privilege/roles/${encodeURIComponent(role.uuid)}`, { reason });
      toast('Role deleted');
      onDeleted();
      onClose();
    } catch (err) {
      setBusy(false);
      setError(err);
    }
  }

  return (
    <Modal title={`Delete role — ${role.name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="ac-form">
        <p className="ac-sub">
          This fails if the role is still assigned to any user — reassign them to a different role first.
        </p>
        <label className="ac-field">
          <span>Reason (optional)</span>
          <input maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
        {error && <ErrorState error={error} />}
        <div className="ac-modal__actions">
          <button type="button" className="admin-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="admin-btn admin-btn--danger" disabled={busy}>{busy ? 'Deleting…' : 'Delete role'}</button>
        </div>
      </form>
    </Modal>
  );
}

function RoleDetail({ role, modules, grantedCodes, canEdit, canDelete, onSave, onDeleteRequest, saving }) {
  const [checked, setChecked] = useState(() => new Set(grantedCodes));

  useEffect(() => { setChecked(new Set(grantedCodes)); }, [role.uuid, grantedCodes]);

  function toggle(code) {
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(code)) next.delete(code); else next.add(code);
      return next;
    });
  }

  return (
    <div>
      <div className="ac-role-detail__head">
        <div>
          <h2 className="ac-role-detail__title">{role.name}</h2>
          <div className="ac-sub">{role.description || ''}</div>
        </div>
        {canDelete && !role.is_system && (
          <button type="button" className="admin-btn admin-btn--danger" onClick={onDeleteRequest}>Delete role</button>
        )}
      </div>

      <div style={{ overflowX: 'auto' }}>
        <table className="admin-table ac-matrix">
          <thead>
            <tr>
              <th>Module</th>
              {ACTIONS.map((a) => <th key={a} style={{ textAlign: 'center', textTransform: 'capitalize' }}>{a}</th>)}
            </tr>
          </thead>
          <tbody>
            {modules.map((mod) => {
              const byAction = {};
              mod.permissions.forEach((p) => { byAction[p.action] = p; });
              return (
                <tr key={mod.module}>
                  <td style={{ fontWeight: 600 }}>{mod.module}</td>
                  {ACTIONS.map((action) => {
                    const perm = byAction[action];
                    if (!perm) return <td key={action} style={{ textAlign: 'center', color: 'var(--ink-300)' }}>—</td>;
                    return (
                      <td key={action} style={{ textAlign: 'center' }}>
                        <input
                          type="checkbox"
                          checked={checked.has(perm.code)}
                          disabled={!canEdit}
                          onChange={() => toggle(perm.code)}
                        />
                      </td>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {canEdit && (
        <button type="button" className="admin-btn admin-btn--primary" style={{ marginTop: 12 }} disabled={saving} onClick={() => onSave(Array.from(checked))}>
          {saving ? 'Saving…' : 'Save permissions'}
        </button>
      )}
    </div>
  );
}

export default function AccessControlRoles({ has }) {
  const canEdit = has('role_management.edit');
  const canAdd = has('role_management.add');
  const canDelete = has('role_management.delete');

  const [state, setState] = useState({ loading: true, error: null, roles: [] });
  const [modules, setModules] = useState(null);
  const [selectedUuid, setSelectedUuid] = useState(null);
  const [detail, setDetail] = useState({ loading: false, error: null, role: null, codes: [] });
  const [saving, setSaving] = useState(false);
  const [modal, setModal] = useState(null);

  function loadRoles() {
    setState((prev) => ({ ...prev, loading: true, error: null }));
    api.get('/admin-privilege/roles')
      .then((res) => setState({ loading: false, error: null, roles: res.data.roles || [] }))
      .catch((error) => setState({ loading: false, error, roles: [] }));
  }

  useEffect(loadRoles, []);

  async function selectRole(uuid) {
    setSelectedUuid(uuid);
    setDetail({ loading: true, error: null, role: null, codes: [] });

    try {
      const [mods, res] = await Promise.all([
        modules || loadModules().then((m) => { setModules(m); return m; }),
        api.get(`/admin-privilege/roles/${encodeURIComponent(uuid)}`),
      ]);
      setDetail({ loading: false, error: null, role: res.data.role, codes: res.data.permission_codes || [] });
      if (!modules) setModules(mods);
    } catch (error) {
      setDetail({ loading: false, error, role: null, codes: [] });
    }
  }

  async function savePermissions(codes) {
    setSaving(true);
    try {
      await api.patch(`/admin-privilege/roles/${encodeURIComponent(selectedUuid)}`, {
        permission_codes: codes,
        reason: 'Updated from the role editor',
      });
      toast('Permissions saved');
      await selectRole(selectedUuid);
      loadRoles();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not save permissions.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <div className="ac-toolbar">
        <p className="ac-sub" style={{ margin: 0 }}>Custom roles and the module/action permission matrix.</p>
        {canAdd && (
          <button type="button" className="admin-btn admin-btn--primary" onClick={() => setModal({ type: 'new' })}>+ New role</button>
        )}
      </div>

      {state.loading && <LoadingState />}
      {state.error && <ErrorState error={state.error} />}

      {!state.loading && !state.error && (
        <div className="ac-roles-grid">
          <div className="ac-role-list">
            {state.roles.length === 0 && <EmptyState title="No roles found" />}
            {state.roles.map((r) => (
              <button
                key={r.uuid}
                type="button"
                className={`ac-role-list__item ${r.uuid === selectedUuid ? 'ac-role-list__item--active' : ''}`}
                onClick={() => selectRole(r.uuid)}
              >
                <div className="ac-role-list__row">
                  <span style={{ fontWeight: 600 }}>{r.name}</span>
                  {Boolean(r.is_system) && <span className="status-badge status-badge--secondary">system</span>}
                </div>
                <div className="ac-sub">{r.code} · {r.user_count} user(s)</div>
              </button>
            ))}
          </div>

          <div className="ac-role-detail">
            {!selectedUuid && <p className="ac-sub">Select a role on the left.</p>}
            {detail.loading && <LoadingState />}
            {detail.error && <ErrorState error={detail.error} />}
            {!detail.loading && !detail.error && detail.role && modules && (
              <RoleDetail
                role={detail.role}
                modules={modules}
                grantedCodes={detail.codes}
                canEdit={canEdit}
                canDelete={canDelete}
                saving={saving}
                onSave={savePermissions}
                onDeleteRequest={() => setModal({ type: 'delete', role: detail.role })}
              />
            )}
          </div>
        </div>
      )}

      {modal && modal.type === 'new' && (
        <NewRoleModal
          onClose={() => setModal(null)}
          onCreated={(uuid) => { loadRoles(); selectRole(uuid); }}
        />
      )}
      {modal && modal.type === 'delete' && (
        <DeleteRoleModal
          role={modal.role}
          onClose={() => setModal(null)}
          onDeleted={() => { setSelectedUuid(null); setDetail({ loading: false, error: null, role: null, codes: [] }); loadRoles(); }}
        />
      )}
    </div>
  );
}
