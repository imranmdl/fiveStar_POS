import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { Tag, fmtDateTime, canManageStock, reportError } from './InventoryShared';

const TEMPLATE_HEADERS = [
  'sku', 'barcode', 'category', 'product_name', 'variant_name', 'pack_type',
  'weight_grams', 'stock_unit_type', 'unit_label', 'mrp', 'selling_price',
  'warehouse_code', 'quantity', 'reorder_threshold', 'purchase_cost', 'batch_no', 'expiry_date',
];
const TEMPLATE_EXAMPLE = [
  'SP-TURM-250', '', 'spices', 'Turmeric Powder', '250 g', 'pouch',
  '250', 'weight', '', '60', '45',
  'MAIN', '50', '10', '30', '', '2027-01-01',
];

function downloadImportTemplate() {
  const csv = `${TEMPLATE_HEADERS.join(',')}\n${TEMPLATE_EXAMPLE.join(',')}\n`;
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'inventory-import-template.csv';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function ImportHistory() {
  const [state, setState] = useState({ loading: true, error: null, rows: [] });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/imports', { per_page: 10 });
      setState({ loading: false, error: null, rows: response.data || [] });
    } catch (error) {
      setState({ loading: false, error, rows: [] });
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState error={state.error} />;
  if (state.rows.length === 0) {
    return <EmptyState title="No imports yet" hint="A confirmed import appears here with its created/updated/skipped counts." />;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead>
          <tr>
            <th>When</th><th>File</th><th>Default warehouse</th><th style={{ textAlign: 'right' }}>Rows</th>
            <th style={{ textAlign: 'right' }}>Created</th><th style={{ textAlign: 'right' }}>Updated</th>
            <th style={{ textAlign: 'right' }}>Skipped</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          {state.rows.map((batch, i) => (
            <tr key={i}>
              <td className="small-muted">{fmtDateTime(batch.created_date)}</td>
              <td className="small-muted">{batch.file_name}</td>
              <td className="small-muted">{batch.warehouse_name || '—'}</td>
              <td style={{ textAlign: 'right' }}>{batch.total_rows}</td>
              <td style={{ textAlign: 'right' }}>{batch.created_count}</td>
              <td style={{ textAlign: 'right' }}>{batch.updated_count}</td>
              <td style={{ textAlign: 'right' }}>{batch.skipped_count}</td>
              <td><Tag tone={batch.status === 'completed' ? 'success' : batch.status === 'failed' ? 'danger' : 'secondary'}>{batch.status}</Tag></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ImportReviewRow({ row }) {
  const n = row.normalized || {};
  const errors = Object.values(row.errors || {}).flat();
  const actionTag = !row.is_valid
    ? <Tag tone="danger">Skip</Tag>
    : row.action === 'create' ? <Tag tone="info">New</Tag> : <Tag tone="secondary">Update</Tag>;

  return (
    <tr style={row.is_valid ? undefined : { background: 'rgba(192,57,43,0.06)' }}>
      <td className="small-muted">{row.row_number}</td>
      <td style={{ fontWeight: 600 }}>{n.sku || (row.raw && row.raw.sku) || ''}</td>
      <td>{actionTag}</td>
      <td className="small-muted">
        {n.product_name || ''}
        {n.variant_name && <div>{n.variant_name}</div>}
      </td>
      <td style={{ textAlign: 'right' }}>{n.quantity !== undefined ? n.quantity : '—'}</td>
      <td className="small-muted">
        {errors.length ? errors.map((e, i) => <div key={i} style={{ color: '#c0392b' }}>✖ {e}</div>) : <span style={{ color: '#2e7d32' }}>OK</span>}
      </td>
    </tr>
  );
}

/**
 * Bulk CSV/XLSX import — preview, confirm. Ported from renderImportTab().
 * Preview writes nothing (open to every inventory-view role); Confirm
 * creates/updates products and posts real stock movements, so it is
 * $manager-gated same as the manual adjust/quick-create endpoints — not
 * shown to inventory_staff/executive here.
 */
export default function InventoryImport({ role, warehouses }) {
  const [warehouseUuid, setWarehouseUuid] = useState('');
  const [preview, setPreview] = useState(null);
  const [previewing, setPreviewing] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [feedback, setFeedback] = useState(null);
  const [historyKey, setHistoryKey] = useState(0);
  const fileRef = useRef(null);

  async function handlePreview() {
    const file = fileRef.current && fileRef.current.files[0];
    if (!file) {
      setFeedback({ tone: 'danger', text: 'Choose a file first.' });
      return;
    }

    setPreviewing(true);
    setFeedback(null);
    setPreview(null);

    try {
      const formData = new FormData();
      formData.append('file', file);
      if (warehouseUuid) formData.append('warehouse_uuid', warehouseUuid);
      const response = await api.upload('/admin/imports/preview', formData);
      setPreview(response.data);
    } catch (error) {
      setFeedback({ tone: 'danger', text: reportError(error) });
    } finally {
      setPreviewing(false);
    }
  }

  async function handleConfirm() {
    setConfirming(true);
    try {
      const response = await api.post('/admin/imports/confirm', {
        token: preview.token,
        file_type: preview.file_type,
        file_name: preview.file_name,
        warehouse_uuid: warehouseUuid || undefined,
      });
      const batch = response.data;
      setFeedback({
        tone: 'success',
        text: `Import complete — ${batch.created_count} created, ${batch.updated_count} updated${batch.skipped_count ? `, ${batch.skipped_count} skipped` : ''}.`,
      });
      toast('Import complete.');
      setPreview(null);
      setHistoryKey((k) => k + 1);
    } catch (error) {
      setFeedback({ tone: 'danger', text: reportError(error) });
    } finally {
      setConfirming(false);
    }
  }

  function handleCancelPreview() {
    setPreview(null);
    setFeedback(null);
  }

  return (
    <div>
      <div className="inv-card">
        <h3 className="inv-modal__h3">Import products &amp; stock from a CSV or Excel file</h3>
        <p className="small-muted">
          One row per pack size. An existing <b>SKU</b> updates that pack size — give it a Quantity to add stock, or
          leave everything but the SKU blank to change nothing. An unrecognised SKU creates a new draft product
          (Category, Product name, Variant name, Weight, MRP and Selling price are then required).
          You review every row before anything is saved.
        </p>

        <div className="inv-filter-row">
          <button type="button" className="admin-btn" onClick={downloadImportTemplate}>Download template</button>
          <select className="inv-select" value={warehouseUuid} onChange={(e) => setWarehouseUuid(e.target.value)}>
            <option value="">No default warehouse</option>
            {warehouses.map((w) => <option key={w.uuid} value={w.uuid}>{w.name}</option>)}
          </select>
          <span className="small-muted">used for rows with no Warehouse code column, or that column left blank</span>
        </div>

        <div className="inv-filter-row">
          <input ref={fileRef} type="file" accept=".csv,.xlsx" />
          <button type="button" className="admin-btn" disabled={previewing} onClick={handlePreview}>
            {previewing ? 'Reading…' : 'Preview'}
          </button>
        </div>

        {feedback && (
          <p className="small-muted" style={{ color: feedback.tone === 'danger' ? '#c0392b' : feedback.tone === 'success' ? '#2e7d32' : undefined }}>
            {feedback.text}
          </p>
        )}

        {preview && (
          preview.rows && preview.rows.length ? (
            <div style={{ marginTop: 12 }}>
              <div className="small-muted" style={{ marginBottom: 8 }}>
                <b>{preview.summary.valid}</b> of {preview.summary.total} row(s) ready
                ({preview.summary.to_create} new, {preview.summary.to_update} update to an existing SKU)
                {preview.summary.invalid ? <span style={{ color: '#c0392b' }}> · <b>{preview.summary.invalid}</b> need fixing</span> : null}
              </div>
              <div style={{ overflowX: 'auto', maxHeight: '28rem' }}>
                <table className="admin-table">
                  <thead><tr><th>Row</th><th>SKU</th><th>Action</th><th>Item</th><th style={{ textAlign: 'right' }}>Qty</th><th>Notes</th></tr></thead>
                  <tbody>{preview.rows.map((row, i) => <ImportReviewRow key={i} row={row} />)}</tbody>
                </table>
              </div>
              <div className="inv-filter-row">
                {canManageStock(role) ? (
                  <button type="button" className="admin-btn admin-btn--primary" disabled={confirming || !preview.summary.valid} onClick={handleConfirm}>
                    {confirming ? 'Importing…' : `Confirm import (${preview.summary.valid} row(s))`}
                  </button>
                ) : (
                  <span className="small-muted">Your role can preview an import but not confirm it — ask a manager or administrator to confirm.</span>
                )}
                <button type="button" className="admin-btn" disabled={confirming} onClick={handleCancelPreview}>Cancel</button>
                <span className="small-muted">Nothing is saved until you confirm. Fix the file and re-upload to correct a row.</span>
              </div>
            </div>
          ) : <div className="small-muted">No rows found in that file.</div>
        )}
      </div>

      <h3 className="inv-modal__h3">Recent imports</h3>
      <div className="inv-card" key={historyKey}>
        <ImportHistory />
      </div>
    </div>
  );
}
