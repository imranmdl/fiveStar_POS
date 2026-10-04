/** Support tickets: raise one and read the thread. */

import { api, bootstrapSession, isSignedIn } from './api.js';
import { mountChrome, mountFooter, escapeHtml, showError, toast, setBusy, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');

async function renderThread(uuid) {
  try {
    const response = await api.get(`/support/tickets/${encodeURIComponent(uuid)}`);
    const ticket = response.data;

    root.innerHTML = `
      <a class="small" href="support.html">← All tickets</a>
      <h1 class="h5 mt-2">${escapeHtml(ticket.subject)}</h1>
      <p class="text-muted small">
        ${escapeHtml(ticket.ticket_number)} · <span class="badge text-bg-secondary">${escapeHtml(ticket.status)}</span>
      </p>

      <div class="mb-4">
        ${ticket.messages.map((message) => `
          <div class="card mb-2 ${message.author_type === 'staff' ? 'border-primary' : ''}">
            <div class="card-body py-2">
              <div class="small fw-semibold">${escapeHtml(message.author_type === 'staff' ? 'Support team' : 'You')}</div>
              <div>${escapeHtml(message.body)}</div>
              <div class="small text-muted">${escapeHtml((message.created_date || '').replace('T', ' ').slice(0, 16))}</div>
            </div>
          </div>`).join('')}
      </div>

      ${ticket.status === 'closed' ? '<p class="text-muted small">This ticket is closed.</p>' : `
        <form data-reply-form>
          <label class="form-label" for="body">Add a reply</label>
          <textarea class="form-control" id="body" name="body" rows="3" required></textarea>
          <button class="btn btn-spice mt-2" type="submit">Send</button>
        </form>`}`;

    const form = root.querySelector('[data-reply-form]');
    if (form) {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        setBusy(button, true, 'Sending');
        try {
          await api.post(`/support/tickets/${encodeURIComponent(uuid)}/reply`,
            { body: new FormData(form).get('body') });
          renderThread(uuid);
        } catch (error) {
          showError(error);
          setBusy(button, false);
        }
      });
    }
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

async function renderList() {
  let tickets = [];

  if (isSignedIn()) {
    try {
      const response = await api.get('/support/tickets');
      tickets = response.data.tickets || [];
    } catch { /* fall through to the form */ }
  }

  root.innerHTML = `
    <div class="row g-4">
      <div class="col-12 col-lg-7">
        <h1 class="h4 mb-3">Raise a support ticket</h1>
        <form class="card" data-ticket-form>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label" for="subject">Subject</label>
              <input class="form-control" id="subject" name="subject" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="category">What is it about?</label>
              <select class="form-select" id="category" name="category">
                <option value="order">An order</option>
                <option value="delivery">Delivery</option>
                <option value="payment">Payment</option>
                <option value="refund">A refund</option>
                <option value="product">A product</option>
                <option value="account">My account</option>
                <option value="wholesale">Wholesale enquiry</option>
                <option value="other" selected>Something else</option>
              </select>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-md-6">
                <label class="form-label" for="contact_name">Your name</label>
                <input class="form-control" id="contact_name" name="contact_name" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="contact_mobile">Mobile</label>
                <input class="form-control" id="contact_mobile" name="contact_mobile" required inputmode="numeric">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="message">How can we help?</label>
              <textarea class="form-control" id="message" name="message" rows="4" required minlength="10"></textarea>
            </div>
            <button class="btn btn-spice" type="submit">Send</button>
          </div>
        </form>
      </div>

      <div class="col-12 col-lg-5">
        <h2 class="h6">Your tickets</h2>
        ${isSignedIn()
          ? (tickets.length
              ? tickets.map((ticket) => `
                  <a class="list-group-item list-group-item-action"
                     href="support.html?uuid=${encodeURIComponent(ticket.uuid)}">
                    <span class="fw-semibold d-block">${escapeHtml(ticket.subject)}</span>
                    <span class="small text-muted">${escapeHtml(ticket.ticket_number)} · ${escapeHtml(ticket.status)}</span>
                  </a>`).join('')
              : '<p class="text-muted small">No tickets yet.</p>')
          : '<p class="text-muted small"><a href="account.html?next=support.html">Sign in</a> to see your tickets.</p>'}
      </div>
    </div>`;

  const form = root.querySelector('[data-ticket-form]');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button');
    setBusy(button, true, 'Sending');

    try {
      const response = await api.post('/support/tickets',
        Object.fromEntries(new FormData(form).entries()));
      toast(`Ticket ${response.data.ticket.ticket_number} raised.`);
      window.location.href = `support.html?uuid=${encodeURIComponent(response.data.ticket.uuid)}`;
    } catch (error) {
      showError(error);
      setBusy(button, false);
    }
  });
}

async function start() {
  await bootstrapSession();
  const uuid = queryParam('uuid');
  if (uuid && isSignedIn()) renderThread(uuid); else renderList();
}

mountChrome('support.html');
mountFooter();
start();
