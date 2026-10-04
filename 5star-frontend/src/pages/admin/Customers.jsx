import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { StatCard, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import './Customers.css';

/**
 * Customers — ported from admin/assets/page-customers.js (163 lines). No
 * filters, search or pagination exist in the source: it is a fixed 30-day
 * growth snapshot plus a "best customers by spend" table. customers.html
 * itself is an empty Bootstrap shell — all markup came from the JS, which is
 * why nothing from its (Bootstrap) classes was copied here.
 *
 * CONSENT IS SHOWN BESIDE EVERY CONTACT. Under TRAI rules a promotional
 * message to someone who opted out, or to a DND-registered number, is an
 * offence — numbers are partly masked and the "Reaching customers" card
 * below spells out what is and is not allowed.
 */

function maskMobile(mobile) {
  const value = String(mobile || '');
  if (value.length < 6) return value;
  return `${value.slice(0, 2)}${'X'.repeat(value.length - 6)}${value.slice(-4)}`;
}

/** wa.me / sms: / tel: links — real links, not JS handlers, so each opens whatever app the staff member's device already has for it. */
function ContactLinks({ mobile }) {
  const digits = String(mobile || '').replace(/\D/g, '').slice(-10);

  if (digits.length !== 10) {
    return <span className="cust-mobile">{maskMobile(mobile)}</span>;
  }

  const intl = `91${digits}`;

  return (
    <div className="cust-contact">
      <span className="cust-mobile">{maskMobile(mobile)}</span>
      <span className="cust-contact__links">
        <a href={`https://wa.me/${intl}`} target="_blank" rel="noopener noreferrer">WhatsApp</a>
        <a href={`sms:+${intl}`}>Message</a>
        <a href={`tel:+${intl}`}>Call</a>
      </span>
    </div>
  );
}

export default function Customers() {
  const [state, setState] = useState({ loading: true, error: null, growth: null, top: [] });

  useEffect(() => {
    let active = true;

    async function load() {
      try {
        const report = await api.get('/admin/reports/customers');
        if (!active) return;

        setState({
          loading: false,
          error: null,
          growth: report.data.growth || {},
          top: report.data.top_customers || [],
        });
      } catch (error) {
        if (!active) return;
        setState({ loading: false, error, growth: null, top: [] });
      }
    }

    load();
    return () => { active = false; };
  }, []);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState error={state.error} />;

  const { growth, top } = state;

  return (
    <div>
      <h1 className="admin-page-title">Customers</h1>

      <div className="stat-grid">
        <StatCard label="New customers" value={growth.total_signups ?? 0} hint="last 30 days" />
        <StatCard label="Bought something" value={growth.buyers ?? 0} hint="in the period" />
        <StatCard label="Came back" value={growth.repeat_buyers ?? 0} hint="more than one order" />
        <StatCard
          label="Repeat rate"
          value={`${growth.repeat_rate_percent ?? 0}%`}
          hint="the number worth watching"
          tone={Number(growth.repeat_rate_percent) > 20 ? 'success' : undefined}
        />
      </div>

      <div className="admin-alert">
        <b>Repeat rate is the figure to watch.</b> Spices and dry fruits are bought
        again and again; winning a customer is expensive and keeping one is nearly
        free. A shop with a rising repeat rate is healthy even in a flat month.
      </div>

      <div className="cust-card">
        <div className="cust-card__header">
          <span className="cust-card__title">Best customers</span>
          <span className="cust-card__hint">by spend, last 30 days</span>
        </div>

        {top.length === 0 ? (
          <EmptyState title="No orders in this period" hint="Come back once you have sales." />
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Contact</th>
                <th>Orders</th>
                <th>Spent</th>
                <th>Last order</th>
                <th>Wallet</th>
              </tr>
            </thead>
            <tbody>
              {top.map((customer) => (
                <tr key={customer.uuid}>
                  <td>{customer.customer_name || '—'}</td>
                  <td>{customer.mobile ? <ContactLinks mobile={customer.mobile} /> : '—'}</td>
                  <td>{customer.order_count}</td>
                  <td>{formatMoney(customer.total_spent)}</td>
                  <td>{String(customer.last_order_date || '').slice(0, 10)}</td>
                  <td>
                    <div className={Number(customer.wallet_balance) > 0 ? 'cust-wallet-balance' : 'cust-wallet-balance cust-wallet-balance--zero'}>
                      {formatMoney(customer.wallet_balance)}
                    </div>
                    {customer.wallet_frozen && <span className="cust-frozen">Frozen</span>}
                    {' '}
                    <Link to={`/admin/wallets?customer_uuid=${encodeURIComponent(customer.uuid)}`}>Manage →</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        <div className="cust-card__footer">
          Mobile numbers are partly hidden. A report that gets shared or screenshotted
          should not carry a column of whole phone numbers.
        </div>
      </div>

      <div className="cust-card">
        <div className="cust-card__header"><span className="cust-card__title">Reaching customers</span></div>
        <p className="cust-note">
          Order updates, payment receipts and dispatch notices go out
          automatically and cannot be switched off — they are part of the order.
        </p>
        <p className="cust-note">
          <b>Offers and new-arrival announcements are different.</b> They may only
          go to customers who have not opted out and whose number is not on the
          national Do Not Disturb register. The platform enforces both, and holds
          promotional messages outside 9am–9pm.
        </p>
        <div className="admin-alert admin-alert--warning">
          <b>Bulk offer announcements are not built yet.</b> The message templates
          and the consent rules exist; what is missing is the screen to choose an
          audience and send. Until then, a promotional campaign has to be queued
          through the API.
        </div>
      </div>
    </div>
  );
}
