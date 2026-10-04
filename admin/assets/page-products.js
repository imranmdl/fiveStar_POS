/** Catalogue: what is on sale, and switching things on and off. */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         badge, emptyState } from './console.js?v=9';
import { mountProductEditor } from './product-editor.js?v=1';
import { renderCsvUpload } from './inward-csv.js';

let root = null;
let search = '';

/** "Added <date>" plus the vendor(s) it was bought from, when any purchase has been recorded. */
function sourcingCell(sourcing) {
  if (!sourcing) return '<span class="text-muted">—</span>';

  const added = String(sourcing.added_date || '').slice(0, 10);
  const vendors = sourcing.vendors || [];

  return `
    <div>Added ${escapeHtml(added)}${sourcing.added_by ? ` <span class="text-muted">by ${escapeHtml(sourcing.added_by)}</span>` : ''}</div>
    <div class="${vendors.length ? '' : 'text-muted'}">${vendors.length
      ? vendors.map((v) => `${escapeHtml(v.name)} <span class="text-muted">(${escapeHtml(String(v.last_purchase_date || '').slice(0, 10))})</span>`).join('<br>')
      : 'No vendor yet'}</div>`;
}

function row(product, offer, sourcing) {
  // Same nesting as the public list: `pricing.min_price`, `rating.average`.
  const pricing = product.pricing || {};
  const rating = product.rating || {};
  const categoryName = (product.category && product.category.name) || product.category_name;

  return `
    <tr data-product="${escapeHtml(product.uuid)}">
      <td>
        <span class="fw-semibold">${escapeHtml(product.name)}</span>
        <div class="small text-muted">${escapeHtml(product.slug)}</div>
      </td>
      <td class="small">${escapeHtml(categoryName || '—')}</td>
      <td class="small">${sourcingCell(sourcing)}</td>
      <td class="text-end">${formatMoney(pricing.min_price)}</td>
      <td class="text-center small">${escapeHtml(pricing.variant_count ?? '—')}</td>
      <td class="text-center small">
        ${Number(rating.count) > 0
          ? `★ ${escapeHtml(Number(rating.average).toFixed(1))} (${escapeHtml(rating.count)})`
          : '<span class="text-muted">—</span>'}
      </td>
      <td>${badge(product.status === 'published' ? 'approved' : 'pending', product.status)}</td>
      <td class="text-nowrap">
        ${offer
          ? `<span class="badge text-bg-success" title="${escapeHtml(offer.title)}">${escapeHtml(offer.summary)}</span>`
          : `<button class="btn btn-sm btn-outline-success" data-add-offer type="button">+ Add offer</button>`}
      </td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary"
           href="products.html?edit=${encodeURIComponent(product.uuid)}">Edit</a>
        <button class="btn btn-sm btn-outline-secondary" data-toggle="${escapeHtml(product.status)}">
          ${product.status === 'published' ? 'Unpublish' : 'Publish'}
        </button>
        <button class="btn btn-sm btn-outline-danger" data-delete type="button" title="Delete this product">Delete</button>
      </td>
    </tr>`;
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
 * Quick "attach an offer to this one product" form — a shortcut into the same
 * offers/offer_targets campaign system the full Promotions screen manages, so
 * anything created here shows up there too (and, once live, in POS billing).
 * Resolves to true if an offer was created, false otherwise.
 */
function addOfferModal(product) {
  return new Promise((resolve) => {
    const host = modalHost('data-add-offer-modal');
    const defaultEnds = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

    host.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal>
        <div class="modal-dialog">
          <div class="modal-content">
            <form data-offer-form>
              <div class="modal-header">
                <h2 class="h6 modal-title">Add an offer — ${escapeHtml(product.name)}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label">Title</label>
                  <input class="form-control" name="title" maxlength="160"
                         value="${escapeHtml(product.name)} offer" required>
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label">Discount type</label>
                    <select class="form-select" name="discount_type" data-discount-type>
                      <option value="percentage">Percentage off</option>
                      <option value="flat">Flat amount off</option>
                      <option value="free_items">Buy X get Y free</option>
                    </select>
                  </div>
                  <div class="col-6" data-value-row>
                    <label class="form-label">Value</label>
                    <input class="form-control" name="discount_value" type="number" min="0" step="0.01" required>
                    <div class="form-text" data-value-hint></div>
                  </div>
                </div>
                <div class="mb-3 mt-2" data-max-discount-row>
                  <label class="form-label">Maximum discount amount</label>
                  <input class="form-control" name="max_discount_amount" type="number" min="1" step="0.01">
                  <div class="form-text">Required for a percentage discount, so it can never exceed this amount.</div>
                </div>
                <div class="row g-2 mb-3 d-none" data-bogo-row>
                  <div class="col-6">
                    <label class="form-label">Buy</label>
                    <input class="form-control" name="buy_quantity" type="number" min="1" step="1" value="1">
                  </div>
                  <div class="col-6">
                    <label class="form-label">Get free</label>
                    <input class="form-control" name="get_quantity" type="number" min="1" step="1" value="1">
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Offer ends</label>
                  <input class="form-control" name="ends_date" type="date" value="${defaultEnds}" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Where it applies</label>
                  <select class="form-select" name="channel">
                    <option value="all">Online and POS (counter)</option>
                    <option value="online">Online only</option>
                    <option value="pos">POS (counter) only</option>
                  </select>
                </div>
                <div class="mb-1">
                  <label class="form-label">Who it's for</label>
                  <select class="form-select" name="audience" data-audience>
                    <option value="all">Every customer</option>
                    <option value="new_customers">New customers only</option>
                    <option value="specific_customer">One specific customer</option>
                  </select>
                </div>
                <div class="mt-2 d-none" data-customer-row>
                  <label class="form-label">Customer's mobile or email</label>
                  <input class="form-control" name="customer_identifier" maxlength="150"
                         placeholder="9999999999 or name@example.com">
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Add offer</button>
              </div>
            </form>
          </div>
        </div>
      </div>`;

    const modalEl = host.querySelector('[data-modal]');
    const modal = new window.bootstrap.Modal(modalEl);
    const form = host.querySelector('[data-offer-form]');
    const typeSelect = form.querySelector('[data-discount-type]');
    const maxRow = form.querySelector('[data-max-discount-row]');
    const maxInput = maxRow.querySelector('input');
    const valueRow = form.querySelector('[data-value-row]');
    const valueInput = valueRow.querySelector('input');
    const valueHint = valueRow.querySelector('[data-value-hint]');
    const bogoRow = form.querySelector('[data-bogo-row]');
    const audienceSelect = form.querySelector('[data-audience]');
    const customerRow = form.querySelector('[data-customer-row]');
    const customerInput = customerRow.querySelector('input');

    const syncDiscountType = () => {
      const isPercentage = typeSelect.value === 'percentage';
      const isBogo = typeSelect.value === 'free_items';
      maxRow.classList.toggle('d-none', !isPercentage);
      maxInput.required = isPercentage;
      valueRow.classList.toggle('d-none', isBogo);
      valueInput.required = !isBogo;
      // 15% is a hard server-side ceiling (assertDiscountCoherent) — capped
      // here too so the form refuses before a round trip, not just after.
      valueInput.max = isPercentage ? '15' : '';
      valueHint.textContent = isPercentage ? 'Capped at 15%, whatever the margin check below allows.' : '';
      bogoRow.classList.toggle('d-none', !isBogo);
    };
    typeSelect.addEventListener('change', syncDiscountType);
    syncDiscountType();

    const syncAudience = () => {
      const isSpecific = audienceSelect.value === 'specific_customer';
      customerRow.classList.toggle('d-none', !isSpecific);
      customerInput.required = isSpecific;
    };
    audienceSelect.addEventListener('change', syncAudience);
    syncAudience();

    let settled = false;
    const finish = (value) => {
      if (settled) return;
      settled = true;
      modal.hide();
      resolve(value);
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type=submit]');
      const data = Object.fromEntries(new FormData(form).entries());
      setBusy(button, true, 'Adding');

      let createdUuid = null;

      try {
        const code = `OFFER-${product.slug}-${Date.now().toString(36)}`.toUpperCase().slice(0, 40);
        const isBogo = data.discount_type === 'free_items';
        const payload = {
          code,
          title: data.title,
          discount_type: data.discount_type,
          discount_value: isBogo ? 0 : Number(data.discount_value),
          ends_date: data.ends_date,
          channel: data.channel,
          audience: data.audience,
        };
        if (data.discount_type === 'percentage') {
          payload.max_discount_amount = Number(data.max_discount_amount);
        }
        if (isBogo) {
          payload.buy_quantity = Number(data.buy_quantity);
          payload.get_quantity = Number(data.get_quantity);
          payload.free_item_scope = 'same_variant';
        }
        if (data.audience === 'specific_customer') {
          payload.customer_identifier = data.customer_identifier;
        }

        const created = await api.post('/admin/offers', payload);
        createdUuid = created.data.offer.uuid;

        await api.put(`/admin/offers/${encodeURIComponent(createdUuid)}/targets`, {
          product_slugs: [product.slug],
        });
        await api.post(`/admin/offers/${encodeURIComponent(createdUuid)}/status`, { status: 'active' });

        toast(`Offer added to ${product.name}.`);
        finish(true);
      } catch (error) {
        // A later step failed — don't leave an orphan draft offer behind.
        if (createdUuid) {
          try { await api.delete(`/admin/offers/${encodeURIComponent(createdUuid)}`); } catch { /* best effort */ }
        }
        setBusy(button, false);
        showError(error);
      }
    });

    modalEl.addEventListener('hidden.bs.modal', () => finish(false), { once: true });
    modal.show();
  });
}

async function render() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Products</h1>
      <div class="d-flex gap-2 align-items-center">
        <a class="btn btn-sm btn-dark" href="products.html?new">Add a product</a>
        <button class="btn btn-sm btn-outline-primary" data-import-products type="button">Import products</button>
        <button class="btn btn-sm btn-outline-danger" data-delete-all type="button" hidden>Delete all</button>
        <button class="btn btn-sm btn-outline-secondary" data-export-products type="button">Export CSV</button>
      <form class="d-flex gap-2" data-search-form>
        <input class="form-control form-control-sm" name="q" value="${escapeHtml(search)}"
               placeholder="Search by product, category or pack/SKU" style="width:18rem">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
      </form>
      </div>
    </div>

    <div data-import-host class="mb-3"></div>

    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>

    <p class="text-muted small mt-3 mb-0">
      Publishing controls whether a product is shown in the shop. Stock levels are
      tracked separately — see <a href="inventory.html">Inventory</a>.
    </p>`;

  root.querySelector('[data-search-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    search = new FormData(event.currentTarget).get('q') || '';
    render();
  });

  root.querySelector('[data-import-products]').addEventListener('click', async (event) => {
    const host = root.querySelector('[data-import-host]');

    if (host.innerHTML.trim() !== '') { host.innerHTML = ''; return; }

    const button = event.currentTarget;
    setBusy(button, true, 'Opening');

    try {
      const setup = (await api.get('/admin/inventory/setup')).data;
      let created = false;

      host.innerHTML = '<div data-import-body></div><div class="text-end mt-n2 mb-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-import-close>Close</button></div>';
      renderCsvUpload(host.querySelector('[data-import-body]'), {
        api, setup, addLine: () => true, escapeHtml, formatMoney, setBusy, showError, toast,
      }, { mode: 'products', onDone: () => { created = true; } });

      host.querySelector('[data-import-close]').addEventListener('click', () => {
        host.innerHTML = '';
        if (created) render();
      });
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  root.querySelector('[data-export-products]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    setBusy(button, true, 'Preparing');

    try {
      const blob = await api.downloadFile('/admin/export/products');
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'products.csv';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/products', { search, per_page: 50 });
    const products = response.data || [];

    if (products.length === 0) {
      list.innerHTML = emptyState(
        search ? 'Nothing matched' : 'No products yet',
        search ? 'Try a different term.' : 'Use "Add a product" to list your first item.'
      );
      return;
    }

    // Best effort: a product list with no offer badges is still useful, so a
    // failed lookup here should not block the page from rendering.
    let offersByProduct = {};
    try {
      const offerResponse = await api.get('/admin/offers/product-lookup', {
        product_uuids: products.map((p) => p.uuid).join(','),
      });
      offersByProduct = offerResponse.data || {};
    } catch { /* no badges this time */ }

    let sourcingByProduct = {};
    try {
      const sourcingResponse = await api.get('/admin/products/sourcing', {
        product_uuids: products.map((p) => p.uuid).join(','),
      });
      sourcingByProduct = sourcingResponse.data || {};
    } catch { /* the column just shows a dash */ }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead>
            <tr>
              <th>Product</th><th>Category</th><th>Added / Vendor</th><th class="text-end">From</th>
              <th class="text-center">Packs</th><th class="text-center">Rating</th>
              <th>Status</th><th>Offer</th><th></th>
            </tr>
          </thead>
          <tbody>${products.map((product) => row(product, offersByProduct[product.uuid], sourcingByProduct[product.uuid])).join('')}</tbody>
        </table>
      </div>`;

    list.querySelectorAll('[data-delete]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-product]').dataset.product;
        const product = products.find((p) => p.uuid === uuid);

        if (!window.confirm(`Delete "${product ? product.name : 'this product'}" and all its pack sizes? This removes it from the shop.`)) return;

        setBusy(button, true, 'Deleting');

        try {
          await api.delete(`/admin/products/${encodeURIComponent(uuid)}`);
          toast('Deleted successfully. View in Recycle Bin.');
          render();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    const deleteAllButton = root.querySelector('[data-delete-all]');

    if (deleteAllButton && !search) {
      deleteAllButton.hidden = false;
      deleteAllButton.addEventListener('click', async () => {
        const typed = window.prompt(`This deletes EVERY product (published and draft) with all their pack sizes and photos, and removes them from the shop.\n\nType DELETE to confirm.`);
        if (typed !== 'DELETE') return;

        setBusy(deleteAllButton, true, 'Deleting');
        let deleted = 0;
        let failed = 0;

        // The list is paged, so keep going until nothing is left (or nothing more can be deleted).
        for (let round = 0; round < 40; round += 1) {
          const batch = (await api.get('/admin/products', { per_page: 50 })).data || [];
          if (batch.length === 0) break;

          let progressed = 0;

          for (const p of batch) {
            try { await api.delete(`/admin/products/${encodeURIComponent(p.uuid)}`); deleted += 1; progressed += 1; } catch { failed += 1; }
          }

          if (progressed === 0) break;
        }

        toast(failed ? `${deleted} deleted, ${failed} could not be deleted.` : `${deleted} product(s) deleted.`, failed ? 'warning' : 'success');
        render();
      });
    }

    list.querySelectorAll('[data-toggle]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-product]').dataset.product;
        // `status` is not a field /admin/products/{uuid} (PATCH) accepts — that
        // silently dropped it and this button did nothing but show a "No
        // changes were supplied" error. Published/draft/archived have their
        // own dedicated actions, /publish and /archive, and those are what
        // actually change status.
        const publishing = button.dataset.toggle !== 'published';

        setBusy(button, true, 'Saving');

        try {
          await api.post(`/admin/products/${encodeURIComponent(uuid)}/${publishing ? 'publish' : 'archive'}`);
          toast(publishing ? 'Product is now on sale.' : 'Product hidden from the shop.');
          render();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    list.querySelectorAll('[data-add-offer]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-product]').dataset.product;
        const product = products.find((p) => p.uuid === uuid);
        const added = await addOfferModal(product);
        if (added) render();
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

const mounted = await mountConsole('products.html');

if (mounted) {
  root = mounted.root;

  // The editor takes over the page when ?edit= or ?new is present, so the list
  // and the form never fight over the same container.
  const editing = await mountProductEditor(root);
  if (!editing) render();
}
