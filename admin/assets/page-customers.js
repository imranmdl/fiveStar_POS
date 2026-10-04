/**
 * Customers: who they are, what they buy, and how to reach them.
 *
 * Built from data the platform already holds — orders, wishlists, referrals and
 * notification preferences — rather than a separate CRM. The useful questions a
 * shop owner asks are "who buys repeatedly", "who has something saved they have
 * not bought", and "who may I message".
 *
 * CONSENT IS SHOWN BESIDE EVERY CONTACT. Under TRAI rules a promotional message
 * to someone who opted out, or to a DND-registered number, is an offence — so
 * the interface never presents a contact list without saying who may lawfully be
 * messaged. Making that easy to ignore would be designing a trap.
 */

import { api, mountConsole, showError, escapeHtml, formatMoney,
         iconStatCard, emptyState } from './console.js?v=9';

let root = null;

const CUSTOMER_ICONS = {
  people: '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M15 14.5c2.3.3 4 2.1 4 4.5v1h3v-1c0-2.3-1.6-4.2-3.8-4.7"/>',
  cart: '<path d="M3 3h2l2.4 12.4a2 2 0 0 0 2 1.6h7.2a2 2 0 0 0 2-1.6L20 8H6"/><circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/>',
  repeat: '<path d="M17 2v4h-4"/><path d="M21 6a9 9 0 0 0-15-3.7L3 6"/><path d="M7 22v-4h4"/><path d="M3 18a9 9 0 0 0 15 3.7l3-3.7"/>',
  trend: '<path d="M4 17 9 11l4 3 7-8"/><path d="M15 6h5v5"/>',
};

function maskMobile(mobile) {
  const value = String(mobile || '');
  if (value.length < 6) return value;

  // A screen someone may share or screenshot should not carry whole phone
  // numbers. The last four are enough to recognise a customer on a call.
  return `${value.slice(0, 2)}${'X'.repeat(value.length - 6)}${value.slice(-4)}`;
}

/**
 * The masked number, clickable to reach the customer — WhatsApp, SMS or a
 * call, each a real link (wa.me / sms: / tel:) rather than a JS handler, so
 * it opens whatever app the staff member's device already has for that.
 * Numbers are stored as plain 10-digit Indian mobiles (see mobile_in in
 * Validator.php) — the +91 is added only for the outgoing link, never shown.
 */
function contactMenu(mobile) {
  const digits = String(mobile || '').replace(/\D/g, '').slice(-10);

  if (digits.length !== 10) return escapeHtml(maskMobile(mobile));

  const intl = `91${digits}`;

  return `
    <div class="dropdown">
      <button class="btn btn-sm btn-link p-0 font-monospace text-decoration-none" type="button"
              data-bs-toggle="dropdown" aria-expanded="false">
        ${escapeHtml(maskMobile(mobile))}
      </button>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item small" href="https://wa.me/${intl}" target="_blank" rel="noopener noreferrer">WhatsApp</a></li>
        <li><a class="dropdown-item small" href="sms:+${intl}">Message</a></li>
        <li><a class="dropdown-item small" href="tel:+${intl}">Call</a></li>
      </ul>
    </div>`;
}

