/** Review moderation: approve, reject, hide, reply. */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, badge, emptyState } from './console.js?v=9';

let root = null;
let filter = '';
let tab = 'product';

function card(review) {
  const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);

  return `
    <div class="card mb-3" data-review="${escapeHtml(review.uuid)}">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <span class="fw-semibold">${escapeHtml(review.product_name || 'Product')}</span>
            ${badge(review.status, review.status)}
            ${review.is_verified_purchase
              ? '<span class="badge text-bg-success ms-1">Verified purchase</span>'
              : '<span class="badge text-bg-warning ms-1">Not a verified purchase</span>'}
            ${Number(review.report_count) > 0
              ? `<span class="badge text-bg-danger ms-1">${escapeHtml(review.report_count)} report(s)</span>`
              : ''}
          </div>
          <div class="text-warning flex-shrink-0">${stars}</div>
        </div>

        <div class="small text-muted mt-1">
          ${escapeHtml(review.author || 'Customer')} ·
          ${escapeHtml(String(review.created_date || '').slice(0, 10))}
        </div>

        ${review.title ? `<div class="fw-semibold mt-2">${escapeHtml(review.title)}</div>` : ''}
        ${review.body ? `<p class="mb-2 mt-1">${escapeHtml(review.body)}</p>` : '<p class="text-muted small mt-2 mb-2">Rating only, no text.</p>'}

        ${review.merchant_reply
          ? `<div class="bg-light border-start border-3 ps-3 py-2 small mb-2">
               <span class="fw-semibold">Our reply:</span> ${escapeHtml(review.merchant_reply)}
             </div>`
          : ''}

        <div class="d-flex flex-wrap gap-2 mt-2">
          ${review.status !== 'approved'
            ? '<button class="btn btn-sm btn-success" data-moderate="approved">Publish</button>' : ''}
          ${review.status !== 'rejected'
            ? '<button class="btn btn-sm btn-outline-danger" data-moderate="rejected">Reject</button>' : ''}
          ${review.status === 'approved'
            ? '<button class="btn btn-sm btn-outline-warning" data-moderate="hidden">Hide</button>' : ''}
          ${review.status === 'approved' && !review.merchant_reply
            ? '<button class="btn btn-sm btn-outline-secondary" data-reply>Reply publicly</button>' : ''}
        </div>
      </div>
    </div>`;
}

function tabSwitch() {
  return `<div class="btn-group btn-group-sm" role="group" aria-label="Review type">
    ${[['product', 'Product reviews'], ['store', 'Store reviews']].map(([value, label]) => `
      <button type="button" class="btn ${tab === value ? 'btn-primary' : 'btn-outline-primary'}" data-tab="${value}">${label}</button>`).join('')}
  </div>`;
}

function storeCard(review) {
  const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);

  return `
    <div class="card mb-3" data-store-review="${escapeHtml(review.uuid)}">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <span class="fw-semibold">${escapeHtml(review.reviewer_name)}</span>
            ${badge(review.status, review.status)}
            ${review.reviewer_mobile ? `<span class="small text-muted ms-2">${escapeHtml(review.reviewer_mobile)}</span>` : ''}
          </div>
          <div class="text-warning flex-shrink-0">${stars}</div>
        </div>
        <div class="small text-muted mt-1">${escapeHtml(String(review.created_date || '').slice(0, 16).replace('T', ' '))}</div>
        ${review.body ? `<p class="mb-2 mt-2">${escapeHtml(review.body)}</p>` : '<p class="text-muted small mt-2 mb-2">Rating only, no text.</p>'}
        ${review.merchant_reply
          ? `<div class="bg-light border-start border-3 ps-3 py-2 small mb-2"><span class="fw-semibold">Our reply:</span> ${escapeHtml(review.merchant_reply)}</div>`
          : ''}
        <div class="d-flex flex-wrap gap-2 mt-2">
          ${review.status !== 'approved' ? '<button class="btn btn-sm btn-success" data-store-moderate="approved">Publish</button>' : ''}
          ${review.status !== 'rejected' ? '<button class="btn btn-sm btn-outline-danger" data-store-moderate="rejected">Reject</button>' : ''}
          ${review.status === 'approved' ? '<button class="btn btn-sm btn-outline-warning" data-store-moderate="hidden">Hide</button>' : ''}
          ${review.status === 'approved' && !review.merchant_reply ? '<button class="btn btn-sm btn-outline-secondary" data-store-reply>Reply publicly</button>' : ''}
        </div>
      </div>
    </div>`;
}

