/**
 * Mobile inventory workflow (brief §10): scan a barcode, confirm what it is,
 * add it to an inward cart, review, confirm. This page is a thin,
 * touch-optimised client of the exact same backend Phase 2 already built —
 * confirming the cart calls the same POST /admin/purchase-orders every
 * desktop purchase order uses, so there is only ever one stock balance,
 * never a separate "mobile" one.
 *
 * Camera scanning uses the browser's native BarcodeDetector API where
 * available (most Android browsers); everywhere else — notably iOS Safari,
 * which doesn't implement it — a manual entry field is always shown
 * alongside the camera view, never only as a fallback path taken on error.
 *
 * An unrecognised barcode offers the brief's "controlled creation workflow":
 * a short form (gated to $manager at the route level) that creates a draft
 * pack size on the spot via the same ImportService::createFromScan() the
 * desktop Pricing/Import screens' quick-create shares.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         queryParam } from './console.js?v=9';
import { resolvePriceDecisionQueue } from './pricing-decisions.js';

const state = { mode: queryParam('mode') || 'scan' };

let root = null;
let vendors = [];
let warehouses = [];
let cart = [];
let scannerStop = null;

function vendorOptions(selected) {
  return vendors.map((v) => `<option value="${escapeHtml(v.uuid)}" ${v.uuid === selected ? 'selected' : ''}>${escapeHtml(v.name)}</option>`).join('');
}

function warehouseOptions(selected) {
  return warehouses.map((w) => `
    <option value="${escapeHtml(w.uuid)}" ${w.uuid === selected || (!selected && w.is_default) ? 'selected' : ''}>${escapeHtml(w.name)}</option>`).join('');
}

function categoryOptionsFrom(categories) {
  return categories.map((c) => `<option value="${escapeHtml(c.uuid)}">${escapeHtml(c.name)}</option>`).join('');
}

async function stopScanner() {
  if (scannerStop) {
    scannerStop();
    scannerStop = null;
  }
}

/** Starts the camera and calls onCode(text) once per detected barcode. Returns false if the browser has no BarcodeDetector. */
async function startScanner(videoEl, onCode) {
  if (!('BarcodeDetector' in window)) return false;

  try {
    const detector = new window.BarcodeDetector({
      formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'],
    });
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    videoEl.srcObject = stream;
    await videoEl.play();

    let stopped = false;
    let lastCode = null;
    let lastAt = 0;

    const loop = async () => {
      if (stopped) return;

      try {
        const codes = await detector.detect(videoEl);
        const now = Date.now();

        if (codes.length > 0 && (codes[0].rawValue !== lastCode || now - lastAt > 3000)) {
          lastCode = codes[0].rawValue;
          lastAt = now;
          onCode(codes[0].rawValue);
        }
      } catch {
        // Transient decode errors are normal mid-scan; just keep looping.
      }

      requestAnimationFrame(loop);
    };

    loop();
    scannerStop = () => {
      stopped = true;
      stream.getTracks().forEach((t) => t.stop());
    };

    return true;
  } catch (error) {
    return false;
  }
}

function cartRow(line, index) {
  const total = (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0);

  return `
    <div class="card mb-2" data-cart-row="${index}">
      <div class="card-body py-2">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="fw-semibold">${escapeHtml(line.product_name)}</div>
            <div class="small text-muted">${escapeHtml(line.variant_name)} · ${escapeHtml(line.sku)}</div>
          </div>
          <button class="btn btn-sm btn-outline-danger" data-remove>×</button>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label small mb-0">Quantity</label>
            <input class="form-control mobile-tap" type="number" step="0.001" min="0.001" value="${escapeHtml(line.quantity)}" data-field="quantity">
          </div>
          <div class="col-6">
            <label class="form-label small mb-0">Unit cost (₹)</label>
            <input class="form-control mobile-tap" type="number" step="0.0001" min="0" value="${escapeHtml(line.unit_cost)}" data-field="unit_cost">
          </div>
        </div>
        <div class="small text-muted mt-1">Line total: <span data-line-total>${formatMoney(total)}</span></div>
      </div>
    </div>`;
}

