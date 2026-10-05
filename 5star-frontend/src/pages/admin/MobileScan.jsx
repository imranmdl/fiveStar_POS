import { useEffect, useRef, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import { toast } from '../../components/admin/toast.js';
import CameraScanner from '../../components/CameraScanner';
import './MobileScan.css';

/**
 * Mobile Scan — ported from admin/assets/page-mobile.js (+ its shared
 * pricing-decisions.js helper).
 *
 * Two modes, same as the live page:
 *  - "Scan inward": barcode -> product/variant match -> inward cart ->
 *    confirm. Confirming posts to the exact same POST /admin/purchase-orders
 *    every desktop purchase order uses, so there is only ever one stock
 *    balance, never a separate "mobile" one.
 *  - "Stock lookup": barcode -> per-warehouse quantities, read-only.
 *
 * An unrecognised barcode offers the brief's "controlled creation
 * workflow": a short form that creates a draft pack size on the spot via
 * POST /admin/inventory/quick-create (gated server-side to managers — this
 * page doesn't duplicate that gate, it just lets a 403 surface normally).
 *
 * SCANNING — three ways in, all ending in the same lookup:
 *  - "Camera" opens the phone's camera (components/CameraScanner, the same
 *    one the Till uses; works in a phone browser over https and in the
 *    Android app). On Scan inward it stays open so several items can be
 *    scanned in a row; it closes by itself when a barcode isn't recognised,
 *    so the quick-create form can be filled in.
 *  - A USB/Bluetooth barcode scanner "types" into the focused text field and
 *    sends Enter.
 *  - Typing the code and pressing Add / Look up.
 * The text field is re-focused after every lookup so a hardware scanner can
 * keep going without anyone touching the screen.
 */

const TODAY = () => new Date().toISOString().slice(0, 10);

function emptyQuickCreate(barcode) {
  return {
    barcode,
    category_uuid: '',
    product_name: '',
    variant_name: '',
    weight_grams: '',
    mrp: '',
    selling_price: '',
  };
}

/** The brief's §7 price-change prompt, one flagged line at a time. */
function PriceDecisionQueue({ pending, purchaseOrderId, onDone }) {
  const [index, setIndex] = useState(0);
  const [manualPrice, setManualPrice] = useState('');
  const [busy, setBusy] = useState(false);

  const item = pending[index];

  if (!item) return null;

  const advance = () => {
    setManualPrice('');
    if (index + 1 >= pending.length) onDone();
    else setIndex(index + 1);
  };

  const decide = async (decision) => {
    if (decision === 'manual' && !manualPrice) {
      toast('Enter a price first.', 'danger');
      return;
    }

    setBusy(true);

    try {
      await api.post('/admin/pricing/decisions', {
        variant_uuid: item.variant_uuid,
        decision,
        manual_price: decision === 'manual' ? manualPrice : null,
        purchase_price: item.purchase_price,
        average_cost: item.average_cost,
        reference_type: 'purchase_order',
        reference_id: purchaseOrderId,
        reason: `Purchase inward decision (${decision})`,
      });
      toast('Selling price updated.');
      setBusy(false);
      advance();
    } catch (error) {
      setBusy(false);
      toast(error instanceof ApiError ? error.message : 'Could not save that decision.', 'danger');
    }
  };

  return (
    <div className="mscan-overlay">
      <div className="mscan-sheet">
        <h2 className="mscan-sheet__title">Selling price for {item.sku}</h2>
        <dl className="mscan-sheet__facts">
          <div><dt>Current selling price</dt><dd>{formatMoney(item.current_price)}</dd></div>
          <div><dt>This purchase&rsquo;s cost</dt><dd>{formatMoney(item.purchase_price)}</dd></div>
          <div><dt>Resulting average cost</dt><dd>{formatMoney(item.average_cost)}</dd></div>
        </dl>

        <div className="mscan-sheet__choices">
          <button type="button" className="admin-btn mscan-choice" disabled={busy} onClick={() => decide('keep_old')}>
            Keep the current price — {formatMoney(item.current_price)}
          </button>
          {item.suggested_use_average !== null && item.suggested_use_average !== undefined && (
            <button type="button" className="admin-btn mscan-choice" disabled={busy} onClick={() => decide('use_average')}>
              Use the average-cost price — {formatMoney(item.suggested_use_average)}
            </button>
          )}
          {item.suggested_use_new !== null && item.suggested_use_new !== undefined && (
            <button type="button" className="admin-btn mscan-choice" disabled={busy} onClick={() => decide('use_new')}>
              Use the new-cost price — {formatMoney(item.suggested_use_new)}
            </button>
          )}
          <div className="mscan-manual-row">
            <input
              className="mscan-input"
              type="number"
              step="0.01"
              min="0.01"
              inputMode="decimal"
              placeholder="Set manually"
              value={manualPrice}
              onChange={(event) => setManualPrice(event.target.value)}
            />
            <button type="button" className="admin-btn admin-btn--primary" disabled={busy} onClick={() => decide('manual')}>
              Use this
            </button>
          </div>
        </div>

        <div className="mscan-sheet__footer">
          <button type="button" className="admin-btn" disabled={busy} onClick={advance}>Skip for now</button>
          <span className="mscan-sheet__progress">{index + 1} of {pending.length}</span>
        </div>
      </div>
    </div>
  );
}

function CartRow({ line, onChange, onRemove }) {
  const total = (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0);

  return (
    <div className="mscan-cart-row">
      <div className="mscan-cart-row__top">
        <div>
          <div className="mscan-cart-row__name">{line.product_name}</div>
          <div className="mscan-cart-row__meta">{line.variant_name} · {line.sku}</div>
        </div>
        <button type="button" className="mscan-remove" aria-label="Remove" onClick={onRemove}>×</button>
      </div>
      <div className="mscan-cart-row__fields">
        <label className="mscan-field">
          <span>Quantity</span>
          <input
            type="number"
            step="0.001"
            min="0.001"
            inputMode="decimal"
            value={line.quantity}
            onChange={(event) => onChange('quantity', event.target.value)}
          />
        </label>
        <label className="mscan-field">
          <span>Unit cost (₹)</span>
          <input
            type="number"
            step="0.0001"
            min="0"
            inputMode="decimal"
            value={line.unit_cost}
            onChange={(event) => onChange('unit_cost', event.target.value)}
          />
        </label>
      </div>
      <div className="mscan-cart-row__total">Line total: {formatMoney(total)}</div>
    </div>
  );
}

export default function MobileScan() {
  useOutletContext(); // staff user not needed directly — server enforces the quick-create role gate.

  const [mode, setMode] = useState('scan');
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [vendors, setVendors] = useState([]);
  const [warehouses, setWarehouses] = useState([]);

  const [code, setCode] = useState('');
  const [feedback, setFeedback] = useState(null); // { tone, text }
  const [cart, setCart] = useState([]);
  const [quickCreate, setQuickCreate] = useState(null); // emptyQuickCreate(barcode) while open
  const [categories, setCategories] = useState([]);
  const [quickCreateBusy, setQuickCreateBusy] = useState(false);

  const [vendorUuid, setVendorUuid] = useState('');
  const [warehouseUuid, setWarehouseUuid] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [confirmError, setConfirmError] = useState(null);
  const [priceQueue, setPriceQueue] = useState(null); // { pending, purchaseOrderId }

  const [lookupResult, setLookupResult] = useState(null);
  const [lookupBusy, setLookupBusy] = useState(false);
  const [lookupError, setLookupError] = useState(null);

  const inputRef = useRef(null);
  const [cameraOpen, setCameraOpen] = useState(false);

  useEffect(() => {
    let active = true;

    async function load() {
      try {
        const [vendorResponse, warehouseResponse] = await Promise.all([
          api.get('/admin/vendors', { per_page: 200, active_only: true }),
          api.get('/admin/warehouses', { active_only: true }),
        ]);
        if (!active) return;

        const vendorList = vendorResponse.data || [];
        const warehouseList = warehouseResponse.data || [];
        setVendors(vendorList);
        setWarehouses(warehouseList);
        const defaultWarehouse = warehouseList.find((w) => w.is_default);
        if (defaultWarehouse) setWarehouseUuid(defaultWarehouse.uuid);
        setLoading(false);
      } catch (error) {
        if (!active) return;
        setLoadError(error);
        setLoading(false);
      }
    }

    load();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    // Keep the scan input focused whenever it's visible so a hardware
    // scanner can fire away without anyone touching the screen first.
    if (mode === 'scan' && !quickCreate) inputRef.current?.focus();
  }, [mode, quickCreate]);

  function focusInput() {
    // Re-focus after React re-renders, not before.
    setTimeout(() => inputRef.current?.focus(), 0);
  }

  async function handleScanSubmit(event) {
    event.preventDefault();
    const value = code.trim();
    if (!value) return;
    setCode('');
    await scanInward(value);
  }

  async function scanInward(value) {

    setFeedback({ tone: 'muted', text: `Looking up ${value}…` });

    try {
      const response = await api.get('/admin/inventory/lookup', { sku: value });
      const variant = response.data;

      setCart((current) => {
        const existingIndex = current.findIndex((l) => l.variant_uuid === variant.uuid);
        if (existingIndex >= 0) {
          const next = [...current];
          const existing = next[existingIndex];
          const bumped = String((Number(existing.quantity) || 0) + 1);
          next[existingIndex] = { ...existing, quantity: bumped };
          setFeedback({ tone: 'success', text: `Already in cart — quantity bumped to ${bumped}.` });
          return next;
        }

        setFeedback({ tone: 'success', text: `Added: ${variant.product_name} — ${variant.variant_name}` });
        return [
          ...current,
          {
            variant_uuid: variant.uuid,
            sku: variant.sku,
            product_name: variant.product_name,
            variant_name: variant.variant_name,
            quantity: '1',
            unit_cost: String(variant.selling_price ?? '0'),
          },
        ];
      });

      setQuickCreate(null);
      focusInput();
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        setFeedback({ tone: 'warning', text: `Unrecognised barcode: ${value}` });
        setQuickCreate(emptyQuickCreate(value));
        // The quick-create form needs the screen — close the camera.
        setCameraOpen(false);

        if (categories.length === 0) {
          try {
            const response = await api.get('/admin/categories', { per_page: 200 });
            setCategories(response.data || []);
          } catch {
            setCategories([]);
          }
        }
      } else {
        setFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Lookup failed.' });
        focusInput();
      }
    }
  }

  async function handleQuickCreateSubmit(event) {
    event.preventDefault();
    setQuickCreateBusy(true);

    try {
      const response = await api.post('/admin/inventory/quick-create', quickCreate);
      const variant = response.data;

      setCart((current) => [
        ...current,
        {
          variant_uuid: variant.uuid,
          sku: variant.sku,
          product_name: quickCreate.product_name,
          variant_name: quickCreate.variant_name,
          quantity: '1',
          unit_cost: '0',
        },
      ]);

      toast(`Created and added: ${quickCreate.product_name}`);
      setQuickCreate(null);
      setFeedback({ tone: 'success', text: `Created and added: ${quickCreate.product_name}` });
      focusInput();
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not create that pack size.', 'danger');
    } finally {
      setQuickCreateBusy(false);
    }
  }

  function updateQuickCreateField(field, value) {
    setQuickCreate((current) => ({ ...current, [field]: value }));
  }

  function updateCartLine(index, field, value) {
    setCart((current) => {
      const next = [...current];
      next[index] = { ...next[index], [field]: value };
      return next;
    });
  }

  function removeCartLine(index) {
    setCart((current) => current.filter((_, i) => i !== index));
  }

  const cartTotal = cart.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);

  async function confirmInward() {
    if (!vendorUuid) {
      toast('Select a vendor first.', 'danger');
      return;
    }
    if (cart.length === 0) {
      toast('The cart is empty.', 'danger');
      return;
    }

    setConfirming(true);
    setConfirmError(null);

    try {
      const response = await api.post('/admin/purchase-orders', {
        vendor_uuid: vendorUuid,
        warehouse_uuid: warehouseUuid,
        purchase_date: TODAY(),
        invoice_reference: null,
        discount_amount: 0,
        other_charges: 0,
        tax_amount: 0,
        notes: 'Recorded via mobile scan',
        lines: cart.map((l) => ({
          variant_uuid: l.variant_uuid,
          quantity: l.quantity,
          unit_cost: l.unit_cost,
        })),
      });

      toast('Inward recorded. Stock and average cost are updated.');

      const pending = response.data.price_decisions_pending || [];

      if (pending.length > 0) {
        setPriceQueue({ pending, purchaseOrderId: response.data.id });
      } else {
        setCart([]);
      }

      setConfirming(false);
    } catch (error) {
      setConfirming(false);
      setConfirmError(error);
    }
  }

  function finishPriceQueue() {
    setPriceQueue(null);
    setCart([]);
  }

  async function handleLookupSubmit(event) {
    event.preventDefault();
    const value = code.trim();
    if (!value) return;
    await lookupStock(value);
  }

  async function lookupStock(value) {
    setCameraOpen(false);

    setLookupBusy(true);
    setLookupError(null);
    setLookupResult(null);

    try {
      const response = await api.get('/admin/inventory/lookup', { sku: value });
      setLookupResult(response.data);
    } catch (error) {
      setLookupError(error);
    } finally {
      setLookupBusy(false);
      setCode('');
      focusInput();
    }
  }

  if (loading) return <LoadingState />;
  if (loadError) return <ErrorState error={loadError} />;

  return (
    <div className="mscan">
      <h1 className="admin-page-title">Mobile Scan</h1>

      <div className="mscan-tabs" role="tablist">
        <button
          type="button"
          className={`mscan-tab ${mode === 'scan' ? 'mscan-tab--active' : ''}`}
          onClick={() => { setMode('scan'); setLookupResult(null); setLookupError(null); setCode(''); }}
        >
          Scan inward
        </button>
        <button
          type="button"
          className={`mscan-tab ${mode === 'lookup' ? 'mscan-tab--active' : ''}`}
          onClick={() => { setMode('lookup'); setFeedback(null); setQuickCreate(null); setCode(''); }}
        >
          Stock lookup
        </button>
      </div>

      {mode === 'scan' ? (
        <>
          <form className="mscan-scan-form" onSubmit={handleScanSubmit}>
            <input
              ref={inputRef}
              className="mscan-input mscan-input--big"
              type="text"
              inputMode="text"
              autoFocus
              autoComplete="off"
              autoCapitalize="off"
              autoCorrect="off"
              placeholder="Scan or type a barcode / SKU"
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
            <button type="submit" className="admin-btn admin-btn--primary mscan-add-btn">Add</button>
            <button type="button" className="admin-btn mscan-camera-btn" onClick={() => setCameraOpen(true)} aria-label="Scan with camera">
              📷 Camera
            </button>
          </form>

          {feedback && (
            <div className={`mscan-feedback mscan-feedback--${feedback.tone}`}>{feedback.text}</div>
          )}

          {quickCreate && (
            <form className="mscan-quick-create" onSubmit={handleQuickCreateSubmit}>
              <h2 className="mscan-quick-create__title">Create this pack size</h2>

              <label className="mscan-field">
                <span>Category</span>
                <select
                  value={quickCreate.category_uuid}
                  onChange={(event) => updateQuickCreateField('category_uuid', event.target.value)}
                  required
                >
                  <option value="">Select…</option>
                  {categories.map((c) => (
                    <option key={c.uuid} value={c.uuid}>{c.name}</option>
                  ))}
                </select>
              </label>

              <label className="mscan-field">
                <span>Product name</span>
                <input
                  value={quickCreate.product_name}
                  onChange={(event) => updateQuickCreateField('product_name', event.target.value)}
                  required
                />
              </label>

              <label className="mscan-field">
                <span>Pack size (e.g. &ldquo;500g pouch&rdquo;)</span>
                <input
                  value={quickCreate.variant_name}
                  onChange={(event) => updateQuickCreateField('variant_name', event.target.value)}
                  required
                />
              </label>

              <div className="mscan-quick-create__row">
                <label className="mscan-field">
                  <span>Weight (g)</span>
                  <input
                    type="number"
                    min="1"
                    inputMode="numeric"
                    value={quickCreate.weight_grams}
                    onChange={(event) => updateQuickCreateField('weight_grams', event.target.value)}
                    required
                  />
                </label>
                <label className="mscan-field">
                  <span>MRP</span>
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    inputMode="decimal"
                    value={quickCreate.mrp}
                    onChange={(event) => updateQuickCreateField('mrp', event.target.value)}
                    required
                  />
                </label>
                <label className="mscan-field">
                  <span>Price</span>
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    inputMode="decimal"
                    value={quickCreate.selling_price}
                    onChange={(event) => updateQuickCreateField('selling_price', event.target.value)}
                    required
                  />
                </label>
              </div>

              <button type="submit" className="admin-btn admin-btn--primary mscan-full-btn" disabled={quickCreateBusy}>
                {quickCreateBusy ? 'Creating…' : 'Create and add to cart'}
              </button>
            </form>
          )}

          <div className="mscan-cart-header">
            <h2>Cart ({cart.length})</h2>
            {cart.length > 0 && <span className="mscan-cart-total">{formatMoney(cartTotal)}</span>}
          </div>

          {cart.length === 0 ? (
            <p className="mscan-empty-hint">Cart is empty — scan or type a barcode/SKU above to add one.</p>
          ) : (
            <div className="mscan-cart-list">
              {cart.map((line, index) => (
                <CartRow
                  key={line.variant_uuid}
                  line={line}
                  onChange={(field, value) => updateCartLine(index, field, value)}
                  onRemove={() => removeCartLine(index)}
                />
              ))}
            </div>
          )}

          <div className="mscan-confirm-card">
            <label className="mscan-field">
              <span>Vendor</span>
              <select value={vendorUuid} onChange={(event) => setVendorUuid(event.target.value)} required>
                <option value="">Select a vendor…</option>
                {vendors.map((v) => (
                  <option key={v.uuid} value={v.uuid}>{v.name}</option>
                ))}
              </select>
            </label>

            <label className="mscan-field">
              <span>Warehouse</span>
              <select value={warehouseUuid} onChange={(event) => setWarehouseUuid(event.target.value)}>
                {warehouses.map((w) => (
                  <option key={w.uuid} value={w.uuid}>{w.name}</option>
                ))}
              </select>
            </label>

            {confirmError && <ErrorState error={confirmError} />}

            <button
              type="button"
              className="admin-btn admin-btn--primary mscan-full-btn mscan-confirm-btn"
              disabled={confirming}
              onClick={confirmInward}
            >
              {confirming ? 'Saving…' : 'Confirm inward'}
            </button>
          </div>
        </>
      ) : (
        <>
          <form className="mscan-scan-form" onSubmit={handleLookupSubmit}>
            <input
              ref={inputRef}
              className="mscan-input mscan-input--big"
              type="text"
              inputMode="text"
              autoFocus
              autoComplete="off"
              autoCapitalize="off"
              autoCorrect="off"
              placeholder="Scan or type a barcode / SKU"
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
            <button type="submit" className="admin-btn admin-btn--primary mscan-add-btn">Look up</button>
            <button type="button" className="admin-btn mscan-camera-btn" onClick={() => setCameraOpen(true)} aria-label="Scan with camera">
              📷 Camera
            </button>
          </form>

          {lookupBusy && <p className="mscan-empty-hint">Looking up…</p>}
          {lookupError && <ErrorState error={lookupError} />}

          {lookupResult && (
            <div className="mscan-lookup-result">
              <div className="mscan-cart-row__name">{lookupResult.product_name}</div>
              <div className="mscan-cart-row__meta">{lookupResult.variant_name} · {lookupResult.sku}</div>

              <div className="mscan-stock-list">
                {(lookupResult.stock || []).map((s) => (
                  <div className="mscan-stock-row" key={s.warehouse_name}>
                    <span>{s.warehouse_name}</span>
                    <span>{Number(s.quantity)}</span>
                  </div>
                ))}
              </div>

              <div className="mscan-lookup-total">
                Total: {(lookupResult.stock || []).reduce((sum, s) => sum + Number(s.quantity), 0)}
              </div>
            </div>
          )}
        </>
      )}

      {priceQueue && (
        <PriceDecisionQueue
          pending={priceQueue.pending}
          purchaseOrderId={priceQueue.purchaseOrderId}
          onDone={finishPriceQueue}
        />
      )}
      {cameraOpen && (
        <CameraScanner
          title={mode === 'scan' ? 'Scan items to inward' : 'Scan to look up stock'}
          hint={mode === 'scan'
            ? 'Point at a barcode — each scan adds to the list. Keep scanning, then press Done.'
            : 'Point at a barcode — the stock shows as soon as it reads.'}
          continuous={mode === 'scan'}
          onDetected={(scanned) => (mode === 'scan' ? scanInward(scanned) : lookupStock(scanned))}
          onClose={() => { setCameraOpen(false); focusInput(); }}
        />
      )}
    </div>
  );
}
