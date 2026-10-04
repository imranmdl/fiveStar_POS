/**
 * Appearance tab — the storefront's colours and the announcement bar,
 * edited with a live preview and saved to /admin/storefront/theme. The web
 * shop and the Android app read the same settings, so a change shows on both
 * the next time a page loads.
 */
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import { BRAND_LOGO_URL } from '../../lib/brand';
import './Content.css';

/** The v2 design's own values — "Reset" restores these. */
const DESIGN_DEFAULTS = {
  header_bg: '#c62d1f',
  header_text: '#ffffff',
  accent: '#ffd23f',
  primary: '#c62d1f',
  page_bg: '#f1f0ee',
  announcement: 'Dispatched within 24 hours · All prices include GST',
  announcement_bg: '#2a2829',
  announcement_text: '#ffffff',
  tagline: 'Spices & Dry Fruits',
  banner_seconds: 5,
  show_deals: true,
};

const COLOR_FIELDS = [
  ['header_bg', 'Header background', 'The bar with the logo, search and cart.'],
  ['header_text', 'Header text', 'Store name and menu links on the header.'],
  ['accent', 'Accent', 'Search button, cart count and the button on banners.'],
  ['primary', 'Buttons & prices', 'Add to cart, Place order, links.'],
  ['page_bg', 'Page background', 'Behind the white panels.'],
  ['announcement_bg', 'Announcement bar background', ''],
  ['announcement_text', 'Announcement bar text', ''],
];

const HEADER_PRESETS = [
  ['Chilli red', { header_bg: '#c62d1f', header_text: '#ffffff', accent: '#ffd23f', primary: '#c62d1f' }],
  ['Charcoal', { header_bg: '#2a2829', header_text: '#ffffff', accent: '#ffd23f', primary: '#c62d1f' }],
  ['Leaf green', { header_bg: '#2e7d32', header_text: '#ffffff', accent: '#ffd23f', primary: '#2e7d32' }],
  ['Saffron', { header_bg: '#e8972e', header_text: '#2a2829', accent: '#2a2829', primary: '#c62d1f' }],
  ['Indigo', { header_bg: '#2b2a5c', header_text: '#ffffff', accent: '#ffd23f', primary: '#c62d1f' }],
];

function isHex(value) {
  return /^#[0-9a-fA-F]{6}$/.test(value);
}

function ColorInput({ id, label, hint, value, onChange }) {
  const [text, setText] = useState(value);
  useEffect(() => setText(value), [value]);

  return (
    <div className="content-field">
      <label htmlFor={id}>{label}</label>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <input type="color" aria-label={`${label} colour picker`} value={value} onChange={(e) => onChange(e.target.value)}
               style={{ width: 44, height: 36, padding: 0, flex: 'none' }} />
        <input id={id} value={text} maxLength={7} style={{ fontFamily: 'ui-monospace, monospace', width: 110 }}
               onChange={(e) => {
                 setText(e.target.value);
                 if (isHex(e.target.value)) onChange(e.target.value.toLowerCase());
               }} />
      </div>
      {hint && <div className="content-hint">{hint}</div>}
    </div>
  );
}

