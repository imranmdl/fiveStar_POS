import { useEffect, useState } from 'react';
import { useOutletContext, Link } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { StatCard, LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import { WeekComboChart, CollectionsDonut, TopProductsBars, StackedStatusBar } from './DashboardCharts.jsx';
import './Dashboard.css';

/**
 * Dashboard — ported from admin/assets/page-index.js.
 *
 * Simplified from the live console: no conic-gradient donuts, no inline SVG
 * line chart, no festival reminder modal, no collections/today drill-down
 * modals (see handback notes). Everything else — the "needs attention" gate,
 * the analytics role gate, the business-rule footnote — is preserved.
 */

// key in needs_attention -> [label, link]. Only entries with count > 0 are
// shown — a panel full of zeroes trains people to ignore it.
const ATTENTION = [
  ['unassigned_orders', 'Paid orders not yet assigned', '/admin/orders?status=confirmed'],
  ['overdue_assignments', 'Assignments past their due time', '/admin/orders'],
  ['delivery_problems', 'Deliveries that failed or are returning', '/admin/shipments?tab=shipments'],
  ['bulk_enquiries_waiting', 'Wholesale enquiries awaiting a quote', null],
  ['commission_awaiting_approval', 'Commission entries to approve', null],
  ['expired_unpaid', 'Unpaid orders past their window', null],
  ['expiring_soon_stock', 'Batches expiring within 15 days', '/admin/inventory?tab=expiry'],
  ['out_of_stock_count', 'Pack sizes out of stock', '/admin/inventory?tab=alerts&status=out'],
  ['low_stock_count', 'Pack sizes at or below their minimum level', '/admin/inventory?tab=alerts&status=low'],
  ['overdue_dues_count', 'Customer dues overdue for collection', '/admin/customer-dues?status=overdue'],
];

const REASON_LABEL = { slow_moving: 'Slow-moving', near_expiry: 'Near expiry' };

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
}

/**
 * Setup problems that silently break customer flows — today: text messages.
 * With SMS off, customers are told nothing (or, before this, wrongly told a
 * code was sent) and can't confirm orders or sign in by OTP. Administrators
 * only (GET /admin/settings); other roles just don't see it.
 */
