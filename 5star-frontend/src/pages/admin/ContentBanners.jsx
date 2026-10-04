/**
 * Adverts tab. Ported from page-content.js's renderBanners() — a banner
 * image with an optional click-through, scheduled by date. The link target
 * is always chosen from a picker backed by the real catalogue, never typed,
 * so an advert can't point at something that doesn't exist.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState, EmptyState } from '../../components/admin/shared.jsx';
import './Content.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const PLACEMENTS = [
  ['home_hero', 'Home — large banner at the top'],
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

const EMPTY_FORM = {
  title: '',
  subtitle: '',
  placement: PLACEMENTS[0][0],
  link_type: 'none',
  cta_label: '',
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

export default function ContentBanners() {
  const [banners, setBanners] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [removingUuid, setRemovingUuid] = useState(null);

  const [form, setForm] = useState(EMPTY_FORM);
  const [linkValue, setLinkValue] = useState('');
  const [linkUrl, setLinkUrl] = useState('');
  const [linkOptions, setLinkOptions] = useState([]);
  const [linkOptionsLoading, setLinkOptionsLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const fileInputRef = useRef(null);
  const choicesCache = useRef({});

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
  }, [load]);

  // Fetched once per type and reused, so changing the link type back and
  // forth doesn't re-query every time.
  const loadChoices = useCallback(async (type) => {
    if (choicesCache.current[type]) return choicesCache.current[type];

    let options = [];
    try {
      if (type === 'category') {
        const response = await api.get('/admin/categories');
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
    setLinkValue('');
    setLinkUrl('');

    if (type === 'none' || type === 'url') {
      setLinkOptions([]);
      return;
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

  async function handleSubmit(event) {
    event.preventDefault();
    const file = fileInputRef.current?.files[0];

    if (!file) {
      toast('Choose a picture for the advert.', 'danger');
      return;
    }

    const type = form.link_type;

    if (type === 'url') {
      if (!/^https?:\/\//i.test(linkUrl.trim())) {
        toast('A web address must start with https://', 'danger');
        return;
      }
    } else if (type !== 'none' && !linkValue) {
      toast('Choose what the advert should open.', 'danger');
      return;
    }

    const body = new FormData();
    body.append('title', form.title);
    if (form.subtitle) body.append('subtitle', form.subtitle);
    body.append('placement', form.placement);
    body.append('link_type', type);
    if (form.cta_label) body.append('cta_label', form.cta_label);
    if (form.alt_text) body.append('alt_text', form.alt_text);

    if (type === 'url') {
      body.append('link_value', linkUrl.trim());
    } else if (type !== 'none') {
      body.append('link_value', linkValue);
    }

    if (form.start_date) body.append('start_date', `${form.start_date} 00:00:00`);
    if (form.end_date) body.append('end_date', `${form.end_date} 23:59:59`);

    body.append('image', file);

    setSubmitting(true);
    try {
      await api.upload('/admin/banners', body);
      toast('Advert added.');
      setForm(EMPTY_FORM);
      setLinkValue('');
      setLinkUrl('');
      if (fileInputRef.current) fileInputRef.current.value = '';
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRemove(banner) {
    if (!window.confirm('Remove this advert?')) return;

    setRemovingUuid(banner.uuid);
    try {
      await api.delete(`/admin/banners/${encodeURIComponent(banner.uuid)}`);
      toast('Advert removed.');
      await load();
    } catch (err) {
      reportError(err);
    } finally {
      setRemovingUuid(null);
    }
  }

  const type = form.link_type;
  const isUrl = type === 'url';

  return (
    <div className="content-grid">
      <div className="content-card content-card--wide">
        <div className="content-card__header">Adverts</div>
        {loading ? (
          <LoadingState />
        ) : error ? (
          <div className="content-card__body"><ErrorState error={error} /></div>
        ) : banners.length === 0 ? (
          <div className="content-card__body">
            <EmptyState title="No adverts yet" hint="Add one on the right. It appears in the shop as soon as it is live." />
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr><th>Advert</th><th>Where</th><th>Seen</th><th>Clicks</th><th></th></tr>
              </thead>
              <tbody>
                {banners.map((banner) => (
                  <tr key={banner.uuid}>
                    <td>
                      <div style={{ fontWeight: 600 }}>{banner.title}</div>
                      <div className="content-subtext">{banner.subtitle || ''}</div>
                    </td>
                    <td>{String(banner.placement).replace(/_/g, ' ')}</td>
                    <td>{banner.impression_count ?? 0}</td>
                    <td>{banner.click_count ?? 0}</td>
                    <td>
                      <button className="admin-btn" disabled={removingUuid === banner.uuid} onClick={() => handleRemove(banner)}>
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="content-note" style={{ padding: '0 16px 16px' }}>
          Adverts never block the page. They load after the products, they can be dismissed, and
          a slot with nothing live in it collapses to nothing rather than leaving a gap.
        </p>
      </div>

      <div className="content-card">
        <div className="content-card__header">Add an advert</div>
        <div className="content-card__body">
          <form onSubmit={handleSubmit}>
            <div className="content-field">
              <label htmlFor="b_title">Headline *</label>
              <input id="b_title" required minLength={2} value={form.title} onChange={set('title')} />
            </div>

            <div className="content-field">
              <label htmlFor="b_subtitle">Supporting line</label>
              <input id="b_subtitle" value={form.subtitle} onChange={set('subtitle')} />
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

            <div className="content-field">
              <label htmlFor="b_cta">Button text</label>
              <input id="b_cta" placeholder="Shop the offer" value={form.cta_label} onChange={set('cta_label')} />
            </div>

            <div className="content-row-form">
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="b_start">Runs from</label>
                <input id="b_start" type="date" value={form.start_date} onChange={set('start_date')} />
              </div>
              <div className="content-field" style={{ flex: 1 }}>
                <label htmlFor="b_end">Until</label>
                <input id="b_end" type="date" value={form.end_date} onChange={set('end_date')} />
              </div>
            </div>

            <div className="content-field">
              <label htmlFor="b_image">Picture *</label>
              <input id="b_image" type="file" accept="image/jpeg,image/png,image/webp" required ref={fileInputRef} />
              <div className="content-hint">
                Wide — roughly 1600×500 works well. Required: an advert without a picture has nothing to show.
              </div>
            </div>

            <div className="content-field">
              <label htmlFor="b_alt">Description for screen readers</label>
              <input id="b_alt" placeholder="Diwali gift hampers on a festive table"
                     value={form.alt_text} onChange={set('alt_text')} />
            </div>

            <button className="admin-btn admin-btn--primary" type="submit" disabled={submitting}>
              {submitting ? 'Adding…' : 'Add advert'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
