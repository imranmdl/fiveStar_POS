import { useEffect, useMemo, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { api, formatMoney } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState, StatCard } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './StockAudit.css';

/**
 * Stock Audit: for a date range, what was bought at what landed cost, what it
 * is priced to sell at, and where every unit went (POS counter, online,
 * damage, loss, returns) — grouped by item, category or vendor — so the owner
 * can spot a wrong data entry: a selling price below cost, a missing cost, a
 * stock figure that no longer matches the ledger.
 *
 * Ported from admin/assets/page-stock-audit.js. Reads the inventory movement
 * ledger via /admin/reports/stock-audit (and its /movements sibling for the
 * per-item drill-down) — this page has no logic of its own beyond shaping
 * those numbers for display.
 */

const FLAG_INFO = {
  below_cost: ['danger', 'Selling below cost'],
  no_cost: ['warning', 'No cost recorded'],
  low_margin: ['warning', 'Margin under 5%'],
  over_mrp: ['danger', 'Selling above MRP'],
  negative_stock: ['danger', 'Negative stock'],
  ledger_mismatch: ['danger', 'Stock ≠ ledger'],
};

const MOVEMENT_TYPE_LABEL = {
  inward: 'Inward',
  sale: 'Sale',
  return: 'Return',
  damage: 'Damage',
  lost: 'Lost',
  adjustment: 'Adjustment',
  return_to_vendor: 'To vendor',
  transfer_in: 'Transfer in',
  transfer_out: 'Transfer out',
};

function iso(d) {
  return d.toISOString().slice(0, 10);
}

function daysAgo(n) {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d;
}

function money(v) {
  return v === null || v === undefined ? '—' : formatMoney(v);
}

function qty(v) {
  const n = Number(v) || 0;
  return Number.isInteger(n) ? String(n) : n.toFixed(3).replace(/\.?0+$/, '');
}

function signed(v) {
  const n = Number(v) || 0;
  return n > 0 ? `+${qty(n)}` : qty(n);
}

function MarginCell({ row }) {
  if (row.margin_percent === null || row.margin_percent === undefined) {
    return <span className="sa-muted">—</span>;
  }
  const tone = row.margin_percent < 0 ? '#c0392b' : row.margin_percent < 5 ? '#b7791f' : '#1e8a5b';
  return (
    <span style={{ color: tone, fontWeight: 600 }}>
      {row.margin_percent}%
    </span>
  );
}

function FlagBadges({ flags }) {
  if (!flags || flags.length === 0) return <span className="sa-ok">OK</span>;
  return flags.map((f) => {
    const [tone, label] = FLAG_INFO[f] || ['secondary', f];
    return (
      <span key={f} className={`status-badge status-badge--${tone} sa-flag-badge`} title={label}>
        {label}
      </span>
    );
  });
}

/** The 8 columns shared by the item and category tables (opening through closing). */
function StockCells({ row, withUnit }) {
  const u = withUnit && row.unit ? ` ${row.unit}` : '';
  const other = Number(row.customer_returns || 0) + Number(row.adjustments || 0) + Number(row.other || 0);
  const damageLost = Number(row.damaged || 0) + Number(row.lost || 0);

  return (
    <>
      <td style={{ textAlign: 'right' }}>{qty(row.opening)}{u}</td>
      <td style={{ textAlign: 'right', color: row.inward ? '#1e8a5b' : undefined }}>{row.inward ? `+${qty(row.inward)}` : '·'}</td>
      <td style={{ textAlign: 'right' }}>{row.pos_sales ? `−${qty(row.pos_sales)}` : '·'}</td>
      <td style={{ textAlign: 'right' }}>{row.online_sales ? `−${qty(row.online_sales)}` : '·'}</td>
      <td style={{ textAlign: 'right' }}>{damageLost ? `−${qty(damageLost)}` : '·'}</td>
      <td style={{ textAlign: 'right' }}>{row.vendor_returns ? `−${qty(row.vendor_returns)}` : '·'}</td>
      <td style={{ textAlign: 'right' }}>{other ? signed(other) : '·'}</td>
      <td style={{ textAlign: 'right', fontWeight: 600, color: Number(row.closing) < 0 ? '#c0392b' : undefined }}>{qty(row.closing)}{u}</td>
    </>
  );
}

