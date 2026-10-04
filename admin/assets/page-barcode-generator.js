/**
 * Barcode Generator: find a pack size, mint it a barcode if it doesn't
 * already have one (reuses InventoryService::assignBarcode() — the same
 * endpoint purchase-inward already calls to print a single label), and
 * print an A4 sheet of everything generated so far in this browser.
 *
 * The "recent" list lives in localStorage rather than the server — it's a
 * printing convenience (what's on the sheet right now), not a business
 * record; the barcode itself is the durable thing, already saved on the
 * variant the moment it's generated.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney, emptyState } from './console.js?v=9';

const STORAGE_KEY = 'spice.barcode_generator.recent';
const MAX_RECENT = 100;

let root = null;
let recent = [];

function loadRecent() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    recent = raw ? JSON.parse(raw) : [];
  } catch {
    recent = [];
  }

  // Entries saved before assignBarcode() returned the product name are
  // permanently missing it — no amount of refreshing fixes an already-saved
  // blank. Drop them here rather than leaving a dead row on the sheet that
  // nothing the user does will ever label correctly.
  const before = recent.length;
  recent = recent.filter((r) => r.product_name);
  if (recent.length !== before) saveRecent();
}

function saveRecent() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(recent));
  } catch {
    // A private window or full storage quota loses the print list on
    // reload — not worth failing over; the barcode itself is already saved.
  }
}

function addToRecent(variant) {
  recent = recent.filter((r) => r.uuid !== variant.uuid);
  recent.unshift({
    uuid: variant.uuid,
    sku: variant.sku,
    barcode: variant.barcode,
    product_name: variant.product_name,
    variant_name: variant.variant_name,
    selling_price: variant.selling_price,
  });
  recent = recent.slice(0, MAX_RECENT);
  saveRecent();
}

/**
 * Opens a small popup window with one label per item on an A4-sized sheet,
 * each rendered as a scannable barcode (via JsBarcode, loaded inside that
 * window — not this page, so browsing this screen never pays for the
 * library unless someone actually prints), and triggers the browser print
 * dialog once every barcode has drawn. Same technique
 * page-purchase-inward.js already uses for a single label, generalized
 * into a full sheet.
 */
function printLabelsA4(items) {
  const win = window.open('', '_blank', 'width=900,height=700');

  if (!win) {
    toast('Allow pop-ups for this site to print barcode labels.', 'danger');
    return;
  }

  const label = (item, index) => `
    <div class="label">
      <div class="title">${escapeHtml(item.product_name)} (${escapeHtml(item.variant_name)})</div>
      <div class="subtitle">${escapeHtml(formatMoney(item.selling_price))}</div>
      <div class="barcode-box">
        <svg id="bc${index}"></svg>
        <div class="code">${escapeHtml(item.barcode)}</div>
      </div>
    </div>`;

  // Wrapped in try/catch per label — a barcode value that isn't a valid
  // EAN-13 (a real manufacturer code of an unusual length, say) simply
  // fails to draw its own label rather than blocking every other one.
  const draw = (item, index) =>
    `try { JsBarcode("#bc${index}", ${JSON.stringify(item.barcode)}, { format: "EAN13", height: 55, fontSize: 12, margin: 4 }); } catch (e) {}`;

  win.document.write(`<!doctype html><html><head><title>Barcode labels — A4</title>
    <style>
      @page { size: A4; margin: 10mm; }
      * { box-sizing: border-box; }
      body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }
      .sheet { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
      .label {
        border: 1px dashed #999; border-radius: 4px; padding: 10px 8px;
        text-align: center; page-break-inside: avoid; break-inside: avoid;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
      }
      /* The item name is the whole point — big and unmistakable, sitting
         clearly above its own barcode box, so whoever is sticking labels on
         stock never has to guess which barcode belongs to which item. */
      .title { font-size: 17px; font-weight: 700; line-height: 1.25; margin-bottom: 2px; }
      .subtitle { font-size: 12px; color: #555; margin-bottom: 8px; }
      .barcode-box {
        border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px;
        width: 100%; background: #fff;
      }
      .code { font-size: 12px; letter-spacing: .5px; margin-top: 2px; }
      svg { max-width: 100%; }
      @media print { .label { border: none; } .barcode-box { border: 1px solid #999; } }
    </style>
  </head><body>
    <div class="sheet">${items.map(label).join('')}</div>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></${''}script>
    <script>
      window.onload = function () {
        ${items.map(draw).join('\n')}
        setTimeout(function () { window.print(); }, 250);
      };
    </${''}script>
  </body></html>`);
  win.document.close();
}

