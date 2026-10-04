/** Frequently asked questions, grouped and searchable. */

import { api } from './api.js';
import { mountChrome, mountFooter, escapeHtml, showError, toast } from './ui.js';

const root = document.querySelector('[data-page-root]');

function accordion(group, index) {
  return `
    <section class="mb-4">
      <h2 class="h6 text-spice">${escapeHtml(group.label)}</h2>
      <div class="accordion" id="faq-${index}">
        ${group.entries.map((entry, position) => {
          const id = `faq-${index}-${position}`;
          return `
            <div class="accordion-item">
              <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#${id}"
                        aria-expanded="false" aria-controls="${id}">
                  ${escapeHtml(entry.question)}
                </button>
              </h3>
              <div id="${id}" class="accordion-collapse collapse" data-bs-parent="#faq-${index}">
                <div class="accordion-body">
                  <p>${escapeHtml(entry.answer)}</p>
                  <button class="btn btn-outline-secondary btn-sm"
                          data-helpful="${escapeHtml(entry.uuid)}" type="button">
                    This helped
                  </button>
                </div>
              </div>
            </div>`;
        }).join('')}
      </div>
    </section>`;
}

async function load(search = '') {
  try {
    const response = await api.get('/content/faq', { q: search });
    const groups = response.data.groups || [];

    root.innerHTML = `
      <h1 class="h4 mb-3">Frequently asked questions</h1>

      <form class="d-flex gap-2 mb-4" data-faq-search>
        <label class="visually-hidden" for="faq-q">Search the FAQ</label>
        <input class="form-control" id="faq-q" name="q" type="search"
               value="${escapeHtml(search)}" placeholder="Search…">
        <button class="btn btn-spice" type="submit">Search</button>
      </form>

      ${groups.length
        ? groups.map(accordion).join('')
        : `<div class="alert alert-light border">
             Nothing matched that. <a href="support.html">Ask us directly</a> and we will help.
           </div>`}

      <div class="text-center mt-4">
        <p class="text-muted small mb-1">Still stuck?</p>
        <a class="btn btn-outline-secondary" href="support.html">Raise a support ticket</a>
      </div>`;

    root.querySelector('[data-faq-search]').addEventListener('submit', (event) => {
      event.preventDefault();
      load(new FormData(event.currentTarget).get('q') || '');
    });

    root.querySelectorAll('[data-helpful]').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          await api.post(`/content/faq/${button.dataset.helpful}/helpful`, {});
          button.textContent = 'Thank you';
        } catch {
          // A vote failing is not worth interrupting anyone over.
          button.disabled = false;
        }
      });
    });
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

mountChrome('support.html');
mountFooter();
load();
