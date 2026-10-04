/**
 * Pricing: the brief's §7/§8 pricing-strategy engine.
 *
 * Two independent pieces on one page: the price-change-mode setting (whether
 * / when a purchase inward should prompt for a selling-price decision), and
 * the pricing rules themselves (markup/margin by category, product, variant
 * or globally) that both that prompt and 'auto_apply' mode compute from.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney, queryParam,
         emptyState } from './console.js?v=9';

let root = null;
let categories = [];

const MODE_LABELS = {
  always_ask: 'Always ask',
  ask_on_increase: 'Ask only when price increases',
  ask_on_decrease: 'Ask only when price decreases',
  auto_apply: 'Automatically apply the pricing rule',
  never: 'Never change selling price automatically',
};

function targetLabel(rule) {
  if (rule.scope === 'global') return 'All products';
  if (rule.scope === 'category') return `Category: ${rule.category_name || '—'}`;
  if (rule.scope === 'product') return `Product: ${rule.product_name || '—'}`;
  return `Pack size: ${rule.variant_sku || '—'} (${rule.variant_name || ''})`;
}

function ruleRow(rule) {
  const calc = rule.calculation === 'markup_percent' ? 'Markup' : 'Margin';

  return `
    <tr data-rule="${escapeHtml(rule.uuid)}">
      <td>
        <span class="fw-semibold">${escapeHtml(rule.name)}</span>
        <div class="small text-muted">${escapeHtml(targetLabel(rule))}</div>
      </td>
      <td class="small">${escapeHtml(calc)} ${escapeHtml(Number(rule.rate))}% (${escapeHtml(rule.tax_mode)})</td>
      <td class="text-center small">${escapeHtml(rule.priority)}</td>
      <td>${rule.status === 'active'
        ? '<span class="badge text-bg-success">Active</span>'
        : '<span class="badge text-bg-secondary">Inactive</span>'}</td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="pricing.html?edit=${encodeURIComponent(rule.uuid)}">Edit</a>
        ${rule.status === 'active' ? `<button class="btn btn-sm btn-outline-danger" data-deactivate>Deactivate</button>` : ''}
      </td>
    </tr>`;
}

async function renderModeCard() {
  let settings;

  try {
    const response = await api.get('/admin/settings');
    settings = response.data;
  } catch (error) {
    return `<div class="alert alert-danger">Could not load the price-change setting.</div>`;
  }

  const options = (settings.inventory_price_change_mode_options || Object.keys(MODE_LABELS))
    .map((mode) => `
      <option value="${escapeHtml(mode)}" ${mode === settings.inventory_price_change_mode ? 'selected' : ''}>
        ${escapeHtml(MODE_LABELS[mode] || mode)}
      </option>`).join('');

  return `
    <div class="card mb-4"><div class="card-body">
      <h2 class="h6">Price-change behaviour</h2>
      <p class="small text-muted">
        What happens to a pack size's selling price when a purchase changes its cost.
      </p>
      <form class="d-flex flex-wrap gap-2 align-items-center" data-mode-form>
        <select class="form-select form-select-sm" name="mode" style="width:22rem">${options}</select>
        <button class="btn btn-sm btn-dark" type="submit">Save</button>
      </form>
    </div></div>`;
}

async function renderRulesList(container) {
  container.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h2 class="h6 mb-0">Pricing rules</h2>
      <a class="btn btn-sm btn-dark" href="pricing.html?new">Add a rule</a>
    </div>
    <div class="card"><div class="card-body p-0" data-list>
      <div class="text-center py-5 text-muted"><div class="spinner-border"></div></div>
    </div></div>`;

  const list = container.querySelector('[data-list]');

  try {
    const response = await api.get('/admin/pricing/rules');
    const rules = response.data || [];

    if (rules.length === 0) {
      list.innerHTML = emptyState('No pricing rules yet',
        'Without one, "Use average cost" / "Use new price" decisions have nothing to compute — add at least a global default.');
      return;
    }

    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr><th>Rule</th><th>Calculation</th><th class="text-center">Priority</th><th>Status</th><th></th></tr></thead>
          <tbody>${rules.map(ruleRow).join('')}</tbody>
        </table>
      </div>`;

    list.querySelectorAll('[data-deactivate]').forEach((button) => {
      button.addEventListener('click', async () => {
        const uuid = button.closest('[data-rule]').dataset.rule;
        if (!window.confirm('Deactivate this pricing rule?')) return;

        setBusy(button, true, 'Saving');

        try {
          await api.delete(`/admin/pricing/rules/${encodeURIComponent(uuid)}`);
          toast('Pricing rule deactivated.');
          renderRulesList(container);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    list.innerHTML = '';
    showError(error, list);
  }
}

function categoryOptions(selected) {
  return categories.map((c) => `
    <option value="${escapeHtml(c.uuid)}" ${c.uuid === selected ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('');
}

async function renderRuleForm(editUuid) {
  let rule = null;

  if (editUuid) {
    try {
      const response = await api.get('/admin/pricing/rules');
      rule = (response.data || []).find((r) => r.uuid === editUuid) || null;

      if (!rule) throw new Error('That pricing rule was not found.');
    } catch (error) {
      root.innerHTML = '<a class="small" href="pricing.html">← Pricing</a>';
      showError(error, root);
      return;
    }
  }

  const r = rule || {};
  let resolvedTarget = r.scope === 'product'
    ? { uuid: r.product_uuid, label: r.product_name }
    : r.scope === 'variant'
      ? { uuid: r.variant_uuid, label: `${r.variant_sku} — ${r.variant_name}` }
      : null;

  root.innerHTML = `
    <a class="small text-decoration-none" href="pricing.html">← Pricing</a>
    <h1 class="h4 mt-2 mb-3">${rule ? 'Edit pricing rule' : 'Add a pricing rule'}</h1>
    <div class="card"><div class="card-body">
      <form data-form>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" required maxlength="120" value="${escapeHtml(r.name || '')}">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="scope">Applies to</label>
            <select class="form-select" id="scope" name="scope" required>
              <option value="global" ${r.scope === 'global' ? 'selected' : ''}>Everything (global default)</option>
              <option value="category" ${r.scope === 'category' ? 'selected' : ''}>One category</option>
              <option value="product" ${r.scope === 'product' ? 'selected' : ''}>One product</option>
              <option value="variant" ${r.scope === 'variant' ? 'selected' : ''}>One pack size</option>
            </select>
          </div>
        </div>

        <div class="mt-3" data-target-category style="display:none">
          <label class="form-label" for="category_uuid">Category</label>
          <select class="form-select" id="category_uuid" name="category_uuid">
            <option value="">Select a category…</option>${categoryOptions(r.category_uuid)}
          </select>
        </div>

        <div class="mt-3" data-target-sku style="display:none">
          <label class="form-label" for="sku_lookup">SKU (of the product or pack size)</label>
          <div class="input-group" style="max-width:24rem">
            <input class="form-control" id="sku_lookup" placeholder="Type a SKU">
            <button class="btn btn-outline-secondary" type="button" data-sku-lookup>Find</button>
          </div>
          <input type="hidden" name="product_uuid">
          <input type="hidden" name="variant_uuid">
          <div class="small mt-1" data-sku-feedback>
            ${resolvedTarget ? `<span class="text-success">Currently: ${escapeHtml(resolvedTarget.label || '')}</span>` : ''}
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <label class="form-label" for="calculation">Calculation</label>
            <select class="form-select" id="calculation" name="calculation" required>
              <option value="markup_percent" ${r.calculation === 'markup_percent' ? 'selected' : ''}>Markup on cost</option>
              <option value="margin_percent" ${r.calculation === 'margin_percent' ? 'selected' : ''}>Margin on selling price</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="rate">Rate (%)</label>
            <input class="form-control" id="rate" name="rate" type="number" step="0.001" min="0.001" max="999" required
                   value="${escapeHtml(r.rate ?? '')}">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="tax_mode">Tax</label>
            <select class="form-select" id="tax_mode" name="tax_mode">
              <option value="exclusive" ${(r.tax_mode || 'exclusive') === 'exclusive' ? 'selected' : ''}>Add GST on top</option>
              <option value="inclusive" ${r.tax_mode === 'inclusive' ? 'selected' : ''}>Rate already includes GST</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="priority">Priority</label>
            <input class="form-control" id="priority" name="priority" type="number" min="1" max="9999"
                   value="${escapeHtml(r.priority ?? 100)}">
            <div class="form-text">Lower number wins when more than one rule could apply.</div>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-dark" type="submit">Save</button>
          <a class="btn btn-outline-secondary" href="pricing.html">Cancel</a>
        </div>
      </form>
    </div></div>`;

  const form = root.querySelector('[data-form]');
  const scopeSelect = form.querySelector('#scope');
  const categoryField = root.querySelector('[data-target-category]');
  const skuField = root.querySelector('[data-target-sku]');

  function syncScopeFields() {
    categoryField.style.display = scopeSelect.value === 'category' ? '' : 'none';
    skuField.style.display = (scopeSelect.value === 'product' || scopeSelect.value === 'variant') ? '' : 'none';
  }

  scopeSelect.addEventListener('change', syncScopeFields);
  syncScopeFields();

  if (resolvedTarget) {
    form.querySelector(r.scope === 'product' ? '[name=product_uuid]' : '[name=variant_uuid]').value = resolvedTarget.uuid || '';
  }

  const skuInput = root.querySelector('#sku_lookup');
  const skuFeedback = root.querySelector('[data-sku-feedback]');

  root.querySelector('[data-sku-lookup]').addEventListener('click', async () => {
    const sku = skuInput.value.trim();
    if (!sku) return;

    skuFeedback.innerHTML = 'Looking up…';

    try {
      const response = await api.get('/admin/inventory/lookup', { sku });
      const variant = response.data;

      if (scopeSelect.value === 'product') {
        form.querySelector('[name=product_uuid]').value = variant.product_uuid;
        skuFeedback.innerHTML = `<span class="text-success">Found: ${escapeHtml(variant.product_name)}</span>`;
      } else {
        form.querySelector('[name=variant_uuid]').value = variant.uuid;
        skuFeedback.innerHTML = `<span class="text-success">Found: ${escapeHtml(variant.product_name)} — ${escapeHtml(variant.variant_name)}</span>`;
      }
    } catch (error) {
      skuFeedback.innerHTML = `<span class="text-danger">${escapeHtml(error.message || 'No pack size has that SKU.')}</span>`;
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button[type=submit]');
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());

    if (data.scope === 'category' && !data.category_uuid) {
      toast('Select a category first.', 'danger');
      return;
    }

    if (data.scope === 'product' && !data.product_uuid) {
      toast('Look up a SKU to resolve the product first.', 'danger');
      return;
    }

    if (data.scope === 'variant' && !data.variant_uuid) {
      toast('Look up a SKU to resolve the pack size first.', 'danger');
      return;
    }

    setBusy(button, true, 'Saving');

    try {
      if (rule) {
        await api.patch(`/admin/pricing/rules/${encodeURIComponent(rule.uuid)}`, data);
      } else {
        await api.post('/admin/pricing/rules', data);
      }

      toast('Pricing rule saved.');
      window.location.href = 'pricing.html';
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });
}

/**
 * Every pack size's current cost, current price and what its rule computes
 * right now — the live link between purchase inward and the online price.
 * "Out of sync" means a purchase (or a rule edit) has moved what the rule
 * would charge away from what the shop is actually charging; under
 * "Always ask"/"Ask only when..." modes that gap sits open until someone
 * resolves it here or through the purchase-inward prompt itself.
 */
