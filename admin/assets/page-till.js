/**
 * Point of Sale (brief §12, Priority 2). Built entirely on top of the
 * inventory/pricing engine the earlier phases already verified — ringing up
 * a sale calls the same InventoryService every purchase, import and mobile
 * scan already uses; there is no separate POS stock balance.
 *
 * Payment here is cashier-attested (cash/UPI/card/other recorded by whoever
 * is standing at the till), not gateway-verified — see PosSaleService's own
 * doc comment for why. A sale defaults to walk-in; looking a customer up by
 * mobile (GET /admin/pos/customers) attaches their account instead, which is
 * what lets it draw on their wallet — see WalletService for the ledger this
 * shares with online checkout's own wallet redemption.
 *
 * This page is deliberately not in the console's sidebar (see console.js's
 * NAV comment) and does not reuse an existing console session at all — see
 * requireTillSignIn() below — so a console session left open on someone
 * else's screen doesn't walk straight into the till just because the URL is
 * guessed or bookmarked; whoever opens it must sign in as themselves.
 */

import { api, showError, toast, setBusy, escapeHtml, formatMoney,
         queryParam, emptyState, iconStatCard, storeTokens, clearTokens,
         renderMinimalChrome } from './console.js?v=9';

const ICONS = {
  cart: '<circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M2.5 3h2l2.8 12.4a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 8H6"/>',
  rupee: '<path d="M6 4h12"/><path d="M6 8h12"/><path d="M6 4c5 0 9 1.8 9 5s-4 5-9 5h9"/><path d="m9 14 8 6"/>',
  voided: '<circle cx="12" cy="12" r="9"/><path d="M8 8l8 8M16 8l-8 8"/>',
};

const state = {
  tab: queryParam('tab') || 'sell',
  history: { page: 1 },
};

let root = null;
let warehouses = [];
let defaultWarehouseUuid = null;
// A blank field the cashier types into (which physical counter this is), not
// a choice from the warehouse list — stock still deducts from the one real
// warehouse below, resolved silently.
let shopLabel = '';
let cart = [];
// The registered customer this sale is attached to, if the cashier looked one
// up — null means walk-in, same as always. Only a linked account can spend or
// receive wallet credit.
let selectedCustomer = null;
let walletApplied = 0;

/** Same tax-inclusive extraction PosSaleService::create() does server-side (Money::extractInclusiveTax) — an estimate for live display, not the authoritative figure. */
function estimateTax(netLineValue, gstRate) {
  const rate = Number(gstRate) || 0;
  if (rate <= 0) return 0;
  return netLineValue * rate / (100 + rate);
}

function cartRow(line, index) {
  const gross = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
  const net = Math.max(0, gross - (Number(line.discount_amount) || 0));
  const tax = estimateTax(net, line.gst_rate);

  return `
    <tr data-cart-row="${index}">
      <td>
        <span class="fw-semibold">${escapeHtml(line.product_name)}</span>
        <div class="small text-muted">${escapeHtml(line.variant_name)} · ${escapeHtml(line.sku)}</div>
        ${line.applied_offer_code ? `<div class="small"><span class="badge text-bg-success">${escapeHtml(line.applied_offer_code)}</span></div>` : ''}
      </td>
      <td><input class="form-control form-control-sm" type="number" step="0.001" min="0.001" value="${escapeHtml(line.quantity)}" data-field="quantity" style="width:5.5rem"></td>
      <td><input class="form-control form-control-sm" type="number" step="0.01" min="0" value="${escapeHtml(line.unit_price)}" data-field="unit_price" style="width:6.5rem"></td>
      <td><input class="form-control form-control-sm" type="number" step="0.01" min="0" value="${escapeHtml(line.discount_amount || 0)}" data-field="discount_amount" style="width:6rem"></td>
      <td class="text-end small" data-line-tax>${formatMoney(tax)}</td>
      <td class="text-end small" data-line-net>${formatMoney(net)}</td>
      <td><button class="btn btn-sm btn-outline-danger" data-remove>×</button></td>
    </tr>`;
}

function totals() {
  const subtotal = cart.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const discount = cart.reduce((sum, l) => sum + (Number(l.discount_amount) || 0), 0);
  const tax = cart.reduce((sum, l) => {
    const gross = (Number(l.quantity) || 0) * (Number(l.unit_price) || 0);
    const net = Math.max(0, gross - (Number(l.discount_amount) || 0));
    return sum + estimateTax(net, l.gst_rate);
  }, 0);
  const grandTotal = Math.max(0, subtotal - discount);

  return { subtotal, discount, tax, grandTotal };
}

/**
 * Recomputes the tax/net figures for one row and the summary card, without
 * touching any `<input>` element — typing into a cart field must never
 * rebuild the DOM it's typed into, or the input loses focus after every
 * single character (that was the bug: renderCart() used to run on every
 * keystroke, tearing down and recreating the very box being typed in).
 */
function updateCartRow(rowEl, line) {
  const gross = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
  const net = Math.max(0, gross - (Number(line.discount_amount) || 0));
  const tax = estimateTax(net, line.gst_rate);

  rowEl.querySelector('[data-line-tax]').textContent = formatMoney(tax);
  rowEl.querySelector('[data-line-net]').textContent = formatMoney(net);
}

function updateCartSummary(container) {
  const t = totals();
  container.querySelector('[data-subtotal]').textContent = formatMoney(t.subtotal);
  container.querySelector('[data-discount]').textContent = formatMoney(t.discount);
  container.querySelector('[data-tax]').textContent = formatMoney(t.tax);
  container.querySelector('[data-grand-total]').textContent = formatMoney(t.grandTotal);

  // The bill may have shrunk (a line removed or a qty cut) below what was
  // already earmarked from the wallet — clamp it back down rather than let a
  // stale amount silently exceed the new total.
  if (walletApplied > t.grandTotal) walletApplied = round2(t.grandTotal);

  const walletRow = container.querySelector('[data-wallet-row]');
  const remainderRow = container.querySelector('[data-remainder-row]');
  const canUseWallet = Boolean(selectedCustomer) && !selectedCustomer.wallet.is_frozen
    && Number(selectedCustomer.wallet.balance) > 0;

  if (walletRow) {
    walletRow.hidden = !canUseWallet;
    remainderRow.hidden = !canUseWallet;

    if (canUseWallet) {
      container.querySelector('[data-remainder-amount]').textContent = formatMoney(remainderDue());
      const walletInput = container.querySelector('[data-wallet-input]');
      walletInput.max = String(Math.min(t.grandTotal, Number(selectedCustomer.wallet.balance)));
      if (document.activeElement !== walletInput) walletInput.value = walletApplied || '';
    }
  }

  updateChange(container);
}

