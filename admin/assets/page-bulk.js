/**
 * Wholesale and gift enquiries.
 *
 * Corporate gifting is the reason this exists: a company ordering two hundred
 * Diwali boxes does not use a shopping cart, they ask for a price. The flow is
 * enquiry → quotation → accepted → an ordinary order.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         badge, emptyState, queryParam } from './console.js?v=9';

let root = null;
let filter = '';

async function renderList() {
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h4 mb-0">Wholesale &amp; gifting</h1>
      <div class="btn-group btn-group-sm">
        ${[['', 'All'], ['new', 'New'], ['quoted', 'Quoted'], ['converted', 'Became orders']]
          .map(([value, label]) => `
            <button type="button" class="btn ${filter === value ? 'btn-dark' : 'btn-outline-secondary'}"
                    data-filter="${value}">${escapeHtml(label)}</button>`).join('')}
      </div>
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  root.querySelectorAll('[data-filter]').forEach((button) => {
    button.addEventListener('click', () => { filter = button.dataset.filter; renderList(); });
  });

  const list = root.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/bulk-orders', { status: filter, per_page: 50 });
    const enquiries = response.data || [];

    if (enquiries.length === 0) {
      list.innerHTML = emptyState('No enquiries',
        'Businesses can send one from the shop without creating an account.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Business</th><th>Wants</th><th>Contact</th>
            <th>Status</th><th class="text-end">Budget</th><th></th></tr></thead>
          <tbody>
            ${enquiries.map((enquiry) => `
              <tr>
                <td>
                  <span class="fw-semibold">${escapeHtml(enquiry.business_name)}</span>
                  <div class="small text-muted">${escapeHtml(enquiry.enquiry_number)}</div>
                </td>
                <td class="small" style="max-width:22rem">
                  ${escapeHtml(String(enquiry.requirements || '').slice(0, 110))}
                </td>
                <td class="small">
                  ${escapeHtml(enquiry.contact_name)}
                  <div class="text-muted">${escapeHtml(enquiry.contact_mobile)}</div>
                </td>
                <td>${badge(enquiry.status === 'converted' ? 'delivered' : 'open',
                  String(enquiry.status).replace(/_/g, ' '))}</td>
                <td class="text-end small">
                  ${enquiry.estimated_budget ? formatMoney(enquiry.estimated_budget) : '—'}
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary"
                     href="bulk.html?uuid=${encodeURIComponent(enquiry.uuid)}">Open</a>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

async function renderDetail(uuid) {
  try {
    const response = await api.get(`/admin/bulk-orders/${encodeURIComponent(uuid)}`);
    const enquiry = response.data.enquiry;
    const quotes = response.data.quotes || [];

    root.innerHTML = `
      <a class="small text-decoration-none" href="bulk.html">← All enquiries</a>
      <h1 class="h4 mt-2 mb-1">${escapeHtml(enquiry.business_name)}</h1>
      <p class="text-muted small">
        ${escapeHtml(enquiry.enquiry_number)} ·
        ${escapeHtml(enquiry.contact_name)} · ${escapeHtml(enquiry.contact_mobile)}
        ${enquiry.gstin ? ` · GSTIN ${escapeHtml(enquiry.gstin)}` : ''}
      </p>

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">What they asked for</div>
            <div class="card-body">
              <p class="mb-2">${escapeHtml(enquiry.requirements)}</p>
              <dl class="row small mb-0">
                ${enquiry.estimated_quantity ? `<dt class="col-4">Quantity</dt>
                  <dd class="col-8">${escapeHtml(enquiry.estimated_quantity)}</dd>` : ''}
                ${enquiry.estimated_budget ? `<dt class="col-4">Budget</dt>
                  <dd class="col-8">${formatMoney(enquiry.estimated_budget)}</dd>` : ''}
                ${enquiry.expected_delivery_date ? `<dt class="col-4">Needed by</dt>
                  <dd class="col-8">${escapeHtml(enquiry.expected_delivery_date)}</dd>` : ''}
                ${enquiry.delivery_pincode ? `<dt class="col-4">Delivering to</dt>
                  <dd class="col-8">${escapeHtml(enquiry.delivery_pincode)}</dd>` : ''}
              </dl>
            </div>
          </div>

          <div class="card">
            <div class="card-header bg-white fw-semibold">Quotations</div>
            ${quotes.length === 0
              ? `<div class="card-body">${emptyState('No quotation yet',
                  'Prepare one through the API — the console form is not built.')}</div>`
              : `<ul class="list-group list-group-flush">
                   ${quotes.map((quote) => `
                     <li class="list-group-item">
                       <div class="d-flex justify-content-between align-items-start">
                         <div>
                           <span class="fw-semibold">${escapeHtml(quote.quote_number)}</span>
                           <span class="small text-muted ms-2">revision ${escapeHtml(quote.revision)}</span>
                           ${badge(quote.status === 'accepted' ? 'delivered' : 'open', quote.status)}
                         </div>
                         <span class="fw-semibold">${formatMoney(quote.grand_total)}</span>
                       </div>
                       <div class="small text-muted mt-1">
                         ${escapeHtml((quote.items || []).length)} line(s) ·
                         valid until ${escapeHtml(quote.valid_until)}
                         ${quote.is_expired ? ' · <span class="text-danger">expired</span>' : ''}
                       </div>
                       ${quote.status === 'draft' ? `
                         <button class="btn btn-sm btn-dark mt-2" data-send="${escapeHtml(quote.uuid)}"
                                 type="button">Send to the customer</button>` : ''}
                     </li>`).join('')}
                 </ul>`}
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card">
            <div class="card-body">
              <h2 class="h6">Decline</h2>
              <form data-decline-form>
                <textarea class="form-control form-control-sm" name="reason" rows="3" required
                          minlength="3" placeholder="Why? The customer is told."></textarea>
                <button class="btn btn-sm btn-outline-danger w-100 mt-2" type="submit">
                  Decline this enquiry
                </button>
              </form>
            </div>
          </div>

          <p class="text-muted small mt-3">
            An accepted quotation becomes an ordinary order — same OTP, same prepaid
            UPI, same courier selection. Wholesale gets no shortcuts, which matters
            most here because the amounts are largest.
          </p>
        </div>
      </div>`;

    root.querySelectorAll('[data-send]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Sending');
        try {
          await api.post(`/admin/bulk-orders/quotes/${encodeURIComponent(button.dataset.send)}/send`, {});
          toast('Quotation sent.');
          renderDetail(uuid);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    const declineForm = root.querySelector('[data-decline-form]');

    declineForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = declineForm.querySelector('button');
      setBusy(button, true, 'Declining');

      try {
        await api.post(`/admin/bulk-orders/${encodeURIComponent(uuid)}/decline`,
          { reason: new FormData(declineForm).get('reason') });
        toast('Enquiry declined.');
        renderDetail(uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  } catch (error) {
    root.innerHTML = '<a class="small" href="bulk.html">← All enquiries</a>';
    showError(error, root);
  }
}

const mounted = await mountConsole('bulk.html');

if (mounted) {
  root = mounted.root;
  const uuid = queryParam('uuid');
  if (uuid) renderDetail(uuid); else renderList();
}
