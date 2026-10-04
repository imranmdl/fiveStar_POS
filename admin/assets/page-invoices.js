/**
 * Invoice Tracking & Communication Center.
 *
 * "Invoice" here is a POS till sale (pos_sales) — see the backend's
 * InvoiceService doc comment for why. Recording a payment, refunding or
 * voiding a sale calls the SAME endpoints the POS/Customer Dues screens
 * already use (PosSaleController) — nothing here duplicates that logic,
 * this page is a read + WhatsApp-communication layer on top of it.
 *
 * WHATSAPP BUTTONS open a wa.me click-to-chat link with the message
 * pre-filled — the same pattern this console already uses for a customer's
 * phone number (see contactMenu() in page-customers.js). There is no
 * WhatsApp Business API credential anywhere in this project, so a send is
 * logged as "opened", not "delivered" — the admin still presses send inside
 * WhatsApp itself.
 */
import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         iconStatCard, badge, emptyState } from './console.js?v=9';

const ICONS = {
  invoice: '<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h6M9 11h6M9 15h3"/>',
  check: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5"/>',
  half: '<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/>',
  alert: '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  undo: '<path d="M3 7v6h6"/><path d="M3.5 13a8 8 0 1 0 2-8.4L3 7"/>',
  ban: '<circle cx="12" cy="12" r="9"/><path d="m5.5 5.5 13 13"/>',
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  gift: '<rect x="3" y="9" width="18" height="12" rx="1"/><path d="M3 13h18M12 9v12"/><path d="M12 9C9 9 8 7.5 8 6a2 2 0 0 1 4 0 2 2 0 0 1 4 0c0 1.5-1 3-4 3Z"/>',
  send: '<path d="m3 12 18-8-8 18-2-8-8-2Z"/>',
};

const TEMPLATES = [
  { code: 'invoice_created', label: 'Invoice Created' },
  { code: 'payment_received', label: 'Payment Received' },
  { code: 'partial_reminder', label: 'Partial Payment Reminder' },
  { code: 'payment_due_reminder', label: 'Payment Due Reminder' },
  { code: 'overdue', label: 'Overdue Payment' },
  { code: 'offer_available', label: 'Offer Available' },
  { code: 'refund_processed', label: 'Refund Processed' },
  { code: 'order_delivered', label: 'Order Delivered' },
  { code: 'payment_completed', label: 'Payment Completed' },
];

let root = null;
let filters = {};
let currentPage = 1;

function money(value) {
  return formatMoney(Number(value || 0));
}

function statusLabel(status) {
  return { unpaid: 'Unpaid', partial: 'Partially Paid', paid: 'Paid' }[status] || status;
}

function customerCell(row) {
  const digits = String(row.customer_mobile || '').replace(/\D/g, '').slice(-10);
  const phone = digits.length === 10
    ? `<a href="https://wa.me/91${digits}" target="_blank" rel="noopener noreferrer" class="small text-decoration-none">${escapeHtml(row.customer_mobile)}</a>`
    : `<span class="small text-muted">${escapeHtml(row.customer_mobile || '—')}</span>`;

  return `<div>${escapeHtml(row.customer_name)}</div>${phone}`;
}

function invoiceRow(row) {
  const remaining = Number(row.balance_due || 0);

  return `
    <tr>
      <td><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-open="${escapeHtml(row.uuid)}">${escapeHtml(row.sale_number)}</button></td>
      <td>${customerCell(row)}</td>
      <td class="text-nowrap small">${escapeHtml((row.created_date || '').slice(0, 16))}</td>
      <td class="num">${money(row.grand_total)}</td>
      <td class="num">${money(row.amount_paid)}</td>
      <td class="num ${remaining > 0 ? 'text-danger fw-semibold' : ''}">${money(remaining)}</td>
      <td class="num">${money(row.discount_amount)}</td>
      <td class="text-uppercase small">${escapeHtml(row.payment_method)}</td>
      <td>${badge(row.payment_status, statusLabel(row.payment_status))}</td>
      <td class="text-nowrap">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-open="${escapeHtml(row.uuid)}">View</button>
      </td>
    </tr>`;
}

