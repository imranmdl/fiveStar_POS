/**
 * Inventory: stock by warehouse, the movement ledger, damage/loss, and
 * manual corrections — including tracing a SKU back to the batches and
 * purchase orders it came from, so a restock at a different cost or from a
 * different vendor is visibly distinct from what was already on the shelf.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney, queryParam,
         emptyState, badge, iconStatCard, headerIcon } from './console.js?v=9';

const ICONS = {
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  alert: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
  box: '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
  ghost: '<path d="M9 10h.01M15 10h.01"/><path d="M5 21V11a7 7 0 0 1 14 0v10l-3-2-2 2-2-2-2 2-2-2-3 2Z"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
};

const state = {
  tab: queryParam('tab') || 'stock',
  stock: { warehouse_uuid: '', category_slug: '', sku: '', stock_status: '', page: 1 },
  movements: { warehouse_uuid: '', sku: '', movement_type: '', page: 1 },
  damageLoss: { warehouse_uuid: '', sku: '', from: '', to: '' },
  expiry: { warehouse_uuid: '', sku: '', status: 'all' },
  // 'all' | 'low' | 'out' — mirrors the general Stock tab's own stock_status
  // filter exactly (see InventoryStockRepository::searchStock()); this tab is
  // that same query, just narrowed to what needs reordering and laid out for
  // that one job.
  alerts: { status: queryParam('status') || 'all', warehouse_uuid: '', page: 1 },
  import: { warehouse_uuid: '' },
  deleted: { product: '', sku: '', deleted_from: '', deleted_to: '', page: 1 },
};

let root = null;
let warehouses = [];
let importPreview = null;

/**
 * The "Set…"/threshold-value link every stock table shares — asks for a new
 * minimum stock level (or blank to clear it) and saves it. `onSaved` is
 * whatever that particular tab needs to do to reflect the change: re-run its
 * own render function.
 */
function bindReorderButtons(list, onSaved) {
  list.querySelectorAll('[data-set-reorder]').forEach((button) => {
    button.addEventListener('click', async () => {
      const entered = window.prompt('Minimum stock level (leave blank to clear):', button.dataset.current || '');
      if (entered === null) return;

      const threshold = entered.trim() === '' ? null : Number(entered);

      if (threshold !== null && (Number.isNaN(threshold) || threshold < 0)) {
        toast('Enter a number of zero or more.', 'danger');
        return;
      }

      try {
        await api.patch('/admin/inventory/reorder-threshold', {
          variant_uuid: button.dataset.variant,
          warehouse_uuid: button.dataset.warehouse,
          reorder_threshold: threshold,
        });
        toast('Minimum stock level saved.');
        onSaved();
      } catch (error) {
        showError(error);
      }
    });
  });
}

function warehouseOptions(selected) {
  return `<option value="">All warehouses</option>` + warehouses.map((w) => `
    <option value="${escapeHtml(w.uuid)}" ${w.uuid === selected ? 'selected' : ''}>${escapeHtml(w.name)}</option>`).join('');
}

function quantityCell(row) {
  const qty = Number(row.quantity);
  const low = row.reorder_threshold !== null && qty <= Number(row.reorder_threshold);
  const tone = qty < 0 ? 'text-danger fw-semibold' : (low ? 'text-warning-emphasis fw-semibold' : '');

  return `<span class="${tone}">${qty.toLocaleString('en-IN', { maximumFractionDigits: 3 })}</span>${low ? ' <span class="badge text-bg-warning">Low</span>' : ''}`;
}

