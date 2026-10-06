/**
 * Banners tab — the rotating home-page banner and the other advert slots.
 *
 * A banner is either a text panel (eyebrow, headline, supporting line,
 * button and promo code on a colour of your choosing) or the same with a
 * photo on the right. The live preview uses the storefront's own
 * BannerSlide component, so what you see here is what customers see.
 * The link target is always chosen from a picker backed by the real
 * catalogue, never typed, so a banner can't point at something that
 * doesn't exist.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState, EmptyState } from '../../components/admin/shared.jsx';
import BannerSlide from '../../components/BannerSlide';
import './Content.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const PLACEMENTS = [
  ['home_hero', 'Home — rotating banner at the top'],
  ['home_strip', 'Home — slim strip below the categories'],
  ['category_top', 'Above a category listing'],
  ['checkout', 'Checkout page'],
  ['app_home', 'Mobile app home'],
];

const LINK_TYPES = [
  ['none', 'Not clickable'],
  ['collection', 'Open a campaign page'],
  ['category', 'Open a category'],
  ['product', 'Open a product'],
  ['offer', 'Open an offer'],
  ['url', 'Open a web address'],
];

const LINK_HINTS = {
  collection: 'Opens your campaign page. Build one under "Campaign pages".',
  category: 'Opens this category in the shop.',
  product: 'Opens this product. Unpublished products are allowed — useful for a launch.',
  offer: 'Opens the shop filtered to this offer.',
};

/** Background / text pairs from the storefront design, readable by default. */
const COLOR_PRESETS = [
  ['#2a2829', '#ffffff', 'Charcoal'],
  ['#f3e2b3', '#2a2829', 'Turmeric'],
  ['#8f1f15', '#ffffff', 'Deep red'],
  ['#c62d1f', '#ffffff', 'Chilli red'],
  ['#2e7d32', '#ffffff', 'Leaf green'],
  ['#efdfca', '#2a2829', 'Almond'],
];

const EMPTY_FORM = {
  eyebrow: '',
  title: '',
  subtitle: '',
  cta_label: '',
  promo_code: '',
  bg_color: '#2a2829',
  text_color: '#ffffff',
  placement: PLACEMENTS[0][0],
  link_type: 'none',
  display_order: '100',
  start_date: '',
  end_date: '',
  alt_text: '',
};

function flattenCategories(list, depth = 0) {
  return list.flatMap((item) => [
    { value: item.slug, label: `${'— '.repeat(depth)}${item.name}` },
    ...flattenCategories(item.children || [], depth + 1),
  ]);
}

function statusOf(banner) {
  if (!banner.is_active) return ['Paused', '#8f898a'];
  const now = Date.now();
  const start = banner.schedule?.start_date ? new Date(banner.schedule.start_date.replace(' ', 'T')).getTime() : null;
  const end = banner.schedule?.end_date ? new Date(banner.schedule.end_date.replace(' ', 'T')).getTime() : null;
  if (start && start > now) return ['Scheduled', '#b7791f'];
  if (end && end < now) return ['Ended', '#8f898a'];
  return ['Live', '#2e7d32'];
}