async function loadSummary() {
  const el = root.querySelector('[data-summary]');
  const alertsEl = root.querySelector('[data-alerts]');

  try {
    const response = await api.get('/admin/invoices/summary');
    const s = response.data;

    el.innerHTML = [
      iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.invoice, label: 'Total Invoices', value: s.total }),
      iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.check, label: 'Paid', value: s.paid }),
      iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.half, label: 'Partially Paid', value: s.partial }),
      iconStatCard({ tone: '#A33B3B', iconSvgPaths: ICONS.alert, label: 'Unpaid', value: s.unpaid }),
      iconStatCard({ tone: '#A33B3B', iconSvgPaths: ICONS.clock, label: 'Overdue', value: s.overdue }),
      iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: ICONS.undo, label: 'Refunded', value: s.refunded }),
      iconStatCard({ tone: 'var(--muted-2)', iconSvgPaths: ICONS.ban, label: 'Cancelled', value: s.cancelled }),
      iconStatCard({ tone: 'var(--gold)', iconSvgPaths: ICONS.rupee, label: "Today's Revenue", value: money(s.todays_revenue) }),
    ].join('');

    const alerts = [
      { key: 'overdue', label: `${s.overdue} overdue`, dot: '🔴', filter: { overdue_only: true } },
      { key: 'partial', label: `${s.partial} partially paid`, dot: '🟡', filter: { payment_status: 'partial' } },
      { key: 'unpaid', label: `${s.unpaid} unpaid`, dot: '🟠', filter: { payment_status: 'unpaid' } },
      { key: 'paid', label: `${s.paid} paid`, dot: '🟢', filter: { payment_status: 'paid' } },
    ];

    alertsEl.innerHTML = alerts.map((a) => `
      <button type="button" class="btn btn-sm btn-outline-secondary" data-alert-filter='${JSON.stringify(a.filter)}'>
        ${a.dot} ${escapeHtml(a.label)}
      </button>`).join('');

    alertsEl.querySelectorAll('[data-alert-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const patch = JSON.parse(btn.dataset.alertFilter);
        resetFilterForm();
        filters = patch;
        applyFiltersToForm();
        currentPage = 1;
        loadList();
      });
    });
  } catch (error) {
    el.innerHTML = '';
    showError(error, el);
  }
}

function currentFilterQuery(extra = {}) {
  const form = root.querySelector('[data-filter-form]');
  const data = new FormData(form);
  const query = {};

  for (const [key, value] of data.entries()) {
    if (value !== '') query[key] = value;
  }

  if (filters.overdue_only) query.overdue_only = 1;
  if (filters.payment_status && !query.payment_status) query.payment_status = filters.payment_status;

  return { ...query, ...extra };
}

function resetFilterForm() {
  filters = {};
  const form = root.querySelector('[data-filter-form]');
  if (form) form.reset();
}

function applyFiltersToForm() {
  const form = root.querySelector('[data-filter-form]');
  if (!form) return;

  if (filters.payment_status) form.elements.payment_status.value = filters.payment_status;
}

