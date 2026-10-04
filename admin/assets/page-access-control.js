/**
 * Admin Privilege as a console sidebar page, not a separate panel.
 *
 * This used to be its own site (admin-privilege/) with its own login, its
 * own token storage and its own idle timeout — deliberately isolated so a
 * compromised console session couldn't reach role/permission editing. That
 * panel has been retired: everything it did now lives here, inside the
 * ordinary console, using the console's own session.
 *
 * Two things from the old panel are DELIBERATELY kept even though this is no
 * longer a separate login:
 *   1. The server-side gate. Every call below still goes through
 *      AdminPrivilegeMiddleware ('adminPrivilege:<permission>') — the same
 *      live role_permissions check, the same audit-logged denials. Nothing
 *      about moving the HTML here loosened who can actually do what; a role
 *      without admin_privilege.access gets the same 403 whether it reaches
 *      these endpoints from this page or the old one ever did.
 *   2. The 20-minute idle timeout. AdminPrivilegeMiddleware still enforces it
 *      server-side on every /admin-privilege/* call this page makes, via
 *      admin_privilege_activity — unchanged from the old panel. What's gone
 *      is the CLIENT-side idle timer that used to force a full sign-out of
 *      the whole panel; now an idle gap just makes the next action on this
 *      one tab ask you to retry, the same as any other console page's token
 *      expiring.
 *
 * Not ported: the old panel's own dashboard (a tile grid linking out to
 * Products/Inventory/POS/etc). Those already have their own sidebar entries
 * here — a second set of tiles pointing at the same pages would just be
 * clutter. This page is scoped to what only lived in that panel: roles,
 * staff accounts, approvals, the audit trail, and the welcome-bonus setting.
 *
 * NOT DIRECTLY OPEN, EVEN NOW: clicking this sidebar link does not drop
 * straight into role editing. It shows a one-button "confirm your password"
 * screen first (POST /admin-privilege/auth/confirm) — a step-up check, not a
 * second sign-in, so an unattended console tab left open cannot be clicked
 * straight into this section by whoever walks up to it. Confirmed once, it
 * stays confirmed for the rest of this browser tab (sessionStorage), the
 * same way till.html caches its own sign-in — a fresh tab, or a different
 * account signing in on this one, asks again.
 */

import {
  api, mountConsole, showError, toast, setBusy, escapeHtml, emptyState, headerIcon, badge,
} from './console.js?v=9';
import { request } from '../../assets/js/api.js';

let root = null;
let PERMS = [];

function has(code) {
  return PERMS.indexOf(code) !== -1;
}

const SHIELD_PATH = '<path d="M12 3.5 4.5 6.2v5.3c0 4.6 3.1 8.4 7.5 9.8 4.4-1.4 7.5-5.2 7.5-9.8V6.2z"/>'
  + '<path d="m9 12 2 2 4-4"/>';

/* ------------------------------------------------------------------------- */
/* Roles & permissions                                                       */
/* ------------------------------------------------------------------------- */

let modulesCache = null;

async function loadModules() {
  if (modulesCache) return modulesCache;
  const res = await api.get('/admin-privilege/permissions');
  modulesCache = res.data.modules;
  return modulesCache;
}

