/** Support tickets: the queue and the thread. */

import { api, mountConsole, showError, toast, setBusy, escapeHtml,
         badge, emptyState, queryParam } from './console.js?v=9';

let root = null;
let filter = 'open';

function row(ticket) {
  return `
    <tr class="${ticket.first_response_breached ? 'table-danger' : ''}">
      <td>
        <a class="fw-semibold text-decoration-none"
           href="support.html?uuid=${encodeURIComponent(ticket.uuid)}">${escapeHtml(ticket.subject)}</a>
        <div class="small text-muted">
          ${escapeHtml(ticket.ticket_number)} · ${escapeHtml(ticket.contact_name || '')}
        </div>
      </td>
      <td>${escapeHtml(ticket.category)}</td>
      <td>${badge(ticket.status, String(ticket.status).replace(/_/g, ' '))}</td>
      <td>
        ${ticket.priority === 'urgent' || ticket.priority === 'high'
          ? `<span class="badge text-bg-danger">${escapeHtml(ticket.priority)}</span>`
          : `<span class="text-muted small">${escapeHtml(ticket.priority)}</span>`}
      </td>
      <td class="small">
        ${ticket.first_response_breached
          ? '<span class="text-danger fw-semibold">Response overdue</span>'
          : (ticket.first_response_date
              ? '<span class="text-success">Responded</span>'
              : '<span class="text-muted">Awaiting first reply</span>')}
      </td>
    </tr>`;
}