function stockRow(row) {
  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(row.product_name)}</span>
        <div class="small text-muted">${escapeHtml(row.variant_name)} · ${escapeHtml(row.sku)}${row.barcode ? ' · ' + escapeHtml(row.barcode) : ''}</div>
      </td>
      <td class="small">${escapeHtml(row.warehouse_name)}</td>
      <td class="text-end">${quantityCell(row)}</td>
      <td class="text-end small">
        <button class="btn btn-sm btn-link p-0" data-set-reorder
                data-variant="${escapeHtml(row.variant_uuid)}"
                data-warehouse="${escapeHtml(row.warehouse_uuid)}"
                data-current="${row.reorder_threshold !== null ? escapeHtml(Number(row.reorder_threshold)) : ''}">
          ${row.reorder_threshold !== null ? escapeHtml(Number(row.reorder_threshold)) : 'Set…'}
        </button>
      </td>
      <td class="text-end small">${row.average_cost !== null ? formatMoney(row.average_cost) : '—'}</td>
      <td class="text-end small">
        ${row.selling_price !== null ? formatMoney(row.selling_price) : '—'}
        <button class="btn btn-sm btn-link p-0 ms-1" data-price-history
                data-variant="${escapeHtml(row.variant_uuid)}"
                data-label="${escapeHtml(row.product_name + ' — ' + row.variant_name)}" title="Price history">history</button>
      </td>
      <td class="text-end text-nowrap">
        <button class="btn btn-sm btn-outline-secondary" data-trace
                data-variant="${escapeHtml(row.variant_uuid)}"
                data-label="${escapeHtml(row.product_name + ' — ' + row.variant_name)}">
          Trace
        </button>
        <button class="btn btn-sm btn-outline-secondary" data-adjust
                data-variant="${escapeHtml(row.variant_uuid)}"
                data-warehouse="${escapeHtml(row.warehouse_uuid)}"
                data-label="${escapeHtml(row.product_name + ' — ' + row.variant_name + ' @ ' + row.warehouse_name)}">
          Adjust
        </button>
      </td>
    </tr>`;
}

async function renderStockTab(container) {
  container.innerHTML = `
    <form class="row row-cols-lg-auto g-2 align-items-center mb-3" data-filter-form>
      <div class="col-12"><select class="form-select form-select-sm" name="warehouse_uuid">${warehouseOptions(state.stock.warehouse_uuid)}</select></div>
      <div class="col-12"><input class="form-control form-control-sm" name="category_slug" placeholder="Category slug" value="${escapeHtml(state.stock.category_slug)}"></div>
      <div class="col-12"><input class="form-control form-control-sm" name="sku" placeholder="SKU" value="${escapeHtml(state.stock.sku)}"></div>
      <div class="col-12">
        <select class="form-select form-select-sm" name="stock_status">
          <option value="">All stock</option>
          <option value="low" ${state.stock.stock_status === 'low' ? 'selected' : ''}>Low stock</option>
          <option value="negative" ${state.stock.stock_status === 'negative' ? 'selected' : ''}>Negative</option>
          <option value="out" ${state.stock.stock_status === 'out' ? 'selected' : ''}>Out of stock</option>
        </select>
      </div>
      <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div>
    </form>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    Object.assign(state.stock, data, { page: 1 });
    renderStockTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/inventory/stock', { ...state.stock, per_page: 30 });
    const rows = response.data || [];

    if (rows.length === 0) {
      list.innerHTML = emptyState('No stock matches these filters', 'Try widening the filters, or record an inward movement.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Product</th><th>Warehouse</th><th class="text-end">Quantity</th>
            <th class="text-end">Reorder at</th><th class="text-end">Avg. cost</th><th class="text-end">Selling price</th><th></th></tr></thead>
          <tbody>${rows.map(stockRow).join('')}</tbody>
        </table>
      </div>
      ${(response.meta && response.meta.total_pages > 1) ? `
        <div class="p-3 border-top d-flex justify-content-between align-items-center">
          <span class="small text-muted">Page ${escapeHtml(response.meta.page)} of ${escapeHtml(response.meta.total_pages)}</span>
          <span>
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.stock.page -= 1; renderStockTab(container); });
    if (next) next.addEventListener('click', () => { state.stock.page += 1; renderStockTab(container); });

    list.querySelectorAll('[data-adjust]').forEach((button) => {
      button.addEventListener('click', () => openAdjustModal(button.dataset.variant, button.dataset.warehouse, button.dataset.label, () => renderStockTab(container)));
    });

    list.querySelectorAll('[data-trace]').forEach((button) => {
      button.addEventListener('click', () => openTraceModal(button.dataset.variant, button.dataset.label));
    });

    list.querySelectorAll('[data-price-history]').forEach((button) => {
      button.addEventListener('click', () => openPriceHistoryModal(button.dataset.variant, button.dataset.label));
    });

    bindReorderButtons(list, () => renderStockTab(container));
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function movementRow(row) {
  const tone = Number(row.quantity_delta) < 0 ? 'text-danger' : 'text-success';

  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td>
        <span class="fw-semibold">${escapeHtml(row.sku)}</span>
        <div class="small text-muted">${escapeHtml(row.variant_name)}</div>
      </td>
      <td class="small">${escapeHtml(row.warehouse_name)}</td>
      <td>${badge(row.movement_type, row.movement_type)}</td>
      <td class="text-end ${tone}">${Number(row.quantity_delta) > 0 ? '+' : ''}${escapeHtml(Number(row.quantity_delta))}</td>
      <td class="text-end small">${escapeHtml(Number(row.quantity_after))}</td>
      <td class="text-end small">${row.unit_cost !== null ? formatMoney(row.unit_cost) : '—'}</td>
      <td class="small">${row.customer_name ? escapeHtml(row.customer_name) : '<span class="text-muted">—</span>'}</td>
      <td class="small">${escapeHtml(row.reason || (row.reference_type ? row.reference_type + (row.reference_id ? ' #' + row.reference_id : '') : '—'))}</td>
    </tr>`;
}

async function renderMovementsTab(container) {
  container.innerHTML = `
    <form class="row row-cols-lg-auto g-2 align-items-center mb-3" data-filter-form>
      <div class="col-12"><select class="form-select form-select-sm" name="warehouse_uuid">${warehouseOptions(state.movements.warehouse_uuid)}</select></div>
      <div class="col-12"><input class="form-control form-control-sm" name="sku" placeholder="SKU" value="${escapeHtml(state.movements.sku)}"></div>
      <div class="col-12">
        <select class="form-select form-select-sm" name="movement_type">
          <option value="">All movement types</option>
          ${['inward', 'sale', 'return', 'damage', 'lost', 'adjustment', 'transfer_in', 'transfer_out'].map((type) => `
            <option value="${type}" ${state.movements.movement_type === type ? 'selected' : ''}>${type}</option>`).join('')}
        </select>
      </div>
      <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div>
    </form>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    Object.assign(state.movements, data, { page: 1 });
    renderMovementsTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/inventory/movements', { ...state.movements, per_page: 30, sort: 'created_date', direction: 'DESC' });
    const rows = response.data || [];

    if (rows.length === 0) {
      list.innerHTML = emptyState('No movements yet', 'Sales, inward stock and adjustments will show up here as they happen.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>When</th><th>SKU</th><th>Warehouse</th><th>Type</th>
            <th class="text-end">Change</th><th class="text-end">Balance</th><th class="text-end">Cost</th>
            <th>Customer</th><th>Reason / reference</th></tr></thead>
          <tbody>${rows.map(movementRow).join('')}</tbody>
        </table>
      </div>
      ${(response.meta && response.meta.total_pages > 1) ? `
        <div class="p-3 border-top d-flex justify-content-between align-items-center">
          <span class="small text-muted">Page ${escapeHtml(response.meta.page)} of ${escapeHtml(response.meta.total_pages)}</span>
          <span>
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.movements.page -= 1; renderMovementsTab(container); });
    if (next) next.addEventListener('click', () => { state.movements.page += 1; renderMovementsTab(container); });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function deletedRow(row) {
  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(row.product_name)}</span>
        ${row.variant_name ? `<div class="small text-muted">${escapeHtml(row.variant_name)}</div>` : ''}
      </td>
      <td class="small">${escapeHtml(row.sku)}</td>
      <td class="text-end small">${escapeHtml(Number(row.quantity))}</td>
      <td class="small">${escapeHtml(String(row.deleted_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td class="small">${row.deleted_by_name ? escapeHtml(row.deleted_by_name) : '<span class="text-muted">—</span>'}</td>
      <td class="text-end text-nowrap">
        <button class="btn btn-sm btn-outline-success" data-restore="${escapeHtml(row.variant_uuid)}" data-label="${escapeHtml(row.product_name)}" type="button">Restore</button>
        <button class="btn btn-sm btn-outline-danger" data-purge="${escapeHtml(row.variant_uuid)}" data-label="${escapeHtml(row.product_name)}" type="button">Permanent delete</button>
      </td>
    </tr>`;
}

async function renderDeletedTab(container) {
  container.innerHTML = `
    <form class="row row-cols-lg-auto g-2 align-items-center mb-3" data-filter-form>
      <div class="col-12"><input class="form-control form-control-sm" name="product" placeholder="Product name" value="${escapeHtml(state.deleted.product)}"></div>
      <div class="col-12"><input class="form-control form-control-sm" name="sku" placeholder="SKU" value="${escapeHtml(state.deleted.sku)}"></div>
      <div class="col-12"><input class="form-control form-control-sm" type="date" name="deleted_from" title="Deleted from" value="${escapeHtml(state.deleted.deleted_from)}"></div>
      <div class="col-12"><input class="form-control form-control-sm" type="date" name="deleted_to" title="Deleted to" value="${escapeHtml(state.deleted.deleted_to)}"></div>
      <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div>
    </form>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    Object.assign(state.deleted, data, { page: 1 });
    renderDeletedTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/inventory/deleted', { ...state.deleted, per_page: 30 });
    const rows = response.data || [];

    if (rows.length === 0) {
      list.innerHTML = emptyState('Recycle bin is empty', 'Products and pack sizes you delete will show up here, never in Active Inventory.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Quantity</th>
            <th>Deleted on</th><th>Deleted by</th><th></th></tr></thead>
          <tbody>${rows.map(deletedRow).join('')}</tbody>
        </table>
      </div>
      ${(response.meta && response.meta.total_pages > 1) ? `
        <div class="p-3 border-top d-flex justify-content-between align-items-center">
          <span class="small text-muted">Page ${escapeHtml(response.meta.page)} of ${escapeHtml(response.meta.total_pages)}</span>
          <span>
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.deleted.page -= 1; renderDeletedTab(container); });
    if (next) next.addEventListener('click', () => { state.deleted.page += 1; renderDeletedTab(container); });

    list.querySelectorAll('[data-restore]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!window.confirm(`Restore "${button.dataset.label}" back into Active Inventory?`)) return;
        setBusy(button, true, 'Restoring');
        try {
          await api.post(`/admin/inventory/deleted/${encodeURIComponent(button.dataset.restore)}/restore`);
          toast('Item restored.');
          renderDeletedTab(container);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    list.querySelectorAll('[data-purge]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!window.confirm(
          `Permanently delete "${button.dataset.label}"? This CANNOT be recovered — it will be gone `
          + `for good, not just moved to the recycle bin. Continue?`
        )) return;
        setBusy(button, true, 'Deleting');
        try {
          await api.delete(`/admin/inventory/deleted/${encodeURIComponent(button.dataset.purge)}`, { confirm: 'yes' });
          toast('Item permanently deleted.', 'warning');
          renderDeletedTab(container);
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

function modalHost(attr) {
  let host = document.querySelector(`[${attr}]`);

  if (!host) {
    host = document.createElement('div');
    host.setAttribute(attr, '');
    document.body.appendChild(host);
  }

  return host;
}

/**
 * "Trace this item" — where the stock actually is, the batches it's tracked
 * in (only ever populated when someone recorded a batch_no on an inward or
 * adjustment), and the full purchase history regardless of batch tracking.
 * The latter is what makes "new vs old" visible even when batches were
 * never used: a different vendor, date or cost per line tells the story on
 * its own.
 */
async function openTraceModal(variantUuid, label) {
  const host = modalHost('data-trace-modal');
  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">Trace — ${escapeHtml(label)}</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>
          </div>
        </div>
      </div>
    </div>`;

  const modalEl = host.querySelector('[data-modal]');
  const modal = new window.bootstrap.Modal(modalEl);
  modal.show();

  const body = host.querySelector('.modal-body');

  try {
    const response = await api.get(`/admin/inventory/stock/${encodeURIComponent(variantUuid)}`);
    const detail = response.data;
    const newestBatchNo = (detail.batches || [])
      .slice()
      .sort((a, b) => new Date(b.created_date) - new Date(a.created_date))[0]?.batch_no;

    body.innerHTML = `
      <h3 class="h6">Current stock</h3>
      <div class="table-responsive mb-3">
        <table class="table table-tight table-sm mb-0">
          <thead><tr><th>Warehouse</th><th class="text-end">Quantity</th></tr></thead>
          <tbody>
            ${(detail.warehouses || []).map((w) => `
              <tr><td>${escapeHtml(w.warehouse_name)}</td><td class="text-end">${escapeHtml(Number(w.quantity))}</td></tr>`).join('')
              || '<tr><td colspan="2" class="text-muted text-center">No stock anywhere.</td></tr>'}
          </tbody>
        </table>
      </div>

      <h3 class="h6">Batches on hand</h3>
      <p class="small text-muted">Only shows when a batch number was recorded on an inward or adjustment — not every restock is batch-tracked.</p>
      <div class="table-responsive mb-3">
        <table class="table table-tight table-sm mb-0">
          <thead><tr><th>Batch</th><th class="text-end">Remaining</th><th class="text-end">Unit cost</th><th>Expiry</th></tr></thead>
          <tbody>
            ${(detail.batches || []).length ? detail.batches.map((b) => `
              <tr>
                <td>${escapeHtml(b.batch_no)}${b.batch_no === newestBatchNo ? ' <span class="badge text-bg-success">Newest</span>' : ''}</td>
                <td class="text-end">${escapeHtml(Number(b.quantity))}</td>
                <td class="text-end">${b.unit_cost !== null ? formatMoney(b.unit_cost) : '—'}</td>
                <td class="small">${escapeHtml(b.expiry_date || '—')}</td>
              </tr>`).join('') : '<tr><td colspan="4" class="text-muted text-center">No batches tracked for this item.</td></tr>'}
          </tbody>
        </table>
      </div>

      <h3 class="h6">Purchase history</h3>
      <div class="table-responsive">
        <table class="table table-tight table-sm mb-0">
          <thead><tr><th>Date</th><th>Vendor</th><th>PO</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th><th class="text-end">Landing cost</th></tr></thead>
          <tbody>
            ${(detail.purchase_history || []).length ? detail.purchase_history.map((h) => `
              <tr>
                <td class="small">${escapeHtml(String(h.purchase_date || '').slice(0, 10))}</td>
                <td class="small">${escapeHtml(h.vendor_name)}</td>
                <td class="small">${escapeHtml(h.po_number)}</td>
                <td class="text-end">${escapeHtml(Number(h.quantity))}</td>
                <td class="text-end">${formatMoney(h.unit_cost)}</td>
                <td class="text-end">${formatMoney(h.landing_cost)}</td>
              </tr>`).join('') : '<tr><td colspan="6" class="text-muted text-center">Never purchased through a recorded purchase order.</td></tr>'}
          </tbody>
        </table>
      </div>`;
  } catch (error) {
    showError(error, body);
  }
}

async function openPriceHistoryModal(variantUuid, label) {
  const host = modalHost('data-price-history-modal');
  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="h5 modal-title">Price history — ${escapeHtml(label)}</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="text-center py-4 text-muted"><div class="spinner-border"></div></div>
          </div>
        </div>
      </div>
    </div>`;

  const modalEl = host.querySelector('[data-modal]');
  const modal = new window.bootstrap.Modal(modalEl);
  modal.show();

  const body = host.querySelector('.modal-body');

  try {
    const response = await api.get(`/admin/pricing/history/${encodeURIComponent(variantUuid)}`);
    const rows = response.data || [];

    body.innerHTML = rows.length ? `
      <div class="table-responsive">
        <table class="table table-tight table-sm mb-0">
          <thead><tr><th>Date</th><th class="text-end">Old</th><th class="text-end">New</th><th>Decision</th><th>Reason</th></tr></thead>
          <tbody>
            ${rows.map((r) => `
              <tr>
                <td class="small">${escapeHtml(String(r.created_date || '').slice(0, 16).replace('T', ' '))}</td>
                <td class="text-end">${formatMoney(r.old_selling_price)}</td>
                <td class="text-end fw-semibold">${formatMoney(r.new_selling_price)}</td>
                <td class="small">${escapeHtml(r.decision)}</td>
                <td class="small">${escapeHtml(r.reason || '—')}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>` : '<p class="text-muted small mb-0">No price changes logged for this pack size yet.</p>';
  } catch (error) {
    showError(error, body);
  }
}

/** A Bootstrap modal for a manual adjustment against one known (variant, warehouse) pair. */
async function openAdjustModal(variantUuid, warehouseUuid, label, onSaved) {
  const host = modalHost('data-adjust-modal');

  host.innerHTML = `
    <div class="modal fade" tabindex="-1" data-modal>
      <div class="modal-dialog">
        <div class="modal-content">
          <form data-adjust-form>
            <div class="modal-header">
              <h2 class="h5 modal-title">Adjust stock</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <p class="small text-muted">${escapeHtml(label)}</p>
              <div class="mb-3">
                <label class="form-label" for="movement_type">Type</label>
                <select class="form-select" id="movement_type" name="movement_type" required>
                  <option value="adjustment">Count adjustment</option>
                  <option value="inward">Inward (received stock)</option>
                  <option value="damage">Damage / write-off</option>
                  <option value="lost">Lost (missing, unaccounted)</option>
                  <option value="return">Return to stock</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="quantity_delta">Quantity change</label>
                <input class="form-control" id="quantity_delta" name="quantity_delta" type="number" step="0.001" required
                       placeholder="Positive to add, negative to remove">
              </div>
              <div class="mb-3" data-unit-cost-field style="display:none">
                <label class="form-label" for="unit_cost">Unit cost (₹)</label>
                <input class="form-control" id="unit_cost" name="unit_cost" type="number" step="0.0001" min="0">
                <div class="form-text">Recalculates the weighted-average cost. Leave blank if this isn't an inward at a known cost.</div>
              </div>
              <div class="mb-3" data-batch-field>
                <label class="form-label" for="batch_no">Batch</label>
                <select class="form-select mb-1" data-batch-select style="display:none"></select>
                <input class="form-control" id="batch_no" name="batch_no" maxlength="60" placeholder="Optional — type a new batch number">
                <div class="form-text" data-batch-hint>
                  For damage/loss: picking an existing batch is what lets this be traced back to the vendor/purchase order it came from.
                </div>
              </div>
              <div class="mb-3" data-expiry-field style="display:none">
                <label class="form-label" for="expiry_date">Expiry date</label>
                <input class="form-control" id="expiry_date" name="expiry_date" type="date">
              </div>
              <div class="mb-3">
                <label class="form-label" for="reason">Reason</label>
                <input class="form-control" id="reason" name="reason" required minlength="3" maxlength="255"
                       placeholder="e.g. Physical count correction, damaged in transit">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-dark">Save adjustment</button>
            </div>
          </form>
        </div>
      </div>
    </div>`;

  const modalEl = host.querySelector('[data-modal]');
  const modal = new window.bootstrap.Modal(modalEl);
  const typeSelect = host.querySelector('#movement_type');
  const unitCostField = host.querySelector('[data-unit-cost-field]');
  const expiryField = host.querySelector('[data-expiry-field]');
  const batchSelect = host.querySelector('[data-batch-select]');
  const batchInput = host.querySelector('#batch_no');

  const syncFieldVisibility = () => {
    unitCostField.style.display = typeSelect.value === 'inward' ? '' : 'none';
    expiryField.style.display = typeSelect.value === 'inward' ? '' : 'none';
  };

  typeSelect.addEventListener('change', syncFieldVisibility);
  syncFieldVisibility();

  batchSelect.addEventListener('change', () => {
    if (batchSelect.value) {
      batchInput.value = batchSelect.value;
      batchInput.readOnly = true;
    } else {
      batchInput.value = '';
      batchInput.readOnly = false;
    }
  });

  modal.show();

  // Existing batches populate a picker so "which batch is this from" is a
  // choice, not something staff has to remember and retype correctly. Runs
  // after the modal is already visible — this is decoration, not something
  // worth delaying the modal opening for.
  (async () => {
    try {
      const response = await api.get(`/admin/inventory/stock/${encodeURIComponent(variantUuid)}`);
      const batches = response.data.batches || [];

      if (batches.length > 0) {
        batchSelect.style.display = '';
        batchSelect.innerHTML = `<option value="">New / untracked batch…</option>` + batches.map((b) => `
          <option value="${escapeHtml(b.batch_no)}">${escapeHtml(b.batch_no)} — ${escapeHtml(Number(b.quantity))} left${b.unit_cost !== null ? ' @ ' + formatMoney(b.unit_cost) : ''}</option>`).join('');
      }
    } catch {
      // Decoration only — the free-text batch field still works without it.
    }
  })();

  host.querySelector('[data-adjust-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button[type=submit]');
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (!data.unit_cost) delete data.unit_cost;
    if (!data.batch_no) delete data.batch_no;
    if (!data.expiry_date) delete data.expiry_date;

    setBusy(button, true, 'Saving');

    try {
      await api.post('/admin/inventory/adjust', { ...data, variant_uuid: variantUuid, warehouse_uuid: warehouseUuid });
      toast('Stock adjusted.');
      modal.hide();
      onSaved();
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

function alertStatus(row) {
  const qty = Number(row.quantity);

  if (qty <= 0) return { label: 'Out of Stock', tone: 'danger' };
  if (row.reorder_threshold !== null && qty <= Number(row.reorder_threshold)) return { label: 'Low Stock', tone: 'warning' };
  return { label: 'OK', tone: 'success' };
}

function alertRow(row) {
  const status = alertStatus(row);
  const createPurchaseUrl = `purchase-inward.html?tab=record&sku=${encodeURIComponent(row.sku)}&warehouse=${encodeURIComponent(row.warehouse_uuid)}`;

  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(row.product_name)}</span>
        <div class="small text-muted">${escapeHtml(row.variant_name)} · ${escapeHtml(row.warehouse_name)}</div>
      </td>
      <td class="small">${escapeHtml(row.sku)}</td>
      <td class="text-end ${status.tone === 'danger' ? 'text-danger fw-semibold' : (status.tone === 'warning' ? 'text-warning-emphasis fw-semibold' : '')}">
        ${Number(row.quantity).toLocaleString('en-IN', { maximumFractionDigits: 3 })}
      </td>
      <td class="text-end small">
        <button class="btn btn-sm btn-link p-0" data-set-reorder
                data-variant="${escapeHtml(row.variant_uuid)}"
                data-warehouse="${escapeHtml(row.warehouse_uuid)}"
                data-current="${row.reorder_threshold !== null ? escapeHtml(Number(row.reorder_threshold)) : ''}">
          ${row.reorder_threshold !== null ? escapeHtml(Number(row.reorder_threshold)) : 'Set…'}
        </button>
      </td>
      <td><span class="badge text-bg-${status.tone}">${status.label}</span></td>
      <td class="text-end">
        <a class="btn btn-sm btn-dark" href="${createPurchaseUrl}">Create Purchase</a>
      </td>
    </tr>`;
}

/**
 * Low Stock Alerts: the same reorder_threshold/quantity comparison the
 * general Stock tab's own "Low stock"/"Out of stock" filters already use
 * (InventoryStockRepository::search()), laid out for exactly this one job —
 * what needs reordering, and a button that starts that purchase order. Stock
 * changes from a sale, purchase, return, damage write-off or expiry all go
 * through InventoryService::recordMovement(), the one place `quantity` is
 * ever written, so this list is never stale by more than the next page load.
 */
async function renderAlertsTab(container) {
  container.innerHTML = `
    <form class="d-flex flex-wrap gap-2 align-items-center mb-3" data-filter-form>
      <div class="btn-group btn-group-sm" role="group">
        ${[['all', 'All'], ['low', 'Low Stock'], ['out', 'Out of Stock']].map(([value, label]) => `
          <button type="button" class="btn ${state.alerts.status === value ? 'btn-dark' : 'btn-outline-secondary'}"
                  data-status-filter="${value}">${label}</button>`).join('')}
      </div>
      <select class="form-select form-select-sm w-auto" name="warehouse_uuid">${warehouseOptions(state.alerts.warehouse_uuid)}</select>
    </form>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelectorAll('[data-status-filter]').forEach((button) => {
    button.addEventListener('click', () => {
      state.alerts.status = button.dataset.statusFilter;
      state.alerts.page = 1;
      renderAlertsTab(container);
    });
  });

  container.querySelector('[name=warehouse_uuid]').addEventListener('change', (event) => {
    state.alerts.warehouse_uuid = event.target.value;
    state.alerts.page = 1;
    renderAlertsTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/inventory/stock', {
      warehouse_uuid: state.alerts.warehouse_uuid,
      stock_status: state.alerts.status === 'all' ? 'alert' : state.alerts.status,
      page: state.alerts.page,
      per_page: 50,
    });
    const items = response.data || [];

    if (items.length === 0) {
      list.innerHTML = emptyState(
        state.alerts.status === 'all' ? 'Nothing needs reordering' : 'Nothing matches this filter',
        'Every pack size with a minimum stock level set is at or above it.'
      );
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr>
            <th>Product</th><th>SKU</th><th class="text-end">Current Stock</th>
            <th class="text-end">Minimum Stock</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>${items.map(alertRow).join('')}</tbody>
        </table>
      </div>
      ${response.meta && response.meta.total_pages > 1 ? `
        <div class="d-flex justify-content-between align-items-center p-2 small text-muted">
          <span>Page ${response.meta.page} of ${response.meta.total_pages} · ${response.meta.total} item(s)</span>
          <span class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" data-page-prev ${response.meta.page <= 1 ? 'disabled' : ''}>Previous</button>
            <button class="btn btn-sm btn-outline-secondary" data-page-next ${response.meta.page >= response.meta.total_pages ? 'disabled' : ''}>Next</button>
          </span>
        </div>` : ''}`;

    const prev = list.querySelector('[data-page-prev]');
    const next = list.querySelector('[data-page-next]');
    if (prev) prev.addEventListener('click', () => { state.alerts.page -= 1; renderAlertsTab(container); });
    if (next) next.addEventListener('click', () => { state.alerts.page += 1; renderAlertsTab(container); });

    bindReorderButtons(list, () => renderAlertsTab(container));
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function renderTabs() {
  return `
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link ${state.tab === 'stock' ? 'active' : ''}" href="inventory.html?tab=stock">Stock</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'movements' ? 'active' : ''}" href="inventory.html?tab=movements">Movement ledger</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'damage-loss' ? 'active' : ''}" href="inventory.html?tab=damage-loss">Damage &amp; loss</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'expiry' ? 'active' : ''}" href="inventory.html?tab=expiry">Expiry</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'alerts' ? 'active' : ''}" href="inventory.html?tab=alerts">Low Stock Alerts</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'import' ? 'active' : ''}" href="inventory.html?tab=import">Import CSV</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'deleted' ? 'active' : ''}" href="inventory.html?tab=deleted">Recycle Bin</a></li>
    </ul>`;
}

// -----------------------------------------------------------------------
// Bulk import (CSV/XLSX) — preview, correct nothing client-side (the file
// itself is the source of truth), confirm. Mirrors the review-before-save
// shape of the Purchase Inward vendor-bill upload, but against
// ImportService's own preview()/confirm(), which creates/updates products
// by SKU and posts stock through the normal InventoryService::recordMovement()
// path — nothing bespoke to this screen.
// -----------------------------------------------------------------------

function importTemplateCsv() {
  const headers = [
    'sku', 'barcode', 'category', 'product_name', 'variant_name', 'pack_type',
    'weight_grams', 'stock_unit_type', 'unit_label', 'mrp', 'selling_price',
    'warehouse_code', 'quantity', 'reorder_threshold', 'purchase_cost', 'batch_no', 'expiry_date',
  ];
  const example = [
    'SP-TURM-250', '', 'spices', 'Turmeric Powder', '250 g', 'pouch',
    '250', 'weight', '', '60', '45',
    'MAIN', '50', '10', '30', '', '2027-01-01',
  ];

  return headers.join(',') + '\n' + example.join(',') + '\n';
}

function downloadImportTemplate() {
  const blob = new Blob([importTemplateCsv()], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'inventory-import-template.csv';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function importRowHtml(row) {
  const n = row.normalized || {};
  const errors = Object.values(row.errors || {}).flat();
  const actionBadge = !row.is_valid
    ? '<span class="badge text-bg-danger">Skip</span>'
    : row.action === 'create'
      ? '<span class="badge text-bg-info">New</span>'
      : '<span class="badge text-bg-secondary">Update</span>';

  return `
    <tr class="${row.is_valid ? '' : 'table-danger'}">
      <td class="small">${row.row_number}</td>
      <td class="small fw-semibold">${escapeHtml(n.sku || row.raw.sku || '')}</td>
      <td>${actionBadge}</td>
      <td class="small">${escapeHtml(n.product_name || '')}${n.variant_name ? `<div class="text-muted">${escapeHtml(n.variant_name)}</div>` : ''}</td>
      <td class="text-end small">${n.quantity !== undefined ? escapeHtml(n.quantity) : '—'}</td>
      <td class="small">${errors.length ? errors.map((e) => `<div class="text-danger">✖ ${escapeHtml(e)}</div>`).join('') : '<span class="text-success">OK</span>'}</td>
    </tr>`;
}

function drawImportReview(review, feedback) {
  if (!importPreview || !importPreview.rows.length) {
    review.innerHTML = importPreview ? '<div class="text-muted small">No rows found in that file.</div>' : '';
    return;
  }

  const { rows, summary } = importPreview;

  review.innerHTML = `
    <div class="d-flex flex-wrap gap-3 align-items-center small mb-2">
      <span><b>${summary.valid}</b> of ${summary.total} row(s) ready (${summary.to_create} new, ${summary.to_update} update to an existing SKU)</span>
      ${summary.invalid ? `<span class="text-danger"><b>${summary.invalid}</b> need fixing</span>` : ''}
    </div>
    <div class="table-responsive" style="max-height:28rem">
      <table class="table table-sm align-middle mb-2">
        <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr>
          <th>Row</th><th>SKU</th><th>Action</th><th>Item</th><th class="text-end">Qty</th><th>Notes</th>
        </tr></thead>
        <tbody>${rows.map(importRowHtml).join('')}</tbody>
      </table>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <button class="btn btn-sm btn-primary" type="button" data-import-confirm ${summary.valid ? '' : 'disabled'}>Confirm import (${summary.valid} row(s))</button>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-import-cancel>Cancel</button>
      <span class="small text-muted">Nothing is saved until you confirm. Fix the file and re-upload to correct a row.</span>
    </div>`;

  review.querySelector('[data-import-cancel]').addEventListener('click', () => {
    importPreview = null;
    review.innerHTML = '';
    feedback.textContent = '';
  });

  review.querySelector('[data-import-confirm]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    setBusy(button, true, 'Importing');

    try {
      const response = await api.post('/admin/imports/confirm', {
        token: importPreview.token,
        file_type: importPreview.file_type,
        file_name: importPreview.file_name,
        warehouse_uuid: state.import.warehouse_uuid || undefined,
      });
      const batch = response.data;
      feedback.innerHTML = `<span class="text-success">Import complete — ${batch.created_count} created, ${batch.updated_count} updated${batch.skipped_count ? `, ${batch.skipped_count} skipped` : ''}.</span>`;
      toast('Import complete.');
      importPreview = null;
      review.innerHTML = '';

      const historyHost = document.querySelector('[data-import-history]');
      if (historyHost) await loadImportHistory(historyHost);
    } catch (error) {
      showError(error, feedback);
    } finally {
      setBusy(button, false);
    }
  });
}

function importBatchRow(batch) {
  return `
    <tr>
      <td class="small">${escapeHtml(String(batch.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td class="small">${escapeHtml(batch.file_name)}</td>
      <td class="small">${escapeHtml(batch.warehouse_name || '—')}</td>
      <td class="text-end small">${batch.total_rows}</td>
      <td class="text-end small">${batch.created_count}</td>
      <td class="text-end small">${batch.updated_count}</td>
      <td class="text-end small">${batch.skipped_count}</td>
      <td>${badge(batch.status, batch.status)}</td>
    </tr>`;
}

async function loadImportHistory(host) {
  try {
    const response = await api.get('/admin/imports', { per_page: 10 });
    const items = response.data || [];

    if (items.length === 0) {
      host.innerHTML = emptyState('No imports yet', 'A confirmed import appears here with its created/updated/skipped counts.');
      return;
    }

    host.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight mb-0">
          <thead><tr>
            <th>When</th><th>File</th><th>Default warehouse</th><th class="text-end">Rows</th>
            <th class="text-end">Created</th><th class="text-end">Updated</th><th class="text-end">Skipped</th><th>Status</th>
          </tr></thead>
          <tbody>${items.map(importBatchRow).join('')}</tbody>
        </table>
      </div>`;
  } catch (error) {
    host.innerHTML = '';
    showError(error, host);
  }
}

async function renderImportTab(container) {
  container.innerHTML = `
    <div class="card mb-3"><div class="card-body">
      <h3 class="h6">Import products &amp; stock from a CSV or Excel file</h3>
      <p class="small text-muted mb-2">
        One row per pack size. An existing <b>SKU</b> updates that pack size — give it a Quantity to add stock, or
        leave everything but the SKU blank to change nothing. An unrecognised SKU creates a new draft product
        (Category, Product name, Variant name, Weight, MRP and Selling price are then required).
        You review every row before anything is saved.
      </p>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <button class="btn btn-sm btn-outline-primary" type="button" data-dl-template>Download template</button>
        <select class="form-select form-select-sm w-auto" data-import-warehouse>
          <option value="">No default warehouse</option>
          ${warehouses.map((w) => `<option value="${escapeHtml(w.uuid)}" ${w.uuid === state.import.warehouse_uuid ? 'selected' : ''}>${escapeHtml(w.name)}</option>`).join('')}
        </select>
        <span class="text-muted small">used for rows with no Warehouse code column, or that column left blank</span>
      </div>
      <div class="input-group input-group-sm" style="max-width:32rem">
        <input class="form-control" type="file" accept=".csv,.xlsx" data-import-file>
        <button class="btn btn-outline-primary" type="button" data-import-preview>Preview</button>
      </div>
      <div data-import-feedback class="small mt-2"></div>
      <div data-import-review class="mt-3"></div>
    </div></div>
    <h3 class="h6">Recent imports</h3>
    <div class="card"><div class="card-body p-0" data-import-history>
      <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
    </div></div>`;

  container.querySelector('[data-dl-template]').addEventListener('click', downloadImportTemplate);

  container.querySelector('[data-import-warehouse]').addEventListener('change', (event) => {
    state.import.warehouse_uuid = event.target.value;
  });

  const feedback = container.querySelector('[data-import-feedback]');
  const review = container.querySelector('[data-import-review]');

  container.querySelector('[data-import-preview]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const file = container.querySelector('[data-import-file]').files[0];

    if (!file) { feedback.innerHTML = '<span class="text-danger">Choose a file first.</span>'; return; }

    setBusy(button, true, 'Reading');
    feedback.textContent = '';
    review.innerHTML = '';

    try {
      const formData = new FormData();
      formData.append('file', file);
      if (state.import.warehouse_uuid) formData.append('warehouse_uuid', state.import.warehouse_uuid);
      const response = await api.upload('/admin/imports/preview', formData);
      importPreview = response.data;
      drawImportReview(review, feedback);
    } catch (error) {
      showError(error, feedback);
    } finally {
      setBusy(button, false);
    }
  });

  await loadImportHistory(container.querySelector('[data-import-history]'));
}

function damageLossRow(row) {
  return `
    <tr>
      <td class="small">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</td>
      <td>
        <span class="fw-semibold">${escapeHtml(row.sku)}</span>
        <div class="small text-muted">${escapeHtml(row.variant_name)} · ${escapeHtml(row.warehouse_name)}</div>
      </td>
      <td>${badge(row.movement_type, row.movement_type)}</td>
      <td class="text-end">${escapeHtml(row.quantity)}</td>
      <td class="text-end small">${row.line_value !== null ? formatMoney(row.line_value) + (row.value_source === 'current_average_cost' ? ' *' : '') : '—'}</td>
      <td class="small">${escapeHtml(row.reason || '—')}</td>
      <td class="small">${row.batch_no ? escapeHtml(row.batch_no) : '<span class="text-muted">—</span>'}</td>
      <td class="small">${row.vendor_name ? `${escapeHtml(row.vendor_name)} <span class="text-muted">(${escapeHtml(row.po_number)})</span>` : '<span class="text-muted">Not traced</span>'}</td>
    </tr>`;
}

async function renderDamageLossTab(container) {
  container.innerHTML = `
    <form class="row row-cols-lg-auto g-2 align-items-center mb-3" data-filter-form>
      <div class="col-12"><select class="form-select form-select-sm" name="warehouse_uuid">${warehouseOptions(state.damageLoss.warehouse_uuid)}</select></div>
      <div class="col-12"><input class="form-control form-control-sm" name="sku" placeholder="SKU" value="${escapeHtml(state.damageLoss.sku)}"></div>
      <div class="col-12"><input class="form-control form-control-sm" type="date" name="from" value="${escapeHtml(state.damageLoss.from)}"></div>
      <div class="col-12"><input class="form-control form-control-sm" type="date" name="to" value="${escapeHtml(state.damageLoss.to)}"></div>
      <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div>
    </form>
    <div data-summary></div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    Object.assign(state.damageLoss, Object.fromEntries(new FormData(event.currentTarget).entries()));
    renderDamageLossTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/inventory/reports/damage-loss', { ...state.damageLoss });
    const { rows, summary } = response.data;

    container.querySelector('[data-summary]').innerHTML = `
      <div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.box, label: 'Incidents', value: escapeHtml(summary.incident_count) })}
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.alert, label: 'Damaged', value: escapeHtml(summary.damage_count) })}
        ${iconStatCard({ tone: '#A6291F', iconSvgPaths: ICONS.ghost, label: 'Lost', value: escapeHtml(summary.lost_count) })}
        ${iconStatCard({ tone: '#A6291F', iconSvgPaths: ICONS.rupee, label: 'Estimated value lost', value: formatMoney(summary.total_value), hint: `${summary.total_quantity} unit(s) total` })}
      </div>`;

    if (rows.length === 0) {
      list.innerHTML = emptyState('No damage or loss recorded', 'Nothing was written off in this range — use "Adjust" on the Stock tab to record one.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>When</th><th>Item</th><th>Type</th><th class="text-end">Qty</th>
            <th class="text-end">Value</th><th>Reason</th><th>Batch</th><th>Source</th></tr></thead>
          <tbody>${rows.map(damageLossRow).join('')}</tbody>
        </table>
      </div>
      <p class="small text-muted p-3 mb-0 border-top">
        * Value estimated from the item's current average cost — this system does not snapshot cost per movement, only per inward.
      </p>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function expiryRow(row) {
  const daysLabel = row.status === 'expired'
    ? `Expired ${Math.abs(row.days_remaining)} day(s) ago`
    : `${row.days_remaining} day(s) left`;

  return `
    <tr>
      <td>
        <span class="fw-semibold">${escapeHtml(row.sku)}</span>
        <div class="small text-muted">${escapeHtml(row.product_name)} · ${escapeHtml(row.variant_name)} · ${escapeHtml(row.warehouse_name)}</div>
      </td>
      <td class="small">${escapeHtml(row.batch_no)}</td>
      <td class="text-end">${Number(row.quantity).toLocaleString('en-IN', { maximumFractionDigits: 3 })}</td>
      <td class="small">${escapeHtml(String(row.expiry_date || '').slice(0, 10))}</td>
      <td class="small">${escapeHtml(daysLabel)}</td>
      <td class="text-end small">${row.line_value !== null ? formatMoney(row.line_value) : '—'}</td>
      <td>${badge(row.status, row.status.replace(/_/g, ' '))}</td>
    </tr>`;
}

async function renderExpiryTab(container) {
  container.innerHTML = `
    <form class="row row-cols-lg-auto g-2 align-items-center mb-3" data-filter-form>
      <div class="col-12"><select class="form-select form-select-sm" name="status">
        <option value="all" ${state.expiry.status === 'all' ? 'selected' : ''}>All</option>
        <option value="expiring_soon" ${state.expiry.status === 'expiring_soon' ? 'selected' : ''}>Expiring soon</option>
        <option value="expired" ${state.expiry.status === 'expired' ? 'selected' : ''}>Expired</option>
      </select></div>
      <div class="col-12"><select class="form-select form-select-sm" name="warehouse_uuid">${warehouseOptions(state.expiry.warehouse_uuid)}</select></div>
      <div class="col-12"><input class="form-control form-control-sm" name="sku" placeholder="SKU" value="${escapeHtml(state.expiry.sku)}"></div>
      <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div>
    </form>
    <div data-summary></div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  container.querySelector('[data-filter-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    Object.assign(state.expiry, Object.fromEntries(new FormData(event.currentTarget).entries()));
    renderExpiryTab(container);
  });

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/inventory/reports/expiry', { ...state.expiry });
    const { items, summary } = response.data;

    container.querySelector('[data-summary]').innerHTML = `
      <div class="row row-cols-2 row-cols-lg-3 g-3 mb-3">
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.clock, label: 'Expiring soon (≤15 days)', value: escapeHtml(summary.expiring_soon_count) })}
        ${iconStatCard({ tone: '#A6291F', iconSvgPaths: ICONS.alert, label: 'Expired', value: escapeHtml(summary.expired_count) })}
        ${iconStatCard({ tone: '#A6291F', iconSvgPaths: ICONS.rupee, label: 'Value at risk', value: formatMoney(summary.total_value) })}
      </div>`;

    if (items.length === 0) {
      list.innerHTML = emptyState('Nothing to show', 'No batches match this filter — declare an expiry date on the Purchase Inward or Adjust screen to track one.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Item</th><th>Batch</th><th class="text-end">Qty</th><th>Expiry date</th>
            <th>Time left</th><th class="text-end">Value</th><th>Status</th></tr></thead>
          <tbody>${items.map(expiryRow).join('')}</tbody>
        </table>
      </div>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

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

function bindExportButtons(container) {
  container.querySelectorAll('[data-export]').forEach((button) => {
    button.addEventListener('click', async () => {
      setBusy(button, true, 'Preparing');
      try {
        await downloadCsv(`/admin/export/${encodeURIComponent(button.dataset.export)}`, `${button.dataset.export}.csv`);
      } catch (error) {
        showError(error);
      } finally {
        setBusy(button, false);
      }
    });
  });
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Inventory</h1>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" data-export="inventory" type="button">Export stock (CSV)</button>
        <button class="btn btn-sm btn-outline-secondary" data-export="inventory_batches" type="button">Export batches (CSV)</button>
      </div>
    </div>
    ${renderTabs()}<div data-tab-body></div>`;
  bindExportButtons(root);
  const body = root.querySelector('[data-tab-body]');

  if (state.tab === 'movements') {
    await renderMovementsTab(body);
  } else if (state.tab === 'damage-loss') {
    await renderDamageLossTab(body);
  } else if (state.tab === 'expiry') {
    await renderExpiryTab(body);
  } else if (state.tab === 'alerts') {
    await renderAlertsTab(body);
  } else if (state.tab === 'import') {
    await renderImportTab(body);
  } else if (state.tab === 'deleted') {
    await renderDeletedTab(body);
  } else {
    await renderStockTab(body);
  }
}

const mounted = await mountConsole('inventory.html');

if (mounted) {
  root = mounted.root;

  try {
    const response = await api.get('/admin/warehouses');
    warehouses = response.data || [];
  } catch {
    warehouses = [];
  }

  render();
}
