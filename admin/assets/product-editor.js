/**
 * Product editor: adding and editing what the shop sells.
 *
 * Without this the console could only publish and unpublish products that
 * already existed, which meant a merchant could not list their own goods
 * without calling the API by hand.
 *
 * A PRODUCT AND ITS FIRST PACK SIZE ARE CREATED TOGETHER.
 *
 * The API refuses to create a product with no pack size — "A product needs at
 * least one pack size" — which means a product can never exist in a state where
 * it has a name and a description but no weight and no price. That is the right
 * rule, and this form follows it rather than working around it: the new-product
 * page asks for the first pack size in the same submission.
 *
 * Further pack sizes are added afterwards, one at a time, on the edit page.
 */

import { api, mountConsole, showError, toast, setBusy, escapeHtml, formatMoney,
         badge, emptyState, queryParam } from './console.js?v=9';

let root = null;
let categories = [];

const PACK_TYPES = ['pouch', 'jar', 'box', 'tin', 'gift_box', 'refill', 'other'];

/**
 * GST rates that actually apply to this catalogue.
 *
 * Offered as a list rather than a free number because picking the wrong rate is
 * a tax problem, not a typo. Whole spices are 5%, most processed foods 12%.
 */
const GST_RATES = [
  ['5', '5% — whole and ground spices, most dry fruits'],
  ['12', '12% — processed foods, blends, gift packs'],
  ['18', '18% — confectionery, most clothing and footwear'],
  ['28', '28% — footwear over ₹1000, luxury and sin goods'],
  ['0', '0% — exempt (fresh milk, unbranded staples)'],
];

function field(name, label, { type = 'text', required = false, hint = '', value = '',
                              width = 'col-md-6', attrs = '' } = {}) {
  return `
    <div class="${width}">
      <label class="form-label small" for="${name}">
        ${escapeHtml(label)}${required ? ' <span class="text-danger">*</span>' : ''}
      </label>
      <input class="form-control form-control-sm" id="${name}" name="${name}" type="${type}"
             value="${escapeHtml(value)}" ${required ? 'required' : ''} ${attrs}>
      ${hint ? `<div class="form-text small">${escapeHtml(hint)}</div>` : ''}
    </div>`;
}

