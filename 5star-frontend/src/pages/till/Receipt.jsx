import { useState } from 'react';
import { formatMoney } from '../../lib/api';
import { PAPER_SIZES, getReceiptPaper, setReceiptPaper, printThermalReceipt } from './thermalReceipt';

/** Ported from renderReceipt() in admin/assets/page-till.js. Used both right after completing a sale and when viewing one from Sales history. */
export default function Receipt({ sale }) {
  // is_credit_sale arrives as 0/1; a bare 0 would render as a stray "0".
  const isCredit = Number(sale.is_credit_sale) === 1;
  const [paper, setPaper] = useState(getReceiptPaper);
  const [printing, setPrinting] = useState(false);

  function changePaper(event) {
    const width = Number(event.target.value);
    setPaper(width);
    setReceiptPaper(width);
  }

  async function handlePrint() {
    setPrinting(true);
    try {
      await printThermalReceipt(sale, paper);
    } finally {
      setPrinting(false);
    }
  }

  return (
    <div className="till-receipt">
      <div className="till-receipt__header">
        <h2>Receipt — {sale.sale_number}</h2>
        <div className="till-receipt__print till-no-print">
          <select aria-label="Receipt paper width" value={paper} onChange={changePaper} className="till-receipt__paper">
            {[80, 58].map((width) => (
              <option key={width} value={width}>{PAPER_SIZES[width].label}</option>
            ))}
          </select>
          <button type="button" className="till-btn till-btn--sm" onClick={handlePrint} disabled={printing}>
            {printing ? 'Printing…' : 'Print receipt'}
          </button>
        </div>
      </div>
      <p className="till-receipt__meta">
        {String(sale.created_date || '').slice(0, 16).replace('T', ' ')}
        {sale.shop_label ? ` · ${sale.shop_label}` : ''}
      </p>

      <table className="till-receipt__table">
        <thead><tr><th>Item</th><th className="till-cart__num">Qty</th><th className="till-cart__num">Amount</th></tr></thead>
        <tbody>
          {sale.items.map((item) => (
            <tr key={item.uuid || item.sku}>
              <td>{item.product_name} <span className="till-cart__meta">({item.variant_name})</span></td>
              <td className="till-cart__num">{Number(item.quantity)}</td>
              <td className="till-cart__num">{formatMoney(item.line_total)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <dl className="till-receipt__totals">
        <div><dt>Subtotal</dt><dd>{formatMoney(sale.subtotal)}</dd></div>
        <div><dt>Discount</dt><dd>{formatMoney(sale.discount_amount)}</dd></div>
        <div><dt>Tax (included)</dt><dd>{formatMoney(sale.tax_amount)}</dd></div>
        <div className="till-receipt__totals-grand"><dt>Total</dt><dd>{formatMoney(sale.grand_total)}</dd></div>
        {Number(sale.wallet_applied) > 0 && (
          <>
            <div><dt>From wallet</dt><dd>−{formatMoney(sale.wallet_applied)}</dd></div>
            <div><dt>Amount due</dt><dd>{formatMoney(Number(sale.grand_total) - Number(sale.wallet_applied))}</dd></div>
          </>
        )}
        {sale.payment_method === 'cash' && !isCredit && (
          <>
            <div><dt>Tendered</dt><dd>{formatMoney(sale.amount_tendered)}</dd></div>
            <div><dt>Change</dt><dd>{formatMoney(sale.change_due)}</dd></div>
          </>
        )}
      </dl>

      {isCredit && (
        <div className={`till-alert till-no-print ${sale.payment_status === 'paid' ? 'till-alert--success' : 'till-alert--warning'}`}>
          <div>Paid so far: <strong>{formatMoney(sale.amount_paid)}</strong></div>
          <div>Balance still due: <strong>{formatMoney(Number(sale.grand_total) - Number(sale.amount_paid))}</strong></div>
        </div>
      )}
    </div>
  );
}
