/**
 * A CMS page: shipping, returns, privacy, terms.
 *
 * The body is escaped rather than rendered as HTML. These pages are written by
 * staff through the admin API, but "our own staff wrote it" is how stored XSS
 * arrives — through a compromised admin account, or a paste from elsewhere.
 * Plain text with paragraph breaks preserved is enough for a policy page, and
 * the trade is worth it.
 */

import { api, ApiError } from './api.js';
import { mountChrome, mountFooter, escapeHtml, showError, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');
const slug = queryParam('slug');

function paragraphs(body) {
  return String(body || '')
    .split(/\n{2,}/)
    .map((block) => `<p>${escapeHtml(block.trim()).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

async function load() {
  if (!slug) {
    root.innerHTML = '<div class="alert alert-warning">No page was specified.</div>';
    return;
  }

  try {
    const response = await api.get(`/content/pages/${encodeURIComponent(slug)}`);
    const page = response.data.page;

    document.title = `${page.title} · Spice & Dry Fruits`;

    root.innerHTML = `
      <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
          <h1 class="h3 text-spice mb-3">${escapeHtml(page.title)}</h1>
          ${page.updated_date
            ? `<p class="text-muted small">Last updated ${escapeHtml(String(page.updated_date).slice(0, 10))}</p>`
            : ''}
          <div class="mt-3">${paragraphs(page.body)}</div>
        </div>
      </div>`;
  } catch (error) {
    root.innerHTML = '';

    if (error instanceof ApiError && error.status === 404) {
      root.innerHTML = `
        <div class="alert alert-warning">
          That page does not exist. <a href="index.html">Back to the shop</a>.
        </div>`;
      return;
    }

    showError(error, root);
  }
}

mountChrome('index.html');
mountFooter();
load();
