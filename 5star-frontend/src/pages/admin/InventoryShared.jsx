/**
 * Shared bits for the Inventory screen's tabs — role gating, the warehouse
 * picker, a tone-coloured tag, quantity formatting and a pagination strip.
 * Split out so every tab file (InventoryStock, InventoryAlerts, ...) can
 * reuse them without re-deriving the rules from the live page-inventory.js.
 *
 * ROLE GATING — mirrors spice-api/backend/routes/api_v1.php exactly:
 *   - $inventoryStaff = administrator, supervisor, manager, inventory_staff,
 *     executive: every GET list/report on this page, plus raising a request
 *     via POST /admin/inventory/adjustments/request-approval.
 *   - $manager = administrator, supervisor, manager: the endpoints that
 *     actually change stock or its configuration — POST .../adjust, PATCH
 *     .../reorder-threshold, POST deleted/{uuid}/restore, POST
 *     /admin/imports/confirm.
 *   - administrator only: DELETE deleted/{uuid} (permanent).
 * cashier has none of the above — this page is not reachable usefully for
 * that role (it's covered by the Till instead).
 */
export const VIEW_ROLES = ['administrator', 'supervisor', 'manager', 'inventory_staff', 'executive'];
export const MANAGE_ROLES = ['administrator', 'supervisor', 'manager'];

export function canViewInventory(role) {
  return VIEW_ROLES.includes(role);
}

/** Can adjust stock directly, set reorder thresholds, restore from the bin, confirm an import. */
export function canManageStock(role) {
  return MANAGE_ROLES.includes(role);
}

export function isAdministrator(role) {
  return role === 'administrator';
}

/** A coloured pill using shared.css's existing status-badge classes, for values StatusBadge's own STATUS_TONE map doesn't know (movement types, expiry status). */
export function Tag({ tone, children }) {
  return <span className={`status-badge status-badge--${tone || 'secondary'}`}>{children}</span>;
}

export function qty(value) {
  return Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
}

export function fmtDateTime(value) {
  return String(value || '').slice(0, 16).replace('T', ' ');
}

export function fmtDate(value) {
  return String(value || '').slice(0, 10);
}

export function WarehouseSelect({ warehouses, value, onChange, ariaLabel }) {
  return (
    <select className="inv-select" aria-label={ariaLabel || 'Warehouse'} value={value} onChange={onChange}>
      <option value="">All warehouses</option>
      {warehouses.map((w) => (
        <option key={w.uuid} value={w.uuid}>{w.name}</option>
      ))}
    </select>
  );
}

export function Pagination({ meta, page, onPrev, onNext }) {
  if (!meta || meta.total_pages <= 1) return null;
  return (
    <div className="inv-pagination">
      <span className="small-muted">Page {meta.page} of {meta.total_pages}{meta.total !== undefined ? ` · ${meta.total} item(s)` : ''}</span>
      <span>
        <button type="button" className="admin-btn" disabled={page <= 1} onClick={onPrev}>Previous</button>{' '}
        <button type="button" className="admin-btn" disabled={page >= meta.total_pages} onClick={onNext}>Next</button>
      </span>
    </div>
  );
}

export function reportError(error) {
  const messages = error && error.fieldMessages ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : ((error && error.message) || 'Something went wrong.');
  return text;
}

export const MOVEMENT_TYPES = ['inward', 'sale', 'return', 'damage', 'lost', 'adjustment', 'transfer_in', 'transfer_out'];

export const MOVEMENT_TONE = {
  inward: 'success',
  return: 'success',
  sale: 'primary',
  damage: 'danger',
  lost: 'danger',
  adjustment: 'warning',
  transfer_in: 'info',
  transfer_out: 'secondary',
};
