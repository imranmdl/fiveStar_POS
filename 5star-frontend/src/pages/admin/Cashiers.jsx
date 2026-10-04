import { useEffect, useState } from 'react';
import { Link, useOutletContext, useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Cashiers.css';

const METHOD_LABEL = { cash: 'Cash', upi: 'UPI', card: 'Card (POS)', other: 'Other' };

function when(value) {
  return value ? String(value).slice(0, 16).replace('T', ' ') : '—';
}

function todayIso() {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

function AddCashierForm({ onCreated }) {
  const [busy, setBusy] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);

    const body = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (!body.email) delete body.email;

    try {
      await api.post('/admin/pos/cashiers', body);
      toast('Cashier login created.');
      event.currentTarget.reset();
      onCreated();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not create the login.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="cashiers-add-form" onSubmit={handleSubmit}>
      <div className="cashiers-add-form__field">
        <label htmlFor="full_name">Full name</label>
        <input id="full_name" name="full_name" required minLength={3} maxLength={120} />
      </div>
      <div className="cashiers-add-form__field">
        <label htmlFor="mobile">Mobile (login ID)</label>
        <input id="mobile" name="mobile" required inputMode="numeric" maxLength={10} />
      </div>
      <div className="cashiers-add-form__field">
        <label htmlFor="email">Email (optional)</label>
        <input id="email" name="email" type="email" />
      </div>
      <div className="cashiers-add-form__field">
        <label htmlFor="password">Password</label>
        <input id="password" name="password" type="text" required minLength={8} autoComplete="new-password" />
      </div>
      <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>
        {busy ? 'Creating…' : 'Create login'}
      </button>
      <p className="cashiers-add-form__hint">
        The cashier signs in at the Till with their mobile number and this password. At least 8 characters with a letter and a number.
      </p>
    </form>
  );
}

function CashierRow({ cashier, onChanged }) {
  const [busy, setBusy] = useState(false);
  const today = todayIso();
  const activeToday = cashier.last_sale_date && String(cashier.last_sale_date).slice(0, 10) === today;

  async function toggleStatus() {
    const next = cashier.is_active ? 'suspended' : 'active';
    if (next === 'suspended' && !window.confirm('Suspend this cashier? They will be signed out and cannot use the Till.')) return;
    setBusy(true);

    try {
      await api.patch(`/admin/pos/cashiers/${encodeURIComponent(cashier.uuid)}`, { status: next });
      toast(next === 'active' ? 'Cashier reactivated.' : 'Cashier suspended.');
      onChanged();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not update this cashier.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function resetPassword() {
    const password = window.prompt('New password (at least 8 characters, with a letter and a number):');
    if (!password) return;
    setBusy(true);

    try {
      await api.patch(`/admin/pos/cashiers/${encodeURIComponent(cashier.uuid)}`, { password });
      toast('Password changed. They have been signed out.');
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not change the password.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr>
      <td>
        <Link to={`/admin/cashiers?uuid=${encodeURIComponent(cashier.uuid)}`} className="cashiers-row__name">
          {cashier.full_name}
        </Link>
        <div className="cashiers-row__sub">{cashier.mobile}</div>
      </td>
      <td>
        <span className={`status-badge status-badge--${cashier.is_active ? 'success' : 'secondary'}`}>
          {cashier.is_active ? 'Active' : 'Suspended'}
        </span>
      </td>
      <td>
        {cashier.shops_today || '—'}
        {activeToday && <div className="cashiers-row__sub">Last sale {when(cashier.last_sale_date)}</div>}
      </td>
      <td style={{ textAlign: 'center' }}>{cashier.sales_today}</td>
      <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatMoney(cashier.total_today)}</td>
      <td>{when(cashier.last_login_date)}</td>
      <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
        <Link className="admin-btn" to={`/admin/cashiers?uuid=${encodeURIComponent(cashier.uuid)}`}>
          History
        </Link>{' '}
        <button type="button" className="admin-btn" onClick={resetPassword} disabled={busy}>
          Reset password
        </button>{' '}
        <button type="button" className="admin-btn" onClick={toggleStatus} disabled={busy}>
          {cashier.is_active ? 'Suspend' : 'Reactivate'}
        </button>
      </td>
    </tr>
  );
}

function CashiersList() {
  const [cashiers, setCashiers] = useState(null);
  const [error, setError] = useState(null);

  function load() {
    setError(null);
    api
      .get('/admin/pos/cashiers')
      .then((response) => setCashiers((response.data || {}).cashiers || []))
      .catch(setError);
  }

  useEffect(load, []);

  const totalToday = (cashiers || []).reduce((sum, c) => sum + Number(c.total_today || 0), 0);

  return (
    <div className="page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>
          Cashiers (POS)
        </h1>
        <Link className="admin-btn" to="/till">
          Open the Till
        </Link>
      </div>

      <AddCashierForm onCreated={load} />

      {cashiers === null && !error && <LoadingState />}
      {error && <ErrorState error={error} />}
      {cashiers && cashiers.length === 0 && <EmptyState title="No cashiers yet" hint="Add a login above for each person who works a till." />}

      {cashiers && cashiers.length > 0 && (
        <table className="admin-table" style={{ marginTop: 16 }}>
          <thead>
            <tr>
              <th>Cashier</th>
              <th>Status</th>
              <th>Shop today</th>
              <th style={{ textAlign: 'center' }}>Sales today</th>
              <th style={{ textAlign: 'right' }}>Sold today</th>
              <th>Last sign-in</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {cashiers.map((c) => (
              <CashierRow key={c.uuid} cashier={c} onChanged={load} />
            ))}
          </tbody>
          <tfoot>
            <tr style={{ fontWeight: 600 }}>
              <td colSpan={4}>All cashiers today</td>
              <td style={{ textAlign: 'right' }}>{formatMoney(totalToday)}</td>
              <td colSpan={2}></td>
            </tr>
          </tfoot>
        </table>
      )}
    </div>
  );
}

function MiniTable({ title, headers, rows }) {
  return (
    <div className="cashiers-mini-table">
      <h3>{title}</h3>
      {rows.length === 0 ? (
        <p className="cashiers-row__sub">Nothing in this period.</p>
      ) : (
        <table className="admin-table">
          <thead>
            <tr>
              {headers.map((h, i) => (
                <th key={h} style={i > 0 ? { textAlign: 'right' } : undefined}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>{rows}</tbody>
        </table>
      )}
    </div>
  );
}

function CashierHistory({ uuid }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  const from = searchParams.get('from') || todayIso();
  const to = searchParams.get('to') || todayIso();

  useEffect(() => {
    setData(null);
    setError(null);
    api
      .get(`/admin/pos/cashiers/${encodeURIComponent(uuid)}/history`, { from, to })
      .then((response) => setData(response.data))
      .catch(setError);
  }, [uuid, from, to]);

  function goToRange(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setSearchParams({ uuid, from: form.get('from') || todayIso(), to: form.get('to') || todayIso() });
  }

  if (error) {
    return (
      <div className="page">
        <Link to="/admin/cashiers">&larr; All cashiers</Link>
        <ErrorState error={error} />
      </div>
    );
  }

  if (!data) return <LoadingState />;

  const single = data.from === data.to;

  return (
    <div className="page">
      <Link to="/admin/cashiers" className="cashiers-row__sub">
        &larr; All cashiers
      </Link>

      <div className="cashiers-history__header">
        <div>
          <h1 className="admin-page-title" style={{ margin: 0 }}>
            {data.cashier.full_name}
          </h1>
          <div className="cashiers-row__sub">
            {data.cashier.mobile} &middot; last sign-in {when(data.cashier.last_login_date)}
          </div>
        </div>
        <form className="cashiers-history__range" onSubmit={goToRange}>
          <div>
            <label>From</label>
            <input type="date" name="from" defaultValue={data.from} max={todayIso()} />
          </div>
          <div>
            <label>To</label>
            <input type="date" name="to" defaultValue={data.to} max={todayIso()} />
          </div>
          <button type="submit" className="admin-btn admin-btn--primary">
            Show
          </button>
          <button type="button" className="admin-btn" onClick={() => setSearchParams({ uuid, from: todayIso(), to: todayIso() })}>
            Today
          </button>
        </form>
      </div>

      <div className="stat-grid">
        <div className="stat-card">
          <div className="stat-card__label">{single ? 'Sold' : 'Total sold'}</div>
          <div className="stat-card__value">{formatMoney(data.summary.total)}</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__label">Sales</div>
          <div className="stat-card__value">{data.summary.sales}</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__label">Discount given</div>
          <div className="stat-card__value">{formatMoney(data.summary.discount)}</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__label">Voided sales</div>
          <div className="stat-card__value">{data.summary.voided}</div>
        </div>
      </div>

      <div className="cashiers-history__grid">
        <div>
          <MiniTable
            title="By shop"
            headers={['Shop', 'Sales', 'Sold', 'First – last sale']}
            rows={data.by_shop.map((r) => (
              <tr key={r.shop}>
                <td>{r.shop}</td>
                <td style={{ textAlign: 'right' }}>{r.sales}</td>
                <td style={{ textAlign: 'right' }}>{formatMoney(r.total)}</td>
                <td style={{ textAlign: 'right' }}>
                  {String(r.first_sale).slice(11, 16)} – {String(r.last_sale).slice(11, 16)}
                </td>
              </tr>
            ))}
          />
          <MiniTable
            title="By payment method"
            headers={['Method', 'Sales', 'Sold']}
            rows={data.by_payment_method.map((r) => (
              <tr key={r.payment_method}>
                <td>{METHOD_LABEL[r.payment_method] || r.payment_method}</td>
                <td style={{ textAlign: 'right' }}>{r.sales}</td>
                <td style={{ textAlign: 'right' }}>{formatMoney(r.total)}</td>
              </tr>
            ))}
          />
          {!single && (
            <MiniTable
              title="By day"
              headers={['Date', 'Sales', 'Sold']}
              rows={data.by_day.map((r) => (
                <tr key={r.date}>
                  <td>{r.date}</td>
                  <td style={{ textAlign: 'right' }}>{r.sales}</td>
                  <td style={{ textAlign: 'right' }}>{formatMoney(r.total)}</td>
                </tr>
              ))}
            />
          )}
        </div>
        <div>
          <MiniTable
            title="Sales"
            headers={['Sale', 'Shop', 'Method', 'Amount']}
            rows={data.sales.map((r) => (
              <tr key={r.sale_number} style={r.status === 'voided' ? { textDecoration: 'line-through', opacity: 0.6 } : undefined}>
                <td>
                  {r.sale_number}
                  <div className="cashiers-row__sub">{when(r.created_date)}</div>
                </td>
                <td style={{ textAlign: 'right' }}>{r.shop}</td>
                <td style={{ textAlign: 'right' }}>{METHOD_LABEL[r.payment_method] || r.payment_method}</td>
                <td style={{ textAlign: 'right' }}>{formatMoney(r.total)}</td>
              </tr>
            ))}
          />
          {data.sales.length >= 200 && <p className="cashiers-row__sub">Showing the latest 200 sales in this period.</p>}
        </div>
      </div>
    </div>
  );
}

export default function Cashiers() {
  const { user } = useOutletContext();
  const [searchParams] = useSearchParams();
  const uuid = searchParams.get('uuid');

  if (String(user.role) !== 'administrator') {
    return (
      <div className="page">
        <EmptyState title="Administrators only" hint="Ask an administrator to manage till logins." />
      </div>
    );
  }

  return uuid ? <CashierHistory uuid={uuid} /> : <CashiersList />;
}
