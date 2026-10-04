/**
 * Print helpers for Purchase Inward — ported from admin/assets/page-purchase-inward.js
 * (printBarcodeLabels / printPurchaseOrder). Plain DOM/window.open, no React
 * involved: each opens a small popup, writes a self-contained print-ready
 * document, and triggers the browser's print dialog. "Download" is the
 * browser's own print-to-PDF, same as the live admin console.
 */
import { toast } from '../../components/admin/toast.js';
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

/**
 * Opens a small popup window with one label per item, each rendered as a
 * scannable EAN-13 (via JsBarcode, loaded inside that window — not this app),
 * and triggers the browser print dialog once the barcodes have drawn.
 */
export function printBarcodeLabels(labels) {
  const win = window.open('', '_blank', 'width=420,height=600');

  if (!win) {
    toast('Allow pop-ups for this site to print barcode labels.', 'danger');
    return;
  }

  const label = (item, index) => `
    <div class="label">
      <div class="title">${escapeHtml(item.title)}</div>
      ${item.subtitle ? `<div class="subtitle">${escapeHtml(item.subtitle)}</div>` : ''}
      <div class="barcode-box">
        <svg id="bc${index}"></svg>
        <div class="code">${escapeHtml(item.barcode)}</div>
      </div>
    </div>`;

  const draw = (item, index) =>
    `try { JsBarcode("#bc${index}", ${JSON.stringify(item.barcode)}, { format: "EAN13", height: 50, fontSize: 13, margin: 4 }); } catch (e) {}`;

  win.document.write(`<!doctype html><html><head><title>Barcode labels</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }
      .label {
        border: 1px dashed #999; border-radius: 4px; padding: 10px 8px; margin-bottom: 10px;
        text-align: center; page-break-inside: avoid;
      }
      .title { font-size: 16px; font-weight: 700; }
      .subtitle { font-size: 12px; color: #555; margin-bottom: 8px; }
      .barcode-box { border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px; background: #fff; }
      .code { font-size: 12px; letter-spacing: 1px; margin-top: 2px; }
      svg { max-width: 100%; }
      @media print { .label { border: none; } .barcode-box { border: 1px solid #999; } }
    </style>
  </head><body>
    ${labels.map(label).join('')}
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></${''}script>
    <script>
      window.onload = function () {
        ${labels.map(draw).join('\n')}
        setTimeout(function () { window.print(); }, 250);
      };
    </${''}script>
  </body></html>`);
  win.document.close();
}

/** The purchase order as a printable document — same popup + window.print() pattern. */
export function printPurchaseOrder(po) {
  const win = window.open('', '_blank', 'width=800,height=900');

  if (!win) {
    toast('Allow pop-ups for this site to print the purchase order.', 'danger');
    return;
  }

  const itemRow = (item) => `
    <tr>
      <td>${escapeHtml(item.sku)}<div class="muted">${escapeHtml(item.variant_name)}</div></td>
      <td>${escapeHtml(item.batch_no || '—')}</td>
      <td>${escapeHtml(item.expiry_date || '—')}</td>
      <td class="num">${escapeHtml(Number(item.quantity))}</td>
      <td class="num">${formatMoney(item.unit_cost)}</td>
      <td class="num">${item.gst_rate ? escapeHtml(item.gst_rate) + '%' : '—'}</td>
      <td class="num">${formatMoney(Number(item.quantity) * Number(item.unit_cost))}</td>
    </tr>`;

  win.document.write(`<!doctype html><html><head><title>${escapeHtml(po.po_number)}</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 24px; color: #1a1a1a; }
      h1 { font-size: 18px; margin-bottom: 2px; }
      .muted { color: #666; font-size: 11px; }
      table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12px; }
      th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
      .num { text-align: right; }
      .totals { width: 260px; margin-left: auto; margin-top: 12px; font-size: 12px; }
      .totals td { border: none; padding: 2px 4px; }
      .totals .grand td { font-weight: 700; border-top: 1px solid #333; }
      @media print { .no-print { display: none; } }
    </style>
  </head><body>
    <h1>Purchase Order ${escapeHtml(po.po_number)}</h1>
    <div class="muted">Vendor: ${escapeHtml(po.vendor_name)} · Warehouse: ${escapeHtml(po.warehouse_name)} · Date: ${escapeHtml(String(po.purchase_date || '').slice(0, 10))}</div>
    <table>
      <thead><tr><th>Item</th><th>Batch</th><th>Expiry</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">GST</th><th class="num">Line total</th></tr></thead>
      <tbody>${(po.items || []).map(itemRow).join('')}</tbody>
    </table>
    <table class="totals">
      <tr><td>Items subtotal</td><td class="num">${formatMoney(po.items_subtotal)}</td></tr>
      <tr><td>Discount</td><td class="num">${formatMoney(po.discount_amount)}</td></tr>
      <tr><td>Transportation</td><td class="num">${formatMoney(po.transport_charge)}</td></tr>
      <tr><td>Other charges</td><td class="num">${formatMoney(po.other_charges)}</td></tr>
      <tr><td>Tax</td><td class="num">${formatMoney(po.tax_amount)}</td></tr>
      <tr class="grand"><td>Grand total</td><td class="num">${formatMoney(po.grand_total)}</td></tr>
      <tr><td>Paid</td><td class="num">${formatMoney(po.amount_paid)}</td></tr>
      <tr><td>Returned</td><td class="num">${formatMoney(po.amount_returned)}</td></tr>
    </table>
    <script>window.onload = function () { setTimeout(function () { window.print(); }, 150); };</${''}script>
  </body></html>`);
  win.document.close();
}