/** Full rebuild — only for adding or removing a line, never for editing one. */
function renderCart(container) {
  const tbody = container.querySelector('[data-cart-body]');

  tbody.innerHTML = cart.length === 0
    ? '<tr><td colspan="7" class="text-center text-muted small py-4">Scan or look up an item to add it.</td></tr>'
    : cart.map(cartRow).join('');

  tbody.querySelectorAll('[data-cart-row]').forEach((rowEl) => {
    const index = Number(rowEl.dataset.cartRow);

    rowEl.querySelector('[data-remove]').addEventListener('click', () => {
      cart.splice(index, 1);
      renderCart(container);
    });

    rowEl.querySelectorAll('[data-field]').forEach((input) => {
      input.addEventListener('input', () => {
        cart[index][input.dataset.field] = input.value;
        updateCartRow(rowEl, cart[index]);
        updateCartSummary(container);
      });
    });
  });

  updateCartSummary(container);
}

/**
 * Cash in hand doesn't come in paise — rounded to the nearest rupee the way a
 * till drawer actually works: 12.49 is treated as ₹12, 12.50 as ₹13. Math.round()
 * already rounds a positive half exactly up (Math.round(12.5) === 13), which is
 * the convention wanted here, so nothing fancier is needed.
 */
function roundToRupee(value) {
  return Math.round(Number(value) || 0);
}

/** What's left after wallet credit covers its share — what cash/UPI/card actually needs to cover. */
function remainderDue() {
  return Math.max(0, round2(totals().grandTotal - walletApplied));
}

function round2(value) {
  return Math.round(value * 100) / 100;
}

/**
 * Whether a mobile number exists to attach a Customer Due to — a registered
 * customer, or whatever's currently typed in the walk-in mobile field. Read
 * live (not cached) since either can change after this is first checked.
 */
function hasDueContact(container) {
  if (selectedCustomer) return true;
  const walkInMobile = container.querySelector('[name=walk_in_mobile]');
  return Boolean(walkInMobile && walkInMobile.value.trim());
}

/**
 * No checkbox, no separate mode to opt into: typing less than the bill IS
 * accepting a partial payment, for any payment method, the moment there is
 * somebody (a registered customer, or the walk-in mobile) to collect the
 * rest from. Typing the full amount (or leaving a non-cash field blank,
 * exactly as before this feature existed) is still a normal, fully-paid sale.
 */
function updateChange(container) {
  const method = container.querySelector('[name=payment_method]').value;
  const hint = container.querySelector('[data-shortfall-hint]');
  const raw = container.querySelector('[name=amount_tendered]').value;

  if (raw === '' && method !== 'cash') {
    // Unchanged from before this feature: no amount typed for a non-cash
    // method means "cashier attests it was paid in full by that method".
    container.querySelector('[data-change-label]').textContent = 'Change due';
    container.querySelector('[data-change]').textContent = '—';
    container.querySelector('[data-tendered-rounded]').textContent = '';
    hint.hidden = true;
    return;
  }

  const tendered = method === 'cash' ? roundToRupee(raw) : (Number(raw) || 0);
  const short = round2(remainderDue() - tendered);
  const willBePartial = short > 0.005;

  container.querySelector('[data-change-label]').textContent = willBePartial ? 'Balance still due' : 'Change due';
  container.querySelector('[data-change]').textContent = willBePartial
    ? formatMoney(short)
    : formatMoney(Math.max(0, -short));
  container.querySelector('[data-tendered-rounded]').textContent =
    method === 'cash' && raw !== '' && Number(raw) !== tendered ? `Rounded to ${formatMoney(tendered)}` : '';

  if (!willBePartial) {
    hint.hidden = true;
    return;
  }

  hint.hidden = false;
  hint.innerHTML = hasDueContact(container)
    ? `This will be recorded as a Customer Due — <strong>${formatMoney(short)}</strong> will remain owed, payable later.`
    : `Short by <strong>${formatMoney(short)}</strong> — enter the walk-in mobile number above so the remaining balance can be tracked.`;
}

function modalHost(attr) {
  let host = document.querySelector(`[${attr}]`);

  if (!host) {
    host = document.createElement('div');
    host.setAttribute(attr, '');
    document.body.appendChild(host);
  }

  return host;
}

/**
 * The "which offer, if any" prompt a cashier sees when a scanned item has
 * live offers. Resolves to the picked candidate, or null for "No discount"
 * — including when the modal is dismissed without a pick, so the item still
 * gets added at full price rather than the scan silently going nowhere.
 * Number keys (1/2/3, 0 for no discount) work as shortcuts, since this runs
 * at a busy till where reaching for the mouse is the slow way.
 *
 * @param {Array<{code:string, title:string, summary:string, discount_amount:number}>} candidates
 */
function offerPickerModal(candidates, itemLabel) {
  return new Promise((resolve) => {
    const host = modalHost('data-offer-picker-modal');

    host.innerHTML = `
      <div class="modal fade" tabindex="-1" data-modal data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="h6 modal-title">Offer available — ${escapeHtml(itemLabel)}</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="No discount"></button>
            </div>
            <div class="modal-body">
              <div class="list-group">
                ${candidates.map((c, i) => `
                  <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2" data-offer-pick="${i}">
                    <span><b>${i + 1}.</b> ${escapeHtml(c.title)} — ${escapeHtml(c.summary)}</span>
                    <span class="badge text-bg-success flex-shrink-0">-${formatMoney(c.discount_amount)}</span>
                  </button>`).join('')}
                <button type="button" class="list-group-item list-group-item-action" data-offer-pick="none">
                  <b>0.</b> No discount
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>`;

    const modalEl = host.querySelector('[data-modal]');
    const modal = new window.bootstrap.Modal(modalEl);
    let settled = false;

    const finish = (value) => {
      if (settled) return;
      settled = true;
      document.removeEventListener('keydown', onKey);
      modal.hide();
      resolve(value);
    };

    host.querySelectorAll('[data-offer-pick]').forEach((button) => {
      button.addEventListener('click', () => {
        const key = button.dataset.offerPick;
        finish(key === 'none' ? null : candidates[Number(key)]);
      });
    });

    const onKey = (event) => {
      if (event.key === '0') { finish(null); return; }
      const n = Number(event.key);
      if (n >= 1 && n <= candidates.length) finish(candidates[n - 1]);
    };
    document.addEventListener('keydown', onKey);

    // Dismissed without a pick (the X button) — still resolve, so the item
    // isn't left in limbo; "no discount" is the safe default.
    modalEl.addEventListener('hidden.bs.modal', () => finish(null), { once: true });

    modal.show();
  });
}

