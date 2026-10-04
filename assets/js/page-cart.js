/** The cart: quantities, coupon, wallet, and the blockers that stop checkout. */

import { api } from './api.js';
import { mountChrome, mountFooter, refreshCartCount, escapeHtml, formatMoney,
         showError, toast, setBusy } from './ui.js';

const root = document.querySelector('[data-page-root]');

function offerCategoryLabel(offer) {
  return offer.discount_type === 'free_items' ? 'Combo offer' : 'Special price';
}

function line(item, offer) {
  return `
    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <div class="flex-grow-1">
          <div class="fw-semibold">${escapeHtml(item.product.name)}</div>
          <div class="small text-muted">${escapeHtml(item.variant.name)} · ${escapeHtml(item.variant.sku)}</div>
          ${offer ? `
            <div class="d-flex align-items-center gap-2 mt-1">
              <span class="tag tag--offer text-uppercase">${escapeHtml(offerCategoryLabel(offer))}</span>
              <span class="small" title="${escapeHtml(offer.title)}">${escapeHtml(offer.summary)}</span>
            </div>` : ''}
          ${item.is_purchasable
            ? ''
            : `<div class="badge text-bg-warning mt-1">${escapeHtml(item.unavailable_reason || 'Unavailable')}</div>`}
          ${item.price_changed
            ? `<div class="small text-danger mt-1">Price changed since you added this.</div>`
            : ''}
        </div>

        <div class="d-flex align-items-center gap-2">
          <label class="visually-hidden" for="qty-${escapeHtml(item.uuid)}">Quantity</label>
          <input class="form-control" id="qty-${escapeHtml(item.uuid)}" type="number" min="0" max="${escapeHtml(item.variant.max_order_quantity || 500)}"
                 value="${escapeHtml(item.quantity)}" style="width:5.5rem"
                 data-quantity-for="${escapeHtml(item.uuid)}">
          <div class="text-end" style="min-width:6rem">
            <div class="fw-semibold">${formatMoney(item.line_total)}</div>
            <div class="small text-muted">${formatMoney(item.unit_price)} each</div>
          </div>
          <button class="btn btn-outline-secondary btn-sm" data-remove="${escapeHtml(item.uuid)}" type="button">Remove</button>
        </div>
      </div>
    </div>`;
}

/**
 * Which blockers actually prevent checkout, and which are just missing detail.
 *
 * THE PINCODE ONE IS NOT FATAL. Choosing a delivery address on the checkout page
 * supplies the pincode, and the server then reports `is_ready: true`. Treating
 * every blocker as fatal turned "we cannot show you the delivery charge yet"
 * into a dead end: the Checkout button was disabled and there was no way past
 * it, even though the server would happily have taken the order.
 *
 * A pincode in the cart is a convenience — it lets someone see the delivery
 * charge before signing in. It is not a second address form.
 */
function classifyBlockers(blockers) {
  const soft = [];
  const hard = [];

  blockers.forEach((blocker) => {
    const text = String(blocker).toLowerCase();

    // Resolved by picking an address at checkout.
    if (text.includes('enter a delivery pincode')) {
      soft.push(blocker);
      return;
    }

    hard.push(blocker);
  });

  return { soft, hard };
}

function summary(cart) {
  const pricing = cart.pricing.summary;
  const payment = cart.checkout || cart.payment || {};
  const allBlockers = (cart.checkout && cart.checkout.blockers) || [];
  const { soft, hard } = classifyBlockers(allBlockers);
  const blockers = hard;
  const delivery = cart.pricing.delivery || {};

  return `
    <div class="card sticky-top" style="top:1rem">
      <div class="card-body">
        <h2 class="h6 mb-3">Order summary</h2>

        <dl class="row small mb-2">
          <dt class="col-7 fw-normal">Items</dt>
          <dd class="col-5 text-end">${formatMoney(pricing.items_subtotal)}</dd>

          ${Number(pricing.order_discount) > 0 ? `
            <dt class="col-7 fw-normal text-success">
              ${cart.promotions.applied_offer ? escapeHtml(cart.promotions.applied_offer.title) : 'Discount'}
            </dt>
            <dd class="col-5 text-end text-success">−${formatMoney(pricing.order_discount)}</dd>` : ''}

          ${cart.promotions.applied_offer ? `
            <dd class="col-12 small text-muted mt-n1 mb-0">${escapeHtml(cart.promotions.applied_offer.summary)}</dd>` : ''}

          <dt class="col-7 fw-normal">Delivery</dt>
          <dd class="col-5 text-end">${Number(pricing.delivery_charge) === 0
            ? '<span class="text-success">Free</span>'
            : formatMoney(pricing.delivery_charge)}</dd>
        </dl>

        <hr>
        <div class="d-flex justify-content-between fw-semibold">
          <span>Total</span><span>${formatMoney(pricing.grand_total)}</span>
        </div>
        <div class="small text-muted mb-2">Includes ${formatMoney(pricing.tax_total)} GST</div>

        ${Number(pricing.total_savings) > 0 ? `
          <div class="small fw-semibold mb-3" style="color:var(--forest)">
            You saved ${formatMoney(pricing.total_savings)} on this order
          </div>` : ''}

        ${Number(payment.wallet_applied || 0) > 0 ? `
          <div class="d-flex justify-content-between small text-success">
            <span>Wallet credit</span><span>−${formatMoney(payment.wallet_applied)}</span>
          </div>
          <div class="d-flex justify-content-between fw-semibold mt-1">
            <span>To pay</span><span>${formatMoney(payment.amount_payable)}</span>
          </div>` : ''}

        <form class="mt-3" data-pincode-form>
          <label class="form-label small mb-1" for="pincode">Delivery pincode</label>
          <div class="input-group input-group-sm">
            <input class="form-control" id="pincode" name="pincode" inputmode="numeric"
                   maxlength="6" pattern="\\d{6}" placeholder="560001"
                   value="${escapeHtml(delivery.pincode || '')}">
            <button class="btn btn-outline-secondary" type="submit">Check</button>
          </div>
          ${delivery.pincode && delivery.is_serviceable === false
            ? '<div class="form-text text-danger">We do not deliver to that pincode yet.</div>'
            : (delivery.estimated_days
                ? `<div class="form-text text-success">
                     Delivers in ${escapeHtml(delivery.estimated_days.min)}–${escapeHtml(delivery.estimated_days.max)} days
                   </div>`
                : `<div class="form-text">
                     Optional — enter it to see the delivery charge now. You can also
                     just continue and pick your address at checkout.
                   </div>`)}
        </form>

        ${blockers.length ? `
          <div class="alert alert-warning small mt-3 mb-0">
            <div class="fw-semibold mb-1">Before you can check out</div>
            <ul class="mb-0 ps-3 blocker-list">
              ${blockers.map((b) => `<li>${escapeHtml(b)}</li>`).join('')}
            </ul>
          </div>` : ''}

        ${soft.length && !blockers.length ? `
          <div class="small text-muted mt-3">
            Delivery is worked out once we know where it is going.
          </div>` : ''}

        <a class="btn btn-spice w-100 mt-3 ${blockers.length ? 'disabled' : ''}"
           href="checkout.html" ${blockers.length ? 'aria-disabled="true" tabindex="-1"' : ''}>
          Checkout
        </a>
        <div class="text-center small text-muted mt-2">Prepaid UPI only</div>
      </div>
    </div>`;
}

