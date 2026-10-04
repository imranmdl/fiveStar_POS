/**
 * Home page: "rate us" (5 stars) and "get help" (contact form, FAQ, track order).
 *
 * Both write to the shop's own records so staff see them in the admin console:
 * a rating goes to Reviews → Store reviews (held until approved), a message
 * becomes a Support ticket. Neither needs an account.
 *
 * A failure here must never get in the way of the shop, so the section renders
 * its static parts first and only the live review list is fetched.
 */

import { api } from './api.js';
import { escapeHtml, showError, toast, setBusy } from './ui.js';

const host = document.querySelector('[data-home-feedback]');

const CATEGORIES = [
  ['order', 'My order'],
  ['delivery', 'Delivery'],
  ['payment', 'Payment'],
  ['product', 'A product'],
  ['other', 'Something else'],
];

function starsText(rating) {
  return '★'.repeat(rating) + '☆'.repeat(5 - rating);
}

function reviewList(data) {
  if (!data.count) {
    return '<p class="text-muted small mb-0">No reviews yet — be the first to rate us.</p>';
  }

  return `
    <div class="d-flex align-items-baseline gap-2 mb-2">
      <span class="fs-3 fw-semibold">${escapeHtml(data.average.toFixed(1))}</span>
      <span class="text-warning">${starsText(Math.round(data.average))}</span>
      <span class="text-muted small">${escapeHtml(data.count)} review${data.count === 1 ? '' : 's'}</span>
    </div>
    ${data.reviews.map((r) => `
      <div class="border-top py-2">
        <div class="small"><span class="text-warning">${starsText(r.rating)}</span>
          <span class="fw-semibold ms-1">${escapeHtml(r.name)}</span></div>
        ${r.body ? `<div class="small">${escapeHtml(r.body)}</div>` : ''}
        ${r.reply ? `<div class="small text-muted border-start ps-2 mt-1">Our reply: ${escapeHtml(r.reply)}</div>` : ''}
      </div>`).join('')}`;
}

function render() {
  host.innerHTML = `
    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="panel p-3 h-100" id="rate-us">
          <h2 class="h5 mb-1">Rate your experience</h2>
          <p class="text-muted small">Tell us how we did — it helps other shoppers too.</p>

          <form data-review-form>
            <div class="mb-2" role="radiogroup" aria-label="Your rating">
              <span data-stars class="fs-3 text-warning" style="cursor:pointer;letter-spacing:.1em">
                ${[1, 2, 3, 4, 5].map((n) => `<span data-star="${n}" role="radio" aria-checked="false" aria-label="${n} star${n > 1 ? 's' : ''}" tabindex="0">☆</span>`).join('')}
              </span>
              <input type="hidden" name="rating" value="">
              <span class="small text-muted ms-2" data-rating-label>Choose 1 to 5 stars</span>
            </div>
            <div class="row g-2">
              <div class="col-sm-6"><input class="form-control" name="name" placeholder="Your name" required minlength="2" maxlength="120"></div>
              <div class="col-sm-6"><input class="form-control" name="mobile" placeholder="Mobile (optional, not shown)" inputmode="numeric" maxlength="10"></div>
              <div class="col-12"><textarea class="form-control" name="body" rows="3" maxlength="1000" placeholder="Your review (optional)"></textarea></div>
            </div>
            <button class="btn btn-spice mt-2" type="submit">Submit review</button>
          </form>

          <hr>
          <div data-review-list><div class="text-muted small">Loading reviews…</div></div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="panel p-3 h-100" id="help">
          <h2 class="h5 mb-1">Need help?</h2>
          <p class="text-muted small">Send us a message and our team will get back to you.</p>

          <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-quiet btn-sm" href="faq.html">Help &amp; FAQ</a>
            <a class="btn btn-quiet btn-sm" href="orders.html">Track my order</a>
            <a class="btn btn-quiet btn-sm" href="support.html">My support tickets</a>
            <a class="btn btn-quiet btn-sm" href="gifting.html">Bulk &amp; gifting</a>
          </div>

          <form data-support-form>
            <div class="row g-2">
              <div class="col-sm-6"><input class="form-control" name="contact_name" placeholder="Your name" required minlength="2" maxlength="120"></div>
              <div class="col-sm-6"><input class="form-control" name="contact_mobile" placeholder="Mobile number" required inputmode="numeric" maxlength="10"></div>
              <div class="col-sm-6"><input class="form-control" name="contact_email" type="email" placeholder="Email (optional)"></div>
              <div class="col-sm-6">
                <select class="form-select" name="category" aria-label="What is it about?">
                  ${CATEGORIES.map(([value, label]) => `<option value="${value}">${escapeHtml(label)}</option>`).join('')}
                </select>
              </div>
              <div class="col-12"><textarea class="form-control" name="message" rows="3" required minlength="10" maxlength="5000" placeholder="How can we help?"></textarea></div>
            </div>
            <button class="btn btn-spice mt-2" type="submit">Send message</button>
          </form>
        </div>
      </div>
    </div>`;

  wireStars();
  wireReviewForm();
  wireSupportForm();
  loadReviews();
}

function wireStars() {
  const stars = [...host.querySelectorAll('[data-star]')];
  const input = host.querySelector('[name="rating"]');
  const label = host.querySelector('[data-rating-label]');
  const words = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

  const paint = (value) => {
    stars.forEach((star) => {
      const on = Number(star.dataset.star) <= value;
      star.textContent = on ? '★' : '☆';
      star.setAttribute('aria-checked', Number(star.dataset.star) === Number(input.value) ? 'true' : 'false');
    });
  };

  const choose = (value) => {
    input.value = String(value);
    label.textContent = `${value} — ${words[value]}`;
    paint(value);
  };

  stars.forEach((star) => {
    const value = Number(star.dataset.star);
    star.addEventListener('mouseenter', () => paint(value));
    star.addEventListener('click', () => choose(value));
    star.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(value); }
    });
  });

  host.querySelector('[data-stars]').addEventListener('mouseleave', () => paint(Number(input.value) || 0));
}

function wireReviewForm() {
  const form = host.querySelector('[data-review-form]');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!form.rating.value) {
      toast('Please choose a star rating first.', 'danger');
      return;
    }

    const button = form.querySelector('[type="submit"]');
    setBusy(button, true, 'Sending');

    try {
      const body = Object.fromEntries(new FormData(form));
      if (!body.mobile) delete body.mobile;
      const response = await api.post('/store-reviews', body);
      form.innerHTML = `<div class="alert alert-success mb-0">${escapeHtml(response.message || 'Thank you for your review!')}</div>`;
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

function wireSupportForm() {
  const form = host.querySelector('[data-support-form]');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    setBusy(button, true, 'Sending');

    try {
      const data = Object.fromEntries(new FormData(form));
      const topic = (CATEGORIES.find(([value]) => value === data.category) || CATEGORIES[4])[1];

      if (!data.contact_email) delete data.contact_email;

      await api.post('/support/tickets', {
        ...data,
        subject: `Website message — ${topic}`,
      });

      form.innerHTML = '<div class="alert alert-success mb-0">Thanks — we have your message and will get back to you soon.</div>';
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

async function loadReviews() {
  const box = host.querySelector('[data-review-list]');

  try {
    box.innerHTML = reviewList((await api.get('/store-reviews')).data);
  } catch {
    box.innerHTML = '';
  }
}

if (host) render();
