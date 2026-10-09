import JsBarcode from 'jsbarcode';

/**
 * Barcode labels: product name, SKU and a scannable barcode, printed one per
 * label on a thermal label roll (50 × 25 mm) or as a grid on an A4 sheet.
 *
 * Barcodes are drawn here in the app (no download at print time):
 *  - EAN-13 for 13-digit codes with a valid check digit — what "Generate
 *    Barcode" creates, and what most shop scanners read best;
 *  - Code 128 for anything else (letters, dashes, other lengths), so an
 *    existing SKU like "SPC-001" still prints as a scannable code.
 * Both are read by ordinary USB/Bluetooth scanners and the till's camera.
 */

export const LABEL_LAYOUTS = {
  roll: { label: 'Label roll 50 × 25 mm', widthMm: 50, heightMm: 25 },
  sheet: { label: 'A4 sheet (3 × 8)', widthMm: 63.5, heightMm: 33.9 },
};

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function validEan13(code) {
  if (!/^\d{13}$/.test(code)) return false;
  const digits = code.split('').map(Number);
  const sum = digits.slice(0, 12).reduce((s, d, i) => s + d * (i % 2 === 0 ? 1 : 3), 0);
  return (10 - (sum % 10)) % 10 === digits[12];
}

export function barcodeFormat(code) {
  return validEan13(String(code)) ? 'EAN13' : 'CODE128';
}

/** The barcode as an SVG string, or '' if the value can't be encoded. */
export function barcodeSvg(code, { height = 40, moduleWidth = 2 } = {}) {
  const value = String(code || '').trim();
  if (!value) return '';
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  try {
    JsBarcode(svg, value, {
      format: barcodeFormat(value),
      displayValue: false,
      // Scanners need a blank "quiet zone" either side of the bars (about 10
      // bar-widths); without it longer Code 128 codes don't read.
      marginTop: 0,
      marginBottom: 0,
      marginLeft: moduleWidth * 10,
      marginRight: moduleWidth * 10,
      height,
      width: moduleWidth,
      background: '#ffffff',
      lineColor: '#000000',
    });
  } catch {
    return '';
  }
  svg.setAttribute('preserveAspectRatio', 'none');
  return svg.outerHTML;
}

/**
 * The code the label encodes: the barcode, else the SKU. After "Generate
 * Barcode" the two are the same value; for older items whose barcode differs
 * from the SKU, the barcode keeps new labels identical to ones already on
 * the shelf. The till finds an item by either.
 */
export function labelCode(item) {
  return String(item.barcode || item.sku || '').trim();
}

function labelHtml(item, layout) {
  const code = labelCode(item);
  const name = [item.product_name, item.variant_name].filter(Boolean).join(' — ');
  const svg = barcodeSvg(code, { height: layout === 'roll' ? 34 : 40 });
  return `
    <div class="label">
      <div class="name">${esc(name)}</div>
      <div class="bc">${svg || '<div class="bad">Cannot draw this code</div>'}</div>
      <div class="code">${esc(code)}</div>
      <div class="meta"><span>SKU: <b>${esc(item.sku || '—')}</b></span>${item.selling_price != null && item.selling_price !== '' ? `<span>&#8377;${esc(Number(item.selling_price).toFixed(2))}</span>` : ''}</div>
    </div>`;
}

export function labelsDocument(items, { layout = 'roll', copies = 1 } = {}) {
  const l = LABEL_LAYOUTS[layout] || LABEL_LAYOUTS.roll;
  const list = [];
  items.forEach((item) => { for (let i = 0; i < Math.max(1, copies); i += 1) list.push(item); });
  const isRoll = layout === 'roll';

  return `<!doctype html><html><head><meta charset="utf-8"><title>Barcode labels</title>
<style>
  @page { size: ${isRoll ? `${l.widthMm}mm ${l.heightMm}mm` : 'A4 portrait'}; margin: ${isRoll ? '0' : '10mm 7mm'}; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; }
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .sheet { ${isRoll ? '' : `display: grid; grid-template-columns: repeat(3, ${l.widthMm}mm); grid-auto-rows: ${l.heightMm}mm; column-gap: 2.5mm;`} }
  .label {
    width: ${l.widthMm}mm; height: ${l.heightMm}mm; overflow: hidden;
    padding: ${isRoll ? '1.2mm 1mm' : '2mm 2mm'};
    display: flex; flex-direction: column; justify-content: space-between; align-items: stretch;
    break-inside: avoid; page-break-inside: avoid;
    ${isRoll ? 'page-break-after: always; break-after: page;' : 'border: 0.2mm dashed #bbb;'}
  }
  .label:last-child { page-break-after: auto; break-after: auto; }
  .name { font-size: ${isRoll ? '7.5pt' : '8.5pt'}; font-weight: 700; line-height: 1.1; max-height: 2.2em; overflow: hidden; text-align: center; }
  .bc { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; min-height: 0; padding: 0.6mm 0; }
  .bc svg { width: 100%; height: 100%; max-height: ${isRoll ? '10mm' : '13mm'}; display: block; }
  .bad { font-size: 7pt; }
  .code { font-size: ${isRoll ? '7.5pt' : '8.5pt'}; text-align: center; letter-spacing: 0.06em; font-family: "Courier New", monospace; font-weight: 700; }
  .meta { display: flex; justify-content: space-between; font-size: ${isRoll ? '6.5pt' : '7.5pt'}; }
  @media print { .label { ${isRoll ? '' : 'border-color: transparent;'} } }
</style></head><body><div class="sheet">${list.map((item) => labelHtml(item, layout)).join('')}</div></body></html>`;
}

/**
 * Codes too long to scan reliably on a 50 mm label at 203 dpi (Code 128
 * beyond ~14 characters gets bars thinner than the printer's dots). EAN-13
 * codes from "Generate Barcode" always fit.
 */
export function tooLongForRoll(item) {
  const code = labelCode(item);
  return barcodeFormat(code) === 'CODE128' && code.length > 14;
}

/** Prints labels from a hidden frame (no pop-up window needed). */
export async function printLabels(items, { layout = 'roll', copies = 1 } = {}) {
  const usable = items.filter((i) => labelCode(i));
  if (usable.length === 0) return 0;
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  document.body.appendChild(frame);
  const doc = frame.contentDocument;
  doc.open();
  doc.write(labelsDocument(usable, { layout, copies }));
  doc.close();
  await new Promise((resolve) => setTimeout(resolve, 150));
  try {
    frame.contentWindow.focus();
    frame.contentWindow.print();
  } finally {
    setTimeout(() => frame.remove(), 1500);
  }
  return usable.length;
}
