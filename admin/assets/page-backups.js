/**
 * Whole-database backup/restore, and hard-deleting rows that are already
 * soft-deleted past a retention window. Administrator-only (see the routes
 * these calls hit) — restore and cleanup are both genuinely destructive, so
 * every action that mutates anything here goes through window.confirm()
 * first, on top of the server's own confirm=yes requirement.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, emptyState } from './console.js?v=9';

let root = null;

function formatBytes(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

async function triggerDownload(filename) {
  const blob = await api.downloadFile(`/admin/backups/${encodeURIComponent(filename)}/download`);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function renderBackupsList(container) {
  container.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>';

  try {
    const response = await api.get('/admin/backups');
    const backups = response.data.backups || [];

    if (backups.length === 0) {
      container.innerHTML = emptyState('No backups yet', 'Use "Create backup" above to make the first one.');
      return;
    }

    container.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead>
          <tbody>${backups.map((b) => `
            <tr>
              <td class="small">${escapeHtml(b.filename)}</td>
              <td class="small">${escapeHtml(formatBytes(b.bytes))}</td>
              <td class="small">${escapeHtml(b.created_date)}</td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-secondary" data-download="${escapeHtml(b.filename)}" type="button">Download</button>
                <button class="btn btn-sm btn-outline-danger" data-delete="${escapeHtml(b.filename)}" type="button">Delete</button>
              </td>
            </tr>`).join('')}</tbody>
        </table>
      </div>`;

    container.querySelectorAll('[data-download]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Preparing');
        try {
          await triggerDownload(button.dataset.download);
          setBusy(button, false);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    container.querySelectorAll('[data-delete]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!window.confirm(`Delete backup "${button.dataset.delete}"? This cannot be undone.`)) return;
        setBusy(button, true, 'Deleting');
        try {
          await api.delete(`/admin/backups/${encodeURIComponent(button.dataset.delete)}`);
          toast('Backup deleted.');
          renderBackupsList(container);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

function bindCreateForm(list) {
  root.querySelector('[data-create-backup]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    setBusy(button, true, 'Creating');

    try {
      await api.post('/admin/backups');
      toast('Backup created.');
      renderBackupsList(list);
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });
}

function bindRestoreForm() {
  const form = root.querySelector('[data-restore-form]');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const fileInput = form.querySelector('[name=file]');
    const file = fileInput.files[0];

    if (!file) {
      toast('Choose a .sql file first.', 'danger');
      return;
    }

    if (!window.confirm(
      'This replaces live data with what is in the file you chose. A safety backup of the '
      + 'current database is taken automatically first, but this is still not reversible '
      + 'from here. Continue?'
    )) {
      return;
    }

    const button = form.querySelector('button[type=submit]');
    setBusy(button, true, 'Restoring');

    const formData = new FormData();
    formData.append('file', file);
    formData.append('confirm', 'yes');

    try {
      const result = await api.upload('/admin/backups/restore', formData);
      toast(`Restore complete. Safety backup: ${result.data.safety_backup.filename}`);
      form.reset();
      renderBackupsList(root.querySelector('[data-backups-list]'));
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });
}

/** Every currently-checked row checkbox, grouped as {table: [id, ...]} for the run() request body. */
function collectSelections(resultHost) {
  const selections = {};
  let count = 0;

  resultHost.querySelectorAll('[data-cleanup-row]:checked').forEach((box) => {
    const table = box.dataset.table;
    (selections[table] ||= []).push(Number(box.dataset.id));
    count += 1;
  });

  return { selections, count };
}

function updateRunButtonLabel(runButton, resultHost) {
  const { count } = collectSelections(resultHost);
  runButton.disabled = count === 0;
  runButton.textContent = count > 0 ? `Delete selected (${count})` : 'Delete selected';
}

/** Keeps a table's own header checkbox in sync when its rows are toggled one at a time. */
function syncTableCheckbox(resultHost, table) {
  const rows = resultHost.querySelectorAll(`[data-cleanup-row][data-table="${table}"]`);
  const checked = resultHost.querySelectorAll(`[data-cleanup-row][data-table="${table}"]:checked`);
  const tableBox = resultHost.querySelector(`[data-cleanup-select-table="${table}"]`);
  if (tableBox) {
    tableBox.checked = checked.length === rows.length;
    tableBox.indeterminate = checked.length > 0 && checked.length < rows.length;
  }
}