/** Full rebuild — only for adding or removing a line, never for editing one. */
function renderCart(container) {
  const list = container.querySelector('[data-cart-list]');

  list.innerHTML = cart.length === 0
    ? '<p class="text-muted small text-center py-4">Cart is empty — scan or look up a pack size to add one.</p>'
    : cart.map(cartRow).join('');

  list.querySelectorAll('[data-cart-row]').forEach((rowEl) => {
    const index = Number(rowEl.dataset.cartRow);

    rowEl.querySelector('[data-remove]').addEventListener('click', () => {
      cart.splice(index, 1);
      renderCart(container);
    });

    // Typing into quantity/unit cost must never rebuild this row — that was
    // the bug: renderCart() used to run on every keystroke, tearing down and
    // recreating the very box being typed in, so it lost focus after each
    // character. Only the line total (a plain span, not an input) updates.
    rowEl.querySelectorAll('[data-field]').forEach((input) => {
      input.addEventListener('input', () => {
        const line = cart[index];
        line[input.dataset.field] = input.value;
        const total = (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0);
        rowEl.querySelector('[data-line-total]').textContent = formatMoney(total);
      });
    });
  });

  container.querySelector('[data-cart-count]').textContent = String(cart.length);
}

async function handleCode(code, feedbackEl, container) {
  feedbackEl.innerHTML = `<span class="text-muted">Looking up ${escapeHtml(code)}…</span>`;

  try {
    const response = await api.get('/admin/inventory/lookup', { sku: code });
    const variant = response.data;

    if (cart.some((l) => l.variant_uuid === variant.uuid)) {
      const existing = cart.find((l) => l.variant_uuid === variant.uuid);
      existing.quantity = String((Number(existing.quantity) || 0) + 1);
      feedbackEl.innerHTML = `<span class="text-success">Already in cart — quantity bumped to ${escapeHtml(existing.quantity)}.</span>`;
    } else {
      cart.push({
        variant_uuid: variant.uuid,
        sku: variant.sku,
        product_name: variant.product_name,
        variant_name: variant.variant_name,
        quantity: '1',
        unit_cost: String(variant.selling_price ?? '0'),
      });
      feedbackEl.innerHTML = `<span class="text-success">Added: ${escapeHtml(variant.product_name)} — ${escapeHtml(variant.variant_name)}</span>`;
    }

    renderCart(container);
  } catch (error) {
    if (error.status === 404) {
      feedbackEl.innerHTML = `<span class="text-warning-emphasis">Unrecognised barcode: ${escapeHtml(code)}</span>`;
      await renderQuickCreate(container, code, feedbackEl);
    } else {
      showError(error, feedbackEl);
    }
  }
}

async function renderQuickCreate(container, barcode, feedbackEl) {
  let categories = [];

  try {
    const response = await api.get('/admin/categories', { per_page: 200 });
    categories = response.data || [];
  } catch {
    categories = [];
  }

  const host = container.querySelector('[data-quick-create]');
  host.innerHTML = `
    <div class="card border-warning mt-2">
      <div class="card-body">
        <h3 class="h6">Create this pack size</h3>
        <form data-quick-create-form>
          <div class="mb-2">
            <label class="form-label small mb-0">Category</label>
            <select class="form-select mobile-tap" name="category_uuid" required>
              <option value="">Select…</option>${categoryOptionsFrom(categories)}
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-0">Product name</label>
            <input class="form-control mobile-tap" name="product_name" required>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-0">Pack size (e.g. "500g pouch")</label>
            <input class="form-control mobile-tap" name="variant_name" required>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-4">
              <label class="form-label small mb-0">Weight (g)</label>
              <input class="form-control mobile-tap" type="number" name="weight_grams" min="1" required>
            </div>
            <div class="col-4">
              <label class="form-label small mb-0">MRP</label>
              <input class="form-control mobile-tap" type="number" step="0.01" name="mrp" min="0.01" required>
            </div>
            <div class="col-4">
              <label class="form-label small mb-0">Price</label>
              <input class="form-control mobile-tap" type="number" step="0.01" name="selling_price" min="0.01" required>
            </div>
          </div>
          <button class="btn btn-dark w-100 mobile-tap" type="submit">Create and add to cart</button>
        </form>
      </div>
    </div>`;

  host.querySelector('[data-quick-create-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button[type=submit]');
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    data.barcode = barcode;

    setBusy(button, true, 'Creating');

    try {
      const variant = await api.post('/admin/inventory/quick-create', data);
      cart.push({
        variant_uuid: variant.data.uuid,
        sku: variant.data.sku,
        product_name: data.product_name,
        variant_name: data.variant_name,
        quantity: '1',
        unit_cost: '0',
      });
      host.innerHTML = '';
      feedbackEl.innerHTML = `<span class="text-success">Created and added: ${escapeHtml(data.product_name)}</span>`;
      renderCart(container);
    } catch (error) {
      setBusy(button, false);
      showError(error, host);
    }
  });
}