async function render() {
  try {
    const response = await api.get('/cart');
    const cart = response.data;
    const items = (cart.items || []).filter((item) => !item.is_saved_for_later);

    if (items.length === 0) {
      root.innerHTML = `
        <div class="text-center py-5">
          <h1 class="h4">Your cart is empty</h1>
          <p class="text-muted">Nothing added yet.</p>
          <a class="btn btn-spice" href="index.html">Browse the shop</a>
        </div>`;
      refreshCartCount();
      return;
    }

    // Best effort: a matching product-targeted offer, shown right on that
    // item's line — separate from `cart.promotions.applied_offer`, which is
    // the one cart-wide automatic discount the resolver picked. A product can
    // carry a live offer that didn't end up "best" for this cart and still be
    // worth showing here.
    let offersByProduct = {};
    try {
      const uuids = [...new Set(items.map((item) => item.product.uuid))];
      const lookup = await api.get('/offers/product-lookup', { product_uuids: uuids.join(',') });
      offersByProduct = lookup.data || {};
    } catch { /* no per-item badges this time */ }

    root.innerHTML = `
      <h1 class="h4 mb-4">Your cart</h1>
      <div class="row g-4">
        <div class="col-12 col-lg-8">
          ${items.map((item) => line(item, offersByProduct[item.product.uuid])).join('')}

          <div class="card">
            <div class="card-body">
              <form class="row g-2 align-items-end" data-coupon-form>
                <div class="col">
                  <label class="form-label small mb-1" for="coupon">Coupon code</label>
                  <input class="form-control" id="coupon" name="coupon_code"
                         value="${escapeHtml((cart.promotions.applied_coupon || {}).code || '')}"
                         placeholder="e.g. WELCOME10">
                </div>
                <div class="col-auto">
                  <button class="btn btn-outline-secondary" type="submit">Apply</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-4">${summary(cart)}</div>
      </div>`;

    root.querySelectorAll('[data-quantity-for]').forEach((input) => {
      input.addEventListener('change', async () => {
        const quantity = Number(input.value);
        try {
          if (quantity <= 0) {
            await api.delete(`/cart/items/${input.dataset.quantityFor}`);
          } else {
            await api.patch(`/cart/items/${input.dataset.quantityFor}`, { quantity });
          }
          render();
          refreshCartCount();
        } catch (error) {
          showError(error);
          render();
        }
      });
    });

    root.querySelectorAll('[data-remove]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Removing');
        try {
          await api.delete(`/cart/items/${button.dataset.remove}`);
          toast('Removed from your cart.');
          render();
          refreshCartCount();
        } catch (error) {
          showError(error);
          setBusy(button, false);
        }
      });
    });

    const pincodeForm = root.querySelector('[data-pincode-form]');

    pincodeForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = pincodeForm.querySelector('button');
      const pincode = new FormData(pincodeForm).get('pincode');

      if (!/^\d{6}$/.test(String(pincode || ''))) {
        toast('An Indian pincode is six digits.', 'danger');
        return;
      }

      setBusy(button, true, '…');

      try {
        await api.post('/cart/pincode', { pincode });
        // Re-render rather than patching in place: the delivery charge, the
        // free-delivery threshold and the total can all move together.
        render();
      } catch (error) {
        showError(error);
        setBusy(button, false);
      }
    });

    const couponForm = root.querySelector('[data-coupon-form]');
    couponForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const code = new FormData(couponForm).get('coupon_code');
      const button = couponForm.querySelector('button');
      setBusy(button, true, 'Applying');

      try {
        if (!code) {
          await api.delete('/cart/coupon');
          toast('Coupon removed.');
        } else {
          const result = await api.post('/cart/coupon', { coupon_code: code });
          toast(result.message || 'Coupon applied.');
        }
        render();
      } catch (error) {
        showError(error);
        setBusy(button, false);
      }
    });
  } catch (error) {
    root.innerHTML = '';
    showError(error, root);
  }
}

mountChrome('cart.html');
mountFooter();
render();
