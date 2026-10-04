/**
 * Purchase returns: the business-wide list of goods sent back to vendors.
 * Creating one happens from a specific purchase order's own detail view
 * (purchase-inward.html?tab=history&uuid=… → "Return items to vendor") where
 * the items/quantities/batches actually available to return are known; this
 * screen is the history/browse view across every vendor and PO, same
 * "record lives where it happened, history is a separate index" split
 * page-purchase-inward.js's own Record/History tabs already use.
 */

import { api, mountConsole, showError, escapeHtml, formatMoney, emptyState } from './console.js?v=9';

let root = null;
let vendors = [];
let filterVendorUuid = '';

function row(r) {
  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(r.return_number)}</span>
        <div class="small text-muted">${escapeHtml(String(r.return_date || '').slice(0, 10))}</div>
      </td>
      <td class="small">${escapeHtml(r.vendor_name)}</td>
      <td class="small">
        <a class="text-decoration-none" href="purchase-inward.html?tab=history&uuid=${encodeURIComponent(r.purchase_order_uuid)}">${escapeHtml(r.po_number)}</a>
      </td>
      <td class="small">${escapeHtml(r.reason)}</td>
      <td class="text-end">${formatMoney(r.total_amount)}</td>
    </tr>`;
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Purchase returns</h1>
      <form class="d-flex gap-2" data-filter-form>
        <select class="form-select form-select-sm" name="vendor_uuid" style="width:14rem">
          <option value="">All vendors</option>
          ${vendors.map((v) => `<option value="${escapeHtml(v.uuid)}" ${v.uuid === filterVendorUuid ? 'selected' : ''}>${escapeHtml(v.name)}</option>`).join('')}
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
      </form>
    </div>

    <p class="text-muted small">To record a new return, open the purchase order it belongs to from
       <a href="purchase-inward.html?tab=history">Purchase Inward → History</a> and use "Return items to vendor".</p>

    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  root.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    filterVendorUuid = new FormData(event.currentTarget).get('vendor_uuid') || '';
    render();
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/purchase-returns', {
      vendor_uuid: filterVendorUuid || undefined,
      per_page: 50,
      direction: 'DESC',
    });
    const returns = response.data || [];

    if (returns.length === 0) {
      list.innerHTML = emptyState('No returns yet', 'Returns recorded against a purchase order will show up here.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Return</th><th>Vendor</th><th>Purchase order</th><th>Reason</th><th class="text-end">Amount</th></tr></thead>
          <tbody>${returns.map(row).join('')}</tbody>
        </table>
      </div>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

const mounted = await mountConsole('purchase-returns.html');

if (mounted) {
  root = mounted.root;

  try {
    vendors = (await api.get('/admin/vendors', { per_page: 200, active_only: true })).data || [];
  } catch { /* filter just shows no vendor options this time */ }

  render();
}
