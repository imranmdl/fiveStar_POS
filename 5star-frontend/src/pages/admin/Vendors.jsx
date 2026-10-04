import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState, StatCard, StatusBadge } from '../../components/admin/shared';
import './Vendors.css';

/**
 * Vendors — who stock is purchased from. List/?new/?edit= shape like
 * Warehouses, plus a dashboard strip, a CSV import/export panel, and a
 * ?history= view tying together everything a vendor relationship touches:
 * purchases, payments, returns, and the pending balance that falls out of
 * them.
 *
 * Ported from admin/assets/page-vendors.js. Links the source points at
 * purchase-inward.html (a separate admin screen not yet ported into this
 * app) are rendered as plain text here rather than dead links — see the
 * handback notes for details.
 */
export default function Vendors() {
  const [searchParams, setSearchParams] = useSearchParams();
  const editUuid = searchParams.get('edit');
  const historyUuid = searchParams.get('history');
  const isNew = searchParams.has('new');

  const goList = () => setSearchParams({});
  const goEdit = (uuid) => setSearchParams({ edit: uuid });
  const goNew = () => setSearchParams({ new: '' });
  const goHistory = (uuid) => setSearchParams({ history: uuid });

  if (historyUuid) {
    return <VendorHistory uuid={historyUuid} onBack={goList} onEdit={goEdit} />;
  }
  if (editUuid || isNew) {
    return <VendorForm editUuid={editUuid} onDone={goList} />;
  }
  return <VendorList onEdit={goEdit} onNew={goNew} onHistory={goHistory} />;
}

function errorToast(error, fallback) {
  const detail = error && error.fieldMessages ? error.fieldMessages() : [];
  toast([(error && error.message) || fallback, ...detail].filter(Boolean).join(' '), 'danger');
}

