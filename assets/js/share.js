/**
 * Sharing a product.
 *
 * WhatsApp first, because in India that is how a recommendation actually
 * travels. The native share sheet is used when the browser has one — on a phone
 * that gives WhatsApp, and everything else, without this code choosing for the
 * person.
 *
 * No SDK, no tracking pixel, no third-party script. A share is a link.
 */

import { escapeHtml } from './api.js';

function shareUrl(slug) {
  return `${window.location.origin}${window.location.pathname.replace(/[^/]*$/, '')}`
    + `product.html?slug=${encodeURIComponent(slug)}`;
}

export function shareButton(product) {
  return `
    <button class="btn btn-quiet btn-sm" type="button"
            data-share="${escapeHtml(product.slug)}"
            data-share-name="${escapeHtml(product.name)}">
      Share
    </button>`;
}

export function bindShare(root, priceText = '') {
  root.querySelectorAll('[data-share]').forEach((button) => {
    button.addEventListener('click', async () => {
      const name = button.dataset.shareName;
      const url = shareUrl(button.dataset.share);
      const text = priceText
        ? `${name} — ${priceText}`
        : name;

      // The native sheet is better than anything this code could offer: it
      // knows which apps the person actually has.
      if (navigator.share) {
        try {
          await navigator.share({ title: name, text, url });
          return;
        } catch {
          // Cancelled, or refused. Fall through to WhatsApp.
        }
      }

      window.open(
        `https://wa.me/?text=${encodeURIComponent(`${text}\n${url}`)}`,
        '_blank',
        'noopener'
      );
    });
  });
}