/** Reviews of the shop itself, left from the storefront home page. */
async function renderStore() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Reviews</h1>
      ${tabSwitch()}
      <div class="btn-group btn-group-sm">
        ${[['', 'Needs a decision'], ['approved', 'Published'], ['rejected', 'Rejected'], ['hidden', 'Hidden']]
          .map(([value, label]) => `
            <button type="button" class="btn ${filter === value ? 'btn-dark' : 'btn-outline-secondary'}"
                    data-filter="${value}">${escapeHtml(label)}</button>`).join('')}
      </div>
    </div>
    <div data-action-feedback class="mb-3"></div>
    <div data-list><div class="text-center py-5 text-muted"><div class="spinner-border"></div></div></div>`;

  wireHeader();

  const list = root.querySelector('[data-list]');

  try {
    const reviews = (await api.get('/admin/store-reviews', { status: filter })).data || [];

    if (reviews.length === 0) {
      list.innerHTML = emptyState(filter ? 'Nothing with that status' : 'Nothing waiting',
        'Ratings left on the shop home page appear here.');
      return;
    }

    list.innerHTML = reviews.map(storeCard).join('');

    const send = async (button, uuid, payload, message) => {
      setBusy(button, true, 'Saving');

      try {
        await api.post(`/admin/store-reviews/${encodeURIComponent(uuid)}/moderate`, payload);
        toast(message);
        renderStore();
      } catch (error) {
        setBusy(button, false);
        showError(error, root.querySelector('[data-action-feedback]'));
      }
    };

    list.querySelectorAll('[data-store-moderate]').forEach((button) => {
      button.addEventListener('click', () => {
        const uuid = button.closest('[data-store-review]').dataset.storeReview;
        const decision = button.dataset.storeModerate;
        const note = decision === 'approved' ? null : (window.prompt('Why? (internal note, optional)') || null);
        send(button, uuid, { decision, note }, decision === 'approved' ? 'Review published.' : `Review ${decision}.`);
      });
    });

    list.querySelectorAll('[data-store-reply]').forEach((button) => {
      button.addEventListener('click', () => {
        const uuid = button.closest('[data-store-review]').dataset.storeReview;
        const reply = window.prompt('Your reply. This is shown publicly under the review.');
        if (reply) send(button, uuid, { decision: 'approved', reply }, 'Reply published.');
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function wireHeader() {
  root.querySelectorAll('[data-filter]').forEach((button) => {
    button.addEventListener('click', () => { filter = button.dataset.filter; render(); });
  });

  root.querySelectorAll('[data-tab]').forEach((button) => {
    button.addEventListener('click', () => { tab = button.dataset.tab; filter = ''; render(); });
  });
}

async function render() {
  if (tab === 'store') { renderStore(); return; }

  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Reviews</h1>
      ${tabSwitch()}
      <div class="btn-group btn-group-sm">
        ${[['', 'Needs a decision'], ['approved', 'Published'], ['rejected', 'Rejected'], ['hidden', 'Hidden']]
          .map(([value, label]) => `
            <button type="button" class="btn ${filter === value ? 'btn-dark' : 'btn-outline-secondary'}"
                    data-filter="${value}">${escapeHtml(label)}</button>`).join('')}
      </div>
    </div>
    <div data-action-feedback class="mb-3"></div>
    <div data-list><div class="text-center py-5 text-muted"><div class="spinner-border"></div></div></div>`;

  wireHeader();

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/reviews', { status: filter, per_page: 50 });
    const reviews = response.data || [];

    if (reviews.length === 0) {
      list.innerHTML = emptyState(
        filter ? 'Nothing with that status' : 'Nothing waiting',
        filter ? 'Try another filter.' : 'No reviews are awaiting moderation or have been reported.'
      );
      return;
    }

    list.innerHTML = reviews.map(card).join('');

    list.querySelectorAll('[data-moderate]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-review]').dataset.review;
        const decision = button.dataset.moderate;

        // A rejection is explained. The note is internal, but "why did we reject
        // this" is asked far more often than anyone expects.
        let note = null;

        if (decision !== 'approved') {
          note = window.prompt('Why? (internal note, optional)') || null;
        }

        setBusy(button, true, 'Saving');

        try {
          await api.post(`/admin/reviews/${encodeURIComponent(uuid)}/moderate`, { decision, note });
          toast(decision === 'approved' ? 'Review published.' : `Review ${decision}.`);
          render();
        } catch (error) {
          setBusy(button, false);
          showError(error, root.querySelector('[data-action-feedback]'));
        }
      });
    });

    list.querySelectorAll('[data-reply]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-review]').dataset.review;
        const body = window.prompt('Your reply. This is shown publicly under the review.');
        if (!body) return;

        setBusy(button, true, 'Posting');

        try {
          await api.post(`/admin/reviews/${encodeURIComponent(uuid)}/reply`, { body });
          toast('Reply published.');
          render();
        } catch (error) {
          setBusy(button, false);
          showError(error, root.querySelector('[data-action-feedback]'));
        }
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

const mounted = await mountConsole('reviews.html');
if (mounted) { root = mounted.root; render(); }
