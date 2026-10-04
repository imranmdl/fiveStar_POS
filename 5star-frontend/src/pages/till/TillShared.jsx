import { ApiError } from '../../lib/api';

/**
 * Small self-contained pieces shared across the Till's own components.
 * Deliberately separate from src/components/admin/shared.jsx — this page
 * has no AdminLayout wrapper and isn't meant to depend on anything owned
 * by the admin console port.
 */

/** Staff get full detail — the server's message plus field errors. */
export function ErrorBanner({ error }) {
  if (!error) return null;

  if (!(error instanceof ApiError)) {
    return (
      <div className="till-alert till-alert--danger">
        <div className="till-alert__title">Cannot reach the API.</div>
        <div className="till-alert__text">Check that the backend is reachable.</div>
      </div>
    );
  }

  const messages = error.fieldMessages ? error.fieldMessages() : [];

  return (
    <div className="till-alert till-alert--danger">
      <div className="till-alert__title">{error.message}</div>
      {messages.length > 0 && (
        <ul className="till-alert__list">
          {messages.map((message, index) => <li key={index}>{message}</li>)}
        </ul>
      )}
    </div>
  );
}

export function LoadingState({ label }) {
  return <div className="till-loading">{label || 'Loading…'}</div>;
}

/** A single floating toast — auto-dismissed by the caller (see Till.jsx's notify()). */
export function Toast({ toast }) {
  if (!toast) return null;
  return <div className={`till-toast till-toast--${toast.tone}`}>{toast.text}</div>;
}