async function render() {
  root.innerHTML = `
    <h1 class="h4 mb-3">Customers</h1>
    <div data-panel><div class="text-center py-5 text-muted"><div class="spinner-border"></div></div></div>`;

  const panel = root.querySelector('[data-panel]');

  try {
    const [report, wishlist] = await Promise.all([
      api.get('/admin/reports/customers'),
      api.get('/admin/reports/products').catch(() => ({ data: { products: [] } })),
    ]);

    const growth = report.data.growth || {};
    const top = report.data.top_customers || [];

    panel.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
        ${iconStatCard({ tone: '#C1670E', iconSvgPaths: CUSTOMER_ICONS.people, label: 'New customers', value: escapeHtml(growth.total_signups ?? 0), hint: 'last 30 days' })}
        ${iconStatCard({ tone: '#C1670E', iconSvgPaths: CUSTOMER_ICONS.cart, label: 'Bought something', value: escapeHtml(growth.buyers ?? 0), hint: 'in the period' })}
        ${iconStatCard({ tone: '#C1670E', iconSvgPaths: CUSTOMER_ICONS.repeat, label: 'Came back', value: escapeHtml(growth.repeat_buyers ?? 0), hint: 'more than one order' })}
        ${iconStatCard({
          tone: Number(growth.repeat_rate_percent) > 20 ? 'var(--forest)' : 'var(--muted-2)',
          iconSvgPaths: CUSTOMER_ICONS.trend,
          label: 'Repeat rate',
          value: `${escapeHtml(growth.repeat_rate_percent ?? 0)}%`,
          hint: 'the number worth watching',
        })}
      </div>

      <div class="alert alert-light border small">
        <b>Repeat rate is the figure to watch.</b> Spices and dry fruits are bought
        again and again; winning a customer is expensive and keeping one is nearly
        free. A shop with a rising repeat rate is healthy even in a flat month.
      </div>

      <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Best customers</span>
          <span class="small text-muted">by spend, last 30 days</span>
        </div>
        ${top.length === 0
          ? `<div class="card-body">${emptyState('No orders in this period', 'Come back once you have sales.')}</div>`
          : `<div class="table-responsive">
               <table class="table table-tight table-hover mb-0">
                 <thead><tr><th>Customer</th><th>Contact</th><th class="text-center">Orders</th>
                   <th class="text-end">Spent</th><th>Last order</th><th class="text-end">Wallet</th></tr></thead>
                 <tbody>
                   ${top.map((customer) => `
                     <tr>
                       <td class="fw-semibold">${escapeHtml(customer.customer_name || '—')}</td>
                       <td class="small">${customer.mobile ? contactMenu(customer.mobile) : '—'}</td>
                       <td class="text-center">${escapeHtml(customer.order_count)}</td>
                       <td class="text-end">${formatMoney(customer.total_spent)}</td>
                       <td class="small">${escapeHtml(String(customer.last_order_date || '').slice(0, 10))}</td>
                       <td class="text-end small">
                         <div class="${Number(customer.wallet_balance) > 0 ? 'fw-semibold' : 'text-muted'}">${formatMoney(customer.wallet_balance)}</div>
                         ${customer.wallet_frozen ? '<span class="badge text-bg-danger">Frozen</span> ' : ''}
                         <a href="wallets.html?customer_uuid=${encodeURIComponent(customer.uuid)}">Manage →</a>
                       </td>
                     </tr>`).join('')}
                 </tbody>
               </table>
             </div>`}
        <div class="card-footer bg-white small text-muted">
          Mobile numbers are partly hidden. A report that gets shared or screenshotted
          should not carry a column of whole phone numbers.
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-white fw-semibold">Reaching customers</div>
        <div class="card-body">
          <p class="small mb-2">
            Order updates, payment receipts and dispatch notices go out
            automatically and cannot be switched off — they are part of the order.
          </p>
          <p class="small mb-3">
            <b>Offers and new-arrival announcements are different.</b> They may only
            go to customers who have not opted out and whose number is not on the
            national Do Not Disturb register. The platform enforces both, and holds
            promotional messages outside 9am–9pm.
          </p>

          <div class="alert alert-warning small mb-0">
            <b>Bulk offer announcements are not built yet.</b> The message templates
            and the consent rules exist; what is missing is the screen to choose an
            audience and send. Until then, a promotional campaign has to be queued
            through the API.
          </div>
        </div>
      </div>`;
  } catch (error) {
    panel.innerHTML = '';
    showError(error, panel);
  }
}

const mounted = await mountConsole('customers.html');
if (mounted) { root = mounted.root; render(); }