function customerCardMarkup() {
  if (!selectedCustomer) return '';

  const balance = Number(selectedCustomer.wallet.balance) || 0;
  const owed = Number(selectedCustomer.totalOutstanding) || 0;
  const loyalty = selectedCustomer.loyalty || null;

  return `
    <div class="alert ${selectedCustomer.wallet.is_frozen ? 'alert-warning' : 'alert-success'} py-2 mb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="small">
        <span class="fw-semibold">${escapeHtml(selectedCustomer.full_name)}</span>
        <span class="text-muted">${escapeHtml(selectedCustomer.mobile)}</span>
        <div>Wallet balance: <span class="fw-semibold">${formatMoney(balance)}</span>
          ${selectedCustomer.wallet.is_frozen ? ' — <span class="text-danger">frozen, cannot be spent</span>' : ''}</div>
        ${loyalty && (loyalty.balance > 0 || loyalty.lifetime_earned > 0)
          ? `<div>Loyalty points: <span class="fw-semibold">${escapeHtml(loyalty.balance)}</span>
              ${loyalty.is_frozen ? ' — <span class="text-danger">on hold</span>' : ''}
              — this sale will earn more, redeemable on the Loyalty page.</div>` : ''}
        ${owed > 0 ? `<div class="text-warning-emphasis">Already owes <span class="fw-semibold">${formatMoney(owed)}</span> from an earlier sale — <a href="customer-dues.html" target="_blank">view dues</a></div>` : ''}
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-customer>Remove</button>
    </div>`;
}

/** Wires the "Remove" control customerCardMarkup() just rendered into resultBox. */
function bindCustomerCard(container, resultBox) {
  resultBox.querySelector('[data-clear-customer]').addEventListener('click', () => {
    selectedCustomer = null;
    walletApplied = 0;
    container.querySelector('[data-walkin-fields]').hidden = false;
    resultBox.innerHTML = '';
    updateCartSummary(container);
  });
}

