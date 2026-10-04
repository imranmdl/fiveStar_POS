/**
 * Cashiers (POS): one login per till operator, who is on today, and what each
 * one has sold. Administrator only — the API enforces that; this page just
 * says so plainly for anyone else who lands here.
 *
 * The list and a single cashier's history share this page via ?uuid=<uuid>.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         emptyState, queryParam, headerIcon } from './console.js?v=9';

let root = null;

const CASHIER_ICONS = {
  personPlus: '<circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M18 8v5M15.5 10.5h5"/>',
  list: '<path d="M4 6h16M4 12h16M4 18h10"/>',
};

const METHOD_LABEL = { cash: 'Cash', upi: 'UPI', card: 'Card (POS)', other: 'Other' };

function when(value) {
  return value ? escapeHtml(String(value).slice(0, 16).replace('T', ' ')) : '—';
}

function todayIso() {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

/* ------------------------------------------------------------------------- */
/* List                                                                       */
/* ------------------------------------------------------------------------- */

function cashierRow(c) {
  const today = todayIso();
  const activeToday = c.last_sale_date && String(c.last_sale_date).slice(0, 10) === today;

  return `
    <tr>
      <td>
        <a class="fw-semibold text-decoration-none" href="cashiers.html?uuid=${encodeURIComponent(c.uuid)}">${escapeHtml(c.full_name)}</a>
        <div class="small text-muted">${escapeHtml(c.mobile)}</div>
      </td>
      <td>${c.is_active
        ? '<span class="badge text-bg-success">Active</span>'
        : '<span class="badge text-bg-secondary">Suspended</span>'}</td>
      <td class="small">${escapeHtml(c.shops_today || '—')}
        ${activeToday ? `<div class="text-muted">Last sale ${when(c.last_sale_date)}</div>` : ''}</td>
      <td class="text-center">${escapeHtml(c.sales_today)}</td>
      <td class="text-end fw-semibold">${formatMoney(c.total_today)}</td>
      <td class="small">${when(c.last_login_date)}</td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="cashiers.html?uuid=${encodeURIComponent(c.uuid)}">History</a>
        <button class="btn btn-sm btn-outline-secondary" data-password="${escapeHtml(c.uuid)}">Reset password</button>
        <button class="btn btn-sm ${c.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                data-toggle="${escapeHtml(c.uuid)}" data-next="${c.is_active ? 'suspended' : 'active'}">
          ${c.is_active ? 'Suspend' : 'Reactivate'}</button>
      </td>
    </tr>`;
}

function addForm() {
  return `
    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold">${headerIcon('#2B6E8F', CASHIER_ICONS.personPlus)}Add a cashier login</div>
      <div class="card-body">
        <form class="row g-2 align-items-end" data-add-form autocomplete="off">
          <div class="col-12 col-md-3">
            <label class="form-label small mb-1">Full name</label>
            <input class="form-control form-control-sm" name="full_name" required minlength="3" maxlength="120">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Mobile (login ID)</label>
            <input class="form-control form-control-sm" name="mobile" required inputmode="numeric" maxlength="10">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Email (optional)</label>
            <input class="form-control form-control-sm" name="email" type="email">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Password</label>
            <input class="form-control form-control-sm" name="password" type="text" required minlength="8"
                   autocomplete="new-password">
          </div>
          <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-dark w-100" type="submit">Create login</button>
          </div>
          <div class="col-12 small text-muted">
            The cashier signs in at the Till with their mobile number and this password.
            At least 8 characters with a letter and a number.
          </div>
        </form>
      </div>
    </div>`;
}

