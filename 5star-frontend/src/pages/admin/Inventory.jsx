/**
 * Inventory: stock by warehouse, the movement ledger, damage/loss and
 * expiry tracking, low-stock alerts, bulk CSV import and the recycle bin.
 *
 * Ported from the live admin/assets/page-inventory.js (read in full, not
 * skimmed) — business rules, endpoints and filters are carried over as-is;
 * only the rendering is React instead of innerHTML templates, and modals
 * are plain overlay panels since this app has no Bootstrap (see
 * InventoryModals.jsx). Split across files because the source is 1255
 * lines: this file is the shell + tab switcher, each tab is its own
 * component, and the three "modals" (Trace, Price history, Adjust) live in
 * InventoryModals.jsx since Trace/Price history/Adjust are all opened from
 * more than one tab.
 *
 * CRITICAL BUSINESS RULE — manual stock adjustment requires approval for
 * everyone except manager-tier staff. Confirmed from
 * spice-api/backend/app/Controllers/Api/V1/InventoryController.php and
 * routes/api_v1.php, not assumed from the old UI (which only ever called
 * the direct endpoint — see the note in InventoryModals.jsx's AdjustModal):
 *   - POST /admin/inventory/adjust (immediate — changes `quantity` right
 *     away) is restricted to administrator/supervisor/manager.
 *   - POST /admin/inventory/adjustments/request-approval (raises a
 *     `pending` row for ApprovalService; changes nothing itself) is open to
 *     inventory_staff and executive as well.
 *   Approving/rejecting that pending request is a DIFFERENT screen's job —
 *   Admin Privilege Management (access-control.html's port) — not
 *   duplicated here.
 *
 * Tab state and the Alerts tab's `status` live in the URL (?tab=&status=)
 * so Dashboard's existing deep links (e.g. inventory.html?tab=alerts&status=out)
 * keep working as /admin/inventory?tab=alerts&status=out.
 */
import { useEffect, useState } from 'react';
import { useOutletContext, useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState } from '../../components/admin/shared';
import { canViewInventory, reportError } from './InventoryShared';
import InventoryStock from './InventoryStock';
import InventoryMovements from './InventoryMovements';
import InventoryDamageLoss from './InventoryDamageLoss';
import InventoryExpiry from './InventoryExpiry';
import InventoryAlerts from './InventoryAlerts';
import InventoryImport from './InventoryImport';
import InventoryDeleted from './InventoryDeleted';
import './Inventory.css';

const TABS = [
  ['stock', 'Stock'],
  ['movements', 'Movement ledger'],
  ['damage-loss', 'Damage & loss'],
  ['expiry', 'Expiry'],
  ['alerts', 'Low Stock Alerts'],
  ['import', 'Import CSV'],
  ['deleted', 'Recycle Bin'],
];

async function downloadCsv(path, fallbackName) {
  const blob = await api.downloadFile(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function ExportButton({ entity, label }) {
  const [busy, setBusy] = useState(false);

  async function handleClick() {
    setBusy(true);
    try {
      await downloadCsv(`/admin/export/${encodeURIComponent(entity)}`, `${entity}.csv`);
    } catch (error) {
      toast(reportError(error), 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <button type="button" className="admin-btn" disabled={busy} onClick={handleClick}>
      {busy ? 'Preparing…' : label}
    </button>
  );
}

export default function Inventory() {
  const { user } = useOutletContext();
  const role = user && user.role;
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = searchParams.get('tab') || 'stock';
  const [warehouses, setWarehouses] = useState([]);

  useEffect(() => {
    api.get('/admin/warehouses')
      .then((response) => setWarehouses(response.data || []))
      .catch(() => setWarehouses([]));
  }, []);

  function setTab(next) {
    const params = new URLSearchParams(searchParams);
    if (next === 'stock') params.delete('tab'); else params.set('tab', next);
    // Switching tabs explicitly starts that tab fresh — carrying over a
    // `status` meant for the Alerts tab onto another tab would be confusing.
    if (next !== 'alerts') params.delete('status');
    setSearchParams(params);
  }

  if (!canViewInventory(role)) {
    return (
      <div>
        <h1 className="admin-page-title">Inventory</h1>
        <EmptyState
          title="Access restricted"
          hint="Your role does not have access to inventory stock data. This is limited to administrators, supervisors, managers, inventory staff and executives."
        />
      </div>
    );
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Inventory</h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <ExportButton entity="inventory" label="Export stock (CSV)" />
          <ExportButton entity="inventory_batches" label="Export batches (CSV)" />
        </div>
      </div>

      <div className="inv-tabs" role="tablist" aria-label="Inventory views">
        {TABS.map(([value, label]) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={tab === value}
            className={`inv-tab ${tab === value ? 'inv-tab--active' : ''}`}
            onClick={() => setTab(value)}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'movements' && <InventoryMovements warehouses={warehouses} />}
      {tab === 'damage-loss' && <InventoryDamageLoss warehouses={warehouses} />}
      {tab === 'expiry' && <InventoryExpiry warehouses={warehouses} />}
      {tab === 'alerts' && <InventoryAlerts role={role} warehouses={warehouses} />}
      {tab === 'import' && <InventoryImport role={role} warehouses={warehouses} />}
      {tab === 'deleted' && <InventoryDeleted role={role} />}
      {tab === 'stock' && <InventoryStock role={role} warehouses={warehouses} />}
    </div>
  );
}
