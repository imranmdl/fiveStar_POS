import { STATUS_TONE } from '../../components/admin/shared.jsx';
import { formatMoney } from '../../lib/api';
import './DashboardCharts.css';

const TONE_COLOR = {
  secondary: 'var(--ink-500)',
  warning: 'var(--warning)',
  primary: 'var(--info)',
  info: 'var(--info)',
  success: 'var(--success)',
  danger: 'var(--danger)',
};

function dayLabel(iso) {
  const d = new Date(`${iso}T00:00:00`);
  return d.toLocaleDateString('en-IN', { weekday: 'short' });
}

/** Smooth cubic-bezier path through a set of points — an even curve instead of sharp line segments. */
function smoothPath(points) {
  if (points.length < 2) return '';
  let d = `M${points[0][0].toFixed(1)} ${points[0][1].toFixed(1)}`;
  for (let i = 0; i < points.length - 1; i++) {
    const [x0, y0] = points[i];
    const [x1, y1] = points[i + 1];
    const dx = (x1 - x0) / 3;
    d += ` C${(x0 + dx).toFixed(1)} ${y0.toFixed(1)}, ${(x1 - dx).toFixed(1)} ${y1.toFixed(1)}, ${x1.toFixed(1)} ${y1.toFixed(1)}`;
  }
  return d;
}

/**
 * Last 7 days, two metrics in one glance: muted orders-count bars behind a
 * smooth revenue curve in front — both independently scaled to the chart's
 * height, so this reads "shape over time", not a precise dual axis.
 */
export function WeekComboChart({ series }) {
  if (!series || series.length === 0) return null;

  const width = 680;
  const height = 220;
  const padX = 10;
  const topPad = 34;
  const bottomPad = 28;
  const plotH = height - topPad - bottomPad;

  const revenue = series.map((d) => Number(d.gross_sales) || 0);
  const orders = series.map((d) => Number(d.orders) || 0);
  const maxRevenue = Math.max(...revenue, 1);
  const maxOrders = Math.max(...orders, 1);
  const todayIso = new Date().toISOString().slice(0, 10);
  const todayIndex = series.findIndex((d) => d.date === todayIso);
  const peakIndex = revenue.indexOf(Math.max(...revenue));

  const slot = (width - padX * 2) / series.length;
  const cx = (i) => padX + slot * (i + 0.5);
  const barW = Math.min(slot * 0.46, 30);

  const yRevenue = (v) => topPad + plotH - (v / maxRevenue) * plotH;
  const points = revenue.map((v, i) => [cx(i), yRevenue(v)]);
  const linePath = smoothPath(points);
  const areaPath = `${linePath} L${points[points.length - 1][0].toFixed(1)} ${topPad + plotH} L${points[0][0].toFixed(1)} ${topPad + plotH} Z`;

  const highlight = points[peakIndex];

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="dash-chart-svg dash-chart-svg--combo" role="img" aria-label="Orders and gross sales over the last 7 days">
      <defs>
        <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--brand-marigold)" stopOpacity="0.4" />
          <stop offset="100%" stopColor="var(--brand-marigold)" stopOpacity="0" />
        </linearGradient>
      </defs>

      {[0, 0.5, 1].map((f) => (
        <line key={f} x1={padX} x2={width - padX} y1={topPad + f * plotH} y2={topPad + f * plotH} className="dash-chart-grid" />
      ))}

      {/* Orders — quiet bars behind the revenue curve */}
      {orders.map((v, i) => {
        const h = Math.max((v / maxOrders) * plotH * 0.62, 4);
        return (
          <rect
            key={`bar-${series[i].date}`}
            x={cx(i) - barW / 2}
            y={topPad + plotH - h}
            width={barW}
            height={h}
            rx={4}
            className={i === todayIndex ? 'dash-combo-bar dash-combo-bar--today' : 'dash-combo-bar'}
          />
        );
      })}

      {/* Revenue — the smooth highlighted curve on top */}
      <path d={areaPath} fill="url(#revenueFill)" />
      <path d={linePath} className="dash-chart-line" fill="none" />

      {highlight && (
        <text x={highlight[0]} y={Math.max(highlight[1] - 10, 12)} textAnchor="middle" className="dash-combo-peak-label">
          {formatMoney(revenue[peakIndex])}
        </text>
      )}

      {points.map(([px, py], i) => {
        const isToday = i === todayIndex;
        return (
          <g key={series[i].date}>
            <circle cx={px} cy={py} r={isToday ? 5 : 3} className={isToday ? 'dash-chart-dot dash-chart-dot--today' : 'dash-chart-dot'} />
            <text x={px} y={height - 6} textAnchor="middle" className="dash-chart-axis-label">
              {dayLabel(series[i].date)}
            </text>
          </g>
        );
      })}

      <g className="dash-combo-legend" transform={`translate(${width - 190}, 10)`}>
        <circle cx={6} cy={0} r={4} className="dash-chart-dot" />
        <text x={16} y={4} className="dash-chart-axis-label">Revenue</text>
        <rect x={80} y={-4} width={10} height={8} rx={2} className="dash-combo-bar" />
        <text x={96} y={4} className="dash-chart-axis-label">Orders</text>
      </g>
    </svg>
  );
}

