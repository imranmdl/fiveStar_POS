/**
 * Vendors: who stock is purchased from. List/?new//?edit= shape like
 * warehouses.html, plus a dashboard strip and a ?history= view tying
 * together everything a vendor relationship touches — purchases, payments,
 * returns, and the pending balance that falls out of them.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney, queryParam,
         emptyState, iconStatCard, badge } from './console.js?v=9';

let root = null;
let search = '';

// Same small icon set the dashboard uses (page-index.js) — kept local here
// rather than exported from console.js, same reasoning iconStatCard's own
// doc comment gives: each page owns its icon shapes.
const VENDOR_ICONS = {
  truck: '<rect x="1" y="7" width="13" height="10" rx="1"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/>',
  box: '<path d="M4 7l8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  check: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5"/>',
  undo: '<path d="M3 7v6h6"/><path d="M3.5 13a8 8 0 1 0 2-8.4L3 7"/>',
};

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

/**
 * One panel, one entity picker — Download template / Export CSV always
 * available, Import CSV only shown for an entity that actually has an
 * import endpoint (vendors, today). Three separate button rows for
 * Vendors/Purchases/Transactions did the same job with three times the
 * visual weight; a dropdown says the same thing in the space of one.
 *
 * @param {Array<{value:string, label:string, importPath?:string}>} entities
 */
function importExportPanel(entities) {
  return `
    <div class="card mb-3"><div class="card-body py-2">
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <div>
          <label class="form-label small mb-0 text-muted">Data</label>
          <select class="form-select form-select-sm" data-ie-entity style="width:11rem">
            ${entities.map((e) => `
              <option value="${escapeHtml(e.value)}" data-import-path="${escapeHtml(e.importPath || '')}">${escapeHtml(e.label)}</option>`).join('')}
          </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary" data-ie-template type="button">Download template</button>
        <button class="btn btn-sm btn-outline-secondary" data-ie-export type="button">Export CSV</button>
        <label class="btn btn-sm btn-outline-secondary mb-0" data-ie-import-label>
          Import CSV
          <input type="file" accept=".csv,.xlsx" data-ie-import hidden>
        </label>
      </div>
    </div></div>`;
}