async function renderLivePricing(container) {
  container.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>';

  try {
    const variants = (await api.get('/admin/pricing/live')).data.variants || [];

    if (variants.length === 0) {
      container.innerHTML = emptyState('No pack sizes yet', 'Add products to see their live pricing here.');
      return;
    }

    const outOfSync = variants.filter((v) => v.is_out_of_sync);

    container.innerHTML = `
      ${outOfSync.length > 0 ? `
        <div class="alert alert-warning small mb-3">
          ${outOfSync.length} pack size(s) below have a rule-computed price that no longer matches
          what the shop charges — usually because a purchase came in under "Ask" or "Never" mode
          and nobody has resolved it yet.
        </div>` : ''}
      <div class="table-responsive">
        <table class="table table-tight table-hover mb-0">
          <thead><tr>
            <th>Pack</th><th>Rule</th><th class="text-end">Avg. cost</th>
            <th class="text-end">Last purchase cost</th><th class="text-end">Online price</th>
            <th class="text-end">Rule says</th><th></th>
          </tr></thead>
          <tbody>
            ${variants.map((v) => `
              <tr class="${v.is_out_of_sync ? 'table-warning' : ''}">
                <td>
                  <span class="fw-semibold">${escapeHtml(v.product_name)}</span>
                  <div class="small text-muted">${escapeHtml(v.variant_name)} · ${escapeHtml(v.sku)}</div>
                </td>
                <td class="small">${v.rule_summary ? escapeHtml(v.rule_summary) : '<span class="text-muted">No rule applies</span>'}</td>
                <td class="text-end small">${v.average_cost !== null ? formatMoney(v.average_cost) : '<span class="text-muted">—</span>'}</td>
                <td class="text-end small text-muted">${v.last_purchase_cost !== null ? formatMoney(v.last_purchase_cost) : '—'}</td>
                <td class="text-end fw-semibold">${formatMoney(v.current_price)}</td>
                <td class="text-end ${v.is_out_of_sync ? 'text-warning-emphasis fw-semibold' : 'text-muted'}">
                  ${v.suggested_price !== null ? formatMoney(v.suggested_price) : '—'}
                </td>
                <td class="text-end">
                  ${v.is_out_of_sync ? `<button class="btn btn-sm btn-outline-dark" data-apply-suggested="${escapeHtml(v.variant_uuid)}" data-price="${v.suggested_price}">Use ${formatMoney(v.suggested_price)}</button>` : ''}
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;

    container.querySelectorAll('[data-apply-suggested]').forEach((button) => {
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Saving');

        try {
          await api.post('/admin/pricing/decisions', {
            variant_uuid: button.dataset.applySuggested,
            decision: 'manual',
            manual_price: button.dataset.price,
            reference_type: 'manual',
            reason: 'Applied from the Live pricing view',
          });
          toast('Price updated.');
          renderLivePricing(container);
        } catch (error) {
          setBusy(button, false);
          showError(error);
        }
      });
    });
  } catch (error) {
    container.innerHTML = '';
    showError(error, container);
  }
}

