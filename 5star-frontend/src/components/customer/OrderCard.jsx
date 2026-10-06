import { Link } from 'react-router-dom';
import { rupees, tintFor } from '../../lib/store';
import { ProductMedia } from './ProductCard';

const TONES = {
  created: 'warn',
  awaiting_payment: 'warn',
  confirmed: 'info',
  packed: 'info',
  ready_to_ship: 'info',
  assigned: 'info',
  shipped: 'info',
  out_for_delivery: 'info',
  delivered: 'good',
  cancelled: 'muted',
  returned: 'muted',
  refunded: 'muted',
};

export function StatusBadge({ status, label }) {
  return <span className={`sf-badge-status sf-badge-status--${TONES[status] || 'info'}`}>{label}</span>;
}

/** "6 Oct 2026" from an API date ("2026-10-06 09:19:57"). */
export function formatDate(value, withTime = false) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value).slice(0, 10);
  const options = { day: 'numeric', month: 'short', year: 'numeric' };
  if (withTime) Object.assign(options, { hour: 'numeric', minute: '2-digit' });
  return date.toLocaleString('en-IN', options);
}

/** The one thing the customer should do next on an order, if any. */
export function orderAction(order) {
  if (order.needs_verification) return { kind: 'verify', label: 'Confirm your order' };
  if (order.can_pay) return { kind: 'pay', label: `Complete payment · ${rupees(order.amount_payable)}` };
  return null;
}

/** One order in the order history / account page. */
export default function OrderCard({ order }) {
  const action = orderAction(order);
  const preview = order.items_preview || [];
  const more = Math.max(0, (order.item_count || 0) - preview.length);
  const names = [...new Set(preview.map((i) => i.product_name))].join(', ') + (more > 0 ? ` + ${more} more` : '');

  let when = `Placed ${formatDate(order.placed_date)}`;
  if (order.status === 'delivered' && order.delivered_date) when = `Delivered ${formatDate(order.delivered_date)}`;
  else if (order.expected_delivery_date && !['cancelled', 'returned', 'refunded', 'delivered'].includes(order.status)) {
    when += ` · Arrives by ${formatDate(order.expected_delivery_date)}`;
  }

  return (
    <article className="sf-ocard">
      <Link className="sf-ocard__main" to={`/orders/${order.uuid}`}>
        <div className="sf-ocard__thumbs" aria-hidden="true">
          {preview.slice(0, 3).map((item, index) => (
            <ProductMedia key={index} image={item.image_url} tint={tintFor(item.product_name)} label={item.product_name.slice(0, 1)} />
          ))}
        </div>
        <div className="sf-ocard__body">
          <div className="sf-ocard__top">
            <StatusBadge status={order.status} label={order.status_label} />
            <span className="sf-ocard__total">{rupees(order.grand_total)}</span>
          </div>
          <b className="sf-ocard__names">{names || `${order.item_count} item(s)`}</b>
          <span className="sf-small">{order.order_number} · {when}</span>
          {order.tracking_number && (
            <span className="sf-small">{order.courier_name || 'Courier'} · AWB {order.tracking_number}</span>
          )}
          {order.total_savings > 0 && <span className="sf-small sf-good">You saved {rupees(order.total_savings)}</span>}
        </div>
      </Link>
      <div className="sf-ocard__actions">
        {action && (
          <Link className="sf-btn sf-btn--red sf-btn--sm" to={`/orders/${order.uuid}?${action.kind}=1`}>{action.label}</Link>
        )}
        {order.tracking_number && (
          <Link className="sf-btn sf-btn--outline sf-btn--sm" to={`/orders/${order.uuid}#tracking`}>Track</Link>
        )}
        <Link className="sf-btn sf-btn--ghost sf-btn--sm" to={`/orders/${order.uuid}`}>Details ›</Link>
      </div>
    </article>
  );
}