const DONUT_COLORS = ['var(--brand-forest)', 'var(--brand-marigold)', 'var(--brand-terracotta)', 'var(--brand-teal)'];

/** A donut split by payment collection type, with a legend. */
export function CollectionsDonut({ collections }) {
  const labels = { cash: 'Cash', upi: 'UPI', pos: 'POS (card)', other: 'Other' };
  const entries = Object.entries(collections || {})
    .map(([key, bucket]) => ({ key, label: labels[key] || key, amount: Number(bucket?.amount) || 0 }))
    .filter((e) => e.amount > 0);

  const total = entries.reduce((sum, e) => sum + e.amount, 0);

  if (total === 0) {
    return <p className="dash-muted">Nothing collected yet today.</p>;
  }

  const r = 54;
  const circumference = 2 * Math.PI * r;
  let offsetSoFar = 0;

  const segments = entries.map((e, i) => {
    const fraction = e.amount / total;
    const dash = fraction * circumference;
    const seg = {
      ...e,
      fraction,
      color: DONUT_COLORS[i % DONUT_COLORS.length],
      dasharray: `${dash} ${circumference - dash}`,
      dashoffset: -offsetSoFar,
    };
    offsetSoFar += dash;
    return seg;
  });

  return (
    <div className="dash-donut-row">
      <svg viewBox="0 0 140 140" className="dash-donut" role="img" aria-label="Today's collections by payment method">
        <circle cx="70" cy="70" r={r} fill="none" stroke="var(--border)" strokeWidth="18" />
        {segments.map((seg) => (
          <circle
            key={seg.key}
            cx="70"
            cy="70"
            r={r}
            fill="none"
            stroke={seg.color}
            strokeWidth="18"
            strokeDasharray={seg.dasharray}
            strokeDashoffset={seg.dashoffset}
            transform="rotate(-90 70 70)"
          />
        ))}
        <text x="70" y="66" textAnchor="middle" className="dash-donut-total-label">
          Total
        </text>
        <text x="70" y="84" textAnchor="middle" className="dash-donut-total-value">
          {total >= 1000 ? `₹${(total / 1000).toFixed(1)}k` : `₹${total.toFixed(0)}`}
        </text>
      </svg>
      <ul className="dash-donut-legend">
        {segments.map((seg) => (
          <li key={seg.key}>
            <span className="dash-donut-legend__dot" style={{ background: seg.color }} />
            <span className="dash-donut-legend__label">{seg.label}</span>
            <span className="dash-donut-legend__value">{Math.round(seg.fraction * 100)}%</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * Orders in progress as ONE segmented bar (proportional by value) plus a chip
 * legend underneath — reads as a distribution at a glance, rather than a
 * list of separate bars each competing for attention.
 */
export function StackedStatusBar({ pipeline }) {
  if (!pipeline || pipeline.length === 0) return null;

  const total = pipeline.reduce((sum, row) => sum + (Number(row.value) || 0), 0) || 1;

  return (
    <div className="dash-stacked">
      <div className="dash-stacked__bar">
        {pipeline.map((row) => {
          const value = Number(row.value) || 0;
          const pct = (value / total) * 100;
          const color = TONE_COLOR[STATUS_TONE[row.status]] || 'var(--brand-forest)';
          return (
            <div
              key={row.status}
              className="dash-stacked__segment"
              style={{ width: `${Math.max(pct, 1.5)}%`, background: color }}
              title={`${row.status.replace(/_/g, ' ')}: ${formatMoney(value)}`}
            />
          );
        })}
      </div>
      <div className="dash-stacked__legend">
        {pipeline.map((row) => {
          const color = TONE_COLOR[STATUS_TONE[row.status]] || 'var(--brand-forest)';
          return (
            <div key={row.status} className="dash-stacked__chip">
              <span className="dash-stacked__dot" style={{ background: color }} />
              <span className="dash-stacked__chip-label">{row.status.replace(/_/g, ' ')}</span>
              <span className="dash-stacked__chip-count">×{row.count}</span>
              <span className="dash-stacked__chip-value">{formatMoney(row.value)}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** Horizontal bars — top products by revenue share. Plain CSS, no SVG needed. */
export function TopProductsBars({ products }) {
  if (!products || products.length === 0) return null;

  const top = products.slice(0, 5);
  const max = Math.max(...top.map((p) => Number(p.revenue) || 0), 1);

  return (
    <div className="dash-bars">
      {top.map((p) => {
        const value = Number(p.revenue) || 0;
        const pct = Math.max((value / max) * 100, 3);
        return (
          <div key={p.product_name} className="dash-bars__row">
            <div className="dash-bars__label">{p.product_name}</div>
            <div className="dash-bars__track">
              <div className="dash-bars__fill" style={{ width: `${pct}%` }} />
            </div>
            <div className="dash-bars__value">₹{Math.round(value).toLocaleString('en-IN')}</div>
          </div>
        );
      })}
    </div>
  );
}
