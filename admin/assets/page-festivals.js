/**
 * Festival Calendar: every major Indian festival and jayanti through the
 * year, so staff can set up that occasion's gift page, add products, and let
 * it close itself once the festival has passed.
 *
 * AUTO-CLOSE NEEDS NO SCHEDULED JOB. A campaign page already stops appearing
 * on the shop (see CollectionRepository::findLiveBySlug()/listLive(), both
 * gated on `ends_date`) the moment `ends_date` is in the past — that check
 * already existed for every campaign page. This screen just sets `ends_date`
 * to "festival date + a few days" when a festival is set up, so that
 * existing gate is what closes it; nothing new runs on a timer, and nothing
 * deletes it, so "Reopen for next year" is just moving the date forward.
 *
 * Underneath, this manages the exact same "campaign page" (collection) that
 * Content -> Campaign pages does — a festival here and a campaign page there
 * are the same record, found by a shared slug. Deeper settings (subtitle,
 * intro, SEO) still live on that screen.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml,
         emptyState } from './console.js?v=9';
import { cycleGroups, addDays, DEFAULT_CLOSE_AFTER_DAYS } from './festival-calendar.js';

let root = null;
let products = [];
let bySlug = {};
let expanded = null;
let isAdmin = false;

const todayStr = () => new Date().toISOString().slice(0, 10);

function closeAfter(festival) {
  return festival.closeAfterDays || DEFAULT_CLOSE_AFTER_DAYS;
}

/** Is this collection's date window in the past — closed, whether or not `status` still says published? */
function isExpired(entry) {
  return Boolean(entry && entry.ends_date && String(entry.ends_date).slice(0, 10) < todayStr());
}

function statusBadge(entry) {
  if (!entry) return '<span class="badge text-bg-secondary">Not set up</span>';
  if (isExpired(entry)) return '<span class="badge text-bg-secondary">Closed for this year</span>';
  return entry.status === 'published'
    ? '<span class="badge text-bg-success">Published</span>'
    : '<span class="badge text-bg-warning">Draft</span>';
}

function confidenceNote(festival) {
  if (festival.confidence === 'confirmed') return '';
  if (festival.confidence === 'uncertain') {
    return '<div class="small text-danger">Date is a rough guess — confirm before relying on it.</div>';
  }
  return '<div class="small text-warning-emphasis">Estimated date — worth double-checking.</div>';
}

function festivalRow(festival) {
  const entry = bySlug[festival.slug];
  const isOpen = expanded === festival.slug;
  const closeDate = addDays(festival.cycleDate, closeAfter(festival));
  const cycleIsNextYear = Number(festival.cycleDate.slice(0, 4)) > new Date().getFullYear();

  return `
    <div class="card mb-2" data-festival="${escapeHtml(festival.slug)}">
      <div class="card-body py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <span class="fw-semibold">${escapeHtml(festival.title)}</span>
          ${statusBadge(entry)}
          <div class="small text-muted">${escapeHtml(festival.note)}</div>
          <div class="small text-muted">${cycleIsNextYear ? 'Next year' : 'Coming up'}: ${escapeHtml(festival.cycleDate)} · closes automatically ${escapeHtml(closeDate)}</div>
          ${confidenceNote(festival)}
          ${entry ? `<div class="small text-muted">${escapeHtml(entry.item_count)} item(s) on the page, ${escapeHtml(entry.purchasable_count)} on sale</div>` : ''}
        </div>
        <div class="d-flex gap-2">
          ${!isAdmin ? '' : entry
            ? `<button class="btn btn-sm ${isOpen ? 'btn-secondary' : 'btn-outline-secondary'}" data-toggle type="button">${isOpen ? 'Close' : 'Manage items'}</button>`
            : '<button class="btn btn-sm btn-dark" data-setup type="button">Set up this festival’s gifts</button>'}
        </div>
      </div>
      <div data-editor-host></div>
    </div>`;
}