function recentRow(item) {
  return `
    <tr data-recent-row="${escapeHtml(item.uuid)}">
      <td>
        <span class="fw-semibold">${escapeHtml(item.product_name)} (${escapeHtml(item.variant_name)})</span>
        <div class="small text-muted">${escapeHtml(item.sku)}</div>
      </td>
      <td class="font-monospace small">${escapeHtml(item.barcode)}</td>
      <td class="text-end small">${formatMoney(item.selling_price)}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-danger" data-remove-recent type="button">Remove</button>
      </td>
    </tr>`;
}

function renderRecent() {
  const body = root.querySelector('[data-recent-body]');
  const empty = root.querySelector('[data-recent-empty]');
  const actions = root.querySelector('[data-recent-actions]');
  const count = root.querySelector('[data-recent-count]');

  count.textContent = recent.length;

  if (recent.length === 0) {
    body.innerHTML = '';
    empty.hidden = false;
    actions.hidden = true;
    return;
  }

  empty.hidden = true;
  actions.hidden = false;
  body.innerHTML = recent.map(recentRow).join('');

  body.querySelectorAll('[data-remove-recent]').forEach((button) => {
    button.addEventListener('click', () => {
      const uuid = button.closest('[data-recent-row]').dataset.recentRow;
      recent = recent.filter((r) => r.uuid !== uuid);
      saveRecent();
      renderRecent();
    });
  });
}

async function render() {
  root.innerHTML = `
    <h1 class="h4 mb-1">Barcode Generator</h1>
    <p class="text-muted small">
      Find a pack size below, generate its barcode if it doesn't have one
      yet, then print an A4 sheet of every label generated so far.
    </p>

    <div class="input-group my-3" style="max-width:32rem">
      <input class="form-control" data-search-input placeholder="Type a product name or SKU…" autocomplete="off" autofocus>
    </div>
    <div class="list-group mb-4" data-search-results style="max-width:32rem"></div>

    <div class="card">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Recently generated</span>
        <span class="small text-muted"><span data-recent-count>0</span> label(s)</span>
      </div>

      <div data-recent-empty class="card-body">
        ${emptyState('No barcodes generated yet', 'Search for an item above to get started.')}
      </div>

      <div class="table-responsive">
        <table class="table table-tight mb-0">
          <thead><tr><th>Item</th><th>Barcode</th><th class="text-end">Price</th><th></th></tr></thead>
          <tbody data-recent-body></tbody>
        </table>
      </div>

      <div class="card-footer bg-white d-flex justify-content-end gap-2" data-recent-actions hidden>
        <button class="btn btn-sm btn-outline-danger" data-clear-recent type="button">Clear list</button>
        <button class="btn btn-sm btn-dark" data-print-recent type="button">Print sheet (A4)</button>
      </div>
    </div>`;

  const searchInput = root.querySelector('[data-search-input]');
  const resultsBox = root.querySelector('[data-search-results]');
  let searchTimer = null;

  const clearResults = () => { resultsBox.innerHTML = ''; };

  const search = async (text) => {
    if (text.length < 2) { clearResults(); return; }

    try {
      const response = await api.get('/admin/inventory/search', { q: text });
      const matches = response.data || [];

      resultsBox.innerHTML = matches.length === 0
        ? '<div class="list-group-item small text-muted">No items match.</div>'
        : matches.map((m, i) => `
            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-result="${i}">
              <span>
                <span class="fw-semibold d-block">${escapeHtml(m.product_name)} (${escapeHtml(m.variant_name)})</span>
                <span class="text-muted small d-block">${escapeHtml(m.sku)}</span>
              </span>
              <span class="small text-muted flex-shrink-0 ms-2">Generate →</span>
            </button>`).join('');

      resultsBox.querySelectorAll('[data-result]').forEach((button) => {
        button.addEventListener('click', async () => {
          const match = matches[Number(button.dataset.result)];
          setBusy(button, true, 'Generating');

          try {
            const response = await api.post(`/admin/inventory/variants/${encodeURIComponent(match.uuid)}/barcode`, {});
            addToRecent(response.data.variant);
            renderRecent();
            toast(`Barcode ready for ${match.product_name} — ${match.variant_name}.`);
            searchInput.value = '';
            clearResults();
            searchInput.focus();
          } catch (error) {
            setBusy(button, false);
            showError(error);
          }
        });
      });
    } catch {
      // A search hiccup just means no suggestions this keystroke.
    }
  };

  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const text = searchInput.value.trim();

    if (text === '') { clearResults(); return; }

    searchTimer = setTimeout(() => search(text), 300);
  });

  root.querySelector('[data-clear-recent]').addEventListener('click', () => {
    if (!window.confirm('Clear the whole print list? The barcodes themselves stay saved on each item.')) return;
    recent = [];
    saveRecent();
    renderRecent();
  });

  root.querySelector('[data-print-recent]').addEventListener('click', () => printLabelsA4(recent));

  renderRecent();
}

const mounted = await mountConsole('barcode-generator.html');

if (mounted) {
  root = mounted.root;
  loadRecent();
  render();
}
