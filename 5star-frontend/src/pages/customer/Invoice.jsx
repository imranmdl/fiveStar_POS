import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, ApiError, formatMoney } from '../../lib/api';
import './Invoice.css';

function TaxRow({ line }) {
  const interState = Number(line.igst_amount) > 0;

  return (
    <tr>
      <td>{line.gst_rate}%</td>
      <td className="text-end">{formatMoney(line.taxable_value)}</td>
      {interState ? (
        <td className="text-end" colSpan={2}>IGST {formatMoney(line.igst_amount)}</td>
      ) : (
        <>
          <td className="text-end">CGST {formatMoney(line.cgst_amount)}</td>
          <td className="text-end">SGST {formatMoney(line.sgst_amount)}</td>
        </>
      )}
      <td className="text-end">{formatMoney(line.tax_amount)}</td>
    </tr>
  );
}

export default function Invoice() {
  const { uuid } = useParams();
  const [status, setStatus] = useState('loading');
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [notReady, setNotReady] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    setNotReady(false);

    api
      .get(`/orders/${encodeURIComponent(uuid)}/invoice`)
      .then((response) => {
        if (cancelled) return;
        setData(response.data);
        const invoice = response.data.invoice || {};
        document.title = `Invoice ${invoice.number || invoice.invoice_number || ''}`;
        setStatus('ready');
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 409) {
          setNotReady(true);
          setStatus('ready');
          return;
        }
        setError(err.message);
        setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [uuid]);

  if (status === 'loading') {
    return <div className="page"><p className="state-message">Loading invoice…</p></div>;
  }

  if (notReady) {
    return (
      <div className="page">
        <Link className="small" to="/orders">← Back to your orders</Link>
        <div className="alert alert-warning">An invoice is issued once payment is confirmed. This order is not paid yet.</div>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div className="page">
        <Link className="small" to="/orders">← Back to your orders</Link>
        <p className="state-message state-message--error">Couldn't load this invoice: {error}</p>
      </div>
    );
  }

  const seller = data.seller || {};
  const buyer = data.buyer || {};
  const totals = data.totals || {};
  const discount = data.discount || {};
  const invoice = data.invoice || {};
  const order = data.order || {};

  return (
    <div className="page invoice-page">
      <div className="invoice-toolbar">
        <Link className="small" to="/orders">← Back to your orders</Link>
        <button type="button" className="btn-marigold" onClick={() => window.print()}>Print or save as PDF</button>
      </div>

      <div className="invoice-card">
        <div className="invoice-head">
          <div>
            <div className="invoice-head__seller">{seller.legal_name || '5 Star Spices & Dry Fruits'}</div>
            {seller.state && <div className="small text-muted">{seller.state}</div>}
            {seller.gstin && <div className="small">GSTIN: {seller.gstin}</div>}
          </div>
          <div className="invoice-head__meta">
            <div className="fw-semibold">Tax Invoice</div>
            <div className="small">{invoice.number || invoice.invoice_number || ''}</div>
            <div className="small text-muted">{String(invoice.date || invoice.invoice_date || '').slice(0, 10)}</div>
            <div className="small text-muted">Order {order.order_number || ''}</div>
          </div>
        </div>

        <div className="invoice-parties">
          <div>
            <div className="small text-muted">Billed to</div>
            <div className="fw-semibold">{buyer.name || ''}</div>
            <div className="small">{buyer.address || ''}</div>
            {buyer.gstin && <div className="small">GSTIN: {buyer.gstin}</div>}
          </div>
          <div className="invoice-parties__right">
            <div className="small text-muted">Place of supply</div>
            <div>{buyer.state || ''}</div>
          </div>
        </div>

        <div className="invoice-table-wrap">
          <table className="invoice-table">
            <thead>
              <tr>
                <th>Item</th><th>HSN</th><th className="text-end">Qty</th>
                <th className="text-end">Rate</th><th className="text-end">Taxable</th>
                <th className="text-end">GST</th><th className="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              {(data.lines || []).map((line, index) => (
                <tr key={index}>
                  <td>
                    {line.description}
                    <div className="small text-muted">{line.sku || ''}</div>
                  </td>
                  <td className="small">{line.hsn_code || '—'}</td>
                  <td className="text-end">{line.quantity}</td>
                  <td className="text-end">{formatMoney(line.unit_price)}</td>
                  <td className="text-end">{formatMoney(line.taxable_value)}</td>
                  <td className="text-end">{formatMoney(line.tax_amount)}</td>
                  <td className="text-end">{formatMoney(line.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {(data.tax_summary || []).length > 0 && (
          <div className="invoice-table-wrap">
            <div className="small fw-semibold">Tax summary</div>
            <table className="invoice-table">
              <thead>
                <tr>
                  <th>Rate</th><th className="text-end">Taxable</th>
                  <th className="text-end" colSpan={2}>Tax</th><th className="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                {data.tax_summary.map((line, index) => <TaxRow key={index} line={line} />)}
              </tbody>
            </table>
          </div>
        )}

        <div className="invoice-totals">
          <dl>
            <dt>Taxable value</dt><dd>{formatMoney(totals.taxable_value)}</dd>
            {Number(discount.total_savings) > 0 && (
              <>
                <dt className="text-success">Discount</dt><dd className="text-success">− {formatMoney(discount.total_savings)}</dd>
                {discount.coupon_code && (
                  <>
                    <dt />
                    <dd className="small text-muted">
                      Coupon <span className="fw-semibold">{discount.coupon_code}</span> applied
                      {Number(discount.coupon_discount) > 0 && ` (− ${formatMoney(discount.coupon_discount)})`}
                    </dd>
                  </>
                )}
                {discount.offer_code && (
                  <>
                    <dt />
                    <dd className="small text-muted">
                      Offer <span className="fw-semibold">{discount.offer_code}</span> applied
                      {Number(discount.offer_discount) > 0 && ` (− ${formatMoney(discount.offer_discount)})`}
                    </dd>
                  </>
                )}
              </>
            )}
            <dt>Delivery</dt><dd>{formatMoney(totals.delivery_charge)}</dd>
            <dt>Total GST</dt><dd>{formatMoney(totals.tax_total)}</dd>
            <dt className="fw-semibold invoice-totals__grand">Grand total</dt>
            <dd className="fw-semibold invoice-totals__grand">{formatMoney(totals.grand_total)}</dd>
            {Number(totals.paid_from_wallet) > 0 && (
              <>
                <dt>Paid by wallet</dt><dd>{formatMoney(totals.paid_from_wallet)}</dd>
                <dt>Paid by UPI</dt><dd>{formatMoney(totals.paid_online)}</dd>
              </>
            )}
          </dl>
        </div>

        <p className="small text-muted invoice-footer-note">
          Prices are inclusive of GST. This is a computer-generated invoice and does not require a signature.
        </p>
      </div>
    </div>
  );
}
