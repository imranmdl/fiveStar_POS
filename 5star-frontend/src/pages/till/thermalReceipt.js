/**
 * Thermal receipt printing for the till (80 mm roll by default, 58 mm optional).
 *
 * The receipt is written into a hidden iframe as its own small document, so
 * the till screen (product grid, cart, sidebars) is never part of the print
 * and none of the app's page CSS can widen it. The page size is set from the
 * paper width and the measured receipt height, so the printer feeds exactly
 * the length of the bill — no A4 page, no landscape, no blank tail.
 *
 * Only figures already on the sale are shown; nothing is recalculated except
 * the same display-only differences the on-screen receipt already shows
 * (amount due after wallet, balance on a credit sale).
 *
 * The A4 tax invoice (admin → Invoices) is separate and unchanged.
 */

export const PAPER_SIZES = {
  80: { label: '80 mm', pageMm: 80, contentMm: 72, fontPx: 12 },
  58: { label: '58 mm', pageMm: 58, contentMm: 48, fontPx: 10.5 },
};

const PAPER_KEY = 'till.receiptPaper';

/** The paper width chosen on this till (remembered per device). */
export function getReceiptPaper() {
  try {
    return localStorage.getItem(PAPER_KEY) === '58' ? 58 : 80;
  } catch {
    return 80;
  }
}

