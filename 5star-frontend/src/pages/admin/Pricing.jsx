/**
 * Pricing: the markup/margin rule engine, plus the price-change-behaviour
 * setting that decides whether a purchase's new cost silently recalculates a
 * pack size's online price, or stops to ask a human first.
 *
 * Ported from admin/assets/page-pricing.js. The "price decision" concept
 * (see admin/assets/pricing-decisions.js, shared with Purchase Inward and
 * Mobile Scan) is the record created whenever a purchase's cost moves a pack
 * size's rule-computed price away from what the shop currently charges.
 * Depending on the mode set here, that gets auto-applied, or queued for a
 * human to resolve — either immediately, through the purchase-inward/mobile
 * "decide now" prompt, or later, through this page's Live pricing table,
 * which flags any pack size still out of sync and lets staff apply the
 * rule's own suggestion with one click.
 */
import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { EmptyState, LoadingState, ErrorState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import './Pricing.css';

const MODE_LABELS = {
  always_ask: 'Always ask',
  ask_on_increase: 'Ask only when price increases',
  ask_on_decrease: 'Ask only when price decreases',
  auto_apply: 'Automatically apply the pricing rule',
  never: 'Never change selling price automatically',
};

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

function targetLabel(rule) {
  if (rule.scope === 'global') return 'All products';
  if (rule.scope === 'category') return `Category: ${rule.category_name || '—'}`;
  if (rule.scope === 'product') return `Product: ${rule.product_name || '—'}`;
  return `Pack size: ${rule.variant_sku || '—'} (${rule.variant_name || ''})`;
}

/* ---------------------------------------------------------------------- */
/* Price-change behaviour                                                 */
/* ---------------------------------------------------------------------- */

function ModeCard() {
  const [mode, setMode] = useState('');
  const [options, setOptions] = useState(Object.keys(MODE_LABELS));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await api.get('/admin/settings');
        const settings = response.data || {};
        setMode(settings.inventory_price_change_mode || '');
        setOptions(settings.inventory_price_change_mode_options || Object.keys(MODE_LABELS));
      } catch (err) {
        setError(err);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    try {
      await api.patch('/admin/settings/price-change-mode', { mode });
      toast('Price-change behaviour updated.');
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="pricing-card">
      <div className="pricing-card__header">Price-change behaviour</div>
      <div className="pricing-card__body">
        <p className="pricing-hint">
          What happens to a pack size's selling price when a purchase changes its cost.
        </p>
        {loading ? <LoadingState /> : error ? (
          <div className="admin-alert admin-alert--danger">Could not load the price-change setting.</div>
        ) : (
          <form className="pricing-mode-form" onSubmit={handleSubmit}>
            <select value={mode} onChange={(event) => setMode(event.target.value)}>
              {options.map((value) => (
                <option key={value} value={value}>{MODE_LABELS[value] || value}</option>
              ))}
            </select>
            <button className="admin-btn admin-btn--primary" type="submit" disabled={busy}>
              {busy ? 'Saving…' : 'Save'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Live pricing                                                           */
/* ---------------------------------------------------------------------- */

function LivePricing() {
  const [variants, setVariants] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyUuid, setBusyUuid] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/pricing/live');
      setVariants((response.data && response.data.variants) || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function applySuggested(variant) {
    setBusyUuid(variant.variant_uuid);
    try {
      await api.post('/admin/pricing/decisions', {
        variant_uuid: variant.variant_uuid,
        decision: 'manual',
        manual_price: variant.suggested_price,
        reference_type: 'manual',
        reason: 'Applied from the Live pricing view',
      });
      toast('Price updated.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;
  if (variants.length === 0) {
    return <EmptyState title="No pack sizes yet" hint="Add products to see their live pricing here." />;
  }

  const outOfSync = variants.filter((v) => v.is_out_of_sync);

  return (
    <div>
      {outOfSync.length > 0 && (
        <div className="admin-alert admin-alert--warning">
          {outOfSync.length} pack size(s) below have a rule-computed price that no longer matches
          what the shop charges — usually because a purchase came in under "Ask" or "Never" mode
          and nobody has resolved it yet.
        </div>
      )}
      <div style={{ overflowX: 'auto' }}>
        <table className="admin-table">
          <thead>
            <tr>
              <th>Pack</th><th>Rule</th><th>Avg. cost</th><th>Last purchase cost</th>
              <th>Online price</th><th>Rule says</th><th></th>
            </tr>
          </thead>
          <tbody>
            {variants.map((v) => (
              <tr key={v.variant_uuid} className={v.is_out_of_sync ? 'pricing-row--warning' : ''}>
                <td>
                  <div style={{ fontWeight: 600 }}>{v.product_name}</div>
                  <div className="pricing-hint">{v.variant_name} · {v.sku}</div>
                </td>
                <td className="pricing-hint">{v.rule_summary || 'No rule applies'}</td>
                <td>{v.average_cost !== null && v.average_cost !== undefined ? formatMoney(v.average_cost) : '—'}</td>
                <td className="pricing-hint">{v.last_purchase_cost !== null && v.last_purchase_cost !== undefined ? formatMoney(v.last_purchase_cost) : '—'}</td>
                <td style={{ fontWeight: 600 }}>{formatMoney(v.current_price)}</td>
                <td className={v.is_out_of_sync ? 'pricing-suggested' : 'pricing-hint'}>
                  {v.suggested_price !== null && v.suggested_price !== undefined ? formatMoney(v.suggested_price) : '—'}
                </td>
                <td>
                  {v.is_out_of_sync && (
                    <button className="admin-btn" disabled={busyUuid === v.variant_uuid}
                            onClick={() => applySuggested(v)}>
                      Use {formatMoney(v.suggested_price)}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Rule form (create / edit)                                              */
/* ---------------------------------------------------------------------- */

const RULE_DEFAULTS = {
  name: '',
  scope: 'global',
  category_uuid: '',
  product_uuid: '',
  variant_uuid: '',
  calculation: 'markup_percent',
  rate: '',
  tax_mode: 'exclusive',
  priority: 100,
};

function RuleForm({ rule, categories, onCancel, onSaved }) {
  const [fields, setFields] = useState(() => (rule ? {
    name: rule.name || '',
    scope: rule.scope || 'global',
    category_uuid: rule.category_uuid || '',
    product_uuid: rule.scope === 'product' ? (rule.product_uuid || '') : '',
    variant_uuid: rule.scope === 'variant' ? (rule.variant_uuid || '') : '',
    calculation: rule.calculation || 'markup_percent',
    rate: rule.rate ?? '',
    tax_mode: rule.tax_mode || 'exclusive',
    priority: rule.priority ?? 100,
  } : RULE_DEFAULTS));

  const [skuInput, setSkuInput] = useState('');
  const [skuFeedback, setSkuFeedback] = useState(
    rule && (rule.scope === 'product' || rule.scope === 'variant')
      ? { ok: true, label: rule.scope === 'product' ? rule.product_name : `${rule.variant_sku} — ${rule.variant_name}` }
      : null,
  );
  const [busy, setBusy] = useState(false);

  const set = (name) => (event) => setFields((f) => ({ ...f, [name]: event.target.value }));

  async function lookupSku() {
    const sku = skuInput.trim();
    if (!sku) return;
    setSkuFeedback({ loading: true });

    try {
      const response = await api.get('/admin/inventory/lookup', { sku });
      const variant = response.data;

      if (fields.scope === 'product') {
        setFields((f) => ({ ...f, product_uuid: variant.product_uuid }));
        setSkuFeedback({ ok: true, label: variant.product_name });
      } else {
        setFields((f) => ({ ...f, variant_uuid: variant.uuid }));
        setSkuFeedback({ ok: true, label: `${variant.product_name} — ${variant.variant_name}` });
      }
    } catch (err) {
      setSkuFeedback({ ok: false, label: err.message || 'No pack size has that SKU.' });
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();

    if (fields.scope === 'category' && !fields.category_uuid) {
      toast('Select a category first.', 'danger');
      return;
    }
    if (fields.scope === 'product' && !fields.product_uuid) {
      toast('Look up a SKU to resolve the product first.', 'danger');
      return;
    }
    if (fields.scope === 'variant' && !fields.variant_uuid) {
      toast('Look up a SKU to resolve the pack size first.', 'danger');
      return;
    }

    const payload = {
      name: fields.name,
      scope: fields.scope,
      calculation: fields.calculation,
      rate: fields.rate,
      tax_mode: fields.tax_mode,
      priority: fields.priority,
    };
    if (fields.scope === 'category') payload.category_uuid = fields.category_uuid;
    if (fields.scope === 'product') payload.product_uuid = fields.product_uuid;
    if (fields.scope === 'variant') payload.variant_uuid = fields.variant_uuid;

    setBusy(true);
    try {
      if (rule) {
        await api.patch(`/admin/pricing/rules/${encodeURIComponent(rule.uuid)}`, payload);
      } else {
        await api.post('/admin/pricing/rules', payload);
      }
      toast('Pricing rule saved.');
      onSaved();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="pricing-card">
      <div className="pricing-card__header">{rule ? 'Edit pricing rule' : 'Add a pricing rule'}</div>
      <div className="pricing-card__body">
        <form onSubmit={handleSubmit} className="pricing-form-grid">
          <div className="pricing-field">
            <label htmlFor="rule-name">Name</label>
            <input id="rule-name" required maxLength={120} value={fields.name} onChange={set('name')} />
          </div>

          <div className="pricing-field">
            <label htmlFor="rule-scope">Applies to</label>
            <select id="rule-scope" value={fields.scope} onChange={set('scope')}>
              <option value="global">Everything (global default)</option>
              <option value="category">One category</option>
              <option value="product">One product</option>
              <option value="variant">One pack size</option>
            </select>
          </div>

          {fields.scope === 'category' && (
            <div className="pricing-field">
              <label htmlFor="rule-category">Category</label>
              <select id="rule-category" value={fields.category_uuid} onChange={set('category_uuid')}>
                <option value="">Select a category…</option>
                {categories.map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
              </select>
            </div>
          )}

          {(fields.scope === 'product' || fields.scope === 'variant') && (
            <div className="pricing-field pricing-field--wide">
              <label htmlFor="rule-sku">SKU (of the product or pack size)</label>
              <div className="pricing-sku-lookup">
                <input id="rule-sku" placeholder="Type a SKU" value={skuInput}
                       onChange={(event) => setSkuInput(event.target.value)} />
                <button className="admin-btn" type="button" onClick={lookupSku}>Find</button>
              </div>
              <div className="pricing-hint">
                {skuFeedback && skuFeedback.loading && 'Looking up…'}
                {skuFeedback && skuFeedback.ok === true && <span className="pricing-ok">Currently: {skuFeedback.label}</span>}
                {skuFeedback && skuFeedback.ok === false && <span className="pricing-error">{skuFeedback.label}</span>}
              </div>
            </div>
          )}

          <div className="pricing-field">
            <label htmlFor="rule-calculation">Calculation</label>
            <select id="rule-calculation" value={fields.calculation} onChange={set('calculation')}>
              <option value="markup_percent">Markup on cost</option>
              <option value="margin_percent">Margin on selling price</option>
            </select>
          </div>

          <div className="pricing-field">
            <label htmlFor="rule-rate">Rate (%)</label>
            <input id="rule-rate" type="number" step="0.001" min="0.001" max="999" required
                   value={fields.rate} onChange={set('rate')} />
          </div>

          <div className="pricing-field">
            <label htmlFor="rule-tax">Tax</label>
            <select id="rule-tax" value={fields.tax_mode} onChange={set('tax_mode')}>
              <option value="exclusive">Add GST on top</option>
              <option value="inclusive">Rate already includes GST</option>
            </select>
          </div>

          <div className="pricing-field">
            <label htmlFor="rule-priority">Priority</label>
            <input id="rule-priority" type="number" min="1" max="9999" value={fields.priority} onChange={set('priority')} />
            <div className="pricing-hint">Lower number wins when more than one rule could apply.</div>
          </div>

          <div className="pricing-actions">
            <button className="admin-btn admin-btn--primary" type="submit" disabled={busy}>
              {busy ? 'Saving…' : 'Save'}
            </button>
            <button className="admin-btn" type="button" onClick={onCancel}>Cancel</button>
          </div>
        </form>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Rules list                                                             */
/* ---------------------------------------------------------------------- */

function RulesTable({ rules, onEdit, onDeactivate, busyUuid }) {
  if (rules.length === 0) {
    return (
      <EmptyState title="No pricing rules yet"
        hint='Without one, "Use average cost" / "Use new price" decisions have nothing to compute — add at least a global default.' />
    );
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead>
          <tr><th>Rule</th><th>Calculation</th><th>Priority</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          {rules.map((rule) => (
            <tr key={rule.uuid}>
              <td>
                <div style={{ fontWeight: 600 }}>{rule.name}</div>
                <div className="pricing-hint">{targetLabel(rule)}</div>
              </td>
              <td className="pricing-hint">
                {rule.calculation === 'markup_percent' ? 'Markup' : 'Margin'} {Number(rule.rate)}% ({rule.tax_mode})
              </td>
              <td>{rule.priority}</td>
              <td>
                <span className={`status-badge status-badge--${rule.status === 'active' ? 'success' : 'secondary'}`}>
                  {rule.status === 'active' ? 'Active' : 'Inactive'}
                </span>
              </td>
              <td style={{ whiteSpace: 'nowrap' }}>
                <button className="admin-btn" onClick={() => onEdit(rule)}>Edit</button>{' '}
                {rule.status === 'active' && (
                  <button className="admin-btn" disabled={busyUuid === rule.uuid}
                          onClick={() => onDeactivate(rule)}>Deactivate</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Page                                                                    */
/* ---------------------------------------------------------------------- */

export default function Pricing() {
  const [categories, setCategories] = useState([]);
  const [rules, setRules] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyUuid, setBusyUuid] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [editingRule, setEditingRule] = useState(null);

  const loadRules = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/pricing/rules');
      setRules(response.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadRules();
    (async () => {
      try {
        const response = await api.get('/admin/categories', { per_page: 200 });
        setCategories(response.data || []);
      } catch {
        setCategories([]);
      }
    })();
  }, [loadRules]);

  async function deactivateRule(rule) {
    if (!window.confirm('Deactivate this pricing rule?')) return;
    setBusyUuid(rule.uuid);
    try {
      await api.delete(`/admin/pricing/rules/${encodeURIComponent(rule.uuid)}`);
      toast('Pricing rule deactivated.');
      await loadRules();
    } catch (err) {
      reportError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  function openNewRule() {
    if (formOpen && !editingRule) { setFormOpen(false); return; }
    setEditingRule(null);
    setFormOpen(true);
  }

  function openEditRule(rule) {
    setEditingRule(rule);
    setFormOpen(true);
  }

  function closeForm() {
    setFormOpen(false);
    setEditingRule(null);
  }

  return (
    <div>
      <h1 className="admin-page-title">Pricing</h1>

      <ModeCard />

      <div className="pricing-card">
        <div className="pricing-card__header">Live pricing</div>
        <div className="pricing-card__body" style={{ padding: 0 }}>
          <LivePricing />
        </div>
      </div>

      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h2 className="admin-page-title" style={{ fontSize: 16, margin: 0 }}>Pricing rules</h2>
        <button className="admin-btn admin-btn--primary" type="button" onClick={openNewRule}>Add a rule</button>
      </div>

      {formOpen && (
        <RuleForm
          key={editingRule ? editingRule.uuid : 'new'}
          rule={editingRule}
          categories={categories}
          onCancel={closeForm}
          onSaved={() => { closeForm(); loadRules(); }}
        />
      )}

      {loading ? <LoadingState /> : error ? <ErrorState error={error} /> : (
        <RulesTable rules={rules} onEdit={openEditRule} onDeactivate={deactivateRule} busyUuid={busyUuid} />
      )}
    </div>
  );
}