function productForm(product) {
  const editing = Boolean(product);
  const p = product || {};

  return `
    <form class="card mb-4" data-product-form>
      <div class="card-header bg-white fw-semibold">
        ${editing ? 'Product details' : 'New product'}
      </div>
      <div class="card-body">
        <div class="row g-3">
          ${field('name', 'Product name', {
            required: !editing, value: p.name || '', width: 'col-md-8',
            hint: 'What customers see. "Organic Turmeric Powder", not "TURM-01".',
          })}
          ${field('product_code', 'Internal code', {
            required: !editing, value: p.product_code || '', width: 'col-md-4',
            hint: 'Your own reference. Not shown to customers.',
          })}

          <div class="col-md-6">
            <label class="form-label small" for="category_slug">
              Category ${editing ? '' : '<span class="text-danger">*</span>'}
            </label>
            <select class="form-select form-select-sm" id="category_slug" name="category_slug"
                    ${editing ? '' : 'required'}>
              <option value="">Choose a category…</option>
              ${categories.map((category) => `
                <option value="${escapeHtml(category.slug)}"
                        ${p.category_slug === category.slug ? 'selected' : ''}>
                  ${escapeHtml(category.name)}
                </option>`).join('')}
            </select>
          </div>

          ${(() => {
            const current = String(p.gst_rate ?? '5');
            const isPreset = GST_RATES.some(([rate]) => rate === current);

            return `
              <div class="col-md-3">
                <label class="form-label small" for="gst_rate">GST rate</label>
                <select class="form-select form-select-sm mb-1" data-gst-picker>
                  ${GST_RATES.map(([rate, label]) => `
                    <option value="${rate}" ${isPreset && current === rate ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                  <option value="" ${isPreset ? '' : 'selected'}>Other % (not in the usual list)…</option>
                </select>
                <input class="form-control form-control-sm" id="gst_rate" name="gst_rate" type="number"
                       step="0.01" min="0" max="28" value="${escapeHtml(current)}" placeholder="Exact GST %" data-gst-value>
              </div>`;
          })()}

          ${field('hsn_code', 'HSN code', {
            value: p.hsn_code || '', width: 'col-md-3',
            hint: 'Required on GST invoices.',
          })}

          ${field('brand', 'Brand', { value: p.brand || '', width: 'col-md-4' })}
          ${field('origin_region', 'Origin', {
            value: p.origin_region || '', width: 'col-md-4',
            hint: 'e.g. Kerala, Kashmir.',
          })}
          ${field('shelf_life_days', 'Shelf life (days)', {
            type: 'number', value: p.shelf_life_days || '', width: 'col-md-4',
            attrs: 'min="1" max="3650"',
          })}

          <div class="col-12">
            <label class="form-label small" for="short_description">Short description</label>
            <input class="form-control form-control-sm" id="short_description" name="short_description"
                   value="${escapeHtml(p.short_description || '')}" maxlength="320">
            <div class="form-text small">One line, shown on the product card.</div>
          </div>

          <div class="col-12">
            <label class="form-label small" for="description">Full description</label>
            <textarea class="form-control form-control-sm" id="description" name="description"
                      rows="4">${escapeHtml(p.description || '')}</textarea>
          </div>

          <div class="col-12">
            <label class="form-label small" for="ingredients">Ingredients</label>
            <textarea class="form-control form-control-sm" id="ingredients" name="ingredients"
                      rows="2">${escapeHtml(p.ingredients || '')}</textarea>
            <div class="form-text small">
              Food labelling law requires this on packaged food. Fill it in.
            </div>
          </div>

          ${field('fssai_license_no', 'FSSAI licence number', {
            value: p.fssai_license_no || '', width: 'col-md-6',
            hint: 'Legally required for packaged food sold in India.',
          })}

          <div class="col-md-6 d-flex align-items-end">
            <div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="is_organic" name="is_organic"
                       ${p.is_organic ? 'checked' : ''}>
                <label class="form-check-label small" for="is_organic">Organic</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="is_vegetarian" name="is_vegetarian"
                       ${p.is_vegetarian === false ? '' : 'checked'}>
                <label class="form-check-label small" for="is_vegetarian">Vegetarian</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured"
                       ${p.is_featured ? 'checked' : ''}>
                <label class="form-check-label small" for="is_featured">Featured</label>
              </div>
            </div>
          </div>
        </div>
      </div>
      ${editing ? '' : `
        <div class="card-body border-top">
          <h2 class="h6">First pack size</h2>
          <p class="text-muted small">
            Every product needs at least one, so it is created with the product.
            You can add more afterwards.
          </p>
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small" for="v_variant_name">Pack name <span class="text-danger">*</span></label>
              <input class="form-control form-control-sm" id="v_variant_name" name="v_variant_name"
                     placeholder="250 g pouch" required>
            </div>
            <div class="col-md-3">
              <label class="form-label small" for="v_sku">SKU <span class="text-danger">*</span></label>
              <input class="form-control form-control-sm" id="v_sku" name="v_sku" required minlength="3">
            </div>
            <div class="col-md-2">
              <label class="form-label small" for="v_weight_grams">Weight (g) <span class="text-danger">*</span></label>
              <input class="form-control form-control-sm" id="v_weight_grams" name="v_weight_grams"
                     type="number" min="1" max="100000" required>
              <div class="form-text small">Always required, even if sized below — used for courier weight.</div>
            </div>
            <div class="col-md-2">
              <label class="form-label small" for="v_size_value">Size (optional)</label>
              <input class="form-control form-control-sm" id="v_size_value" name="v_size_value"
                     list="size-suggestions" placeholder="e.g. M, or 8">
              <div class="form-text small">For clothing, footwear, anything sized rather than weighed.</div>
            </div>
            <div class="col-md-2">
              <label class="form-label small" for="v_mrp">MRP <span class="text-danger">*</span></label>
              <input class="form-control form-control-sm" id="v_mrp" name="v_mrp"
                     type="number" step="0.01" min="1" required>
            </div>
            <div class="col-md-2">
              <label class="form-label small" for="v_selling_price">Selling <span class="text-danger">*</span></label>
              <input class="form-control form-control-sm" id="v_selling_price" name="v_selling_price"
                     type="number" step="0.01" min="1" required>
            </div>

            <div class="col-md-2">
              <label class="form-label small" for="v_max_order_quantity">Max per order</label>
              <input class="form-control form-control-sm" id="v_max_order_quantity"
                     name="v_max_order_quantity" type="number" min="1" max="500" placeholder="No limit">
              <div class="form-text small">
                Blank means no limit. Set it on anything scarce or heavily
                discounted — the cart refuses more than this.
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label small" for="v_expiry_date">Expiry date</label>
              <input class="form-control form-control-sm" id="v_expiry_date" name="v_expiry_date" type="date">
            </div>
            <div class="col-12">
              <div class="form-text small">
                Prices INCLUDE GST. Indian MRP is tax-inclusive, so the tax is
                extracted from this figure rather than added to it.
              </div>
            </div>
          </div>
          ${sizeSuggestionsDatalist()}
        </div>`}

      <div class="card-footer bg-white d-flex gap-2">
        <button class="btn btn-sm btn-dark" type="submit">
          ${editing ? 'Save changes' : 'Create product'}
        </button>
        <a class="btn btn-sm btn-outline-secondary" href="products.html">Cancel</a>
      </div>
    </form>`;
}

function variantsPanel(product) {
  const variants = product.variants || [];

  return `
    <div class="card mb-4">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Pack sizes</span>
        <span class="small text-muted">${escapeHtml(variants.length)} defined</span>
      </div>

      ${variants.length === 0
        ? `<div class="card-body">
             <div class="alert alert-warning small mb-0">
               This product has no pack sizes, so it cannot be sold. Add at least one below.
             </div>
           </div>`
        : `<div class="table-responsive">
             <table class="table table-tight mb-0">
               <thead><tr><th>Pack</th><th>SKU</th><th class="text-end">Weight</th>
                 <th class="text-end">MRP</th><th class="text-end">Selling</th>
                     <th class="text-center">Max/order</th><th></th></tr></thead>
               <tbody>
                 ${variants.map((variant) => `
                   <tr data-variant="${escapeHtml(variant.uuid)}">
                     <td>
                       ${escapeHtml(variant.variant_name)}
                       ${variant.is_default ? '<span class="badge text-bg-secondary ms-1">Default</span>' : ''}
                     </td>
                     <td class="small font-monospace">${escapeHtml(variant.sku)}</td>
                     <td class="text-end small">
                       ${escapeHtml(variant.weight_grams)} g
                       ${variant.size_label ? `<div class="text-muted">Size ${escapeHtml(variant.size_label)}</div>` : ''}
                     </td>
                     <td class="text-end small">${formatMoney(variant.mrp)}</td>
                     <td class="text-end">${formatMoney(variant.selling_price)}</td>
                     <td class="text-center small">
                       ${Number(variant.max_order_quantity) > 0
                         ? escapeHtml(variant.max_order_quantity)
                         : '<span class="text-muted">—</span>'}
                     </td>
                     <td class="text-end">
                       <button class="btn btn-sm btn-outline-danger" data-remove-variant type="button">Remove</button>
                     </td>
                   </tr>`).join('')}
               </tbody>
             </table>
           </div>`}

      <div class="card-body border-top">
        <form class="row g-2 align-items-end" data-variant-form>
          <div class="col-md-3">
            <label class="form-label small" for="variant_name">Pack name <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="variant_name" name="variant_name"
                   placeholder="250 g pouch" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="sku">SKU <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="sku" name="sku" required minlength="3">
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="weight_grams">Weight (g) <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="weight_grams" name="weight_grams"
                   type="number" min="1" max="100000" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="size_value">Size (optional)</label>
            <input class="form-control form-control-sm" id="size_value" name="size_value"
                   list="size-suggestions" placeholder="e.g. M, or 8">
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="mrp">MRP <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="mrp" name="mrp"
                   type="number" step="0.01" min="1" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="selling_price">Selling price <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="selling_price" name="selling_price"
                   type="number" step="0.01" min="1" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="max_order_quantity">Max per order</label>
            <input class="form-control form-control-sm" id="max_order_quantity"
                   name="max_order_quantity" type="number" min="1" max="500" placeholder="No limit">
          </div>
          <div class="col-md-2">
            <label class="form-label small" for="expiry_date">Expiry date</label>
            <input class="form-control form-control-sm" id="expiry_date" name="expiry_date" type="date">
          </div>

          <div class="col-md-1">
            <button class="btn btn-sm btn-dark w-100" type="submit">Add</button>
          </div>

          <div class="col-12">
            <div class="form-text small">
              Prices INCLUDE GST — Indian MRP is tax-inclusive, and the tax is
              extracted from this figure rather than added to it. Enter what the
              customer pays. Expiry date is optional and informational — for
              stock actually received, declare its expiry on the Purchase Inward
              or Inventory adjust screen instead, where it's tracked per batch.
              Weight is always required — used for courier weight even on a sized pack.
            </div>
          </div>
          ${sizeSuggestionsDatalist()}
        </form>
      </div>
    </div>`;
}

/**
 * Product photographs.
 *
 * A product cannot be published without at least one — the API refuses, and it
 * is right to: an unillustrated item in a food shop does not sell, and the
 * customer has no way to judge what they are buying.
 */
function imagesPanel(product) {
  const images = product.images || product.media || [];

  return `
    <div class="card mb-4">
      <div class="card-header bg-white fw-semibold">Photographs</div>

      ${images.length === 0
        ? `<div class="card-body">
             <div class="alert alert-warning small mb-0">
               No photographs yet. At least one is required before this product can
               go on sale.
             </div>
           </div>`
        : `<div class="card-body">
             <div class="row row-cols-2 row-cols-md-4 g-2">
               ${images.map((image) => `
                 <div class="col" data-media="${escapeHtml(image.uuid)}">
                   <div class="border rounded p-1 h-100">
                     <img src="${escapeHtml(image.url || image.file_path || '')}"
                          class="img-fluid rounded" alt="${escapeHtml(image.alt_text || product.name)}">
                     <div class="d-flex justify-content-between align-items-center mt-1">
                       ${image.is_primary
                         ? '<span class="badge text-bg-secondary">Main</span>'
                         : '<span></span>'}
                       <button class="btn btn-sm btn-link text-danger p-0" data-remove-image type="button">Remove</button>
                     </div>
                   </div>
                 </div>`).join('')}
             </div>
           </div>`}

      <div class="card-body border-top">
        <form class="row g-2 align-items-end" data-image-form>
          <div class="col-md-6">
            <label class="form-label small" for="image">Add a photograph</label>
            <input class="form-control form-control-sm" id="image" name="image" type="file"
                   accept="image/jpeg,image/png,image/webp" required>
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="alt_text">Description for screen readers</label>
            <input class="form-control form-control-sm" id="alt_text" name="alt_text"
                   placeholder="Whole green cardamom pods">
          </div>
          <div class="col-md-2">
            <button class="btn btn-sm btn-dark w-100" type="submit">Upload</button>
          </div>
        </form>
      </div>
    </div>`;
}

async function loadCategories() {
  try {
    const response = await api.get('/admin/categories');
    categories = response.data.categories || response.data || [];
  } catch {
    categories = [];
  }
}

// The "size" variant dimension (Size/Colour/Pack size all already exist as
// dimensions — see VariantOptionService) — cached once per page load. Sizes
// are free text per product (a dress picks S/M/L/XL, a slipper might use
// 6/7/8/9/10), so this is a suggestion list, not a fixed set: typing
// anything not already here just adds a new value to the dimension.
let sizeType = null;

async function loadSizeType() {
  if (sizeType) return sizeType;

  try {
    const response = await api.get('/admin/inventory/option-types');
    sizeType = (response.data || []).find((t) => t.code === 'size') || null;
  } catch {
    sizeType = null;
  }

  return sizeType;
}

function sizeSuggestionsDatalist() {
  const values = (sizeType && sizeType.values) || [];

  return `<datalist id="size-suggestions">
    ${values.map((v) => `<option value="${escapeHtml(v.value)}">`).join('')}
  </datalist>`;
}

/**
 * Finds the id of an existing size value matching `text` (case-insensitive),
 * or creates one. Returns null for blank input — sizing is optional, most
 * pack sizes (spices, dry fruits) never use this at all.
 */
async function resolveSizeValueId(text) {
  const typed = (text || '').trim();
  if (typed === '') return null;

  const type = await loadSizeType();
  if (!type) return null;

  const existing = (type.values || []).find((v) => v.value.toLowerCase() === typed.toLowerCase());
  if (existing) return { typeId: type.id, valueId: existing.id };

  const created = await api.post(`/admin/inventory/option-types/${encodeURIComponent(type.uuid)}/values`, { value: typed });
  type.values = type.values || [];
  type.values.push(created.data);

  return { typeId: type.id, valueId: created.data.id };
}

/** Attaches (or leaves untouched, if blank) a size to a just-created variant. */
async function attachSizeIfProvided(variantUuid, sizeText) {
  const resolved = await resolveSizeValueId(sizeText);
  if (!resolved) return;

  await api.put(`/admin/variants/${encodeURIComponent(variantUuid)}/options`, {
    options: [{ option_type_id: resolved.typeId, option_value_id: resolved.valueId }],
  });
}

/** Only the fields the person actually filled in, so a PATCH stays a patch. */
function collect(form, { includeEmpty = false } = {}) {
  const payload = {};

  new FormData(form).forEach((value, key) => {
    const element = form.elements[key];

    if (element && element.type === 'checkbox') {
      payload[key] = element.checked;
      return;
    }

    if (value === '' && !includeEmpty) return;
    payload[key] = value;
  });

  // Unchecked boxes never appear in FormData at all.
  Array.from(form.elements)
    .filter((element) => element.type === 'checkbox')
    .forEach((element) => { payload[element.name] = element.checked; });

  return payload;
}

/**
 * Mirrors ProductService::publish()'s own three checks exactly (pack size,
 * image, short description) — this used to only check the first two, so a
 * product missing only its short description showed the "Put this product
 * on sale" button anyway, and clicking it failed with a message that didn't
 * say why. Checked here so staff see what's missing before clicking, not
 * after.
 */
function publishReadiness(product) {
  const missing = [];

  if ((product.variants || []).length === 0) missing.push('at least one pack size');
  if ((product.images || product.media || []).length === 0) missing.push('at least one photograph');
  if (!product.short_description || !product.short_description.trim()) missing.push('a short description');

  return missing;
}

function publishSection(product) {
  const missing = publishReadiness(product);

  if (missing.length === 0) {
    return `
      <button class="btn btn-success" data-publish type="button">Put this product on sale</button>
      <div data-publish-error class="mt-2"></div>`;
  }

  return `
    <div class="alert alert-secondary small mb-0">
      Before this can go on sale it needs ${escapeHtml(missing.join(', '))}.
    </div>`;
}

async function renderEditor(identifier) {
  await Promise.all([loadCategories(), loadSizeType()]);

  let product = null;

  if (identifier) {
    try {
      const response = await api.get(`/admin/products/${encodeURIComponent(identifier)}`);
      product = response.data.product;
    } catch (error) {
      root.innerHTML = '<a class="small" href="products.html">← All products</a>';
      showError(error, root);
      return;
    }
  }

  root.innerHTML = `
    <a class="small text-decoration-none" href="products.html">← All products</a>
    <div class="d-flex justify-content-between align-items-start mt-2 mb-3">
      <h1 class="h4 mb-0">${product ? escapeHtml(product.name) : 'Add a product'}</h1>
      ${product ? badge(product.status === 'published' ? 'approved' : 'pending', product.status) : ''}
    </div>

    ${productForm(product)}
    ${product ? variantsPanel(product) : ''}
    ${product ? imagesPanel(product) : ''}

    ${product && product.status !== 'published' ? publishSection(product) : ''}`;

  bindEditor(product);
}

function bindEditor(product) {
  const form = root.querySelector('[data-product-form]');

  // The GST rate picker is a convenience, not the field that's actually
  // submitted — #gst_rate (a plain number input) always is, so typing an
  // exact rate the preset list doesn't cover (28%, or anything else) works
  // without needing a matching option first.
  form.querySelector('[data-gst-picker]').addEventListener('change', (event) => {
    if (event.currentTarget.value !== '') {
      form.querySelector('[data-gst-value]').value = event.currentTarget.value;
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    setBusy(button, true, 'Saving');

    try {
      if (product) {
        await api.patch(`/admin/products/${encodeURIComponent(product.uuid)}`, collect(form));
        toast('Product saved.');
        renderEditor(product.uuid);
        return;
      }

      // Split the `v_`-prefixed fields back out into the variants array the
      // API expects. The prefix exists only so the two sets of inputs can share
      // one form without their ids colliding.
      const all = collect(form);
      const payload = {};
      const variant = {};

      Object.entries(all).forEach(([key, value]) => {
        if (key.startsWith('v_')) variant[key.slice(2)] = value;
        else payload[key] = value;
      });

      if (Number(variant.selling_price) > Number(variant.mrp)) {
        setBusy(button, false);
        toast('The selling price cannot be more than the MRP.', 'danger');
        return;
      }

      const sizeValue = variant.size_value;
      delete variant.size_value;

      variant.is_default = true;
      payload.variants = [variant];

      const response = await api.post('/admin/products', payload);

      if (sizeValue) {
        const created = response.data.product.variants[0];
        await attachSizeIfProvided(created.uuid, sizeValue);
      }

      toast('Product created. Add more pack sizes, then put it on sale.');
      window.location.href = `products.html?edit=${encodeURIComponent(response.data.product.uuid)}`;
    } catch (error) {
      setBusy(button, false);
      showError(error);
    }
  });

  const variantForm = root.querySelector('[data-variant-form]');

  if (variantForm) {
    variantForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = variantForm.querySelector('button');
      const payload = collect(variantForm);
      const sizeValue = payload.size_value;
      delete payload.size_value;

      // Caught here rather than by the server, because "selling price above MRP"
      // is a mistake with a clear explanation and no reason to make a round trip.
      if (Number(payload.selling_price) > Number(payload.mrp)) {
        toast('The selling price cannot be more than the MRP.', 'danger');
        return;
      }

      setBusy(button, true, '…');

      try {
        const response = await api.post(`/admin/products/${encodeURIComponent(product.uuid)}/variants`, payload);
        // The API uppercases SKUs, so match case-insensitively against what
        // was actually typed here.
        const created = (response.data.product.variants || [])
          .find((v) => v.sku.toUpperCase() === String(payload.sku).toUpperCase());

        if (created && sizeValue) {
          await attachSizeIfProvided(created.uuid, sizeValue);
        }

        toast('Pack size added.');
        renderEditor(product.uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  }

  root.querySelectorAll('[data-remove-variant]').forEach((button) => {
    button.addEventListener('click', async () => {
      const uuid = button.closest('[data-variant]').dataset.variant;
      if (!window.confirm('Remove this pack size?')) return;

      setBusy(button, true, '…');

      try {
        await api.delete(`/admin/variants/${encodeURIComponent(uuid)}`);
        toast('Deleted successfully. View in Recycle Bin.');
        renderEditor(product.uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  });

  const imageForm = root.querySelector('[data-image-form]');

  if (imageForm) {
    imageForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = imageForm.querySelector('button');
      const file = imageForm.querySelector('#image').files[0];

      if (!file) {
        toast('Choose a file first.', 'danger');
        return;
      }

      // FormData, not JSON. The browser sets the multipart boundary itself.
      const body = new FormData();
      body.append('image', file);
      body.append('alt_text', imageForm.querySelector('#alt_text').value || product.name);
      body.append('is_primary', (product.images || product.media || []).length === 0 ? '1' : '0');

      setBusy(button, true, 'Uploading');

      try {
        await api.upload(`/admin/products/${encodeURIComponent(product.uuid)}/images`, body);
        toast('Photograph added.');
        renderEditor(product.uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  }

  root.querySelectorAll('[data-remove-image]').forEach((button) => {
    button.addEventListener('click', async () => {
      const uuid = button.closest('[data-media]').dataset.media;
      if (!window.confirm('Remove this photograph?')) return;

      setBusy(button, true, '…');

      try {
        await api.delete(`/admin/media/${encodeURIComponent(uuid)}`);
        toast('Photograph removed.');
        renderEditor(product.uuid);
      } catch (error) {
        setBusy(button, false);
        showError(error);
      }
    });
  });

  const publish = root.querySelector('[data-publish]');

  if (publish) {
    publish.addEventListener('click', async () => {
      setBusy(publish, true, 'Publishing');

      try {
        // "Save changes" and "Put this product on sale" are two separate
        // buttons — filling in the short description and clicking Publish
        // without saving first left the edit sitting in the input, unsent,
        // while the server (correctly) still saw the old, empty value and
        // refused. Saving whatever is currently in the form here first means
        // Publish always acts on what's on screen, not what's stale on the
        // server.
        const changes = collect(form);
        if (Object.keys(changes).length > 0) {
          await api.patch(`/admin/products/${encodeURIComponent(product.uuid)}`, changes);
        }

        await api.post(`/admin/products/${encodeURIComponent(product.uuid)}/publish`, {});
        toast('This product is now on sale.');
        renderEditor(product.uuid);
      } catch (error) {
        setBusy(publish, false);
        // The API's own field messages (e.g. "Add a short description for
        // the listing tile.") are the actual reason — shown here instead of
        // dropped in favour of the generic "not ready to publish." toast.
        showError(error, root.querySelector('[data-publish-error]'));
      }
    });
  }
}

export async function mountProductEditor(pageRoot) {
  root = pageRoot;
  const edit = queryParam('edit');
  const isNew = queryParam('new') !== null;

  if (edit || isNew) {
    await renderEditor(edit);
    return true;
  }

  return false;
}
