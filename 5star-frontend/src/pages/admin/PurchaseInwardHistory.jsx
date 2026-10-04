import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared.jsx';
import PurchaseInwardDetail from './PurchaseInwardDetail.jsx';

function paymentTone(status) {
  return status === 'paid' ? 'success' : status === 'partial' ? 'warning' : 'secondary';
}

/**
 * "History" tab — ported from admin/assets/page-purchase-inward.js
 * (renderHistoryTab). A vendor-filterable, paginated list of past purchase
 * orders; opening one hands off to PurchaseInwardDetail for payment/return
 * follow-up.
 */
export default function PurchaseInwardHistory({ vendors }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const uuid = searchParams.get('uuid');

  function openPo(poUuid) {
    const params = new URLSearchParams(searchParams);
    params.set('uuid', poUuid);
    setSearchParams(params);
  }

  function back() {
    const params = new URLSearchParams(searchParams);
    params.delete('uuid');
    setSearchParams(params);
  }

  if (uuid) {
    return <PurchaseInwardDetail uuid={uuid} onBack={back} />;
  }

  return <PurchaseInwardList vendors={vendors} onOpen={openPo} />;
}

function PurchaseInwardList({ vendors, onOpen }) {
  const [vendorFilter, setVendorFilter] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/purchase-orders', {
        vendor_uuid: vendorFilter,
        page,
        per_page: 30,
        direction: 'DESC',
      });
      setRows(response.data || []);
      setMeta(response.meta || null);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [vendorFilter, page]);

  useEffect(() => { load(); }, [load]);

  function submitFilter(event) {
    event.preventDefault();
    setPage(1);
    // vendorFilter is already current via onChange; load() reacts via useCallback deps.
  }

  return (
    <div>
      <form className="pi-toolbar" onSubmit={submitFilter}>
        <select value={vendorFilter} onChange={(e) => { setVendorFilter(e.target.value); setPage(1); }}>
          <option value="">All vendors</option>
          {vendors.map((v) => <option key={v.uuid} value={v.uuid}>{v.name}</option>)}
        </select>
        <button type="submit" className="admin-btn">Filter</button>
      </form>

      <div className="pi-card">
        {loading ? (
          <LoadingState />
        ) : error ? (
          <ErrorState error={error} />
        ) : rows.length === 0 ? (
          <EmptyState title="No purchases recorded yet" hint="Use the Record inward tab to log your first purchase." />
        ) : (
          <>
            <table className="admin-table">
              <thead>
                <tr><th>PO</th><th>Vendor</th><th>Warehouse</th><th>Payment</th><th className="pi-right">Total</th></tr>
              </thead>
              <tbody>
                {rows.map((po) => (
                  <tr key={po.uuid}>
                    <td>
                      <button type="button" className="pi-link-btn pi-fw-semibold" onClick={() => onOpen(po.uuid)}>{po.po_number}</button>
                      <div className="pi-small pi-muted">{String(po.purchase_date || '').slice(0, 10)}</div>
                    </td>
                    <td className="pi-small">{po.vendor_name}</td>
                    <td className="pi-small">{po.warehouse_name}</td>
                    <td><span className={`status-badge status-badge--${paymentTone(po.payment_status)}`}>{po.payment_status}</span></td>
                    <td className="pi-right">{formatMoney(po.grand_total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {meta && meta.total_pages > 1 && (
              <div className="pi-row-between pi-pagination">
                <span className="pi-small pi-muted">Page {meta.page} of {meta.total_pages}</span>
                <span>
                  <button type="button" className="admin-btn" disabled={meta.page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>{' '}
                  <button type="button" className="admin-btn" disabled={meta.page >= meta.total_pages} onClick={() => setPage((p) => p + 1)}>Next</button>
                </span>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