async function renderRoles(panel) {
  const canEdit = has('role_management.edit');
  const canAdd = has('role_management.add');
  const canDelete = has('role_management.delete');

  panel.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <p class="text-muted small mb-0">Custom roles and the module/action permission matrix.</p>
      ${canAdd ? '<button class="btn btn-dark btn-sm" data-new-role>+ New role</button>' : ''}
    </div>
    <div class="row g-3">
      <div class="col-lg-3">
        <div class="list-group" data-role-list></div>
      </div>
      <div class="col-lg-9">
        <div class="card"><div class="card-body" data-role-detail>
          <p class="text-muted mb-0">Select a role on the left.</p>
        </div></div>
      </div>
    </div>`;

  async function loadRoles() {
    const res = await api.get('/admin-privilege/roles');
    const roles = res.data.roles;

    panel.querySelector('[data-role-list]').innerHTML = roles.map((r) => `
      <button type="button" class="list-group-item list-group-item-action" data-role-uuid="${escapeHtml(r.uuid)}">
        <div class="d-flex justify-content-between">
          <span class="fw-semibold">${escapeHtml(r.name)}</span>
          ${r.is_system ? '<span class="badge text-bg-secondary">system</span>' : ''}
        </div>
        <div class="small text-muted">${escapeHtml(r.code)} &middot; ${r.user_count} user(s)</div>
      </button>`).join('') || '<p class="text-muted small mb-0">No roles found.</p>';

    panel.querySelectorAll('[data-role-uuid]').forEach((btn) => {
      btn.addEventListener('click', () => selectRole(btn.dataset.roleUuid));
    });
  }

  async function selectRole(uuid) {
    panel.querySelectorAll('[data-role-uuid]').forEach((b) => b.classList.toggle('active', b.dataset.roleUuid === uuid));

    const [modules, detail] = await Promise.all([
      loadModules(),
      api.get(`/admin-privilege/roles/${uuid}`),
    ]);

    const role = detail.data.role;
    const granted = new Set(detail.data.permission_codes);
    const actions = ['view', 'add', 'edit', 'delete', 'approve', 'export'];

    const rows = modules.map((mod) => {
      const byAction = {};
      mod.permissions.forEach((p) => { byAction[p.action] = p; });

      const cells = actions.map((action) => {
        const perm = byAction[action];
        if (!perm) return '<td class="text-muted">&mdash;</td>';
        const checked = granted.has(perm.code) ? 'checked' : '';
        return `<td><input type="checkbox" class="form-check-input" data-perm-code="${escapeHtml(perm.code)}" ${checked} ${canEdit ? '' : 'disabled'}></td>`;
      }).join('');

      return `<tr><td class="text-start fw-semibold">${escapeHtml(mod.module)}</td>${cells}</tr>`;
    }).join('');

    panel.querySelector('[data-role-detail]').innerHTML = `
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h2 class="h5 mb-1">${escapeHtml(role.name)}</h2>
          <div class="text-muted small">${escapeHtml(role.description || '')}</div>
        </div>
        ${canDelete && !role.is_system ? '<button class="btn btn-outline-danger btn-sm" data-delete-role>Delete role</button>' : ''}
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle text-center">
          <thead><tr><th class="text-start">Module</th>${actions.map((a) => `<th>${a}</th>`).join('')}</tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      ${canEdit ? '<button class="btn btn-dark" data-save-permissions>Save permissions</button>' : ''}`;

    if (canEdit) {
      panel.querySelector('[data-save-permissions]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const codes = Array.from(panel.querySelectorAll('[data-perm-code]:checked')).map((el) => el.dataset.permCode);
        setBusy(button, true, 'Saving');

        try {
          await api.patch(`/admin-privilege/roles/${uuid}`, {
            permission_codes: codes,
            reason: 'Updated from the role editor',
          });
          toast('Permissions saved');
          await loadRoles();
        } catch (error) {
          showError(error);
        } finally {
          setBusy(button, false);
        }
      });
    }

    if (canDelete && !role.is_system) {
      panel.querySelector('[data-delete-role]').addEventListener('click', async () => {
        const reason = prompt('Reason for deleting this role?');
        if (reason === null) return;

        try {
          await request(`/admin-privilege/roles/${uuid}`, { method: 'DELETE', body: { reason } });
          toast('Role deleted');
          panel.querySelector('[data-role-detail]').innerHTML = '<p class="text-muted mb-0">Select a role on the left.</p>';
          await loadRoles();
        } catch (error) {
          showError(error);
        }
      });
    }
  }

  if (canAdd) {
    panel.querySelector('[data-new-role]').addEventListener('click', async () => {
      const code = prompt('Role code (lowercase, no spaces, e.g. store_manager)');
      if (!code) return;
      const name = prompt('Display name', code) || code;

      try {
        const created = await api.post('/admin-privilege/roles', { code, name, permission_codes: [] });
        toast('Role created');
        await loadRoles();
        await selectRole(created.data.role.uuid);
      } catch (error) {
        showError(error);
      }
    });
  }

  await loadRoles();
}

/* ------------------------------------------------------------------------- */
/* Staff users                                                               */
/* ------------------------------------------------------------------------- */

async function renderUsers(panel) {
  const canAdd = has('user_management.add');
  const canEdit = has('user_management.edit');
  const canDeactivate = has('user_management.delete');

  panel.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <p class="text-muted small mb-0">Staff accounts, role assignment and account recovery.</p>
      <div class="d-flex gap-2">
        <input type="search" class="form-control form-control-sm" style="width:220px" placeholder="Search name/mobile/email" data-search>
        ${canAdd ? '<button class="btn btn-dark btn-sm text-nowrap" data-new-user>+ New user</button>' : ''}
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Role</th><th>Status</th><th>Locked</th><th></th></tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>`;

  let roles = [];

  async function loadRoles() {
    const res = await api.get('/admin-privilege/roles');
    roles = res.data.roles;
  }

  function actionButtons(user) {
    const buttons = [];
    if (canEdit) {
      buttons.push(`<button class="btn btn-outline-secondary btn-sm" data-change-role="${escapeHtml(user.uuid)}">Change role</button>`);
      buttons.push(`<button class="btn btn-outline-secondary btn-sm" data-unlock="${escapeHtml(user.uuid)}">Unlock</button>`);
      buttons.push(`<button class="btn btn-outline-secondary btn-sm" data-force-logout="${escapeHtml(user.uuid)}">Force logout</button>`);
      buttons.push(`<button class="btn btn-outline-secondary btn-sm" data-reset-password="${escapeHtml(user.uuid)}">Reset password</button>`);
    }
    if (canDeactivate) {
      buttons.push(user.is_active
        ? `<button class="btn btn-outline-danger btn-sm" data-deactivate="${escapeHtml(user.uuid)}">Deactivate</button>`
        : `<button class="btn btn-outline-success btn-sm" data-activate="${escapeHtml(user.uuid)}">Activate</button>`);
    }
    return `<div class="d-flex gap-1 flex-wrap">${buttons.join('')}</div>`;
  }

  async function loadUsers(search) {
    const res = await api.get('/admin-privilege/users', { per_page: 50, ...(search ? { search } : {}) });
    const users = res.data;

    panel.querySelector('[data-rows]').innerHTML = users.map((u) => `
      <tr>
        <td>${escapeHtml(u.full_name)}</td>
        <td>${escapeHtml(u.mobile)}</td>
        <td>${escapeHtml(u.email || '')}</td>
        <td><span class="badge text-bg-secondary">${escapeHtml(u.role_code)}</span></td>
        <td>${escapeHtml(u.status)}</td>
        <td>${u.locked_until_date ? `<span class="text-danger">until ${escapeHtml(u.locked_until_date)}</span>` : '&mdash;'}</td>
        <td>${actionButtons(u)}</td>
      </tr>`).join('') || '<tr><td colspan="7" class="text-muted text-center py-3">No users found</td></tr>';

    wireRowActions();
  }

  function wireRowActions() {
    panel.querySelectorAll('[data-change-role]').forEach((btn) => btn.addEventListener('click', async () => {
      const choice = prompt(`Enter the role code to assign:\n${roles.map((r) => r.code).join(', ')}`);
      const role = roles.find((r) => r.code === (choice || '').trim());
      if (!role) return;
      const reason = prompt('Reason for this role change?') || '';

      try {
        await api.patch(`/admin-privilege/users/${btn.dataset.changeRole}/role`, { role_uuid: role.uuid, reason });
        toast('Role updated');
        await loadUsers(panel.querySelector('[data-search]').value.trim());
      } catch (error) { showError(error); }
    }));

    panel.querySelectorAll('[data-unlock]').forEach((btn) => btn.addEventListener('click', async () => {
      try {
        await api.post(`/admin-privilege/users/${btn.dataset.unlock}/unlock`, {});
        toast('Account unlocked');
        await loadUsers(panel.querySelector('[data-search]').value.trim());
      } catch (error) { showError(error); }
    }));

    panel.querySelectorAll('[data-force-logout]').forEach((btn) => btn.addEventListener('click', async () => {
      try {
        const res = await api.post(`/admin-privilege/users/${btn.dataset.forceLogout}/force-logout`, {});
        toast(`${res.data.sessions_revoked} session(s) signed out`);
      } catch (error) { showError(error); }
    }));

    panel.querySelectorAll('[data-reset-password]').forEach((btn) => btn.addEventListener('click', async () => {
      const password = prompt('New password (min 8 characters):');
      if (!password) return;
      try {
        await api.post(`/admin-privilege/users/${btn.dataset.resetPassword}/reset-password`, { password });
        toast('Password reset. Every session for this account was signed out.');
      } catch (error) { showError(error); }
    }));

    panel.querySelectorAll('[data-deactivate]').forEach((btn) => btn.addEventListener('click', async () => {
      const reason = prompt('Reason for deactivating this account?');
      if (reason === null || reason.trim() === '') return;
      try {
        await api.post(`/admin-privilege/users/${btn.dataset.deactivate}/deactivate`, { reason });
        toast('User deactivated');
        await loadUsers(panel.querySelector('[data-search]').value.trim());
      } catch (error) { showError(error); }
    }));

    panel.querySelectorAll('[data-activate]').forEach((btn) => btn.addEventListener('click', async () => {
      try {
        await api.post(`/admin-privilege/users/${btn.dataset.activate}/activate`, {});
        toast('User activated');
        await loadUsers(panel.querySelector('[data-search]').value.trim());
      } catch (error) { showError(error); }
    }));
  }

  panel.querySelector('[data-search]').addEventListener('input', (event) => {
    clearTimeout(window.__acUserSearchTimer);
    window.__acUserSearchTimer = setTimeout(() => loadUsers(event.target.value.trim()), 300);
  });

  if (canAdd) {
    panel.querySelector('[data-new-user]').addEventListener('click', async () => {
      const full_name = prompt('Full name');
      if (!full_name) return;
      const mobile = prompt('Mobile (10 digits)');
      if (!mobile) return;
      const email = prompt('Email (optional)') || null;
      const password = prompt('Temporary password (min 8 characters)');
      if (!password) return;
      const roleCode = prompt(`Role code (${roles.map((r) => r.code).join(', ')})`);
      const role = roles.find((r) => r.code === (roleCode || '').trim());
      if (!role) { toast('Unknown role code', 'danger'); return; }

      try {
        await api.post('/admin-privilege/users', { full_name, mobile, email, password, role_uuid: role.uuid });
        toast('User created');
        await loadUsers('');
      } catch (error) { showError(error); }
    });
  }

  await loadRoles();
  await loadUsers('');
}

