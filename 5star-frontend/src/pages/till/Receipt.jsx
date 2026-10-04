import { formatMoney } from '../../lib/api';

/** Ported from renderReceipt() in admin/assets/page-till.js. Used both right after completing a sale and when viewing one from Sales history. */
export default function Receipt({ sale }) {
  return (
    <div className="till-receipt">
      <div className="till-receipt__header">
        <h2>Receipt — {sale.sale_number}</h2>
        <button type="button" className="till-btn till-btn--sm till-no-print" onClick={() => window.print()}>Print</button>
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
        {sale.payment_method === 'cash' && !sale.is_credit_sale && (
          <>
            <div><dt>Tendered</dt><dd>{formatMoney(sale.amount_tendered)}</dd></div>
            <div><dt>Change</dt><dd>{formatMoney(sale.change_due)}</dd></div>
          </>
        )}
      </dl>

      {sale.is_credit_sale && (
        <div className={`till-alert till-no-print ${sale.payment_status === 'paid' ? 'till-alert--success' : 'till-alert--warning'}`}>
          <div>Paid so far: <strong>{formatMoney(sale.amount_paid)}</strong></div>
          <div>Balance still due: <strong>{formatMoney(Number(sale.grand_total) - Number(sale.amount_paid))}</strong></div>
        </div>
      )}
    </div>
  );
}
