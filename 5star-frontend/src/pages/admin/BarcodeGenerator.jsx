/**
 * Barcode Generator — ported from admin/assets/page-barcode-generator.js.
 *
 * Find a pack size, mint it a barcode if it doesn't already have one (reuses
 * InventoryService::assignBarcode() — the same endpoint Purchase Inward
 * already calls to print a single label), and print an A4 sheet of
 * everything generated so far in this browser.
 *
 * The "recent" list lives in localStorage rather than the server — it's a
 * printing convenience (what's on the sheet right now), not a business
 * record; the barcode itself is the durable thing, already saved on the
 * variant the moment it's generated.
 */
import { useEffect, useRef, useState } from 'react';
import { api, formatMoney, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState } from '../../components/admin/shared.jsx';
import { printBarcodeSheetA4 } from './barcodeGeneratorPrint.js';
import './BarcodeGenerator.css';

const STORAGE_KEY = 'spice.barcode_generator.recent';
const MAX_RECENT = 100;

function loadRecent() {
  let recent;
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    recent = raw ? JSON.parse(raw) : [];
  } catch {
    recent = [];
  }

  // Entries saved before assignBarcode() returned the product name are
  // permanently missing it — no amount of refreshing fixes an already-saved
  // blank. Drop them here rather than leaving a dead row on the sheet that
  // nothing the user does will ever label correctly.
  return recent.filter((r) => r.product_name);
}

function saveRecent(recent) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(recent));
  } catch {
    // A private window or full storage quota loses the print list on
    // reload — not worth failing over; the barcode itself is already saved.
  }
}

export default function BarcodeGenerator() {
  const [recent, setRecent] = useState(() => loadRecent());
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [generatingUuid, setGeneratingUuid] = useState(null);
  const searchTimerRef = useRef(null);
  const searchInputRef = useRef(null);

  useEffect(() => {
    saveRecent(recent);
  }, [recent]);

  useEffect(() => {
    clearTimeout(searchTimerRef.current);
    const text = query.trim();

    if (text === '') {
      setResults([]);
      return undefined;
    }

    if (text.length < 2) {
      setResults([]);
      return undefined;
    }

    searchTimerRef.current = setTimeout(async () => {
      try {
        const response = await api.get('/admin/inventory/search', { q: text });
        setResults(response.data || []);
      } catch {
        // A search hiccup just means no suggestions this keystroke.
      }
    }, 300);

    return () => clearTimeout(searchTimerRef.current);
  }, [query]);

  function addToRecent(variant) {
    setRecent((current) => {
      const next = current.filter((r) => r.uuid !== variant.uuid);
      next.unshift({
        uuid: variant.uuid,
        sku: variant.sku,
        barcode: variant.barcode,
        product_name: variant.product_name,
        variant_name: variant.variant_name,
        selling_price: variant.selling_price,
      });
      return next.slice(0, MAX_RECENT);
    });
  }

  async function handleGenerate(match) {
    setGeneratingUuid(match.uuid);
    try {
      const response = await api.post(`/admin/inventory/variants/${encodeURIComponent(match.uuid)}/barcode`, {});
      addToRecent(response.data.variant);
      toast(`Barcode ready for ${match.product_name} — ${match.variant_name}.`);
      setQuery('');
      setResults([]);
      searchInputRef.current?.focus();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not generate a barcode.', 'danger');
    } finally {
      setGeneratingUuid(null);
    }
  }

  function removeFromRecent(uuid) {
    setRecent((current) => current.filter((r) => r.uuid !== uuid));
  }

  function clearRecent() {
    if (!window.confirm('Clear the whole print list? The barcodes themselves stay saved on each item.')) return;
    setRecent([]);
  }

  return (
    <div className="page">
      <h1 className="admin-page-title">Barcode Generator</h1>
      <p className="barcode-gen-intro">
        Find a pack size below, generate its barcode if it doesn't have one yet, then print an A4
        sheet of every label generated so far.
      </p>

      <div className="barcode-gen-search">
        <input
          ref={searchInputRef}
          className="barcode-gen-search__input"
          placeholder="Type a product name or SKU…"
          autoComplete="off"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </div>

      {results.length > 0 && (
        <div className="barcode-gen-results">
          {results.map((match) => (
            <button
              key={match.uuid}
              type="button"
              className="barcode-gen-results__item"
              disabled={generatingUuid === match.uuid}
              onClick={() => handleGenerate(match)}
            >
              <span>
                <span className="barcode-gen-results__name">{match.product_name} ({match.variant_name})</span>
                <span className="barcode-gen-results__sku">{match.sku}</span>
              </span>
              <span className="barcode-gen-results__cta">
                {generatingUuid === match.uuid ? 'Generating…' : 'Generate →'}
              </span>
            </button>
          ))}
        </div>
      )}

      {query.trim().length >= 2 && results.length === 0 && (
        <div className="barcode-gen-results">
          <div className="barcode-gen-results__empty">No items match.</div>
        </div>
      )}

      <div className="barcode-gen-card">
        <div className="barcode-gen-card__header">
          <span className="barcode-gen-card__title">Recently generated</span>
          <span className="barcode-gen-card__count">{recent.length} label(s)</span>
        </div>

        {recent.length === 0 ? (
          <div className="barcode-gen-card__body">
            <EmptyState title="No barcodes generated yet" hint="Search for an item above to get started." />
          </div>
        ) : (
          <>
            <div className="barcode-gen-table-wrap">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Barcode</th>
                    <th style={{ textAlign: 'right' }}>Price</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {recent.map((item) => (
                    <tr key={item.uuid}>
                      <td>
                        <span className="barcode-gen-table__name">{item.product_name} ({item.variant_name})</span>
                        <div className="barcode-gen-table__sku">{item.sku}</div>
                      </td>
                      <td className="barcode-gen-table__code">{item.barcode}</td>
                      <td style={{ textAlign: 'right' }}>{formatMoney(item.selling_price)}</td>
                      <td style={{ textAlign: 'right' }}>
                        <button type="button" className="admin-btn" onClick={() => removeFromRecent(item.uuid)}>
                          Remove
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="barcode-gen-card__footer">
              <button type="button" className="admin-btn" onClick={clearRecent}>Clear list</button>
              <button type="button" className="admin-btn admin-btn--primary" onClick={() => printBarcodeSheetA4(recent)}>
                Print sheet (A4)
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