async function render() {
  const modeCardHtml = await renderModeCard();

  root.innerHTML = `
    <h1 class="h4 mb-3">Pricing</h1>
    ${modeCardHtml}

    <div class="card mb-4">
      <div class="card-header bg-white fw-semibold">Live pricing</div>
      <div class="card-body" data-live-pricing></div>
    </div>

    <h2 class="h5 mb-3">Pricing rules</h2>
    <div data-rules-list></div>`;

  renderLivePricing(root.querySelector('[data-live-pricing]'));

  const modeForm = root.querySelector('[data-mode-form]');

  if (modeForm) {
    modeForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = event.currentTarget.querySelector('button[type=submit]');
      const mode = new FormData(event.currentTarget).get('mode');

      setBusy(button, true, 'Saving');

      try {
        await api.patch('/admin/settings/price-change-mode', { mode });
        toast('Price-change behaviour updated.');
        setBusy(button, false);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  }

  await renderRulesList(root.querySelector('[data-rules-list]'));
}

const mounted = await mountConsole('pricing.html');

if (mounted) {
  root = mounted.root;

  try {
    const response = await api.get('/admin/categories', { per_page: 200 });
    categories = response.data || [];
  } catch {
    categories = [];
  }

  const editUuid = queryParam('edit');
  const isNew = queryParam('new') !== null;

  if (editUuid || isNew) {
    renderRuleForm(editUuid);
  } else {
    render();
  }
}