async function renderList() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Cashiers (POS)</h1>
      <a class="btn btn-sm btn-outline-secondary" href="till.html">Open the Till</a>
    </div>
    ${addForm()}
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  root.querySelector('[data-add-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('[type="submit"]');
    setBusy(submit, true, 'Creating');

    try {
      const body = Object.fromEntries(new FormData(form));
      if (!body.email) delete body.email;
      await api.post('/admin/pos/cashiers', body);
      toast('Cashier login created.');
      renderList();
    } catch (error) {
      setBusy(submit, false);
      showError(error);
    }
  });

  const list = root.querySelector('[data-list]');

  try {
    const cashiers = ((await api.get('/admin/pos/cashiers')).data || {}).cashiers || [];

    if (cashiers.length === 0) {
      list.innerHTML = emptyState('No cashiers yet', 'Add a login above for each person who works a till.');
      return;
    }

    const totalToday = cashiers.reduce((sum, c) => sum + c.total_today, 0);

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr>
            <th>Cashier</th><th>Status</th><th>Shop today</th><th class="text-center">Sales today</th>
            <th class="text-end">Sold today</th><th>Last sign-in</th><th></th>
          </tr></thead>
          <tbody>${cashiers.map(cashierRow).join('')}</tbody>
          <tfoot><tr class="fw-semibold">
            <td colspan="4">All cashiers today</td>
            <td class="text-end">${formatMoney(totalToday)}</td><td colspan="2"></td>
          </tr></tfoot>
        </table>
      </div>`;

    list.querySelectorAll('[data-toggle]').forEach((button) => {
      button.addEventListener('click', async () => {
        const next = button.dataset.next;
        if (next === 'suspended' && !window.confirm('Suspend this cashier? They will be signed out and cannot use the Till.')) return;
        setBusy(button, true, 'Saving');

        try {
          await api.patch(`/admin/pos/cashiers/${encodeURIComponent(button.dataset.toggle)}`, { status: next });
          toast(next === 'active' ? 'Cashier reactivated.' : 'Cashier suspended.');
          renderList();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    list.querySelectorAll('[data-password]').forEach((button) => {
      button.addEventListener('click', async () => {
        const password = window.prompt('New password (at least 8 characters, with a letter and a number):');
        if (!password) return;
        setBusy(button, true, 'Saving');

        try {
          await api.patch(`/admin/pos/cashiers/${encodeURIComponent(button.dataset.password)}`, { password });
          toast('Password changed. They have been signed out.');
        } catch (error) {
          showError(error);
        } finally {
          setBusy(button, false);
        }
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

/* ------------------------------------------------------------------------- */
/* One cashier's history                                                      */
/* ------------------------------------------------------------------------- */

function miniTable(title, headers, rows) {
  return `
    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold">${headerIcon('#2B6E8F', CASHIER_ICONS.list)}${escapeHtml(title)}</div>
      ${rows.length === 0
        ? '<div class="card-body small text-muted">Nothing in this period.</div>'
        : `<div class="table-responsive"><table class="table table-tight mb-0">
             <thead><tr>${headers.map((h, i) => `<th class="${i > 0 ? 'text-end' : ''}">${escapeHtml(h)}</th>`).join('')}</tr></thead>
             <tbody>${rows.join('')}</tbody></table></div>`}
    </div>`;
}

async function renderHistory(uuid) {
  const from = queryParam('from') || todayIso();
  const to = queryParam('to') || todayIso();

  root.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>';

  try {
    const d = (await api.get(`/admin/pos/cashiers/${encodeURIComponent(uuid)}/history`, { from, to })).data;
    const single = d.from === d.to;

    root.innerHTML = `
      <a class="small text-decoration-none" href="cashiers.html">← All cashiers</a>
      <div class="d-flex flex-wrap justify-content-between align-items-end mt-2 mb-3 gap-2">
        <div>
          <h1 class="h4 mb-0">${escapeHtml(d.cashier.full_name)}</h1>
          <div class="small text-muted">${escapeHtml(d.cashier.mobile)} · last sign-in ${when(d.cashier.last_login_date)}</div>
        </div>
        <form class="d-flex flex-wrap gap-2 align-items-end" data-range>
          <div><label class="form-label small mb-0">From</label>
            <input class="form-control form-control-sm" type="date" name="from" value="${escapeHtml(d.from)}" max="${todayIso()}"></div>
          <div><label class="form-label small mb-0">To</label>
            <input class="form-control form-control-sm" type="date" name="to" value="${escapeHtml(d.to)}" max="${todayIso()}"></div>
          <button class="btn btn-sm btn-dark" type="submit">Show</button>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-today>Today</button>
        </form>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card"><div class="card-body">
          <div class="small text-muted">${single ? 'Sold' : 'Total sold'}</div>
          <div class="fs-4 fw-semibold">${formatMoney(d.summary.total)}</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body">
          <div class="small text-muted">Sales</div>
          <div class="fs-4 fw-semibold">${escapeHtml(d.summary.sales)}</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body">
          <div class="small text-muted">Discount given</div>
          <div class="fs-4 fw-semibold">${formatMoney(d.summary.discount)}</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body">
          <div class="small text-muted">Voided sales</div>
          <div class="fs-4 fw-semibold">${escapeHtml(d.summary.voided)}</div></div></div></div>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-6">
          ${miniTable('By shop', ['Shop', 'Sales', 'Sold', 'First – last sale'], d.by_shop.map((r) => `
            <tr><td>${escapeHtml(r.shop)}</td><td class="text-end">${escapeHtml(r.sales)}</td>
                <td class="text-end">${formatMoney(r.total)}</td>
                <td class="text-end small">${escapeHtml(String(r.first_sale).slice(11, 16))} – ${escapeHtml(String(r.last_sale).slice(11, 16))}</td></tr>`))}
          ${miniTable('By payment method', ['Method', 'Sales', 'Sold'], d.by_payment_method.map((r) => `
            <tr><td>${escapeHtml(METHOD_LABEL[r.payment_method] || r.payment_method)}</td>
                <td class="text-end">${escapeHtml(r.sales)}</td><td class="text-end">${formatMoney(r.total)}</td></tr>`))}
          ${single ? '' : miniTable('By day', ['Date', 'Sales', 'Sold'], d.by_day.map((r) => `
            <tr><td>${escapeHtml(r.date)}</td><td class="text-end">${escapeHtml(r.sales)}</td>
                <td class="text-end">${formatMoney(r.total)}</td></tr>`))}
        </div>
        <div class="col-12 col-lg-6">
          ${miniTable('Sales', ['Sale', 'Shop', 'Method', 'Amount'], d.sales.map((r) => `
            <tr class="${r.status === 'voided' ? 'text-decoration-line-through text-muted' : ''}">
              <td>${escapeHtml(r.sale_number)}<div class="small text-muted">${when(r.created_date)}</div></td>
              <td class="text-end small">${escapeHtml(r.shop)}</td>
              <td class="text-end small">${escapeHtml(METHOD_LABEL[r.payment_method] || r.payment_method)}</td>
              <td class="text-end">${formatMoney(r.total)}</td></tr>`))}
          ${d.sales.length >= 200 ? '<p class="small text-muted">Showing the latest 200 sales in this period.</p>' : ''}
        </div>
      </div>`;

    const go = (f, t) => {
      window.location.href = `cashiers.html?uuid=${encodeURIComponent(uuid)}&from=${f}&to=${t}`;
    };

    root.querySelector('[data-range]').addEventListener('submit', (event) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      go(data.get('from') || todayIso(), data.get('to') || todayIso());
    });
    root.querySelector('[data-today]').addEventListener('click', () => go(todayIso(), todayIso()));
  } catch (error) {
    root.innerHTML = '<a class="small" href="cashiers.html">← All cashiers</a>';
    showError(error, root);
  }
}

const mounted = await mountConsole('cashiers.html');

if (mounted) {
  root = mounted.root;

  if (String(mounted.user.role) !== 'administrator') {
    root.innerHTML = emptyState('Administrators only', 'Ask an administrator to manage till logins.');
  } else if (queryParam('uuid')) {
    renderHistory(queryParam('uuid'));
  } else {
    renderList();
  }
}