function Preview({ theme }) {
  return (
    <div style={{ borderRadius: 8, overflow: 'hidden', border: '1px solid #e2ded9', background: theme.page_bg }}>
      <div style={{ background: theme.header_bg, padding: '10px 12px', display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <div style={{ width: 34, height: 34, borderRadius: 999, background: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <img src={BRAND_LOGO_URL} alt="" style={{ width: 30, height: 30 }} />
        </div>
        <div style={{ color: theme.header_text, display: 'flex', flexDirection: 'column', gap: 2 }}>
          <b style={{ font: "800 16px/1 'Bricolage Grotesque', system-ui" }}>5 Star</b>
          <span style={{ font: 'italic 600 10px/1 system-ui', opacity: 0.85 }}>{theme.tagline}</span>
        </div>
        <div style={{ flex: '1 1 140px', display: 'flex', height: 32, background: '#fff', borderRadius: 5, overflow: 'hidden' }}>
          <span style={{ flex: 1, padding: '0 10px', font: '13px/32px system-ui', color: '#8f898a' }}>Search…</span>
          <span style={{ background: theme.accent, padding: '0 12px', font: '700 12px/32px system-ui', color: '#2a2829' }}>Search</span>
        </div>
        <span style={{ color: theme.header_text, font: '600 13px system-ui' }}>All products</span>
        <span style={{ background: 'rgba(0,0,0,.18)', color: theme.header_text, borderRadius: 5, padding: '6px 10px', font: '700 13px system-ui' }}>
          Cart <span style={{ background: theme.accent, color: '#2a2829', borderRadius: 999, padding: '1px 7px', marginLeft: 4 }}>2</span>
        </span>
      </div>
      {theme.announcement && (
        <div style={{ background: theme.announcement_bg, color: theme.announcement_text, textAlign: 'center', font: '500 12px/1.4 system-ui', padding: '6px 10px' }}>
          {theme.announcement}
        </div>
      )}
      <div style={{ padding: 12, display: 'flex', gap: 10 }}>
        <div style={{ flex: 1, background: '#fff', borderRadius: 6, padding: 10, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <div style={{ aspectRatio: '1 / 1', background: '#f3e2b3', borderRadius: 4 }} />
          <span style={{ font: '500 12px system-ui' }}>Organic Turmeric</span>
          <b style={{ font: '800 13px system-ui' }}>₹129</b>
          <span style={{ border: `1px solid ${theme.primary}`, color: theme.primary, borderRadius: 4, textAlign: 'center', font: '800 10px/24px system-ui' }}>ADD TO CART</span>
        </div>
        <div style={{ flex: 1, background: '#fff', borderRadius: 6, padding: 10, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <div style={{ aspectRatio: '1 / 1', background: '#efdfca', borderRadius: 4 }} />
          <span style={{ font: '500 12px system-ui' }}>California Almonds</span>
          <b style={{ font: '800 13px system-ui' }}>₹449</b>
          <span style={{ background: theme.primary, color: '#fff', borderRadius: 4, textAlign: 'center', font: '800 12px/24px system-ui' }}>− 1 +</span>
        </div>
      </div>
    </div>
  );
}

export default function ContentAppearance() {
  const [saved, setSaved] = useState(null);
  const [theme, setTheme] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get('/admin/storefront/theme')
      .then((response) => {
        setSaved(response.data.theme);
        setTheme(response.data.theme);
      })
      .catch(setError);
  }, []);

  if (error) return <div className="content-card"><div className="content-card__body"><ErrorState error={error} /></div></div>;
  if (!theme) return <LoadingState />;

  const update = (field) => (value) => setTheme((t) => ({ ...t, [field]: value }));
  const dirty = JSON.stringify(theme) !== JSON.stringify(saved);

  async function save() {
    const changes = Object.fromEntries(Object.entries(theme).filter(([k, v]) => saved[k] !== v));
    if (Object.keys(changes).length === 0) return;
    setBusy(true);
    try {
      const response = await api.patch('/admin/storefront/theme', changes);
      setSaved(response.data.theme);
      setTheme(response.data.theme);
      toast('Storefront appearance saved. It shows on the website and app at the next page load.');
    } catch (err) {
      const messages = err instanceof ApiError ? err.fieldMessages() : [];
      toast(messages.length > 0 ? `${err.message} ${messages.join(' ')}` : err.message, 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="content-grid">
      <div className="content-card content-card--wide">
        <div className="content-card__header">Preview</div>
        <div className="content-card__body" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <Preview theme={theme} />
          <div>
            <div className="content-hint" style={{ marginBottom: 6 }}>Header colour presets</div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {HEADER_PRESETS.map(([name, values]) => (
                <button key={name} type="button" className="admin-btn" onClick={() => setTheme((t) => ({ ...t, ...values }))}>
                  <span style={{ display: 'inline-block', width: 12, height: 12, borderRadius: 3, background: values.header_bg, marginRight: 6, verticalAlign: -1 }} />
                  {name}
                </button>
              ))}
            </div>
          </div>
          <p className="content-note" style={{ margin: 0 }}>
            The rotating home banners are edited under <b>Banners</b>. Deals of the Day come from offers set to
            “Deal of the Day” under <Link to="/admin/promotions">Promotions</Link> — the countdown runs to the offer's end time.
          </p>
        </div>
      </div>

      <div className="content-card">
        <div className="content-card__header">Storefront appearance</div>
        <div className="content-card__body">
          {COLOR_FIELDS.slice(0, 5).map(([field, label, hint]) => (
            <ColorInput key={field} id={`t_${field}`} label={label} hint={hint} value={theme[field]} onChange={update(field)} />
          ))}

          <div className="content-field">
            <label htmlFor="t_tagline">Line under the store name</label>
            <input id="t_tagline" maxLength={60} value={theme.tagline} onChange={(e) => update('tagline')(e.target.value)} />
          </div>

          <div className="content-field">
            <label htmlFor="t_announcement">Announcement bar</label>
            <input id="t_announcement" maxLength={160} value={theme.announcement} onChange={(e) => update('announcement')(e.target.value)} />
            <div className="content-hint">Leave empty to hide the bar.</div>
          </div>

          {COLOR_FIELDS.slice(5).map(([field, label]) => (
            <ColorInput key={field} id={`t_${field}`} label={label} value={theme[field]} onChange={update(field)} />
          ))}

          <div className="content-field">
            <label htmlFor="t_seconds">Banner rotation: {theme.banner_seconds} seconds</label>
            <input id="t_seconds" type="range" min="3" max="20" value={theme.banner_seconds}
                   onChange={(e) => update('banner_seconds')(Number(e.target.value))} />
          </div>

          <div className="content-field">
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontWeight: 600 }}>
              <input type="checkbox" checked={theme.show_deals} onChange={(e) => update('show_deals')(e.target.checked)} />
              Show “Deals of the Day” on the home page
            </label>
          </div>

          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button className="admin-btn admin-btn--primary" type="button" disabled={!dirty || busy} onClick={save}>
              {busy ? 'Saving…' : 'Save appearance'}
            </button>
            <button className="admin-btn" type="button" disabled={!dirty || busy} onClick={() => setTheme(saved)}>Undo changes</button>
            <button className="admin-btn" type="button" disabled={busy} onClick={() => setTheme({ ...DESIGN_DEFAULTS })}>Design defaults</button>
          </div>
        </div>
      </div>
    </div>
  );
}
