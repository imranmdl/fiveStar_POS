import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import './Warehouses.css';

/**
 * Warehouses — locations inventory can be held at and deducted from.
 *
 * Ported from admin/assets/page-warehouses.js. The list and the create/edit
 * form share this page via ?new / ?edit=<uuid>, the same pattern the source
 * uses, rather than separate routes (adminRoutes.jsx is out of scope here).
 */
export default function Warehouses() {
  const [searchParams, setSearchParams] = useSearchParams();
  const editUuid = searchParams.get('edit');
  const isNew = searchParams.has('new');

  const goList = () => setSearchParams({});
  const goEdit = (uuid) => setSearchParams({ edit: uuid });
  const goNew = () => setSearchParams({ new: '' });

  if (editUuid || isNew) {
    return <WarehouseForm editUuid={editUuid} onDone={goList} />;
  }

  return <WarehouseList onEdit={goEdit} onNew={goNew} />;
}

function WarehouseList({ onEdit, onNew }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [warehouses, setWarehouses] = useState([]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/warehouses');
      setWarehouses(response.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function handleDeactivate(uuid) {
    if (!window.confirm('Deactivate this warehouse? Its stock stays on record, but it drops out of pickers.')) {
      return;
    }

    try {
      await api.delete(`/admin/warehouses/${encodeURIComponent(uuid)}`);
      toast('Warehouse deactivated.');
      load();
    } catch (error) {
      toast(error.message || 'Could not deactivate warehouse.', 'danger');
    }
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title">Warehouses</h1>
        <button type="button" className="admin-btn admin-btn--primary" onClick={onNew}>
          Add a warehouse
        </button>
      </div>

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} />
      ) : warehouses.length === 0 ? (
        <EmptyState
          title="No warehouses yet"
          hint="Add one — automatic sale deduction needs a default warehouse to target."
        />
      ) : (
        <table className="admin-table">
          <thead>
            <tr>
              <th>Warehouse</th>
              <th>Location</th>
              <th></th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {warehouses.map((w) => (
              <tr key={w.uuid}>
                <td>
                  <div style={{ fontWeight: 600 }}>{w.name}</div>
                  <div className="small-muted">{w.code}</div>
                </td>
                <td>{[w.city, w.state].filter(Boolean).join(', ') || '—'}</td>
                <td>
                  {w.is_default && <span className="status-badge status-badge--primary">Default</span>}
                </td>
                <td>
                  {w.is_active ? (
                    <span className="status-badge status-badge--success">Active</span>
                  ) : (
                    <span className="status-badge status-badge--secondary">Inactive</span>
                  )}
                </td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button type="button" className="admin-btn" onClick={() => onEdit(w.uuid)}>
                    Edit
                  </button>
                  {!w.is_default && (
                    <button type="button" className="admin-btn" onClick={() => handleDeactivate(w.uuid)}>
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

const BLANK_WAREHOUSE = {
  code: '',
  name: '',
  address_line1: '',
  address_line2: '',
  city: '',
  state: '',
  pincode: '',
  phone: '',
  is_default: false,
};

function WarehouseForm({ editUuid, onDone }) {
  const [loading, setLoading] = useState(Boolean(editUuid));
  const [error, setError] = useState(null);
  const [warehouse, setWarehouse] = useState(null);
  const [form, setForm] = useState(BLANK_WAREHOUSE);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!editUuid) return;
    let cancelled = false;
    setLoading(true);

    api
      .get(`/admin/warehouses/${encodeURIComponent(editUuid)}`)
      .then((response) => {
        if (cancelled) return;
        const w = response.data;
        setWarehouse(w);
        setForm({
          code: w.code || '',
          name: w.name || '',
          address_line1: w.address_line1 || '',
          address_line2: w.address_line2 || '',
          city: w.city || '',
          state: w.state || '',
          pincode: w.pincode || '',
          phone: w.phone || '',
          is_default: Boolean(w.is_default),
        });
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
      if (warehouse) {
        await api.patch(`/admin/warehouses/${encodeURIComponent(warehouse.uuid)}`, form);
      } else {
        await api.post('/admin/warehouses', form);
      }
      toast('Warehouse saved.');
      onDone();
    } catch (error) {
      setSaving(false);
      const detail = error.fieldMessages ? error.fieldMessages() : [];
      toast([error.message || 'Could not save warehouse.', ...detail].filter(Boolean).join(' '), 'danger');
    }
  }

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;

  return (
    <div>
      <button type="button" className="admin-link-back" onClick={onDone}>
        ← Warehouses
      </button>
      <h1 className="admin-page-title">{warehouse ? 'Edit warehouse' : 'Add a warehouse'}</h1>

      <form className="wh-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <label className="field field--sm">
            <span>Code</span>
            <input required maxLength={30} value={form.code} onChange={set('code')} />
          </label>
          <label className="field field--lg">
            <span>Name</span>
            <input required maxLength={120} value={form.name} onChange={set('name')} />
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
          <label className="field field--half">
            <span>Phone</span>
            <input value={form.phone} onChange={set('phone')} />
          </label>
          <label className="field field--half field--checkbox">
            <input
              type="checkbox"
              checked={form.is_default}
              onChange={(event) => setForm((f) => ({ ...f, is_default: event.target.checked }))}
            />
            <span>
              Default warehouse
              <div className="small-muted">Automatic sale deduction always targets this one.</div>
            </span>
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
