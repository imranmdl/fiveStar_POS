/**
 * Print helper for the Barcode Generator page — ported from
 * admin/assets/page-barcode-generator.js's printLabelsA4().
 *
 * Opens a small popup window with one label per item on an A4-sized sheet,
 * each rendered as a scannable barcode (via JsBarcode, loaded inside that
 * window — not this app, so browsing this screen never pays for the library
 * unless someone actually prints), and triggers the browser print dialog
 * once every barcode has drawn.
 *
 * Kept separate from purchaseInwardPrint.js's printBarcodeLabels() (a single
 * stacked-column popup for one or two labels at a time) because this one
 * needs a 2-up A4 grid sized for a full sheet of labels, with the price
 * shown under each item name — the two don't share a layout.
 */
import { toast } from '../../components/admin/toast';
import { formatMoney } from '../../lib/api';

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[c]);
}

export function printBarcodeSheetA4(items) {
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
  // EAN-13 (a real manufacturer code of an unusual length, say) simply fails
  // to draw its own label rather than blocking every other one.
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