async function downloadCsv(path, fallbackName) {
  const blob = await api.downloadFile(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

const IE_ENTITIES = [
  { value: 'vendors', label: 'Vendors', importPath: '/admin/import/vendors' },
  { value: 'purchase_orders', label: 'Purchases' },
  { value: 'transactions', label: 'Transactions' },
];

/**
 * One panel, one entity picker — Download template / Export CSV always
 * available, Import CSV only shown for an entity that actually has an
 * import endpoint (vendors, today), matching the source's reasoning.
 */
function ImportExportPanel({ onImported }) {
  const [entity, setEntity] = useState(IE_ENTITIES[0].value);
  const [busyTemplate, setBusyTemplate] = useState(false);
  const [busyExport, setBusyExport] = useState(false);

  const current = IE_ENTITIES.find((e) => e.value === entity);

  async function handleTemplate() {
    setBusyTemplate(true);
    try {
      await downloadCsv(`/admin/export/${encodeURIComponent(entity)}/template`, `${entity}_template.csv`);
    } catch (error) {
      errorToast(error, 'Could not download template.');
    } finally {
      setBusyTemplate(false);
    }
  }

  async function handleExport() {
    setBusyExport(true);
    try {
      await downloadCsv(`/admin/export/${encodeURIComponent(entity)}`, `${entity}.csv`);
    } catch (error) {
      errorToast(error, 'Could not export CSV.');
    } finally {
      setBusyExport(false);
    }
  }

  async function handleImportChange(event) {
    const file = event.target.files[0];
    const importPath = current && current.importPath;
    if (!file || !importPath) return;

    const formData = new FormData();
    formData.append('file', file);

    try {
      const result = await api.upload(importPath, formData);
      const { total, created, skipped } = result.data.summary;
      toast(`Imported ${created} of ${total} row(s)${skipped ? `, ${skipped} skipped` : ''}.`);

      const problems = (result.data.rows || []).filter((r) => !r.is_valid);
      if (problems.length) {
        window.alert(problems.map((r) => `Row ${r.row_number}: ${r.error}`).join('\n'));
      }

      if (onImported) onImported();
    } catch (error) {
      errorToast(error, 'Could not import CSV.');
    } finally {
      event.target.value = '';
    }
  }

  return (
    <div className="ie-panel">
      <label className="field field--sm ie-panel__field">
        <span>Data</span>
        <select value={entity} onChange={(event) => setEntity(event.target.value)}>
          {IE_ENTITIES.map((e) => (
            <option key={e.value} value={e.value}>
              {e.label}
            </option>
          ))}
        </select>
      </label>
      <button type="button" className="admin-btn" onClick={handleTemplate} disabled={busyTemplate}>
        {busyTemplate ? 'Preparing…' : 'Download template'}
      </button>
      <button type="button" className="admin-btn" onClick={handleExport} disabled={busyExport}>
        {busyExport ? 'Preparing…' : 'Export CSV'}
      </button>
      {current && current.importPath && (
        <label className="admin-btn ie-import-label">
          Import CSV
          <input type="file" accept=".csv,.xlsx" hidden onChange={handleImportChange} />
        </label>
      )}
    </div>
  );
}

function VendorDashboard({ onHistory, refreshKey }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [data, setData] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .get('/admin/vendors/dashboard')
      .then((response) => {
        if (!cancelled) setData(response.data);
      })
      .catch((err) => {
        if (!cancelled) setError(err);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;
  if (!data) return null;

  return (
    <div className="vendor-dashboard">
      <div className="stat-grid">
        <StatCard label="Total vendors" value={data.total_vendors} />
        <StatCard label="Total purchases" value={data.total_purchases} hint={formatMoney(data.total_purchase_value)} />
        <StatCard
          label="Pending payments"
          value={formatMoney(data.pending_payments)}
          tone={data.pending_payments > 0 ? 'danger' : undefined}
        />
        <StatCard label="Paid amount" value={formatMoney(data.paid_amount)} tone="success" />
      </div>

      <div className="vendor-dashboard__grid">
        <div className="vendor-panel">
          <h2>Top vendors</h2>
          {data.top_vendors.length === 0 ? (
            <p className="small-muted">No purchases yet.</p>
          ) : (
            <table className="admin-table admin-table--plain">
              <tbody>
                {data.top_vendors.map((v) => (
                  <tr key={v.vendor_uuid}>
                    <td>
                      <button type="button" className="admin-link" onClick={() => onHistory(v.vendor_uuid)}>
                        {v.vendor_name}
                      </button>
                    </td>
                    <td style={{ textAlign: 'right' }}>{formatMoney(v.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="vendor-panel">
          <h2>Recent purchases</h2>
          {data.recent_purchases.length === 0 ? (
            <p className="small-muted">No purchases yet.</p>
          ) : (
            <table className="admin-table admin-table--plain">
              <tbody>
                {data.recent_purchases.slice(0, 6).map((p) => (
                  <tr key={p.uuid}>
                    <td>
                      {/* Purchase Inward isn't ported into this app yet — plain text, not a dead link. */}
                      <div>{p.po_number}</div>
                      <div className="small-muted">{p.vendor_name}</div>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {formatMoney(p.grand_total)}
                      <div>
                        <StatusBadge status={p.payment_status} />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {data.purchase_returns && data.purchase_returns.count > 0 && (
        <p className="small-muted">
          {data.purchase_returns.count} purchase return(s) recorded, worth {formatMoney(data.purchase_returns.value)}.
        </p>
      )}
    </div>
  );
}

function VendorList({ onEdit, onNew, onHistory }) {
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [vendors, setVendors] = useState([]);
  const [refreshKey, setRefreshKey] = useState(0);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/vendors', { search, per_page: 50 });
      setVendors(response.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => {
    load();
  }, [load, refreshKey]);

  function handleSearchSubmit(event) {
    event.preventDefault();
    setSearch(searchInput);
  }

  async function handleDeactivate(uuid) {
    if (!window.confirm('Deactivate this vendor? Past purchase history is kept.')) return;
    try {
      await api.delete(`/admin/vendors/${encodeURIComponent(uuid)}`);
      toast('Vendor deactivated.');
      load();
    } catch (error) {
      errorToast(error, 'Could not deactivate vendor.');
    }
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title">Vendors</h1>
        <div className="admin-toolbar" style={{ marginBottom: 0 }}>
          <button type="button" className="admin-btn admin-btn--primary" onClick={onNew}>
            Add a vendor
          </button>
          <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: 8 }}>
            <input
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Search vendors"
              style={{ width: '14rem', padding: '6px 10px', borderRadius: 6, border: '1px solid var(--border)' }}
            />
            <button type="submit" className="admin-btn">
              Search
            </button>
          </form>
        </div>
      </div>

      <VendorDashboard onHistory={onHistory} refreshKey={refreshKey} />

      <ImportExportPanel onImported={() => setRefreshKey((k) => k + 1)} />

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} />
      ) : vendors.length === 0 ? (
        <EmptyState
          title={search ? 'Nothing matched' : 'No vendors yet'}
          hint={search ? 'Try a different term.' : 'Use "Add a vendor" to record your first supplier.'}
        />
      ) : (
        <table className="admin-table">
          <thead>
            <tr>
              <th>Vendor</th>
              <th>Contact</th>
              <th>Reach</th>
              <th>Location</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {vendors.map((v) => (
              <tr key={v.uuid}>
                <td>
                  <button type="button" className="admin-link" onClick={() => onHistory(v.uuid)}>
                    {v.name}
                  </button>
                  <div className="small-muted">
                    {v.vendor_code || '—'}
                    {v.gstin ? ` · ${v.gstin}` : ''}
                  </div>
                </td>
                <td>{v.contact_person || '—'}</td>
                <td>{v.phone || v.email || '—'}</td>
                <td>{[v.city, v.state].filter(Boolean).join(', ') || '—'}</td>
                <td>
                  {v.is_active ? (
                    <span className="status-badge status-badge--success">Active</span>
                  ) : (
                    <span className="status-badge status-badge--secondary">Inactive</span>
                  )}
                </td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button type="button" className="admin-btn" onClick={() => onHistory(v.uuid)}>
                    History
                  </button>
                  <button type="button" className="admin-btn" onClick={() => onEdit(v.uuid)}>
                    Edit
                  </button>
                  {v.is_active && (
                    <button type="button" className="admin-btn" onClick={() => handleDeactivate(v.uuid)}>
                      Deactivate
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

const BLANK_VENDOR = {
  name: '',
  company_name: '',
  contact_person: '',
  phone: '',
  email: '',
  address_line1: '',
  address_line2: '',
  city: '',
  state: '',
  pincode: '',
  gstin: '',
  pan: '',
  bank_account_name: '',
  bank_name: '',
  bank_account_number: '',
  bank_ifsc: '',
  payment_terms: '',
  notes: '',
};

function VendorForm({ editUuid, onDone }) {
  const [loading, setLoading] = useState(Boolean(editUuid));
  const [error, setError] = useState(null);
  const [vendor, setVendor] = useState(null);
  const [form, setForm] = useState(BLANK_VENDOR);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!editUuid) return;
    let cancelled = false;
    setLoading(true);

    api
      .get(`/admin/vendors/${encodeURIComponent(editUuid)}`)
      .then((response) => {
        if (cancelled) return;
        const v = response.data;
        setVendor(v);
        const next = { ...BLANK_VENDOR };
        Object.keys(BLANK_VENDOR).forEach((key) => {
          next[key] = v[key] || '';
        });
        setForm(next);
      })
      .catch((err) => {
        if (!cancelled) setError(err);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [editUuid]);

  function set(field) {
    return (event) => setForm((f) => ({ ...f, [field]: event.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setSaving(true);

    try {
      if (vendor) {
        await api.patch(`/admin/vendors/${encodeURIComponent(vendor.uuid)}`, form);
      } else {
        await api.post('/admin/vendors', form);
      }
      toast('Vendor saved.');
      onDone();
    } catch (error) {
      setSaving(false);
      errorToast(error, 'Could not save vendor.');
    }
  }

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;

  return (
    <div>
      <button type="button" className="admin-link-back" onClick={onDone}>
        ← Vendors
      </button>
      <h1 className="admin-page-title">{vendor ? 'Edit vendor' : 'Add a vendor'}</h1>

      <form className="vendor-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <label className="field field--half">
            <span>Name</span>
            <input required maxLength={150} value={form.name} onChange={set('name')} />
          </label>
          <label className="field field--half">
            <span>Company name</span>
            <input maxLength={160} value={form.company_name} onChange={set('company_name')} />
          </label>
          <label className="field field--half">
            <span>Contact person</span>
            <input maxLength={120} value={form.contact_person} onChange={set('contact_person')} />
          </label>
          <label className="field field--sm">
            <span>Phone</span>
            <input maxLength={15} value={form.phone} onChange={set('phone')} />
          </label>
          <label className="field field--sm">
            <span>Email</span>
            <input type="email" maxLength={150} value={form.email} onChange={set('email')} />
          </label>
          <label className="field field--half">
            <span>Address line 1</span>
            <input value={form.address_line1} onChange={set('address_line1')} />
          </label>
          <label className="field field--half">
            <span>Address line 2</span>
            <input value={form.address_line2} onChange={set('address_line2')} />
          </label>
          <label className="field field--sm">
            <span>City</span>
            <input value={form.city} onChange={set('city')} />
          </label>
          <label className="field field--sm">
            <span>State</span>
            <input value={form.state} onChange={set('state')} />
          </label>
          <label className="field field--sm">
            <span>Pincode</span>
            <input value={form.pincode} onChange={set('pincode')} />
          </label>

          <div className="form-section">Tax</div>
          <label className="field field--half">
            <span>GSTIN</span>
            <input maxLength={20} value={form.gstin} onChange={set('gstin')} />
          </label>
          <label className="field field--half">
            <span>PAN</span>
            <input maxLength={10} value={form.pan} onChange={set('pan')} />
          </label>

          <div className="form-section">Bank details</div>
          <label className="field field--half">
            <span>Account holder name</span>
            <input maxLength={160} value={form.bank_account_name} onChange={set('bank_account_name')} />
          </label>
          <label className="field field--half">
            <span>Bank name</span>
            <input maxLength={120} value={form.bank_name} onChange={set('bank_name')} />
          </label>
          <label className="field field--half">
            <span>Account number</span>
            <input maxLength={30} value={form.bank_account_number} onChange={set('bank_account_number')} />
          </label>
          <label className="field field--half">
            <span>IFSC</span>
            <input maxLength={11} value={form.bank_ifsc} onChange={set('bank_ifsc')} />
          </label>

          <div className="form-section"></div>
          <label className="field field--sm">
            <span>Payment terms</span>
            <input maxLength={120} placeholder="e.g. Net 30" value={form.payment_terms} onChange={set('payment_terms')} />
          </label>
          <label className="field field--lg">
            <span>Notes</span>
            <textarea rows={1} maxLength={500} value={form.notes} onChange={set('notes')} />
          </label>
        </div>

        <div className="form-actions">
          <button type="submit" className="admin-btn admin-btn--primary" disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </button>
          <button type="button" className="admin-btn" onClick={onDone}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
}

/** Purchases → Payments → Returns → pending balance, all in one place. */
function VendorHistory({ uuid, onBack, onEdit }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [data, setData] = useState(null);
  const [tab, setTab] = useState('purchases');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .get(`/admin/vendors/${encodeURIComponent(uuid)}/history`)
      .then((response) => {
        if (!cancelled) setData(response.data);
      })
      .catch((err) => {
        if (!cancelled) setError(err);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [uuid]);

  if (loading) return <LoadingState />;
  if (error) {
    return (
      <div>
        <button type="button" className="admin-link-back" onClick={onBack}>
          ← Vendors
        </button>
        <ErrorState error={error} />
      </div>
    );
  }
  if (!data) return null;

  const { vendor, purchases = [], payments = [], returns = [], summary = {} } = data;

  return (
    <div>
      <button type="button" className="admin-link-back" onClick={onBack}>
        ← Vendors
      </button>

      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <div>
          <h1 className="admin-page-title" style={{ marginBottom: 0 }}>
            {vendor.name}
          </h1>
          <p className="small-muted">
            {vendor.vendor_code || ''}
            {vendor.company_name ? ` · ${vendor.company_name}` : ''}
          </p>
        </div>
        <button type="button" className="admin-btn" onClick={() => onEdit(vendor.uuid)}>
          Edit vendor
        </button>
      </div>

      <div className="stat-grid">
        <StatCard label="Purchases" value={summary.purchase_count} hint={formatMoney(summary.total_purchased)} />
        <StatCard label="Paid" value={formatMoney(summary.total_paid)} tone="success" />
        <StatCard label="Returned" value={formatMoney(summary.total_returned)} />
        <StatCard
          label="Pending balance"
          value={formatMoney(summary.pending_balance)}
          tone={summary.pending_balance > 0 ? 'danger' : undefined}
        />
      </div>

      <div className="vendor-tabs">
        <button type="button" className={tab === 'purchases' ? 'active' : ''} onClick={() => setTab('purchases')}>
          Purchases
        </button>
        <button type="button" className={tab === 'payments' ? 'active' : ''} onClick={() => setTab('payments')}>
          Payments
        </button>
        <button type="button" className={tab === 'returns' ? 'active' : ''} onClick={() => setTab('returns')}>
          Returns
        </button>
      </div>

      {tab === 'purchases' &&
        (purchases.length === 0 ? (
          <EmptyState title="No purchases yet" />
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>PO</th>
                <th>Date</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Returned</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {purchases.map((p) => (
                <tr key={p.uuid}>
                  <td>{p.po_number}</td>
                  <td>{p.purchase_date}</td>
                  <td>{formatMoney(p.grand_total)}</td>
                  <td>{formatMoney(p.amount_paid)}</td>
                  <td>{formatMoney(p.amount_returned)}</td>
                  <td>
                    <StatusBadge status={p.payment_status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ))}

      {tab === 'payments' &&
        (payments.length === 0 ? (
          <EmptyState title="No payments yet" />
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>PO</th>
                <th>Date</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((p, index) => (
                <tr key={p.uuid || index}>
                  <td>{p.po_number}</td>
                  <td>{p.payment_date}</td>
                  <td style={{ textTransform: 'uppercase' }}>{p.payment_method}</td>
                  <td>{p.reference_number || '—'}</td>
                  <td>{formatMoney(p.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ))}

      {tab === 'returns' &&
        (returns.length === 0 ? (
          <EmptyState title="No returns yet" />
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Return</th>
                <th>PO</th>
                <th>Date</th>
                <th>Reason</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              {returns.map((r, index) => (
                <tr key={r.uuid || index}>
                  <td>{r.return_number}</td>
                  <td>{r.po_number}</td>
                  <td>{r.return_date}</td>
                  <td>{r.reason}</td>
                  <td>{formatMoney(r.total_amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ))}
    </div>
  );
}
