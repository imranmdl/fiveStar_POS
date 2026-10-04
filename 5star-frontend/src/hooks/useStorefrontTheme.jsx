import { createContext, useContext, useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * Storefront look set by the administrator (Shopfront → Appearance):
 * colours, announcement bar, banner speed, Deals of the Day switch.
 *
 * The last theme is kept in localStorage so returning visitors (and the
 * Android app, offline) paint in the right colours at once; the fresh copy
 * from GET /storefront/theme replaces it a moment later.
 */
export const DEFAULT_THEME = {
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

const CACHE_KEY = 'spice.storefront_theme';
const ThemeContext = createContext(DEFAULT_THEME);

function cached() {
  try {
    const value = JSON.parse(localStorage.getItem(CACHE_KEY) || 'null');
    return value && typeof value === 'object' ? { ...DEFAULT_THEME, ...value } : DEFAULT_THEME;
  } catch {
    return DEFAULT_THEME;
  }
}

/** Darkens a #rrggbb colour by `amount` (0–1) for hover states. */
function shade(hex, amount) {
  const n = parseInt(hex.slice(1), 16);
  const c = [n >> 16, (n >> 8) & 255, n & 255].map((v) => Math.max(0, Math.round(v * (1 - amount))));
  return `#${c.map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

/** CSS custom properties for the .sf root element. */
export function themeVars(theme) {
  return {
    '--sf-header-bg': theme.header_bg,
    '--sf-header-fg': theme.header_text,
    '--sf-accent': theme.accent,
    '--sf-accent-dark': shade(theme.accent, 0.08),
    '--sf-red': theme.primary,
    '--sf-red-dark': shade(theme.primary, 0.15),
    '--sf-page': theme.page_bg,
    '--sf-ann-bg': theme.announcement_bg,
    '--sf-ann-fg': theme.announcement_text,
  };
}

export function StorefrontThemeProvider({ children }) {
  const [theme, setTheme] = useState(cached);

  useEffect(() => {
    api.get('/storefront/theme')
      .then((response) => {
        const fresh = { ...DEFAULT_THEME, ...(response.data.theme || {}) };
        setTheme(fresh);
        try {
          localStorage.setItem(CACHE_KEY, JSON.stringify(fresh));
        } catch {
          // Storage unavailable — the theme still applies for this visit.
        }
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme.header_bg);
  }, [theme.header_bg]);

  return <ThemeContext.Provider value={theme}>{children}</ThemeContext.Provider>;
}

export function useStorefrontTheme() {
  return useContext(ThemeContext);
}
