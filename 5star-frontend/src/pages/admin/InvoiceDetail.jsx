import { useEffect, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { StatusBadge, LoadingState, ErrorState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';

const TEMPLATES = [
  { code: 'invoice_created', label: 'Invoice Created' },
  { code: 'payment_received', label: 'Payment Received' },
  { code: 'partial_reminder', label: 'Partial Payment Reminder' },
  { code: 'payment_due_reminder', label: 'Payment Due Reminder' },
  { code: 'overdue', label: 'Overdue Payment' },
  { code: 'offer_available', label: 'Offer Available' },
  { code: 'refund_processed', label: 'Refund Processed' },
  { code: 'order_delivered', label: 'Order Delivered' },
  { code: 'payment_completed', label: 'Payment Completed' },
];

function money(value) {
  return formatMoney(Number(value || 0));
}

function statusLabel(status) {
  return { unpaid: 'Unpaid', partial: 'Partially Paid', paid: 'Paid' }[status] || status;
}

/** Opens a wa.me chat with the message pre-filled; a human still presses send inside WhatsApp. */
function WhatsAppModal({ saleUuid, templateCode, onClose, onLogged }) {
  const [message, setMessage] = useState('Loading…');
  const [loading, setLoading] = useState(true);
  const [waLink, setWaLink] = useState(null);
  const [sendingSms, setSendingSms] = useState(false);

  useEffect(() => {
    api
      .get(`/admin/invoices/${saleUuid}/whatsapp-preview`, { template: templateCode })
      .then((response) => {
        setMessage(response.data.message);
        if (response.data.phone && response.data.wa_link) {
          setWaLink(response.data.wa_link.split('?text=')[0]);
        } else {
          toast('This customer has no phone number on file.', 'danger');
        }
        setLoading(false);
      })
      .catch(() => {
        setMessage('');
        setLoading(false);
      });
  }, [saleUuid, templateCode]);

  async function openWhatsApp() {
    if (!waLink) return;
    // Opened synchronously, before any await — a tab opened after an awaited
    // fetch resolves is what most popup blockers silently swallow.
    const tab = window.open('', '_blank');

    try {
      await api.post(`/admin/invoices/${saleUuid}/whatsapp-log`, { template: templateCode, message });
      const url = `${waLink}?text=${encodeURIComponent(message)}`;
      if (tab) tab.location.href = url;
      else window.open(url, '_blank', 'noopener');
      toast('WhatsApp opened and logged.');
      onLogged();
      onClose();
    } catch (error) {
      if (tab) tab.close();
      toast(error instanceof ApiError ? error.message : 'Could not log this message.', 'danger');
    }
  }

  async function sendSms() {
    setSendingSms(true);
    try {
      await api.post(`/admin/invoices/${saleUuid}/sms-send`, { template: templateCode, message });
      toast('SMS sent.');
      onLogged();
      onClose();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not send the SMS.', 'danger');
    } finally {
      setSendingSms(false);
    }
  }

  const label = TEMPLATES.find((t) => t.code === templateCode)?.label || templateCode;

  return (
    <div className="pl-modal-backdrop" onClick={onClose}>
      <div className="pl-modal" style={{ maxWidth: 480 }} onClick={(e) => e.stopPropagation()}>
        <div className="pl-modal__header">
          <h2>WhatsApp — {label}</h2>
          <button type="button" onClick={onClose} aria-label="Close">
            &times;
          </button>
        </div>
        <div className="pl-modal__body">
          <label className="inv-field-label">Message (you can edit before sending)</label>
          <textarea rows={6} value={message} disabled={loading} onChange={(e) => setMessage(e.target.value)} style={{ width: '100%' }} />
          <p className="pl-sub" style={{ marginTop: 8 }}>
            If WhatsApp says this number isn't on WhatsApp, use <strong>Send SMS instead</strong> — it works for any mobile number.
          </p>
          <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
            <button type="button" className="admin-btn" onClick={onClose}>
              Cancel
            </button>
            <button type="button" className="admin-btn" onClick={sendSms} disabled={sendingSms || loading}>
              {sendingSms ? 'Sending…' : 'Send SMS instead'}
            </button>
            <button type="button" className="admin-btn admin-btn--primary" onClick={openWhatsApp} disabled={!waLink || loading}>
              Open WhatsApp &amp; Log
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function CustomerHistoryModal({ customerUuid, onClose }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api
      .get(`/admin/customers/${customerUuid}/invoice-summary`)
      .then((response) => setData(response.data))
      .catch(setError);
  }, [customerUuid]);

  return (
    <div className="pl-modal-backdrop" onClick={onClose}>
      <div className="pl-modal" style={{ maxWidth: 480 }} onClick={(e) => e.stopPropagation()}>
        <div className="pl-modal__header">
          <h2>Customer history</h2>
          <button type="button" onClick={onClose} aria-label="Close">
            &times;
          </button>
        </div>
        <div className="pl-modal__body">
          {error && <ErrorState error={error} />}
          {!data && !error && <LoadingState />}
          {data && (
            <>
              <h3 style={{ marginBottom: 2 }}>{data.customer.full_name}</h3>
              <p className="pl-sub" style={{ marginBottom: 12 }}>
                {data.customer.mobile}
              </p>
              <div className="inv-customer-grid">
                <div>
                  <div className="pl-sub">Invoices</div>
                  <div style={{ fontWeight: 600 }}>{data.invoice_count}</div>
                </div>
                <div>
                  <div className="pl-sub">Total purchases</div>
                  <div style={{ fontWeight: 600 }}>{money(data.total_purchases)}</div>
                </div>
                <div>
                  <div className="pl-sub">Total paid</div>
                  <div style={{ fontWeight: 600, color: '#1e8a5b' }}>{money(data.total_paid)}</div>
                </div>
                <div>
                  <div className="pl-sub">Total due</div>
                  <div style={{ fontWeight: 600, color: Number(data.total_due) > 0 ? '#c0392b' : undefined }}>{money(data.total_due)}</div>
                </div>
                <div>
                  <div className="pl-sub">Partial payments</div>
                  <div style={{ fontWeight: 600 }}>{data.partial_count}</div>
                </div>
                <div>
                  <div className="pl-sub">Wallet balance</div>
                  <div style={{ fontWeight: 600 }}>{money(data.wallet_balance)}</div>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

export default function InvoiceDetail({ uuid, onClose, onChanged }) {
  const [sale, setSale] = useState(null);
  const [error, setError] = useState(null);
  const [whatsapp, setWhatsapp] = useState(null);
  const [customerHistory, setCustomerHistory] = useState(null);

  function load() {
    setError(null);
    api
      .get(`/admin/invoices/${uuid}`)
      .then((response) => setSale(response.data))
      .catch(setError);
  }

  useEffect(load, [uuid]);

  async function refresh() {
    const response = await api.get(`/admin/invoices/${uuid}`);
    setSale(response.data);
    onChanged();
  }

  async function recordPayment() {
    const remaining = (Number(sale.grand_total) - Number(sale.amount_paid)).toFixed(2);
    const amount = window.prompt(`Remaining balance is ${money(remaining)}. Enter the amount received:`, remaining);
    if (amount === null || amount.trim() === '') return;
    const method = window.prompt('Payment method (cash, upi, card, other):', 'cash');
    if (!method) return;

    try {
      await api.post(`/admin/pos/sales/${sale.uuid}/payments`, { amount: Number(amount), payment_method: method.trim().toLowerCase() });
      toast('Payment recorded. Balance updated.');
      refresh();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not record this payment.', 'danger');
    }
  }

  async function refund() {
    const lines = (sale.items || []).filter((i) => Number(i.quantity) - Number(i.refunded_quantity || 0) > 0);
    if (lines.length === 0) {
      toast('Nothing left on this invoice to refund.', 'danger');
      return;
    }
    const summary = lines.map((i, idx) => `${idx + 1}) ${i.product_name} (${i.variant_name}) — up to ${i.quantity} available`).join('\n');
    const choice = window.prompt(`Which item number to refund?\n${summary}`);
    const line = lines[Number(choice) - 1];
    if (!line) return;
    const qty = window.prompt(`Quantity to refund for ${line.product_name}?`, String(line.quantity));
    if (!qty || Number(qty) <= 0) return;
    const reason = window.prompt('Reason for the refund:');
    if (!reason || reason.trim().length < 3) {
      toast('A reason is required.', 'danger');
      return;
    }

    try {
      await api.post(`/admin/pos/sales/${sale.uuid}/refund`, { reason: reason.trim(), items: [{ pos_sale_item_uuid: line.uuid, quantity: Number(qty) }] });
      toast('Refund processed.');
      refresh();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not process this refund.', 'danger');
    }
  }

  async function voidSale() {
    if (!window.confirm(`Cancel invoice ${sale.sale_number}? This cannot be undone.`)) return;
    const reason = window.prompt('Reason for cancelling this invoice:');
    if (!reason || reason.trim().length < 3) {
      toast('A reason is required.', 'danger');
      return;
    }

    try {
      await api.post(`/admin/pos/sales/${sale.uuid}/void`, { reason: reason.trim() });
      toast('Invoice cancelled.');
      onChanged();
      onClose();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not cancel this invoice.', 'danger');
    }
  }

  if (error) {
    return (
      <div className="inv-drawer-backdrop" onClick={onClose}>
        <div className="inv-drawer" onClick={(e) => e.stopPropagation()}>
          <ErrorState error={error} />
        </div>
      </div>
    );
  }

  if (!sale) {
    return (
      <div className="inv-drawer-backdrop" onClick={onClose}>
        <div className="inv-drawer" onClick={(e) => e.stopPropagation()}>
          <LoadingState />
        </div>
      </div>
    );
  }

  const remaining = Number(sale.grand_total) - Number(sale.amount_paid);
  const cs = sale.customer_summary;

  return (
    <div className="inv-drawer-backdrop" onClick={onClose}>
      <div className="inv-drawer" onClick={(e) => e.stopPropagation()}>
        <div className="inv-drawer__header">
          <h2>{sale.sale_number}</h2>
          <button type="button" onClick={onClose} aria-label="Close">
            &times;
          </button>
        </div>

        <div className="inv-drawer__body">
          <div className="inv-drawer__top">
            <div>
              <div style={{ fontWeight: 600 }}>{sale.customer_name}</div>
              <div className="pl-sub">{sale.customer_mobile || 'No phone on file'}</div>
              {sale.customer_uuid && (
                <button type="button" className="pl-link-btn" onClick={() => setCustomerHistory(sale.customer_uuid)}>
                  View customer history
                </button>
              )}
            </div>
            <StatusBadge status={sale.payment_status} label={statusLabel(sale.payment_status)} />
          </div>

          <div className="pl-card">
            <div className="pl-card__header">Payment</div>
            <div style={{ padding: 16 }}>
              <div className="inv-payment-grid">
                <div>
                  <div className="pl-sub">Total</div>
                  <div style={{ fontWeight: 600 }}>{money(sale.grand_total)}</div>
                </div>
                <div>
                  <div className="pl-sub">Paid</div>
                  <div style={{ fontWeight: 600, color: '#1e8a5b' }}>{money(sale.amount_paid)}</div>
                </div>
                <div>
                  <div className="pl-sub">Remaining</div>
                  <div style={{ fontWeight: 600, color: remaining > 0 ? '#c0392b' : undefined }}>{money(Math.max(0, remaining))}</div>
                </div>
              </div>
              <p className="pl-sub" style={{ marginTop: 8 }}>
                Payment method: {String(sale.payment_method || '').toUpperCase()} · Discount: {money(sale.discount_amount)}
              </p>
              {sale.due_payments && sale.due_payments.length > 0 ? (
                <table className="admin-table" style={{ marginTop: 8 }}>
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th style={{ textAlign: 'right' }}>Amount</th>
                      <th>Method</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sale.due_payments.map((p, i) => (
                      <tr key={i}>
                        <td>{p.payment_date}</td>
                        <td style={{ textAlign: 'right' }}>{money(p.amount)}</td>
                        <td style={{ textTransform: 'uppercase' }}>{p.payment_method}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <p className="pl-sub">No due-payment history.</p>
              )}
            </div>
          </div>

          <div className="pl-card">
            <div className="pl-card__header">Products</div>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Qty</th>
                  <th>Price</th>
                  <th>Discount</th>
                  <th>GST</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                {(sale.items || []).map((i, idx) => (
                  <tr key={idx}>
                    <td>
                      {i.product_name} <span className="pl-sub">({i.variant_name})</span>
                    </td>
                    <td>{i.quantity}</td>
                    <td>{money(i.unit_price)}</td>
                    <td>{money(i.discount_amount)}</td>
                    <td>{money(i.tax_amount)}</td>
                    <td>{money(i.line_total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {cs && (
            <div className="pl-card">
              <div className="pl-card__header">Customer at a glance</div>
              <div className="inv-customer-glance">
                <div>
                  Invoices
                  <br />
                  <b>{cs.invoice_count}</b>
                </div>
                <div>
                  Total due
                  <br />
                  <b style={{ color: Number(cs.total_due) > 0 ? '#c0392b' : undefined }}>{money(cs.total_due)}</b>
                </div>
                <div>
                  Wallet
                  <br />
                  <b>{money(cs.wallet_balance)}</b>
                </div>
              </div>
            </div>
          )}

          {sale.applicable_offers && sale.applicable_offers.length > 0 && (
            <div className="pl-card">
              <div className="pl-card__header">Offer available</div>
              <div style={{ padding: 16 }}>
                {sale.applicable_offers.map((offer, i) => (
                  <div key={i} className="inv-offer-card">
                    <div style={{ fontWeight: 600 }}>🎁 {offer.title}</div>
                    <div className="pl-sub">{offer.discount?.summary}</div>
                    <div className="pl-sub">
                      Valid until: {offer.schedule?.ends_date ? offer.schedule.ends_date.slice(0, 10) : 'No end date'} · Min order:{' '}
                      {offer.discount?.min_order_value ? money(offer.discount.min_order_value) : 'None'}
                    </div>
                    <button type="button" className="admin-btn" style={{ marginTop: 6 }} onClick={() => setWhatsapp('offer_available')}>
                      Send Offer on WhatsApp
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="pl-card">
            <div className="pl-card__header">Send WhatsApp</div>
            <div className="inv-template-grid">
              {TEMPLATES.map((t) => (
                <button key={t.code} type="button" className="admin-btn" onClick={() => setWhatsapp(t.code)}>
                  {t.label}
                </button>
              ))}
            </div>
          </div>

          <div className="pl-card">
            <div className="pl-card__header">Communication history</div>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>When</th>
                  <th>To</th>
                  <th>Channel</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {(sale.communications || []).length === 0 ? (
                  <tr>
                    <td colSpan={5} className="pl-sub" style={{ textAlign: 'center', padding: 12 }}>
                      No messages sent yet
                    </td>
                  </tr>
                ) : (
                  sale.communications.map((c, i) => {
                    const ok = c.status === 'opened' || c.status === 'sent';
                    const label = c.status === 'opened' ? 'Opened' : c.status === 'sent' ? 'Sent' : 'Failed';
                    return (
                      <tr key={i}>
                        <td>{TEMPLATES.find((t) => t.code === c.template_code)?.label || c.template_code}</td>
                        <td>{(c.created_date || '').slice(0, 16)}</td>
                        <td>{c.recipient_mobile}</td>
                        <td>{c.channel === 'sms' ? 'SMS' : 'WhatsApp'}</td>
                        <td>
                          <span className={`status-badge status-badge--${ok ? 'success' : 'danger'}`}>{label}</span>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          <div className="pl-card">
            <div className="pl-card__header">Timeline</div>
            <ol className="inv-timeline">
              {(sale.timeline || []).map((step, i) => (
                <li key={i} className={`inv-timeline__dot--${step.tone || 'secondary'}`}>
                  <div style={{ fontWeight: 600 }}>{step.label}</div>
                  <div className="pl-sub">{step.date || 'Pending'}</div>
                </li>
              ))}
            </ol>
          </div>

          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {remaining > 0.004 && (
              <button type="button" className="admin-btn admin-btn--primary" onClick={recordPayment}>
                Record Payment
              </button>
            )}
            {sale.status === 'completed' && (
              <button type="button" className="admin-btn" onClick={refund}>
                Refund
              </button>
            )}
            {sale.status === 'completed' && (
              <button type="button" className="admin-btn" style={{ color: '#c0392b' }} onClick={voidSale}>
                Cancel Invoice
              </button>
            )}
            <button type="button" className="admin-btn" onClick={() => window.print()}>
              Print
            </button>
          </div>
        </div>
      </div>

      {whatsapp && (
        <WhatsAppModal
          saleUuid={sale.uuid}
          templateCode={whatsapp}
          onClose={() => setWhatsapp(null)}
          onLogged={refresh}
        />
      )}
      {customerHistory && <CustomerHistoryModal customerUuid={customerHistory} onClose={() => setCustomerHistory(null)} />}
    </div>
  );
}
