/**
 * Wholesale and gift enquiries.
 *
 * Corporate gifting is the reason this exists: a company ordering two
 * hundred Diwali boxes does not use a shopping cart, they ask for a price.
 * The flow is enquiry -> quotation -> accepted -> an ordinary order.
 *
 * Ported from admin/assets/page-bulk.js. The live page used two HTML files
 * (bulk.html list, bulk.html?uuid=... detail); here both live under one
 * route and the `uuid` query param picks list vs. detail, same idea.
 */
import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { StatusBadge, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Bulk.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const FILTERS = [['', 'All'], ['new', 'New'], ['quoted', 'Quoted'], ['converted', 'Became orders']];

function BulkList({ onOpen }) {
  const [filter, setFilter] = useState('');
  const [enquiries, setEnquiries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(async (status) => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/bulk-orders', { status, per_page: 50 });
      setEnquiries(response.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(filter); }, [filter, load]);

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title">Wholesale &amp; gifting</h1>
        <div className="bulk-filter-group">
          {FILTERS.map(([value, label]) => (
            <button key={value} type="button"
                    className={`admin-btn${filter === value ? ' admin-btn--primary' : ''}`}
                    onClick={() => setFilter(value)}>{label}</button>
          ))}
        </div>
      </div>

      <div className="bulk-card">
        {loading ? <LoadingState /> : error ? <ErrorState error={error} /> : enquiries.length === 0 ? (
          <EmptyState title="No enquiries" hint="Businesses can send one from the shop without creating an account." />
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr><th>Business</th><th>Wants</th><th>Contact</th><th>Status</th><th>Budget</th><th></th></tr>
              </thead>
              <tbody>
                {enquiries.map((enquiry) => (
                  <tr key={enquiry.uuid}>
                    <td>
                      <div style={{ fontWeight: 600 }}>{enquiry.business_name}</div>
                      <div className="bulk-note">{enquiry.enquiry_number}</div>
                    </td>
                    <td className="bulk-wants">{String(enquiry.requirements || '').slice(0, 110)}</td>
                    <td>
                      {enquiry.contact_name}
                      <div className="bulk-note">{enquiry.contact_mobile}</div>
                    </td>
                    <td>
                      <StatusBadge status={enquiry.status === 'converted' ? 'delivered' : 'open'}
                                   label={String(enquiry.status).replace(/_/g, ' ')} />
                    </td>
                    <td>{enquiry.estimated_budget ? formatMoney(enquiry.estimated_budget) : '—'}</td>
                    <td>
                      <button className="admin-btn" onClick={() => onOpen(enquiry.uuid)}>Open</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

function BulkDetail({ uuid, onBack }) {
  const [enquiry, setEnquiry] = useState(null);
  const [quotes, setQuotes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sendingUuid, setSendingUuid] = useState(null);
  const [declineReason, setDeclineReason] = useState('');
  const [declining, setDeclining] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get(`/admin/bulk-orders/${encodeURIComponent(uuid)}`);
      setEnquiry(response.data.enquiry);
      setQuotes(response.data.quotes || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [uuid]);

  useEffect(() => { load(); }, [load]);

  async function sendQuote(quoteUuid) {
    setSendingUuid(quoteUuid);
    try {
      await api.post(`/admin/bulk-orders/quotes/${encodeURIComponent(quoteUuid)}/send`, {});
      toast('Quotation sent.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setSendingUuid(null);
    }
  }

  async function handleDecline(event) {
    event.preventDefault();
    setDeclining(true);
    try {
      await api.post(`/admin/bulk-orders/${encodeURIComponent(uuid)}/decline`, { reason: declineReason });
      toast('Enquiry declined.');
      setDeclineReason('');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setDeclining(false);
    }
  }

  if (loading) return (<div><button className="admin-btn" onClick={onBack}>← All enquiries</button><LoadingState /></div>);
  if (error) return (<div><button className="admin-btn" onClick={onBack}>← All enquiries</button><ErrorState error={error} /></div>);
  if (!enquiry) return null;

  return (
    <div>
      <button className="admin-btn" onClick={onBack}>← All enquiries</button>
      <h1 className="admin-page-title" style={{ marginTop: 12, marginBottom: 2 }}>{enquiry.business_name}</h1>
      <p className="bulk-note">
        {enquiry.enquiry_number} · {enquiry.contact_name} · {enquiry.contact_mobile}
        {enquiry.gstin ? ` · GSTIN ${enquiry.gstin}` : ''}
      </p>

      <div className="bulk-detail-grid">
        <div className="bulk-detail-main">
          <div className="bulk-card">
            <div className="bulk-card__header">What they asked for</div>
            <div className="bulk-card__body">
              <p style={{ marginBottom: 10 }}>{enquiry.requirements}</p>
              <dl className="bulk-dl">
                {enquiry.estimated_quantity && (<><dt>Quantity</dt><dd>{enquiry.estimated_quantity}</dd></>)}
                {enquiry.estimated_budget && (<><dt>Budget</dt><dd>{formatMoney(enquiry.estimated_budget)}</dd></>)}
                {enquiry.expected_delivery_date && (<><dt>Needed by</dt><dd>{enquiry.expected_delivery_date}</dd></>)}
                {enquiry.delivery_pincode && (<><dt>Delivering to</dt><dd>{enquiry.delivery_pincode}</dd></>)}
              </dl>
            </div>
          </div>

          <div className="bulk-card">
            <div className="bulk-card__header">Quotations</div>
            {quotes.length === 0 ? (
              <div className="bulk-card__body">
                <EmptyState title="No quotation yet" hint="Prepare one through the API — the console form is not built." />
              </div>
            ) : (
              <ul className="bulk-quote-list">
                {quotes.map((quote) => (
                  <li key={quote.uuid} className="bulk-quote-item">
                    <div className="bulk-quote-item__top">
                      <div>
                        <span style={{ fontWeight: 600 }}>{quote.quote_number}</span>
                        <span className="bulk-note" style={{ marginLeft: 8 }}>revision {quote.revision}</span>{' '}
                        <StatusBadge status={quote.status === 'accepted' ? 'delivered' : 'open'} label={quote.status} />
                      </div>
                      <span style={{ fontWeight: 600 }}>{formatMoney(quote.grand_total)}</span>
                    </div>
                    <div className="bulk-note" style={{ marginTop: 4 }}>
                      {(quote.items || []).length} line(s) · valid until {quote.valid_until}
                      {quote.is_expired ? <span style={{ color: '#c0392b' }}> · expired</span> : ''}
                    </div>
                    {quote.status === 'draft' && (
                      <button className="admin-btn admin-btn--primary" style={{ marginTop: 8 }}
                              disabled={sendingUuid === quote.uuid}
                              onClick={() => sendQuote(quote.uuid)}>
                        {sendingUuid === quote.uuid ? 'Sending…' : 'Send to the customer'}
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        <div className="bulk-detail-side">
          <div className="bulk-card">
            <div className="bulk-card__body">
              <h2 style={{ fontSize: 15, margin: '0 0 8px' }}>Decline</h2>
              <form onSubmit={handleDecline}>
                <textarea rows={3} required minLength={3} placeholder="Why? The customer is told."
                          value={declineReason} onChange={(e) => setDeclineReason(e.target.value)} />
                <button className="admin-btn" style={{ width: '100%', marginTop: 8, color: '#c0392b' }}
                        type="submit" disabled={declining}>
                  {declining ? 'Declining…' : 'Decline this enquiry'}
                </button>
              </form>
            </div>
          </div>

          <p className="bulk-note" style={{ marginTop: 12 }}>
            An accepted quotation becomes an ordinary order — same OTP, same prepaid UPI, same
            courier selection. Wholesale gets no shortcuts, which matters most here because the
            amounts are largest.
          </p>
        </div>
      </div>
    </div>
  );
}

export default function Bulk() {
  const [searchParams, setSearchParams] = useSearchParams();
  const uuid = searchParams.get('uuid');

  if (uuid) {
    return (
      <BulkDetail
        uuid={uuid}
        onBack={() => {
          const next = new URLSearchParams(searchParams);
          next.delete('uuid');
          setSearchParams(next);
        }}
      />
    );
  }

  return (
    <BulkList
      onOpen={(nextUuid) => {
        const next = new URLSearchParams(searchParams);
        next.set('uuid', nextUuid);
        setSearchParams(next);
      }}
    />
  );
}
