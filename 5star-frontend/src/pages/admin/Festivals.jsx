/**
 * Festival Calendar — ported from admin/assets/page-festivals.js.
 *
 * Every major Indian festival and jayanti through the year, so staff can set
 * up that occasion's gift page, add products, and let it close itself once
 * the festival has passed.
 *
 * AUTO-CLOSE NEEDS NO SCHEDULED JOB. A campaign page already stops appearing
 * on the shop the moment `ends_date` is in the past (CollectionRepository's
 * live-page gate). This screen just sets `ends_date` to "festival date + a
 * few days" when a festival is set up, so that existing gate is what closes
 * it; nothing new runs on a timer, and nothing deletes it, so "Reopen for
 * next year" is just moving the date forward.
 *
 * Underneath, this manages the exact same "campaign page" (collection) that
 * Shopfront -> Campaign pages does — a festival here and a campaign page
 * there are the same record, found by a shared slug. Deeper settings
 * (subtitle, intro, SEO) still live on that screen, linked to below.
 */
import { useCallback, useEffect, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState, EmptyState } from '../../components/admin/shared.jsx';
import { cycleGroups, addDays, DEFAULT_CLOSE_AFTER_DAYS } from './festivalCalendarData';
import './Festivals.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const todayStr = () => new Date().toISOString().slice(0, 10);

function closeAfter(festival) {
  return festival.closeAfterDays || DEFAULT_CLOSE_AFTER_DAYS;
}

/** Is this collection's date window in the past — closed, whether or not `status` still says published? */
function isExpired(entry) {
  return Boolean(entry && entry.ends_date && String(entry.ends_date).slice(0, 10) < todayStr());
}

function StatusPill({ entry }) {
  if (!entry) return <span className="status-badge status-badge--secondary">Not set up</span>;
  if (isExpired(entry)) return <span className="status-badge status-badge--secondary">Closed for this year</span>;
  return entry.status === 'published'
    ? <span className="status-badge status-badge--success">Published</span>
    : <span className="status-badge status-badge--warning">Draft</span>;
}

function ConfidenceNote({ festival }) {
  if (festival.confidence === 'confirmed') return null;
  if (festival.confidence === 'uncertain') {
    return <div className="festivals-confidence festivals-confidence--danger">Date is a rough guess — confirm before relying on it.</div>;
  }
  return <div className="festivals-confidence festivals-confidence--warning">Estimated date — worth double-checking.</div>;
}