function bindCleanupForm() {
  const form = root.querySelector('[data-cleanup-form]');
  const resultHost = root.querySelector('[data-cleanup-result]');
  const runButton = root.querySelector('[data-cleanup-run]');
  let lastPreviewedDays = null;

  runButton.disabled = true;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const days = Number(new FormData(form).get('retention_days'));
    const button = form.querySelector('button[type=submit]');
    setBusy(button, true, 'Checking');
    runButton.disabled = true;

    try {
      const response = await api.get('/admin/data-cleanup/preview', { retention_days: days });
      const { total, tables } = response.data;
      lastPreviewedDays = days;

      resultHost.innerHTML = total === 0
        ? `<div class="alert alert-secondary small mb-0">Nothing older than ${escapeHtml(days)} days is sitting in the trash — no rows would be removed.</div>`
        : `
          <div class="alert alert-warning small mb-2">
            ${escapeHtml(total)} row(s) across ${escapeHtml(tables.length)} table(s) are eligible — every one is listed below,
            unchecked by default. Nothing is deleted unless you tick it, then use "Delete selected".
          </div>
          <div class="d-flex gap-2 mb-2">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-cleanup-select-all>Select all</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-cleanup-select-none>Deselect all</button>
          </div>
          <div class="accordion" data-cleanup-accordion>
            ${tables.map((t, index) => `
              <div class="accordion-item">
                <h3 class="accordion-header d-flex align-items-center">
                  <input class="form-check-input ms-3 me-2" type="checkbox" data-cleanup-select-table="${escapeHtml(t.table)}">
                  <button class="accordion-button collapsed py-2 small" type="button" data-bs-toggle="collapse" data-bs-target="#cleanup-table-${index}">
                    <span class="fw-semibold me-2">${escapeHtml(t.table)}</span>
                    <span class="text-muted">— ${escapeHtml(t.count)} row(s)${t.count > t.rows.length ? ` (showing oldest ${escapeHtml(t.rows.length)} of ${escapeHtml(t.count)})` : ''}</span>
                  </button>
                </h3>
                <div class="accordion-collapse collapse" id="cleanup-table-${index}">
                  <div class="accordion-body p-0">
                    <div class="table-responsive">
                      <table class="table table-sm table-tight mb-0">
                        <thead><tr><th></th><th>ID</th><th>${t.label_column ? escapeHtml(t.label_column) : 'Label'}</th><th>Deleted on</th></tr></thead>
                        <tbody>
                          ${t.rows.map((row) => `
                            <tr>
                              <td><input class="form-check-input" type="checkbox" data-cleanup-row data-table="${escapeHtml(t.table)}" data-id="${escapeHtml(row.id)}"></td>
                              <td class="small">${escapeHtml(row.id)}</td>
                              <td class="small">${row.label !== null && row.label !== '' ? escapeHtml(row.label) : '<span class="text-muted">—</span>'}</td>
                              <td class="small">${escapeHtml(String(row.deleted_date || '').slice(0, 16).replace('T', ' '))}</td>
                            </tr>`).join('')}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>`).join('')}
          </div>`;

      if (total > 0) {
        resultHost.querySelectorAll('[data-cleanup-row]').forEach((box) => {
          box.addEventListener('change', () => {
            syncTableCheckbox(resultHost, box.dataset.table);
            updateRunButtonLabel(runButton, resultHost);
          });
        });

        resultHost.querySelectorAll('[data-cleanup-select-table]').forEach((tableBox) => {
          tableBox.addEventListener('change', () => {
            const table = tableBox.dataset.cleanupSelectTable;
            resultHost.querySelectorAll(`[data-cleanup-row][data-table="${table}"]`).forEach((box) => {
              box.checked = tableBox.checked;
            });
            tableBox.indeterminate = false;
            updateRunButtonLabel(runButton, resultHost);
          });
        });

        resultHost.querySelector('[data-cleanup-select-all]').addEventListener('click', () => {
          resultHost.querySelectorAll('[data-cleanup-row], [data-cleanup-select-table]').forEach((box) => {
            box.checked = true;
            box.indeterminate = false;
          });
          updateRunButtonLabel(runButton, resultHost);
        });

        resultHost.querySelector('[data-cleanup-select-none]').addEventListener('click', () => {
          resultHost.querySelectorAll('[data-cleanup-row], [data-cleanup-select-table]').forEach((box) => {
            box.checked = false;
            box.indeterminate = false;
          });
          updateRunButtonLabel(runButton, resultHost);
        });
      }

      updateRunButtonLabel(runButton, resultHost);
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  runButton.addEventListener('click', async () => {
    if (lastPreviewedDays === null) return;

    const { selections, count } = collectSelections(resultHost);
    if (count === 0) return;

    if (!window.confirm(
      `Permanently delete the ${count} checked row(s) above, already-deleted for `
      + `${lastPreviewedDays}+ days? Anything left unchecked will be kept. This cannot be undone.`
    )) {
      return;
    }

    setBusy(runButton, true, 'Deleting');

    try {
      const response = await api.post('/admin/data-cleanup/run', {
        retention_days: lastPreviewedDays,
        confirm: 'yes',
        selections,
      });
      const { total_deleted: totalDeleted, tables } = response.data;
      // A table can land in BOTH lists — it deletes row by row now (see
      // DataCleanupService::run()'s own doc comment), so one blocked row no
      // longer stops the rest of that table's genuinely safe rows from
      // being removed.
      const removed = tables.filter((t) => t.deleted > 0);
      const blocked = tables.filter((t) => t.skipped > 0 || (t.error && t.deleted === 0));

      toast(`Deleted ${totalDeleted} row(s).`, blocked.length > 0 ? 'warning' : 'success');

      // Every table this run touched, whether it fully succeeded, partly
      // succeeded, or was entirely blocked — the previous version discarded
      // all of this and only ever showed a generic "Deleted N row(s)" toast,
      // which is exactly why some tables' rows looked like they were never
      // being removed: they were being blocked, just invisibly.
      resultHost.innerHTML = `
        ${removed.length > 0 ? `
          <div class="alert alert-success small mb-2">
            <div class="fw-semibold mb-1">Removed:</div>
            <ul class="mb-0 ps-3">${removed.map((t) => `<li>${escapeHtml(t.table)}: ${escapeHtml(t.deleted)}</li>`).join('')}</ul>
          </div>` : ''}
        ${blocked.length > 0 ? `
          <div class="alert alert-danger small mb-0">
            <div class="fw-semibold mb-1">Still referenced elsewhere — left alone rather than risk breaking that other record:</div>
            <ul class="mb-0 ps-3">${blocked.map((t) => `<li>${escapeHtml(t.table)}: ${escapeHtml(t.skipped ?? '?')} row(s)${t.error ? ` — ${escapeHtml(t.error)}` : ''}</li>`).join('')}</ul>
          </div>` : ''}`;

      runButton.disabled = true;
      runButton.textContent = 'Delete selected';
      lastPreviewedDays = null;
    } catch (error) {
      showError(error);
    } finally {
      setBusy(runButton, false);
    }
  });
}

async function render() {
  root.innerHTML = `
    <h1 class="h4 mb-3">Backups</h1>

    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 mb-0">Database backups</h2>
          <button class="btn btn-sm btn-dark" data-create-backup type="button">Create backup</button>
        </div>
        <div data-backups-list></div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h6 mb-1">Restore from backup</h2>
        <p class="text-muted small">Replaces live data with what's in the file. A safety backup of
          the current database is always taken first.</p>
        <form data-restore-form>
          <div class="mb-3">
            <input class="form-control" type="file" name="file" accept=".sql" required>
          </div>
          <button class="btn btn-outline-danger" type="submit">Restore</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h2 class="h6 mb-1">Clean up old deleted data</h2>
        <p class="text-muted small">Permanently removes rows already marked deleted, once they've
          sat that way longer than the retention period. Live data is never touched — only
          things someone already deleted.</p>
        <form class="d-flex gap-2 align-items-end mb-3" data-cleanup-form>
          <div>
            <label class="form-label small mb-1" for="retention_days">Retention (days)</label>
            <input class="form-control form-control-sm" id="retention_days" name="retention_days"
                   type="number" min="0" max="3650" value="90" style="width:8rem" required>
          </div>
          <button class="btn btn-sm btn-outline-secondary" type="submit">Preview</button>
          <button class="btn btn-sm btn-outline-danger" data-cleanup-run type="button">Delete selected</button>
        </form>
        <div data-cleanup-result></div>
      </div>
    </div>`;

  const list = root.querySelector('[data-backups-list]');
  renderBackupsList(list);
  bindCreateForm(list);
  bindRestoreForm();
  bindCleanupForm();
}

const mounted = await mountConsole('backups.html');

if (mounted) {
  root = mounted.root;
  render();
}