/* ------------------------------------------------------------------------- */
/* Sensitive action approvals                                                */
/* ------------------------------------------------------------------------- */

async function renderApprovals(panel) {
  panel.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-2">
      <p class="text-muted small mb-0">
        Large discounts, refunds, wallet adjustments, stock adjustments, price changes, customer
        credit/dues and payment adjustments land here for review. Approving records the decision —
        the approver still carries out the underlying action through that module's own screen.
      </p>
      <select class="form-select form-select-sm text-nowrap" style="width:180px" data-status-filter>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
        <option value="">All</option>
      </select>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>When</th><th>Module</th><th>Action</th><th>Title</th><th>Amount</th><th>Requested by</th><th>Status</th><th></th></tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>`;

  async function load(status) {
    const res = await api.get('/admin-privilege/approvals', { per_page: 50, ...(status ? { status } : {}) });
    const rows = res.data;

    panel.querySelector('[data-rows]').innerHTML = rows.map((r) => `
      <tr>
        <td class="text-nowrap">${escapeHtml(r.created_date)}</td>
        <td>${escapeHtml(r.module)}</td>
        <td>${escapeHtml(r.action_type)}</td>
        <td>${escapeHtml(r.title)}<div class="small text-muted">${escapeHtml(r.reason)}</div></td>
        <td>${r.amount != null ? escapeHtml(r.amount) : '&mdash;'}</td>
        <td>${escapeHtml(r.requested_by_name || '')}</td>
        <td>${badge(r.status, r.status)}</td>
        <td>${r.status === 'pending' ? `
          <button class="btn btn-outline-success btn-sm" data-approve="${escapeHtml(r.uuid)}">Approve</button>
          <button class="btn btn-outline-danger btn-sm" data-reject="${escapeHtml(r.uuid)}">Reject</button>` : (r.decision_note ? escapeHtml(r.decision_note) : '')}
        </td>
      </tr>`).join('') || '<tr><td colspan="8" class="text-muted text-center py-3">Nothing here</td></tr>';

    panel.querySelectorAll('[data-approve]').forEach((btn) => btn.addEventListener('click', async () => {
      const note = prompt('Optional note for this approval:') || '';
      try {
        await api.post(`/admin-privilege/approvals/${btn.dataset.approve}/approve`, { note });
        toast('Request approved');
        await load(panel.querySelector('[data-status-filter]').value);
      } catch (error) { showError(error); }
    }));

    panel.querySelectorAll('[data-reject]').forEach((btn) => btn.addEventListener('click', async () => {
      const note = prompt('Reason for rejecting this request:');
      if (!note) return;
      try {
        await api.post(`/admin-privilege/approvals/${btn.dataset.reject}/reject`, { note });
        toast('Request rejected');
        await load(panel.querySelector('[data-status-filter]').value);
      } catch (error) { showError(error); }
    }));
  }

  panel.querySelector('[data-status-filter]').addEventListener('change', (event) => load(event.target.value));
  await load('pending');
}

/* ------------------------------------------------------------------------- */
/* Audit log                                                                 */
/* ------------------------------------------------------------------------- */

function auditRow(r) {
  return `<tr>
    <td class="text-nowrap">${escapeHtml(r.created_date)}</td>
    <td>${escapeHtml(r.performed_by_name || r.performed_by_role || 'System')}</td>
    <td>${escapeHtml(r.action)}</td>
    <td>${escapeHtml(r.entity_name || '')} ${r.entity_id ? '#' + escapeHtml(r.entity_id) : ''}</td>
    <td><code class="small">${escapeHtml(r.old_values || '')}</code></td>
    <td><code class="small">${escapeHtml(r.new_values || '')}</code></td>
    <td>${escapeHtml(r.notes || '')}</td>
    <td>${escapeHtml(r.ip_address || '')}</td>
  </tr>`;
}

function activityRow(r) {
  return `<tr>
    <td class="text-nowrap">${escapeHtml(r.created_date)}</td>
    <td>${escapeHtml(r.user_name || 'Guest')} ${r.user_role ? `(${escapeHtml(r.user_role)})` : ''}</td>
    <td>${escapeHtml(r.module)}</td>
    <td>${escapeHtml(r.http_method)} ${escapeHtml(r.endpoint)}</td>
    <td>${badge(r.status_code < 300 ? 'success' : r.status_code < 500 ? 'pending' : 'rejected', r.status_code)}</td>
    <td>${escapeHtml(r.duration_ms)} ms</td>
    <td>${escapeHtml(r.ip_address || '')}</td>
  </tr>`;
}

async function renderAudit(panel) {
  panel.innerHTML = `
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><button type="button" class="nav-link active" data-subtab="audit">Audit trail (before/after values)</button></li>
      <li class="nav-item"><button type="button" class="nav-link" data-subtab="activity">Request activity</button></li>
    </ul>
    <div data-subpanel></div>`;

  const sub = panel.querySelector('[data-subpanel]');

  async function loadAudit() {
    sub.innerHTML = '<div class="text-center py-4"><div class="spinner-border"></div></div>';
    const res = await api.get('/admin-privilege/audit-logs', { per_page: 100 });

    sub.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover">
      <thead><tr><th>When</th><th>Admin/User</th><th>Action</th><th>Entity</th><th>Old value</th><th>New value</th><th>Reason</th><th>IP</th></tr></thead>
      <tbody>${res.data.map(auditRow).join('') || '<tr><td colspan="8" class="text-muted text-center py-3">No entries</td></tr>'}</tbody>
    </table></div>`;
  }

  async function loadActivity() {
    sub.innerHTML = '<div class="text-center py-4"><div class="spinner-border"></div></div>';
    const res = await api.get('/admin-privilege/activity-logs', { per_page: 100 });

    sub.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover">
      <thead><tr><th>When</th><th>User</th><th>Module</th><th>Endpoint</th><th>Status</th><th>Duration</th><th>IP</th></tr></thead>
      <tbody>${res.data.map(activityRow).join('') || '<tr><td colspan="7" class="text-muted text-center py-3">No entries</td></tr>'}</tbody>
    </table></div>`;
  }

  panel.querySelectorAll('[data-subtab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      panel.querySelectorAll('[data-subtab]').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      btn.dataset.subtab === 'audit' ? loadAudit() : loadActivity();
    });
  });

  await loadAudit();
}

/* ------------------------------------------------------------------------- */
/* Welcome bonus                                                             */
/* ------------------------------------------------------------------------- */

async function renderWelcomeBonus(panel) {
  const canEdit = has('system_settings.edit');

  panel.innerHTML = `
    <p class="text-muted small mb-3" style="max-width:640px">
      When a new customer's mobile number is verified for the first time — at the end of
      registration, or the first time they sign in by OTP — this can credit a one-time amount
      straight to their wallet. Off by default; nothing is credited to anyone until this is
      turned on.
    </p>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header">Settings</div>
          <div class="card-body" data-form-body>
            <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header">How it works</div>
          <div class="card-body small text-muted">
            <ul class="ps-3 mb-0">
              <li class="mb-2">Applies once per customer, automatically — nothing for a cashier or
                the customer to do to claim it.</li>
              <li class="mb-2">Cannot be paid out twice, even if a customer gets verified more than
                once (e.g. registers, then later also verifies by OTP).</li>
              <li class="mb-2">An expiry of 0 days means the credit never expires.</li>
              <li class="mb-0">Turning this off does not take back credits already given — it only
                stops new ones.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>`;

  const formBody = panel.querySelector('[data-form-body]');

  function render(cfg) {
    formBody.innerHTML = `
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="ac-wb-enabled" ${cfg.enabled ? 'checked' : ''} ${canEdit ? '' : 'disabled'}>
        <label class="form-check-label fw-semibold" for="ac-wb-enabled">Credit new customers a welcome bonus</label>
      </div>
      <div class="mb-3">
        <label class="form-label small mb-0">Amount (₹)</label>
        <input type="number" class="form-control" id="ac-wb-amount" min="0" max="100000" step="1" value="${escapeHtml(cfg.amount)}" ${canEdit ? '' : 'disabled'}>
      </div>
      <div class="mb-3">
        <label class="form-label small mb-0">Expires after (days, 0 = never)</label>
        <input type="number" class="form-control" id="ac-wb-expiry" min="0" max="3650" step="1" value="${escapeHtml(cfg.expiry_days)}" ${canEdit ? '' : 'disabled'}>
      </div>
      ${canEdit ? '<button type="button" class="btn btn-dark" data-save>Save</button>' : '<p class="text-muted small mb-0">Your role can view this but not edit it.</p>'}`;

    if (!canEdit) return;

    formBody.querySelector('[data-save]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const enabled = formBody.querySelector('#ac-wb-enabled').checked;
      const amount = Number(formBody.querySelector('#ac-wb-amount').value);
      const expiryDays = Number(formBody.querySelector('#ac-wb-expiry').value);

      setBusy(button, true, 'Saving');

      try {
        const res = await api.patch('/admin-privilege/wallet/welcome-bonus', { enabled, amount, expiry_days: expiryDays });
        toast(enabled
          ? `Welcome bonus enabled — new customers now receive ₹${amount}.`
          : 'Welcome bonus disabled.');
        render(res.data);
      } catch (error) {
        showError(error);
      } finally {
        setBusy(button, false);
      }
    });
  }

  try {
    const res = await api.get('/admin-privilege/wallet/welcome-bonus');
    render(res.data);
  } catch (error) {
    showError(error, formBody);
  }
}

/* ------------------------------------------------------------------------- */
/* Shell: tabs, gated per-permission                                         */
/* ------------------------------------------------------------------------- */

const TABS = [
  { key: 'roles', label: 'Roles & Permissions', perm: 'role_management.view', render: renderRoles },
  { key: 'users', label: 'Staff Users', perm: 'user_management.view', render: renderUsers },
  { key: 'approvals', label: 'Approvals', perm: null, render: renderApprovals },
  { key: 'audit', label: 'Audit Log', perm: 'audit_logs.view', render: renderAudit },
  { key: 'wallet', label: 'Welcome Bonus', perm: 'system_settings.view', render: renderWelcomeBonus },
];

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
  } catch { /* private browsing: just asks again next click, no worse than that */ }
}

/**
 * The one-button gate. Not a sign-in — the console session already exists —
 * just "type your password again" before anything sensitive renders.
 */
function renderConfirmGate(user, onConfirmed) {
  root.innerHTML = `
    <div class="d-flex align-items-center justify-content-center" style="min-height:60vh">
      <div class="card shadow-sm" style="width:min(24rem,92vw)">
        <div class="card-body p-4 text-center">
          ${headerIcon('#7A1F3D', SHIELD_PATH)}
          <h1 class="h5 mt-2 mb-1">Admin Privilege</h1>
          <p class="text-muted small mb-3">Signed in as <strong>${escapeHtml(user.full_name)}</strong>.
            Confirm your password to open this section.</p>
          <form data-confirm-form>
            <div class="mb-3 text-start">
              <label class="form-label small" for="ac-confirm-password">Password</label>
              <input class="form-control" id="ac-confirm-password" name="password" type="password" required
                     autocomplete="current-password" autofocus>
            </div>
            <button class="btn btn-dark w-100" type="submit">Sign In</button>
          </form>
        </div>
      </div>
    </div>`;

  root.querySelector('[data-confirm-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button');
    const password = event.currentTarget.querySelector('#ac-confirm-password').value;
    setBusy(button, true, 'Signing in');

    try {
      const res = await api.post('/admin-privilege/auth/confirm', { password });
      markConfirmed(user.uuid);
      onConfirmed(res.data.permissions || []);
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

function renderTabs() {
  const visibleTabs = TABS.filter((t) => !t.perm || has(t.perm));

  if (visibleTabs.length === 0) {
    root.innerHTML = emptyState('Nothing to show', 'Your role has no module permissions under Admin Privilege yet.');
    return;
  }

  root.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-1">
      ${headerIcon('#7A1F3D', SHIELD_PATH)}
      <h1 class="h4 mb-0">Admin Privilege</h1>
    </div>
    <p class="text-muted small mb-3">Roles, staff accounts, sensitive-action approvals and the audit trail.</p>
    <ul class="nav nav-tabs mb-3" data-ac-tabs>
      ${visibleTabs.map((t, i) => `<li class="nav-item"><button type="button" class="nav-link ${i === 0 ? 'active' : ''}" data-tab="${t.key}">${escapeHtml(t.label)}</button></li>`).join('')}
    </ul>
    <div data-tab-panel></div>`;

  const panel = root.querySelector('[data-tab-panel]');

  async function open(tab) {
    panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border"></div></div>';
    try {
      await tab.render(panel);
    } catch (error) {
      showError(error, panel);
    }
  }

  root.querySelectorAll('[data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      root.querySelectorAll('[data-tab]').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      open(TABS.find((t) => t.key === btn.dataset.tab));
    });
  });

  open(visibleTabs[0]);
}

async function init() {
  const mounted = await mountConsole('access-control.html');
  if (!mounted) return;

  root = mounted.root;
  root.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div></div>';

  let me;

  try {
    me = await api.get('/admin-privilege/auth/me');
  } catch (error) {
    root.innerHTML = emptyState(
      'Not authorised',
      'This account is not authorised for Admin Privilege Management. Ask an administrator to grant the admin_privilege.access permission.'
    );
    return;
  }

  if (isConfirmed(mounted.user.uuid)) {
    PERMS = me.data.permissions || [];
    renderTabs();
    return;
  }

  renderConfirmGate(mounted.user, (permissions) => {
    PERMS = permissions;
    renderTabs();
  });
}

await init();
