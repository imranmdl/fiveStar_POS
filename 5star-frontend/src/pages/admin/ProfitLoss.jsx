import { useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatusBadge } from '../../components/admin/shared';
import './ProfitLoss.css';

function money(value) {
  return formatMoney(Number(value) || 0);
}

function Modal({ title, onClose, children }) {
  return (
    <div className="pl-modal-backdrop" onClick={onClose}>
      <div className="pl-modal" onClick={(e) => e.stopPropagation()}>
        <div className="pl-modal__header">
          <h2>{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close">
            &times;
          </button>
        </div>
        <div className="pl-modal__body">{children}</div>
      </div>
    </div>
  );
}

function ItemsTable({ items, onViewPurchases }) {
  if (!items || items.length === 0) {
    return <EmptyState title="No sales in this period" hint="Try a longer range." />;
  }

  return (
    <table className="admin-table">
      <thead>
        <tr>
          <th>Item</th>
          <th style={{ textAlign: 'right' }}>Units sold</th>
          <th style={{ textAlign: 'right' }}>Revenue</th>
          <th style={{ textAlign: 'right' }}>Cost (est.)</th>
          <th style={{ textAlign: 'right' }}>Profit</th>
          <th style={{ textAlign: 'right' }}>Margin</th>
        </tr>
      </thead>
      <tbody>
        {items.map((item) => {
          const profit = Number(item.profit) || 0;
          const tone = profit < 0 ? '#c0392b' : profit > 0 ? '#1e8a5b' : undefined;
          return (
            <tr key={item.variant_uuid}>
              <td>
                <span style={{ fontWeight: 600 }}>{item.sku}</span>
                <div className="pl-sub">
                  {item.product_name} — {item.variant_name}
                </div>
              </td>
              <td style={{ textAlign: 'right' }}>{item.units_sold}</td>
              <td style={{ textAlign: 'right' }}>{money(item.revenue)}</td>
              <td style={{ textAlign: 'right', color: 'var(--ink-500)' }}>{money(item.cost)}</td>
              <td style={{ textAlign: 'right' }}>
                <button
                  type="button"
                  className="pl-link-btn"
                  style={{ color: tone, fontWeight: 600 }}
                  onClick={() => onViewPurchases(item.variant_uuid, `${item.product_name} — ${item.variant_name}`)}
                >
                  {money(item.profit)}
                </button>
              </td>
              <td style={{ textAlign: 'right', color: tone }}>{item.margin_percent}%</td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

function InvoiceHistoryTable({ rows }) {
  if (!rows || rows.length === 0) {
    return <EmptyState title="No purchase history" hint="This item has never been received on a purchase order." />;
  }
  return (
    <>
      <table className="admin-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Vendor / invoice</th>
            <th style={{ textAlign: 'right' }}>Qty received</th>
            <th style={{ textAlign: 'right' }}>Unit cost</th>
            <th style={{ textAlign: 'right' }}>Landing cost</th>
            <th>Batch</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => {
            const isShort = row.invoiced_quantity !== null && Number(row.invoiced_quantity) > Number(row.quantity);
            return (
              <tr key={i}>
                <td>{String(row.purchase_date || '').slice(0, 10)}</td>
                <td>
                  {row.vendor_name}
                  <div className="pl-sub">
                    {row.po_number}
                    {row.invoice_reference ? ` · invoice ${row.invoice_reference}` : ' · no invoice reference on file'}
                  </div>
                </td>
                <td style={{ textAlign: 'right' }}>
                  {row.quantity}
                  {isShort && <span style={{ color: '#c0392b' }}> ({row.invoiced_quantity} invoiced)</span>}
                </td>
                <td style={{ textAlign: 'right' }}>{money(row.unit_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(row.landing_cost)}</td>
                <td>{row.batch_no || '—'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <p className="pl-footnote">
        Landing cost includes this purchase order's share of transportation — it's what actually set this item's average cost, not the raw vendor price.
      </p>
    </>
  );
}

function InvoiceLossTable({ invoiceLoss }) {
  if (!invoiceLoss.rows || invoiceLoss.rows.length === 0) {
    return <EmptyState title="No invoice loss in this period" hint="Every purchase order line received matched what the vendor invoiced." />;
  }
  return (
    <table className="admin-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Item</th>
          <th>Vendor / PO</th>
          <th style={{ textAlign: 'right' }}>Invoiced vs received</th>
          <th style={{ textAlign: 'right' }}>Short</th>
          <th style={{ textAlign: 'right' }}>Value</th>
        </tr>
      </thead>
      <tbody>
        {invoiceLoss.rows.map((row, i) => (
          <tr key={i}>
            <td>{String(row.date || '').slice(0, 10)}</td>
            <td>
              <span style={{ fontWeight: 600 }}>{row.sku}</span>
              <div className="pl-sub">
                {row.variant_name} · {row.warehouse_name}
              </div>
            </td>
            <td>
              {row.vendor_name}
              <div className="pl-sub">
                {row.po_number}
                {row.invoice_reference ? ` · invoice ${row.invoice_reference}` : ''}
              </div>
            </td>
            <td style={{ textAlign: 'right' }}>
              {row.invoiced_quantity} invoiced, {row.received_quantity} received
            </td>
            <td style={{ textAlign: 'right', color: '#c0392b', fontWeight: 600 }}>{row.short_quantity} short</td>
            <td style={{ textAlign: 'right' }}>{row.value !== null ? money(row.value) : '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function DamageLossTable({ damageLoss }) {
  if (!damageLoss.rows || damageLoss.rows.length === 0) {
    return <EmptyState title="No damage or loss recorded" hint="Nothing was written off in this period." />;
  }
  return (
    <>
      <table className="admin-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Item</th>
            <th>Type</th>
            <th style={{ textAlign: 'right' }}>Qty</th>
            <th style={{ textAlign: 'right' }}>Value</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          {damageLoss.rows.map((row, i) => (
            <tr key={i}>
              <td>{String(row.created_date || '').slice(0, 16).replace('T', ' ')}</td>
              <td>
                <span style={{ fontWeight: 600 }}>{row.sku}</span>
                <div className="pl-sub">
                  {row.variant_name} · {row.warehouse_name}
                </div>
              </td>
              <td>
                <StatusBadge status={row.movement_type} label={row.movement_type} />
              </td>
              <td style={{ textAlign: 'right' }}>{row.quantity}</td>
              <td style={{ textAlign: 'right' }}>
                {row.line_value !== null ? money(row.line_value) + (row.value_source === 'current_average_cost' ? ' *' : '') : '—'}
              </td>
              <td>{row.reason || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="pl-footnote">
        * Value estimated from the item's current average cost. See Inventory &gt; Damage &amp; loss for the full tool.
      </p>
    </>
  );
}

function PoProfitLossTable({ po, items }) {
  return (
    <>
      <p className="pl-footnote" style={{ marginBottom: 12 }}>
        Profit &amp; loss for each item on this invoice, across the WHOLE selected period — not just the units this one delivery brought in, since sales
        aren't tracked back to a specific purchase batch. "Not sold in this period" means none of this item sold in the current range.
      </p>
      <table className="admin-table">
        <thead>
          <tr>
            <th>Item</th>
            <th style={{ textAlign: 'right' }}>Bought (this invoice)</th>
            <th style={{ textAlign: 'right' }}>Units sold</th>
            <th style={{ textAlign: 'right' }}>Revenue</th>
            <th style={{ textAlign: 'right' }}>Profit</th>
            <th style={{ textAlign: 'right' }}>Margin</th>
          </tr>
        </thead>
        <tbody>
          {po.items.map((line) => {
            const match = items.find((item) => item.variant_uuid === line.variant_uuid);
            const profit = match ? Number(match.profit) : null;
            const tone = profit === null ? 'var(--ink-500)' : profit < 0 ? '#c0392b' : profit > 0 ? '#1e8a5b' : undefined;
            return (
              <tr key={line.variant_uuid}>
                <td>
                  <span style={{ fontWeight: 600 }}>{line.sku}</span>
                  <div className="pl-sub">{line.variant_name}</div>
                </td>
                <td style={{ textAlign: 'right' }}>
                  {line.quantity} @ {money(line.unit_cost)}
                </td>
                <td style={{ textAlign: 'right' }}>{match ? match.units_sold : '—'}</td>
                <td style={{ textAlign: 'right' }}>{match ? money(match.revenue) : '—'}</td>
                <td style={{ textAlign: 'right', fontWeight: 600, color: tone }}>{match ? money(match.profit) : 'Not sold in this period'}</td>
                <td style={{ textAlign: 'right', color: tone }}>{match ? `${match.margin_percent}%` : '—'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </>
  );
}

const RANGES = [
  [1, 'Today'],
  [7, 'Last 7 days'],
  [30, 'Last 30 days'],
  [90, 'Last 90 days'],
];

export default function ProfitLoss() {
  const [days, setDays] = useState(30);
  const [plData, setPlData] = useState(null);
  const [purchaseOrders, setPurchaseOrders] = useState(null);
  const [error, setError] = useState(null);
  const [modal, setModal] = useState(null);

  useEffect(() => {
    setPlData(null);
    setPurchaseOrders(null);
    setError(null);

    const to = new Date();
    const from = new Date(to.getTime() - (days - 1) * 86400000);
    const iso = (d) => d.toISOString().slice(0, 10);

    Promise.all([
      api.get('/admin/reports/profit-loss', { from: iso(from), to: iso(to) }),
      api.get('/admin/purchase-orders', { from: iso(from), to: iso(to), per_page: 100, direction: 'DESC' }),
    ])
      .then(([plResponse, poResponse]) => {
        setPlData(plResponse.data);
        setPurchaseOrders(poResponse.data || []);
      })
      .catch(setError);
  }, [days]);

  async function viewPurchases(variantUuid, label) {
    setModal({ title: `Purchase invoices — ${label}`, body: <LoadingState /> });
    try {
      const response = await api.get(`/admin/inventory/stock/${encodeURIComponent(variantUuid)}`);
      setModal({ title: `Purchase invoices — ${label}`, body: <InvoiceHistoryTable rows={response.data.purchase_history || []} /> });
    } catch (err) {
      setModal({ title: `Purchase invoices — ${label}`, body: <ErrorState error={err} /> });
    }
  }

  async function viewPoProfitLoss(uuid) {
    setModal({ title: 'Profit & loss — invoice', body: <LoadingState /> });
    try {
      const po = (await api.get(`/admin/purchase-orders/${encodeURIComponent(uuid)}`)).data;
      setModal({ title: `Profit & loss — ${po.po_number}`, body: <PoProfitLossTable po={po} items={plData.items} /> });
    } catch (err) {
      setModal({ title: 'Profit & loss — invoice', body: <ErrorState error={err} /> });
    }
  }

  const rangeLabel = days === 1 ? 'today' : `last ${days} days`;

  if (error) {
    return (
      <div className="page">
        <h1 className="admin-page-title">Profit &amp; Loss</h1>
        <ErrorState error={error} />
      </div>
    );
  }

  return (
    <div className="page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>
          Profit &amp; Loss
        </h1>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {RANGES.map(([value, label]) => (
            <button
              key={value}
              type="button"
              className={value === days ? 'admin-btn admin-btn--primary' : 'admin-btn'}
              onClick={() => setDays(value)}
            >
              {label}
            </button>
          ))}
          <button
            type="button"
            className="admin-btn"
            onClick={() => plData && setModal({ title: `Item-wise profit & loss — ${rangeLabel}`, body: <ItemsTable items={plData.items} onViewPurchases={viewPurchases} /> })}
          >
            View item-wise profit &amp; loss
          </button>
        </div>
      </div>

      {!plData && <LoadingState />}

      {plData && (
        <>
          <div className="stat-grid">
            <div className="stat-card">
              <div className="stat-card__label">Revenue</div>
              <div className="stat-card__value">{money(plData.summary.total_revenue)}</div>
              <div className="stat-card__hint">{rangeLabel}</div>
            </div>
            <div className="stat-card">
              <div className="stat-card__label">Cost (est.)</div>
              <div className="stat-card__value">{money(plData.summary.total_cost)}</div>
              <div className="stat-card__hint">against current average cost</div>
            </div>
            <div className={`stat-card ${plData.summary.total_profit < 0 ? 'stat-card--danger' : 'stat-card--success'}`}>
              <div className="stat-card__label">Profit</div>
              <div className="stat-card__value">{money(plData.summary.total_profit)}</div>
              <div className="stat-card__hint">{plData.summary.margin_percent}% margin</div>
            </div>
            <div className="stat-card">
              <div className="stat-card__label">Net after invoice loss</div>
              <div className="stat-card__value">{money(plData.summary.net_after_invoice_loss)}</div>
              <div className="stat-card__hint">profit less invoice loss</div>
            </div>
          </div>

          <div className="stat-grid">
            <div className="stat-card">
              <div className="stat-card__label">Opening stock</div>
              <div className="stat-card__value">{money(plData.summary.opening_stock_value)}</div>
              <div className="stat-card__hint">as of start of {rangeLabel}</div>
            </div>
            <div className="stat-card">
              <div className="stat-card__label">Closing stock</div>
              <div className="stat-card__value">{money(plData.summary.closing_stock_value)}</div>
              <div className="stat-card__hint">as of end of {rangeLabel}</div>
            </div>
            <button
              type="button"
              className="pl-clickable-stat"
              onClick={() => setModal({ title: `Invoice loss — ${rangeLabel}`, body: <InvoiceLossTable invoiceLoss={plData.invoice_loss} /> })}
            >
              <div className="stat-card__label">Invoice loss</div>
              <div className="stat-card__value">{money(plData.invoice_loss.summary.total_value)}</div>
              <div className="stat-card__hint">{plData.invoice_loss.summary.incident_count} shortfall(s) — click to view</div>
            </button>
            <button
              type="button"
              className="pl-clickable-stat"
              onClick={() => setModal({ title: `Damage & loss — ${rangeLabel}`, body: <DamageLossTable damageLoss={plData.damage_loss} /> })}
            >
              <div className="stat-card__label">Damage &amp; loss</div>
              <div className="stat-card__value">{money(plData.damage_loss.summary.total_value)}</div>
              <div className="stat-card__hint">{plData.damage_loss.summary.incident_count} incident(s) — click to view</div>
            </button>
          </div>

          <div className="pl-note">
            <b>Profit, cost and stock values here are estimates.</b> They price everything at each item's CURRENT average cost — this system doesn't
            snapshot cost per sale or per day, only at the point stock comes in. Invoice loss and Damage &amp; loss are exact, recorded figures.
          </div>

          <div className="pl-card">
            <div className="pl-card__header">
              <span>Invoices — {rangeLabel}</span>
              <span className="pl-sub">Click an invoice for its items' profit &amp; loss</span>
            </div>
            {!purchaseOrders || purchaseOrders.length === 0 ? (
              <EmptyState title="No purchases in this period" hint="Nothing was recorded on Purchase Inward in this range." />
            ) : (
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Vendor</th>
                    <th>Warehouse</th>
                    <th style={{ textAlign: 'right' }}>Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {purchaseOrders.map((po) => (
                    <tr key={po.uuid} role="button" className="pl-row-clickable" onClick={() => viewPoProfitLoss(po.uuid)}>
                      <td>{String(po.purchase_date || '').slice(0, 10)}</td>
                      <td>
                        <span style={{ fontWeight: 600 }}>{po.po_number}</span>
                        <div className="pl-sub">{po.invoice_reference ? `invoice ${po.invoice_reference}` : 'no invoice reference on file'}</div>
                      </td>
                      <td>{po.vendor_name}</td>
                      <td>{po.warehouse_name}</td>
                      <td style={{ textAlign: 'right' }}>{money(po.grand_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          <div className="pl-card">
            <div className="pl-card__header">
              <span>Vendor reliability — {rangeLabel}</span>
              <span className="pl-sub">Worst loss rate first</span>
            </div>
            {!plData.vendor_reliability || plData.vendor_reliability.length === 0 ? (
              <EmptyState title="No purchases in this period" hint="Nothing was bought from any vendor in this range." />
            ) : (
              <>
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>Vendor</th>
                      <th style={{ textAlign: 'right' }}>POs</th>
                      <th style={{ textAlign: 'right' }}>Purchased</th>
                      <th style={{ textAlign: 'right' }}>Invoice loss</th>
                      <th style={{ textAlign: 'right' }}>Damage/loss</th>
                      <th style={{ textAlign: 'right' }}>Loss rate</th>
                    </tr>
                  </thead>
                  <tbody>
                    {plData.vendor_reliability.map((vendor) => {
                      const rate = Number(vendor.loss_rate_percent) || 0;
                      const tone = rate > 10 ? { color: '#c0392b', fontWeight: 600 } : rate > 0 ? { color: '#b7791f' } : { color: 'var(--ink-500)' };
                      return (
                        <tr key={vendor.vendor_name}>
                          <td style={{ fontWeight: 600 }}>{vendor.vendor_name}</td>
                          <td style={{ textAlign: 'right' }}>{vendor.po_count}</td>
                          <td style={{ textAlign: 'right' }}>{money(vendor.total_value)}</td>
                          <td style={{ textAlign: 'right' }}>{vendor.invoice_loss_count ? `${money(vendor.invoice_loss_value)} (${vendor.invoice_loss_count})` : '—'}</td>
                          <td style={{ textAlign: 'right' }}>{vendor.damage_loss_count ? `${money(vendor.damage_loss_value)} (${vendor.damage_loss_count})` : '—'}</td>
                          <td style={{ textAlign: 'right', ...tone }}>{vendor.loss_rate_percent}%</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
                <p className="pl-footnote">
                  Loss rate = (invoice loss + traced damage/loss) &divide; total purchased from that vendor. Damage/loss only counts when it could be
                  traced back to a specific purchase order.
                </p>
              </>
            )}
          </div>
        </>
      )}

      {modal && (
        <Modal title={modal.title} onClose={() => setModal(null)}>
          {modal.body}
        </Modal>
      )}
    </div>
  );
}