function ItemTable({ rows, onOpenItem }) {
  return (
    <div className="sa-table-wrap">
      <table className="admin-table">
        <thead>
          <tr>
            <th>Item</th>
            <th style={{ textAlign: 'right' }}>Open</th>
            <th style={{ textAlign: 'right' }}>In</th>
            <th style={{ textAlign: 'right' }} title="Sold at the counter">POS</th>
            <th style={{ textAlign: 'right' }} title="Sold online">Online</th>
            <th style={{ textAlign: 'right' }}>Dmg/lost</th>
            <th style={{ textAlign: 'right' }}>To vendor</th>
            <th style={{ textAlign: 'right' }} title="Customer returns, stock adjustments, other">Ret/adj</th>
            <th style={{ textAlign: 'right' }}>Close</th>
            <th style={{ textAlign: 'right' }} title="Weighted average landed cost per unit">Landing</th>
            <th style={{ textAlign: 'right' }} title="Selling price, with MRP below">Sell / MRP</th>
            <th style={{ textAlign: 'right' }}>Margin</th>
            <th style={{ textAlign: 'right' }} title="Closing stock × landing cost, and × selling price">Stock @ cost / sell</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.uuid} className={r.flags && r.flags.length ? 'sa-row--flagged' : ''} onClick={() => onOpenItem(r.uuid)}>
              <td className="sa-item-cell">
                <span style={{ fontWeight: 600 }}>{r.name}</span>
                <div className="sa-sub">{r.sku} · {r.category}</div>
                {r.flags && r.flags.length > 0 && (
                  <div className="sa-flag-row">
                    <FlagBadges flags={r.flags} />
                  </div>
                )}
              </td>
              <StockCells row={r} withUnit />
              <td style={{ textAlign: 'right' }}>
                {money(r.landing_cost)}
                {r.last_cost !== null && r.landing_cost !== null && Math.abs(r.last_cost - r.landing_cost) > 0.005 && (
                  <div className="sa-sub">last {money(r.last_cost)}</div>
                )}
              </td>
              <td style={{ textAlign: 'right' }}>
                {money(r.selling_price)}
                <div className="sa-sub">{money(r.mrp)}</div>
              </td>
              <td style={{ textAlign: 'right' }}><MarginCell row={r} /></td>
              <td style={{ textAlign: 'right' }}>
                {money(r.closing_cost_value)}
                <div className="sa-sub">{money(r.closing_selling_value)}</div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CategoryTable({ rows, onOpenCategory }) {
  return (
    <div className="sa-table-wrap">
      <table className="admin-table">
        <thead>
          <tr>
            <th>Category</th>
            <th style={{ textAlign: 'right' }}>Items</th>
            <th style={{ textAlign: 'right' }}>Open</th>
            <th style={{ textAlign: 'right' }}>In</th>
            <th style={{ textAlign: 'right' }} title="Sold at the counter">POS</th>
            <th style={{ textAlign: 'right' }} title="Sold online">Online</th>
            <th style={{ textAlign: 'right' }}>Dmg/lost</th>
            <th style={{ textAlign: 'right' }}>To vendor</th>
            <th style={{ textAlign: 'right' }} title="Customer returns, stock adjustments, other">Ret/adj</th>
            <th style={{ textAlign: 'right' }}>Close</th>
            <th style={{ textAlign: 'right' }} title="Closing stock × landing cost, and × selling price">Stock @ cost / sell</th>
            <th style={{ textAlign: 'right' }}>Margin</th>
            <th style={{ textAlign: 'right' }} title="Sales value in this period">Sales ₹ POS / online</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.uuid} className={r.flagged ? 'sa-row--flagged' : ''} onClick={() => onOpenCategory(r.uuid)}>
              <td className="sa-item-cell">
                <span style={{ fontWeight: 600 }}>{r.name}</span>
                {r.flagged > 0 && (
                  <div>
                    <span className="status-badge status-badge--warning">{r.flagged} item{r.flagged === 1 ? '' : 's'} to check</span>
                  </div>
                )}
              </td>
              <td style={{ textAlign: 'right' }}>{r.items}</td>
              <StockCells row={r} withUnit={false} />
              <td style={{ textAlign: 'right' }}>
                {money(r.closing_cost_value)}
                <div className="sa-sub">{money(r.closing_selling_value)}</div>
              </td>
              <td style={{ textAlign: 'right' }}><MarginCell row={r} /></td>
              <td style={{ textAlign: 'right' }}>
                {money(r.pos_sales_value)}
                <div className="sa-sub">{money(r.online_sales_value)}</div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function VendorTable({ rows, onOpenVendor }) {
  return (
    <div className="sa-table-wrap">
      <table className="admin-table">
        <thead>
          <tr>
            <th>Vendor</th>
            <th style={{ textAlign: 'right' }}>Purchases</th>
            <th style={{ textAlign: 'right' }}>Items</th>
            <th style={{ textAlign: 'right' }}>Qty bought</th>
            <th style={{ textAlign: 'right' }} title="What the vendor billed, before transport">Billed cost</th>
            <th style={{ textAlign: 'right' }} title="Including transport share">Landed cost</th>
            <th style={{ textAlign: 'right' }} title="Qty × the selling price entered at inward">Selling value</th>
            <th style={{ textAlign: 'right' }}>MRP value</th>
            <th style={{ textAlign: 'right' }}>Expected margin</th>
            <th style={{ textAlign: 'right' }}>Returned</th>
            <th>Check</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const issues = [];
            if (r.below_cost_lines) issues.push(`${r.below_cost_lines} line(s) priced below cost`);
            if (r.no_cost_lines) issues.push(`${r.no_cost_lines} line(s) with no cost`);
            return (
              <tr key={r.uuid} className={issues.length ? 'sa-row--flagged' : ''} onClick={() => onOpenVendor(r.uuid)}>
                <td style={{ fontWeight: 600 }}>{r.name}</td>
                <td style={{ textAlign: 'right' }}>{r.orders}</td>
                <td style={{ textAlign: 'right' }}>{r.items}</td>
                <td style={{ textAlign: 'right' }}>{qty(r.quantity)}</td>
                <td style={{ textAlign: 'right' }}>{money(r.base_value)}</td>
                <td style={{ textAlign: 'right' }}>{money(r.landed_value)}</td>
                <td style={{ textAlign: 'right' }}>{money(r.selling_value)}</td>
                <td style={{ textAlign: 'right' }}>{money(r.mrp_value)}</td>
                <td style={{ textAlign: 'right' }}><MarginCell row={{ margin_percent: r.expected_margin_percent }} /></td>
                <td style={{ textAlign: 'right' }}>{r.returned_quantity ? `${qty(r.returned_quantity)} (${money(r.returned_value)})` : '·'}</td>
                <td>
                  {issues.length ? (
                    issues.map((i, idx) => (
                      <span key={idx} className="status-badge status-badge--warning sa-flag-badge">{i}</span>
                    ))
                  ) : (
                    <span className="sa-ok">OK</span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function MovementsModal({ uuid, from, to, onClose }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    setData(null);
    setError(null);
    api
      .get('/admin/reports/stock-audit/movements', { variant_uuid: uuid, from, to })
      .then((res) => setData(res.data))
      .catch(setError);
  }, [uuid, from, to]);

  return (
    <div className="sa-modal-backdrop" onClick={onClose}>
      <div className="sa-modal" onClick={(e) => e.stopPropagation()}>
        <div className="sa-modal__header">
          <h2>{data ? `${data.item.name} (${data.item.sku}) — ${from} to ${to}` : 'Stock movements'}</h2>
          <button type="button" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="sa-modal__body">
          {error && <ErrorState error={error} />}
          {!data && !error && <LoadingState />}
          {data && data.movements.length === 0 && (
            <EmptyState title="No movements" hint="Nothing was recorded for this item in the period." />
          )}
          {data && data.movements.length > 0 && (
            <div className="sa-table-wrap">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Counter / channel</th>
                    <th style={{ textAlign: 'right' }}>Qty</th>
                    <th style={{ textAlign: 'right' }}>Balance</th>
                    <th style={{ textAlign: 'right' }}>Unit cost</th>
                    <th>Batch</th>
                    <th>Entered by</th>
                    <th>Reason</th>
                  </tr>
                </thead>
                <tbody>
                  {data.movements.map((m, i) => (
                    <tr key={i}>
                      <td>{m.date}</td>
                      <td>{MOVEMENT_TYPE_LABEL[m.type] || m.type}</td>
                      <td>{m.source}</td>
                      <td>{m.channel || '—'}</td>
                      <td style={{ textAlign: 'right', color: m.quantity_delta < 0 ? '#c0392b' : '#1e8a5b' }}>{signed(m.quantity_delta)}</td>
                      <td style={{ textAlign: 'right' }}>{qty(m.quantity_after)}</td>
                      <td style={{ textAlign: 'right' }}>{m.unit_cost !== null ? money(m.unit_cost) : '—'}</td>
                      <td>{m.batch_no || '—'}</td>
                      <td>{m.entered_by || '—'}</td>
                      <td className="sa-sub">{m.reason || ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function toCsv(rows, group) {
  const cols =
    group === 'vendor'
      ? [
          ['name', 'Vendor'], ['orders', 'Purchases'], ['items', 'Items'], ['quantity', 'Qty bought'], ['base_value', 'Billed cost'],
          ['landed_value', 'Landed cost'], ['selling_value', 'Selling value'], ['mrp_value', 'MRP value'],
          ['expected_margin_percent', 'Expected margin %'], ['returned_quantity', 'Returned qty'], ['returned_value', 'Returned value'],
        ]
      : [
          [group === 'item' ? 'sku' : 'uuid', group === 'item' ? 'SKU' : 'Id'],
          ['name', group === 'item' ? 'Item' : 'Category'],
          ...(group === 'item' ? [['category', 'Category']] : [['items', 'Items']]),
          ['opening', 'Opening'], ['inward', 'Inward'], ['pos_sales', 'POS sold'], ['online_sales', 'Online sold'],
          ['damaged', 'Damaged'], ['lost', 'Lost'], ['vendor_returns', 'Back to vendor'], ['customer_returns', 'Customer returns'],
          ['adjustments', 'Adjustments'], ['closing', 'Closing'],
          ...(group === 'item' ? [['landing_cost', 'Landing cost'], ['selling_price', 'Selling price'], ['mrp', 'MRP']] : []),
          ['margin_percent', 'Margin %'], ['closing_cost_value', 'Stock @ cost'], ['closing_selling_value', 'Stock @ selling'],
          ['pos_sales_value', 'POS sales value'], ['online_sales_value', 'Online sales value'],
          ...(group === 'item' ? [['flags', 'Checks']] : []),
        ];

  const cell = (v) => {
    const s = Array.isArray(v) ? v.map((f) => (FLAG_INFO[f] ? FLAG_INFO[f][1] : f)).join('; ') : v ?? '';
    return `"${String(s).replace(/"/g, '""')}"`;
  };

  return [cols.map(([, h]) => cell(h)).join(','), ...rows.map((r) => cols.map(([k]) => cell(r[k])).join(','))].join('\n');
}

const STOCK_AUDIT_ROLES = ['administrator', 'supervisor'];

export default function StockAudit() {
  const { user } = useOutletContext();

  if (!STOCK_AUDIT_ROLES.includes(String(user.role))) {
    return (
      <div className="page">
        <EmptyState title="Supervisors and administrators only" hint="Ask a supervisor or administrator to run a stock audit." />
      </div>
    );
  }

  return <StockAuditPanel />;
}

/** Everything below the role gate — a separate component so the gate's early return never skips a hook. */
function StockAuditPanel() {
  const today = useMemo(() => new Date(), []);
  const monthStart = useMemo(() => iso(new Date(today.getFullYear(), today.getMonth(), 1)), [today]);
  const lastMonthStart = useMemo(() => iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)), [today]);
  const lastMonthEnd = useMemo(() => iso(new Date(today.getFullYear(), today.getMonth(), 0)), [today]);

  const [group, setGroup] = useState('item');
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(iso(today));
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [categoryUuid, setCategoryUuid] = useState('');
  const [vendorUuid, setVendorUuid] = useState('');
  const [flaggedOnly, setFlaggedOnly] = useState(false);

  const [rows, setRows] = useState([]);
  const [totals, setTotals] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [categories, setCategories] = useState([]);
  const [vendors, setVendors] = useState([]);
  const [movementsUuid, setMovementsUuid] = useState(null);

  // Debounce the search box before it hits the API, same 350ms as the source.
  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  // Filter option lists, loaded once.
  useEffect(() => {
    Promise.all([
      api.get('/admin/inventory/setup').catch(() => ({ data: { categories: [] } })),
      api.get('/admin/vendors', { per_page: 200 }).catch(() => ({ data: [] })),
    ])
      .then(([setup, vendorsRes]) => {
        const cats = setup.data.categories || [];
        const byUuid = Object.fromEntries(cats.map((c) => [c.uuid, c]));
        setCategories(
          cats
            .map((c) => ({ uuid: c.uuid, label: c.parent_uuid && byUuid[c.parent_uuid] ? `${byUuid[c.parent_uuid].name} › ${c.name}` : c.name }))
            .sort((a, b) => a.label.localeCompare(b.label)),
        );
        setVendors(vendorsRes.data || []);
      })
      .catch(() => {
        /* filters just stay empty */
      });
  }, []);

  useEffect(() => {
    setLoading(true);
    setError(null);

    const params = { from, to, group };
    if (group === 'item') {
      if (search) params.search = search;
      if (vendorUuid) params.vendor_uuid = vendorUuid;
    }
    if (categoryUuid && group === 'item') params.category_uuid = categoryUuid;

    api
      .get('/admin/reports/stock-audit', params)
      .then((res) => {
        setRows(res.data.rows || []);
        setTotals(res.data.totals || null);
      })
      .catch(setError)
      .finally(() => setLoading(false));
  }, [group, from, to, search, categoryUuid, vendorUuid]);

  const visibleRows = useMemo(() => {
    if (!flaggedOnly || group === 'vendor') return rows;
    return rows.filter((r) => (group === 'item' ? r.flags && r.flags.length : r.flagged));
  }, [rows, flaggedOnly, group]);

  function drillCategory(uuid) {
    setGroup('item');
    setCategoryUuid(uuid);
    setVendorUuid('');
  }

  function drillVendor(uuid) {
    setGroup('item');
    setVendorUuid(uuid);
    setCategoryUuid('');
  }

  function exportCsv() {
    if (visibleRows.length === 0) {
      toast('Nothing to export.', 'warning');
      return;
    }
    const blob = new Blob(['﻿' + toCsv(visibleRows, group)], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `stock-audit-${group}-${from}_${to}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <div>
          <h1 className="admin-page-title" style={{ margin: 0 }}>Stock Audit</h1>
          <p className="sa-sub" style={{ margin: 0 }}>Landing cost, selling value and every stock movement — to check that data entry is right.</p>
        </div>
        <button type="button" className="admin-btn" onClick={exportCsv}>Export CSV</button>
      </div>

      <div className="sa-card sa-filters">
        <div className="sa-tabs">
          {[['item', 'By item'], ['category', 'By category'], ['vendor', 'By vendor']].map(([k, l]) => (
            <button
              key={k}
              type="button"
              className={group === k ? 'admin-btn admin-btn--primary' : 'admin-btn'}
              onClick={() => setGroup(k)}
            >
              {l}
            </button>
          ))}
        </div>

        <div className="sa-filter-grid">
          <div className="sa-field">
            <label className="sa-field-label">From</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="sa-field">
            <label className="sa-field-label">To</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <div className="sa-presets">
            <button type="button" className="admin-btn" onClick={() => { setFrom(iso(today)); setTo(iso(today)); }}>Today</button>
            <button type="button" className="admin-btn" onClick={() => { setFrom(iso(daysAgo(6))); setTo(iso(today)); }}>7 days</button>
            <button type="button" className="admin-btn" onClick={() => { setFrom(iso(daysAgo(29))); setTo(iso(today)); }}>30 days</button>
            <button type="button" className="admin-btn" onClick={() => { setFrom(monthStart); setTo(iso(today)); }}>This month</button>
            <button type="button" className="admin-btn" onClick={() => { setFrom(lastMonthStart); setTo(lastMonthEnd); }}>Last month</button>
          </div>

          {group === 'item' && (
            <>
              <div className="sa-field">
                <label className="sa-field-label">Category</label>
                <select value={categoryUuid} onChange={(e) => setCategoryUuid(e.target.value)}>
                  <option value="">All</option>
                  {categories.map((c) => (
                    <option key={c.uuid} value={c.uuid}>{c.label}</option>
                  ))}
                </select>
              </div>
              <div className="sa-field">
                <label className="sa-field-label">Vendor</label>
                <select value={vendorUuid} onChange={(e) => setVendorUuid(e.target.value)}>
                  <option value="">All</option>
                  {vendors.map((v) => (
                    <option key={v.uuid} value={v.uuid}>{v.name}</option>
                  ))}
                </select>
              </div>
              <div className="sa-field sa-search-field">
                <label className="sa-field-label">Search</label>
                <input placeholder="Search item, SKU or barcode" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
              </div>
            </>
          )}

          {group !== 'vendor' && (
            <div className="sa-flagged-check">
              <label>
                <input type="checkbox" checked={flaggedOnly} onChange={(e) => setFlaggedOnly(e.target.checked)} />
                Only show items needing a check
              </label>
            </div>
          )}
        </div>
      </div>

      {group !== 'vendor' && totals && (
        <>
          <div className="stat-grid">
            <StatCard label="Items shown" value={totals.items} hint={group === 'item' ? '' : 'across the categories below'} />
            <StatCard label="Stock @ landing cost" value={money(totals.closing_cost_value)} hint="closing stock" />
            <StatCard label="Stock @ selling price" value={money(totals.closing_selling_value)} hint="closing stock" />
            <StatCard label="Sales — counter (POS)" value={money(totals.pos_sales_value)} hint="in this period" />
            <StatCard label="Sales — online" value={money(totals.online_sales_value)} hint="in this period" />
          </div>
          {totals.flagged > 0 && (
            <div className="admin-alert admin-alert--warning">
              <strong>{totals.flagged}</strong> item(s) need a look — highlighted below. Click any row to see every stock movement behind it.
            </div>
          )}
        </>
      )}

      {loading && <LoadingState />}
      {!loading && error && <ErrorState error={error} />}
      {!loading && !error && visibleRows.length === 0 && (
        <div className="sa-card">
          <EmptyState title="Nothing to show" hint="No stock movement or purchases in this period for these filters." />
        </div>
      )}
      {!loading && !error && visibleRows.length > 0 && (
        <div className="sa-card">
          {group === 'item' && <ItemTable rows={visibleRows} onOpenItem={setMovementsUuid} />}
          {group === 'category' && <CategoryTable rows={visibleRows} onOpenCategory={drillCategory} />}
          {group === 'vendor' && <VendorTable rows={visibleRows} onOpenVendor={drillVendor} />}
        </div>
      )}

      {group === 'vendor' && <p className="sa-sub sa-hint">Click a vendor to see the items bought from them.</p>}
      {group === 'category' && <p className="sa-sub sa-hint">Click a category to see its items.</p>}

      {movementsUuid && <MovementsModal uuid={movementsUuid} from={from} to={to} onClose={() => setMovementsUuid(null)} />}
    </div>
  );
}
