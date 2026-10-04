import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../../lib/api';
import { LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import PurchaseInwardRecordForm from './PurchaseInwardRecordForm.jsx';
import PurchaseInwardHistory from './PurchaseInwardHistory.jsx';
import './PurchaseInward.css';

const TABS = [
  ['record', 'Record inward'],
  ['history', 'History'],
];

/**
 * Purchase Inward — ported from admin/assets/page-purchase-inward.js (+ its
 * inward-new-item.js, inward-csv.js and pricing-decisions.js helpers, each
 * split into its own co-located file here).
 *
 * One guided flow from "who did we buy this from" through to "what does
 * this stock actually cost us landed, and have we paid for it": record what
 * was bought from a vendor (creating the vendor right here if it's a new
 * one), list or create the items, apply a transportation charge as a
 * landing-cost percentage, and track what's been paid.
 *
 * Saving posts inventory movements immediately — there is no draft/receive
 * step. A History tab lists past purchase orders, with a detail view for
 * payment/return follow-up.
 */
export default function PurchaseInward() {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = searchParams.get('tab') || 'record';

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [vendors, setVendors] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [inventorySetup, setInventorySetup] = useState({ categories: [], option_types: [] });

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const [vendorResponse, warehouseResponse, setupResponse] = await Promise.all([
        api.get('/admin/vendors', { per_page: 200, active_only: true }),
        api.get('/admin/warehouses', { active_only: true }),
        api.get('/admin/inventory/setup').catch(() => ({ data: { categories: [], option_types: [] } })),
      ]);
      setVendors(vendorResponse.data || []);
      setWarehouses(warehouseResponse.data || []);
      setInventorySetup(setupResponse.data || { categories: [], option_types: [] });
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  function switchTab(next) {
    const params = new URLSearchParams(searchParams);
    params.set('tab', next);
    params.delete('uuid');
    setSearchParams(params);
  }

  function afterSave() {
    setSearchParams({ tab: 'history' });
  }

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;

  return (
    <div className="pi">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Purchase Inward</h1>
        <div className="pi-tabs">
          {TABS.map(([key, label]) => (
            <button
              key={key}
              type="button"
              className={`admin-btn ${tab === key ? 'admin-btn--primary' : ''}`}
              onClick={() => switchTab(key)}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {tab === 'history' ? (
        <PurchaseInwardHistory vendors={vendors} />
      ) : (
        <PurchaseInwardRecordForm
          vendors={vendors}
          warehouses={warehouses}
          inventorySetup={inventorySetup}
          onVendorCreated={(vendor) => setVendors((prev) => [...prev, vendor])}
          onCategoryCreated={(category) => setInventorySetup((prev) => ({ ...prev, categories: [...prev.categories, category] }))}
          onSaved={afterSave}
        />
      )}
    </div>
  );
}
