import { formatMoney } from '../../lib/api';
import { lineNet, lineTax } from './tillMath';

/**
 * Editable cart lines — ported from cartRow()/renderCart() in
 * admin/assets/page-till.js. Quantity/unit price/discount are editable
 * in place; tax (est.) and net recompute live from tillMath as any of
 * those three change, without rebuilding the row (React already gives us
 * that for free via controlled inputs — the source's own comment explains
 * why its vanilla-JS version had to special-case this: rebuilding the DOM
 * node being typed into loses focus after every keystroke).
 */
export default function CartTable({ cart, onLineChange, onRemoveLine }) {
  return (
    <div className="till-cart">
      <table className="till-cart__table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Discount</th>
            <th className="till-cart__num">Tax (est.)</th>
            <th className="till-cart__num">Net</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {cart.length === 0 ? (
            <tr>
              <td colSpan={7} className="till-cart__empty">Scan or look up an item to add it.</td>
            </tr>
          ) : (
            cart.map((line, index) => (
              <tr key={line.variant_uuid}>
                <td>
                  <div className="till-cart__name">{line.product_name}</div>
                  <div className="till-cart__meta">{line.variant_name} · {line.sku}</div>
                  {line.applied_offer_code && (
                    <span className="till-badge till-badge--success">{line.applied_offer_code}</span>
                  )}
                </td>
                <td>
                  <input
                    className="till-cart__cell-input"
                    type="number"
                    step="0.001"
                    min="0.001"
                    inputMode="decimal"
                    value={line.quantity}
                    onChange={(event) => onLineChange(index, 'quantity', event.target.value)}
                  />
                </td>
                <td>
                  <input
                    className="till-cart__cell-input"
                    type="number"
                    step="0.01"
                    min="0"
                    inputMode="decimal"
                    value={line.unit_price}
                    onChange={(event) => onLineChange(index, 'unit_price', event.target.value)}
                  />
                </td>
                <td>
                  <input
                    className="till-cart__cell-input"
                    type="number"
                    step="0.01"
                    min="0"
                    inputMode="decimal"
                    value={line.discount_amount || 0}
                    onChange={(event) => onLineChange(index, 'discount_amount', event.target.value)}
                  />
                </td>
                <td className="till-cart__num">{formatMoney(lineTax(line))}</td>
                <td className="till-cart__num">{formatMoney(lineNet(line))}</td>
                <td>
                  <button type="button" className="till-remove-btn" aria-label="Remove line" onClick={() => onRemoveLine(index)}>×</button>
                </td>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
