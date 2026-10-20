import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from './toast';
import './CategoryPicker.css';

/**
 * Category → Sub-category picking for every "add an item" screen, with
 * suggestions read from the item's name (GET /admin/inventory/suggest-category).
 *
 * `categories` is the flat list from GET /admin/inventory/setup:
 * [{uuid, slug, name, parent_uuid, item_type, has_children}].
 *
 * Rules the picker enforces, matching the server:
 *  - a category is required;
 *  - when the category has sub-categories, one of them is required.
 */

/** Kind of business a new top-level category is for (inherited by its sub-categories). */
export const ITEM_TYPE_OPTIONS = [
  ['grocery', 'Grocery (spices, dry fruits, staples)'], ['oils', 'Oils & ghee'], ['clothing', 'Clothing'],
  ['footwear', 'Footwear'], ['perfumes', 'Perfumes & attar'], ['personal_care', 'Personal care'],
  ['pooja', 'Pooja items'], ['home_kitchen', 'Home & kitchen'], ['toys', 'Toys'], ['stationery', 'Stationery'],
  ['general', 'Other / general'],
];

export const topsOf = (categories) => (categories || []).filter((c) => !c.parent_uuid);
export const childrenOf = (categories, uuid) => (categories || []).filter((c) => c.parent_uuid === uuid);

/** The category an item is filed under, or '' if the choice is not complete. */
export function leafOf(categories, topUuid, subUuid) {
  if (!topUuid) return '';
  return childrenOf(categories, topUuid).length > 0 ? (subUuid || '') : topUuid;
}

/** "Clothing › Men" for a leaf uuid. */
export function pathLabel(categories, uuid) {
  const node = (categories || []).find((c) => c.uuid === uuid);
  if (!node) return '';
  const parent = node.parent_uuid ? categories.find((c) => c.uuid === node.parent_uuid) : null;
  return parent ? `${parent.name} › ${node.name}` : node.name;
}

/** Splits a stored category uuid into {topUuid, subUuid}. */
export function splitCategory(categories, uuid) {
  const node = (categories || []).find((c) => c.uuid === uuid || c.slug === uuid);
  if (!node) return { topUuid: '', subUuid: '' };
  return node.parent_uuid ? { topUuid: node.parent_uuid, subUuid: node.uuid } : { topUuid: node.uuid, subUuid: '' };
}

