/**
 * Small pieces shared by the Admin Privilege Management sub-pages
 * (AccessControlUsers/Roles/Approvals/AuditLog/WelcomeBonus) — a plain
 * fixed-overlay modal (this app has no Bootstrap) and a status badge keyed
 * by an explicit tone rather than a status-string lookup, since several of
 * these screens show a numeric HTTP status code rather than a named status.
 */

export function Modal({ title, onClose, children, wide }) {
  return (
    <div className="ac-modal-backdrop" onClick={onClose}>
      <div className={`ac-modal ${wide ? 'ac-modal--wide' : ''}`} onClick={(e) => e.stopPropagation()}>
        <div className="ac-modal__header">
          <h2>{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="ac-modal__body">{children}</div>
      </div>
    </div>
  );
}

export function Badge({ tone, children }) {
  return <span className={`status-badge status-badge--${tone}`}>{children}</span>;
}

export function when(value) {
  return value ? String(value).slice(0, 16).replace('T', ' ') : '—';
}
