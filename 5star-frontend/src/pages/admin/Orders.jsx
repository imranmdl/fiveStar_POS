/**
 * Orders: the screen staff live in — confirm, pack, book a courier, look up
 * why something is stuck. Ported faithfully from the live
 * admin/assets/page-orders.js (status-transition rules and endpoints are
 * copied exactly; only the rendering is React instead of innerHTML).
 *
 * BR-005 IS THE SERVER'S RULE, NOT THIS SCREEN'S. An unpaid order cannot be
 * progressed, and the server enforces that regardless of what any client
 * sends. This screen disables/hides the buttons anyway, so staff see the
 * constraint before they hit it rather than as a red error afterwards.
 *
 * Detail views are in-page state (query params), not nested routes, so no
 * change to adminRoutes.jsx is needed.
 */
import { useCallback, useEffect, useState } from 'react';
import { useOutletContext, useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { StatusBadge, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import ManualPaymentForm, { orderPaymentLabel } from '../../components/admin/ManualPaymentForm';
import './Orders.css';

function reportError(error, fallback) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || fallback || 'Something went wrong.');
  toast(text, 'danger');
}

function money(value) {
  return formatMoney(Number(value || 0));
}

function fmtDate(value) {
  return String(value || '').slice(0, 16).replace('T', ' ');
}

function statusLabel(status) {
  return String(status || '').replace(/_/g, ' ');
}

const STATUS_FILTERS = [
  ['', 'All'],
  ['awaiting_payment', 'Awaiting payment'],
  ['confirmed', 'To pack'],
  ['packed', 'To ship'],
  ['shipped', 'In transit'],
  ['delivered', 'Delivered'],
  ['cancelled', 'Cancelled'],
];

const PAYMENT_FILTERS = [
  ['', 'All payments'],
  ['paid', 'Payment done'],
  ['pending', 'Payment not done'],
  ['processing', 'Payment processing'],
  ['failed', 'Payment failed'],
  ['refunded', 'Refunded'],
];

const DELIVERY_FILTERS = [
  ['', 'All deliveries'],
  ['delivered', 'Delivered'],
  ['pending', 'Not delivered'],
];

const METHOD_LABEL = { cash: 'Cash', upi: 'UPI', card: 'Card (POS)', other: 'Other' };
const PAYMENT_STATUS_LABEL = { unpaid: 'Unpaid', partial: 'Partially Paid', paid: 'Paid' };

/** View-type tabs — counter (POS) sales are an administrator-only view, same gate as the source. */
function ViewTabs({ view, onChange }) {
  return (
    <div className="orders-tabs" role="group" aria-label="Order type">
      {[['online', 'Online orders'], ['counter', 'Counter (POS) sales']].map(([value, label]) => (
        <button
          key={value}
          type="button"
          className={`admin-btn ${view === value ? 'admin-btn--primary' : ''}`}
          onClick={() => onChange(value)}
        >
          {label}
        </button>
      ))}
    </div>
  );
}

/* ----------------------------------------------------------------------- */
/* Online orders: list                                                     */
/* ----------------------------------------------------------------------- */

