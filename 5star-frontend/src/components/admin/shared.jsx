import { ApiError } from '../../lib/api';
import './shared.css';

/** Status colours, shared so a status never means two things (ported from admin/console.js). */
export const STATUS_TONE = {
  created: 'secondary',
  awaiting_payment: 'warning',
  confirmed: 'primary',
  packed: 'primary',
  ready: 'primary',
  assigned: 'info',
  shipped: 'info',
  out_for_delivery: 'info',
  delivered: 'success',
  cancelled: 'secondary',
  returned: 'warning',
  refunded: 'secondary',
  pending: 'warning',
  paid: 'success',
  failed: 'danger',
  open: 'warning',
  in_progress: 'primary',
  awaiting_customer: 'info',
  resolved: 'success',
  closed: 'secondary',
  approved: 'success',
  rejected: 'danger',
  hidden: 'warning',
};

export function StatusBadge({ status, label }) {
  const tone = STATUS_TONE[status] || 'secondary';
  return <span className={`status-badge status-badge--${tone}`}>{label || status}</span>;
}

export function StatCard({ label, value, hint, tone }) {
  return (
    <div className={`stat-card ${tone ? `stat-card--${tone}` : ''}`}>
      <div className="stat-card__label">{label}</div>
      <div className="stat-card__value">{value}</div>
      {hint && <div className="stat-card__hint">{hint}</div>}
    </div>
  );
}

export function EmptyState({ title, hint }) {
  return (
    <div className="empty-state">
      <p className="empty-state__title">{title}</p>
      {hint && <p className="empty-state__hint">{hint}</p>}
    </div>
  );
}

export function LoadingState() {
  return <div className="loading-state">Loading…</div>;
}

/** Staff get full detail — the server's message plus field errors and the HTTP status. */
export function ErrorState({ error }) {
  if (!(error instanceof ApiError)) {
    return (
      <div className="admin-alert admin-alert--danger">
        <div className="fw-semibold">Cannot reach the API.</div>
        <div className="small">Check that the backend is reachable and the dev proxy in vite.config.js is pointed at it.</div>
      </div>
    );
  }

  const messages = error.fieldMessages ? error.fieldMessages() : [];

  return (
    <div className="admin-alert admin-alert--danger">
      <div className="fw-semibold">{error.message}</div>
      {messages.length > 0 && (
        <ul>
          {messages.map((message, index) => (
            <li key={index}>{message}</li>
          ))}
        </ul>
      )}
      <div className="small">HTTP {error.status}</div>
    </div>
  );
}