export function setReceiptPaper(width) {
  try {
    localStorage.setItem(PAPER_KEY, String(width === 58 ? 58 : 80));
  } catch {
    // Private mode etc.: falls back to 80 mm next time.
  }
}

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function num(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** 1234.5 → "1,234.50" (Indian grouping, no ₹ sign so columns stay narrow). */
function amt(value) {
  return num(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function qty(value) {
  const n = num(value);
  return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(3)));
}

/** "2026-10-09 10:23:05" → "09/10/2026 10:23 AM" */
function dateTime(value) {
  const raw = String(value || '').replace(' ', 'T');
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return esc(String(value || '').slice(0, 16));
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  let h = d.getHours();
  const ap = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${dd}/${mm}/${d.getFullYear()} ${h}:${String(d.getMinutes()).padStart(2, '0')} ${ap}`;
}

const METHOD_LABELS = { cash: 'Cash', upi: 'UPI', card: 'Card', other: 'Other' };

function row(label, value, cls = '') {
  return `<div class="r ${cls}"><span>${label}</span><span>${value}</span></div>`;
}

/** The receipt body (no <html> wrapper). */
export function receiptMarkup(sale, { logoUrl } = {}) {
  const storeName = sale.store_name || '5 Star Spices & Dry Fruits';
  const cityLine = [sale.store_city, sale.store_state].filter(Boolean).join(', ')
    + (sale.store_pincode ? ` - ${sale.store_pincode}` : '');
  const addressLines = [sale.store_address_line1, sale.store_address_line2, cityLine.trim()].filter(Boolean);

  const items = Array.isArray(sale.items) ? sale.items : [];
  const totalQty = items.reduce((sum, i) => sum + num(i.quantity), 0);

  const lines = items.map((item) => {
    const lineDiscount = num(item.discount_amount);
    return `
      <div class="item">
        <div class="item-name">${esc(item.product_name)}${item.variant_name ? ` <span class="muted">(${esc(item.variant_name)})</span>` : ''}</div>
        <div class="cols">
          <span class="c-qty">${qty(item.quantity)}</span>
          <span class="c-rate">${amt(item.unit_price)}</span>
          <span class="c-amt">${amt(item.line_total)}</span>
        </div>
        ${lineDiscount > 0 ? `<div class="muted small">  Item discount -${amt(lineDiscount)}</div>` : ''}
      </div>`;
  }).join('');

  const walletApplied = num(sale.wallet_applied);
  const grandTotal = num(sale.grand_total);
  const method = METHOD_LABELS[sale.payment_method] || esc(sale.payment_method || '');

  let payment = row('Payment', esc(method));
  if (walletApplied > 0) {
    payment += row('From wallet', `-${amt(walletApplied)}`);
    payment += row('Amount due', amt(grandTotal - walletApplied));
  }
  if (Number(sale.is_credit_sale) === 1) {
    // amount_paid already includes any wallet credit, as on the till screen.
    payment += row('Paid so far', amt(sale.amount_paid));
    payment += row('Balance due', amt(grandTotal - num(sale.amount_paid)), 'b');
  } else if (sale.payment_method === 'cash' && sale.amount_tendered != null) {
    payment += row('Cash received', amt(sale.amount_tendered));
    payment += row('Change', amt(sale.change_due), 'b');
  } else {
    payment += row(`Paid (${esc(method)})`, amt(grandTotal - walletApplied));
  }

  return `
    <div class="receipt">
      <div class="center">
        ${logoUrl ? `<img class="logo" src="${esc(logoUrl)}" alt="">` : ''}
        <div class="store">${esc(storeName)}</div>
        ${addressLines.map((l) => `<div class="small">${esc(l)}</div>`).join('')}
        ${sale.store_phone ? `<div class="small">Ph: ${esc(sale.store_phone)}</div>` : ''}
        ${sale.store_gstin ? `<div class="small">GSTIN: ${esc(sale.store_gstin)}</div>` : ''}
      </div>
      <div class="rule"></div>
      <div class="center b">RECEIPT</div>
      ${row('Bill No', esc(sale.sale_number))}
      ${row('Date', dateTime(sale.created_date))}
      ${sale.cashier_name ? row('Cashier', esc(sale.cashier_name)) : ''}
      ${sale.shop_label ? row('Counter', esc(sale.shop_label)) : ''}
      ${sale.customer_name ? row('Customer', esc(sale.customer_name)) : ''}
      ${sale.customer_mobile ? row('Mobile', esc(sale.customer_mobile)) : ''}
      <div class="rule"></div>
      <div class="cols head"><span class="c-qty">Qty</span><span class="c-rate">Rate</span><span class="c-amt">Amount</span></div>
      <div class="rule thin"></div>
      ${lines}
      <div class="rule"></div>
      ${row(`Items: ${items.length}`, `Qty: ${qty(totalQty)}`, 'small')}
      ${row('Subtotal', amt(sale.subtotal))}
      ${num(sale.discount_amount) > 0 ? row('Discount', `-${amt(sale.discount_amount)}`) : ''}
      ${row('GST (included)', amt(sale.tax_amount))}
      <div class="rule"></div>
      ${row('TOTAL', `&#8377; ${amt(grandTotal)}`, 'grand')}
      <div class="rule"></div>
      ${payment}
      ${sale.status === 'voided' ? '<div class="rule"></div><div class="center b">*** VOIDED ***</div>' : ''}
      <div class="rule"></div>
      <div class="center thanks">Thank you! Visit again.</div>
      <div class="center small">Prices include GST</div>
    </div>`;
}

export function receiptDocument(sale, paper, { logoUrl, heightMm } = {}) {
  const p = PAPER_SIZES[paper] || PAPER_SIZES[80];
  const sideMm = (p.pageMm - p.contentMm) / 2;
  const pageSize = heightMm ? `${p.pageMm}mm ${Math.ceil(heightMm)}mm` : `${p.pageMm}mm 297mm`;

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(sale.sale_number)}</title>
<style>
  @page { size: ${pageSize}; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; color: #000; }
  body { width: ${p.pageMm}mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .receipt {
    width: ${p.contentMm}mm; margin: 0 ${sideMm}mm; padding: 2mm 0 4mm;
    font-family: "Courier New", Consolas, monospace; font-size: ${p.fontPx}px; line-height: 1.3;
  }
  .center { text-align: center; }
  .b { font-weight: 700; }
  .small { font-size: 0.88em; }
  .muted { color: #000; font-weight: 400; }
  .logo { width: ${paper === 58 ? 16 : 20}mm; height: auto; display: block; margin: 0 auto 1mm; filter: grayscale(1) contrast(1.4); }
  .store { font-size: 1.3em; font-weight: 700; margin-bottom: 0.5mm; }
  .rule { border-top: 1px dashed #000; margin: 1.2mm 0; }
  .rule.thin { margin: 0.6mm 0; }
  .r { display: flex; justify-content: space-between; gap: 2mm; }
  .r > span:first-child { flex: 0 1 auto; }
  .r > span:last-child { text-align: right; word-break: break-word; }
  .r.b { font-weight: 700; }
  .r.grand { font-size: 1.25em; font-weight: 700; }
  .item { margin-bottom: 0.8mm; break-inside: avoid; }
  .item-name { font-weight: 700; word-break: break-word; }
  .cols { display: flex; }
  .cols.head { font-weight: 700; }
  .c-qty { flex: 0 0 22%; }
  .c-rate { flex: 0 0 36%; text-align: right; }
  .c-amt { flex: 1 1 auto; text-align: right; }
  .thanks { font-weight: 700; margin-top: 1mm; }
</style></head><body>${receiptMarkup(sale, { logoUrl })}</body></html>`;
}

function waitForImages(doc) {
  const imgs = Array.from(doc.images || []);
  return Promise.all(imgs.map((img) => (img.complete ? null : new Promise((resolve) => {
    img.onload = resolve;
    img.onerror = () => { img.remove(); resolve(); };
  }))));
}

/**
 * Prints the sale on the thermal printer: builds the receipt in a hidden
 * iframe, measures it, sets the page to paper width × receipt length, and
 * opens the print dialog for just that receipt.
 */
export async function printThermalReceipt(sale, paper = getReceiptPaper()) {
  const logoUrl = `${window.location.origin}/brand/logo-192.png`;
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  document.body.appendChild(frame);

  const write = (html) => {
    const doc = frame.contentDocument;
    doc.open();
    doc.write(html);
    doc.close();
    return doc;
  };

  try {
    // First pass at a tall page to measure the receipt's real length.
    let doc = write(receiptDocument(sale, paper, { logoUrl }));
    await waitForImages(doc);
    const px = doc.querySelector('.receipt').getBoundingClientRect().height;
    const heightMm = px * 25.4 / 96 + 2;

    // Second pass with the page cut to exactly that length.
    doc = write(receiptDocument(sale, paper, { logoUrl, heightMm }));
    await waitForImages(doc);

    await new Promise((resolve) => {
      const win = frame.contentWindow;
      const done = () => setTimeout(resolve, 500);
      win.addEventListener('afterprint', done, { once: true });
      win.focus();
      win.print();
      // Some browsers don't fire afterprint for iframes; print() blocks until
      // the dialog closes in those, so this still cleans up afterwards.
      setTimeout(resolve, 1000);
    });
  } finally {
    setTimeout(() => frame.remove(), 1500);
  }
}
