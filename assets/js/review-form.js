/**
 * Writing a review.
 *
 * THIS WAS MISSING ENTIRELY. The moderation queue existed in the console and the
 * API accepted submissions, but the shop offered no way to make one — so the
 * whole review system was write-only from the merchant's side.
 *
 * ONLY VERIFIED BUYERS MAY REVIEW. The server enforces it: a review requires a
 * delivered order containing the product. That rule is why the form explains
 * eligibility rather than showing a box that fails on submit — being told "you
 * cannot" after writing three paragraphs is worse than not being offered the box.
 */

import { api, ApiError, isSignedIn } from './api.js';
import { escapeHtml, showError, toast, setBusy } from './ui.js';

/**
 * Whether this customer may review this product.
 *
 * Asked of the server rather than guessed, because the rule is the server's.
 */
async function eligibility(slug) {
  if (!isSignedIn()) return { canReview: false, reason: 'signed-out' };

  try {
    // Staff get told plainly that the rule applies to them too. A shop owner
    // who sees "only customers who bought this can review it" on their own site
    // reasonably concludes the feature is broken — when in fact it is working
    // exactly as intended and refusing them on purpose.
    const me = await api.get('/auth/me');
    const role = String((me.data.user || {}).role || '');

    if (['administrator', 'supervisor', 'executive'].includes(role)) {
      return { canReview: false, reason: 'staff' };
    }
  } catch {
    // Not fatal; fall through to the ordinary checks.
  }

  try {
    const [awaiting, mine] = await Promise.all([
      api.get('/reviews/awaiting'),
      api.get('/reviews/mine'),
    ]);

    const already = (mine.data.reviews || []).find((review) => review.product_slug === slug);

    if (already) return { canReview: false, reason: 'already', review: already };

    const pending = (awaiting.data.products || []).find((product) => product.slug === slug);

    return pending
      ? { canReview: true }
      : { canReview: false, reason: 'not-purchased' };
  } catch {
    // If eligibility cannot be determined, offer nothing rather than a form that
    // will be refused.
    return { canReview: false, reason: 'unknown' };
  }
}

function stars() {
  // Radio buttons, not a bespoke star widget: they work with a keyboard, with a
  // screen reader, and without JavaScript deciding what "hover" means on touch.
  return `
    <fieldset class="mb-3">
      <legend class="form-label mb-1">Your rating <span class="text-danger">*</span></legend>
      <div class="d-flex gap-3" role="radiogroup">
        ${[1, 2, 3, 4, 5].map((value) => `
          <label class="d-inline-flex align-items-center gap-1" style="cursor:pointer">
            <input class="form-check-input m-0" type="radio" name="rating" value="${value}" required>
            <span>${value}</span>
          </label>`).join('')}
      </div>
      <div class="form-text">1 is poor, 5 is excellent.</div>
    </fieldset>`;
}

function formMarkup() {
  return `
    <div class="panel p-3 p-md-4 mt-3" data-review-form-panel>
      <h3 class="h6 mb-1">Write a review</h3>
      <p class="text-muted small">
        You bought this, so your review will be marked as a verified purchase.
      </p>

      <form data-review-form>
        ${stars()}

        <div class="mb-3">
          <label class="form-label" for="review_title">Headline</label>
          <input class="form-control" id="review_title" name="title" maxlength="150"
                 placeholder="Fresh, and ground properly">
        </div>

        <div class="mb-3">
          <label class="form-label" for="review_body">Your review</label>
          <textarea class="form-control" id="review_body" name="body" rows="4" maxlength="4000"
                    placeholder="How was the quality, the aroma, the packing?"></textarea>
          <div class="form-text">
            Reviews are read before publishing, so it may be a day before yours appears.
          </div>
        </div>

        <button class="btn btn-spice" type="submit">Submit review</button>
      </form>
    </div>`;
}