async function loadList() {
  const tbody = root.querySelector('[data-rows]');
  const pager = root.querySelector('[data-pager]');
  tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></td></tr>`;

  try {
    const query = currentFilterQuery({ page: currentPage, per_page: 25, sort: 'created_date', direction: 'DESC' });
    const response = await api.get('/admin/invoices', query);
    const rows = response.data || [];
    const meta = response.meta || {};

    tbody.innerHTML = rows.map(invoiceRow).join('') || `<tr><td colspan="10">${emptyState('No invoices match these filters', 'Try widening the date range or clearing a filter.')}</td></tr>`;

    tbody.querySelectorAll('[data-open]').forEach((btn) => {
      btn.addEventListener('click', () => openDetail(btn.dataset.open));
    });

    const totalPages = meta.total_pages || 1;
    pager.innerHTML = totalPages > 1 ? `
      <button class="btn btn-sm btn-outline-secondary" ${currentPage <= 1 ? 'disabled' : ''} data-page="prev">Previous</button>
      <span class="small text-muted mx-2">Page ${meta.page} of ${totalPages} · ${meta.total} invoice(s)</span>
      <button class="btn btn-sm btn-outline-secondary" ${currentPage >= totalPages ? 'disabled' : ''} data-page="next">Next</button>
    ` : `<span class="small text-muted">${meta.total || 0} invoice(s)</span>`;

    const prevBtn = pager.querySelector('[data-page="prev"]');
    const nextBtn = pager.querySelector('[data-page="next"]');
    if (prevBtn) prevBtn.addEventListener('click', () => { currentPage -= 1; loadList(); });
    if (nextBtn) nextBtn.addEventListener('click', () => { currentPage += 1; loadList(); });
  } catch (error) {
    tbody.innerHTML = '';
    showError(error, tbody.closest('.card-body') || tbody);
  }
}

async function exportCsv() {
  const button = root.querySelector('[data-export]');
  setBusy(button, true, 'Exporting');

  try {
    const query = currentFilterQuery();
    const qs = new URLSearchParams(query).toString();
    const blob = await api.downloadFile(`/admin/invoices/export${qs ? `?${qs}` : ''}`);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `invoices_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    showError(error);
  } finally {
    setBusy(button, false);
  }
}

// ---------------------------------------------------------------------------
// Detail drawer
// ---------------------------------------------------------------------------

function timelineMarkup(steps) {
  const toneDot = { success: 'success', danger: 'danger', pending: 'warning', neutral: 'secondary' };

  return `<ol class="list-unstyled ms-1 border-start ps-3" style="border-color:var(--sand-2)!important">
    ${steps.map((s) => `
      <li class="mb-3 position-relative">
        <span class="position-absolute top-0 start-0 translate-middle-x rounded-circle bg-${toneDot[s.tone] || 'secondary'}" style="width:10px;height:10px;margin-left:-1.55rem;margin-top:4px"></span>
        <div class="fw-semibold small">${escapeHtml(s.label)}</div>
        <div class="text-muted small">${escapeHtml(s.date || 'Pending')}</div>
      </li>`).join('')}
  </ol>`;
}

function offerCard(offer) {
  const discount = offer.discount && offer.discount.summary ? offer.discount.summary : '';
  const ends = offer.schedule && offer.schedule.ends_date ? offer.schedule.ends_date.slice(0, 10) : 'No end date';
  const minOrder = offer.discount && offer.discount.min_order_value ? money(offer.discount.min_order_value) : 'None';

  return `
    <div class="border rounded p-2 mb-2" style="border-color:var(--sand)!important">
      <div class="fw-semibold small">🎁 ${escapeHtml(offer.title)}</div>
      <div class="small text-muted">${escapeHtml(discount)}</div>
      <div class="small text-muted">Valid until: ${escapeHtml(ends)} · Min order: ${minOrder}</div>
      <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-send-offer="${escapeHtml(offer.title)}">Send Offer on WhatsApp</button>
    </div>`;
}

function communicationRow(c) {
  const ok = c.status === 'opened' || c.status === 'sent';
  const label = c.status === 'opened' ? 'Opened' : c.status === 'sent' ? 'Sent' : 'Failed';
  const channel = c.channel === 'sms' ? 'SMS' : 'WhatsApp';

  return `<tr>
    <td class="small">${escapeHtml(TEMPLATES.find((t) => t.code === c.template_code)?.label || c.template_code)}</td>
    <td class="small text-nowrap">${escapeHtml((c.created_date || '').slice(0, 16))}</td>
    <td class="small">${escapeHtml(c.recipient_mobile)}</td>
    <td class="small">${channel}</td>
    <td><span class="badge text-bg-${ok ? 'success' : 'danger'}">${label}</span></td>
  </tr>`;
}

let activeSale = null;