function SetupWarnings() {
  const [settings, setSettings] = useState(null);

  useEffect(() => {
    api.get('/admin/settings').then((response) => setSettings(response.data)).catch(() => setSettings(null));
  }, []);

  if (!settings) return null;
  const warnings = [];

  if (settings.sms_configured === false && !settings.otp_shown_on_screen) {
    warnings.push(
      <>
        <b>Text messages are switched off.</b> Customers don&apos;t receive OTP codes, so they can&apos;t confirm orders or
        sign in with OTP. Add your SMS provider on the server (<code>SMS_DRIVER=http</code> plus <code>SMS_ENDPOINT</code>,{' '}
        <code>SMS_API_KEY</code>, <code>SMS_SENDER_ID</code>, <code>SMS_DLT_TEMPLATE_ID</code>) and redeploy.
      </>,
    );
  }
  if (settings.otp_shown_on_screen) {
    warnings.push(
      <>
        <b>Test mode: OTP codes are shown on screen</b> instead of being texted. Remove <code>OTP_EXPOSE_IN_RESPONSE</code>{' '}
        from the server before real customers use the shop.
      </>,
    );
  }
  if (warnings.length === 0) return null;

  return (
    <div className="dash-card dash-card--attention" style={{ marginBottom: 16 }}>
      <div className="dash-card__header">Shop setup</div>
      <ul className="attention-list">
        {warnings.map((w, i) => (
          <li key={i} className="attention-list__item" style={{ alignItems: 'flex-start', lineHeight: 1.45 }}>
            <span className="attention-list__dot" style={{ marginTop: 6 }} />
            <span className="attention-list__label">{w}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function AttentionPanel({ counts }) {
  const live = ATTENTION.filter(([key]) => Number(counts[key] || 0) > 0);

  if (live.length === 0) {
    return (
      <div className="dash-card dash-attention-empty">
        <div className="dash-attention-empty__title">Nothing needs attention.</div>
        <div className="dash-attention-empty__hint">No unassigned orders, overdue work or delivery problems.</div>
      </div>
    );
  }

  return (
    <div className="dash-card dash-card--attention">
      <div className="dash-card__header">Needs attention</div>
      <ul className="attention-list">
        {live.map(([key, label, href]) => (
          <li key={key} className="attention-list__item">
            <span className="attention-list__dot" />
            <span className="attention-list__label">{label}</span>
            <span className="attention-list__count">{counts[key]}</span>
            {href && (
              <Link className="admin-btn" to={href}>View</Link>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

function CollectionsPanel({ collections }) {
  return <CollectionsDonut collections={collections} />;
}

function PipelinePanel({ pipeline }) {
  if (!pipeline.length) {
    return <p className="dash-muted">No paid orders in progress.</p>;
  }

  const totalValue = pipeline.reduce((sum, row) => sum + Number(row.value || 0), 0);

  return (
    <>
      <div className="dash-muted dash-muted--spaced">{formatMoney(totalValue)} total value in progress</div>
      <StackedStatusBar pipeline={pipeline} />
    </>
  );
}

function LastSevenDaysPanel({ series }) {
  if (!series.length) {
    return <p className="dash-muted">No sales in the last seven days.</p>;
  }

  const weekTotal = series.reduce((sum, d) => sum + (Number(d.gross_sales) || 0), 0);

  return (
    <>
      <div className="dash-muted dash-muted--spaced">7-day total: {formatMoney(weekTotal)}</div>
      <WeekComboChart series={series} />
    </>
  );
}

function TopProductsPanel({ products }) {
  if (!products.length) {
    return <p className="dash-muted">No sales in the last seven days.</p>;
  }

  return <TopProductsBars products={products} />;
}

function RecommendedOffersPanel({ items }) {
  if (!items.length) {
    return <p className="dash-muted">Nothing needs a push right now.</p>;
  }

  return (
    <table className="admin-table">
      <thead>
        <tr><th>Product</th><th>Why</th><th>Suggested</th><th></th></tr>
      </thead>
      <tbody>
        {items.slice(0, 8).map((item, index) => (
          <tr key={`${item.product_name}-${index}`}>
            <td>
              <span className="dash-offer-name">{item.product_name}</span>{' '}
              <span className="dash-muted">({item.variant_name})</span>
              {item.urgency === 'high' && <span className="dash-urgent">Urgent</span>}
              <div className="dash-muted">{item.detail}</div>
            </td>
            <td>{REASON_LABEL[item.reason] || item.reason}</td>
            <td>{item.suggested_discount_value}% off</td>
            <td>
              <Link
                className="admin-btn"
                to={`/admin/promotions?new_offer=1&title=${encodeURIComponent(`${item.product_name} — Special offer`)}&discount_value=${encodeURIComponent(item.suggested_discount_value)}`}
              >
                Create offer
              </Link>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export default function Dashboard() {
  const { user } = useOutletContext();
  const [state, setState] = useState({
    loading: true, error: null, data: null, topProducts: null, recommendations: null,
  });

  useEffect(() => {
    let active = true;

    async function load() {
      try {
        // /admin/reports/products and /admin/offers/recommendations are
        // administrator/supervisor only (see $supervisory in
        // routes/api_v1.php) — fetched only for those roles so other roles
        // never issue a call they'll get a 403 for, and wrapped so a genuine
        // failure just hides the analytics card instead of breaking the rest
        // of the dashboard.
        const canSeeAnalytics = ['administrator', 'supervisor'].includes(String(user?.role));
        const today = new Date().toISOString().slice(0, 10);
        const weekAgo = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);

        const [response, productsResponse, recommendationsResponse] = await Promise.all([
          api.get('/admin/dashboard'),
          canSeeAnalytics
            ? api.get('/admin/reports/products', { from: weekAgo, to: today }).catch(() => null)
            : Promise.resolve(null),
          canSeeAnalytics
            ? api.get('/admin/offers/recommendations').catch(() => null)
            : Promise.resolve(null),
        ]);

        if (!active) return;

        setState({
          loading: false,
          error: null,
          data: response.data,
          topProducts: productsResponse ? (productsResponse.data.products || []) : null,
          recommendations: recommendationsResponse ? (recommendationsResponse.data.recommendations || []) : null,
        });
      } catch (error) {
        if (!active) return;
        setState({ loading: false, error, data: null, topProducts: null, recommendations: null });
      }
    }

    load();
    return () => { active = false; };
  }, [user?.role]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState error={state.error} />;

  const data = state.data || {};
  const today = data.today || {};
  const profitLoss = data.profit_loss || {};
  const wallet = data.wallet || {};
  const customerDues = data.customer_dues || {};
  const pendingRefunds = wallet.pending_refunds || {};

  return (
    <div>
      <div className="dash-header">
        <div>
          <div className="dash-eyebrow">{greeting()}</div>
          <h1 className="admin-page-title">Dashboard</h1>
        </div>
        <div className="dash-date">
          {new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
        </div>
      </div>

      {user && user.role === 'administrator' && <SetupWarnings />}
      <AttentionPanel counts={data.needs_attention || {}} />

      <div className="dash-section-label">Today's Overview</div>
      <div className="stat-grid">
        <StatCard
          label="Orders today"
          value={today.orders ?? 0}
          hint={`Online ${today.online_orders ?? 0} · Counter ${today.pos_orders ?? 0}`}
        />
        <StatCard
          label="Revenue today"
          value={formatMoney(today.revenue)}
          hint={`Online ${formatMoney(today.online_revenue)} · Counter ${formatMoney(today.pos_revenue)}`}
          tone="indigo"
        />
        <StatCard label="Delivered today" value={today.delivered ?? 0} tone="success" />
        <StatCard
          label="Cancelled today"
          value={today.cancelled ?? 0}
          tone={Number(today.cancelled) > 0 ? 'danger' : undefined}
        />
      </div>

      <div className="dash-section-label">Profit &amp; Loss <span className="dash-muted">today's figures</span></div>
      <div className="stat-grid">
        <StatCard label="Today's sales" value={formatMoney(profitLoss.sales)} hint="Online + POS" />
        <StatCard
          label="Profit (est.)"
          value={formatMoney(profitLoss.profit)}
          hint="Against current avg. cost"
          tone={Number(profitLoss.profit) < 0 ? 'danger' : undefined}
        />
        <StatCard
          label="Loss"
          value={formatMoney(profitLoss.loss)}
          hint="Damaged, lost, expired today"
          tone={Number(profitLoss.loss) > 0 ? 'danger' : undefined}
        />
        <StatCard label="Total collection" value={formatMoney(profitLoss.total_collection)} hint="Money actually in hand" />
      </div>

      <div className="dash-card">
        <div className="dash-card__header">Collections by payment type</div>
        <CollectionsPanel collections={data.collections} />
      </div>

      <div className="dash-card">
        <div className="dash-card__header dash-card__header--split">
          <span>Wallets</span>
          <Link className="dash-card__link" to="/admin/wallets">Manage wallets →</Link>
        </div>
        <div className="stat-grid">
          <StatCard label="Total wallet balance" value={formatMoney(wallet.total_balance)} hint={`${wallet.account_count ?? 0} account(s)`} tone="teal" />
          <StatCard label="Total refund credits" value={formatMoney(wallet.total_refund_credits)} tone="teal" />
          <StatCard label="Total wallet credits" value={formatMoney(wallet.total_credits)} tone="teal" />
          <StatCard label="Total wallet debits" value={formatMoney(wallet.total_debits)} />
          <StatCard
            label="Pending refunds"
            value={formatMoney(pendingRefunds.amount)}
            hint={`${pendingRefunds.count ?? 0} order(s)`}
            tone={Number(pendingRefunds.count) > 0 ? 'danger' : undefined}
          />
        </div>
      </div>

      <div className="dash-card">
        <div className="dash-card__header dash-card__header--split">
          <span>Customer Dues</span>
          <Link className="dash-card__link" to="/admin/customer-dues">Manage dues →</Link>
        </div>
        <div className="stat-grid">
          <StatCard label="Total due" value={formatMoney(customerDues.total_due)} tone="terracotta" />
          <StatCard label="Partially paid" value={customerDues.partially_paid_count ?? 0} tone="terracotta" />
          <StatCard label="Fully paid" value={customerDues.fully_paid_count ?? 0} />
          <StatCard
            label="Overdue"
            value={customerDues.overdue_count ?? 0}
            hint={formatMoney(customerDues.overdue_amount)}
            tone={Number(customerDues.overdue_count) > 0 ? 'danger' : undefined}
          />
          <StatCard label="Collected today" value={formatMoney(customerDues.collected_today)} />
        </div>
      </div>

      <div className="dash-grid-2">
        <div className="dash-card">
          <div className="dash-card__header">In progress</div>
          <PipelinePanel pipeline={data.pipeline || []} />
        </div>
        <div className="dash-card">
          <div className="dash-card__header">Last seven days</div>
          <LastSevenDaysPanel series={data.last_7_days || []} />
        </div>
      </div>

      {state.topProducts && (
        <>
          <div className="dash-section-label">Analytics <span className="dash-muted">top products, last 7 days</span></div>
          <div className="dash-card">
            <div className="dash-card__header">Top products by revenue</div>
            <TopProductsPanel products={state.topProducts} />
          </div>
        </>
      )}

      {state.recommendations && (
        <>
          <div className="dash-section-label">Recommended Offers <span className="dash-muted">slow-moving &amp; near-expiry stock</span></div>
          <div className="dash-card">
            <div className="dash-card__header">Suggestions — nothing here is applied automatically</div>
            <RecommendedOffersPanel items={state.recommendations} />
          </div>
        </>
      )}

      <p className="dash-footnote">
        Revenue counts confirmed, non-cancelled orders only. An order that has been
        placed but not paid for is not revenue. Profit is estimated against each
        item's current average cost, not a historical snapshot — treat it as a
        same-day approximation, not a ledger figure. Total collection is money
        actually in hand today: paid online orders plus completed POS sales.
      </p>
    </div>
  );
}
