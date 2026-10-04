/**
 * Warehouses: locations inventory can be held at and deducted from.
 *
 * Small screen on purpose — most merchants have one or two warehouses. The
 * list and the create/edit form share this page via ?new / ?edit=<uuid>, the
 * same pattern products.html uses, rather than a second file.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, queryParam } from './console.js?v=9';

let root = null;

function row(warehouse) {
  return `
    <tr data-warehouse="${escapeHtml(warehouse.uuid)}">
      <td>
        <span class="fw-semibold">${escapeHtml(warehouse.name)}</span>
        <div class="small text-muted">${escapeHtml(warehouse.code)}</div>
      </td>
      <td class="small">${escapeHtml([warehouse.city, warehouse.state].filter(Boolean).join(', ') || '—')}</td>
      <td>${warehouse.is_default ? '<span class="badge text-bg-primary">Default</span>' : ''}</td>
      <td>${warehouse.is_active
        ? '<span class="badge text-bg-success">Active</span>'
        : '<span class="badge text-bg-secondary">Inactive</span>'}</td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary"
           href="warehouses.html?edit=${encodeURIComponent(warehouse.uuid)}">Edit</a>
        ${!warehouse.is_default ? `
          <button class="btn btn-sm btn-outline-danger" data-deactivate>Deactivate</button>` : ''}
      </td>
    </tr>`;
}

async function renderList() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Warehouses</h1>
      <a class="btn btn-sm btn-dark" href="warehouses.html?new">Add a warehouse</a>
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/warehouses');
    const warehouses = response.data || [];

    if (warehouses.length === 0) {
      list.innerHTML = `
        <div class="text-center py-5">
          <p class="fw-semibold mb-1">No warehouses yet</p>
          <p class="text-muted small mb-0">Add one — automatic sale deduction needs a default warehouse to target.</p>
        </div>`;
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Warehouse</th><th>Location</th><th></th><th>Status</th><th></th></tr></thead>
          <tbody>${warehouses.map(row).join('')}</tbody>
        </table>
      </div>`;

    list.querySelectorAll('[data-deactivate]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-warehouse]').dataset.warehouse;
        if (!window.confirm('Deactivate this warehouse? Its stock stays on record, but it drops out of pickers.')) return;

        setBusy(button, true, 'Saving');

        try {
          await api.delete(`/admin/warehouses/${encodeURIComponent(uuid)}`);
          toast('Warehouse deactivated.');
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

function formFields(warehouse) {
  const w = warehouse || {};

  return `
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label" for="code">Code</label>
        <input class="form-control" id="code" name="code" required maxlength="30" value="${escapeHtml(w.code || '')}">
      </div>
      <div class="col-md-8">
        <label class="form-label" for="name">Name</label>
        <input class="form-control" id="name" name="name" required maxlength="120" value="${escapeHtml(w.name || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="address_line1">Address line 1</label>
        <input class="form-control" id="address_line1" name="address_line1" value="${escapeHtml(w.address_line1 || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="address_line2">Address line 2</label>
        <input class="form-control" id="address_line2" name="address_line2" value="${escapeHtml(w.address_line2 || '')}">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="city">City</label>
        <input class="form-control" id="city" name="city" value="${escapeHtml(w.city || '')}">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="state">State</label>
        <input class="form-control" id="state" name="state" value="${escapeHtml(w.state || '')}">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="pincode">Pincode</label>
        <input class="form-control" id="pincode" name="pincode" value="${escapeHtml(w.pincode || '')}">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="phone">Phone</label>
        <input class="form-control" id="phone" name="phone" value="${escapeHtml(w.phone || '')}">
      </div>
      <div class="col-md-6 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"
                 ${w.is_default ? 'checked' : ''}>
          <label class="form-check-label" for="is_default">
            Default warehouse
            <div class="small text-muted">Automatic sale deduction always targets this one.</div>
          </label>
        </div>
      </div>
    </div>`;
}

function collect(form) {
  const data = Object.fromEntries(new FormData(form).entries());
  data.is_default = form.querySelector('[name=is_default]').checked;

  return data;
}

async function renderForm(editUuid) {
  let warehouse = null;

  if (editUuid) {
    try {
      const response = await api.get(`/admin/warehouses/${encodeURIComponent(editUuid)}`);
      warehouse = response.data;
    } catch (error) {
      root.innerHTML = '<a class="small" href="warehouses.html">← Warehouses</a>';
      showError(error, root);
      return;
    }
  }

  root.innerHTML = `
    <a class="small text-decoration-none" href="warehouses.html">← Warehouses</a>
    <h1 class="h4 mt-2 mb-3">${warehouse ? 'Edit warehouse' : 'Add a warehouse'}</h1>
    <div class="card"><div class="card-body">
      <form data-form>
        ${formFields(warehouse)}
        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-dark" type="submit">Save</button>
          <a class="btn btn-outline-secondary" href="warehouses.html">Cancel</a>
        </div>
      </form>
    </div></div>`;

  root.querySelector('[data-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button[type=submit]');
    const data = collect(event.currentTarget);
    setBusy(button, true, 'Saving');

    try {
      if (warehouse) {
        await api.patch(`/admin/warehouses/${encodeURIComponent(warehouse.uuid)}`, data);
      } else {
        await api.post('/admin/warehouses', data);
      }

      toast('Warehouse saved.');
      window.location.href = 'warehouses.html';
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

const mounted = await mountConsole('warehouses.html');

if (mounted) {
  root = mounted.root;

  const editUuid = queryParam('edit');
  const isNew = queryParam('new') !== null;

  if (editUuid || isNew) {
    renderForm(editUuid);
  } else {
    renderList();
  }
}
