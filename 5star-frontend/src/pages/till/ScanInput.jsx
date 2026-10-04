import { useEffect, useRef, useState, useCallback } from 'react';
import { formatMoney } from '../../lib/api';
import CameraScanner from './CameraScanner';

/**
 * The universal scan input — the core of this page. One text field serves
 * THREE input sources identically:
 *
 *  1. A USB/Bluetooth HID barcode scanner: to the browser this looks
 *     exactly like someone typing very fast and then pressing Enter. No
 *     pairing, no special API — just keep this field focused and handle
 *     Enter.
 *  2. The camera overlay (@zxing/browser / getUserMedia), opened by the
 *     "Scan with camera" button — decodes a frame and calls the exact same
 *     `onCode` resolver the HID path uses.
 *  3. Manual typing: an exact code still resolves on Enter; anything else
 *     triggers a debounced name search with results to pick from, for when
 *     neither a scanner nor the camera is available, or the barcode just
 *     doesn't resolve.
 *
 * Ported from the `data-sku-input` field in admin/assets/page-till.js
 * (addByExactCode / searchByName), split out here since the camera path has
 * no equivalent there — this page is the one place that needed it built for
 * real (contrast admin/MobileScan, which dropped the camera entirely).
 */
export default function ScanInput({ onCode, onSearch, onPickMatch, feedback, disabled }) {
  const [value, setValue] = useState('');
  const [results, setResults] = useState(null); // null = hidden, [] = "no matches"
  const [cameraOpen, setCameraOpen] = useState(false);
  const inputRef = useRef(null);
  const searchTimer = useRef(null);

  useEffect(() => {
    if (!cameraOpen && !disabled) inputRef.current?.focus();
  }, [cameraOpen, disabled]);

  const clearResults = () => setResults(null);

  const refocus = useCallback(() => {
    // After React re-renders, not before, so the field that gets focus is
    // the one actually in the DOM.
    setTimeout(() => inputRef.current?.focus(), 0);
  }, []);

  async function submitExactCode(rawCode) {
    const code = rawCode.trim();
    if (!code) return;

    setValue('');
    clearResults();
    await onCode(code);
    refocus();
  }

  function handleKeyDown(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      submitExactCode(value);
    }
  }

  function handleChange(event) {
    const text = event.target.value;
    setValue(text);
    clearTimeout(searchTimer.current);

    if (text.trim() === '') {
      clearResults();
      return;
    }

    if (text.trim().length < 2) {
      clearResults();
      return;
    }

    searchTimer.current = setTimeout(async () => {
      try {
        const matches = await onSearch(text.trim());
        setResults(matches);
      } catch {
        // A search hiccup shouldn't interrupt typing — the exact-code path
        // (scan, or Enter) still works regardless.
      }
    }, 300);
  }

  async function handlePick(match) {
    clearResults();
    setValue('');
    await onPickMatch(match);
    refocus();
  }

  async function handleCameraDetected(code) {
    await onCode(code);
  }

  return (
    <div className="till-scan">
      <div className="till-scan__row">
        <input
          ref={inputRef}
          className="till-scan__input"
          type="text"
          inputMode="text"
          autoComplete="off"
          autoCapitalize="off"
          autoCorrect="off"
          placeholder="Scan a barcode, or type a SKU / item name"
          value={value}
          disabled={disabled}
          onChange={handleChange}
          onKeyDown={handleKeyDown}
        />
        <button type="button" className="till-btn" disabled={disabled} onClick={() => submitExactCode(value)}>
          Add
        </button>
        <button
          type="button"
          className="till-btn till-btn--camera"
          disabled={disabled}
          onClick={() => setCameraOpen(true)}
          aria-label="Scan with camera"
        >
          📷 Camera
        </button>
      </div>

      {results !== null && (
        <div className="till-scan__results">
          {results.length === 0 ? (
            <div className="till-scan__result-empty">No items match — try scanning, or a shorter name.</div>
          ) : (
            results.map((match, index) => {
              const isPublished = match.product_status === 'published';
              return (
                <button
                  type="button"
                  key={match.sku || index}
                  className={`till-scan__result ${isPublished ? '' : 'till-scan__result--muted'}`}
                  onClick={() => handlePick(match)}
                >
                  <span className="till-scan__result-text">
                    <span className="till-scan__result-name">{match.product_name}</span>
                    <span className="till-scan__result-meta">{match.variant_name} · {match.sku}</span>
                    {!isPublished && (
                      <span className="till-badge till-badge--muted">
                        {match.product_status === 'archived' ? 'Unpublished' : 'Draft — not published'}
                      </span>
                    )}
                    {match.isDuplicate && (
                      <div className="till-text-danger till-scan__result-dupe">
                        Another pack size shares this name and weight — check the SKU ({match.sku}) before picking one.
                      </div>
                    )}
                  </span>
                  <span className="till-scan__result-price">{formatMoney(match.selling_price)}</span>
                </button>
              );
            })
          )}
        </div>
      )}

      {feedback && <div className={`till-feedback till-feedback--${feedback.tone}`}>{feedback.text}</div>}

      {cameraOpen && (
        <CameraScanner
          onDetected={handleCameraDetected}
          onClose={() => setCameraOpen(false)}
        />
      )}
    </div>
  );
}
