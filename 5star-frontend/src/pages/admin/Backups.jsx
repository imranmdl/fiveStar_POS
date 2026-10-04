import { useEffect, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Backups.css';

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

function BackupsList() {
  const [backups, setBackups] = useState(null);
  const [error, setError] = useState(null);
  const [creating, setCreating] = useState(false);
  const [busyFile, setBusyFile] = useState(null);

  function load() {
    setError(null);
    api
      .get('/admin/backups')
      .then((response) => setBackups(response.data.backups || []))
      .catch(setError);
  }

  useEffect(load, []);

  async function createBackup() {
    setCreating(true);
    try {
      await api.post('/admin/backups');
      toast('Backup created.');
      load();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not create a backup.', 'danger');
    } finally {
      setCreating(false);
    }
  }

  async function download(filename) {
    setBusyFile(filename);
    try {
      await triggerDownload(filename);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not download this backup.', 'danger');
    } finally {
      setBusyFile(null);
    }
  }

  async function remove(filename) {
    if (!window.confirm(`Delete backup "${filename}"? This cannot be undone.`)) return;
    setBusyFile(filename);
    try {
      await api.delete(`/admin/backups/${encodeURIComponent(filename)}`);
      toast('Backup deleted.');
      load();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not delete this backup.', 'danger');
    } finally {
      setBusyFile(null);
    }
  }

  return (
    <div className="pl-card">
      <div className="pl-card__header">
        <span>Database backups</span>
        <button type="button" className="admin-btn admin-btn--primary" onClick={createBackup} disabled={creating}>
          {creating ? 'Creating…' : 'Create backup'}
        </button>
      </div>
      <div style={{ padding: backups && backups.length === 0 ? 0 : undefined }}>
        {error && <ErrorState error={error} />}
        {!backups && !error && <LoadingState />}
        {backups && backups.length === 0 && <EmptyState title="No backups yet" hint='Use "Create backup" above to make the first one.' />}
        {backups && backups.length > 0 && (
          <table className="admin-table">
            <thead>
              <tr>
                <th>File</th>
                <th>Size</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {backups.map((b) => (
                <tr key={b.filename}>
                  <td>{b.filename}</td>
                  <td>{formatBytes(b.bytes)}</td>
                  <td>{b.created_date}</td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button type="button" className="admin-btn" disabled={busyFile === b.filename} onClick={() => download(b.filename)}>
                      Download
                    </button>{' '}
                    <button
                      type="button"
                      className="admin-btn"
                      style={{ color: '#c0392b' }}
                      disabled={busyFile === b.filename}
                      onClick={() => remove(b.filename)}
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function RestoreForm({ onRestored }) {
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    if (!file) {
      toast('Choose a .sql file first.', 'danger');
      return;
    }
    if (
      !window.confirm(
        'This replaces live data with what is in the file you chose. A safety backup of the current database is taken automatically first, but this is still not reversible from here. Continue?'
      )
    ) {
      return;
    }

    setBusy(true);
    const formData = new FormData();
    formData.append('file', file);
    formData.append('confirm', 'yes');

    try {
      const result = await api.upload('/admin/backups/restore', formData);
      toast(`Restore complete. Safety backup: ${result.data.safety_backup.filename}`);
      setFile(null);
      event.currentTarget.reset();
      onRestored();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not restore this backup.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="pl-card">
      <div className="pl-card__header">Restore from backup</div>
      <div style={{ padding: 16 }}>
        <p className="pl-sub" style={{ marginBottom: 12 }}>
          Replaces live data with what's in the file. A safety backup of the current database is always taken first.
        </p>
        <form onSubmit={handleSubmit}>
          <input type="file" accept=".sql" required onChange={(e) => setFile(e.target.files[0] || null)} style={{ marginBottom: 12 }} />
          <div>
            <button type="submit" className="admin-btn" style={{ color: '#c0392b' }} disabled={busy}>
              {busy ? 'Restoring…' : 'Restore'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function CleanupTable({ table, selected, onToggleRow, onToggleTable }) {
  const [open, setOpen] = useState(false);
  const checkedCount = table.rows.filter((row) => selected.has(`${table.table}:${row.id}`)).length;
  const allChecked = checkedCount === table.rows.length && table.rows.length > 0;
  const someChecked = checkedCount > 0 && !allChecked;

  return (
    <div className="backups-cleanup-table">
      <div className="backups-cleanup-table__head">
        <input
          type="checkbox"
          checked={allChecked}
          ref={(el) => el && (el.indeterminate = someChecked)}
          onChange={(e) => onToggleTable(table.table, e.target.checked)}
        />
        <button type="button" className="backups-cleanup-table__toggle" onClick={() => setOpen((o) => !o)}>
          <span style={{ fontWeight: 600 }}>{table.table}</span>
          <span className="pl-sub">
            {' '}
            — {table.count} row(s){table.count > table.rows.length ? ` (showing oldest ${table.rows.length} of ${table.count})` : ''}
          </span>
        </button>
      </div>
      {open && (
        <table className="admin-table">
          <thead>
            <tr>
              <th></th>
              <th>ID</th>
              <th>{table.label_column || 'Label'}</th>
              <th>Deleted on</th>
            </tr>
          </thead>
          <tbody>
            {table.rows.map((row) => (
              <tr key={row.id}>
                <td>
                  <input
                    type="checkbox"
                    checked={selected.has(`${table.table}:${row.id}`)}
                    onChange={(e) => onToggleRow(table.table, row.id, e.target.checked)}
                  />
                </td>
                <td>{row.id}</td>
                <td>{row.label !== null && row.label !== '' ? row.label : '—'}</td>
                <td>{String(row.deleted_date || '').slice(0, 16).replace('T', ' ')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function CleanupForm() {
  const [days, setDays] = useState(90);
  const [preview, setPreview] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  async function handlePreview(event) {
    event.preventDefault();
    setBusy(true);
    setResult(null);
    try {
      const response = await api.get('/admin/data-cleanup/preview', { retention_days: days });
      setPreview(response.data);
      setSelected(new Set());
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not preview this cleanup.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  function toggleRow(table, id, checked) {
    setSelected((prev) => {
      const next = new Set(prev);
      const key = `${table}:${id}`;
      if (checked) next.add(key);
      else next.delete(key);
      return next;
    });
  }

  function toggleTable(table, checked) {
    const tableInfo = preview.tables.find((t) => t.table === table);
    setSelected((prev) => {
      const next = new Set(prev);
      tableInfo.rows.forEach((row) => {
        const key = `${table}:${row.id}`;
        if (checked) next.add(key);
        else next.delete(key);
      });
      return next;
    });
  }

  function selectAll(checked) {
    if (!preview) return;
    const next = new Set();
    if (checked) {
      preview.tables.forEach((t) => t.rows.forEach((row) => next.add(`${t.table}:${row.id}`)));
    }
    setSelected(next);
  }

  async function runCleanup() {
    if (selected.size === 0) return;
    if (
      !window.confirm(
        `Permanently delete the ${selected.size} checked row(s) above, already-deleted for ${days}+ days? Anything left unchecked will be kept. This cannot be undone.`
      )
    ) {
      return;
    }

    const selections = {};
    selected.forEach((key) => {
      const [table, id] = key.split(':');
      (selections[table] ||= []).push(Number(id));
    });

    setBusy(true);
    try {
      const response = await api.post('/admin/data-cleanup/run', { retention_days: days, confirm: 'yes', selections });
      const { total_deleted, tables } = response.data;
      const removed = tables.filter((t) => t.deleted > 0);
      const blocked = tables.filter((t) => t.skipped > 0 || (t.error && t.deleted === 0));
      toast(`Deleted ${total_deleted} row(s).`, blocked.length > 0 ? 'warning' : 'success');
      setResult({ removed, blocked });
      setPreview(null);
      setSelected(new Set());
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not run this cleanup.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="pl-card">
      <div className="pl-card__header">Clean up old deleted data</div>
      <div style={{ padding: 16 }}>
        <p className="pl-sub" style={{ marginBottom: 12 }}>
          Permanently removes rows already marked deleted, once they've sat that way longer than the retention period. Live data is never touched —
          only things someone already deleted.
        </p>
        <form onSubmit={handlePreview} className="backups-cleanup-form">
          <div>
            <label className="inv-field-label">Retention (days)</label>
            <input type="number" min="0" max="3650" value={days} onChange={(e) => setDays(Number(e.target.value))} style={{ width: '8rem' }} required />
          </div>
          <button type="submit" className="admin-btn" disabled={busy}>
            {busy ? 'Checking…' : 'Preview'}
          </button>
          <button type="button" className="admin-btn" style={{ color: '#c0392b' }} disabled={busy || selected.size === 0} onClick={runCleanup}>
            {selected.size > 0 ? `Delete selected (${selected.size})` : 'Delete selected'}
          </button>
        </form>

        {result && (
          <>
            {result.removed.length > 0 && (
              <div className="admin-alert" style={{ background: 'var(--success-bg)', color: 'var(--success)', marginBottom: 8 }}>
                <div style={{ fontWeight: 600, marginBottom: 4 }}>Removed:</div>
                <ul style={{ margin: 0, paddingLeft: 18 }}>
                  {result.removed.map((t) => (
                    <li key={t.table}>
                      {t.table}: {t.deleted}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {result.blocked.length > 0 && (
              <div className="admin-alert admin-alert--danger">
                <div style={{ fontWeight: 600, marginBottom: 4 }}>Still referenced elsewhere — left alone rather than risk breaking that other record:</div>
                <ul style={{ margin: 0, paddingLeft: 18 }}>
                  {result.blocked.map((t) => (
                    <li key={t.table}>
                      {t.table}: {t.skipped ?? '?'} row(s){t.error ? ` — ${t.error}` : ''}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}

        {preview && preview.total === 0 && (
          <div className="admin-alert">Nothing older than {days} days is sitting in the trash — no rows would be removed.</div>
        )}

        {preview && preview.total > 0 && (
          <>
            <div className="admin-alert admin-alert--warning">
              {preview.total} row(s) across {preview.tables.length} table(s) are eligible — every one is listed below, unchecked by default. Nothing is
              deleted unless you tick it, then use "Delete selected".
            </div>
            <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
              <button type="button" className="admin-btn" onClick={() => selectAll(true)}>
                Select all
              </button>
              <button type="button" className="admin-btn" onClick={() => selectAll(false)}>
                Deselect all
              </button>
            </div>
            {preview.tables.map((table) => (
              <CleanupTable key={table.table} table={table} selected={selected} onToggleRow={toggleRow} onToggleTable={toggleTable} />
            ))}
          </>
        )}
      </div>
    </div>
  );
}

export default function Backups() {
  const [listKey, setListKey] = useState(0);

  return (
    <div className="page">
      <h1 className="admin-page-title">Backups</h1>
      <BackupsList key={listKey} />
      <RestoreForm onRestored={() => setListKey((k) => k + 1)} />
      <CleanupForm />
    </div>
  );
}