/** The item-management panel for one festival — dates, a picture, products, publish. */
function FestivalEditor({ festival, products, onChanged }) {
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [itemProduct, setItemProduct] = useState('');
  const [itemHeadline, setItemHeadline] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get(`/admin/collections/${encodeURIComponent(festival.slug)}`);
      setDetail(response.data);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [festival.slug]);

  useEffect(() => { load(); }, [load]);

  if (loading) return <div className="festivals-editor"><LoadingState /></div>;
  if (error) return <div className="festivals-editor"><ErrorState error={error} /></div>;
  if (!detail) return null;

  const collection = detail.collection;
  const items = detail.items || [];
  const chosen = new Set(items.map((item) => item.slug));
  const expired = isExpired(collection);
  const choosable = products.filter((p) => !chosen.has(p.slug));

  async function saveDates(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const starts = form.get('starts_date');
    const ends = form.get('ends_date');
    const payload = {};
    if (starts) payload.starts_date = `${starts} 00:00:00`;
    if (ends) payload.ends_date = `${ends} 23:59:59`;

    setBusy(true);
    try {
      await api.patch(`/admin/collections/${encodeURIComponent(festival.slug)}`, payload);
      toast('Dates saved.');
      await load();
      onChanged();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  async function reopen() {
    const typed = window.prompt(
      `This year's date for ${festival.title}. Check a current calendar — festival dates ` +
      `shift every year and this is only a rough guess.`,
      festival.cycleDate,
    );
    if (!typed) return;

    setBusy(true);
    try {
      await api.patch(`/admin/collections/${encodeURIComponent(festival.slug)}`, {
        starts_date: `${typed} 00:00:00`,
        ends_date: `${addDays(typed, closeAfter(festival))} 23:59:59`,
      });
      await api.post(`/admin/collections/${encodeURIComponent(festival.slug)}/status`, { status: 'published' });
      toast(`Reopened for ${typed}.`);
      await load();
      onChanged();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  async function handleHeroUpload(event) {
    event.preventDefault();
    const file = event.currentTarget.elements.image.files[0];
    if (!file) { toast('Choose a picture first.', 'danger'); return; }

    const body = new FormData();
    body.append('image', file);
    setBusy(true);
    try {
      await api.upload(`/admin/collections/${encodeURIComponent(festival.slug)}/image`, body);
      toast('Picture updated.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  async function setStatus(status) {
    setBusy(true);
    try {
      await api.post(`/admin/collections/${encodeURIComponent(festival.slug)}/status`, { status });
      toast(status === 'published' ? 'This festival’s page is live.' : 'Unpublished.');
      await load();
      onChanged();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  async function addItem(event) {
    event.preventDefault();
    if (!itemProduct) return;

    const payload = { product: itemProduct, display_order: (items.length + 1) * 10 };
    const headline = itemHeadline.trim();
    if (headline) payload.headline = headline;

    setBusy(true);
    try {
      await api.post(`/admin/collections/${encodeURIComponent(festival.slug)}/items`, payload);
      toast('Added to the page.');
      setItemProduct('');
      setItemHeadline('');
      await load();
      onChanged();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  async function removeItem(itemUuid) {
    setBusy(true);
    try {
      await api.delete(`/admin/collections/${encodeURIComponent(festival.slug)}/items/${encodeURIComponent(itemUuid)}`);
      toast('Removed.');
      await load();
      onChanged();
    } catch (err) {
      reportError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="festivals-editor">
      <div className="festivals-editor__top">
        <a className="festivals-link" target="_blank" rel="noopener noreferrer"
           href={`/collection/${encodeURIComponent(festival.slug)}`}>
          Preview on the shop →
        </a>
        <div className="festivals-editor__actions">
          <a className="festivals-link" href={`/admin/content?tab=collections&open=${encodeURIComponent(festival.slug)}`}>
            Full page settings (subtitle, intro, SEO) →
          </a>
          {collection.status === 'published' ? (
            <button className="admin-btn" type="button" disabled={busy} onClick={() => setStatus('draft')}>Unpublish</button>
          ) : (
            <button className="admin-btn admin-btn--primary" type="button" disabled={busy} onClick={() => setStatus('published')}>Publish</button>
          )}
        </div>
      </div>

      {expired && (
        <div className="admin-alert admin-alert--warning festivals-expired">
          <span>
            This closed automatically on {String(collection.ends_date).slice(0, 10)} — it no
            longer shows on the shop, but everything here is kept for next year.
          </span>
          <button className="admin-btn admin-btn--primary" type="button" disabled={busy} onClick={reopen}>
            Reopen for next year
          </button>
        </div>
      )}

      <form className="festivals-dates-form" onSubmit={saveDates}>
        <div className="festivals-dates-form__field">
          <label>Opens</label>
          <input type="date" name="starts_date"
                 defaultValue={String(collection.starts_date || festival.cycleDate || '').slice(0, 10)} />
        </div>
        <div className="festivals-dates-form__field">
          <label>Closes automatically</label>
          <input type="date" name="ends_date"
                 defaultValue={String(collection.ends_date || addDays(festival.cycleDate, closeAfter(festival))).slice(0, 10)} />
        </div>
        <button className="admin-btn" type="submit" disabled={busy}>Save dates</button>
        <p className="festivals-dates-form__hint">
          The page hides itself from the shop the day after "Closes automatically" — no one needs
          to remember to take it down.
        </p>
      </form>

      <div className="festivals-hero">
        {collection.hero_image_url ? (
          <img src={collection.hero_image_url} alt="" className="festivals-hero__image" />
        ) : (
          <div className="festivals-hero__empty">No picture yet.</div>
        )}
        <form className="festivals-hero__form" onSubmit={handleHeroUpload}>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required />
          <button className="admin-btn" type="submit" disabled={busy}>
            {collection.hero_image_url ? 'Replace picture' : 'Upload picture'}
          </button>
        </form>
      </div>

      <div className="festivals-items-grid">
        <div>
          <div className="festivals-section-label">On this page</div>
          {items.length === 0 ? (
            <EmptyState title="Nothing chosen yet" hint="Add products with the form on the right." />
          ) : (
            <ul className="festivals-item-list">
              {items.map((item) => (
                <li key={item.item_uuid} className="festivals-item-list__row">
                  <span>
                    {item.name}
                    {item.headline && <div className="festivals-item-list__headline">{item.headline}</div>}
                    {!item.is_purchasable && (
                      <div className="festivals-item-list__warning">Not on sale — hidden from shoppers</div>
                    )}
                  </span>
                  <button className="admin-btn" type="button" disabled={busy} onClick={() => removeItem(item.item_uuid)}>
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div>
          <div className="festivals-section-label">Add a product</div>
          <form className="festivals-add-form" onSubmit={addItem}>
            <select required value={itemProduct} onChange={(e) => setItemProduct(e.target.value)}>
              <option value="">Choose…</option>
              {choosable.map((p) => (
                <option key={p.slug} value={p.slug}>
                  {p.name}{p.status === 'published' ? '' : ' (not on sale)'}
                </option>
              ))}
            </select>
            <input maxLength={120} placeholder="Why it's here, e.g. “For the mithai box”"
                   value={itemHeadline} onChange={(e) => setItemHeadline(e.target.value)} />
            <button className="admin-btn admin-btn--primary" type="submit" disabled={busy}>Add to page</button>
          </form>
        </div>
      </div>
    </div>
  );
}

function FestivalRow({ festival, entry, isAdmin, products, isOpen, onToggle, onSetup, onChanged, settingUp }) {
  const closeDate = addDays(festival.cycleDate, closeAfter(festival));
  const cycleIsNextYear = Number(festival.cycleDate.slice(0, 4)) > new Date().getFullYear();

  return (
    <div className="festivals-card" data-festival={festival.slug}>
      <div className="festivals-card__row">
        <div>
          <span className="festivals-card__title">{festival.title}</span>{' '}
          <StatusPill entry={entry} />
          <div className="festivals-card__note">{festival.note}</div>
          <div className="festivals-card__note">
            {cycleIsNextYear ? 'Next year' : 'Coming up'}: {festival.cycleDate} · closes automatically {closeDate}
          </div>
          <ConfidenceNote festival={festival} />
          {entry && (
            <div className="festivals-card__note">
              {entry.item_count} item(s) on the page, {entry.purchasable_count} on sale
            </div>
          )}
        </div>
        <div className="festivals-card__actions">
          {isAdmin && (entry ? (
            <button className="admin-btn" type="button" onClick={() => onToggle(festival.slug)}>
              {isOpen ? 'Close' : 'Manage items'}
            </button>
          ) : (
            <button className="admin-btn admin-btn--primary" type="button" disabled={settingUp}
                    onClick={() => onSetup(festival)}>
              {settingUp ? 'Creating…' : 'Set up this festival’s gifts'}
            </button>
          ))}
        </div>
      </div>
      {isOpen && entry && (
        <FestivalEditor festival={festival} products={products} onChanged={onChanged} />
      )}
    </div>
  );
}

export default function Festivals() {
  const { user } = useOutletContext();
  const isAdmin = String(user?.role) === 'administrator';

  const [bySlug, setBySlug] = useState({});
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [expanded, setExpanded] = useState(null);
  const [settingUpSlug, setSettingUpSlug] = useState(null);

  const refreshSummary = useCallback(async () => {
    const response = await api.get('/admin/collections');
    setBySlug(Object.fromEntries((response.data.collections || []).map((c) => [c.slug, c])));
  }, []);

  useEffect(() => {
    let active = true;

    (async () => {
      try {
        const [collectionsResponse, productsResponse] = await Promise.all([
          api.get('/admin/collections'),
          api.get('/admin/products', { per_page: 200 }),
        ]);
        if (!active) return;
        setBySlug(Object.fromEntries((collectionsResponse.data.collections || []).map((c) => [c.slug, c])));
        setProducts(productsResponse.data || []);
        setLoading(false);
      } catch (err) {
        if (!active) return;
        setError(err);
        setLoading(false);
      }
    })();

    return () => { active = false; };
  }, []);

  async function handleSetup(festival) {
    setSettingUpSlug(festival.slug);
    try {
      await api.post('/admin/collections', {
        title: festival.title,
        slug: festival.slug,
        template: 'gift',
        display_order: (festival.month || 13) * 10,
        starts_date: `${festival.cycleDate} 00:00:00`,
        ends_date: `${addDays(festival.cycleDate, closeAfter(festival))} 23:59:59`,
      });
      toast(`${festival.title} gift page created — add products below.`);
      await refreshSummary();
      setExpanded(festival.slug);
      requestAnimationFrame(() => {
        document.querySelector(`[data-festival="${CSS.escape(festival.slug)}"]`)
          ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    } catch (err) {
      reportError(err);
    } finally {
      setSettingUpSlug(null);
    }
  }

  function handleToggle(slug) {
    setExpanded((current) => (current === slug ? null : slug));
  }

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;

  const groups = cycleGroups();

  return (
    <div className="page">
      <h1 className="admin-page-title">Festival Calendar</h1>
      <p className="festivals-intro">
        Indian festivals and jayanthis through the year. Set up a gift page for one, add
        products, and it closes itself automatically a few days after the festival —
        ready to reopen with next year's date rather than starting over.
        Hindu and Islamic dates shift every year, so double-check anything not marked
        "confirmed" before relying on it.
      </p>
      {!isAdmin && (
        <div className="admin-alert admin-alert--warning">
          Only an administrator can set up or change a festival’s gift page. You can still see
          what is already published.
        </div>
      )}

      {groups.map(([label, festivals]) => (
        <div key={label}>
          <h2 className="festivals-group-label">{label}</h2>
          {festivals.map((festival) => (
            <FestivalRow
              key={festival.slug}
              festival={festival}
              entry={bySlug[festival.slug]}
              isAdmin={isAdmin}
              products={products}
              isOpen={expanded === festival.slug}
              onToggle={handleToggle}
              onSetup={handleSetup}
              onChanged={refreshSummary}
              settingUp={settingUpSlug === festival.slug}
            />
          ))}
        </div>
      ))}
    </div>
  );
}