function noticeMarkup(state, slug) {
  switch (state.reason) {
    case 'signed-out':
      return `
        <div class="panel p-3 mt-3 small">
          <b>Bought this?</b>
          <a href="account.html?next=${encodeURIComponent(`product.html?slug=${slug}`)}">Sign in</a>
          to leave a review.
        </div>`;

    case 'already':
      return `
        <div class="panel p-3 mt-3 small">
          You have already reviewed this product${state.review && state.review.status === 'pending'
            ? ' — it is waiting to be published.' : '.'}
        </div>`;

    case 'staff':
      return `
        <div class="panel p-3 mt-3 small">
          <b>You are signed in as staff, so you cannot review this.</b>
          Only a customer with a delivered order containing this product can — that
          rule is what makes the ratings here worth reading, and it applies to the
          shop's own accounts too.
          <div class="text-muted mt-2">
            To see the review form, sign in as a customer whose order has been
            marked delivered.
          </div>
        </div>`;

    case 'not-purchased':
      // Stated plainly, and with what would change it. A customer who cannot see
      // why the box is missing assumes the site is broken.
      return `
        <div class="panel p-3 mt-3 small">
          <b>Only customers who have received this can review it.</b>
          <div class="text-muted mt-1">
            Order it, and once it is delivered the review box appears here.
          </div>
        </div>`;

    default:
      return '';
  }
}

/**
 * Renders whatever is appropriate into the given container.
 *
 * @param {string} slug     the product being reviewed
 * @param {Element} host    where to put the form or the notice
 * @param {Function} onDone called after a successful submission
 */
export async function mountReviewForm(slug, host, onDone) {
  if (!host) return;

  const state = await eligibility(slug);

  if (!state.canReview) {
    host.innerHTML = noticeMarkup(state, slug);
    return;
  }

  host.innerHTML = formMarkup();

  host.querySelector('[data-review-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    const data = new FormData(form);
    const rating = data.get('rating');

    if (!rating) {
      toast('Choose a rating first.', 'danger');
      return;
    }

    const payload = { rating: Number(rating) };
    const title = String(data.get('title') || '').trim();
    const body = String(data.get('body') || '').trim();

    if (title) payload.title = title;
    if (body) payload.body = body;

    setBusy(button, true, 'Sending');

    try {
      await api.post(`/products/${encodeURIComponent(slug)}/reviews`, payload);

      host.innerHTML = `
        <div class="panel p-3 p-md-4 mt-3 text-center">
          <p class="fw-semibold mb-1">Thank you</p>
          <p class="text-muted small mb-0">
            Your review has been sent. It appears once it has been read, usually
            within a day.
          </p>
        </div>`;

      if (typeof onDone === 'function') onDone();
    } catch (error) {
      setBusy(button, false);

      // The server may refuse for a reason worth showing exactly — already
      // reviewed, or the order is not delivered yet.
      if (error instanceof ApiError && error.status === 422) {
        showError(error, host.querySelector('[data-review-form-panel]'));
        return;
      }

      showError(error);
    }
  });
}

/**
 * A prompt listing everything the customer could review but has not.
 *
 * Used on the orders page: the moment someone is looking at a delivered order is
 * the moment they are most likely to say something about it.
 */
export async function mountReviewPrompt(host) {
  if (!host || !isSignedIn()) return;

  try {
    const response = await api.get('/reviews/awaiting');
    const products = response.data.products || [];

    if (products.length === 0) return;

    host.innerHTML = `
      <div class="panel p-3 mb-4">
        <div class="fw-semibold mb-1">How were these?</div>
        <p class="text-muted small mb-2">
          You have received ${escapeHtml(products.length)} product(s) you have not
          reviewed. A sentence helps the next person choose.
        </p>
        <div class="d-flex flex-wrap gap-2">
          ${products.map((product) => `
            <a class="btn btn-quiet btn-sm"
               href="product.html?slug=${encodeURIComponent(product.slug)}#review">
              Review ${escapeHtml(product.name)}
            </a>`).join('')}
        </div>
      </div>`;
  } catch {
    // A prompt is a nicety; never let it break the orders page.
  }
}