async function renderList() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Support</h1>
      <div class="btn-group btn-group-sm">
        ${[['open', 'Open'], ['in_progress', 'In progress'], ['awaiting_customer', 'Waiting on customer'],
           ['resolved', 'Resolved'], ['closed', 'Closed'], ['', 'All']].map(([value, label]) => `
          <button type="button" class="btn ${filter === value ? 'btn-dark' : 'btn-outline-secondary'}"
                  data-filter="${value}">${escapeHtml(label)}</button>`).join('')}
      </div>
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  root.querySelectorAll('[data-filter]').forEach((button) => {
    button.addEventListener('click', () => { filter = button.dataset.filter; renderList(); });
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/support/tickets', { status: filter, per_page: 50 });
    const tickets = response.data || [];

    if (tickets.length === 0) {
      list.innerHTML = emptyState('No tickets here', 'Nothing currently has that status.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Subject</th><th>About</th><th>Status</th><th>Priority</th><th>SLA</th></tr></thead>
          <tbody>${tickets.map(row).join('')}</tbody>
        </table>
      </div>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

async function renderThread(uuid) {
  try {
    const response = await api.get(`/admin/support/tickets/${encodeURIComponent(uuid)}`);
    const ticket = response.data;

    root.innerHTML = `
      <a class="small text-decoration-none" href="support.html">← All tickets</a>
      <h1 class="h4 mt-2 mb-1">${escapeHtml(ticket.subject)}</h1>
      <div class="mb-3">
        ${badge(ticket.status, String(ticket.status).replace(/_/g, ' '))}
        <span class="small text-muted ms-2">
          ${escapeHtml(ticket.ticket_number)} · ${escapeHtml(ticket.contact_name)} ·
          ${escapeHtml(ticket.contact_mobile)}
        </span>
        ${ticket.first_response_breached
          ? '<span class="badge text-bg-danger ms-2">First response overdue</span>' : ''}
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-8">
          <div class="mb-3">
            ${ticket.messages.map((message) => `
              <div class="card mb-2 ${message.is_internal_note ? 'border-warning bg-light' : ''}">
                <div class="card-body py-2">
                  <div class="small fw-semibold">
                    ${escapeHtml(message.author_name || message.author_type)}
                    ${message.is_internal_note
                      ? '<span class="badge text-bg-warning ms-2">Internal note — not shown to the customer</span>'
                      : ''}
                  </div>
                  <div>${escapeHtml(message.body)}</div>
                  <div class="small text-muted">
                    ${escapeHtml(String(message.created_date || '').slice(0, 16).replace('T', ' '))}
                  </div>
                </div>
              </div>`).join('')}
          </div>

          ${ticket.status === 'closed' ? '<p class="text-muted small">This ticket is closed.</p>' : `
            <div class="card">
              <div class="card-body">
                <form data-reply-form>
                  <label class="form-label" for="body">Reply</label>
                  <textarea class="form-control" id="body" name="body" rows="4" required></textarea>
                  <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="internal" name="internal_note">
                    <label class="form-check-label small" for="internal">
                      Internal note — visible to staff only, and it does NOT count as a
                      first response for SLA purposes
                    </label>
                  </div>
                  <div data-reply-feedback class="mt-2"></div>
                  <button class="btn btn-dark mt-2" type="submit">Send</button>
                </form>
              </div>
            </div>`}
        </div>

        <div class="col-12 col-lg-4">
          <div class="card mb-3">
            <div class="card-body">
              <h2 class="h6">Assigned to</h2>
              <p class="small mb-2">${ticket.assigned_to_name ? escapeHtml(ticket.assigned_to_name) : '<span class="text-muted">Nobody yet</span>'}</p>
              <form data-assign-form>
                <select class="form-select form-select-sm" name="assignee_uuid" required>
                  <option value="">Loading staff…</option>
                </select>
                <div data-assign-feedback class="small mt-2"></div>
                <button class="btn btn-sm btn-outline-dark w-100 mt-2" type="submit">Assign</button>
              </form>
            </div>
          </div>

          ${['resolved', 'closed'].includes(ticket.status) ? `
            <div class="card">
              <div class="card-body small">
                <div class="fw-semibold">Resolved</div>
                <p class="mb-0">${escapeHtml(ticket.resolution_note || '')}</p>
                ${ticket.satisfaction_rating
                  ? `<div class="mt-2">Customer rated this ${escapeHtml(ticket.satisfaction_rating)}/5</div>` : ''}
              </div>
            </div>` : `
            <div class="card">
              <div class="card-body">
                <h2 class="h6">Resolve</h2>
                <form data-resolve-form>
                  <textarea class="form-control" name="note" rows="3" required minlength="5"
                            placeholder="What was done? The customer sees this."></textarea>
                  <div data-resolve-feedback class="mt-2"></div>
                  <button class="btn btn-success w-100 mt-2" type="submit">Mark resolved</button>
                </form>
              </div>
            </div>`}
        </div>
      </div>`;

    const assignForm = root.querySelector('[data-assign-form]');
    const assigneeSelect = assignForm.querySelector('[name=assignee_uuid]');

    api.get('/admin/support/staff').then((response) => {
      const staff = response.data.staff || [];
      assigneeSelect.innerHTML = `<option value="">Select a staff member…</option>${staff.map((s) => `
        <option value="${escapeHtml(s.uuid)}" ${s.uuid === ticket.assigned_to_uuid ? 'selected' : ''}>${escapeHtml(s.full_name)}</option>`).join('')}`;
    }).catch(() => {
      assigneeSelect.innerHTML = '<option value="">Could not load staff</option>';
    });

    assignForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = assignForm.querySelector('button');
      const assigneeUuid = assigneeSelect.value;
      setBusy(button, true, 'Assigning');

      try {
        await api.post(`/admin/support/tickets/${encodeURIComponent(uuid)}/assign`, { assignee_uuid: assigneeUuid });
        toast('Ticket assigned.');
        renderThread(uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error, assignForm.querySelector('[data-assign-feedback]'));
      }
    });

    const replyForm = root.querySelector('[data-reply-form]');

    if (replyForm) {
      replyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = replyForm.querySelector('button');
        const data = new FormData(replyForm);
        setBusy(button, true, 'Sending');

        try {
          await api.post(`/admin/support/tickets/${encodeURIComponent(uuid)}/reply`, {
            body: data.get('body'),
            internal_note: data.get('internal_note') === 'on',
          });
          toast(data.get('internal_note') === 'on' ? 'Note added.' : 'Reply sent.');
          renderThread(uuid);
        } catch (error) {
          setBusy(button, false);
          showError(error, replyForm.querySelector('[data-reply-feedback]'));
        }
      });
    }

    const resolveForm = root.querySelector('[data-resolve-form]');

    if (resolveForm) {
      resolveForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = resolveForm.querySelector('button');
        setBusy(button, true, 'Resolving');

        try {
          await api.post(`/admin/support/tickets/${encodeURIComponent(uuid)}/resolve`, {
            note: new FormData(resolveForm).get('note'),
          });
          toast('Ticket resolved.');
          renderThread(uuid);
        } catch (error) {
          setBusy(button, false);
          showError(error, resolveForm.querySelector('[data-resolve-feedback]'));
        }
      });
    }
  } catch (error) {
    root.innerHTML = '<a class="small" href="support.html">← All tickets</a>';
    showError(error, root);
  }
}

const mounted = await mountConsole('support.html');

if (mounted) {
  root = mounted.root;
  const uuid = queryParam('uuid');
  if (uuid) renderThread(uuid); else renderList();
}
