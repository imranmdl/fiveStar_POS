/**
 * Marketing: who left their contact details on the storefront, and the
 * recurring offer/new-arrival/best-seller SMS sent to those who opted in.
 *
 * Only SMS actually sends today — see MarketingService::sendRecurringBroadcast()
 * and the note on this page's broadcast card. Email/WhatsApp have no real
 * provider wired in yet, so a lead's email is captured for later but
 * nothing is sent to it.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, badge, emptyState } from './console.js?v=9';

const TASK_CODE = 'marketing.recurring_broadcast';

let root = null;

function leadRow(lead) {
  const consented = lead.consent_marketing && !lead.unsubscribed_date;

  return `
    <tr>
      <td class="fw-semibold">${escapeHtml(lead.full_name || '—')}</td>
      <td class="small">${escapeHtml(lead.email)}</td>
      <td class="small font-monospace">${escapeHtml(lead.mobile)}</td>
      <td>
        ${lead.unsubscribed_date
          ? '<span class="badge text-bg-secondary">Unsubscribed</span>'
          : (consented ? '<span class="badge text-bg-success">Opted in</span>' : '<span class="badge text-bg-warning">Not opted in</span>')}
      </td>
      <td class="small">${escapeHtml(lead.message_count)} sent</td>
      <td class="small">${escapeHtml(String(lead.created_date || '').slice(0, 10))}</td>
    </tr>`;
}

function leadsTable(leads) {
  if (leads.length === 0) {
    return emptyState('No leads yet', 'Nothing captured on the storefront popup so far.');
  }

  return `
    <div class="table-responsive">
      <table class="table table-tight table-hover mb-0">
        <thead><tr><th>Name</th><th>Email</th><th>Mobile</th><th>Consent</th><th>Messages</th><th>Captured</th></tr></thead>
        <tbody>${leads.map(leadRow).join('')}</tbody>
      </table>
    </div>`;
}

function broadcastCard(task) {
  if (!task) {
    return `<div class="alert alert-warning small mb-0">The recurring broadcast task wasn't found — check the migration ran.</div>`;
  }

  const days = Math.round(task.interval_minutes / 1440);

  return `
    <div class="row g-3 align-items-center mb-3">
      <div class="col-auto">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="broadcast-toggle" data-broadcast-toggle
                 ${task.is_enabled ? 'checked' : ''}>
          <label class="form-check-label small" for="broadcast-toggle">
            ${task.is_enabled ? 'Enabled' : 'Disabled'} — sends every ${escapeHtml(days)} day(s)
          </label>
        </div>
      </div>
      <div class="col-auto">
        <button type="button" class="btn btn-sm btn-outline-dark" data-send-now>Send now</button>
      </div>
    </div>
    <dl class="row small mb-0">
      <dt class="col-4 col-lg-2 fw-normal">Next run</dt>
      <dd class="col-8 col-lg-10">${task.next_run_date ? escapeHtml(String(task.next_run_date).slice(0, 16).replace('T', ' ')) : '—'}</dd>
      <dt class="col-4 col-lg-2 fw-normal">Last run</dt>
      <dd class="col-8 col-lg-10">
        ${task.last_run_date
          ? `${badge(task.last_run_status, task.last_run_status)} ${escapeHtml(String(task.last_run_date).slice(0, 16).replace('T', ' '))} — ${escapeHtml(task.last_run_summary || '')}`
          : '<span class="text-muted">Never run</span>'}
      </dd>
    </dl>`;
}

async function render() {
  root.innerHTML = `
    <h1 class="h4 mb-3">Marketing</h1>
    <div data-panel><div class="text-center py-5 text-muted"><div class="spinner-border"></div></div></div>`;

  const panel = root.querySelector('[data-panel]');

  try {
    const [leadsResponse, tasksResponse] = await Promise.all([
      api.get('/admin/leads', { per_page: 100 }),
      api.get('/admin/scheduler/tasks'),
    ]);

    const leads = leadsResponse.data || [];
    const summary = leadsResponse.meta.summary || { total: 0, consented: 0, unsubscribed: 0 };
    const task = (tasksResponse.data.tasks || []).find((t) => t.code === TASK_CODE) || null;

    panel.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-3 g-3 mb-4">
        <div class="col"><div class="card h-100"><div class="card-body">
          <div class="text-muted small">Total leads</div>
          <div class="fs-4 fw-semibold">${escapeHtml(summary.total)}</div>
        </div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body">
          <div class="text-muted small">Opted in (messageable)</div>
          <div class="fs-4 fw-semibold text-success">${escapeHtml(summary.consented)}</div>
        </div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body">
          <div class="text-muted small">Unsubscribed</div>
          <div class="fs-4 fw-semibold">${escapeHtml(summary.unsubscribed)}</div>
        </div></div></div>
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white fw-semibold">Recurring offer broadcast</div>
        <div class="card-body">
          ${broadcastCard(task)}
          <p class="small text-muted mt-3 mb-0">
            Each run picks the best live offer, else the newest product, else a best-seller, and
            sends one SMS to every opted-in lead — never more than once a day per lead, however many
            times this is triggered. Only SMS actually delivers today; email is captured for later
            but has no live provider wired in yet.
          </p>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white fw-semibold">Leads</div>
        <div class="card-body p-0">${leadsTable(leads)}</div>
      </div>`;

    const toggle = panel.querySelector('[data-broadcast-toggle]');

    if (toggle) {
      toggle.addEventListener('change', async () => {
        toggle.disabled = true;

        try {
          await api.patch(`/admin/scheduler/tasks/${encodeURIComponent(TASK_CODE)}`, { is_enabled: toggle.checked });
          toast(toggle.checked ? 'Broadcast enabled.' : 'Broadcast disabled.');
          render();
        } catch (error) {
          toggle.checked = !toggle.checked;
          toggle.disabled = false;
          showError(error);
        }
      });
    }

    const sendNowButton = panel.querySelector('[data-send-now]');

    if (sendNowButton) {
      sendNowButton.addEventListener('click', async () => {
        setBusy(sendNowButton, true, 'Sending');

        try {
          const response = await api.post('/admin/scheduler/run', { task: TASK_CODE });
          const result = (response.data.results || [])[0];
          toast(result ? result.summary : 'Run complete.');
          render();
        } catch (error) {
          setBusy(sendNowButton, false);
          showError(error);
        }
      });
    }
  } catch (error) {
    panel.innerHTML = '';
    showError(error, panel);
  }
}

const mounted = await mountConsole('marketing.html');
if (mounted) { root = mounted.root; render(); }