function bindImportExportPanel(container, onImported) {
  const select = container.querySelector('[data-ie-entity]');
  const importLabel = container.querySelector('[data-ie-import-label]');
  const importInput = container.querySelector('[data-ie-import]');

  const syncImportVisibility = () => {
    const importPath = select.selectedOptions[0].dataset.importPath;
    importLabel.hidden = !importPath;
  };
  select.addEventListener('change', syncImportVisibility);
  syncImportVisibility();

  container.querySelector('[data-ie-template]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const entity = select.value;
    setBusy(button, true, 'Preparing');
    try {
      await downloadCsv(`/admin/export/${encodeURIComponent(entity)}/template`, `${entity}_template.csv`);
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  container.querySelector('[data-ie-export]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const entity = select.value;
    setBusy(button, true, 'Preparing');
    try {
      await downloadCsv(`/admin/export/${encodeURIComponent(entity)}`, `${entity}.csv`);
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  importInput.addEventListener('change', async () => {
    const file = importInput.files[0];
    const importPath = select.selectedOptions[0].dataset.importPath;
    if (!file || !importPath) return;

    const formData = new FormData();
    formData.append('file', file);

    try {
      const result = await api.upload(importPath, formData);
      const { total, created, skipped } = result.data.summary;
      toast(`Imported ${created} of ${total} row(s)${skipped ? `, ${skipped} skipped` : ''}.`);

      const problems = result.data.rows.filter((r) => !r.is_valid);
      if (problems.length) {
        window.alert(problems.map((r) => `Row ${r.row_number}: ${r.error}`).join('\n'));
      }

      if (onImported) onImported();
    } catch (error) {
      showError(error);
    } finally {
      importInput.value = '';
    }
  });
}

function row(vendor) {
  return `
    <tr data-vendor="${escapeHtml(vendor.uuid)}">
      <td>
        <a class="fw-semibold text-decoration-none" href="vendors.html?history=${encodeURIComponent(vendor.uuid)}">${escapeHtml(vendor.name)}</a>
        <div class="small text-muted">${escapeHtml(vendor.vendor_code || '—')}${vendor.gstin ? ' · ' + escapeHtml(vendor.gstin) : ''}</div>
      </td>
      <td class="small">${escapeHtml(vendor.contact_person || '—')}</td>
      <td class="small">${escapeHtml(vendor.phone || vendor.email || '—')}</td>
      <td class="small">${escapeHtml([vendor.city, vendor.state].filter(Boolean).join(', ') || '—')}</td>
      <td>${vendor.is_active
        ? '<span class="badge text-bg-success">Active</span>'
        : '<span class="badge text-bg-secondary">Inactive</span>'}</td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="vendors.html?history=${encodeURIComponent(vendor.uuid)}">History</a>
        <a class="btn btn-sm btn-outline-secondary"
           href="vendors.html?edit=${encodeURIComponent(vendor.uuid)}">Edit</a>
        ${vendor.is_active ? `<button class="btn btn-sm btn-outline-danger" data-deactivate>Deactivate</button>` : ''}
      </td>
    </tr>`;
}

async function renderDashboard(container) {
  try {
    const response = await api.get('/admin/vendors/dashboard');
    const d = response.data;

    container.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
        ${iconStatCard({ tone: '#B5773A', iconSvgPaths: VENDOR_ICONS.truck, label: 'Total vendors', value: escapeHtml(d.total_vendors) })}
        ${iconStatCard({ tone: '#B5773A', iconSvgPaths: VENDOR_ICONS.box, label: 'Total purchases', value: escapeHtml(d.total_purchases), hint: formatMoney(d.total_purchase_value) })}
        ${iconStatCard({ tone: d.pending_payments > 0 ? '#A6291F' : 'var(--muted-2)', iconSvgPaths: VENDOR_ICONS.clock, label: 'Pending payments', value: formatMoney(d.pending_payments) })}
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: VENDOR_ICONS.check, label: 'Paid amount', value: formatMoney(d.paid_amount) })}
      </div>
      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
          <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3">Top vendors</h2>
            ${d.top_vendors.length === 0 ? '<p class="text-muted small mb-0">No purchases yet.</p>' : `
              <table class="table table-sm table-tight mb-0">
                <tbody>
                  ${d.top_vendors.map((v) => `
                    <tr>
                      <td><a class="text-decoration-none" href="vendors.html?history=${encodeURIComponent(v.vendor_uuid)}">${escapeHtml(v.vendor_name)}</a></td>
                      <td class="text-end">${formatMoney(v.total)}</td>
                    </tr>`).join('')}
                </tbody>
              </table>`}
          </div></div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3">Recent purchases</h2>
            ${d.recent_purchases.length === 0 ? '<p class="text-muted small mb-0">No purchases yet.</p>' : `
              <table class="table table-sm table-tight mb-0">
                <tbody>
                  ${d.recent_purchases.slice(0, 6).map((p) => `
                    <tr>
                      <td class="small">
                        <a class="text-decoration-none" href="purchase-inward.html?tab=history&uuid=${encodeURIComponent(p.uuid)}">${escapeHtml(p.po_number)}</a>
                        <div class="text-muted">${escapeHtml(p.vendor_name)}</div>
                      </td>
                      <td class="text-end small">
                        ${formatMoney(p.grand_total)}
                        <div>${badge(p.payment_status)}</div>
                      </td>
                    </tr>`).join('')}
                </tbody>
              </table>`}
          </div></div>
        </div>
      </div>
      ${d.purchase_returns.count > 0 ? `
        <p class="text-muted small mb-4">${escapeHtml(d.purchase_returns.count)} purchase return(s) recorded,
           worth ${formatMoney(d.purchase_returns.value)}.</p>` : ''}`;
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

async function renderList() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Vendors</h1>
      <div class="d-flex gap-2 align-items-center">
        <a class="btn btn-sm btn-dark" href="vendors.html?new">Add a vendor</a>
        <form class="d-flex gap-2" data-search-form>
          <input class="form-control form-control-sm" name="q" value="${escapeHtml(search)}"
                 placeholder="Search vendors" style="width:14rem">
          <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
        </form>
      </div>
    </div>

    <div data-dashboard></div>

    ${importExportPanel([
      { value: 'vendors', label: 'Vendors', importPath: '/admin/import/vendors' },
      { value: 'purchase_orders', label: 'Purchases' },
      { value: 'transactions', label: 'Transactions' },
    ])}

    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  renderDashboard(root.querySelector('[data-dashboard]'));
  bindImportExportPanel(root, () => renderList());

  root.querySelector('[data-search-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    search = new FormData(event.currentTarget).get('q') || '';
    renderList();
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/vendors', { search, per_page: 50 });
    const vendors = response.data || [];

    if (vendors.length === 0) {
      list.innerHTML = emptyState(
        search ? 'Nothing matched' : 'No vendors yet',
        search ? 'Try a different term.' : 'Use "Add a vendor" to record your first supplier.'
      );
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Vendor</th><th>Contact</th><th>Reach</th><th>Location</th><th>Status</th><th></th></tr></thead>
          <tbody>${vendors.map(row).join('')}</tbody>
        </table>
      </div>`;

    list.querySelectorAll('[data-deactivate]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-vendor]').dataset.vendor;
        if (!window.confirm('Deactivate this vendor? Past purchase history is kept.')) return;

        setBusy(button, true, 'Saving');

        try {
          await api.delete(`/admin/vendors/${encodeURIComponent(uuid)}`);
          toast('Vendor deactivated.');
          renderList();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function formFields(vendor) {
  const v = vendor || {};

  return `
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="name">Name</label>
        <input class="form-control" id="name" name="name" required maxlength="150" value="${escapeHtml(v.name || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="company_name">Company name</label>
        <input class="form-control" id="company_name" name="company_name" maxlength="160" value="${escapeHtml(v.company_name || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="contact_person">Contact person</label>
        <input class="form-control" id="contact_person" name="contact_person" maxlength="120" value="${escapeHtml(v.contact_person || '')}">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="phone">Phone</label>
        <input class="form-control" id="phone" name="phone" maxlength="15" value="${escapeHtml(v.phone || '')}">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="email">Email</label>
        <input class="form-control" id="email" name="email" type="email" maxlength="150" value="${escapeHtml(v.email || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="address_line1">Address line 1</label>
        <input class="form-control" id="address_line1" name="address_line1" value="${escapeHtml(v.address_line1 || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="address_line2">Address line 2</label>
        <input class="form-control" id="address_line2" name="address_line2" value="${escapeHtml(v.address_line2 || '')}">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="city">City</label>
        <input class="form-control" id="city" name="city" value="${escapeHtml(v.city || '')}">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="state">State</label>
        <input class="form-control" id="state" name="state" value="${escapeHtml(v.state || '')}">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="pincode">Pincode</label>
        <input class="form-control" id="pincode" name="pincode" value="${escapeHtml(v.pincode || '')}">
      </div>

      <div class="col-12"><hr class="my-1"><div class="eyebrow small text-muted">Tax</div></div>
      <div class="col-md-6">
        <label class="form-label" for="gstin">GSTIN</label>
        <input class="form-control" id="gstin" name="gstin" maxlength="20" value="${escapeHtml(v.gstin || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="pan">PAN</label>
        <input class="form-control" id="pan" name="pan" maxlength="10" value="${escapeHtml(v.pan || '')}">
      </div>

      <div class="col-12"><hr class="my-1"><div class="eyebrow small text-muted">Bank details</div></div>
      <div class="col-md-6">
        <label class="form-label" for="bank_account_name">Account holder name</label>
        <input class="form-control" id="bank_account_name" name="bank_account_name" maxlength="160" value="${escapeHtml(v.bank_account_name || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="bank_name">Bank name</label>
        <input class="form-control" id="bank_name" name="bank_name" maxlength="120" value="${escapeHtml(v.bank_name || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="bank_account_number">Account number</label>
        <input class="form-control" id="bank_account_number" name="bank_account_number" maxlength="30" value="${escapeHtml(v.bank_account_number || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="bank_ifsc">IFSC</label>
        <input class="form-control" id="bank_ifsc" name="bank_ifsc" maxlength="11" value="${escapeHtml(v.bank_ifsc || '')}">
      </div>

      <div class="col-12"><hr class="my-1"></div>
      <div class="col-md-4">
        <label class="form-label" for="payment_terms">Payment terms</label>
        <input class="form-control" id="payment_terms" name="payment_terms" maxlength="120"
               placeholder="e.g. Net 30" value="${escapeHtml(v.payment_terms || '')}">
      </div>
      <div class="col-md-8">
        <label class="form-label" for="notes">Notes</label>
        <textarea class="form-control" id="notes" name="notes" rows="1" maxlength="500">${escapeHtml(v.notes || '')}</textarea>
      </div>
    </div>`;
}

async function renderForm(editUuid) {
  let vendor = null;

  if (editUuid) {
    try {
      const response = await api.get(`/admin/vendors/${encodeURIComponent(editUuid)}`);
      vendor = response.data;
    } catch (error) {
      root.innerHTML = '<a class="small" href="vendors.html">← Vendors</a>';
      showError(error, root);
      return;
    }
  }

  root.innerHTML = `
    <a class="small text-decoration-none" href="vendors.html">← Vendors</a>
    <h1 class="h4 mt-2 mb-3">${vendor ? 'Edit vendor' : 'Add a vendor'}</h1>
    <div class="card"><div class="card-body">
      <form data-form>
        ${formFields(vendor)}
        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-dark" type="submit">Save</button>
          <a class="btn btn-outline-secondary" href="vendors.html">Cancel</a>
        </div>
      </form>
    </div></div>`;

  root.querySelector('[data-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button[type=submit]');
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    setBusy(button, true, 'Saving');

    try {
      if (vendor) {
        await api.patch(`/admin/vendors/${encodeURIComponent(vendor.uuid)}`, data);
      } else {
        await api.post('/admin/vendors', data);
      }

      toast('Vendor saved.');
      window.location.href = 'vendors.html';
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

/** Purchases → Items → Payments → Returns → Pending Balance → Transactions, all in one place. */
async function renderHistory(uuid) {
  root.innerHTML = `
    <a class="small text-decoration-none" href="vendors.html">← Vendors</a>
    <div data-history class="mt-2">
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div>`;

  const host = root.querySelector('[data-history]');

  try {
    const response = await api.get(`/admin/vendors/${encodeURIComponent(uuid)}/history`);
    const { vendor, purchases, payments, returns, summary } = response.data;

    host.innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h1 class="h4 mb-0">${escapeHtml(vendor.name)}</h1>
          <p class="text-muted small mb-0">${escapeHtml(vendor.vendor_code || '')}${vendor.company_name ? ' · ' + escapeHtml(vendor.company_name) : ''}</p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="vendors.html?edit=${encodeURIComponent(vendor.uuid)}">Edit vendor</a>
      </div>

      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-4">
        ${iconStatCard({ tone: '#B5773A', iconSvgPaths: VENDOR_ICONS.box, label: 'Purchases', value: escapeHtml(summary.purchase_count), hint: formatMoney(summary.total_purchased) })}
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: VENDOR_ICONS.check, label: 'Paid', value: formatMoney(summary.total_paid) })}
        ${iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: VENDOR_ICONS.undo, label: 'Returned', value: formatMoney(summary.total_returned) })}
        ${iconStatCard({ tone: summary.pending_balance > 0 ? '#A6291F' : 'var(--muted-2)', iconSvgPaths: VENDOR_ICONS.clock, label: 'Pending balance', value: formatMoney(summary.pending_balance) })}
      </div>

      <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link active" data-tab-link="purchases" href="#">Purchases</a></li>
        <li class="nav-item"><a class="nav-link" data-tab-link="payments" href="#">Payments</a></li>
        <li class="nav-item"><a class="nav-link" data-tab-link="returns" href="#">Returns</a></li>
      </ul>

      <div data-tab-body></div>`;

    const body = host.querySelector('[data-tab-body]');

    const tabs = {
      purchases: () => purchases.length === 0 ? emptyState('No purchases yet', '') : `
        <div class="table-responsive"><table class="table table-tight table-hover mb-0">
          <thead><tr><th>PO</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Returned</th><th>Status</th></tr></thead>
          <tbody>${purchases.map((p) => `
            <tr>
              <td><a class="text-decoration-none" href="purchase-inward.html?tab=history&uuid=${encodeURIComponent(p.uuid)}">${escapeHtml(p.po_number)}</a></td>
              <td class="small">${escapeHtml(p.purchase_date)}</td>
              <td class="text-end">${formatMoney(p.grand_total)}</td>
              <td class="text-end">${formatMoney(p.amount_paid)}</td>
              <td class="text-end">${formatMoney(p.amount_returned)}</td>
              <td>${badge(p.payment_status)}</td>
            </tr>`).join('')}</tbody>
        </table></div>`,
      payments: () => payments.length === 0 ? emptyState('No payments yet', '') : `
        <div class="table-responsive"><table class="table table-tight table-hover mb-0">
          <thead><tr><th>PO</th><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
          <tbody>${payments.map((p) => `
            <tr>
              <td><a class="text-decoration-none" href="purchase-inward.html?tab=history&uuid=${encodeURIComponent(p.purchase_order_uuid)}">${escapeHtml(p.po_number)}</a></td>
              <td class="small">${escapeHtml(p.payment_date)}</td>
              <td class="small text-uppercase">${escapeHtml(p.payment_method)}</td>
              <td class="small">${escapeHtml(p.reference_number || '—')}</td>
              <td class="text-end">${formatMoney(p.amount)}</td>
            </tr>`).join('')}</tbody>
        </table></div>`,
      returns: () => returns.length === 0 ? emptyState('No returns yet', '') : `
        <div class="table-responsive"><table class="table table-tight table-hover mb-0">
          <thead><tr><th>Return</th><th>PO</th><th>Date</th><th>Reason</th><th class="text-end">Amount</th></tr></thead>
          <tbody>${returns.map((r) => `
            <tr>
              <td>${escapeHtml(r.return_number)}</td>
              <td><a class="text-decoration-none" href="purchase-inward.html?tab=history&uuid=${encodeURIComponent(r.purchase_order_uuid)}">${escapeHtml(r.po_number)}</a></td>
              <td class="small">${escapeHtml(r.return_date)}</td>
              <td class="small">${escapeHtml(r.reason)}</td>
              <td class="text-end">${formatMoney(r.total_amount)}</td>
            </tr>`).join('')}</tbody>
        </table></div>`,
    };

    function showTab(name) {
      host.querySelectorAll('[data-tab-link]').forEach((link) => {
        link.classList.toggle('active', link.dataset.tabLink === name);
      });
      body.innerHTML = `<div class="card"><div class="card-body p-0">${tabs[name]()}</div></div>`;
    }

    host.querySelectorAll('[data-tab-link]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        showTab(link.dataset.tabLink);
      });
    });

    showTab('purchases');
  } catch (error) {
    host.innerHTML = '';
    showError(error, host);
  }
}

const mounted = await mountConsole('vendors.html');

if (mounted) {
  root = mounted.root;

  const editUuid = queryParam('edit');
  const historyUuid = queryParam('history');
  const isNew = queryParam('new') !== null;

  if (historyUuid) {
    renderHistory(historyUuid);
  } else if (editUuid || isNew) {
    renderForm(editUuid);
  } else {
    renderList();
  }
}