async function renderSellTab(container) {
  cart = [];
  selectedCustomer = null;
  walletApplied = 0;

  container.innerHTML = `
    <div class="mb-3">
      <label class="form-label" for="till-shop">Shop name</label>
      <input class="form-control" id="till-shop" data-shop-label placeholder="e.g. Shivaji Circle counter"
             value="${escapeHtml(shopLabel)}" autocomplete="off">
      <div class="form-text">Printed on the receipt and shown in sales history — type wherever you're selling from.</div>
    </div>

    <div class="mb-3">
      <label class="form-label mb-1">Registered customer (optional)</label>
      <div class="input-group" style="max-width:26rem">
        <input class="form-control" data-customer-mobile placeholder="Mobile number" inputmode="numeric" autocomplete="off" maxlength="10">
        <button class="btn btn-outline-secondary" type="button" data-find-customer>Find</button>
      </div>
      <div class="form-text">Attaching a registered customer lets this sale use, or add to, their wallet.</div>
      <div class="mt-2" data-customer-result></div>
    </div>

    <div class="row g-3" data-walkin-fields>
      <div class="col-md-6">
        <label class="form-label">Walk-in customer name</label>
        <input class="form-control" name="walk_in_name" placeholder="Optional">
      </div>
      <div class="col-md-6">
        <label class="form-label">Walk-in mobile</label>
        <input class="form-control" name="walk_in_mobile" placeholder="Optional" inputmode="numeric" maxlength="10">
        <div class="form-text">With their mobile, the customer can review what they bought once they sign in on the website.</div>
      </div>
    </div>

    <div class="input-group my-3">
      <input class="form-control" data-sku-input placeholder="Scan a barcode, or type a SKU / item name" autofocus autocomplete="off">
      <button class="btn btn-outline-secondary" data-sku-lookup>Add</button>
    </div>
    <div class="list-group mb-2 position-relative" data-search-results style="z-index:5"></div>
    <div class="small mb-2" data-sku-feedback>&nbsp;</div>

    <div class="table-responsive">
      <table class="table table-tight">
        <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Discount</th><th class="text-end">Tax (est.)</th><th class="text-end">Net</th><th></th></tr></thead>
        <tbody data-cart-body></tbody>
      </table>
    </div>

    <div class="row g-3 justify-content-end">
      <div class="col-md-4">
        <div class="card"><div class="card-body small">
          <div class="d-flex justify-content-between"><span>Subtotal</span><span data-subtotal>${formatMoney(0)}</span></div>
          <div class="d-flex justify-content-between"><span>Discount</span><span data-discount>${formatMoney(0)}</span></div>
          <div class="d-flex justify-content-between text-muted"><span>Tax (est., included)</span><span data-tax>${formatMoney(0)}</span></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fs-5 fw-semibold"><span>Total</span><span data-grand-total>${formatMoney(0)}</span></div>
          <div class="mt-2" data-wallet-row hidden>
            <label class="form-label small mb-0">From wallet</label>
            <input class="form-control form-control-sm" type="number" step="0.01" min="0" data-wallet-input>
          </div>
          <div class="d-flex justify-content-between small mt-1 fw-semibold" data-remainder-row hidden>
            <span>Amount due</span><span data-remainder-amount></span>
          </div>
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card"><div class="card-body">
          <label class="form-label small mb-0">Payment method</label>
          <select class="form-select mb-2" name="payment_method">
            <option value="cash">Cash</option>
            <option value="upi">UPI</option>
            <option value="card">Card (POS machine)</option>
            <option value="other">Other</option>
          </select>
          <div class="mb-2">
            <label class="form-label small mb-0" data-tendered-label>Amount tendered</label>
            <input class="form-control" type="number" step="0.01" min="0" name="amount_tendered">
            <div class="form-text" data-tendered-rounded></div>
          </div>
          <div class="small text-muted mb-2"><span data-change-label>Change due</span>: <span data-change>—</span></div>
          <div class="small text-warning-emphasis mb-2" data-shortfall-hint hidden></div>
          <label class="form-label small mb-0" for="till-delivery">Items given to the customer?</label>
          <select class="form-select mb-3" id="till-delivery" name="delivery">
            <option value="delivered">Delivered — handed over now</option>
            <option value="pending">Not delivered yet</option>
          </select>
          <button class="btn btn-dark w-100" data-complete-sale>Complete sale</button>
        </div></div>
      </div>
    </div>
    <div data-receipt class="mt-4"></div>`;

  renderCart(container);

  const skuInput = container.querySelector('[data-sku-input]');
  const feedback = container.querySelector('[data-sku-feedback]');
  const resultsBox = container.querySelector('[data-search-results]');

  const clearResults = () => { resultsBox.innerHTML = ''; };

  /**
   * Adds an already-resolved variant to the bill — shared by the fast
   * scan/exact-code path and the "no scanner, typed a name instead" search
   * path below, so both check offers and merge into an existing line the
   * same way.
   */
  const addVariantToCart = async (variant) => {
    // Check live offers before this item joins the bill — a picker only
    // interrupts when there's an actual choice to make; nothing applicable
    // means the item is added exactly as fast as before.
    let discountAmount = 0;
    let appliedOfferCode = null;
    let replaceDiscount = false;
    let extraQuantity = 0;
    const existing = cart.find((l) => l.variant_uuid === variant.uuid);

    try {
      const offersResponse = await api.get('/admin/pos/offers', {
        variant_uuid: variant.uuid,
        subtotal_so_far: totals().subtotal,
        existing_quantity: existing ? existing.quantity : 0,
        // The whole bill, so basket-wide offers (minimum spend, cheapest one free) can be judged.
        cart: JSON.stringify(cart.map((l) => ({ variant_uuid: l.variant_uuid, quantity: Number(l.quantity) || 0, unit_price: l.unit_price }))),
      });
      const candidates = offersResponse.data.offers || [];

      if (candidates.length > 0) {
        const picked = await offerPickerModal(candidates, `${variant.product_name} — ${variant.variant_name}`);

        if (picked) {
          discountAmount = Number(picked.discount_amount) || 0;
          appliedOfferCode = picked.code;
          replaceDiscount = Boolean(picked.replace_discount);
          extraQuantity = Number(picked.extra_quantity) || 0;
        }
      }
    } catch {
      // An offer-lookup failure must never block ringing up the item — same
      // "an advert must never cost the customer anything" rule applied to
      // the till: the item still gets added, just without a discount
      // prompt this one time.
    }

    if (existing) {
      existing.quantity = String((Number(existing.quantity) || 0) + 1 + extraQuantity);

      if (appliedOfferCode) {
        // BOGO's discount is recomputed from the line's new TOTAL quantity
        // each time (it earns in whole buy+get blocks, not linearly per
        // unit), so it replaces the line's discount rather than adding to
        // it — unlike a percentage/flat offer, which is genuinely one more
        // unit's worth of discount on top of what was already there.
        existing.discount_amount = String(
          replaceDiscount ? discountAmount : (Number(existing.discount_amount) || 0) + discountAmount
        );
        existing.applied_offer_code = appliedOfferCode;
      }
    } else {
      cart.push({
        variant_uuid: variant.uuid,
        sku: variant.sku,
        product_name: variant.product_name,
        variant_name: variant.variant_name,
        gst_rate: variant.gst_rate ?? 0,
        quantity: String(1 + extraQuantity),
        unit_price: String(variant.selling_price ?? '0'),
        discount_amount: String(discountAmount),
        applied_offer_code: appliedOfferCode,
      });
    }

    feedback.innerHTML = appliedOfferCode
      ? `<span class="text-success">Added: ${escapeHtml(variant.product_name)} — ${escapeHtml(variant.variant_name)} (${escapeHtml(appliedOfferCode)} applied${extraQuantity ? `, ${extraQuantity} free unit added` : ''})</span>`
      : `<span class="text-success">Added: ${escapeHtml(variant.product_name)} — ${escapeHtml(variant.variant_name)}</span>`;
    renderCart(container);
    clearResults();
    skuInput.value = '';
    skuInput.focus();
  };

  // Fast path: a scanner (or someone typing the exact SKU/barcode) sends the
  // full code then Enter almost instantly — resolved by an exact match.
  const addByExactCode = async () => {
    const code = skuInput.value.trim();
    if (!code) return;

    feedback.textContent = 'Looking up…';

    try {
      const response = await api.get('/admin/inventory/lookup', { sku: code });
      await addVariantToCart(response.data);
    } catch (error) {
      feedback.innerHTML = `<span class="text-danger">${escapeHtml(error.message || 'Not found.')}</span>`;
    }
  };

  // Fallback when there's no scanner: typing an item NAME instead of a code
  // shows matches to pick from, one at a time, rather than a hard "not
  // found". Debounced so every keystroke doesn't fire a request.
  let searchTimer = null;

  const searchByName = async (text) => {
    if (text.length < 2) { clearResults(); return; }

    try {
      const response = await api.get('/admin/inventory/search', { q: text });
      const matches = response.data || [];

      if (matches.length === 0) {
        resultsBox.innerHTML = '<div class="list-group-item small text-muted">No items match — try scanning, or a shorter name.</div>';
        return;
      }

      // Two entries with the same product name and pack weight are a data
      // problem (someone created the pack twice), not a real choice — flagged
      // so it's spotted here instead of at the till, mid-sale.
      const seen = {};
      matches.forEach((m) => {
        const key = `${m.product_name}|${m.weight_grams}`;
        seen[key] = (seen[key] || 0) + 1;
      });

      resultsBox.innerHTML = matches.map((m, i) => {
        const isDuplicate = seen[`${m.product_name}|${m.weight_grams}`] > 1;
        const isPublished = m.product_status === 'published';

        return `
        <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ${isPublished ? '' : 'opacity-75'}" data-result="${i}">
          <span>
            <span class="fw-semibold">${escapeHtml(m.product_name)}</span>
            <span class="text-muted small ms-1">${escapeHtml(m.variant_name)} · ${escapeHtml(m.sku)}</span>
            ${isPublished
              ? '<span class="badge text-bg-success ms-1">Published</span>'
              : `<span class="badge text-bg-secondary ms-1">${escapeHtml(m.product_status === 'archived' ? 'Unpublished' : 'Draft — not published')}</span>`}
            ${isDuplicate ? `<div class="small text-danger">Another pack size shares this name and weight — check the SKU (${escapeHtml(m.sku)}) before picking one.</div>` : ''}
          </span>
          <span class="small">${formatMoney(m.selling_price)}</span>
        </button>`;
      }).join('');

      resultsBox.querySelectorAll('[data-result]').forEach((button) => {
        button.addEventListener('click', () => {
          const match = matches[Number(button.dataset.result)];

          if (match.product_status !== 'published') {
            toast('This item is not published, so it cannot be sold. Ask an administrator to publish it first.', 'danger');
            return;
          }

          addVariantToCart(match);
        });
      });
    } catch {
      // A search hiccup shouldn't interrupt typing — the exact-code path
      // (scan, or Enter) still works regardless.
    }
  };

  container.querySelector('[data-sku-lookup]').addEventListener('click', addByExactCode);
  skuInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      addByExactCode();
    }
  });
  skuInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const text = skuInput.value.trim();

    if (text === '') { clearResults(); return; }

    searchTimer = setTimeout(() => searchByName(text), 300);
  });

  container.querySelector('[data-shop-label]').addEventListener('input', (event) => {
    shopLabel = event.target.value;
    try { sessionStorage.setItem('till_shop_label', shopLabel); } catch { /* remembered value is a convenience only */ }
  });

  // A mobile number field: digits only, capped at 10 — strips letters/symbols
  // as they're typed rather than only rejecting on submit.
  const digitsOnly = (event) => {
    event.target.value = event.target.value.replace(/\D/g, '').slice(0, 10);
  };
  container.querySelector('[data-customer-mobile]').addEventListener('input', digitsOnly);
  container.querySelector('[name=walk_in_mobile]').addEventListener('input', (event) => {
    digitsOnly(event);
    updateChange(container);
  });

  /** Shared by a successful lookup and a freshly quick-added customer. */
  const attachCustomer = async (customer, resultBox) => {
    selectedCustomer = customer;
    walletApplied = 0;

    // Best-effort: shows what this customer already owes so the cashier
    // sees it before deciding to add more debt. Not fatal if it fails — the
    // sale itself does not depend on this figure.
    try {
      const dues = await api.get(`/admin/customers/${encodeURIComponent(selectedCustomer.uuid)}/dues`);
      selectedCustomer.totalOutstanding = dues.data.total_outstanding;
    } catch { /* the "already owes" line just stays hidden */ }

    container.querySelector('[data-walkin-fields]').hidden = true;
    resultBox.innerHTML = customerCardMarkup();
    bindCustomerCard(container, resultBox);
    updateCartSummary(container);
  };

  /**
   * Most shoppers a small store extends credit to have never signed up on
   * the website or app, so a lookup that only ever says "not found" makes
   * Accept Partial Payment useless for exactly the people it's for. This
   * inline form quick-registers them (name + mobile only) and attaches the
   * new account the same way a successful lookup would.
   */
  const quickAddForm = (mobile, resultBox) => {
    resultBox.innerHTML = `
      <div class="alert alert-warning py-2 mb-0">
        <div class="small mb-2">No customer account has that mobile number yet. Add them now so this sale (and any future due payment) can be tracked against them.</div>
        <div class="input-group input-group-sm">
          <input class="form-control" data-quick-add-name placeholder="Customer's name" maxlength="120">
          <button class="btn btn-dark" type="button" data-quick-add-submit>Add &amp; attach</button>
        </div>
      </div>`;

    const nameInput = resultBox.querySelector('[data-quick-add-name]');
    const submit = async () => {
      const fullName = nameInput.value.trim();
      if (fullName.length < 2) { toast('Enter the customer’s name first.', 'danger'); return; }

      setBusy(submit.button, true, 'Adding');

      try {
        const response = await api.post('/admin/pos/customers', { full_name: fullName, mobile });
        await attachCustomer(response.data, resultBox);
      } catch (error) {
        setBusy(submit.button, false);
        showError(error, resultBox);
      }
    };

    const submitButton = resultBox.querySelector('[data-quick-add-submit]');
    submit.button = submitButton;
    submitButton.addEventListener('click', submit);
    nameInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); submit(); }
    });
    nameInput.focus();
  };

  const findCustomer = async () => {
    const mobile = container.querySelector('[data-customer-mobile]').value.trim();
    if (!mobile) return;

    const resultBox = container.querySelector('[data-customer-result]');
    resultBox.innerHTML = '<div class="small text-muted">Looking up…</div>';

    try {
      const response = await api.get('/admin/pos/customers', { mobile });
      await attachCustomer(response.data, resultBox);
    } catch (error) {
      selectedCustomer = null;

      if (error.status === 404) {
        quickAddForm(mobile, resultBox);
      } else {
        showError(error, resultBox);
      }
    }
  };

  container.querySelector('[data-find-customer]').addEventListener('click', findCustomer);
  container.querySelector('[data-customer-mobile]').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); findCustomer(); }
  });

  container.querySelector('[data-wallet-input]').addEventListener('input', (event) => {
    const cap = Math.min(totals().grandTotal, Number(selectedCustomer?.wallet.balance) || 0);
    walletApplied = Math.max(0, Math.min(round2(Number(event.target.value) || 0), cap));
    updateCartSummary(container);
  });

  container.querySelector('[name=payment_method]').addEventListener('change', () => updateChange(container));
  container.querySelector('[name=amount_tendered]').addEventListener('input', () => updateChange(container));

  container.querySelector('[data-complete-sale]').addEventListener('click', async (event) => {
    if (cart.length === 0) {
      toast('Add at least one item first.', 'danger');
      return;
    }

    if (!defaultWarehouseUuid) {
      toast('No warehouse is set up yet — add one on the Warehouses screen first.', 'danger');
      return;
    }

    const paymentMethod = container.querySelector('[name=payment_method]').value;
    const rawTendered = container.querySelector('[name=amount_tendered]').value;
    const walkInMobileValue = container.querySelector('[name=walk_in_mobile]').value.trim();

    if (paymentMethod === 'cash' && rawTendered === '' && remainderDue() > 0.001) {
      toast('Enter the amount tendered.', 'danger');
      return;
    }

    // Whatever the cashier typed, what's actually collected in cash is
    // rounded to the nearest rupee — this is what gets recorded and what
    // change/balance was calculated from, so the receipt and the till drawer
    // always agree. An empty field for a non-cash method still means "paid
    // in full by that method", exactly as before this feature existed.
    const amountTendered = rawTendered === '' ? null : (paymentMethod === 'cash' ? roundToRupee(rawTendered) : (Number(rawTendered) || 0));
    const shortBeforeSubmit = amountTendered === null ? 0 : round2(remainderDue() - amountTendered);
    const acceptPartial = shortBeforeSubmit > 0.005;

    if (acceptPartial && !selectedCustomer && !walkInMobileValue) {
      toast('Enter the walk-in mobile number so there is someone to collect the rest from later.', 'danger');
      return;
    }

    const button = event.currentTarget;

    setBusy(button, true, 'Saving');

    try {
      const response = await api.post('/admin/pos/sales', {
        warehouse_uuid: defaultWarehouseUuid,
        customer_uuid: selectedCustomer?.uuid || null,
        walk_in_name: selectedCustomer ? null : (container.querySelector('[name=walk_in_name]').value || null),
        walk_in_mobile: selectedCustomer ? null : (container.querySelector('[name=walk_in_mobile]').value || null),
        payment_method: paymentMethod,
        amount_tendered: amountTendered,
        accept_partial: acceptPartial,
        delivered: container.querySelector('[name=delivery]').value === 'delivered',
        shop_label: shopLabel.trim() || null,
        wallet_applied: walletApplied > 0 ? walletApplied : null,
        lines: cart.map((l) => ({
          variant_uuid: l.variant_uuid,
          quantity: l.quantity,
          unit_price: l.unit_price,
          discount_amount: l.discount_amount || 0,
          applied_offer_code: l.applied_offer_code || null,
        })),
      });

      toast('Sale completed.');
      renderReceipt(container.querySelector('[data-receipt]'), response.data);
      cart = [];
      selectedCustomer = null;
      walletApplied = 0;
      container.querySelector('[data-customer-result]').innerHTML = '';
      container.querySelector('[data-walkin-fields]').hidden = false;
      renderCart(container);
    } catch (error) {
      setBusy(button, false);
      showError(error);
    } finally {
      setBusy(button, false);
    }
  });
}