/** The item-management panel for one festival — dates, a picture, products, publish. */
async function renderEditor(host, festival) {
  host.innerHTML = '<div class="card-body border-top text-center py-3 text-muted"><div class="spinner-border spinner-border-sm"></div></div>';

  try {
    const detail = (await api.get(`/admin/collections/${encodeURIComponent(festival.slug)}`)).data;
    const collection = detail.collection;
    const items = detail.items || [];
    const chosen = new Set(items.map((item) => item.slug));
    const expired = isExpired(collection);

    host.innerHTML = `
      <div class="card-body border-top">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <a class="small" target="_blank" rel="noopener" href="../collection.html?slug=${encodeURIComponent(festival.slug)}">Preview on the shop →</a>
          <div class="d-flex gap-2">
            <a class="small" href="content.html?tab=collections&open=${encodeURIComponent(festival.slug)}">Full page settings (subtitle, intro, SEO) →</a>
            ${collection.status === 'published'
              ? '<button class="btn btn-sm btn-outline-warning" data-status="draft" type="button">Unpublish</button>'
              : '<button class="btn btn-sm btn-success" data-status="published" type="button">Publish</button>'}
          </div>
        </div>

        ${expired ? `
          <div class="alert alert-secondary small d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>This closed automatically on ${escapeHtml(String(collection.ends_date).slice(0, 10))} —
              it no longer shows on the shop, but everything here is kept for next year.</span>
            <button class="btn btn-sm btn-dark" data-reopen type="button">Reopen for next year</button>
          </div>` : ''}

        <form class="row g-2 align-items-end mb-3" data-dates-form>
          <div class="col-auto">
            <label class="form-label small mb-0">Opens</label>
            <input class="form-control form-control-sm" type="date" name="starts_date"
                   value="${escapeHtml(String(collection.starts_date || festival.cycleDate || '').slice(0, 10))}">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-0">Closes automatically</label>
            <input class="form-control form-control-sm" type="date" name="ends_date"
                   value="${escapeHtml(String(collection.ends_date || addDays(festival.cycleDate, closeAfter(festival))).slice(0, 10))}">
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit">Save dates</button>
          </div>
          <div class="col-12 small text-muted">
            The page hides itself from the shop the day after "Closes automatically" — no one needs to
            remember to take it down.
          </div>
        </form>

        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
          ${collection.hero_image_url
            ? `<img src="${escapeHtml(collection.hero_image_url)}" alt="" style="width:8rem;height:5rem;object-fit:cover;border-radius:.5rem">`
            : '<div class="text-muted small">No picture yet.</div>'}
          <form class="d-flex flex-wrap gap-2 align-items-center" data-hero-form>
            <input class="form-control form-control-sm" type="file" name="image" accept="image/jpeg,image/png,image/webp" required style="width:auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit">${collection.hero_image_url ? 'Replace picture' : 'Upload picture'}</button>
          </form>
        </div>

        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <div class="fw-semibold small mb-1">On this page</div>
            ${items.length === 0
              ? emptyState('Nothing chosen yet', 'Add products with the form on the right.')
              : `<ul class="list-group list-group-flush">
                   ${items.map((item) => `
                     <li class="list-group-item d-flex justify-content-between align-items-center px-0"
                         data-item="${escapeHtml(item.item_uuid)}">
                       <span>
                         ${escapeHtml(item.name)}
                         ${item.headline ? `<div class="small" style="color:var(--gold-dark,#8A6A1F)">${escapeHtml(item.headline)}</div>` : ''}
                         ${item.is_purchasable ? '' : '<div class="small text-danger">Not on sale — hidden from shoppers</div>'}
                       </span>
                       <button class="btn btn-sm btn-outline-danger" data-remove-item type="button">Remove</button>
                     </li>`).join('')}
                 </ul>`}
          </div>
          <div class="col-12 col-lg-6">
            <div class="fw-semibold small mb-1">Add a product</div>
            <form class="row g-2" data-item-form>
              <div class="col-12">
                <select class="form-select form-select-sm" name="product" required>
                  <option value="">Choose…</option>
                  ${products.filter((p) => !chosen.has(p.slug)).map((p) => `
                    <option value="${escapeHtml(p.slug)}">${escapeHtml(p.name)}${p.status === 'published' ? '' : ' (not on sale)'}</option>`).join('')}
                </select>
              </div>
              <div class="col-12">
                <input class="form-control form-control-sm" name="headline" maxlength="120"
                       placeholder="Why it's here, e.g. “For the mithai box”">
              </div>
              <div class="col-12">
                <button class="btn btn-sm btn-dark" type="submit">Add to page</button>
              </div>
            </form>
          </div>
        </div>
      </div>`;

    host.querySelector('[data-dates-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type="submit"]');
      const data = new FormData(form);
      const starts = data.get('starts_date');
      const ends = data.get('ends_date');
      const payload = {};
      if (starts) payload.starts_date = `${starts} 00:00:00`;
      if (ends) payload.ends_date = `${ends} 23:59:59`;

      setBusy(button, true, 'Saving');

      try {
        await api.patch(`/admin/collections/${encodeURIComponent(festival.slug)}`, payload);
        toast('Dates saved.');
        await refreshSummary();
        renderEditor(host, festival);
        renderList();
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    const reopenBtn = host.querySelector('[data-reopen]');
    if (reopenBtn) {
      reopenBtn.addEventListener('click', async () => {
        const typed = window.prompt(
          `This year's date for ${festival.title}. Check a current calendar — festival dates ` +
          `shift every year and this is only a rough guess.`,
          festival.cycleDate
        );
        if (!typed) return;

        setBusy(reopenBtn, true, 'Reopening');

        try {
          await api.patch(`/admin/collections/${encodeURIComponent(festival.slug)}`, {
            starts_date: `${typed} 00:00:00`,
            ends_date: `${addDays(typed, closeAfter(festival))} 23:59:59`,
          });
          await api.post(`/admin/collections/${encodeURIComponent(festival.slug)}/status`, { status: 'published' });
          toast(`Reopened for ${typed}.`);
          await refreshSummary();
          renderEditor(host, festival);
          renderList();
        } catch (error) {
          setBusy(reopenBtn, false);
          showError(error);
        }
      });
    }

    host.querySelector('[data-hero-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button');
      const file = form.querySelector('[name="image"]').files[0];
      if (!file) { toast('Choose a picture first.', 'danger'); return; }

      const body = new FormData();
      body.append('image', file);
      setBusy(button, true, 'Uploading');

      try {
        await api.upload(`/admin/collections/${encodeURIComponent(festival.slug)}/image`, body);
        toast('Picture updated.');
        renderEditor(host, festival);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    host.querySelectorAll('[data-status]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Saving');

        try {
          await api.post(`/admin/collections/${encodeURIComponent(festival.slug)}/status`, { status: button.dataset.status });
          toast(button.dataset.status === 'published' ? 'This festival’s page is live.' : 'Unpublished.');
          await refreshSummary();
          renderList();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    host.querySelector('[data-item-form]').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button');
      const data = new FormData(form);
      const headline = (data.get('headline') || '').trim();
      const payload = { product: data.get('product'), display_order: (items.length + 1) * 10 };
      if (headline) payload.headline = headline;

      setBusy(button, true, 'Adding');

      try {
        await api.post(`/admin/collections/${encodeURIComponent(festival.slug)}/items`, payload);
        toast('Added to the page.');
        await refreshSummary();
        renderEditor(host, festival);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });

    host.querySelectorAll('[data-remove-item]').forEach((button) => {
      button.addEventListener('click', async () => {
        const itemUuid = button.closest('[data-item]').dataset.item;
        setBusy(button, true, '…');

        try {
          await api.delete(`/admin/collections/${encodeURIComponent(festival.slug)}/items/${encodeURIComponent(itemUuid)}`);
          toast('Removed.');
          await refreshSummary();
          renderEditor(host, festival);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    host.innerHTML = '<div class="card-body border-top"></div>';
    showError(error, host.querySelector('.card-body'));
  }
}

async function refreshSummary() {
  const collections = (await api.get('/admin/collections')).data.collections || [];
  bySlug = Object.fromEntries(collections.map((c) => [c.slug, c]));
}

function renderList() {
  root.innerHTML = `
    <h1 class="h4 mb-1">Festival Calendar</h1>
    <p class="text-muted small mb-3">
      Indian festivals and jayanthis through the year. Set up a gift page for one, add
      products, and it closes itself automatically a few days after the festival —
      ready to reopen with next year's date rather than starting over.
      Hindu and Islamic dates shift every year, so double-check anything not marked
      "confirmed" before relying on it.
    </p>
    ${!isAdmin ? '<div class="alert alert-warning small">Only an administrator can set up or change a festival’s gift page. You can still see what is already published.</div>' : ''}
    <div data-groups></div>`;

  const host = root.querySelector('[data-groups]');
  const groups = cycleGroups();
  // Flat, so "Set up" / "Manage" handlers can look a festival back up with its
  // cycleDate attached — the plain FESTIVALS list doesn't carry that.
  const annotated = Object.fromEntries(groups.flatMap(([, festivals]) => festivals).map((f) => [f.slug, f]));

  host.innerHTML = groups.map(([label, festivals]) => `
    <h2 class="h6 text-muted mt-3">${escapeHtml(label)}</h2>
    ${festivals.map(festivalRow).join('')}`).join('');

  host.querySelectorAll('[data-setup]').forEach((button) => {
    button.addEventListener('click', async () => {
      const slug = button.closest('[data-festival]').dataset.festival;
      const festival = annotated[slug];
      setBusy(button, true, 'Creating');

      try {
        await api.post('/admin/collections', {
          title: festival.title,
          slug: festival.slug,
          template: 'gift',
          display_order: (festival.month || 13) * 10,
          starts_date: `${festival.cycleDate} 00:00:00`,
          ends_date: `${addDays(festival.cycleDate, closeAfter(festival))} 23:59:59`,
        });
        toast(`${festival.title} gift page created — add products below.`);
        await refreshSummary();
        expanded = slug;
        renderList();
        const editorHost = root.querySelector(`[data-festival="${CSS.escape(slug)}"] [data-editor-host]`);
        editorHost?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        renderEditor(editorHost, festival);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  });

  host.querySelectorAll('[data-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const slug = button.closest('[data-festival]').dataset.festival;
      expanded = expanded === slug ? null : slug;
      renderList();

      if (expanded) {
        renderEditor(root.querySelector(`[data-festival="${CSS.escape(expanded)}"] [data-editor-host]`), annotated[expanded]);
      }
    });
  });
}

const mounted = await mountConsole('festivals.html');

if (mounted) {
  root = mounted.root;
  isAdmin = String(mounted.user.role) === 'administrator';
  root.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>';

  try {
    const [collectionsResponse, productsResponse] = await Promise.all([
      api.get('/admin/collections'),
      api.get('/admin/products', { per_page: 200 }),
    ]);

    bySlug = Object.fromEntries((collectionsResponse.data.collections || []).map((c) => [c.slug, c]));
    products = productsResponse.data || [];
    renderList();
  } catch (error) {
    showError(error, root);
  }
}
