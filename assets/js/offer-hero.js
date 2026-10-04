/**
 * The homepage headline: live promotional offers (from the Promotions/Offers
 * system, GET /offers), shown one at a time in a lightweight auto-advancing
 * carousel — the "main headline" a customer sees before anything else.
 *
 * Distinct from banners.js, which renders manually curated marketing images.
 * This pulls straight from the Offers system, so an offer staff activates in
 * Promotions shows up here immediately, with nothing separate to create.
 *
 * Same advert rules as banners.js: loaded after the products, never delays
 * the shop; a slot with nothing live in it renders nothing at all; a failed
 * request is silent.
 */

import { api, escapeHtml } from './api.js';

const ROTATE_MS = 5000;

function destination(offer) {
  return `index.html?offer=${encodeURIComponent(offer.code)}`;
}

function slide(offer, index) {
  const background = offer.banner_image_url
    ? ` style="background-image:linear-gradient(0deg, rgba(11,59,46,.82), rgba(11,59,46,.6)), url('${escapeHtml(offer.banner_image_url)}')"`
    : '';

  const summary = offer.discount && offer.discount.summary
    ? `<div class="offer-hero-sub fw-semibold mt-1">${escapeHtml(offer.discount.summary)}</div>`
    : '';

  return `
    <a class="offer-hero-slide${index === 0 ? ' is-active' : ''}" data-offer-slide="${index}"
       href="${escapeHtml(destination(offer))}"${background}>
      <div>
        <div class="offer-hero-eyebrow">Limited-time offer</div>
        <div class="offer-hero-title">${escapeHtml(offer.title)}</div>
        ${offer.subtitle ? `<div class="offer-hero-sub">${escapeHtml(offer.subtitle)}</div>` : ''}
        ${summary}
      </div>
      <span class="btn btn-quiet btn-sm offer-hero-cta flex-shrink-0">Shop now</span>
    </a>`;
}

/** @param {string} selector where to put the carousel */
export async function mountOfferHero(selector) {
  const host = document.querySelector(selector);
  if (!host) return;

  let offers = [];

  try {
    const response = await api.get('/offers');
    offers = response.data.offers || response.data || [];
  } catch {
    // Silent by design — see banners.js for why.
    return;
  }

  if (offers.length === 0) return;

  host.innerHTML = `
    <div class="offer-hero">
      ${offers.map(slide).join('')}
      ${offers.length > 1 ? `
        <div class="offer-hero-dots">
          ${offers.map((_, i) => `
            <button type="button" class="offer-hero-dot${i === 0 ? ' is-active' : ''}"
                    data-offer-dot="${i}" aria-label="Show offer ${i + 1} of ${offers.length}"></button>`).join('')}
        </div>` : ''}
    </div>`;

  if (offers.length <= 1) return;

  const slides = host.querySelectorAll('[data-offer-slide]');
  const dots = host.querySelectorAll('[data-offer-dot]');
  let active = 0;

  const show = (index) => {
    active = (index + offers.length) % offers.length;
    slides.forEach((el, i) => el.classList.toggle('is-active', i === active));
    dots.forEach((el, i) => el.classList.toggle('is-active', i === active));
  };

  let timer = setInterval(() => show(active + 1), ROTATE_MS);

  // Auto-rotates on its own until a shopper deliberately picks one — a dot
  // tap is a real choice ("show me that one"), so it stops the rotation for
  // good rather than resuming a few seconds later and undoing it. Hovering
  // is not a choice (and means nothing on a touch screen anyway), so it no
  // longer pauses anything.
  dots.forEach((dot) => {
    dot.addEventListener('click', (event) => {
      event.preventDefault();
      clearInterval(timer);
      show(Number(dot.dataset.offerDot));
    });
  });
}
