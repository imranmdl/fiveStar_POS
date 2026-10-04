import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatCard, StatusBadge } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './CustomerDues.css';

/**
 * Customer Dues: every POS sale a registered customer was knowingly left
 * owing money on (PosSaleService's "accept partial payment" path), how much
 * of it is still outstanding, and recording a payment against it.
 *
 * Ported from admin/assets/page-dues.js. Every figure here is a thin UI over
 * PosDuePaymentService (the overpayment guard, the duplicate-reference
 * guard, recomputing payment_status) — this page has no business logic of
 * its own.
 */

const METHOD_LABEL = { cash: 'Cash', upi: 'UPI', card: 'Card', other: 'Other' };

function money(value) {
  return formatMoney(Number(value || 0));
}

/** Reuses the shared StatusBadge's existing tone keys so this page needs no new colours. */
function DueStatusBadge({ status }) {
  if (status === 'paid') return <StatusBadge status="paid" label="Fully Paid" />;
  if (status === 'partial') return <StatusBadge status="pending" label="Partially Paid" />;
  return <StatusBadge status="failed" label="Unpaid" />;
}

function DashboardTiles({ dues }) {
  return (
    <div className="stat-grid">
      <StatCard
        label="Total due"
        value={<span style={{ color: Number(dues.total_due) > 0 ? '#b7791f' : undefined }}>{money(dues.total_due)}</span>}
      />
      <StatCard label="Partially paid" value={dues.partially_paid_count ?? 0} />
      <StatCard label="Fully paid" value={<span style={{ color: '#1e8a5b' }}>{dues.fully_paid_count ?? 0}</span>} />
      <StatCard
        label={`Overdue (${dues.overdue_after_days ?? '—'}+ days)`}
        value={<span style={{ color: Number(dues.overdue_count) > 0 ? '#c0392b' : undefined }}>{dues.overdue_count ?? 0}</span>}
        hint={money(dues.overdue_amount)}
      />
      <StatCard label="Collected today" value={<span style={{ color: '#1e8a5b' }}>{money(dues.collected_today)}</span>} />
    </div>
  );
}

