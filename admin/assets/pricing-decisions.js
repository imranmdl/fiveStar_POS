/**
 * Shared by page-purchase-inward.js and page-mobile.js: the brief's §7
 * price-change prompt. One Bootstrap modal per flagged line, shown in
 * sequence, displaying the four figures the brief asks for and the four
 * decisions. Each choice is applied immediately via POST
 * /admin/pricing/decisions; "Skip for now" just moves to the next line
 * without deciding (nothing is lost — the old price simply stands).
 *
 * A plain shared module rather than a re-export from either page file: an
 * ES module runs its top-level code on import, and both page files mount
 * themselves into the page as a side effect of loading — importing one from
 * the other would run two pages' worth of mounting logic against the same
 * DOM. console.js itself has no such side effect (it only exports helpers),
 * so importing it here directly is safe.
 */

import { api, toast, setBusy, showError, escapeHtml, formatMoney } from './console.js?v=9';

export function resolvePriceDecisionQueue(pending, purchaseOrderId) {
  let host = document.querySelector('[data-price-decision-modal]');

  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-price-decision-modal', '');
    document.body.appendChild(host);
  }

  const showOne = (index) => new Promise((resolve) => {
    if (index >= pending.length) {
      resolve();
      return;
    }

    const item = pending[index];

    host.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal data-backdrop="static">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="h5 modal-title">Selling price for ${escapeHtml(item.sku)}</h2>
            </div>
            <div class="modal-body">
              <dl class="row small mb-3">
                <dt class="col-7">Current selling price</dt><dd class="col-5 text-end">${formatMoney(item.current_price)}</dd>
                <dt class="col-7">This purchase's cost</dt><dd class="col-5 text-end">${formatMoney(item.purchase_price)}</dd>
                <dt class="col-7">Resulting average cost</dt><dd class="col-5 text-end">${formatMoney(item.average_cost)}</dd>
              </dl>
              <div class="d-grid gap-2">
                <button class="btn btn-outline-secondary text-start" data-decision="keep_old">
                  Keep the current price — ${formatMoney(item.current_price)}
                </button>
                ${item.suggested_use_average !== null ? `
                  <button class="btn btn-outline-secondary text-start" data-decision="use_average">
                    Use the average-cost price — ${formatMoney(item.suggested_use_average)}
                  </button>` : ''}
                ${item.suggested_use_new !== null ? `
                  <button class="btn btn-outline-secondary text-start" data-decision="use_new">
                    Use the new-cost price — ${formatMoney(item.suggested_use_new)}
                  </button>` : ''}
                <div class="input-group">
                  <input class="form-control" type="number" step="0.01" min="0.01" placeholder="Set manually" data-manual-price>
                  <button class="btn btn-outline-secondary" data-decision="manual">Use this</button>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-link" data-skip>Skip for now</button>
              <span class="small text-muted">${index + 1} of ${pending.length}</span>
            </div>
          </div>
        </div>
      </div>`;

    const modalEl = host.querySelector('[data-modal]');
    const modal = new window.bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });

    const finishAndAdvance = () => {
      modal.hide();
      modalEl.addEventListener('hidden.bs.modal', () => resolve(showOne(index + 1)), { once: true });
    };

    host.querySelector('[data-skip]').addEventListener('click', finishAndAdvance);

    host.querySelectorAll('[data-decision]').forEach((button) => {
      button.addEventListener('click', async () => {
        const decision = button.dataset.decision;
        const manualPrice = host.querySelector('[data-manual-price]').value;

        if (decision === 'manual' && !manualPrice) {
          toast('Enter a price first.', 'danger');
          return;
        }

        setBusy(button, true, 'Saving');

        try {
          await api.post('/admin/pricing/decisions', {
            variant_uuid: item.variant_uuid,
            decision,
            manual_price: decision === 'manual' ? manualPrice : null,
            purchase_price: item.purchase_price,
            average_cost: item.average_cost,
            reference_type: 'purchase_order',
            reference_id: purchaseOrderId,
            reason: `Purchase inward decision (${decision})`,
          });
          toast('Selling price updated.');
          finishAndAdvance();
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });

    modal.show();
  });

  return showOne(0);
}
