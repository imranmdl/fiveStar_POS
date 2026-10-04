/**
 * The GST invoice for an order.
 *
 * `orders.html` has always linked here; the page simply did not exist, so the
 * link 404'd. Built to be printed — a customer who wants an invoice usually
 * wants a PDF, and the browser's own print-to-PDF is more reliable than
 * anything generated client-side.
 */

import { api, ApiError } from './api.js';
import { mountChrome, mountFooter, escapeHtml, formatMoney, showError, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');
const uuid = queryParam('uuid');

function taxRow(line) {
  // CGST and SGST for a sale inside the seller's state; IGST across state
  // lines. Showing whichever applies rather than a row of zeroes.
  const interState = Number(line.igst_amount) > 0;

  return `
    <tr>
      <td>${escapeHtml(line.gst_rate)}%</td>
      <td class="text-end">${formatMoney(line.taxable_value)}</td>
      ${interState
        ? `<td class="text-end" colspan="2">IGST ${formatMoney(line.igst_amount)}</td>`
        : `<td class="text-end">CGST ${formatMoney(line.cgst_amount)}</td>
           <td class="text-end">SGST ${formatMoney(line.sgst_amount)}</td>`}
      <td class="text-end">${formatMoney(line.tax_amount)}</td>
    </tr>`;
}

async function load() {
  if (!uuid) {
    root.innerHTML = '<div class="alert alert-warning">No order was specified.</div>';
    return;
  }

  try {
    const response = await api.get(`/orders/${encodeURIComponent(uuid)}/invoice`);
    const data = response.data;
    const seller = data.seller || {};
    const buyer = data.buyer || {};
    const totals = data.totals || {};
    // `invoice` holds the number and date; `order` holds the order number.
    const invoice = data.invoice || {};
    const order = data.order || {};

    document.title = `Invoice ${invoice.number || invoice.invoice_number || ''}`;

    root.innerHTML = `
      <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <a class="small text-decoration-none" href="orders.html">← Back to your orders</a>
        <button class="btn btn-sm btn-spice" data-print type="button">Print or save as PDF</button>
      </div>

      <div class="card">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between flex-wrap gap-3 border-bottom pb-3 mb-3">
            <div>
              <div class="h5 mb-1">${escapeHtml(seller.legal_name || 'Spice & Dry Fruits')}</div>
              ${seller.state ? `<div class="small text-muted">${escapeHtml(seller.state)}</div>` : ''}
              ${seller.gstin ? `<div class="small">GSTIN: ${escapeHtml(seller.gstin)}</div>` : ''}
            </div>
            <div class="text-end">
              <div class="fw-semibold">Tax Invoice</div>
              <div class="small">${escapeHtml(invoice.number || invoice.invoice_number || '')}</div>
              <div class="small text-muted">
                ${escapeHtml(String(invoice.date || invoice.invoice_date || '').slice(0, 10))}
              </div>
              <div class="small text-muted">Order ${escapeHtml(order.order_number || '')}</div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-sm-6">
              <div class="small text-muted">Billed to</div>
              <div class="fw-semibold">${escapeHtml(buyer.name || '')}</div>
              <div class="small">${escapeHtml(buyer.address || '')}</div>
              ${buyer.gstin ? `<div class="small">GSTIN: ${escapeHtml(buyer.gstin)}</div>` : ''}
            </div>
            <div class="col-sm-6 text-sm-end">
              <div class="small text-muted">Place of supply</div>
              <div>${escapeHtml(buyer.state || '')}</div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Item</th><th>HSN</th><th class="text-end">Qty</th>
                  <th class="text-end">Rate</th><th class="text-end">Taxable</th>
                  <th class="text-end">GST</th><th class="text-end">Amount</th>
                </tr>
              </thead>
              <tbody>
                ${(data.lines || []).map((line) => `
                  <tr>
                    <td>
                      ${escapeHtml(line.description)}
                      <div class="small text-muted">${escapeHtml(line.sku || '')}</div>
                    </td>
                    <td class="small">${escapeHtml(line.hsn_code || '—')}</td>
                    <td class="text-end">${escapeHtml(line.quantity)}</td>
                    <td class="text-end">${formatMoney(line.unit_price)}</td>
                    <td class="text-end">${formatMoney(line.taxable_value)}</td>
                    <td class="text-end">${formatMoney(line.tax_amount)}</td>
                    <td class="text-end">${formatMoney(line.line_total)}</td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>

          ${(data.tax_summary || []).length ? `
            <div class="table-responsive mt-3">
              <div class="small fw-semibold mb-1">Tax summary</div>
              <table class="table table-sm">
                <thead>
                  <tr><th>Rate</th><th class="text-end">Taxable</th>
                    <th class="text-end" colspan="2">Tax</th><th class="text-end">Total</th></tr>
                </thead>
                <tbody>${data.tax_summary.map(taxRow).join('')}</tbody>
              </table>
            </div>` : ''}

          <div class="row justify-content-end mt-3">
            <div class="col-sm-6 col-md-5">
              <dl class="row small mb-0">
                <dt class="col-7 fw-normal">Taxable value</dt>
                <dd class="col-5 text-end">${formatMoney(totals.taxable_value)}</dd>
                <dt class="col-7 fw-normal">Delivery</dt>
                <dd class="col-5 text-end">${formatMoney(totals.delivery_charge)}</dd>
                <dt class="col-7 fw-normal">Total GST</dt>
                <dd class="col-5 text-end">${formatMoney(totals.tax_total)}</dd>
                <dt class="col-7 fw-semibold border-top pt-2">Grand total</dt>
                <dd class="col-5 text-end fw-semibold border-top pt-2">
                  ${formatMoney(totals.grand_total)}
                </dd>
                ${Number(totals.paid_from_wallet) > 0 ? `
                  <dt class="col-7 fw-normal">Paid by wallet</dt>
                  <dd class="col-5 text-end">${formatMoney(totals.paid_from_wallet)}</dd>
                  <dt class="col-7 fw-normal">Paid by UPI</dt>
                  <dd class="col-5 text-end">${formatMoney(totals.paid_online)}</dd>` : ''}
              </dl>
            </div>
          </div>

          <p class="small text-muted mt-4 mb-0">
            Prices are inclusive of GST. This is a computer-generated invoice and
            does not require a signature.
          </p>
        </div>
      </div>`;

    root.querySelector('[data-print]').addEventListener('click', () => window.print());
  } catch (error) {
    root.innerHTML = '<a class="small" href="orders.html">← Back to your orders</a>';

    if (error instanceof ApiError && error.status === 409) {
      root.innerHTML += `
        <div class="alert alert-warning mt-2">
          An invoice is issued once payment is confirmed. This order is not paid yet.
        </div>`;
      return;
    }

    showError(error, root);
  }
}

mountChrome('orders.html');
mountFooter();
load();
