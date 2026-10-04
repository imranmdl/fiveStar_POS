import { useEffect, useState } from 'react';
import { useOutletContext, useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Wallets.css';

/**
 * Wallets — a customer's balance, their full ledger, and the admin actions on
 * it (credit, debit, freeze/unfreeze, export). Ported from
 * admin/assets/page-wallets.js. Every figure and action here is a thin UI
 * over WalletService on the backend; this page has no business logic of its
 * own.
 *
 * Administrator only, matching the API's own gate on every /admin/wallet/*
 * route.
 */

const SOURCE_LABEL = {
  referral_reward: 'Referral reward',
  referral_signup_bonus: 'Referral sign-up bonus',
  order_refund: 'Refund',
  promotional: 'Promotional credit',
  cashback: 'Cashback',
  redemption: 'Spent on an order',
  expiry: 'Expired',
  admin_adjustment: 'Admin adjustment',
};

const TILE_INFO = {
  balance: { title: 'Total wallet balance — accounts holding a balance' },
  refund_credits: { title: 'Total refund credits — credited back after a refund' },
  credits: { title: 'Total wallet credits — every credit, any reason' },
  debits: { title: 'Total wallet debits — every debit, any reason' },
  pending_refunds: { title: 'Pending refunds — still waiting on the gateway' },
};

function when(value) {
  return value ? String(value).slice(0, 16).replace('T', ' ') : '—';
}

async function downloadCsv(path, fallbackName) {
  const blob = await api.downloadFile(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function Modal({ title, onClose, children, wide }) {
  return (
    <div className="wal-modal-backdrop" onClick={onClose}>
      <div className={`wal-modal ${wide ? 'wal-modal--wide' : ''}`} onClick={(e) => e.stopPropagation()}>
        <div className="wal-modal__header">
          <h2>{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="wal-modal__body">{children}</div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------------- */
/* Dashboard tiles + drilldown                                               */
/* ------------------------------------------------------------------------- */

function DashboardTiles({ onOpenTile }) {
  const [wallet, setWallet] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.get('/admin/dashboard')
      .then((res) => { if (!cancelled) setWallet((res.data && res.data.wallet) || {}); })
      .catch(() => { if (!cancelled) setWallet({}); });
    return () => { cancelled = true; };
  }, []);

  if (wallet === null) {
    return <div className="wal-tiles wal-tiles--loading"><div className="loading-state">Loading…</div></div>;
  }

  const pr = wallet.pending_refunds || { count: 0, amount: 0 };

  const Tile = ({ tileKey, label, value, hint, valueClass }) => (
    <button type="button" className="wal-tile" onClick={() => onOpenTile(tileKey)}>
      <div className="wal-tile__label">{label}</div>
      <div className={`wal-tile__value ${valueClass || ''}`}>{value}</div>
      <div className="wal-tile__hint">{hint}</div>
    </button>
  );

  return (
    <div className="wal-tiles">
      <Tile tileKey="balance" label="Total wallet balance" value={formatMoney(wallet.total_balance)}
        hint={`${wallet.account_count || 0} account(s) · click for details`} />
      <Tile tileKey="refund_credits" label="Total refund credits" value={formatMoney(wallet.total_refund_credits)}
        hint="click for details" />
      <Tile tileKey="credits" label="Total wallet credits" value={formatMoney(wallet.total_credits)}
        valueClass="wal-tile__value--success" hint="click for details" />
      <Tile tileKey="debits" label="Total wallet debits" value={formatMoney(wallet.total_debits)}
        hint="click for details" />
      <Tile tileKey="pending_refunds" label="Pending refunds" value={formatMoney(pr.amount)}
        valueClass={pr.count > 0 ? 'wal-tile__value--warning' : ''}
        hint={`${pr.count || 0} order(s) · click for details`} />
    </div>
  );
}

function AccountRow({ row }) {
  return (
    <tr>
      <td>
        {row.customer_name}
        <div className="wal-sub">{row.customer_mobile}</div>
      </td>
      <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatMoney(row.balance)}</td>
      <td style={{ textAlign: 'right' }} className="wal-sub">{formatMoney(row.lifetime_credited)}</td>
      <td style={{ textAlign: 'right' }} className="wal-sub">{formatMoney(row.lifetime_debited)}</td>
      <td>{row.is_frozen ? <span className="status-badge status-badge--danger">Frozen</span> : null}</td>
    </tr>
  );
}

function PendingRefundRow({ row }) {
  return (
    <tr>
      <td className="wal-nowrap">{when(row.created_date)}</td>
      <td>
        {row.order_number}
        <div className="wal-sub">{row.customer_name} · {row.customer_mobile}</div>
      </td>
      <td>{row.reason}</td>
      <td style={{ textTransform: 'uppercase' }}>{row.gateway || '—'}</td>
      <td><span className="status-badge status-badge--warning">{row.status}</span></td>
      <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatMoney(row.total_amount)}</td>
    </tr>
  );
}

function TransactionDrilldownRow({ row }) {
  const isCredit = row.direction === 'credit';
  return (
    <tr>
      <td className="wal-nowrap">{when(row.created_date)}</td>
      <td>
        {row.customer_name}
        <div className="wal-sub">{row.customer_mobile}</div>
      </td>
      <td>{SOURCE_LABEL[row.source] || row.source}</td>
      <td className="wal-sub">{row.narration}</td>
      <td style={{ textAlign: 'right' }} className={isCredit ? 'wal-amount-credit' : ''}>
        {isCredit ? '+' : '−'}{formatMoney(row.amount)}
      </td>
    </tr>
  );
}

function DrilldownModal({ kind, onClose }) {
  const [state, setState] = useState({ loading: true, error: null, items: [] });
  const info = TILE_INFO[kind];

  useEffect(() => {
    let cancelled = false;
    setState({ loading: true, error: null, items: [] });

    async function load() {
      try {
        if (kind === 'balance') {
          const res = await api.get('/admin/wallet/accounts', { per_page: 100 });
          if (!cancelled) setState({ loading: false, error: null, items: res.data || [] });
          return;
        }
        if (kind === 'pending_refunds') {
          const res = await api.get('/admin/wallet/pending-refunds', { per_page: 100 });
          if (!cancelled) setState({ loading: false, error: null, items: res.data || [] });
          return;
        }
        const flow = kind === 'debits' ? 'debit' : 'credit';
        const source = kind === 'refund_credits' ? 'order_refund' : '';
        const res = await api.get('/admin/wallet/transactions', { flow, source, per_page: 100 });
        if (!cancelled) setState({ loading: false, error: null, items: res.data || [] });
      } catch (error) {
        if (!cancelled) setState({ loading: false, error, items: [] });
      }
    }

    load();
    return () => { cancelled = true; };
  }, [kind]);

  return (
    <Modal title={info.title} onClose={onClose} wide>
      {state.loading ? (
        <LoadingState />
      ) : state.error ? (
        <ErrorState error={state.error} />
      ) : state.items.length === 0 ? (
        <p className="wal-sub">{kind === 'balance' ? 'No account currently holds a balance.' : kind === 'pending_refunds' ? 'Nothing is waiting on a refund right now.' : 'No matching transactions yet.'}</p>
      ) : kind === 'balance' ? (
        <table className="admin-table">
          <thead><tr><th>Customer</th><th style={{ textAlign: 'right' }}>Balance</th><th style={{ textAlign: 'right' }}>Lifetime credited</th><th style={{ textAlign: 'right' }}>Lifetime debited</th><th></th></tr></thead>
          <tbody>{state.items.map((row, i) => <AccountRow key={row.uuid || i} row={row} />)}</tbody>
        </table>
      ) : kind === 'pending_refunds' ? (
        <table className="admin-table">
          <thead><tr><th>Since</th><th>Order</th><th>Reason</th><th>Gateway</th><th>Status</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
          <tbody>{state.items.map((row, i) => <PendingRefundRow key={row.order_uuid || i} row={row} />)}</tbody>
        </table>
      ) : (
        <table className="admin-table">
          <thead><tr><th>Date</th><th>Customer</th><th>Reason</th><th>Note</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
          <tbody>{state.items.map((row, i) => <TransactionDrilldownRow key={i} row={row} />)}</tbody>
        </table>
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------------------- */
/* Customer search                                                           */
/* ------------------------------------------------------------------------- */

function CustomerSearch({ onFound }) {
  const [mobile, setMobile] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function runSearch(event) {
    if (event) event.preventDefault();
    const value = mobile.trim();
    if (!value) return;
    setError('');
    setBusy(true);

    try {
      const res = await api.get('/admin/pos/customers', { mobile: value });
      onFound({ uuid: res.data.uuid, full_name: res.data.full_name, mobile: res.data.mobile });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'That customer could not be found.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="wal-search-card">
      <form onSubmit={runSearch}>
        <label className="wal-search-label" htmlFor="wal-mobile">Find a customer by mobile number</label>
        <div className="wal-search-row">
          <input id="wal-mobile" inputMode="numeric" autoComplete="off" placeholder="Mobile number"
            value={mobile} onChange={(e) => setMobile(e.target.value)} />
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>
            {busy ? 'Searching…' : 'Find'}
          </button>
        </div>
        {error && <div className="wal-search-error">{error}</div>}
      </form>
    </div>
  );
}

/* ------------------------------------------------------------------------- */
/* Credit / debit forms                                                     */
/* ------------------------------------------------------------------------- */

function CreditForm({ customerUuid, onDone }) {
  const [amount, setAmount] = useState('');
  const [source, setSource] = useState('admin_adjustment');
  const [narration, setNarration] = useState('');
  const [expiryDays, setExpiryDays] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    const body = { amount, source, narration };
    if (expiryDays.trim() !== '') body.expiry_days = expiryDays;

    try {
      await api.post(`/admin/wallet/${encodeURIComponent(customerUuid)}/credit`, body);
      toast('Wallet credited.');
      setAmount('');
      setNarration('');
      setExpiryDays('');
      onDone();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not credit this wallet.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="wal-card">
      <div className="wal-card__header">Add credit</div>
      <form className="wal-form" onSubmit={handleSubmit}>
        <div className="wal-form__row">
          <input type="number" step="0.01" min="1" placeholder="Amount" required
            value={amount} onChange={(e) => setAmount(e.target.value)} />
          <select value={source} onChange={(e) => setSource(e.target.value)}>
            <option value="admin_adjustment">Admin adjustment</option>
            <option value="promotional">Promotional credit</option>
            <option value="cashback">Cashback</option>
            <option value="order_refund">Refund</option>
          </select>
        </div>
        <input placeholder="Reason (required)" required minLength={3}
          value={narration} onChange={(e) => setNarration(e.target.value)} />
        <div className="wal-form__row">
          <input type="number" min="1" placeholder="Expires in days (optional)"
            value={expiryDays} onChange={(e) => setExpiryDays(e.target.value)} />
          <button type="submit" className="admin-btn admin-btn--success" disabled={busy}>
            {busy ? 'Saving…' : 'Add credit'}
          </button>
        </div>
      </form>
    </div>
  );
}

function DebitForm({ customerUuid, isFrozen, onDone, onFreezeToggle }) {
  const [amount, setAmount] = useState('');
  const [narration, setNarration] = useState('');
  const [busy, setBusy] = useState(false);
  const [freezeBusy, setFreezeBusy] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);

    try {
      await api.post(`/admin/wallet/${encodeURIComponent(customerUuid)}/debit`, { amount, narration });
      toast('Wallet debited.');
      setAmount('');
      setNarration('');
      onDone();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not debit this wallet.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function toggleFreeze() {
    if (isFrozen) {
      setFreezeBusy(true);
      try {
        await api.post(`/admin/wallet/${encodeURIComponent(customerUuid)}/unfreeze`);
        toast('Wallet unfrozen.');
        onFreezeToggle();
      } catch (error) {
        toast(error instanceof ApiError ? error.message : 'Could not unfreeze this wallet.', 'danger');
      } finally {
        setFreezeBusy(false);
      }
      return;
    }

    const reason = window.prompt('Why is this wallet being frozen? Shown to the customer.');
    if (!reason) return;
    setFreezeBusy(true);

    try {
      await api.post(`/admin/wallet/${encodeURIComponent(customerUuid)}/freeze`, { reason });
      toast('Wallet frozen.');
      onFreezeToggle();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not freeze this wallet.', 'danger');
    } finally {
      setFreezeBusy(false);
    }
  }

  return (
    <div className="wal-card">
      <div className="wal-card__header">Deduct balance</div>
      <form className="wal-form" onSubmit={handleSubmit}>
        <div className="wal-form__row">
          <input type="number" step="0.01" min="1" placeholder="Amount" required
            value={amount} onChange={(e) => setAmount(e.target.value)} />
          <button type="button" className={`admin-btn ${isFrozen ? 'admin-btn--success' : 'admin-btn--danger'}`} onClick={toggleFreeze} disabled={freezeBusy}>
            {freezeBusy ? 'Saving…' : isFrozen ? 'Unfreeze wallet' : 'Freeze wallet'}
          </button>
        </div>
        <input placeholder="Reason (required)" required minLength={3}
          value={narration} onChange={(e) => setNarration(e.target.value)} />
        <button type="submit" className="admin-btn" disabled={busy}>{busy ? 'Saving…' : 'Deduct'}</button>
      </form>
    </div>
  );
}

/* ------------------------------------------------------------------------- */
/* Customer wallet panel                                                     */
/* ------------------------------------------------------------------------- */

function TransactionRow({ row }) {
  const isCredit = row.direction === 'credit';
  return (
    <tr>
      <td className="wal-nowrap">{when(row.created_date)}</td>
      <td>
        <span className={`status-badge ${isCredit ? 'status-badge--success' : 'status-badge--secondary'}`}>
          {isCredit ? 'Credit' : 'Debit'}
        </span>{' '}
        {SOURCE_LABEL[row.source] || row.source}
      </td>
      <td>{row.narration}</td>
      <td className="wal-sub">{row.reference && row.reference.type ? `${row.reference.type} · ${row.reference.id}` : '—'}</td>
      <td style={{ textAlign: 'right' }} className={isCredit ? 'wal-amount-credit' : ''}>
        {isCredit ? '+' : '−'}{formatMoney(row.amount)}
      </td>
      <td style={{ textAlign: 'right' }} className="wal-sub">{formatMoney(row.balance_after)}</td>
    </tr>
  );
}

function CustomerWalletPanel({ customer, onChangeCustomer }) {
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ loading: true, error: null, wallet: null, integrity: null, items: [], meta: {} });
  const [exportBusy, setExportBusy] = useState(false);

  function load() {
    setState((prev) => ({ ...prev, loading: true, error: null }));
    Promise.all([
      api.get(`/admin/wallet/${encodeURIComponent(customer.uuid)}`),
      api.get(`/admin/wallet/${encodeURIComponent(customer.uuid)}/statement`, { page, per_page: 25 }),
    ])
      .then(([walletRes, statementRes]) => {
        setState({
          loading: false,
          error: null,
          wallet: walletRes.data.wallet,
          integrity: walletRes.data.integrity,
          items: statementRes.data || [],
          meta: statementRes.meta || {},
        });
      })
      .catch((error) => setState((prev) => ({ ...prev, loading: false, error })));
  }

  useEffect(load, [customer.uuid, page]);

  useEffect(() => { setPage(1); }, [customer.uuid]);

  async function exportCustomerCsv() {
    setExportBusy(true);
    try {
      const rows = (await api.get(`/admin/wallet/${encodeURIComponent(customer.uuid)}/statement`, { per_page: 5000 })).data || [];
      const header = 'date,direction,source,narration,reference_type,reference_id,amount,balance_after\n';
      const csv = header + rows.map((r) => [
        r.created_date, r.direction, r.source, `"${String(r.narration).replace(/"/g, '""')}"`,
        (r.reference && r.reference.type) || '', (r.reference && r.reference.id) || '', r.amount, r.balance_after,
      ].join(',')).join('\n');
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `wallet_${customer.mobile}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not export this statement.', 'danger');
    } finally {
      setExportBusy(false);
    }
  }

  if (state.error) {
    return (
      <div>
        <button type="button" className="admin-btn" onClick={onChangeCustomer} style={{ marginBottom: 12 }}>Search another customer</button>
        <ErrorState error={state.error} />
      </div>
    );
  }

  if (state.loading && !state.wallet) {
    return <LoadingState />;
  }

  const { wallet, integrity, items, meta } = state;

  return (
    <div>
      <div className="wal-panel-head">
        <div>
          <h2 className="wal-panel-head__name">{customer.full_name}</h2>
          <div className="wal-sub">{customer.mobile}</div>
        </div>
        <button type="button" className="admin-btn" onClick={onChangeCustomer}>Search another customer</button>
      </div>

      <div className="stat-grid">
        <div className="stat-card">
          <div className="stat-card__label">Balance</div>
          <div className="stat-card__value">{formatMoney(wallet.balance)}</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__label">Lifetime credited</div>
          <div className="stat-card__value">{formatMoney(wallet.lifetime_credited)}</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__label">Lifetime debited</div>
          <div className="stat-card__value">{formatMoney(wallet.lifetime_debited)}</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__label">Status</div>
          <div className="wal-status-value">
            {wallet.is_frozen
              ? <span className="status-badge status-badge--danger">Frozen</span>
              : <span className="status-badge status-badge--success">Active</span>}
          </div>
        </div>
      </div>

      {wallet.is_frozen && (
        <div className="admin-alert admin-alert--warning">
          Frozen: {wallet.frozen_reason || 'no reason on file'}. Credits still post; spending is blocked.
        </div>
      )}
      {integrity && !integrity.matches && (
        <div className="admin-alert admin-alert--danger">
          The ledger and the cached balance disagree for this account (ledger says {formatMoney(integrity.derived_balance)}, stored balance reads {formatMoney(integrity.cached_balance)}) — worth investigating before trusting the figure above.
        </div>
      )}

      <div className="wal-forms-grid">
        <CreditForm customerUuid={customer.uuid} onDone={load} />
        <DebitForm customerUuid={customer.uuid} isFrozen={wallet.is_frozen} onDone={load} onFreezeToggle={load} />
      </div>

      <div className="wal-card">
        <div className="wal-card__header wal-card__header--split">
          <span>Transactions</span>
          <button type="button" className="admin-btn" onClick={exportCustomerCsv} disabled={exportBusy}>
            {exportBusy ? 'Preparing…' : "Export this customer's CSV"}
          </button>
        </div>
        {items.length === 0 ? (
          <EmptyState title="No transactions yet" hint="Credits and debits will appear here." />
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr><th>Date</th><th>Type</th><th>Reason</th><th>Reference</th><th style={{ textAlign: 'right' }}>Amount</th><th style={{ textAlign: 'right' }}>Balance after</th></tr>
              </thead>
              <tbody>{items.map((row, i) => <TransactionRow key={i} row={row} />)}</tbody>
            </table>
          </div>
        )}
        {meta.total_pages > 1 && (
          <div className="wal-pagination">
            <span className="wal-sub">Page {meta.page} of {meta.total_pages}</span>
            <span className="wal-pagination__buttons">
              <button type="button" className="admin-btn" disabled={meta.page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
              <button type="button" className="admin-btn" disabled={meta.page >= meta.total_pages} onClick={() => setPage((p) => p + 1)}>Next</button>
            </span>
          </div>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------------- */
/* Top-level page                                                            */
/* ------------------------------------------------------------------------- */

function WalletsPage() {
  const [searchParams] = useSearchParams();
  const [customer, setCustomer] = useState(null);
  const [resolving, setResolving] = useState(true);
  const [drilldown, setDrilldown] = useState(null);
  const [exportAllBusy, setExportAllBusy] = useState(false);

  useEffect(() => {
    const prefillUuid = searchParams.get('customer_uuid');
    const prefillMobile = searchParams.get('mobile');

    if (!prefillUuid && !prefillMobile) {
      setResolving(false);
      return;
    }

    let cancelled = false;

    (async () => {
      try {
        if (prefillUuid) {
          const res = await api.get(`/admin/wallet/${encodeURIComponent(prefillUuid)}`);
          if (!cancelled) setCustomer(res.data.customer);
        } else {
          const res = await api.get('/admin/pos/customers', { mobile: prefillMobile });
          if (!cancelled) setCustomer({ uuid: res.data.uuid, full_name: res.data.full_name, mobile: res.data.mobile });
        }
      } catch {
        // Fall through to the search screen.
      } finally {
        if (!cancelled) setResolving(false);
      }
    })();

    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function exportAll() {
    setExportAllBusy(true);
    try {
      await downloadCsv('/admin/export/wallet_transactions', 'wallet_transactions.csv');
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not export wallet transactions.', 'danger');
    } finally {
      setExportAllBusy(false);
    }
  }

  return (
    <div className="page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Wallets</h1>
        <button type="button" className="admin-btn" onClick={exportAll} disabled={exportAllBusy}>
          {exportAllBusy ? 'Preparing…' : 'Export all wallet transactions'}
        </button>
      </div>

      <DashboardTiles onOpenTile={setDrilldown} />

      {resolving ? (
        <LoadingState />
      ) : customer ? (
        <CustomerWalletPanel customer={customer} onChangeCustomer={() => setCustomer(null)} />
      ) : (
        <CustomerSearch onFound={setCustomer} />
      )}

      {drilldown && <DrilldownModal kind={drilldown} onClose={() => setDrilldown(null)} />}
    </div>
  );
}

export default function Wallets() {
  const { user } = useOutletContext();

  if (String(user.role) !== 'administrator') {
    return (
      <div className="page">
        <EmptyState title="Administrators only" hint="Ask an administrator to manage wallets." />
      </div>
    );
  }

  return <WalletsPage />;
}
