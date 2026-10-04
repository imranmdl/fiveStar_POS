/** Customer's own loyalty points: balance, history, and redeeming into wallet credit. */

import { api, bootstrapSession, isSignedIn } from './api.js';
import { mountChrome, mountFooter, escapeHtml, showError, toast, setBusy } from './ui.js';

const root = document.querySelector('[data-page-root]');

const SOURCE_LABEL = {
  purchase_online: 'Order placed',
  purchase_pos: 'In-store purchase',
  review: 'Review approved',
  referral: 'Referral bonus',
  redemption: 'Redeemed to wallet',
  expiry: 'Expired',
  admin_adjustment: 'Adjustment',
};

function ledgerRow(row) {
  const isCredit = row.direction === 'credit';

  return `
    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
      <div>
        <div class="small fw-semibold">${escapeHtml(SOURCE_LABEL[row.source] || row.source)}</div>
        <div class="small text-muted">${escapeHtml(row.narration)}</div>
        <div class="small text-muted">${escapeHtml(String(row.created_date || '').slice(0, 16).replace('T', ' '))}</div>
      </div>
      <div class="fw-semibold ${isCredit ? 'text-success' : 'text-danger'}">${isCredit ? '+' : '−'}${escapeHtml(row.points)}</div>
    </div>`;
}

async function renderDashboard() {
  root.innerHTML = `
    <div class="row justify-content-center"><div class="col-12 col-md-9 col-lg-7">
      <h1 class="h4 mb-3">Loyalty Points</h1>
      <div class="text-center py-5 text-muted" data-loading><div class="spinner-border"></div></div>
    </div></div>`;

  const host = root.querySelector('[data-loading]').parentElement;

  try {
    const [summaryResponse, statementResponse] = await Promise.all([
      api.get('/loyalty'),
      api.get('/loyalty/statement', { page: 1, per_page: 20 }),
    ]);

    const loyalty = summaryResponse.data.loyalty;
    const items = statementResponse.data || [];

    host.innerHTML = `
      <h1 class="h4 mb-3">Loyalty Points</h1>

      <div class="card mb-3">
        <div class="card-body text-center">
          <div class="small text-muted">Your balance</div>
          <div class="display-6 fw-semibold">${escapeHtml(loyalty.balance)}</div>
          <div class="small text-muted">points</div>
          <div class="small text-muted mt-2">
            Lifetime earned ${escapeHtml(loyalty.lifetime_earned)} · Lifetime redeemed ${escapeHtml(loyalty.lifetime_redeemed)}
          </div>
        </div>
      </div>

      ${loyalty.is_frozen ? `
        <div class="alert alert-warning small">Your loyalty account is temporarily on hold. Please contact support.</div>` : `
        <div class="card mb-4">
          <div class="card-header bg-white fw-semibold">Redeem for wallet credit</div>
          <div class="card-body">
            <p class="small text-muted mb-2">
              Each point is worth ₹${escapeHtml(loyalty.redeem_value_per_point)} of wallet credit.
              Redeem at least ${escapeHtml(loyalty.min_redeem_points)}${loyalty.max_redeem_points_per_order > 0 ? ` and up to ${escapeHtml(loyalty.max_redeem_points_per_order)}` : ''} points at a time.
            </p>
            <form class="d-flex gap-2" data-redeem-form>
              <input class="form-control" type="number" name="points" min="${escapeHtml(loyalty.min_redeem_points)}"
                     max="${loyalty.max_redeem_points_per_order > 0 ? escapeHtml(loyalty.max_redeem_points_per_order) : escapeHtml(loyalty.balance)}"
                     placeholder="Points to redeem" required ${loyalty.balance < loyalty.min_redeem_points ? 'disabled' : ''}>
              <button class="btn btn-spice text-nowrap" type="submit" ${loyalty.balance < loyalty.min_redeem_points ? 'disabled' : ''}>Redeem</button>
            </form>
            ${loyalty.balance < loyalty.min_redeem_points
              ? `<div class="small text-muted mt-2">You need at least ${escapeHtml(loyalty.min_redeem_points)} points to redeem.</div>` : ''}
          </div>
        </div>`}

      <div class="card">
        <div class="card-header bg-white fw-semibold">History</div>
        <div class="card-body">
          ${items.length === 0
            ? '<p class="small text-muted mb-0">No activity yet — points appear here as you shop.</p>'
            : items.map(ledgerRow).join('')}
        </div>
      </div>
    </div></div>`;

    const form = host.querySelector('[data-redeem-form]');

    if (form) {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        const points = Number(new FormData(form).get('points'));

        setBusy(button, true, 'Redeeming');

        try {
          const response = await api.post('/loyalty/redeem', { points });
          toast(`₹${response.data.rupees_credited} added to your wallet.`);
          renderDashboard();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    }
  } catch (error) {
    host.innerHTML = '';
    showError(error, host);
  }
}

async function start() {
  await bootstrapSession();

  if (!isSignedIn()) {
    root.innerHTML = `
      <div class="text-center py-5">
        <h1 class="h5">Sign in to see your loyalty points</h1>
        <a class="btn btn-spice mt-2" href="account.html?next=loyalty.html">Sign in</a>
      </div>`;
    return;
  }

  renderDashboard();
}

mountChrome('loyalty.html');
mountFooter();
start();