async function renderScanMode(container) {
  container.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h1 class="h5 mb-0">Scan inward</h1>
      <a class="btn btn-sm btn-outline-secondary" href="mobile.html?mode=lookup">Stock lookup</a>
    </div>

    <video id="scanner-video" playsinline muted hidden></video>
    <div class="d-grid gap-2 my-2">
      <button class="btn btn-dark mobile-tap" data-start-scan>Start camera scan</button>
    </div>

    <div class="input-group mb-1">
      <input class="form-control mobile-tap" data-manual-code placeholder="Or type/scan a barcode or SKU" autofocus>
      <button class="btn btn-outline-secondary mobile-tap" data-manual-add>Add</button>
    </div>
    <div class="small mb-3" data-scan-feedback>&nbsp;</div>
    <div data-quick-create></div>

    <hr>

    <div class="d-flex justify-content-between align-items-center">
      <h2 class="h6 mb-0">Cart (<span data-cart-count>0</span>)</h2>
    </div>
    <div data-cart-list class="my-2"></div>

    <div class="card">
      <div class="card-body">
        <div class="mb-2">
          <label class="form-label small mb-0">Vendor</label>
          <select class="form-select mobile-tap" data-vendor required>
            <option value="">Select a vendor…</option>${vendorOptions()}
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small mb-0">Warehouse</label>
          <select class="form-select mobile-tap" data-warehouse>${warehouseOptions()}</select>
        </div>
        <button class="btn btn-dark w-100 mobile-tap" data-confirm-cart>Confirm inward</button>
      </div>
    </div>`;

  renderCart(container);

  const feedback = container.querySelector('[data-scan-feedback]');
  const manualInput = container.querySelector('[data-manual-code]');
  const video = container.querySelector('#scanner-video');

  container.querySelector('[data-start-scan]').addEventListener('click', async (event) => {
    video.hidden = false;
    const button = event.currentTarget;
    setBusy(button, true, 'Starting camera');

    const started = await startScanner(video, (code) => handleCode(code, feedback, container));

    setBusy(button, false);

    if (!started) {
      video.hidden = true;
      toast('Camera scanning is not supported on this browser — use the text field below.', 'danger');
    }
  });

  const submitManual = () => {
    const code = manualInput.value.trim();
    if (!code) return;
    handleCode(code, feedback, container);
    manualInput.value = '';
    manualInput.focus();
  };

  container.querySelector('[data-manual-add]').addEventListener('click', submitManual);
  manualInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      submitManual();
    }
  });

  container.querySelector('[data-confirm-cart]').addEventListener('click', async (event) => {
    const vendorUuid = container.querySelector('[data-vendor]').value;
    const warehouseUuid = container.querySelector('[data-warehouse]').value;

    if (!vendorUuid) {
      toast('Select a vendor first.', 'danger');
      return;
    }

    if (cart.length === 0) {
      toast('The cart is empty.', 'danger');
      return;
    }

    const button = event.currentTarget;
    setBusy(button, true, 'Saving');

    try {
      const response = await api.post('/admin/purchase-orders', {
        vendor_uuid: vendorUuid,
        warehouse_uuid: warehouseUuid,
        purchase_date: new Date().toISOString().slice(0, 10),
        invoice_reference: null,
        discount_amount: 0,
        other_charges: 0,
        tax_amount: 0,
        notes: 'Recorded via mobile scan',
        lines: cart.map((l) => ({
          variant_uuid: l.variant_uuid,
          quantity: l.quantity,
          unit_cost: l.unit_cost,
        })),
      });

      toast('Inward recorded. Stock and average cost are updated.');

      const pending = response.data.price_decisions_pending || [];

      if (pending.length > 0) {
        await resolvePriceDecisionQueue(pending, response.data.id);
      }

      cart = [];
      await stopScanner();
      renderScanMode(container);
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

async function renderLookupMode(container) {
  container.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h5 mb-0">Stock lookup</h1>
      <a class="btn btn-sm btn-outline-secondary" href="mobile.html?mode=scan">Scan inward</a>
    </div>
    <video id="scanner-video" playsinline muted hidden></video>
    <div class="d-grid mb-2"><button class="btn btn-outline-dark mobile-tap" data-start-scan>Scan to look up</button></div>
    <div class="input-group mb-3">
      <input class="form-control mobile-tap" data-manual-code placeholder="Or type a barcode or SKU" autofocus>
      <button class="btn btn-outline-secondary mobile-tap" data-manual-add>Look up</button>
    </div>
    <div data-result></div>`;

  const resultEl = container.querySelector('[data-result]');
  const manualInput = container.querySelector('[data-manual-code]');
  const video = container.querySelector('#scanner-video');

  const lookup = async (code) => {
    resultEl.innerHTML = '<p class="text-muted small">Looking up…</p>';

    try {
      const response = await api.get('/admin/inventory/lookup', { sku: code });
      const variant = response.data;
      const rows = (variant.stock || []).map((s) => `
        <tr><td>${escapeHtml(s.warehouse_name)}</td>
            <td class="text-end">${escapeHtml(Number(s.quantity))}</td></tr>`).join('');
      const total = (variant.stock || []).reduce((sum, s) => sum + Number(s.quantity), 0);

      resultEl.innerHTML = `
        <div class="card">
          <div class="card-body">
            <div class="fw-semibold">${escapeHtml(variant.product_name)}</div>
            <div class="small text-muted mb-2">${escapeHtml(variant.variant_name)} · ${escapeHtml(variant.sku)}</div>
            <table class="table table-tight table-sm mb-2">
              <thead><tr><th>Warehouse</th><th class="text-end">Qty</th></tr></thead>
              <tbody>${rows}</tbody>
            </table>
            <div class="fw-semibold">Total: ${escapeHtml(total)}</div>
          </div>
        </div>`;
    } catch (error) {
      resultEl.innerHTML = '';
      showError(error, resultEl);
    }
  };

  container.querySelector('[data-start-scan]').addEventListener('click', async (event) => {
    video.hidden = false;
    const button = event.currentTarget;
    setBusy(button, true, 'Starting camera');
    const started = await startScanner(video, (code) => { lookup(code); stopScanner(); video.hidden = true; });
    setBusy(button, false);
    if (!started) {
      video.hidden = true;
      toast('Camera scanning is not supported on this browser — use the text field below.', 'danger');
    }
  });

  const submitManual = () => {
    const code = manualInput.value.trim();
    if (code) lookup(code);
  };

  container.querySelector('[data-manual-add]').addEventListener('click', submitManual);
  manualInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      submitManual();
    }
  });
}

async function render() {
  await stopScanner();
  cart = [];

  if (state.mode === 'lookup') {
    await renderLookupMode(root);
  } else {
    await renderScanMode(root);
  }
}

const mounted = await mountConsole('mobile.html');

if (mounted) {
  root = mounted.root;

  try {
    const [vendorResponse, warehouseResponse] = await Promise.all([
      api.get('/admin/vendors', { per_page: 200, active_only: true }),
      api.get('/admin/warehouses', { active_only: true }),
    ]);
    vendors = vendorResponse.data || [];
    warehouses = warehouseResponse.data || [];
    render();
  } catch (error) {
    showError(error, root);
  }
}