function renderReceipt(host, sale) {
  host.innerHTML = `
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start no-print mb-2">
          <h2 class="h6 mb-0">Receipt — ${escapeHtml(sale.sale_number)}</h2>
          <button class="btn btn-sm btn-outline-secondary" data-print>Print</button>
        </div>
        <p class="small text-muted mb-2">
          ${escapeHtml(String(sale.created_date || '').slice(0, 16).replace('T', ' '))}
          ${sale.shop_label ? ` · ${escapeHtml(sale.shop_label)}` : ''}
        </p>
        <div class="table-responsive">
          <table class="table table-tight table-sm mb-2">
            <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
              ${sale.items.map((item) => `
                <tr>
                  <td>${escapeHtml(item.product_name)} <span class="text-muted">(${escapeHtml(item.variant_name)})</span></td>
                  <td class="text-end">${escapeHtml(Number(item.quantity))}</td>
                  <td class="text-end">${formatMoney(item.line_total)}</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
        <dl class="row small mb-0">
          <dt class="col-8">Subtotal</dt><dd class="col-4 text-end">${formatMoney(sale.subtotal)}</dd>
          <dt class="col-8">Discount</dt><dd class="col-4 text-end">${formatMoney(sale.discount_amount)}</dd>
          <dt class="col-8">Tax (included)</dt><dd class="col-4 text-end">${formatMoney(sale.tax_amount)}</dd>
          <dt class="col-8 fw-semibold">Total</dt><dd class="col-4 text-end fw-semibold">${formatMoney(sale.grand_total)}</dd>
          ${Number(sale.wallet_applied) > 0 ? `
            <dt class="col-8">From wallet</dt><dd class="col-4 text-end">−${formatMoney(sale.wallet_applied)}</dd>
            <dt class="col-8">Amount due</dt><dd class="col-4 text-end">${formatMoney(Number(sale.grand_total) - Number(sale.wallet_applied))}</dd>` : ''}
          ${sale.payment_method === 'cash' && !sale.is_credit_sale ? `
            <dt class="col-8">Tendered</dt><dd class="col-4 text-end">${formatMoney(sale.amount_tendered)}</dd>
            <dt class="col-8">Change</dt><dd class="col-4 text-end">${formatMoney(sale.change_due)}</dd>` : ''}
        </dl>
        ${sale.is_credit_sale ? `
          <div class="alert ${sale.payment_status === 'paid' ? 'alert-success' : 'alert-warning'} small mt-2 mb-0 no-print">
            <div>Paid so far: <strong>${formatMoney(sale.amount_paid)}</strong></div>
            <div>Balance still due: <strong>${formatMoney(Number(sale.grand_total) - Number(sale.amount_paid))}</strong></div>
            <a href="customer-dues.html" target="_blank">Record a payment on this later →</a>
          </div>` : ''}
      </div>
    </div>`;

  host.querySelector('[data-print]').addEventListener('click', () => window.print());
}

function historyRow(sale) {
  return `
    <tr>
      <td>
        <a href="till.html?tab=history&uuid=${encodeURIComponent(sale.uuid)}" class="text-decoration-none fw-semibold">${escapeHtml(sale.sale_number)}</a>
        <div class="small text-muted">${escapeHtml(String(sale.created_date || '').slice(0, 16).replace('T', ' '))} · ${escapeHtml(sale.cashier_name)}${sale.shop_label ? ' · ' + escapeHtml(sale.shop_label) : ''}</div>
      </td>
      <td class="small text-uppercase">${escapeHtml(sale.payment_method)}</td>
      <td>${sale.status === 'completed'
        ? '<span class="badge text-bg-success">Completed</span>'
        : '<span class="badge text-bg-secondary">Voided</span>'}
        ${sale.status === 'completed' && sale.delivery_status === 'pending'
          ? '<span class="badge text-bg-warning ms-1">Not delivered</span>' : ''}</td>
      <td class="text-end">${formatMoney(sale.grand_total)}</td>
    </tr>`;
}

async function renderHistoryDetail(container, uuid) {
  try {
    const response = await api.get(`/admin/pos/sales/${encodeURIComponent(uuid)}`);
    const sale = response.data;

    container.innerHTML = `<a class="small text-decoration-none" href="till.html?tab=history">← Sales</a><div class="mt-2" data-receipt-host></div>`;
    renderReceipt(container.querySelector('[data-receipt-host]'), sale);

    const host = container.querySelector('[data-receipt-host]');
    const actions = document.createElement('div');
    actions.className = 'd-flex gap-2 mt-3 no-print';

    if (sale.status === 'completed') {
      actions.innerHTML = `
        ${sale.delivery_status === 'pending'
          ? '<span class="badge text-bg-warning align-self-center">Not delivered yet</span><button class="btn btn-sm btn-success" data-deliver>Mark as delivered</button>'
          : `<span class="badge text-bg-success align-self-center">Delivered${sale.delivered_date ? ' ' + escapeHtml(String(sale.delivered_date).slice(0, 16).replace('T', ' ')) : ''}</span>`}
        <button class="btn btn-sm btn-outline-danger" data-void>Void sale</button>
        <button class="btn btn-sm btn-outline-warning" data-refund>Refund a line</button>`;
    }

    host.appendChild(actions);

    const deliverBtn = actions.querySelector('[data-deliver]');
    if (deliverBtn) {
      deliverBtn.addEventListener('click', async () => {
        setBusy(deliverBtn, true, 'Saving');

        try {
          await api.post(`/admin/pos/sales/${encodeURIComponent(uuid)}/deliver`, {});
          toast('Marked as delivered.');
          renderHistoryDetail(container, uuid);
        } catch (error) {
          setBusy(deliverBtn, false);
          showError(error);
        }
      });
    }

    const voidBtn = actions.querySelector('[data-void]');
    if (voidBtn) {
      voidBtn.addEventListener('click', async () => {
        const reason = window.prompt('Reason for voiding this sale?');
        if (!reason) return;

        try {
          await api.post(`/admin/pos/sales/${encodeURIComponent(uuid)}/void`, { reason });
          toast('Sale voided.');
          renderHistoryDetail(container, uuid);
        } catch (error) {
          showError(error);
        }
      });
    }

    const refundBtn = actions.querySelector('[data-refund]');
    if (refundBtn) {
      refundBtn.addEventListener('click', async () => {
        const skuList = sale.items.map((i, idx) => `${idx + 1}. ${i.sku} (sold ${Number(i.quantity)}, refunded ${Number(i.refunded_quantity)})`).join('\n');
        const choice = window.prompt(`Which line number to refund?\n${skuList}`);
        const index = Number(choice) - 1;
        if (!sale.items[index]) return;

        const qty = window.prompt('Quantity to refund?', '1');
        if (!qty) return;

        const reason = window.prompt('Reason for the refund?');
        if (!reason) return;

        // Only a sale linked to a registered customer has anywhere to credit
        // a wallet refund to — a walk-in always gets cash/card handed back.
        const refundMethod = sale.customer_id
          && window.confirm('Credit this refund to the customer’s wallet instead of handing it back at the counter?\n\nOK = wallet, Cancel = handed back as usual.')
          ? 'wallet'
          : 'original';

        try {
          await api.post(`/admin/pos/sales/${encodeURIComponent(uuid)}/refund`, {
            reason,
            refund_method: refundMethod,
            items: [{ pos_sale_item_uuid: sale.items[index].uuid, quantity: qty }],
          });
          toast(refundMethod === 'wallet' ? 'Refund credited to the customer’s wallet.' : 'Refund recorded.');
          renderHistoryDetail(container, uuid);
        } catch (error) {
          showError(error);
        }
      });
    }
  } catch (error) {
    container.innerHTML = '<a class="small" href="till.html?tab=history">← Sales</a>';
    showError(error, container);
  }
}

/** Best-effort only — a cashier-only account can't read /admin/reports/pos ($supervisory), so a 403 here just means no stat row, not a broken page. */
async function renderTodayStats(container) {
  const host = container.querySelector('[data-pos-stats]');
  if (!host) return;

  try {
    const today = new Date().toISOString().slice(0, 10);
    const isAdmin = String(tillUser.role) === 'administrator';
    let saleCount = 0;
    let revenue = 0;

    const voidedResponse = await api.get('/admin/pos/sales', { status: 'voided', from: today, to: today, per_page: 1 });

    if (isAdmin) {
      // Everyone's sales, all cashiers together.
      const byCashier = (await api.get('/admin/reports/pos', { from: today, to: today })).data.by_cashier || [];
      saleCount = byCashier.reduce((sum, row) => sum + Number(row.sale_count || 0), 0);
      revenue = byCashier.reduce((sum, row) => sum + Number(row.gross_sales || 0), 0);
    } else {
      // Only the signed-in person's own sales (the API scopes the list to them).
      for (let page = 1; page <= 20; page += 1) {
        const response = await api.get('/admin/pos/sales', { status: 'completed', from: today, to: today, per_page: 50, page });
        (response.data || []).forEach((sale) => { saleCount += 1; revenue += Number(sale.grand_total || 0); });
        if (page >= (response.meta?.total_pages ?? 1)) break;
      }
    }

    const voidedCount = voidedResponse.meta?.total ?? 0;

    host.innerHTML = `
      <div class="row row-cols-2 row-cols-lg-3 g-3 mb-3">
        ${iconStatCard({ tone: 'var(--forest)', iconSvgPaths: ICONS.cart, label: isAdmin ? "Today's sales" : 'Your sales today', value: escapeHtml(saleCount) })}
        ${iconStatCard({ tone: 'var(--gold-dark)', iconSvgPaths: ICONS.rupee, label: isAdmin ? "Today's takings" : 'Your takings today', value: formatMoney(revenue) })}
        ${iconStatCard({ tone: voidedCount > 0 ? '#A6291F' : 'var(--muted-2)', iconSvgPaths: ICONS.voided, label: 'Voided today', value: escapeHtml(voidedCount), hint: 'Excluded from takings above' })}
      </div>`;
  } catch {
    host.innerHTML = '';
  }
}

async function renderHistoryTab(container) {
  const uuid = queryParam('uuid');

  if (uuid) {
    await renderHistoryDetail(container, uuid);
    return;
  }

  container.innerHTML = `
    <div data-pos-stats></div>
    <div class="card"><div class="card-body p-0" data-list>
    <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
  </div></div>`;

  renderTodayStats(container);

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/pos/sales', { ...state.history, per_page: 30, direction: 'DESC' });
    const rows = response.data || [];

    if (rows.length === 0) {
      list.innerHTML = emptyState('No sales yet', 'Use the Sell tab to ring up your first sale.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Sale</th><th>Payment</th><th>Status</th><th class="text-end">Total</th></tr></thead>
          <tbody>${rows.map(historyRow).join('')}</tbody>
        </table>
      </div>`;
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

/**
 * The till's own sign-in — deliberately NOT the shared mountConsole() flow.
 * mountConsole() treats an existing console session as sufficient; that is
 * exactly what would let anyone already signed into the console (an admin
 * setting up products, say) walk straight into the till because the browser
 * happens to hold a valid token. Here, whoever wants to open the till —
 * admin included — must type a mobile/email AND password of their own, every
 * time this tab hasn't already done so.
 *
 * The identifier is not pre-filled from any existing session, and the login
 * that succeeds becomes the till's session (storeTokens IS called, unlike a
 * simple password re-check) — so a cashier can sign into the till with their
 * own account even while an admin's console session is sitting open in
 * another tab of the same browser.
 *
 * Cached in sessionStorage (per-tab, cleared on sign-out and when the tab
 * closes) so switching between the Sell and Sales history tabs — each a full
 * page navigation — doesn't re-prompt every click; a shift change or a
 * closed tab does.
 *
 * @returns {Promise<object>} the signed-in user
 */
async function requireTillSignIn() {
  const cached = sessionStorage.getItem('till_session_user');

  if (cached) {
    try {
      const user = JSON.parse(cached);

      // The name on screen must be the account the requests really run as.
      // If the saved login is missing or belongs to someone else, sign in again.
      const me = (await api.get('/auth/me')).data.user;
      if (me && me.uuid === user.uuid) return user;
    } catch { /* fall through to a fresh sign-in */ }

    sessionStorage.removeItem('till_session_user');
  }

  const shell = document.querySelector('[data-console]');
  const allowedRoles = ['administrator', 'supervisor', 'manager', 'cashier'];

  return new Promise((resolve) => {
    function renderForm(message) {
      shell.innerHTML = `
        <div class="d-flex align-items-center justify-content-center min-vh-100">
          <div class="card shadow-sm" style="width:min(24rem,92vw)">
            <div class="card-body p-4">
              <h1 class="h5 mb-1">Till sign-in</h1>
              <p class="text-muted small">Separate from the console — sign in with your own staff
                login, whoever you are, to open the till.</p>
              ${message ? `<div class="alert alert-warning small">${escapeHtml(message)}</div>` : ''}
              <form data-till-signin>
                <div class="mb-3">
                  <label class="form-label" for="till-identifier">Mobile or email</label>
                  <input class="form-control" id="till-identifier" name="identifier" required autocomplete="username">
                </div>
                <div class="mb-3">
                  <label class="form-label" for="till-password">Password</label>
                  <input class="form-control" id="till-password" name="password" type="password" required
                         autocomplete="current-password">
                </div>
                <button class="btn btn-dark w-100" type="submit">Sign in</button>
              </form>
            </div>
          </div>
        </div>`;

      shell.querySelector('[data-till-signin]').addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = event.currentTarget.querySelector('button');
        setBusy(button, true, 'Signing in');

        try {
          const login = await api.post('/auth/login',
            Object.fromEntries(new FormData(event.currentTarget).entries()));
          storeTokens(login.data.tokens);

          const me = await api.get('/auth/me');
          const user = me.data.user;

          if (!allowedRoles.includes(String(user.role))) {
            clearTokens();
            setBusy(button, false);
            renderForm('That account cannot open the till.');
            return;
          }

          sessionStorage.setItem('till_session_user', JSON.stringify(user));
          resolve(user);
        } catch (error) {
          setBusy(button, false);
          renderForm((error && error.message) || 'Sign-in failed.');
        }
      });
    }

    renderForm();
  });
}

