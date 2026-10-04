import { useEffect, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatCard, StatusBadge } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import InvoiceDetail from './InvoiceDetail';
import './Invoices.css';

function money(value) {
  return formatMoney(Number(value || 0));
}

function statusLabel(status) {
  return { unpaid: 'Unpaid', partial: 'Partially Paid', paid: 'Paid' }[status] || status;
}

function CustomerCell({ row }) {
  const digits = String(row.customer_mobile || '').replace(/\D/g, '').slice(-10);
  return (
    <div>
      <div>{row.customer_name}</div>
      {digits.length === 10 ? (
        <a href={`https://wa.me/91${digits}`} target="_blank" rel="noopener noreferrer" className="inv-sub-link">
          {row.customer_mobile}
        </a>
      ) : (
        <span className="pl-sub">{row.customer_mobile || '—'}</span>
      )}
    </div>
  );
}

const EMPTY_FILTERS = { search: '', payment_status: '', payment_method: '', from: '', to: '', amount_min: '', amount_max: '' };

export default function Invoices() {
  const [summary, setSummary] = useState(null);
  const [summaryError, setSummaryError] = useState(null);
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [appliedFilters, setAppliedFilters] = useState(EMPTY_FILTERS);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState({});
  const [listError, setListError] = useState(null);
  const [openUuid, setOpenUuid] = useState(null);
  const [exporting, setExporting] = useState(false);

  function loadSummary() {
    setSummaryError(null);
    api.get('/admin/invoices/summary').then((response) => setSummary(response.data)).catch(setSummaryError);
  }

  useEffect(loadSummary, []);

  function loadList() {
    setListError(null);
    const query = {};
    Object.entries(appliedFilters).forEach(([key, value]) => {
      if (value !== '') query[key] = value;
    });
    api
      .get('/admin/invoices', { ...query, page, per_page: 25, sort: 'created_date', direction: 'DESC' })
      .then((response) => {
        setRows(response.data || []);
        setMeta(response.meta || {});
      })
      .catch(setListError);
  }

  useEffect(loadList, [appliedFilters, page]);

  function applyAlert(patch) {
    setFilters({ ...EMPTY_FILTERS, ...patch });
    setAppliedFilters({ ...EMPTY_FILTERS, ...patch });
    setPage(1);
  }

  function applyFilters() {
    setAppliedFilters(filters);
    setPage(1);
  }

  function clearFilters() {
    setFilters(EMPTY_FILTERS);
    setAppliedFilters(EMPTY_FILTERS);
    setPage(1);
  }

  async function exportCsv() {
    setExporting(true);
    try {
      const query = {};
      Object.entries(appliedFilters).forEach(([key, value]) => {
        if (value !== '') query[key] = value;
      });
      const qs = new URLSearchParams(query).toString();
      const blob = await api.downloadFile(`/admin/invoices/export${qs ? `?${qs}` : ''}`);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `invoices_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not export invoices.', 'danger');
    } finally {
      setExporting(false);
    }
  }

  const totalPages = meta.total_pages || 1;

  return (
    <div className="page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <div>
          <h1 className="admin-page-title" style={{ margin: 0 }}>
            Invoice Tracking
          </h1>
          <p className="pl-sub" style={{ margin: 0 }}>
            Till invoices, partial-payment balances and WhatsApp follow-ups.
          </p>
        </div>
        <button type="button" className="admin-btn admin-btn--primary" onClick={exportCsv} disabled={exporting}>
          {exporting ? 'Exporting…' : 'Export CSV'}
        </button>
      </div>

      {summaryError && <ErrorState error={summaryError} />}
      {!summary && !summaryError && <LoadingState />}
      {summary && (
        <>
          <div className="stat-grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))' }}>
            <StatCard label="Total Invoices" value={summary.total} />
            <StatCard label="Paid" value={summary.paid} />
            <StatCard label="Partially Paid" value={summary.partial} />
            <StatCard label="Unpaid" value={summary.unpaid} tone="danger" />
            <StatCard label="Overdue" value={summary.overdue} tone="danger" />
            <StatCard label="Refunded" value={summary.refunded} />
            <StatCard label="Cancelled" value={summary.cancelled} />
            <StatCard label="Today's Revenue" value={money(summary.todays_revenue)} />
          </div>

          <div className="inv-alerts">
            <button type="button" className="admin-btn" onClick={() => applyAlert({ overdue_only: true })}>
              🔴 {summary.overdue} overdue
            </button>
            <button type="button" className="admin-btn" onClick={() => applyAlert({ payment_status: 'partial' })}>
              🟡 {summary.partial} partially paid
            </button>
            <button type="button" className="admin-btn" onClick={() => applyAlert({ payment_status: 'unpaid' })}>
              🟠 {summary.unpaid} unpaid
            </button>
            <button type="button" className="admin-btn" onClick={() => applyAlert({ payment_status: 'paid' })}>
              🟢 {summary.paid} paid
            </button>
          </div>
        </>
      )}

      <div className="pl-card" style={{ padding: 16 }}>
        <div className="inv-filter-grid">
          <div>
            <label className="inv-field-label">Search</label>
            <input
              type="search"
              placeholder="Invoice #, name or phone"
              value={filters.search}
              onChange={(e) => setFilters({ ...filters, search: e.target.value })}
            />
          </div>
          <div>
            <label className="inv-field-label">Status</label>
            <select value={filters.payment_status} onChange={(e) => setFilters({ ...filters, payment_status: e.target.value })}>
              <option value="">All</option>
              <option value="paid">Paid</option>
              <option value="partial">Partially Paid</option>
              <option value="unpaid">Unpaid</option>
            </select>
          </div>
          <div>
            <label className="inv-field-label">Payment method</label>
            <select value={filters.payment_method} onChange={(e) => setFilters({ ...filters, payment_method: e.target.value })}>
              <option value="">All</option>
              <option value="cash">Cash</option>
              <option value="upi">UPI</option>
              <option value="card">Card</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label className="inv-field-label">From</label>
            <input type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
          </div>
          <div>
            <label className="inv-field-label">To</label>
            <input type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
          </div>
          <div>
            <label className="inv-field-label">Min amount</label>
            <input type="number" min="0" value={filters.amount_min} onChange={(e) => setFilters({ ...filters, amount_min: e.target.value })} />
          </div>
          <div>
            <label className="inv-field-label">Max amount</label>
            <input type="number" min="0" value={filters.amount_max} onChange={(e) => setFilters({ ...filters, amount_max: e.target.value })} />
          </div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
            <button type="button" className="admin-btn admin-btn--primary" onClick={applyFilters}>
              Filter
            </button>
            <button type="button" className="admin-btn" onClick={clearFilters}>
              Clear
            </button>
          </div>
        </div>
      </div>

      <div className="pl-card">
        {listError && <ErrorState error={listError} />}
        {!rows && !listError && <LoadingState />}
        {rows && rows.length === 0 && <EmptyState title="No invoices match these filters" hint="Try widening the date range or clearing a filter." />}
        {rows && rows.length > 0 && (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Date</th>
                <th style={{ textAlign: 'right' }}>Total</th>
                <th style={{ textAlign: 'right' }}>Paid</th>
                <th style={{ textAlign: 'right' }}>Remaining</th>
                <th style={{ textAlign: 'right' }}>Discount</th>
                <th>Method</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const remaining = Number(row.balance_due || 0);
                return (
                  <tr key={row.uuid}>
                    <td>
                      <button type="button" className="pl-link-btn" onClick={() => setOpenUuid(row.uuid)}>
                        {row.sale_number}
                      </button>
                    </td>
                    <td>
                      <CustomerCell row={row} />
                    </td>
                    <td>{(row.created_date || '').slice(0, 16)}</td>
                    <td style={{ textAlign: 'right' }}>{money(row.grand_total)}</td>
                    <td style={{ textAlign: 'right' }}>{money(row.amount_paid)}</td>
                    <td style={{ textAlign: 'right', color: remaining > 0 ? '#c0392b' : undefined, fontWeight: remaining > 0 ? 600 : undefined }}>
                      {money(remaining)}
                    </td>
                    <td style={{ textAlign: 'right' }}>{money(row.discount_amount)}</td>
                    <td style={{ textTransform: 'uppercase' }}>{row.payment_method}</td>
                    <td>
                      <StatusBadge status={row.payment_status} label={statusLabel(row.payment_status)} />
                    </td>
                    <td>
                      <button type="button" className="admin-btn" onClick={() => setOpenUuid(row.uuid)}>
                        View
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
        <div className="inv-pager">
          {totalPages > 1 ? (
            <>
              <button type="button" className="admin-btn" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </button>
              <span className="pl-sub">
                Page {meta.page} of {totalPages} &middot; {meta.total} invoice(s)
              </span>
              <button type="button" className="admin-btn" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
                Next
              </button>
            </>
          ) : (
            <span className="pl-sub">{meta.total || 0} invoice(s)</span>
          )}
        </div>
      </div>

      {openUuid && (
        <InvoiceDetail
          uuid={openUuid}
          onClose={() => setOpenUuid(null)}
          onChanged={() => {
            loadSummary();
            loadList();
          }}
        />
      )}
    </div>
  );
}