function OnlineOrdersList({ isAdmin, view, onViewChange, status, payment, page, onFilter, onPage, onOpen }) {
  const [state, setState] = useState({ loading: true, error: null, orders: [], meta: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/orders', {
        status,
        payment_status: payment,
        page,
        per_page: 25,
      });
      setState({ loading: false, error: null, orders: response.data || [], meta: response.meta || null });
    } catch (error) {
      setState({ loading: false, error, orders: [], meta: null });
    }
  }, [status, payment, page]);

  useEffect(() => { load(); }, [load]);

  return (
    <>
      <div className="admin-toolbar">
        <h1 className="admin-page-title" style={{ margin: 0 }}>Orders</h1>
        {isAdmin && <ViewTabs view={view} onChange={onViewChange} />}
        <select
          className="orders-select"
          aria-label="Filter by payment"
          value={payment}
          onChange={(event) => onFilter({ payment_status: event.target.value })}
        >
          {PAYMENT_FILTERS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      <div className="admin-toolbar">
        {STATUS_FILTERS.map(([value, label]) => (
          <button
            key={value}
            type="button"
            className={`admin-btn ${status === value ? 'admin-btn--primary' : ''}`}
            onClick={() => onFilter({ status: value })}
          >
            {label}
          </button>
        ))}
      </div>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.orders.length === 0 ? (
        <EmptyState
          title="No orders here"
          hint={(status || payment) ? 'Nothing matches those filters.' : 'No orders have been placed yet.'}
        />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Order</th><th>Customer</th><th>Status</th><th>Payment</th>
                  <th style={{ textAlign: 'right' }}>Total</th><th></th>
                </tr>
              </thead>
              <tbody>
                {state.orders.map((order) => {
                  const paid = order.payment_status === 'paid' || order.payment_status === 'partially_refunded';
                  return (
                    <tr key={order.uuid}>
                      <td>
                        <button type="button" className="orders-link" onClick={() => onOpen(order.uuid)}>
                          {order.order_number}
                        </button>
                        <div className="orders-subtext">{fmtDate(order.placed_date)}</div>
                      </td>
                      <td>{order.customer_name || '—'}</td>
                      <td><StatusBadge status={order.status} label={statusLabel(order.status)} /></td>
                      <td>
                        {paid
                          ? <StatusBadge status="paid" label="Paid" />
                          : <StatusBadge status={order.payment_status} label={orderPaymentLabel(order.payment_status)} />}
                      </td>
                      <td style={{ textAlign: 'right' }}>{money(order.grand_total)}</td>
                      <td style={{ textAlign: 'right' }}>
                        <button type="button" className="admin-btn" onClick={() => onOpen(order.uuid)}>Open</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {state.meta && state.meta.total_pages > 1 && (
            <div className="orders-pagination">
              <span className="orders-subtext">
                Page {state.meta.page} of {state.meta.total_pages}, {state.meta.total} order(s)
              </span>
              <span>
                <button className="admin-btn" disabled={state.meta.page <= 1} onClick={() => onPage(page - 1)}>Previous</button>{' '}
                <button className="admin-btn" disabled={state.meta.page >= state.meta.total_pages} onClick={() => onPage(page + 1)}>Next</button>
              </span>
            </div>
          )}
        </>
      )}
    </>
  );
}

/* ----------------------------------------------------------------------- */
/* Online orders: detail + actions                                         */
/* ----------------------------------------------------------------------- */

/**
 * "Refund to original payment method, or to the customer's wallet?" — asked
 * only when there's actually a captured payment to send somewhere. Rendered
 * inline (no Bootstrap modal available here) in place of the normal action
 * buttons; "Back" abandons the cancellation rather than picking a default.
 */
function RefundChoice({ onChoose, onBack }) {
  return (
    <div>
      <p className="orders-hint">Refund this payment to…</p>
      <div className="orders-choice-list">
        <button type="button" className="orders-choice" onClick={() => onChoose('gateway')}>
          <span className="orders-choice__title">Original payment method</span>
          <span className="orders-choice__hint">Sent back through the gateway the customer paid with.</span>
        </button>
        <button type="button" className="orders-choice" onClick={() => onChoose('wallet')}>
          <span className="orders-choice__title">Customer's wallet</span>
          <span className="orders-choice__hint">Credited instantly as store credit — no gateway involved.</span>
        </button>
      </div>
      <button type="button" className="admin-btn" onClick={onBack}>Back</button>
    </div>
  );
}

/**
 * Was: always POST /ship with no body, letting BR-007 choose silently. This
 * lets staff see what BR-007 would choose and why — cost, SLA, eligibility —
 * before committing, using the courier-options endpoint.
 */
function CourierChooser({ uuid, onBooked, onCancel }) {
  const [state, setState] = useState({ loading: true, error: null, options: null });
  const [choice, setChoice] = useState('');
  const [booking, setBooking] = useState(false);

  useEffect(() => {
    let mounted = true;
    api.get(`/admin/orders/${encodeURIComponent(uuid)}/courier-options`)
      .then((response) => { if (mounted) setState({ loading: false, error: null, options: response.data }); })
      .catch((error) => { if (mounted) setState({ loading: false, error, options: null }); });
    return () => { mounted = false; };
  }, [uuid]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState error={state.error} />;

  const candidates = state.options.candidates || [];

  async function handleBook() {
    setBooking(true);
    try {
      const response = await api.post(`/admin/orders/${encodeURIComponent(uuid)}/ship`, choice ? { courier_code: choice } : {});
      toast(`Booked with ${response.data.courier_name || 'a courier'}.`);
      onBooked();
    } catch (error) {
      setBooking(false);
      reportError(error);
    }
  }

  return (
    <div>
      <p className="orders-hint">
        Automatic selection ({state.options.strategy}): {state.options.reason || ''}
      </p>
      <div className="orders-choice-list">
        <label className="orders-choice orders-choice--radio">
          <input type="radio" name="courier-choice" checked={choice === ''} onChange={() => setChoice('')} />
          Automatic (recommended)
        </label>
        {candidates.map((c) => (
          <label key={c.courier_code} className={`orders-choice orders-choice--radio ${c.is_eligible ? '' : 'orders-choice--disabled'}`}>
            <span>
              <input
                type="radio"
                name="courier-choice"
                value={c.courier_code}
                disabled={!c.is_eligible}
                checked={choice === c.courier_code}
                onChange={() => setChoice(c.courier_code)}
              />
              {' '}{c.courier_name}
              {!c.is_eligible && <span className="orders-subtext"> — {(c.ineligibility_reasons || []).join('; ')}</span>}
            </span>
            <span className="orders-subtext">{money(c.cost)} · {c.sla_min_days}–{c.sla_max_days}d</span>
          </label>
        ))}
      </div>
      <div className="orders-action-row">
        <button type="button" className="admin-btn admin-btn--primary" disabled={booking} onClick={handleBook}>
          {booking ? 'Booking…' : 'Book'}
        </button>
        <button type="button" className="admin-btn" disabled={booking} onClick={onCancel}>Cancel</button>
      </div>
    </div>
  );
}

/** The payment-not-yet-verified form: amount + UTR, or reject. Shown only while a manual UPI payment is waiting for staff. */
function PendingPaymentForm({ order, pending, onResolved }) {
  return (
    <div>
      <p className="orders-hint">
        Payment: <strong>{orderPaymentLabel(order.payment_status)}</strong>. Check that ₹{pending.amount} has actually
        reached your account, then enter the UTR of that transfer.
      </p>
      <ManualPaymentForm
        compact
        payment={{
          uuid: pending.uuid,
          amount: pending.amount,
          order_number: order.order_number,
          order_status: order.status,
          order_expired: Boolean(pending.order_expired),
        }}
        onDone={onResolved}
      />
    </div>
  );
}

/** Every payment attempt on the order, with the saved UTR — what was confirmed, when, and how. */
function PaymentRecords({ payments }) {
  if (!payments || payments.length === 0) return null;
  const STATUS = { created: 'Waiting', pending: 'Waiting', captured: 'Paid', authorized: 'Authorised', failed: 'Not received', cancelled: 'Closed', refunded: 'Refunded', partially_refunded: 'Partly refunded' };
  return (
    <div className="orders-card">
      <div className="orders-card__header">Payments</div>
      <div style={{ overflowX: 'auto' }}>
        <table className="mp-records">
          <thead>
            <tr><th>#</th><th>Via</th><th>Status</th><th>Amount</th><th>UTR / reference</th><th>Paid on</th></tr>
          </thead>
          <tbody>
            {payments.map((p) => (
              <tr key={p.uuid}>
                <td>{p.attempt}</td>
                <td>{p.gateway === 'manual' ? 'UPI QR (staff checked)' : p.gateway}{p.method ? ` · ${String(p.method).toUpperCase()}` : ''}</td>
                <td>{STATUS[p.status] || p.status}{p.failure_reason ? <div className="orders-subtext">{p.failure_reason}</div> : null}</td>
                <td>{money(p.amount)}</td>
                <td className="mp-utr">{p.upi_transaction_id || '—'}</td>
                <td>{p.paid_date ? fmtDate(p.paid_date) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** The actions available, given what the server will actually allow — exact transition rules from the source. */
function OrderActions({ uuid, order, detail, onChanged }) {
  const [mode, setMode] = useState(null); // null | 'courier' | { cancelReason }
  const [busyAction, setBusyAction] = useState(null);

  const paid = order.payment_status === 'paid' || order.payment_status === 'partially_refunded';
  const shipped = Boolean(detail.shipping && detail.shipping.tracking_number);

  if (mode === 'courier') {
    return <CourierChooser uuid={uuid} onBooked={() => { setMode(null); onChanged(); }} onCancel={() => setMode(null)} />;
  }

  if (mode && mode.cancelReason) {
    const doCancel = async (refundMethod) => {
      setBusyAction('cancel');
      try {
        await api.post(`/admin/orders/${encodeURIComponent(uuid)}/cancel`, {
          reason: mode.cancelReason,
          ...(refundMethod ? { refund_method: refundMethod } : {}),
        });
        toast(refundMethod === 'wallet'
          ? 'Order cancelled. The payment was credited to the customer’s wallet.'
          : 'Order cancelled. Any payment will be refunded.');
        setMode(null);
        onChanged();
      } catch (error) {
        reportError(error);
      } finally {
        setBusyAction(null);
      }
    };
    return <RefundChoice onChoose={doCancel} onBack={() => setMode(null)} />;
  }

  if (!paid && detail.pending_manual_payment) {
    return <PendingPaymentForm order={order} pending={detail.pending_manual_payment} onResolved={onChanged} />;
  }

  if (!paid) {
    return (
      <div className="admin-alert admin-alert--warning">
        <div style={{ fontWeight: 600 }}>Nothing can be done until this is paid for.</div>
        Orders do not progress without a verified payment, and that applies to staff actions too. If the
        customer has paid, the confirmation arrives by webhook — it is not something to force through here.
      </div>
    );
  }

  async function handleStatusAction(nextStatus) {
    setBusyAction(nextStatus);
    try {
      await api.post(`/admin/orders/${encodeURIComponent(uuid)}/status`, { status: nextStatus });
      toast('Order updated.');
      onChanged();
    } catch (error) {
      reportError(error);
    } finally {
      // Reset even on success: onChanged() re-fetches but does not remount
      // this component, so a stale busyAction would otherwise leave every
      // button disabled after the status actually changed.
      setBusyAction(null);
    }
  }

  function handleCancelClick() {
    const reason = window.prompt('Why is this order being cancelled? The customer is told.');
    if (!reason) return;

    const hasCapturedPayment = ['paid', 'partially_refunded'].includes(order.payment_status);
    if (hasCapturedPayment) {
      setMode({ cancelReason: reason });
    } else {
      setBusyAction('cancel');
      api.post(`/admin/orders/${encodeURIComponent(uuid)}/cancel`, { reason })
        .then(() => { toast('Order cancelled. Any payment will be refunded.'); onChanged(); })
        .catch((error) => { reportError(error); })
        .finally(() => { setBusyAction(null); });
    }
  }

  const buttons = [];
  if (order.status === 'confirmed') {
    buttons.push(['packed', 'Mark as packed', 'admin-btn--primary', () => handleStatusAction('packed')]);
  }
  if (['packed', 'ready_to_ship'].includes(order.status) && !shipped) {
    buttons.push(['__ship', 'Book a courier', 'admin-btn--primary', () => setMode('courier')]);
  }
  if (['confirmed', 'packed'].includes(order.status)) {
    buttons.push(['cancelled', 'Cancel order', '', handleCancelClick]);
  }

  if (buttons.length === 0) {
    return <p className="orders-hint">No actions available at this status.</p>;
  }

  return (
    <div className="orders-action-row">
      {buttons.map(([key, label, cls, handler]) => (
        <button
          key={key}
          type="button"
          className={`admin-btn ${cls}`}
          disabled={busyAction !== null}
          onClick={handler}
        >
          {busyAction === key ? 'Working…' : label}
        </button>
      ))}
    </div>
  );
}

function OrderDetail({ uuid, onBack }) {
  const [state, setState] = useState({ loading: true, error: null, detail: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get(`/admin/orders/${encodeURIComponent(uuid)}`);
      setState({ loading: false, error: null, detail: response.data });
    } catch (error) {
      setState({ loading: false, error, detail: null });
    }
  }, [uuid]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <button type="button" className="orders-link" onClick={onBack}>&larr; All orders</button>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : (() => {
        const detail = state.detail;
        const order = detail.order;
        const shipping = detail.shipping || {};
        const pricing = detail.pricing || {};

        return (
          <>
            <div className="orders-detail-header">
              <div>
                <h1 className="admin-page-title" style={{ margin: '8px 0 4px' }}>{order.order_number}</h1>
                <div>
                  <StatusBadge status={order.status} label={statusLabel(order.status)} />
                  {' '}
                  <StatusBadge status={order.payment_status} label={orderPaymentLabel(order.payment_status)} />
                  {order.invoice_number && <span className="orders-subtext"> Invoice {order.invoice_number}</span>}
                </div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: 20, fontWeight: 600 }}>{money(order.grand_total)}</div>
                <div className="orders-subtext">incl. {money(pricing.tax_total)} GST</div>
              </div>
            </div>

            <div className="orders-card">
              <div className="orders-card__header">Actions</div>
              <div className="orders-card__body">
                <OrderActions uuid={uuid} order={order} detail={detail} onChanged={load} />
              </div>
            </div>

            <PaymentRecords payments={detail.payments} />

            <div className="orders-detail-grid">
              <div>
                <div className="orders-card">
                  <div className="orders-card__header">Items</div>
                  <div style={{ overflowX: 'auto' }}>
                    <table className="admin-table">
                      <tbody>
                        {(detail.items || []).map((item, index) => (
                          <tr key={index}>
                            <td>
                              {item.product_name}
                              <div className="orders-subtext">{item.variant_name} &middot; {item.sku || ''}</div>
                            </td>
                            <td style={{ textAlign: 'right' }}>&times; {item.quantity}</td>
                            <td style={{ textAlign: 'right' }}>{money(item.line_payable)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                <div className="orders-card">
                  <div className="orders-card__header">Timeline</div>
                  <ul className="orders-timeline">
                    {(detail.timeline || []).map((entry, index) => (
                      <li key={index}>
                        <div style={{ fontWeight: 600, fontSize: 13 }}>{entry.title}</div>
                        {entry.note && <div className="orders-subtext">{entry.note}</div>}
                        <div className="orders-subtext">{fmtDate(entry.date)}</div>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>

              <div>
                <div className="orders-card">
                  <div className="orders-card__header">Deliver to</div>
                  <div className="orders-card__body">
                    <div style={{ fontWeight: 600 }}>{shipping.name || ''}</div>
                    <div>{shipping.address || ''}</div>
                    <div style={{ marginTop: 8 }}>{shipping.mobile || ''}</div>
                    {shipping.tracking_number && (
                      <>
                        <hr />
                        <div>{shipping.courier_name || 'Courier'}</div>
                        <div style={{ fontFamily: 'monospace' }}>{shipping.tracking_number}</div>
                      </>
                    )}
                  </div>
                </div>

                <div className="orders-card">
                  <div className="orders-card__header">Payment</div>
                  <div className="orders-card__body">
                    <dl className="orders-dl">
                      <dt>Items</dt><dd>{money(pricing.items_subtotal)}</dd>
                      <dt>Discount</dt><dd>{money(pricing.order_discount)}</dd>
                      <dt>Delivery</dt><dd>{money(pricing.delivery_charge)}</dd>
                      <dt style={{ fontWeight: 600 }}>Total</dt><dd style={{ fontWeight: 600 }}>{money(pricing.grand_total)}</dd>
                      {Number(pricing.wallet_applied) > 0 && (
                        <>
                          <dt>Paid by wallet</dt><dd>{money(pricing.wallet_applied)}</dd>
                        </>
                      )}
                    </dl>
                  </div>
                </div>
              </div>
            </div>
          </>
        );
      })()}
    </div>
  );
}

/* ----------------------------------------------------------------------- */
/* Counter (POS) sales                                                     */
/* ----------------------------------------------------------------------- */

/**
 * Sale detail — items, discounts, amounts and payment status — shown as an
 * overlay panel rather than sending the admin to the Invoice Tracking page.
 * Reuses the same read endpoint Invoice Tracking uses (/admin/invoices/{uuid},
 * a POS sale by another name), but its own, simpler view: no WhatsApp
 * composer, no communication history — just what this screen needs.
 */
function SaleDetailPanel({ uuid, onClose, onChanged }) {
  const [state, setState] = useState({ loading: true, error: null, sale: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get(`/admin/invoices/${encodeURIComponent(uuid)}`);
      setState({ loading: false, error: null, sale: response.data });
    } catch (error) {
      setState({ loading: false, error, sale: null });
    }
  }, [uuid]);

  useEffect(() => { load(); }, [load]);

  async function handleRecordPayment() {
    const sale = state.sale;
    const due = (Number(sale.grand_total) - Number(sale.amount_paid)).toFixed(2);
    const amount = window.prompt(`Remaining balance is ${money(due)}. Enter the amount received:`, due);
    if (amount === null || amount.trim() === '') return;

    const method = window.prompt('Payment method (cash, upi, card, other):', 'cash');
    if (!method) return;

    try {
      await api.post(`/admin/pos/sales/${sale.uuid}/payments`, {
        amount: Number(amount),
        payment_method: method.trim().toLowerCase(),
      });
      toast('Payment recorded. Balance updated.');
      onChanged();
      load();
    } catch (error) {
      reportError(error);
    }
  }

  return (
    <div className="orders-overlay" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="orders-slideover">
        <div className="orders-slideover__header">
          <h2 className="orders-slideover__title">{state.sale ? state.sale.sale_number : 'Sale'}</h2>
          <button type="button" className="admin-btn" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="orders-slideover__body">
          {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : (() => {
            const sale = state.sale;
            const remaining = Number(sale.grand_total) - Number(sale.amount_paid);
            return (
              <>
                <div className="orders-detail-header" style={{ marginBottom: 12 }}>
                  <div>
                    <div style={{ fontWeight: 600 }}>{sale.customer_name || 'Walk-in customer'}</div>
                    <div className="orders-subtext">{sale.customer_mobile || 'No phone on file'}</div>
                  </div>
                  <StatusBadge status={sale.payment_status} label={PAYMENT_STATUS_LABEL[sale.payment_status] || sale.payment_status} />
                </div>

                <div className="orders-card">
                  <div className="orders-card__header">Payment</div>
                  <div className="orders-card__body">
                    <div className="orders-pay-summary">
                      <div><div className="orders-subtext">Total</div><div style={{ fontWeight: 600 }}>{money(sale.grand_total)}</div></div>
                      <div><div className="orders-subtext">Paid</div><div style={{ fontWeight: 600, color: '#2e7d32' }}>{money(sale.amount_paid)}</div></div>
                      <div><div className="orders-subtext">Remaining</div><div style={{ fontWeight: 600, color: remaining > 0 ? '#c0392b' : 'inherit' }}>{money(Math.max(0, remaining))}</div></div>
                    </div>
                    <div className="orders-subtext" style={{ marginBottom: 8 }}>
                      Payment method: <span style={{ textTransform: 'uppercase' }}>{sale.payment_method}</span> &middot; Discount: {money(sale.discount_amount)}
                    </div>
                    {sale.due_payments && sale.due_payments.length > 0 ? (
                      <table className="admin-table">
                        <thead><tr><th>Date</th><th>Amount</th><th>Method</th></tr></thead>
                        <tbody>
                          {sale.due_payments.map((p, i) => (
                            <tr key={i}><td>{p.payment_date}</td><td>{money(p.amount)}</td><td style={{ textTransform: 'uppercase' }}>{p.payment_method}</td></tr>
                          ))}
                        </tbody>
                      </table>
                    ) : <div className="orders-subtext">No due-payment history.</div>}
                  </div>
                </div>

                <div className="orders-card">
                  <div className="orders-card__header">Items</div>
                  <div style={{ overflowX: 'auto' }}>
                    <table className="admin-table">
                      <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Discount</th><th>GST</th><th>Total</th></tr></thead>
                      <tbody>
                        {(sale.items || []).map((item, i) => (
                          <tr key={i}>
                            <td>{item.product_name} <span className="orders-subtext">({item.variant_name})</span></td>
                            <td>{item.quantity}</td>
                            <td>{money(item.unit_price)}</td>
                            <td>{money(item.discount_amount)}</td>
                            <td>{money(item.tax_amount)}</td>
                            <td>{money(item.line_total)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {sale.refunds && sale.refunds.length > 0 && (
                  <div className="orders-card">
                    <div className="orders-card__header">Refunds</div>
                    <div className="orders-card__body">
                      {sale.refunds.map((r, i) => (
                        <div key={i} className="orders-refund-row">
                          <span>{fmtDate(r.created_date)} &middot; {r.reason || ''}</span>
                          <span style={{ fontWeight: 600 }}>{money(r.refund_amount)}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {remaining > 0.004 && (
                  <button type="button" className="admin-btn admin-btn--primary" onClick={handleRecordPayment}>Record Payment</button>
                )}
              </>
            );
          })()}
        </div>
      </div>
    </div>
  );
}

function CounterSalesList({ isAdmin, view, onViewChange, delivery, page, onFilter, onPage, onOpen }) {
  const [state, setState] = useState({ loading: true, error: null, sales: [], meta: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const response = await api.get('/admin/counter-sales', { delivery, page, per_page: 25 });
      setState({ loading: false, error: null, sales: response.data || [], meta: response.meta || null });
    } catch (error) {
      setState({ loading: false, error, sales: [], meta: null });
    }
  }, [delivery, page]);

  useEffect(() => { load(); }, [load]);

  async function handleDeliver(saleUuid) {
    try {
      await api.post(`/admin/pos/sales/${encodeURIComponent(saleUuid)}/deliver`, {});
      toast('Marked as delivered.');
      load();
    } catch (error) {
      reportError(error);
    }
  }

  return (
    <>
      <div className="admin-toolbar">
        <h1 className="admin-page-title" style={{ margin: 0 }}>Orders</h1>
        {isAdmin && <ViewTabs view={view} onChange={onViewChange} />}
        <select
          className="orders-select"
          aria-label="Filter by delivery"
          value={delivery}
          onChange={(event) => onFilter({ delivery: event.target.value })}
        >
          {DELIVERY_FILTERS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      {state.loading ? <LoadingState /> : state.error ? <ErrorState error={state.error} /> : state.sales.length === 0 ? (
        <EmptyState title="No counter sales here" hint={delivery ? 'Nothing matches that filter.' : 'Sales rung up at the till appear here.'} />
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Sale</th><th>Customer</th><th>Items</th><th>Payment</th><th>Delivery</th>
                  <th>Customer review</th><th style={{ textAlign: 'right' }}>Total</th>
                </tr>
              </thead>
              <tbody>
                {state.sales.map((sale) => {
                  const delivered = sale.delivery_status === 'delivered';
                  const balanceDue = Number(sale.balance_due || 0);
                  return (
                    <tr key={sale.uuid}>
                      <td>
                        <button type="button" className="orders-link" onClick={() => onOpen(sale.uuid)}>{sale.sale_number}</button>
                        <div className="orders-subtext">
                          {fmtDate(sale.created_date)} &middot; {sale.cashier_name}{sale.shop_label ? ` · ${sale.shop_label}` : ''}
                        </div>
                      </td>
                      <td>
                        {sale.customer_name || sale.customer_mobile
                          ? <>{sale.customer_name || 'Walk-in customer'}<div className="orders-subtext">{sale.customer_mobile || ''}</div></>
                          : <span className="orders-subtext">Walk-in customer</span>}
                      </td>
                      <td>{sale.items.map((i, idx) => <div key={idx}>{i.name} &times; {i.quantity}</div>)}</td>
                      <td>
                        <StatusBadge status={sale.payment_status} label={PAYMENT_STATUS_LABEL[sale.payment_status] || sale.payment_status} />
                        {sale.payment_status !== 'paid' && (
                          <>
                            <div className="orders-subtext">Paid {money(sale.amount_paid)} of {money(sale.grand_total)}</div>
                            <div className="orders-subtext" style={{ color: balanceDue > 0 ? '#c0392b' : undefined, fontWeight: balanceDue > 0 ? 600 : 400 }}>
                              {money(balanceDue)} due
                            </div>
                          </>
                        )}
                      </td>
                      <td>
                        {delivered ? (
                          <>
                            <StatusBadge status="delivered" label="Delivered" />
                            <div className="orders-subtext">{fmtDate(sale.delivered_date)}</div>
                          </>
                        ) : (
                          <>
                            <StatusBadge status="pending" label="Not delivered" />
                            <div><button type="button" className="admin-btn" style={{ marginTop: 4 }} onClick={() => handleDeliver(sale.uuid)}>Mark delivered</button></div>
                          </>
                        )}
                      </td>
                      <td>
                        {sale.reviews.length ? sale.reviews.map((r, idx) => (
                          <div key={idx} className="orders-subtext">
                            <span style={{ color: '#b7791f' }}>{'★'.repeat(r.rating)}{'☆'.repeat(5 - r.rating)}</span>
                            {' '}{r.product}{' '}
                            <StatusBadge status={r.status} label={r.status} />
                            {r.body && <div>{r.body}</div>}
                          </div>
                        )) : <span className="orders-subtext">No review yet</span>}
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {money(sale.grand_total)}
                        <div className="orders-subtext">{METHOD_LABEL[sale.payment_method] || sale.payment_method}</div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {state.meta && state.meta.total_pages > 1 && (
            <div className="orders-pagination">
              <span className="orders-subtext">Page {state.meta.page} of {state.meta.total_pages}</span>
              <span>
                <button className="admin-btn" disabled={state.meta.page <= 1} onClick={() => onPage(page - 1)}>Previous</button>{' '}
                <button className="admin-btn" disabled={state.meta.page >= state.meta.total_pages} onClick={() => onPage(page + 1)}>Next</button>
              </span>
            </div>
          )}
        </>
      )}
    </>
  );
}

/* ----------------------------------------------------------------------- */
/* Top level                                                               */
/* ----------------------------------------------------------------------- */

export default function Orders() {
  const { user } = useOutletContext();
  const isAdmin = String(user && user.role) === 'administrator';
  const [params, setParams] = useSearchParams();

  const view = isAdmin && params.get('view') === 'counter' ? 'counter' : 'online';
  const status = params.get('status') || '';
  const payment = params.get('payment_status') || '';
  const delivery = params.get('delivery') || '';
  const page = Number(params.get('page') || 1);
  const selectedOrderUuid = params.get('uuid');
  const selectedSaleUuid = params.get('sale');

  // Remounting the list (via this key) is the refresh signal after an action
  // taken in the sale-detail overlay changes something the list shows (e.g.
  // recording a payment changes the balance-due column) — the source does
  // the equivalent by calling renderCounterList() again after such actions.
  const [listReloadToken, setListReloadToken] = useState(0);

  function patch(partial, replace) {
    const next = new URLSearchParams(params);
    Object.entries(partial).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') next.delete(key);
      else next.set(key, String(value));
    });
    setParams(next, replace ? { replace: true } : undefined);
  }

  function handleViewChange(nextView) {
    patch({ view: nextView === 'online' ? null : nextView, page: null, delivery: null, status: null, payment_status: null });
  }

  if (selectedOrderUuid) {
    return (
      <div className="orders-page">
        <OrderDetail uuid={selectedOrderUuid} onBack={() => patch({ uuid: null })} />
      </div>
    );
  }

  return (
    <div className="orders-page">
      {view === 'counter' ? (
        <CounterSalesList
          key={listReloadToken}
          isAdmin={isAdmin}
          view={view}
          onViewChange={handleViewChange}
          delivery={delivery}
          page={page}
          onFilter={(f) => patch({ ...f, page: null })}
          onPage={(p) => patch({ page: p })}
          onOpen={(uuid) => patch({ sale: uuid })}
        />
      ) : (
        <OnlineOrdersList
          isAdmin={isAdmin}
          view={view}
          onViewChange={handleViewChange}
          status={status}
          payment={payment}
          page={page}
          onFilter={(f) => patch({ ...f, page: null })}
          onPage={(p) => patch({ page: p })}
          onOpen={(uuid) => patch({ uuid })}
        />
      )}

      {selectedSaleUuid && (
        <SaleDetailPanel
          uuid={selectedSaleUuid}
          onClose={() => patch({ sale: null })}
          onChanged={() => setListReloadToken((t) => t + 1)}
        />
      )}
    </div>
  );
}