function renderTabs() {
  return `
    <ul class="nav nav-tabs mb-3 no-print">
      <li class="nav-item"><a class="nav-link ${state.tab === 'sell' ? 'active' : ''}" href="till.html?tab=sell">Sell</a></li>
      <li class="nav-item"><a class="nav-link ${state.tab === 'history' ? 'active' : ''}" href="till.html?tab=history">Sales history</a></li>
    </ul>`;
}

async function render() {
  root.innerHTML = `<h1 class="h4 mb-3 no-print">Point of Sale</h1>${renderTabs()}<div data-tab-body></div>`;
  const body = root.querySelector('[data-tab-body]');

  if (state.tab === 'history') {
    await renderHistoryTab(body);
  } else {
    await renderSellTab(body);
  }
}

const tillUser = await requireTillSignIn();

renderMinimalChrome(tillUser);
root = document.querySelector('[data-page-root]');

try {
  const response = await api.get('/admin/warehouses', { active_only: true });
  warehouses = response.data || [];
  // Which warehouse stock is deducted from is resolved silently — the
  // cashier is never asked to pick one; "Shop name" below is a free-text
  // label for the receipt, not a warehouse choice.
  defaultWarehouseUuid = (warehouses.find((w) => w.is_default) || warehouses[0] || {}).uuid || null;

  try {
    shopLabel = sessionStorage.getItem('till_shop_label') || '';
  } catch { /* starts blank */ }

  render();
} catch (error) {
  showError(error, root);
}
