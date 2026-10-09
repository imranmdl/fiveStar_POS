import { buildUpiUri, qrSvg } from '../../lib/upiQr.js';

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
  80: { label: '80 mm', pageMm: 80, contentMm: 72, fontPx: 12.5 },
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

/** The shop's name as printed: "5 Star …" is written out as "Five Star …". */
export function receiptStoreName(name) {
  const raw = String(name || '').trim() || 'Five Star Spices & Dry Fruits';
  return raw.replace(/^5\s*(?:-|\s)?\s*star/i, 'Five Star');
}

function row(label, value, cls = '') {
  return `<div class="r ${cls}"><span class="l">${label}</span><span class="v">${value}</span></div>`;
}

/** GST included in the bill, grouped by rate, from the saved line figures. */
function gstSummary(items) {
  const byRate = new Map();
  items.forEach((item) => {
    const rate = num(item.gst_rate);
    const tax = num(item.tax_amount);
    const entry = byRate.get(rate) || { taxable: 0, tax: 0 };
    entry.taxable += num(item.line_total) - tax;
    entry.tax += tax;
    byRate.set(rate, entry);
  });
  return [...byRate.entries()].sort((a, b) => a[0] - b[0]).map(([rate, e]) => {
    const tax = Math.round(e.tax * 100) / 100;
    // Counter sales are within the state: GST is shown as equal CGST + SGST.
    const cgst = Math.round((tax / 2) * 100) / 100;
    return { rate, taxable: e.taxable, cgst, sgst: Math.round((tax - cgst) * 100) / 100 };
  });
}

/**
 * The receipt body (no <html> wrapper).
 * `reprint` marks a copy printed again from Sales history.
 */
