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
export function receiptMarkup(sale, { logoUrl, reprint = false, printedAt = new Date(), paper = 80, mode = 'receipt' } = {}) {
  // mode 'bill': printed from the Sell screen BEFORE payment — nothing is
  // recorded yet; it shows what to pay and a QR for that amount.
  const isBill = mode === 'bill';
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

  const show = { cashier: true, counter: true, customer: true, upi_qr: true, offer: true, ...(shop.receipt || {}) };

  // Optional offer box: the customer's own coupon, else the shop's chosen
  // coupon or offer message (picked by the server; see ReceiptOfferService).
  const offer = !isBill && show.offer !== false && sale.status !== 'voided' ? sale.offer : null;
  let offerBlock = '';
  if (offer && offer.kind === 'coupon') {
    const validTo = offer.valid_to ? offer.valid_to.split('-').reverse().join('/') : '';
    const conditions = [
      offer.min_order_value ? `On orders above &#8377;${amt(offer.min_order_value)}` : '',
      validTo ? `Valid till ${esc(validTo)}` : '',
    ].filter(Boolean).join(' &middot; ');
    offerBlock = `
      <section class="offer center">
        <div class="offer-kicker">${offer.personal ? 'A SPECIAL OFFER JUST FOR YOU' : 'SPECIAL OFFER'}</div>
        <div class="offer-head">${esc(offer.headline)}</div>
        ${offer.title ? `<div class="offer-title">${esc(offer.title)}</div>` : ''}
        <div class="offer-use">Use code</div>
        <div class="offer-code">${esc(offer.code)}</div>
        ${conditions ? `<div class="fine">${conditions}</div>` : ''}
        ${website ? `<div class="fine">Shop online at <b>${esc(website)}</b></div>` : ''}
      </section>`;
  } else if (offer && offer.kind === 'message') {
    offerBlock = `
      <section class="offer center">
        <div class="offer-kicker">SPECIAL OFFER</div>
        <div class="offer-msg">${esc(offer.message)}</div>
      </section>`;
  }

  // MRP vs the price charged, per line and for the bill. All display-only:
  // the bill's own figures (subtotal, discount, GST, total) are printed as
  // saved. A line without an MRP (or priced above it) counts at its price.
  let mrpTotal = 0;
  const lines = items.map((item, index) => {
    const lineDiscount = num(item.discount_amount);
    const price = num(item.unit_price);
    const mrp = item.mrp != null && num(item.mrp) > 0 ? num(item.mrp) : null;
    mrpTotal += Math.max(mrp ?? price, price) * num(item.quantity);
    return `
      <div class="item">
        <div class="item-name"><span class="sn">${index + 1}.</span>${esc(item.product_name)}${item.variant_name ? ` <span class="var">${esc(item.variant_name)}</span>` : ''}</div>
        <div class="grid nums">
          <span class="c-qty">${qty(item.quantity)}</span>
          <span class="c-mrp${mrp != null && mrp > price ? ' strike' : ''}">${mrp != null ? amt(mrp) : '&ndash;'}</span>
          <span class="c-rate">${amt(price)}</span>
          <span class="c-amt">${amt(item.line_total)}</span>
        </div>
        ${lineDiscount > 0 ? `<div class="disc">Item discount &minus;${amt(lineDiscount)}</div>` : ''}
      </div>`;
  }).join('');
  mrpTotal = Math.round(mrpTotal * 100) / 100;
  const priceSaving = Math.max(0, Math.round((mrpTotal - num(sale.subtotal)) * 100) / 100);

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
  if (isBill) {
    payment = '';
    if (walletApplied > 0) {
      payment += row('Wallet credit', `&minus;${amt(walletApplied)}`);
    }
    payment += row('AMOUNT PAYABLE', amt(grandTotal - walletApplied), 'b');
  } else if (isCredit) {
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

  // UPI QR (Admin → Shop details → "Print UPI QR", on by default):
  //  - bill before payment: "Scan & Pay" the amount payable;
  //  - receipt with money still owed: "Scan & Pay" exactly that balance;
  //  - receipt already paid: marked PAID, with the shop's plain QR (no
  //    amount), so nobody is asked to pay this bill twice.
  // Showing or scanning a QR never records a payment.
  const payable = Math.round((grandTotal - walletApplied) * 100) / 100;
  const balanceDue = isBill
    ? payable
    : (isCredit && sale.status !== 'voided' ? Math.round((grandTotal - num(sale.amount_paid)) * 100) / 100 : 0);
  const upi = show.upi_qr !== false && shop.upi ? shop.upi : null;
  const qrSize = paper === 58 ? 30 : 36;
  const reference = isBill ? 'Five Star Spices bill' : `Bill ${sale.sale_number}`;
  let qrBlock = '';
  if (balanceDue > 0.004) {
    const uri = upi ? buildUpiUri({ vpa: upi.vpa, payeeName: upi.payee_name, amount: balanceDue, note: reference }) : null;
    qrBlock = uri
      ? `<div class="rule solid"></div>
      <section class="upi center">
        <div class="upi-title">Scan &amp; Pay &#8377;${amt(balanceDue)}</div>
        <div class="upi-qr">${qrSvg(uri, { sizeMm: qrSize })}</div>
        <div class="upi-id">UPI ID: <b>${esc(upi.vpa)}</b></div>
        <div class="fine">GPay &middot; PhonePe &middot; Paytm &middot; BHIM &middot; any UPI app</div>
        ${isBill ? '' : `<div class="fine">Balance due on bill ${esc(sale.sale_number)}</div>`}
      </section>`
      : `<div class="rule solid"></div><div class="center b">${isBill ? 'Amount payable' : 'Balance due'} &#8377;${amt(balanceDue)} &mdash; please pay at the counter.</div>`;
  } else if (!isBill && upi && sale.status !== 'voided') {
    const uri = buildUpiUri({ vpa: upi.vpa, payeeName: upi.payee_name, amount: null });
    qrBlock = uri ? `<div class="rule solid"></div>
      <section class="upi center">
        <div class="paid-mark">&#10003; PAID &mdash; nothing more to pay</div>
        <div class="upi-qr">${qrSvg(uri, { sizeMm: paper === 58 ? 24 : 28 })}</div>
        <div class="upi-id">Pay us by UPI next time: <b>${esc(upi.vpa)}</b></div>
      </section>` : '';
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

      <div class="band">${isBill ? 'BILL &mdash; PAYMENT DUE' : reprint ? 'DUPLICATE RECEIPT' : 'SALES RECEIPT'}</div>

      <section class="meta">
        ${isBill ? row('Status', '<b>Not yet paid</b>') : row('Receipt No', `<b>${esc(sale.sale_number)}</b>`)}
        ${row('Date', datePart)}
        ${row('Time', timePart)}
        ${show.cashier && sale.cashier_name ? row('Cashier', esc(sale.cashier_name)) : ''}
        ${show.counter && sale.shop_label ? row('Counter', esc(sale.shop_label)) : ''}
        ${show.customer && sale.customer_name ? row('Customer', esc(sale.customer_name)) : ''}
        ${show.customer && sale.customer_mobile ? row('Mobile', esc(sale.customer_mobile)) : ''}
      </section>

      <div class="rule solid"></div>
      <div class="thead">
        <div>ITEM</div>
        <div class="grid"><span class="c-qty">QTY</span><span class="c-mrp">MRP</span><span class="c-rate">PRICE</span><span class="c-amt">AMOUNT</span></div>
      </div>
      <div class="rule solid"></div>
      ${lines}
      <div class="rule"></div>

      <section class="totals nums">
        ${row(`Items ${items.length} &middot; Qty ${qty(totalQty)}`, '', 'small')}
        ${priceSaving > 0 ? row('Total MRP', amt(mrpTotal)) : ''}
        ${priceSaving > 0 ? row('Savings on MRP', `&minus;${amt(priceSaving)}`) : ''}
        ${row(priceSaving > 0 ? 'Subtotal (our price)' : 'Subtotal', amt(sale.subtotal))}
        ${discount > 0 ? row('Discount', `&minus;${amt(discount)}`) : ''}
        ${row('GST (included)', amt(sale.tax_amount))}
      </section>

      <div class="grand"><span>${isBill ? 'TOTAL' : 'GRAND TOTAL'}</span><span class="nums">&#8377;${amt(grandTotal)}</span></div>

      <section class="pay nums">${payment}</section>

      ${gst.length ? `
      <div class="rule"></div>
      <section class="gst">
        <div class="sub">GST SUMMARY (included in prices)</div>
        <div class="grid4 head"><span>Rate</span><span>Taxable</span><span>CGST</span><span>SGST</span></div>
        ${gstRows}
      </section>` : ''}

      ${qrBlock}

      ${priceSaving + discount > 0.004 ? `<div class="saved">
        <div class="saved-big">${isBill ? 'YOU SAVE' : 'YOU SAVED'} &#8377;${amt(priceSaving + discount)}</div>
        <div class="saved-split">${[priceSaving > 0 ? `MRP savings &#8377;${amt(priceSaving)}` : '', discount > 0 ? `Discount &#8377;${amt(discount)}` : ''].filter(Boolean).join(' + ')}</div>
        ${mrpTotal > 0 ? `<div class="saved-split">MRP &#8377;${amt(mrpTotal)} &rarr; you ${isBill ? 'pay' : 'paid'} &#8377;${amt(grandTotal)}</div>` : ''}
      </div>` : ''}
      ${offerBlock}
      ${sale.status === 'voided' ? '<div class="band">*** VOIDED ***</div>' : ''}

      <div class="rule solid"></div>
      <footer class="center">
        <div class="thanks">Thank you for shopping with us!</div>
        <div>We look forward to serving you again.</div>
        <div class="fine">${esc(storeName)}</div>
        ${website ? `<div class="web">${esc(website)}</div>` : ''}
        ${reprint ? `<div class="fine">Reprinted ${pDate}</div>` : ''}
        ${isBill ? '<div class="fine">Your receipt is printed after payment.</div>' : ''}
      </footer>
    </div>`;
}

export function receiptDocument(sale, paper, { logoUrl, heightMm, reprint = false, mode = 'receipt' } = {}) {
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
  .grid { display: grid; grid-template-columns: ${narrow ? '13% 27% 27% 33%' : '15% 26% 26% 33%'}; }
  .c-qty { text-align: left; padding-left: ${narrow ? 0 : 3.5}mm; }
  .c-mrp, .c-rate, .c-amt { text-align: right; }
  .strike { text-decoration: line-through; }
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

  .saved { text-align: center; border: 1.5px dashed #000; padding: 1mm 0.5mm; margin: 2mm 0 0; }
  .saved-big { font-weight: 800; font-size: 1.1em; }
  .saved-split { font-size: 0.86em; }

  footer { line-height: 1.38; }
  .thanks { font-weight: 800; font-size: 1.08em; margin-bottom: 0.3mm; }
  .fine { font-size: 0.82em; margin-top: 0.6mm; }
  .upi { margin-top: 1mm; }
  .upi-title { font-size: ${narrow ? 1.15 : 1.3}em; font-weight: 800; margin-bottom: 1mm; }
  .upi-qr svg { display: block; margin: 0 auto; }
  .upi-id { margin-top: 1mm; word-break: break-all; }
  .web { font-weight: 700; margin-top: 0.6mm; }
  .offer { border: 2px dashed #000; padding: 1.4mm 1mm; margin: 2.2mm 0 0; }
  .offer-kicker { font-weight: 800; font-size: 0.82em; letter-spacing: 0.1em; }
  .offer-head { font-weight: 800; font-size: ${narrow ? 1.3 : 1.5}em; line-height: 1.15; margin: 0.6mm 0; }
  .offer-title { font-weight: 600; }
  .offer-use { font-size: 0.86em; margin-top: 0.8mm; }
  .offer-code { display: inline-block; background: #000; color: #fff; font-weight: 800; letter-spacing: 0.12em;
    font-size: ${narrow ? 1.15 : 1.3}em; padding: 0.6mm 2.5mm; margin: 0.4mm 0 0.8mm; }
  .offer-msg { font-weight: 700; font-size: 1.05em; margin-top: 0.6mm; white-space: pre-line; }
  .paid-mark { font-weight: 800; font-size: 1.1em; border: 2px solid #000; padding: 0.8mm 0; margin-bottom: 1.2mm; }
</style></head><body>${receiptMarkup(sale, { logoUrl, reprint, paper, mode })}</body></html>`;
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
export async function printThermalReceipt(sale, paper = getReceiptPaper(), { reprint = false, mode = 'receipt' } = {}) {
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
    let doc = write(receiptDocument(sale, paper, { logoUrl, reprint, mode }));
    await waitForImages(doc);
    const px = doc.querySelector('.receipt').getBoundingClientRect().height;
    const heightMm = px * 25.4 / 96 + 2;

    // Second pass with the page cut to exactly that length.
    doc = write(receiptDocument(sale, paper, { logoUrl, heightMm, reprint, mode }));
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