/** Debounced suggestions for an item name. */
export function useCategorySuggestions(name) {
  const [state, setState] = useState({ loading: false, items: [], for: '' });

  useEffect(() => {
    const query = String(name || '').trim();
    if (query.length < 3) {
      setState({ loading: false, items: [], for: '' });
      return undefined;
    }
    let cancelled = false;
    setState((s) => ({ ...s, loading: true }));
    const timer = setTimeout(async () => {
      try {
        const response = await api.get('/admin/inventory/suggest-category', { name: query });
        if (!cancelled) setState({ loading: false, items: response.data.suggestions || [], for: query });
      } catch {
        if (!cancelled) setState({ loading: false, items: [], for: query });
      }
    }, 350);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [name]);

  return state;
}

/**
 * Makes a suggestion real: creates whatever part of "Category › Sub" does not
 * exist yet (POST /admin/inventory/quick-category — which reuses a same-named
 * category instead of duplicating it), reports each new category through
 * onCreated, and returns the uuids to select.
 */
export async function applySuggestion(suggestion, categories, onCreated) {
  let topUuid = suggestion.category.uuid;
  const list = [...(categories || [])];

  if (!topUuid) {
    const r = await api.post('/admin/inventory/quick-category', {
      name: suggestion.category.name,
      item_type: suggestion.category.item_type || 'general',
    });
    const c = r.data.category;
    topUuid = c.uuid;
    const node = { uuid: c.uuid, slug: c.slug, name: c.name, parent_uuid: null, item_type: c.item_type || suggestion.category.item_type || 'general', has_children: false };
    if (!list.some((x) => x.uuid === node.uuid)) { list.push(node); onCreated?.(node); }
  }

  let subUuid = '';
  if (suggestion.sub_category) {
    subUuid = suggestion.sub_category.uuid || '';
    if (!subUuid) {
      const r = await api.post('/admin/inventory/quick-category', { name: suggestion.sub_category.name, parent_uuid: topUuid });
      const c = r.data.category;
      subUuid = c.uuid;
      const top = list.find((x) => x.uuid === topUuid);
      const node = { uuid: c.uuid, slug: c.slug, name: c.name, parent_uuid: topUuid, item_type: top?.item_type || suggestion.category.item_type || 'general', has_children: false };
      if (!list.some((x) => x.uuid === node.uuid)) { list.push(node); onCreated?.(node); }
    }
  }

  return { topUuid, subUuid };
}

/** "Suggested for this item: [Clothing › Ethnic Wear] [+ Perfumes › Attar]". */
export function CategorySuggestions({ name, categories, current, onPick, onCreated }) {
  const { loading, items } = useCategorySuggestions(name);
  const [busy, setBusy] = useState('');

  if (String(name || '').trim().length < 3) return null;

  async function pick(s) {
    setBusy(s.label);
    try {
      const picked = await applySuggestion(s, categories, onCreated);
      onPick(picked, s);
      if (!s.exists) toast(`Added ${s.label} to your categories.`);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not use that category.', 'danger');
    } finally {
      setBusy('');
    }
  }

  return (
    <div className="cp-suggest" aria-live="polite">
      <span className="cp-suggest__lead">{loading ? 'Reading the name…' : items.length ? 'Suggested:' : 'No suggestion — choose below.'}</span>
      {items.map((s) => {
        const on = current && s.leaf_uuid && current === s.leaf_uuid;
        return (
          <button
            key={s.label}
            type="button"
            className={`cp-chip${on ? ' is-on' : ''}${s.exists ? '' : ' cp-chip--new'}`}
            disabled={Boolean(busy)}
            title={(s.reasons || []).join('; ')}
            onClick={() => pick(s)}
          >
            {s.exists ? '' : '+ '}{s.label}
            {s.needs_sub_category && <em> · pick sub-category</em>}
            {!s.exists && <em> · new</em>}
            {busy === s.label && '…'}
          </button>
        );
      })}
    </div>
  );
}

/**
 * Two required selects (Category, then Sub-category when it has any) plus the
 * name-based suggestions. Controlled: value = {topUuid, subUuid}.
 */
export default function CategoryPicker({
  categories, name, value, onChange, onCreated, disabled = false, idPrefix = 'cp', showErrors = false,
}) {
  const tops = topsOf(categories);
  const subs = value.topUuid ? childrenOf(categories, value.topUuid) : [];
  const subRef = useRef(null);
  // Inline "+ New category" / "+ New sub-category".
  const [adding, setAdding] = useState(null); // 'top' | 'sub' | null
  const [newName, setNewName] = useState('');
  const [newType, setNewType] = useState('grocery');
  const [creating, setCreating] = useState(false);
  const parent = value.topUuid ? (categories || []).find((c) => c.uuid === value.topUuid) : null;

  function openAdd(kind) {
    setAdding(kind);
    setNewName('');
    setNewType(parent?.item_type || 'grocery');
  }

  async function createNew() {
    const name = newName.trim();
    if (name.length < 2) { toast('Type a name of at least 2 letters.', 'danger'); return; }
    setCreating(true);
    try {
      const body = adding === 'sub'
        ? { name, parent_uuid: value.topUuid }
        : { name, item_type: newType };
      const r = await api.post('/admin/inventory/quick-category', body);
      const c = r.data.category;
      const node = {
        uuid: c.uuid,
        slug: c.slug,
        name: c.name,
        parent_uuid: adding === 'sub' ? value.topUuid : null,
        item_type: c.item_type || (adding === 'sub' ? parent?.item_type : newType) || 'general',
        has_children: false,
      };
      if (!(categories || []).some((x) => x.uuid === node.uuid)) onCreated?.(node);
      onChange(adding === 'sub' ? { topUuid: value.topUuid, subUuid: node.uuid } : { topUuid: node.uuid, subUuid: '' });
      toast(c.existing ? `"${c.name}" already exists — selected it.` : `Added ${adding === 'sub' ? 'sub-category' : 'category'} "${c.name}".`);
      setAdding(null);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not add the category.', 'danger');
    } finally {
      setCreating(false);
    }
  }
  const leaf = leafOf(categories, value.topUuid, value.subUuid);
  const missingTop = showErrors && !value.topUuid;
  const missingSub = showErrors && value.topUuid && subs.length > 0 && !value.subUuid;

  return (
    <div className="cp">
      <CategorySuggestions
        name={name}
        categories={categories}
        current={leaf}
        onCreated={onCreated}
        onPick={(picked, s) => {
          onChange(picked);
          if (s.needs_sub_category) setTimeout(() => subRef.current?.focus(), 0);
        }}
      />
      <div className="cp-selects">
        <label className="cp-field">
          <span>Category <b className="cp-req">*</b></span>
          <select
            id={`${idPrefix}-category`}
            value={value.topUuid}
            disabled={disabled}
            aria-invalid={missingTop ? 'true' : 'false'}
            onChange={(e) => onChange({ topUuid: e.target.value, subUuid: '' })}
          >
            <option value="">Select category…</option>
            {tops.map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
          </select>
          {missingTop && <small className="cp-err">Choose a category.</small>}
          {!disabled && (
            <button type="button" className="cp-add" onClick={() => openAdd('top')}>+ New category</button>
          )}
        </label>
        <label className="cp-field">
          <span>Sub-category {subs.length > 0 && <b className="cp-req">*</b>}</span>
          <select
            ref={subRef}
            id={`${idPrefix}-subcategory`}
            value={value.subUuid}
            disabled={disabled || !value.topUuid || subs.length === 0}
            aria-invalid={missingSub ? 'true' : 'false'}
            onChange={(e) => onChange({ topUuid: value.topUuid, subUuid: e.target.value })}
          >
            {!value.topUuid && <option value="">Select a category first</option>}
            {value.topUuid && subs.length === 0 && <option value="">None — items go directly in this category</option>}
            {value.topUuid && subs.length > 0 && (
              <>
                <option value="">Select sub-category…</option>
                {subs.map((c) => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
              </>
            )}
          </select>
          {missingSub && <small className="cp-err">Choose a sub-category.</small>}
          {!disabled && value.topUuid && (
            <button type="button" className="cp-add" onClick={() => openAdd('sub')}>
              + New sub-category{parent ? ` in ${parent.name}` : ''}
            </button>
          )}
        </label>
      </div>
      {adding && (
        <div className="cp-new" role="group" aria-label={adding === 'sub' ? 'New sub-category' : 'New category'}>
          <label className="cp-field">
            <span>{adding === 'sub' ? `New sub-category in ${parent?.name || 'this category'}` : 'New category name'}</span>
            <input
              autoFocus
              value={newName}
              maxLength={120}
              placeholder={adding === 'sub' ? 'e.g. Cold-pressed oils' : 'e.g. Oils & Ghee'}
              onChange={(e) => setNewName(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createNew(); } if (e.key === 'Escape') setAdding(null); }}
            />
          </label>
          {adding === 'top' && (
            <label className="cp-field">
              <span>Kind of items</span>
              <select value={newType} onChange={(e) => setNewType(e.target.value)}>
                {ITEM_TYPE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </label>
          )}
          <div className="cp-new__actions">
            <button type="button" className="admin-btn admin-btn--primary" disabled={creating} onClick={createNew}>
              {creating ? 'Adding…' : 'Add'}
            </button>
            <button type="button" className="admin-btn" disabled={creating} onClick={() => setAdding(null)}>Cancel</button>
          </div>
          {adding === 'sub' && subs.length === 0 && (
            <small className="cp-note">
              Items already filed directly under {parent?.name || 'this category'} stay there until you move them.
            </small>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * Bulk version for spreadsheet imports: resolves each distinct suggestion
 * once (creating a missing "Perfumes › Attar" a single time, not per row)
 * and returns label → {topUuid, subUuid}.
 */
export async function resolveSuggestions(suggestions, categories, onCreated) {
  const out = new Map();
  let list = [...(categories || [])];
  for (const s of suggestions) {
    if (!s || out.has(s.label)) continue;
    // eslint-disable-next-line no-await-in-loop
    const picked = await applySuggestion(s, list, (node) => { list = [...list, node]; onCreated?.(node); });
    out.set(s.label, picked);
  }
  return out;
}

/** Inline "Suggested: X › Y [Use]" for one import row. */
export function RowSuggestion({ suggestion, onUse, disabled }) {
  if (!suggestion) return null;
  return (
    <button
      type="button"
      className={`cp-chip cp-chip--row${suggestion.exists ? '' : ' cp-chip--new'}`}
      disabled={disabled}
      title={(suggestion.reasons || []).join('; ')}
      onClick={onUse}
    >
      Use {suggestion.exists ? '' : '+ '}{suggestion.label}{suggestion.exists ? '' : ' (new)'}
    </button>
  );
}