export function receiptMarkup(sale, { logoUrl, reprint = false, printedAt = new Date(), paper = 80 } = {}) {
  // `shop` comes from Admin → Payments → Settings → Shop details (address
  // and phone fall back to the till's warehouse). Older responses without it
  // still print from the sale's own store_* fields.
  const shop = sale.shop || {
    name: sale.store_name, address_line1: sale.store_address_line1, address_line2: sale.store_address_line2,
    city: sale.store_city, state: sale.store_state, pincode: sale.store_pincode, phone: sale.store_phone,
    gstin: sale.store_gstin, website: '', email: '', upi: null,
  };
  const storeName = receiptStoreName(shop.name);
  const brand = storeName.match(/^(Five Star)\s+(.+)$/i);
  const cityLine = [shop.city, shop.state].filter(Boolean).join(', ')
    + (shop.pincode ? ` - ${shop.pincode}` : '');
  const addressLines = [shop.address_line1, shop.address_line2, cityLine.trim()].filter(Boolean);
  const website = String(shop.website || '').replace(/^https?:\/\//i, '').replace(/\/$/, '');

  const items = Array.isArray(sale.items) ? sale.items : [];
  const totalQty = items.reduce((sum, i) => sum + num(i.quantity), 0);
  const [datePart, timePart] = (() => {
    const t = dateTime(sale.created_date);
    const i = t.indexOf(' ');
    return i > 0 ? [t.slice(0, i), t.slice(i + 1)] : [t, ''];
  })();

  const lines = items.map((item, index) => {
    const lineDiscount = num(item.discount_amount);
    return `
      <div class="item">
        <div class="item-name"><span class="sn">${index + 1}.</span>${esc(item.product_name)}${item.variant_name ? ` <span class="var">${esc(item.variant_name)}</span>` : ''}</div>
        <div class="grid nums">
          <span class="c-qty">${qty(item.quantity)}</span>
          <span class="c-rate">${amt(item.unit_price)}</span>
          <span class="c-amt">${amt(item.line_total)}</span>
        </div>
        ${lineDiscount > 0 ? `<div class="disc">Discount on item &minus;${amt(lineDiscount)}</div>` : ''}
      </div>`;
  }).join('');

  const gst = gstSummary(items);
  const gstRows = gst.map((g) => `
        <div class="grid4 nums"><span>${qty(g.rate)}%</span><span>${amt(g.taxable)}</span><span>${amt(g.cgst)}</span><span>${amt(g.sgst)}</span></div>`).join('');

  const walletApplied = num(sale.wallet_applied);
  const grandTotal = num(sale.grand_total);
  const discount = num(sale.discount_amount);
  const method = METHOD_LABELS[sale.payment_method] || esc(sale.payment_method || '');
  const isCredit = Number(sale.is_credit_sale) === 1;
  const isSplit = walletApplied > 0 && grandTotal - walletApplied > 0.004;

  let payment = row('Payment mode', `<b>${isSplit ? 'Split payment' : esc(walletApplied >= grandTotal - 0.004 && walletApplied > 0 ? 'Wallet' : method)}</b>`);
  if (walletApplied > 0) {
    payment += row('&nbsp;&nbsp;Wallet', amt(walletApplied));
    if (isSplit) payment += row(`&nbsp;&nbsp;${esc(method)}`, amt(grandTotal - walletApplied));
  }
  if (isCredit) {
    // amount_paid already includes any wallet credit, as on the till screen.
    payment += row('Amount paid', amt(sale.amount_paid));
    payment += row('Balance due', amt(grandTotal - num(sale.amount_paid)), 'b');
  } else if (sale.payment_method === 'cash' && sale.amount_tendered != null) {
    payment += row('Cash received', amt(sale.amount_tendered));
    payment += row('Change returned', amt(sale.change_due), 'b');
  } else {
    payment += row('Amount paid', amt(grandTotal));
  }

  const pDate = dateTime(printedAt.toISOString());

  // UPI QR only for money still owed on this bill (a part-paid / credit
  // sale). A bill already settled by cash, UPI, card or wallet gets no QR, so
  // nobody is asked to pay twice. The amount is the bill's own balance.
  const balanceDue = isCredit && sale.status !== 'voided'
    ? Math.round((grandTotal - num(sale.amount_paid)) * 100) / 100
    : 0;
  let qrBlock = '';
  if (balanceDue > 0.004) {
    const uri = shop.upi
      ? buildUpiUri({ vpa: shop.upi.vpa, payeeName: shop.upi.payee_name, amount: balanceDue, note: `Bill ${sale.sale_number}` })
      : null;
    qrBlock = uri
      ? `<div class="rule solid"></div>
      <section class="upi center">
        <div class="upi-title">Scan &amp; Pay &#8377;${amt(balanceDue)}</div>
        <div class="upi-qr">${qrSvg(uri, { sizeMm: paper === 58 ? 30 : 36 })}</div>
        <div class="upi-id">UPI ID: <b>${esc(shop.upi.vpa)}</b></div>
        <div class="fine">GPay &middot; PhonePe &middot; Paytm &middot; BHIM &middot; any UPI app</div>
        <div class="fine">Balance due on bill ${esc(sale.sale_number)}</div>
      </section>`
      : `<div class="rule solid"></div><div class="center b">Balance due &#8377;${amt(balanceDue)} &mdash; please pay at the counter.</div>`;
  }

  return `
    <div class="receipt">
      <header class="center">
        ${logoUrl ? `<img class="logo" src="${esc(logoUrl)}" alt="">` : ''}
        ${brand
          ? `<div class="brand">${esc(brand[1].toUpperCase())}</div><div class="tagline">${esc(brand[2].toUpperCase())}</div>`
          : `<div class="brand">${esc(storeName.toUpperCase())}</div>`}
        <div class="addr">
          ${addressLines.map((l) => `<div>${esc(l)}</div>`).join('')}
          ${shop.phone ? `<div>Tel: ${esc(shop.phone)}</div>` : ''}
          ${shop.email ? `<div>${esc(shop.email)}</div>` : ''}
          ${shop.gstin ? `<div>GSTIN: ${esc(shop.gstin)}</div>` : ''}
        </div>
      </header>

      <div class="band">${reprint ? 'DUPLICATE RECEIPT' : 'SALES RECEIPT'}</div>

      <section class="meta">
        ${row('Receipt No', `<b>${esc(sale.sale_number)}</b>`)}
        ${row('Date', datePart)}
        ${row('Time', timePart)}
        ${sale.cashier_name ? row('Cashier', esc(sale.cashier_name)) : ''}
        ${sale.shop_label ? row('Counter', esc(sale.shop_label)) : ''}
        ${sale.customer_name ? row('Customer', esc(sale.customer_name)) : ''}
        ${sale.customer_mobile ? row('Mobile', esc(sale.customer_mobile)) : ''}
      </section>

      <div class="rule solid"></div>
      <div class="thead">
        <div>ITEM</div>
        <div class="grid"><span class="c-qty">QTY</span><span class="c-rate">RATE</span><span class="c-amt">AMOUNT</span></div>
      </div>
      <div class="rule solid"></div>
      ${lines}
      <div class="rule"></div>

      <section class="totals nums">
        ${row(`Items ${items.length} &middot; Qty ${qty(totalQty)}`, '', 'small')}
        ${row('Subtotal', amt(sale.subtotal))}
        ${discount > 0 ? row('Discount', `&minus;${amt(discount)}`) : ''}
        ${row('GST (included)', amt(sale.tax_amount))}
      </section>

      <div class="grand"><span>GRAND TOTAL</span><span class="nums">&#8377;${amt(grandTotal)}</span></div>

      <section class="pay nums">${payment}</section>

      ${gst.length ? `
      <div class="rule"></div>
      <section class="gst">
        <div class="sub">GST SUMMARY (included in prices)</div>
        <div class="grid4 head"><span>Rate</span><span>Taxable</span><span>CGST</span><span>SGST</span></div>
        ${gstRows}
      </section>` : ''}

      ${qrBlock}

      ${discount > 0 ? `<div class="saved">You saved &#8377;${amt(discount)} on this bill</div>` : ''}
      ${sale.status === 'voided' ? '<div class="band">*** VOIDED ***</div>' : ''}

      <div class="rule solid"></div>
      <footer class="center">
        <div class="thanks">Thank you for shopping with us!</div>
        <div>We look forward to serving you again.</div>
        <div class="fine">${esc(storeName)}</div>
        ${website ? `<div class="web">${esc(website)}</div>` : ''}
        ${reprint ? `<div class="fine">Reprinted ${pDate}</div>` : ''}
      </footer>
    </div>`;
}

export function receiptDocument(sale, paper, { logoUrl, heightMm, reprint = false } = {}) {
  const p = PAPER_SIZES[paper] || PAPER_SIZES[80];
  const sideMm = (p.pageMm - p.contentMm) / 2;
  const pageSize = heightMm ? `${p.pageMm}mm ${Math.ceil(heightMm)}mm` : `${p.pageMm}mm 297mm`;
  const narrow = paper === 58;

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(sale.sale_number)}</title>
<style>
  @page { size: ${pageSize}; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; color: #000; }
  body { width: ${p.pageMm}mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .receipt {
    width: ${p.contentMm}mm; margin: 0 ${sideMm}mm; padding: 1.5mm 0 3mm;
    font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
    font-size: ${p.fontPx}px; line-height: 1.32; font-weight: 500;
  }
  .nums, .grid, .grid4, .r .v { font-variant-numeric: tabular-nums; }
  .center { text-align: center; }
  b, .b { font-weight: 700; }
  .small { font-size: 0.9em; }

  .logo { width: ${narrow ? 15 : 19}mm; height: auto; display: block; margin: 0 auto 1mm; filter: grayscale(1) contrast(1.3); }
  .brand { font-size: ${narrow ? 1.55 : 1.75}em; font-weight: 800; letter-spacing: 0.06em; line-height: 1.1; }
  .tagline { font-size: 0.86em; font-weight: 700; letter-spacing: ${narrow ? 0.08 : 0.16}em; margin-top: 0.4mm; }
  .addr { font-size: 0.9em; margin-top: 1.2mm; line-height: 1.3; }

  .band {
    background: #000; color: #fff; text-align: center; font-weight: 800;
    letter-spacing: 0.12em; padding: 0.9mm 0; margin: 2mm 0 1.6mm; font-size: 0.95em;
  }

  .rule { border-top: 1px dashed #000; margin: 1.4mm 0; }
  .rule.solid { border-top: 1.5px solid #000; }

  .r { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
  .r .l { flex: 0 1 auto; }
  .r .v { flex: 0 0 auto; text-align: right; max-width: 65%; word-break: break-word; }
  .r.b { font-weight: 800; }
  .meta .r { line-height: 1.38; }

  .thead { font-weight: 800; font-size: 0.9em; letter-spacing: 0.04em; }
  .grid { display: grid; grid-template-columns: ${narrow ? '20% 36% 44%' : '22% 36% 42%'}; }
  .c-qty { text-align: left; padding-left: ${narrow ? 0 : 3.5}mm; }
  .c-rate, .c-amt { text-align: right; }
  .item { padding: 0.9mm 0; break-inside: avoid; }
  .item + .item { border-top: 1px dotted #000; }
  .item-name { font-weight: 700; word-break: break-word; }
  .sn { display: inline-block; min-width: ${narrow ? 3 : 3.5}mm; }
  .var { font-weight: 500; }
  .item .grid .c-amt { font-weight: 700; }
  .disc { font-size: 0.88em; padding-left: ${narrow ? 0 : 3.5}mm; }

  .totals .r { padding: 0.2mm 0; }
  .grand {
    display: flex; justify-content: space-between; align-items: center;
    border-top: 2px solid #000; border-bottom: 2px solid #000;
    margin: 1.6mm 0; padding: 1.2mm 0;
    font-size: ${narrow ? 1.2 : 1.35}em; font-weight: 800;
  }
  .pay .r { padding: 0.2mm 0; }

  .gst { font-size: 0.82em; }
  .gst .sub { font-weight: 800; margin-bottom: 0.6mm; }
  .grid4 { display: grid; grid-template-columns: 16% 30% 27% 27%; }
  .grid4 span:not(:first-child) { text-align: right; }
  .grid4.head { font-weight: 700; border-bottom: 1px solid #000; padding-bottom: 0.3mm; margin-bottom: 0.3mm; }

  .saved { text-align: center; font-weight: 800; border: 1.5px dashed #000; padding: 0.9mm 0; margin: 2mm 0 0; }

  footer { line-height: 1.38; }
  .thanks { font-weight: 800; font-size: 1.08em; margin-bottom: 0.3mm; }
  .fine { font-size: 0.82em; margin-top: 0.6mm; }
  .upi { margin-top: 1mm; }
  .upi-title { font-size: ${narrow ? 1.15 : 1.3}em; font-weight: 800; margin-bottom: 1mm; }
  .upi-qr svg { display: block; margin: 0 auto; }
  .upi-id { margin-top: 1mm; word-break: break-all; }
  .web { font-weight: 700; margin-top: 0.6mm; }
</style></head><body>${receiptMarkup(sale, { logoUrl, reprint, paper })}</body></html>`;
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
export async function printThermalReceipt(sale, paper = getReceiptPaper(), { reprint = false } = {}) {
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
    let doc = write(receiptDocument(sale, paper, { logoUrl, reprint }));
    await waitForImages(doc);
    const px = doc.querySelector('.receipt').getBoundingClientRect().height;
    const heightMm = px * 25.4 / 96 + 2;

    // Second pass with the page cut to exactly that length.
    doc = write(receiptDocument(sale, paper, { logoUrl, heightMm, reprint }));
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