/** Amount / method / reference / notes form for POST .../payments — same self-contained-modal shape as InvoiceDetail.jsx. */
function RecordPaymentModal({ invoice, onClose, onSaved }) {
  const [amount, setAmount] = useState(invoice.balance_due);
  const [method, setMethod] = useState('cash');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    const amt = Number(amount);

    if (!(amt > 0) || amt > Number(invoice.balance_due) + 0.005) {
      setError('Enter an amount greater than zero and no more than the balance due.');
      return;
    }

    setBusy(true);
    setError('');
    const body = { amount: amt, payment_method: method };
    if (reference) body.reference_number = reference;
    if (notes) body.notes = notes;

    try {
      await api.post(`/admin/pos/sales/${encodeURIComponent(invoice.uuid)}/payments`, body);
      toast('Payment recorded.');
      onSaved();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not record this payment.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="cd-modal-backdrop" onClick={onClose}>
      <div className="cd-modal" onClick={(e) => e.stopPropagation()}>
        <form onSubmit={handleSubmit}>
          <div className="cd-modal__header">
            <h2>Record a payment — {invoice.sale_number}</h2>
            <button type="button" onClick={onClose} aria-label="Cancel">&times;</button>
          </div>
          <div className="cd-modal__body">
            <p className="cd-sub">
              Balance due: <strong>{money(invoice.balance_due)}</strong> of {money(invoice.grand_total)}
            </p>
            <div className="cd-field">
              <label className="cd-field-label">Amount</label>
              <input
                type="number"
                step="0.01"
                min="0.01"
                max={invoice.balance_due}
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                required
              />
            </div>
            <div className="cd-field">
              <label className="cd-field-label">Payment method</label>
              <select value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div className="cd-field">
              <label className="cd-field-label">Reference number (optional)</label>
              <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="UPI/card transaction ID" />
            </div>
            <div className="cd-field">
              <label className="cd-field-label">Notes (optional)</label>
              <input value={notes} maxLength={255} onChange={(e) => setNotes(e.target.value)} />
            </div>
            {error && <div className="cd-error">{error}</div>}
          </div>
          <div className="cd-modal__footer">
            <button type="button" className="admin-btn" onClick={onClose}>Cancel</button>
            <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>
              {busy ? 'Saving…' : 'Record payment'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * Payment details behind clicking a customer name or sale number — the full
 * payment history plus item lines and bill summary. Reuses
 * /admin/invoices/{uuid}, the same read endpoint the Invoice Tracking Center
 * uses, so this is a second view onto data already fetched elsewhere.
 */
function PaymentDetailsModal({ uuid, onClose }) {
  const [sale, setSale] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    setSale(null);
    setError(null);
    api
      .get(`/admin/invoices/${encodeURIComponent(uuid)}`)
      .then((res) => setSale(res.data))
      .catch(setError);
  }, [uuid]);

  const remaining = sale ? Number(sale.grand_total) - Number(sale.amount_paid) : 0;

  return (
    <div className="cd-modal-backdrop" onClick={onClose}>
      <div className="cd-modal cd-modal--lg" onClick={(e) => e.stopPropagation()}>
        <div className="cd-modal__header">
          <h2>Payment details{sale ? ` — ${sale.sale_number}` : ''}</h2>
          <button type="button" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="cd-modal__body">
          {error && <ErrorState error={error} />}
          {!sale && !error && <LoadingState />}
          {sale && (
            <>
              <div className="cd-details-top">
                <div>
                  <div className="cd-strong">{sale.customer_name || 'Walk-in customer'}</div>
                  <div className="cd-sub">{sale.customer_mobile || 'No phone on file'}</div>
                  <div className="cd-sub">
                    Rung up {String(sale.created_date || '').slice(0, 16).replace('T', ' ')} by {sale.cashier_name || '—'}
                  </div>
                </div>
                <DueStatusBadge status={sale.payment_status} />
              </div>

              <div className="cd-summary-row">
                <div>
                  <div className="cd-sub">Total</div>
                  <div className="cd-strong">{money(sale.grand_total)}</div>
                </div>
                <div>
                  <div className="cd-sub">Paid</div>
                  <div className="cd-strong" style={{ color: '#1e8a5b' }}>{money(sale.amount_paid)}</div>
                </div>
                <div>
                  <div className="cd-sub">Remaining</div>
                  <div className="cd-strong" style={{ color: remaining > 0 ? '#c0392b' : undefined }}>{money(Math.max(0, remaining))}</div>
                </div>
              </div>

              <div className="cd-card">
                <div className="cd-card__header">Payment history</div>
                {sale.due_payments && sale.due_payments.length > 0 ? (
                  <table className="admin-table">
                    <thead>
                      <tr>
                        <th>Date &amp; time</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Notes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sale.due_payments.map((p, i) => (
                        <tr key={i}>
                          <td>{String(p.created_date || p.payment_date || '').replace('T', ' ').slice(0, 16)}</td>
                          <td>{money(p.amount)}</td>
                          <td style={{ textTransform: 'uppercase' }}>{METHOD_LABEL[p.payment_method] || p.payment_method}</td>
                          <td>{p.notes || ''}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="cd-empty-note">No separate payment entries — settled in full at the till.</div>
                )}
              </div>

              <div className="cd-card">
                <div className="cd-card__header">Items</div>
                <div className="cd-table-wrap">
                  <table className="admin-table">
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(sale.items || []).map((i, idx) => (
                        <tr key={idx}>
                          <td>{i.product_name} <span className="cd-sub">({i.variant_name})</span></td>
                          <td>{i.quantity}</td>
                          <td>{money(i.unit_price)}</td>
                          <td>{money(i.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function InvoiceRow({ invoice, showCustomer, onOpenDetails, onRecordPayment }) {
  return (
    <tr>
      {showCustomer && (
        <td>
          <button type="button" className="cd-link-btn" onClick={() => onOpenDetails(invoice.uuid)}>{invoice.customer_name}</button>
          <div className="cd-sub">{invoice.customer_mobile}</div>
        </td>
      )}
      <td>
        <button type="button" className="cd-link-btn" onClick={() => onOpenDetails(invoice.uuid)}>{invoice.sale_number}</button>
        <div className="cd-sub">{String(invoice.created_date || '').slice(0, 16).replace('T', ' ')}</div>
      </td>
      <td style={{ textAlign: 'right' }}>{money(invoice.grand_total)}</td>
      <td style={{ textAlign: 'right' }}>{money(invoice.amount_paid)}</td>
      <td style={{ textAlign: 'right', fontWeight: 600, color: Number(invoice.balance_due) > 0 ? '#b7791f' : undefined }}>
        {money(invoice.balance_due)}
      </td>
      <td>
        <DueStatusBadge status={invoice.payment_status} />
        {invoice.is_overdue && <StatusBadge status="failed" label="Overdue" />}
      </td>
      <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
        {Number(invoice.balance_due) > 0.005 ? (
          <button type="button" className="admin-btn admin-btn--primary" onClick={() => onRecordPayment(invoice)}>Record payment</button>
        ) : (
          <span className="cd-sub">Settled</span>
        )}
      </td>
    </tr>
  );
}

function AllDuesView() {
  const [searchParams, setSearchParams] = useSearchParams();
  const filter = searchParams.get('status') || 'all';
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState({});
  const [error, setError] = useState(null);
  const [detailsUuid, setDetailsUuid] = useState(null);
  const [payingInvoice, setPayingInvoice] = useState(null);

  function setFilter(value) {
    setSearchParams(value === 'all' ? {} : { status: value });
    setPage(1);
  }

  function load() {
    setError(null);
    const params = { page, per_page: 25 };
    if (filter === 'overdue') params.overdue_only = 1;
    else if (filter !== 'all') params.payment_status = filter;

    api
      .get('/admin/pos/dues', params)
      .then((res) => {
        setRows(res.data || []);
        setMeta(res.meta || {});
      })
      .catch(setError);
  }

  useEffect(() => {
    setRows(null);
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filter, page]);

  return (
    <>
      <div className="cd-filter-group">
        {[['all', 'All'], ['partial', 'Partially Paid'], ['unpaid', 'Unpaid'], ['overdue', 'Overdue']].map(([value, label]) => (
          <button
            key={value}
            type="button"
            className={filter === value ? 'admin-btn admin-btn--primary' : 'admin-btn'}
            onClick={() => setFilter(value)}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="cd-card">
        {error && <ErrorState error={error} />}
        {!rows && !error && <LoadingState />}
        {rows && rows.length === 0 && <EmptyState title="Nothing here" hint="No credit sale matches this filter." />}
        {rows && rows.length > 0 && (
          <>
            <div className="cd-table-wrap">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Customer</th>
                    <th>Sale</th>
                    <th style={{ textAlign: 'right' }}>Bill</th>
                    <th style={{ textAlign: 'right' }}>Paid</th>
                    <th style={{ textAlign: 'right' }}>Balance due</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((invoice) => (
                    <InvoiceRow
                      key={invoice.uuid}
                      invoice={invoice}
                      showCustomer
                      onOpenDetails={setDetailsUuid}
                      onRecordPayment={setPayingInvoice}
                    />
                  ))}
                </tbody>
              </table>
            </div>
            {meta.total_pages > 1 && (
              <div className="cd-pager">
                <span className="cd-sub">Page {meta.page} of {meta.total_pages} &middot; {meta.total} invoice(s)</span>
                <span className="cd-pager__buttons">
                  <button type="button" className="admin-btn" disabled={meta.page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
                  <button type="button" className="admin-btn" disabled={meta.page >= meta.total_pages} onClick={() => setPage((p) => p + 1)}>Next</button>
                </span>
              </div>
            )}
          </>
        )}
      </div>

      {detailsUuid && <PaymentDetailsModal uuid={detailsUuid} onClose={() => setDetailsUuid(null)} />}
      {payingInvoice && <RecordPaymentModal invoice={payingInvoice} onClose={() => setPayingInvoice(null)} onSaved={load} />}
    </>
  );
}

function CustomerView({ customer, onChangeCustomer }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [detailsUuid, setDetailsUuid] = useState(null);
  const [payingInvoice, setPayingInvoice] = useState(null);

  function load() {
    setError(null);
    api
      .get(`/admin/customers/${encodeURIComponent(customer.uuid)}/dues`)
      .then((res) => setData(res.data))
      .catch(setError);
  }

  useEffect(() => {
    setData(null);
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customer.uuid]);

  if (error) {
    return (
      <>
        <div className="cd-toolbar">
          <button type="button" className="admin-btn" onClick={onChangeCustomer}>&larr; Browse all customers instead</button>
        </div>
        <ErrorState error={error} />
      </>
    );
  }

  if (!data) return <LoadingState />;

  const invoices = data.invoices || [];

  return (
    <>
      <div className="cd-toolbar">
        <div>
          <h2 className="cd-customer-title">{customer.full_name}</h2>
          <div className="cd-sub">{customer.mobile}</div>
        </div>
        <button type="button" className="admin-btn" onClick={onChangeCustomer}>Browse all customers instead</button>
      </div>

      <div className="cd-card cd-total-card">
        <div className="cd-sub">Total outstanding across {invoices.length} invoice(s)</div>
        <div className="cd-total-outstanding" style={{ color: Number(data.total_outstanding) > 0 ? '#b7791f' : '#1e8a5b' }}>
          {money(data.total_outstanding)}
        </div>
      </div>

      <div className="cd-card">
        {invoices.length === 0 ? (
          <EmptyState title="No credit sales" hint="This customer has no sale that was ever left partly or fully due." />
        ) : (
          <div className="cd-table-wrap">
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Sale</th>
                  <th style={{ textAlign: 'right' }}>Bill</th>
                  <th style={{ textAlign: 'right' }}>Paid</th>
                  <th style={{ textAlign: 'right' }}>Balance due</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {invoices.map((invoice) => (
                  <InvoiceRow
                    key={invoice.uuid}
                    invoice={invoice}
                    showCustomer={false}
                    onOpenDetails={setDetailsUuid}
                    onRecordPayment={setPayingInvoice}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {detailsUuid && <PaymentDetailsModal uuid={detailsUuid} onClose={() => setDetailsUuid(null)} />}
      {payingInvoice && <RecordPaymentModal invoice={payingInvoice} onClose={() => setPayingInvoice(null)} onSaved={load} />}
    </>
  );
}

export default function CustomerDues() {
  const [dashboard, setDashboard] = useState(null); // null = loading, 'error' = failed, object = loaded
  const [customer, setCustomer] = useState(null);
  const [mobile, setMobile] = useState('');
  const [searchError, setSearchError] = useState('');
  const [searching, setSearching] = useState(false);

  useEffect(() => {
    api
      .get('/admin/dashboard')
      .then((res) => setDashboard(res.data.customer_dues || {}))
      .catch(() => setDashboard('error'));
  }, []);

  async function runSearch() {
    if (!mobile) return;
    setSearchError('');
    setSearching(true);

    try {
      const res = await api.get('/admin/pos/customers', { mobile });
      setCustomer({ uuid: res.data.uuid, full_name: res.data.full_name, mobile: res.data.mobile });
    } catch (err) {
      setSearchError(err instanceof ApiError ? err.message : 'That customer could not be found.');
    } finally {
      setSearching(false);
    }
  }

  return (
    <div className="page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Customer Dues</h1>
      </div>

      {dashboard === null && <LoadingState />}
      {dashboard && dashboard !== 'error' && <DashboardTiles dues={dashboard} />}

      {!customer && (
        <div className="cd-card cd-search-card">
          <label className="cd-field-label">Look up one customer's total outstanding balance</label>
          <div className="cd-search-row">
            <input
              value={mobile}
              onChange={(e) => setMobile(e.target.value.replace(/\D/g, '').slice(0, 10))}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } }}
              placeholder="Mobile number"
              inputMode="numeric"
              maxLength={10}
              autoComplete="off"
            />
            <button type="button" className="admin-btn admin-btn--primary" onClick={runSearch} disabled={searching}>
              {searching ? 'Searching…' : 'Find'}
            </button>
          </div>
          {searchError && <div className="cd-error">{searchError}</div>}
        </div>
      )}

      {customer ? (
        <CustomerView customer={customer} onChangeCustomer={() => setCustomer(null)} />
      ) : (
        <AllDuesView />
      )}
    </div>
  );
}