async function openDetail(uuid) {
  const offcanvasEl = root.querySelector('[data-detail-offcanvas]');
  const body = offcanvasEl.querySelector('[data-detail-body]');
  body.innerHTML = `<div class="text-center py-5"><div class="spinner-border"></div></div>`;

  const bsOffcanvas = window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
  bsOffcanvas.show();

  try {
    const response = await api.get(`/admin/invoices/${uuid}`);
    activeSale = response.data;
    renderDetail(activeSale);
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

function renderDetail(sale) {
  const offcanvasEl = root.querySelector('[data-detail-offcanvas]');
  const body = offcanvasEl.querySelector('[data-detail-body]');
  const remaining = Number(sale.grand_total) - Number(sale.amount_paid);
  const cs = sale.customer_summary;

  offcanvasEl.querySelector('[data-detail-title]').textContent = sale.sale_number;

  body.innerHTML = `
    <div data-print-area>
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <div class="fw-semibold">${escapeHtml(sale.customer_name)}</div>
          <div class="small text-muted">${escapeHtml(sale.customer_mobile || 'No phone on file')}</div>
          ${sale.customer_uuid ? `<button type="button" class="btn btn-link btn-sm p-0" data-customer-history="${escapeHtml(sale.customer_uuid)}">View customer history</button>` : ''}
        </div>
        ${badge(sale.payment_status, statusLabel(sale.payment_status))}
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold py-2">Payment</div>
        <div class="card-body">
          <div class="row text-center g-2 mb-2">
            <div class="col"><div class="small text-muted">Total</div><div class="fw-semibold">${money(sale.grand_total)}</div></div>
            <div class="col"><div class="small text-muted">Paid</div><div class="fw-semibold text-success">${money(sale.amount_paid)}</div></div>
            <div class="col"><div class="small text-muted">Remaining</div><div class="fw-semibold ${remaining > 0 ? 'text-danger' : ''}">${money(Math.max(0, remaining))}</div></div>
          </div>
          <div class="small text-muted mb-2">Payment method: <span class="text-uppercase">${escapeHtml(sale.payment_method)}</span> · Discount: ${money(sale.discount_amount)}</div>
          ${sale.due_payments && sale.due_payments.length ? `
            <table class="table table-sm mb-0">
              <thead><tr><th>Date</th><th>Amount</th><th>Method</th></tr></thead>
              <tbody>${sale.due_payments.map((p) => `<tr><td class="small">${escapeHtml(p.payment_date)}</td><td class="num">${money(p.amount)}</td><td class="small text-uppercase">${escapeHtml(p.payment_method)}</td></tr>`).join('')}</tbody>
            </table>` : '<div class="small text-muted">No due-payment history.</div>'}
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold py-2">Products</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Discount</th><th>GST</th><th>Total</th></tr></thead>
            <tbody>${(sale.items || []).map((i) => `
              <tr>
                <td class="small">${escapeHtml(i.product_name)} <span class="text-muted">(${escapeHtml(i.variant_name)})</span></td>
                <td class="num">${escapeHtml(i.quantity)}</td>
                <td class="num">${money(i.unit_price)}</td>
                <td class="num">${money(i.discount_amount)}</td>
                <td class="num">${money(i.tax_amount)}</td>
                <td class="num">${money(i.line_total)}</td>
              </tr>`).join('')}</tbody>
          </table>
        </div>
      </div>

      ${cs ? `
      <div class="card mb-3" data-no-print>
        <div class="card-header bg-white fw-semibold py-2">Customer at a glance</div>
        <div class="card-body row g-2 text-center small">
          <div class="col-4">Invoices<br><b>${escapeHtml(cs.invoice_count)}</b></div>
          <div class="col-4">Total due<br><b class="${Number(cs.total_due) > 0 ? 'text-danger' : ''}">${money(cs.total_due)}</b></div>
          <div class="col-4">Wallet<br><b>${money(cs.wallet_balance)}</b></div>
        </div>
      </div>` : ''}

      ${sale.applicable_offers && sale.applicable_offers.length ? `
      <div class="card mb-3" data-no-print>
        <div class="card-header bg-white fw-semibold py-2">Offer Available</div>
        <div class="card-body">${sale.applicable_offers.map(offerCard).join('')}</div>
      </div>` : ''}

      <div class="card mb-3" data-no-print>
        <div class="card-header bg-white fw-semibold py-2">Send WhatsApp</div>
        <div class="card-body d-flex flex-wrap gap-2">
          ${TEMPLATES.map((t) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-whatsapp="${t.code}">${escapeHtml(t.label)}</button>`).join('')}
        </div>
      </div>

      <div class="card mb-3" data-no-print>
        <div class="card-header bg-white fw-semibold py-2">Communication history</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Type</th><th>When</th><th>To</th><th>Channel</th><th>Status</th></tr></thead>
            <tbody data-comm-rows>${(sale.communications || []).map(communicationRow).join('') || '<tr><td colspan="5" class="text-muted small text-center py-2">No messages sent yet</td></tr>'}</tbody>
          </table>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold py-2">Timeline</div>
        <div class="card-body">${timelineMarkup(sale.timeline || [])}</div>
      </div>

      <div class="d-flex flex-wrap gap-2" data-no-print>
        ${remaining > 0.004 ? '<button type="button" class="btn btn-dark btn-sm" data-record-payment>Record Payment</button>' : ''}
        ${sale.status === 'completed' ? '<button type="button" class="btn btn-outline-secondary btn-sm" data-refund>Refund</button>' : ''}
        ${sale.status === 'completed' ? '<button type="button" class="btn btn-outline-danger btn-sm" data-void>Cancel Invoice</button>' : ''}
        <button type="button" class="btn btn-outline-secondary btn-sm" data-print>Print</button>
      </div>
    </div>`;

  wireDetailActions(sale);
}

function wireDetailActions(sale) {
  const offcanvasEl = root.querySelector('[data-detail-offcanvas]');

  offcanvasEl.querySelectorAll('[data-whatsapp]').forEach((btn) => {
    btn.addEventListener('click', () => openWhatsAppModal(sale.uuid, btn.dataset.whatsapp));
  });

  offcanvasEl.querySelectorAll('[data-send-offer]').forEach((btn) => {
    btn.addEventListener('click', () => openWhatsAppModal(sale.uuid, 'offer_available'));
  });

  const printBtn = offcanvasEl.querySelector('[data-print]');
  if (printBtn) printBtn.addEventListener('click', () => window.print());

  const payBtn = offcanvasEl.querySelector('[data-record-payment]');
  if (payBtn) payBtn.addEventListener('click', () => openRecordPaymentModal(sale));

  const refundBtn = offcanvasEl.querySelector('[data-refund]');
  if (refundBtn) refundBtn.addEventListener('click', () => openRefundModal(sale));

  const voidBtn = offcanvasEl.querySelector('[data-void]');
  if (voidBtn) voidBtn.addEventListener('click', () => voidSale(sale));

  const historyBtn = offcanvasEl.querySelector('[data-customer-history]');
  if (historyBtn) historyBtn.addEventListener('click', () => openCustomerHistory(historyBtn.dataset.customerHistory));
}

async function refreshActiveDetail() {
  if (!activeSale) return;
  const response = await api.get(`/admin/invoices/${activeSale.uuid}`);
  activeSale = response.data;
  renderDetail(activeSale);
  loadSummary();
  loadList();
}

// ---------------------------------------------------------------------------
// WhatsApp preview/send modal
// ---------------------------------------------------------------------------

async function openWhatsAppModal(saleUuid, templateCode) {
  const modalEl = root.querySelector('[data-whatsapp-modal]');
  const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
  const textarea = modalEl.querySelector('[data-message-text]');
  const openLink = modalEl.querySelector('[data-open-whatsapp]');
  const label = TEMPLATES.find((t) => t.code === templateCode)?.label || templateCode;

  modalEl.querySelector('[data-whatsapp-title]').textContent = `WhatsApp — ${label}`;
  textarea.value = 'Loading…';
  textarea.disabled = true;
  openLink.classList.add('disabled');
  modalEl.dataset.template = templateCode;
  modalEl.dataset.sale = saleUuid;
  bsModal.show();

  try {
    const response = await api.get(`/admin/invoices/${saleUuid}/whatsapp-preview`, { template: templateCode });
    const { phone, message, wa_link } = response.data;

    textarea.value = message;
    textarea.disabled = false;

    if (phone && wa_link) {
      openLink.classList.remove('disabled');
      openLink.dataset.baseLink = wa_link.split('?text=')[0];
    } else {
      openLink.classList.add('disabled');
      toast('This customer has no phone number on file.', 'danger');
    }
  } catch (error) {
    textarea.value = '';
    showError(error);
  }
}

function wireWhatsAppModal() {
  const modalEl = root.querySelector('[data-whatsapp-modal]');
  const openLink = modalEl.querySelector('[data-open-whatsapp]');

  openLink.addEventListener('click', async (event) => {
    event.preventDefault();
    if (openLink.classList.contains('disabled')) return;

    const message = modalEl.querySelector('[data-message-text]').value;
    const saleUuid = modalEl.dataset.sale;
    const templateCode = modalEl.dataset.template;
    const baseLink = openLink.dataset.baseLink;

    // Opened synchronously, inside the click handler and before any await —
    // a tab opened AFTER an awaited fetch resolves is what most browsers'
    // popup blockers silently swallow, since it no longer reads as a direct
    // response to the click. An about:blank tab opened right now is still
    // trusted, and its location is filled in once the log call succeeds.
    //
    // Deliberately NOT passing 'noopener' here: per spec, window.open()
    // always returns null when noopener is set (there is then no way to
    // hold a reference to the tab it just opened), which silently broke
    // this exact trick — `tab` was always null, so every send fell through
    // to the post-await fallback and got blocked. wa.me is a fixed, trusted
    // destination we set ourselves, so the small reverse-tabnabbing risk
    // noopener guards against does not apply here.
    const tab = window.open('', '_blank');

    try {
      await api.post(`/admin/invoices/${saleUuid}/whatsapp-log`, { template: templateCode, message });
      const waUrl = `${baseLink}?text=${encodeURIComponent(message)}`;

      if (tab) tab.location.href = waUrl;
      else window.open(waUrl, '_blank', 'noopener'); // popup was blocked anyway; try once more directly

      toast('WhatsApp opened and logged.');
      window.bootstrap.Modal.getInstance(modalEl)?.hide();
      if (activeSale && activeSale.uuid === saleUuid) {
        const commResponse = await api.get(`/admin/invoices/${saleUuid}/communications`);
        const tbody = root.querySelector('[data-detail-offcanvas] [data-comm-rows]');
        if (tbody) tbody.innerHTML = commResponse.data.communications.map(communicationRow).join('');
      }
    } catch (error) {
      if (tab) tab.close();
      showError(error);
    }
  });

  const smsBtn = modalEl.querySelector('[data-send-sms]');
  smsBtn.addEventListener('click', async () => {
    const message = modalEl.querySelector('[data-message-text]').value;
    const saleUuid = modalEl.dataset.sale;
    const templateCode = modalEl.dataset.template;

    smsBtn.disabled = true;
    smsBtn.textContent = 'Sending…';

    try {
      await api.post(`/admin/invoices/${saleUuid}/sms-send`, { template: templateCode, message });
      toast('SMS sent.');
      window.bootstrap.Modal.getInstance(modalEl)?.hide();
      if (activeSale && activeSale.uuid === saleUuid) {
        const commResponse = await api.get(`/admin/invoices/${saleUuid}/communications`);
        const tbody = root.querySelector('[data-detail-offcanvas] [data-comm-rows]');
        if (tbody) tbody.innerHTML = commResponse.data.communications.map(communicationRow).join('');
      }
    } catch (error) {
      showError(error);
    } finally {
      smsBtn.disabled = false;
      smsBtn.textContent = 'Send SMS instead';
    }
  });
}

// ---------------------------------------------------------------------------
// Record payment / refund / void
// ---------------------------------------------------------------------------

async function openRecordPaymentModal(sale) {
  const remaining = (Number(sale.grand_total) - Number(sale.amount_paid)).toFixed(2);
  const amount = prompt(`Remaining balance is ${money(remaining)}. Enter the amount received:`, remaining);
  if (amount === null || amount.trim() === '') return;

  const method = prompt('Payment method (cash, upi, card, other):', 'cash');
  if (!method) return;

  try {
    await api.post(`/admin/pos/sales/${sale.uuid}/payments`, {
      amount: Number(amount),
      payment_method: method.trim().toLowerCase(),
    });
    toast('Payment recorded. Balance updated.');
    await refreshActiveDetail();
  } catch (error) {
    showError(error);
  }
}

async function openRefundModal(sale) {
  const lines = (sale.items || []).filter((i) => Number(i.quantity) - Number(i.refunded_quantity || 0) > 0);

  if (lines.length === 0) {
    toast('Nothing left on this invoice to refund.', 'danger');
    return;
  }

  const summary = lines.map((i, idx) => `${idx + 1}) ${i.product_name} (${i.variant_name}) — up to ${i.quantity} available`).join('\n');
  const choice = prompt(`Which item number to refund?\n${summary}`);
  const line = lines[Number(choice) - 1];
  if (!line) return;

  const qty = prompt(`Quantity to refund for ${line.product_name}?`, String(line.quantity));
  if (!qty || Number(qty) <= 0) return;

  const reason = prompt('Reason for the refund:');
  if (!reason || reason.trim().length < 3) { toast('A reason is required.', 'danger'); return; }

  try {
    await api.post(`/admin/pos/sales/${sale.uuid}/refund`, {
      reason: reason.trim(),
      items: [{ pos_sale_item_uuid: line.uuid, quantity: Number(qty) }],
    });
    toast('Refund processed.');
    await refreshActiveDetail();
  } catch (error) {
    showError(error);
  }
}

async function voidSale(sale) {
  if (!confirm(`Cancel invoice ${sale.sale_number}? This cannot be undone.`)) return;

  const reason = prompt('Reason for cancelling this invoice:');
  if (!reason || reason.trim().length < 3) { toast('A reason is required.', 'danger'); return; }

  try {
    await api.post(`/admin/pos/sales/${sale.uuid}/void`, { reason: reason.trim() });
    toast('Invoice cancelled.');
    window.bootstrap.Offcanvas.getInstance(root.querySelector('[data-detail-offcanvas]'))?.hide();
    loadSummary();
    loadList();
  } catch (error) {
    showError(error);
  }
}

// ---------------------------------------------------------------------------
// Customer history
// ---------------------------------------------------------------------------

async function openCustomerHistory(customerUuid) {
  const modalEl = root.querySelector('[data-customer-modal]');
  const body = modalEl.querySelector('[data-customer-body]');
  body.innerHTML = `<div class="text-center py-4"><div class="spinner-border"></div></div>`;
  window.bootstrap.Modal.getOrCreateInstance(modalEl).show();

  try {
    const response = await api.get(`/admin/customers/${customerUuid}/invoice-summary`);
    const d = response.data;

    body.innerHTML = `
      <h6 class="mb-1">${escapeHtml(d.customer.full_name)}</h6>
      <div class="text-muted small mb-3">${escapeHtml(d.customer.mobile)}</div>
      <div class="row g-2 text-center">
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Invoices</div><div class="fw-semibold">${escapeHtml(d.invoice_count)}</div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Total purchases</div><div class="fw-semibold">${money(d.total_purchases)}</div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Total paid</div><div class="fw-semibold text-success">${money(d.total_paid)}</div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Total due</div><div class="fw-semibold ${Number(d.total_due) > 0 ? 'text-danger' : ''}">${money(d.total_due)}</div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Partial payments</div><div class="fw-semibold">${escapeHtml(d.partial_count)}</div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Wallet balance</div><div class="fw-semibold">${money(d.wallet_balance)}</div></div></div>
      </div>`;
  } catch (error) {
    body.innerHTML = '';
    showError(error, body);
  }
}

// ---------------------------------------------------------------------------
// Page shell
// ---------------------------------------------------------------------------

function shell() {
  return `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h1 class="h4 mb-0">Invoice Tracking</h1>
        <p class="text-muted small mb-0">Till invoices, partial-payment balances and WhatsApp follow-ups.</p>
      </div>
      <button type="button" class="btn btn-outline-dark btn-sm" data-export>Export CSV</button>
    </div>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-3" data-summary></div>

    <div class="d-flex flex-wrap gap-2 mb-3" data-alerts></div>

    <div class="card mb-3">
      <div class="card-body">
        <form data-filter-form class="row g-2 align-items-end" onsubmit="return false">
          <div class="col-12 col-md-3">
            <label class="form-label small mb-0">Search</label>
            <input type="search" class="form-control form-control-sm" name="search" placeholder="Invoice #, name or phone">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Status</label>
            <select class="form-select form-select-sm" name="payment_status">
              <option value="">All</option>
              <option value="paid">Paid</option>
              <option value="partial">Partially Paid</option>
              <option value="unpaid">Unpaid</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Payment method</label>
            <select class="form-select form-select-sm" name="payment_method">
              <option value="">All</option>
              <option value="cash">Cash</option>
              <option value="upi">UPI</option>
              <option value="card">Card</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0">From</label>
            <input type="date" class="form-control form-control-sm" name="from">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0">To</label>
            <input type="date" class="form-control form-control-sm" name="to">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Min amount</label>
            <input type="number" min="0" class="form-control form-control-sm" name="amount_min">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Max amount</label>
            <input type="number" min="0" class="form-control form-control-sm" name="amount_max">
          </div>
          <div class="col-12 col-md-2 d-flex gap-2">
            <button type="button" class="btn btn-dark btn-sm w-100" data-apply-filters>Filter</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-clear-filters>Clear</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Invoice #</th><th>Customer</th><th>Date</th><th>Total</th><th>Paid</th>
              <th>Remaining</th><th>Discount</th><th>Method</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody data-rows></tbody>
        </table>
      </div>
      <div class="card-body d-flex justify-content-end align-items-center py-2" data-pager></div>
    </div>

    <!-- Detail drawer -->
    <div class="offcanvas offcanvas-end" tabindex="-1" style="width:min(560px,100vw)" data-detail-offcanvas>
      <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" data-detail-title>Invoice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close" data-no-print></button>
      </div>
      <div class="offcanvas-body" data-detail-body></div>
    </div>

    <!-- WhatsApp modal -->
    <div class="modal fade" tabindex="-1" data-whatsapp-modal>
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h6 class="modal-title" data-whatsapp-title>WhatsApp</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <label class="form-label small">Message (you can edit before sending)</label>
            <textarea class="form-control" rows="6" data-message-text></textarea>
            <p class="small text-muted mb-0 mt-2">
              If WhatsApp says this number isn't on WhatsApp (common for a brand new
              customer), use <strong>Send SMS instead</strong> — it goes through the
              regular SMS gateway and works for any mobile number.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-outline-primary btn-sm" data-send-sms>Send SMS instead</button>
            <a href="#" class="btn btn-success btn-sm" data-open-whatsapp>Open WhatsApp &amp; Log</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Customer history modal -->
    <div class="modal fade" tabindex="-1" data-customer-modal>
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h6 class="modal-title">Customer history</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" data-customer-body></div>
        </div>
      </div>
    </div>`;
}

async function render() {
  root.innerHTML = shell();

  root.querySelector('[data-export]').addEventListener('click', exportCsv);
  root.querySelector('[data-apply-filters]').addEventListener('click', () => {
    // The form is now the source of truth — drop any overdue_only/status
    // state a smart-alert chip left behind, so it cannot silently combine
    // with a manually-picked filter into a contradictory query.
    filters = {};
    currentPage = 1;
    loadList();
  });
  root.querySelector('[data-clear-filters]').addEventListener('click', () => { resetFilterForm(); currentPage = 1; loadList(); });

  wireWhatsAppModal();

  await Promise.all([loadSummary(), loadList()]);
}

const mounted = await mountConsole('invoices.html');
if (mounted) { root = mounted.root; render(); }