function contrastRatio(a, b) {
  const lum = (hex) => {
    const c = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
      .map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
  return (x + 0.05) / (y + 0.05);
}

export default function ContentBanners() {
  const [banners, setBanners] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyUuid, setBusyUuid] = useState(null);
  const [accent, setAccent] = useState('#ffd23f');

  const [editing, setEditing] = useState(null); // banner being edited, or null for a new one
  const [form, setForm] = useState(EMPTY_FORM);
  const [linkValue, setLinkValue] = useState('');
  const [linkUrl, setLinkUrl] = useState('');
  const [linkOptions, setLinkOptions] = useState([]);
  const [linkOptionsLoading, setLinkOptionsLoading] = useState(false);
  const [imagePreview, setImagePreview] = useState(null);
  const [removeImage, setRemoveImage] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const fileInputRef = useRef(null);
  const choicesCache = useRef({});
  const pendingLink = useRef(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/banners');
      setBanners(response.data.banners || response.data || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    api.get('/admin/storefront/theme')
      .then((response) => setAccent(response.data.theme.accent))
      .catch(() => {});
  }, [load]);

  // Fetched once per type and reused, so changing the link type back and
  // forth doesn't re-query every time.
  const loadChoices = useCallback(async (type) => {
    if (choicesCache.current[type]) return choicesCache.current[type];

    let options = [];
    try {
      if (type === 'category') {
        const response = await api.get('/admin/categories', { per_page: 200 });
        options = flattenCategories(response.data.categories || response.data || []);
      } else if (type === 'product') {
        const response = await api.get('/admin/products', { per_page: 200 });
        options = (response.data || []).map((item) => ({
          value: item.slug,
          label: item.status === 'published' ? item.name : `${item.name} (not on sale)`,
        }));
      } else if (type === 'collection') {
        const response = await api.get('/admin/collections');
        options = (response.data.collections || []).map((item) => ({
          value: item.slug,
          label: item.is_live ? item.title : `${item.title} (not live)`,
        }));
      } else if (type === 'offer') {
        const response = await api.get('/admin/offers', { per_page: 100 });
        options = (response.data || []).map((item) => ({
          value: item.code,
          label: `${item.code} — ${item.title}`,
        }));
      }
    } catch {
      options = [];
    }

    choicesCache.current[type] = options;
    return options;
  }, []);

  useEffect(() => {
    const type = form.link_type;
    // When an existing banner is loaded for editing, keep its link target.
    const keep = pendingLink.current;
    pendingLink.current = null;
    setLinkValue(keep && type !== 'url' ? keep : '');
    setLinkUrl(keep && type === 'url' ? keep : '');

    if (type === 'none' || type === 'url') {
      setLinkOptions([]);
      return undefined;
    }

    let cancelled = false;
    setLinkOptionsLoading(true);
    loadChoices(type).then((options) => {
      if (!cancelled) {
        setLinkOptions(options);
        setLinkOptionsLoading(false);
      }
    });
    return () => { cancelled = true; };
  }, [form.link_type, loadChoices]);

  const set = (name) => (event) => setForm((f) => ({ ...f, [name]: event.target.value }));

  function resetForm() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setLinkValue('');
    setLinkUrl('');
    setImagePreview(null);
    setRemoveImage(false);
    if (fileInputRef.current) fileInputRef.current.value = '';
  }

  function startEdit(banner) {
    setEditing(banner);
    pendingLink.current = banner.link?.value || null;
    setForm({
      eyebrow: banner.eyebrow || '',
      title: banner.title || '',
      subtitle: banner.subtitle || '',
      cta_label: banner.cta_label || '',
      promo_code: banner.promo_code || '',
      bg_color: banner.bg_color || '#2a2829',
      text_color: banner.text_color || '#ffffff',
      placement: banner.placement,
      link_type: banner.link?.type || 'none',
      display_order: String(banner.display_order ?? 100),
      start_date: String(banner.schedule?.start_date || '').slice(0, 10),
      end_date: String(banner.schedule?.end_date || '').slice(0, 10),
      alt_text: banner.alt_text || '',
    });
    setImagePreview(null);
    setRemoveImage(false);
    if (fileInputRef.current) fileInputRef.current.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function onPickImage() {
    const file = fileInputRef.current?.files[0];
    setRemoveImage(false);
    setImagePreview(file ? URL.createObjectURL(file) : null);
  }

  async function handleSubmit(event) {
    event.preventDefault();
    const file = fileInputRef.current?.files[0];
    const type = form.link_type;

    if (type === 'url') {
      if (!/^https?:\/\//i.test(linkUrl.trim())) {
        toast('A web address must start with https://', 'danger');
        return;
      }
    } else if (type !== 'none' && !linkValue) {
      toast('Choose what the banner should open.', 'danger');
      return;
    }

    const fields = {
      title: form.title,
      subtitle: form.subtitle,
      eyebrow: form.eyebrow,
      cta_label: form.cta_label,
      promo_code: form.promo_code.toUpperCase(),
      bg_color: form.bg_color,
      text_color: form.text_color,
      placement: form.placement,
      link_type: type,
      link_value: type === 'url' ? linkUrl.trim() : (type === 'none' ? '' : linkValue),
      display_order: form.display_order || '100',
      alt_text: form.alt_text,
      start_date: form.start_date ? `${form.start_date} 00:00:00` : '',
      end_date: form.end_date ? `${form.end_date} 23:59:59` : '',
    };

    setSubmitting(true);
    try {
      if (editing) {
        const patch = { ...fields, display_order: Number(fields.display_order) };
        // Blank optional fields are cleared rather than skipped.
        ['subtitle', 'eyebrow', 'cta_label', 'promo_code', 'alt_text', 'link_value', 'start_date', 'end_date'].forEach((k) => {
          if (patch[k] === '') patch[k] = null;
        });
        await api.patch(`/admin/banners/${encodeURIComponent(editing.uuid)}`, patch);
        if (file) {
          const body = new FormData();
          body.append('image', file);
          await api.upload(`/admin/banners/${encodeURIComponent(editing.uuid)}/image`, body);
        } else if (removeImage && editing.image_url) {
          await api.delete(`/admin/banners/${encodeURIComponent(editing.uuid)}/image`);
        }
        toast('Banner saved.');
      } else {
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => {
          if (value !== '' && value !== null) body.append(key, value);
        });
        if (file) body.append('image', file);
        await api.upload('/admin/banners', body);
        toast('Banner added.');
      }
      resetForm();
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setSubmitting(false);
    }
  }

  async function toggleActive(banner) {
    setBusyUuid(banner.uuid);
    try {
      await api.patch(`/admin/banners/${encodeURIComponent(banner.uuid)}`, { is_active: !banner.is_active });
      toast(banner.is_active ? 'Banner paused.' : 'Banner is live again.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  async function handleRemove(banner) {
    if (!window.confirm('Remove this banner?')) return;

    setBusyUuid(banner.uuid);
    try {
      await api.delete(`/admin/banners/${encodeURIComponent(banner.uuid)}`);
      toast('Banner removed.');
      if (editing && editing.uuid === banner.uuid) resetForm();
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  const type = form.link_type;
  const isUrl = type === 'url';
  const previewImage = imagePreview || (editing && !removeImage ? editing.image_url : null);
  const contrast = contrastRatio(form.bg_color, form.text_color);
  const sorted = [...banners].sort((a, b) => (a.placement === b.placement
    ? a.display_order - b.display_order
    : String(a.placement).localeCompare(String(b.placement))));

  return (
    <div className="content-grid">
      <div className="content-card content-card--wide">
        <div className="content-card__header">Banners</div>
        {loading ? (
          <LoadingState />
        ) : error ? (
          <div className="content-card__body"><ErrorState error={error} /></div>
        ) : banners.length === 0 ? (
          <div className="content-card__body">
            <EmptyState title="No banners yet" hint="Add one on the right. Home banners rotate at the top of the shop." />
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr><th>Banner</th><th>Where</th><th>Order</th><th>Status</th><th>Seen</th><th>Clicks</th><th></th></tr>
              </thead>
              <tbody>
                {sorted.map((banner) => {
                  const [label, color] = statusOf(banner);
                  return (
                    <tr key={banner.uuid}>
                      <td>
                        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                          <span
                            aria-hidden="true"
                            style={{
                              width: 44, height: 28, borderRadius: 4, flex: 'none',
                              background: banner.image_url ? `center / cover url("${banner.image_url}")` : banner.bg_color,
                              border: '1px solid #e2ded9',
                            }}
                          />
                          <div>
                            <div style={{ fontWeight: 600 }}>{banner.title}</div>
                            <div className="content-subtext">
                              {[banner.eyebrow, banner.promo_code].filter(Boolean).join(' · ') || banner.subtitle || ''}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>{String(banner.placement).replace(/_/g, ' ')}</td>
                      <td>{banner.display_order}</td>
                      <td><span style={{ color, fontWeight: 600 }}>{label}</span></td>
                      <td>{banner.stats?.impressions ?? 0}</td>
                      <td>{banner.stats?.clicks ?? 0}</td>
                      <td style={{ whiteSpace: 'nowrap' }}>
                        <button className="admin-btn" disabled={busyUuid === banner.uuid} onClick={() => startEdit(banner)}>Edit</button>{' '}
                        <button className="admin-btn" disabled={busyUuid === banner.uuid} onClick={() => toggleActive(banner)}>
                          {banner.is_active ? 'Pause' : 'Resume'}
                        </button>{' '}
                        <button className="admin-btn" disabled={busyUuid === banner.uuid} onClick={() => handleRemove(banner)}>Remove</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="content-note" style={{ padding: '0 16px 16px' }}>
          Home banners rotate in the order shown (lowest number first). Rotation speed and the button colour are set
          under <b>Appearance</b>. A slot with nothing live in it collapses rather than leaving a gap.
        </p>
      </div>

      <div className="content-card">
        <div className="content-card__header">{editing ? 'Edit banner' : 'Add a banner'}</div>
        <div className="content-card__body">
          <div style={{ borderRadius: 8, overflow: 'hidden', marginBottom: 16, boxShadow: '0 1px 3px rgba(0,0,0,.12)' }}>
            <BannerSlide banner={{ ...form, image_url: previewImage }} accent={accent} compact />
          </div>

          <form onSubmit={handleSubmit}>
            <div className="content-field">
              <label htmlFor="b_eyebrow">Small label</label>
              <input id="b_eyebrow" maxLength={60} placeholder="FESTIVE SALE" value={form.eyebrow} onChange={set('eyebrow')} />
            </div>

            <div className="content-field">
              <label htmlFor="b_title">Headline *</label>
              <input id="b_title" required minLength={2} maxLength={160} placeholder="Up to 20% off dry fruits" value={form.title} onChange={set('title')} />
            </div>

            <div className="content-field">
              <label htmlFor="b_subtitle">Supporting line</label>
              <input id="b_subtitle" maxLength={240} placeholder="Almonds, cashews, pistachios and dates at festive prices." value={form.subtitle} onChange={set('subtitle')} />
            </div>

            <div className="content-row-form">
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="b_cta">Button text</label>
                <input id="b_cta" maxLength={60} placeholder="Shop dry fruits" value={form.cta_label} onChange={set('cta_label')} />
              </div>
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="b_code">Promo code</label>
                <input id="b_code" maxLength={40} placeholder="FESTIVE20" style={{ textTransform: 'uppercase' }} value={form.promo_code} onChange={set('promo_code')} />
              </div>
            </div>

            <div className="content-field">
              <label>Colours</label>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 8 }}>
                {COLOR_PRESETS.map(([bg, fg, name]) => (
                  <button
                    key={bg}
                    type="button"
                    title={name}
                    aria-label={`${name} preset`}
                    onClick={() => setForm((f) => ({ ...f, bg_color: bg, text_color: fg }))}
                    style={{
                      width: 32, height: 32, borderRadius: 6, background: bg, color: fg, cursor: 'pointer',
                      border: form.bg_color === bg ? '2px solid #2a2829' : '1px solid #d9d4cf', font: '700 13px system-ui',
                    }}
                  >
                    Aa
                  </button>
                ))}
              </div>
              <div className="content-row-form">
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, fontWeight: 500 }}>
                  <input type="color" value={form.bg_color} onChange={set('bg_color')} style={{ width: 40, height: 32, padding: 0 }} /> Background
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, fontWeight: 500 }}>
                  <input type="color" value={form.text_color} onChange={set('text_color')} style={{ width: 40, height: 32, padding: 0 }} /> Text
                </label>
              </div>
              {contrast < 4.5 && (
                <div className="content-hint" style={{ color: '#b7791f' }}>
                  Low contrast ({contrast.toFixed(1)}:1) — the text may be hard to read. Pick a darker or lighter text colour.
                </div>
              )}
            </div>

            <div className="content-field">
              <label htmlFor="b_placement">Where it appears *</label>
              <select id="b_placement" required value={form.placement} onChange={set('placement')}>
                {PLACEMENTS.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            <div className="content-field">
              <label htmlFor="b_link_type">On click</label>
              <select id="b_link_type" value={form.link_type} onChange={set('link_type')}>
                {LINK_TYPES.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            {type !== 'none' && (
              <div className="content-field">
                <label htmlFor="b_link_value">Which one</label>
                {isUrl ? (
                  <input id="b_link_value" placeholder="https://example.com/page"
                         value={linkUrl} onChange={(e) => setLinkUrl(e.target.value)} />
                ) : (
                  <select id="b_link_value" value={linkValue} onChange={(e) => setLinkValue(e.target.value)}>
                    <option value="">{linkOptionsLoading ? 'Loading…' : (linkOptions.length === 0 ? 'Nothing available yet' : 'Choose…')}</option>
                    {linkOptions.map((option) => (
                      <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                  </select>
                )}
                <div className="content-hint">{isUrl ? 'A full web address including https://' : (LINK_HINTS[type] || '')}</div>
              </div>
            )}

            <div className="content-row-form">
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="b_start">Runs from</label>
                <input id="b_start" type="date" value={form.start_date} onChange={set('start_date')} />
              </div>
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="b_end">Until</label>
                <input id="b_end" type="date" value={form.end_date} onChange={set('end_date')} />
              </div>
              <div className="content-field" style={{ width: 90 }}>
                <label htmlFor="b_order">Order</label>
                <input id="b_order" type="number" min="1" max="9999" value={form.display_order} onChange={set('display_order')} />
              </div>
            </div>

            <div className="content-field">
              <label htmlFor="b_image">Photo (optional)</label>
              <input id="b_image" type="file" accept="image/jpeg,image/png,image/webp" ref={fileInputRef} onChange={onPickImage} />
              <div className="content-hint">Shown on the right of the banner. Roughly 1200×800 works well. Without one, the banner is the coloured panel only.</div>
              {editing && editing.image_url && !imagePreview && (
                <label style={{ display: 'flex', gap: 6, alignItems: 'center', fontWeight: 500, marginTop: 6 }}>
                  <input type="checkbox" checked={removeImage} onChange={(e) => setRemoveImage(e.target.checked)} /> Remove the current photo
                </label>
              )}
            </div>

            <div className="content-field">
              <label htmlFor="b_alt">Photo description for screen readers</label>
              <input id="b_alt" maxLength={180} placeholder="Festive dry fruit box on a table"
                     value={form.alt_text} onChange={set('alt_text')} />
            </div>

            <div style={{ display: 'flex', gap: 8 }}>
              <button className="admin-btn admin-btn--primary" type="submit" disabled={submitting}>
                {submitting ? 'Saving…' : editing ? 'Save banner' : 'Add banner'}
              </button>
              {editing && <button className="admin-btn" type="button" onClick={resetForm}>Cancel</button>}
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
