import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { toast } from '../../components/admin/toast.js';
import PurchaseInwardNewItem from './PurchaseInwardNewItem.jsx';
import PurchaseInwardCsvUpload from './PurchaseInwardCsvUpload.jsx';
import PurchaseInwardPriceQueue from './PurchaseInwardPriceQueue.jsx';
import { printBarcodeLabels } from './purchaseInwardPrint.js';
import CameraScanner from '../../components/CameraScanner';

const TODAY = () => new Date().toISOString().slice(0, 10);

const BLANK_VENDOR = { name: '', gstin: '', phone: '', address_line1: '', city: '', state: '', pincode: '' };

/**
 * "Record inward" tab — ported from admin/assets/page-purchase-inward.js
 * (renderRecordTab + its line-table helpers). One purchase order: a vendor
 * and warehouse, its items (looked up, newly created, or bulk-uploaded via
 * CSV), transportation/other charges folded into landing cost, and how much
 * has been paid so far.
 *
 * Saving posts inventory movements immediately (PurchaseOrderService) —
 * there is no draft/receive step — and, if the purchase changed any item's
 * cost enough to raise a pricing question, the server returns
 * price_decisions_pending for the queue shown after save.
 */
export default function PurchaseInwardRecordForm({ vendors, warehouses, inventorySetup, onVendorCreated, onCategoryCreated, onSaved }) {
  const [searchParams] = useSearchParams();

  const [vendorUuid, setVendorUuid] = useState('');
  const [warehouseUuid, setWarehouseUuid] = useState('');
  const [purchaseDate, setPurchaseDate] = useState(TODAY());
  const [invoiceReference, setInvoiceReference] = useState('');

  const [showNewVendor, setShowNewVendor] = useState(false);
  const [newVendor, setNewVendor] = useState(BLANK_VENDOR);
  const [savingVendor, setSavingVendor] = useState(false);
  const [vendorFeedback, setVendorFeedback] = useState(null);

  const [showNewItem, setShowNewItem] = useState(false);
  const [showCsv, setShowCsv] = useState(false);
  const [cameraOpen, setCameraOpen] = useState(false);

  const [lines, setLines] = useState([]);
  // Latest lines for the scan feedback text (scans can arrive faster than renders).
  const linesRef = useRef(lines);
  linesRef.current = lines;
  const [skuInput, setSkuInput] = useState('');
  const [skuFeedback, setSkuFeedback] = useState(null);
  const [searchResults, setSearchResults] = useState([]);
  const [busyBarcodeIndex, setBusyBarcodeIndex] = useState(null);
  const searchTimerRef = useRef(null);
  const skuInputRef = useRef(null);

  const [discountAmount, setDiscountAmount] = useState('0');
  const [otherCharges, setOtherCharges] = useState('0');
  const [taxEnabled, setTaxEnabled] = useState(false);
  const [taxAmount, setTaxAmount] = useState('0');
  const [transportIncluded, setTransportIncluded] = useState(false);
  const [transportCharge, setTransportCharge] = useState('0');
  const [amountPaid, setAmountPaid] = useState('0');
  const [notes, setNotes] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);
  const [priceQueue, setPriceQueue] = useState(null); // { pending, purchaseOrderId }

  // Default warehouse (or a "Create Purchase" deep link's ?warehouse=) once the list is ready.
  useEffect(() => {
    if (warehouses.length === 0) return;
    const prefill = searchParams.get('warehouse');

    if (prefill && warehouses.some((w) => w.uuid === prefill)) {
      setWarehouseUuid(prefill);
      return;
    }

    const def = warehouses.find((w) => w.is_default);
    if (def) setWarehouseUuid((current) => current || def.uuid);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [warehouses]);

  // A "Create Purchase" deep link from a low-stock alert (?sku=...) — the
  // item is already known, so the line is added instead of asking again.
  useEffect(() => {
    const prefillSku = searchParams.get('sku');
    if (prefillSku) {
      setSkuInput(prefillSku);
      lookupSku(prefillSku);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function addLine(entry) {
    let added = true;
    setLines((prev) => {
      if (prev.some((l) => l.variant_uuid === entry.variant_uuid)) {
        added = false;
        return prev;
      }
      return [...prev, entry];
    });
    return added;
  }

  /**
   * Adds a scanned/picked pack to the order. Scanning one that's already on
   * it adds one more to that row (same as Mobile Scan and the Till), so a
   * scanner gun or the camera can count items by scanning each one.
   */
  function addVariantLine(variant) {
    const existing = linesRef.current.find((l) => l.variant_uuid === variant.uuid);

    setLines((prev) => {
      const index = prev.findIndex((l) => l.variant_uuid === variant.uuid);
      if (index >= 0) {
        const next = [...prev];
        next[index] = { ...next[index], quantity: String((Number(next[index].quantity) || 0) + 1) };
        return next;
      }
      return [...prev, {
        variant_uuid: variant.uuid,
        sku: variant.sku,
        barcode: variant.barcode || null,
        product_name: variant.product_name,
        variant_name: variant.variant_name,
        quantity: '1',
        invoiced_quantity: '',
        unit_cost: String(variant.selling_price ?? '0'),
        batch_no: '',
        expiry_date: '',
        mrp: '',
        selling_price: '',
        gst_rate: '',
        discount_amount: '',
        is_new: false,
      }];
    });

    setSkuFeedback(existing
      ? { tone: 'success', text: `${variant.product_name} — ${variant.variant_name}: quantity now ${(Number(existing.quantity) || 0) + 1}` }
      : { tone: 'success', text: `Added: ${variant.product_name} — ${variant.variant_name}` });
    setSkuInput('');
    setSearchResults([]);
    if (!cameraOpen) skuInputRef.current?.focus();
  }

  /** Name matches for what was typed, shown as a pick-list under the input. */
  async function searchByName(text) {
    if (text.length < 2) { setSearchResults([]); return []; }

    const response = await api.get('/admin/inventory/search', { q: text });
    const matches = response.data || [];
    setSearchResults(matches);

    if (matches.length === 0) {
      setSkuFeedback({ tone: 'muted', text: 'No item matches that name, SKU or barcode.' });
    }

    return matches;
  }

  /** Camera: exact barcode only. An unknown code closes the camera so it can be created with "+ New item". */
  async function handleCameraCode(code) {
    try {
      const response = await api.get('/admin/inventory/lookup', { sku: code });
      addVariantLine(response.data);
    } catch {
      setCameraOpen(false);
      setSkuInput(code);
      setSkuFeedback({ tone: 'warning', text: `No item has barcode ${code}. Use "+ New item" to create it, then scan again.` });
    }
  }

  /** Exact SKU/barcode first; if nothing has that code, treat the text as an item name. */
  async function lookupSku(rawText) {
    const text = (rawText ?? skuInput).trim();
    if (!text) return;

    setSkuFeedback({ tone: 'muted', text: 'Looking up…' });

    try {
      const response = await api.get('/admin/inventory/lookup', { sku: text });
      setSearchResults([]);
      addVariantLine(response.data);
      return;
    } catch {
      // Not a SKU or barcode — try it as a name.
    }

    try {
      const matches = await searchByName(text);

      if (matches.length === 1) {
        addVariantLine(matches[0]);
        setSearchResults([]);
      } else if (matches.length > 1) {
        setSkuFeedback({ tone: 'muted', text: 'Several items match — pick one from the list.' });
      } else {
        setSkuFeedback({ tone: 'danger', text: 'No item has that name, SKU or barcode.' });
      }
    } catch (error) {
      setSkuFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not look that up.' });
    }
  }

  function onSkuInputChange(value) {
    setSkuInput(value);
    clearTimeout(searchTimerRef.current);
    const text = value.trim();

    if (text.length < 2) { setSearchResults([]); return; }

    // A search hiccup must not interrupt typing — Enter still does the exact lookup.
    searchTimerRef.current = setTimeout(() => { searchByName(text).catch(() => {}); }, 300);
  }

  function updateLine(index, field, value) {
    setLines((prev) => {
      const next = [...prev];
      next[index] = { ...next[index], [field]: value };
      return next;
    });
  }

  function removeLine(index) {
    setLines((prev) => prev.filter((_, i) => i !== index));
  }

  async function generateBarcode(index) {
    const line = lines[index];
    setBusyBarcodeIndex(index);

    try {
      const response = await api.post(`/admin/inventory/variants/${encodeURIComponent(line.variant_uuid)}/barcode`, {});
      updateLine(index, 'barcode', response.data.variant.barcode);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not generate a barcode.', 'danger');
    } finally {
      setBusyBarcodeIndex(null);
    }
  }

  function printLineBarcode(line) {
    printBarcodeLabels([{ barcode: line.barcode, title: line.product_name, subtitle: `${line.variant_name} · ${line.sku}` }]);
  }

  async function handleCreateVendor() {
    if (!newVendor.name.trim()) {
      setVendorFeedback({ tone: 'danger', text: 'A vendor name is required.' });
      return;
    }

    setSavingVendor(true);

    try {
      const response = await api.post('/admin/vendors', {
        name: newVendor.name,
        gstin: newVendor.gstin || null,
        phone: newVendor.phone || null,
        address_line1: newVendor.address_line1 || null,
        city: newVendor.city || null,
        state: newVendor.state || null,
        pincode: newVendor.pincode || null,
      });
      const vendor = response.data;
      onVendorCreated(vendor);
      setVendorUuid(vendor.uuid);
      toast('Vendor saved and selected.');
      setShowNewVendor(false);
      setNewVendor(BLANK_VENDOR);
      setVendorFeedback(null);
    } catch (error) {
      setVendorFeedback({ tone: 'danger', text: error instanceof ApiError ? error.message : 'Could not save that vendor.' });
    } finally {
      setSavingVendor(false);
    }
  }

  // ---------------------------------------------------------------------
  // Totals — derived on every render, never stored, same formulas as the
  // live page's updateTotals()/currentTransportPercent().
  // ---------------------------------------------------------------------
  const itemsSubtotal = lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);
  const transportChargeNum = transportIncluded ? 0 : (Number(transportCharge) || 0);
  const transportPercent = (!transportIncluded && transportChargeNum > 0 && itemsSubtotal > 0)
    ? (transportChargeNum / itemsSubtotal) * 100
    : null;
  const discountNum = Number(discountAmount) || 0;
  const otherChargesNum = Number(otherCharges) || 0;
  const taxNum = Number(taxAmount) || 0;
  const grandTotal = itemsSubtotal - discountNum + transportChargeNum + otherChargesNum + taxNum;
  const amountPaidNum = Number(amountPaid) || 0;

  let paymentLabel = 'Unpaid';
  let paymentTone = 'secondary';
  if (amountPaidNum > 0.005) {
    if (amountPaidNum >= grandTotal - 0.005) { paymentLabel = 'Paid'; paymentTone = 'success'; }
    else { paymentLabel = 'Partial'; paymentTone = 'warning'; }
  }

  function calcTaxFromGst() {
    const tax = lines.reduce((sum, l) => {
      const base = (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0) - (Number(l.discount_amount) || 0);
      return sum + (Math.max(0, base) * (Number(l.gst_rate) || 0)) / 100;
    }, 0);
    setTaxAmount(tax.toFixed(2));
    if (tax === 0) toast('No item has a GST % yet — enter it on each item, or type the tax amount.', 'warning');
  }

  async function handleSubmit(event) {
    event.preventDefault();

    if (!vendorUuid) { toast('Select a vendor first.', 'danger'); return; }
    if (lines.length === 0) { toast('Add at least one item first.', 'danger'); return; }
    if (lines.some((l) => !l.unit_cost || Number(l.unit_cost) < 0)) { toast('Every item needs a unit cost.', 'danger'); return; }

    setSubmitting(true);
    setSubmitError(null);

    try {
      const response = await api.post('/admin/purchase-orders', {
        vendor_uuid: vendorUuid,
        warehouse_uuid: warehouseUuid,
        purchase_date: purchaseDate,
        invoice_reference: invoiceReference || null,
        discount_amount: discountAmount,
        other_charges: otherCharges,
        transport_included_in_cost: transportIncluded,
        transport_charge: transportCharge,
        tax_amount: taxAmount,
        amount_paid: amountPaid,
        notes: notes || null,
        lines: lines.map((l) => ({
          variant_uuid: l.variant_uuid,
          quantity: l.quantity,
          invoiced_quantity: l.invoiced_quantity || null,
          unit_cost: l.unit_cost,
          batch_no: l.batch_no || null,
          expiry_date: l.expiry_date || null,
          mrp: l.mrp || null,
          selling_price: l.selling_price || null,
          gst_rate: l.gst_rate || null,
          discount_amount: l.discount_amount || null,
        })),
      });

      setSubmitting(false);
      toast('Purchase recorded. Stock and landing cost are updated.');

      const pending = response.data.price_decisions_pending || [];

      if (pending.length > 0) {
        setPriceQueue({ pending, purchaseOrderId: response.data.id });
      } else {
        onSaved();
      }
    } catch (error) {
      setSubmitting(false);
      setSubmitError(error);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="pi-card">
        <div className="pi-card__header">Vendor &amp; order</div>
        <div className="pi-card__body">
          <div className="pi-grid pi-grid-3">
            <label className="pi-field">
              <span>Vendor</span>
              <select value={vendorUuid} onChange={(e) => setVendorUuid(e.target.value)} required>
                <option value="">Select a vendor…</option>
                {vendors.map((v) => <option key={v.uuid} value={v.uuid}>{v.name}</option>)}
              </select>
              <button type="button" className="pi-link-btn" onClick={() => setShowNewVendor((s) => !s)}>+ New vendor</button>
            </label>
            <label className="pi-field">
              <span>Warehouse</span>
              <select value={warehouseUuid} onChange={(e) => setWarehouseUuid(e.target.value)} required>
                {warehouses.map((w) => <option key={w.uuid} value={w.uuid}>{w.name}</option>)}
              </select>
            </label>
            <label className="pi-field">
              <span>Purchase date</span>
              <input type="date" value={purchaseDate} onChange={(e) => setPurchaseDate(e.target.value)} required />
            </label>
            <label className="pi-field pi-field-lg">
              <span>Vendor invoice / reference</span>
              <input maxLength={80} value={invoiceReference} onChange={(e) => setInvoiceReference(e.target.value)} />
            </label>
          </div>

          {showNewVendor && (
            <div className="pi-card pi-card--accent pi-mt3">
              <div className="pi-card__body">
                <h3 className="pi-h6">New vendor</h3>
                <div className="pi-grid pi-grid-2">
                  <label className="pi-field">
                    <span>Name</span>
                    <input maxLength={150} required value={newVendor.name} onChange={(e) => setNewVendor({ ...newVendor, name: e.target.value })} />
                  </label>
                  <label className="pi-field">
                    <span>GSTIN</span>
                    <input maxLength={20} value={newVendor.gstin} onChange={(e) => setNewVendor({ ...newVendor, gstin: e.target.value })} />
                  </label>
                  <label className="pi-field">
                    <span>Mobile</span>
                    <input maxLength={15} value={newVendor.phone} onChange={(e) => setNewVendor({ ...newVendor, phone: e.target.value })} />
                  </label>
                  <label className="pi-field">
                    <span>Address</span>
                    <input value={newVendor.address_line1} onChange={(e) => setNewVendor({ ...newVendor, address_line1: e.target.value })} />
                  </label>
                  <label className="pi-field">
                    <span>City</span>
                    <input value={newVendor.city} onChange={(e) => setNewVendor({ ...newVendor, city: e.target.value })} />
                  </label>
                  <label className="pi-field">
                    <span>State</span>
                    <input value={newVendor.state} onChange={(e) => setNewVendor({ ...newVendor, state: e.target.value })} />
                  </label>
                  <label className="pi-field">
                    <span>Pincode</span>
                    <input value={newVendor.pincode} onChange={(e) => setNewVendor({ ...newVendor, pincode: e.target.value })} />
                  </label>
                </div>
                {vendorFeedback && <div className={`pi-feedback pi-feedback--${vendorFeedback.tone}`}>{vendorFeedback.text}</div>}
                <div className="pi-toolbar">
                  <button type="button" className="admin-btn admin-btn--primary" disabled={savingVendor} onClick={handleCreateVendor}>
                    {savingVendor ? 'Saving…' : 'Save vendor'}
                  </button>
                  <button type="button" className="admin-btn" onClick={() => { setShowNewVendor(false); setVendorFeedback(null); }}>Cancel</button>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      <div className="pi-card">
        <div className="pi-card__header">Items</div>
        <div className="pi-card__body">
          <div className="pi-toolbar pi-items-toolbar">
            <label className="pi-field pi-field-lg">
              <span>Add existing item</span>
              <div className="pi-inline-group">
                <input
                  ref={skuInputRef}
                  value={skuInput}
                  autoComplete="off"
                  placeholder="Scan, or type a SKU / barcode / item name"
                  onChange={(e) => onSkuInputChange(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      clearTimeout(searchTimerRef.current);
                      lookupSku(skuInput);
                    }
                  }}
                />
                <button type="button" className="admin-btn" onClick={() => lookupSku(skuInput)}>Add</button>
                <button type="button" className="admin-btn" onClick={() => setCameraOpen(true)} aria-label="Scan with camera">📷 Camera</button>
              </div>
            </label>
            <button type="button" className="admin-btn" onClick={() => setShowNewItem((s) => !s)}>+ New item</button>
            <button type="button" className="admin-btn" onClick={() => setShowCsv((s) => !s)}>Upload CSV</button>
          </div>

          {searchResults.length > 0 && (
            <div className="pi-search-results">
              {searchResults.map((m, i) => {
                const seenKey = (x) => `${x.product_name}|${x.weight_grams}`;
                const isDuplicate = searchResults.filter((x) => seenKey(x) === seenKey(m)).length > 1;

                return (
                  <div className="pi-search-result" key={m.uuid || i}>
                    <button type="button" className="pi-search-result__btn" onClick={() => addVariantLine(m)}>
                      <span className="pi-fw-semibold">{m.product_name}</span>
                      <span className="pi-muted pi-small"> {m.variant_name} · {m.sku}</span>
                      {m.product_status === 'published' ? (
                        <span className="status-badge status-badge--success pi-ml4">Published</span>
                      ) : (
                        <span className="status-badge status-badge--secondary pi-ml4">{m.product_status === 'archived' ? 'Unpublished' : 'Draft'}</span>
                      )}
                    </button>
                    {isDuplicate && (
                      <div className="pi-small pi-text-danger pi-duplicate-warning">
                        Another pack size shares this name and weight — confirm the SKU before adding it.{' '}
                        <a href={`/admin/products?edit=${encodeURIComponent(m.product_uuid)}`} target="_blank" rel="noopener noreferrer">Edit this product →</a>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}

          {skuFeedback && <div className={`pi-feedback pi-feedback--${skuFeedback.tone}`}>{skuFeedback.text}</div>}

          {showNewItem && (
            <PurchaseInwardNewItem
              setup={inventorySetup}
              onCategoryCreated={onCategoryCreated}
              onAddLine={addLine}
              onClose={() => setShowNewItem(false)}
            />
          )}

          {showCsv && (
            <PurchaseInwardCsvUpload
              setup={inventorySetup}
              onAddLine={addLine}
              onClose={() => setShowCsv(false)}
            />
          )}

          <div className="pi-lines-table-wrap">
            <table className="admin-table pi-lines-table">
              <thead>
                <tr>
                  <th>Item</th><th>Barcode</th><th>Quantity</th><th>Invoiced qty</th><th>Unit cost</th>
                  <th>Batch</th><th>Expiry</th><th className="pi-right">Line total</th>
                  {transportPercent !== null && <th className="pi-right">Landing cost</th>}
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {lines.length === 0 ? (
                  <tr><td colSpan={transportPercent !== null ? 10 : 9} className="pi-empty-row">No items yet — add an existing item, a new item, or upload a CSV above.</td></tr>
                ) : (
                  lines.map((line, index) => (
                    <LineRow
                      key={line.variant_uuid}
                      line={line}
                      index={index}
                      transportPercent={transportPercent}
                      onUpdate={updateLine}
                      onRemove={removeLine}
                      onGenerateBarcode={generateBarcode}
                      onPrintBarcode={printLineBarcode}
                      busy={busyBarcodeIndex === index}
                    />
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="pi-card">
        <div className="pi-card__header">Charges &amp; landing cost</div>
        <div className="pi-card__body">
          <div className="pi-grid pi-grid-2">
            <label className="pi-field">
              <span>Discount (₹)</span>
              <input type="number" step="0.01" min="0" value={discountAmount} onChange={(e) => setDiscountAmount(e.target.value)} />
            </label>
            <label className="pi-field">
              <span>Other/misc. charges (₹)</span>
              <input type="number" step="0.01" min="0" value={otherCharges} onChange={(e) => setOtherCharges(e.target.value)} />
            </label>
          </div>

          <label className="pi-checkbox-inline pi-mt3">
            <input type="checkbox" checked={taxEnabled} onChange={(e) => { setTaxEnabled(e.target.checked); if (!e.target.checked) setTaxAmount('0'); }} />
            Add tax (GST) to this bill <span className="pi-muted pi-small">— optional</span>
          </label>

          {taxEnabled && (
            <div className="pi-grid pi-grid-2 pi-mt2">
              <label className="pi-field">
                <span>Tax (₹)</span>
                <input type="number" step="0.01" min="0" value={taxAmount} onChange={(e) => setTaxAmount(e.target.value)} />
              </label>
              <div className="pi-field">
                <span>&nbsp;</span>
                <button type="button" className="admin-btn" onClick={calcTaxFromGst}>Calculate from item GST %</button>
              </div>
              <div className="pi-field-full pi-small pi-muted">Tax is added to the bill total only — it does not change item landing cost.</div>
            </div>
          )}

          <label className="pi-checkbox-inline pi-mt3">
            <input
              type="checkbox"
              checked={transportIncluded}
              onChange={(e) => { setTransportIncluded(e.target.checked); if (e.target.checked) setTransportCharge('0'); }}
            />
            The item cost above already includes transportation
          </label>

          {!transportIncluded && (
            <div className="pi-grid pi-grid-2 pi-mt2">
              <label className="pi-field">
                <span>Transportation charge (₹)</span>
                <input type="number" step="0.01" min="0" value={transportCharge} onChange={(e) => setTransportCharge(e.target.value)} />
              </label>
              <div className="pi-field pi-transport-hint">
                {transportPercent !== null && <span className="pi-small pi-muted">≈ {transportPercent.toFixed(2)}% added to every item&rsquo;s landed cost</span>}
              </div>
            </div>
          )}
        </div>
      </div>

      <div className="pi-card">
        <div className="pi-card__header">Payment</div>
        <div className="pi-card__body">
          <div className="pi-grid pi-grid-2">
            <label className="pi-field">
              <span>Amount paid now (₹)</span>
              <input type="number" step="0.01" min="0" value={amountPaid} onChange={(e) => setAmountPaid(e.target.value)} />
            </label>
            <div className="pi-field">
              <span>&nbsp;</span>
              <span className={`status-badge status-badge--${paymentTone}`}>{paymentLabel}</span>
            </div>
          </div>
          <p className="pi-small pi-muted">Leave at 0 if payment is fully deferred — you can record it later from purchase order history.</p>
        </div>
      </div>

      <div className="pi-totals-row">
        <div>Items subtotal: <span className="pi-fw-semibold">{formatMoney(itemsSubtotal)}</span></div>
        <div>Grand total: <span className="pi-fw-semibold pi-grand-total">{formatMoney(grandTotal)}</span></div>
      </div>

      <label className="pi-field pi-mb3">
        <span>Notes</span>
        <textarea rows={2} maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </label>

      {submitError && <div className="pi-feedback pi-feedback--danger">{submitError.message || 'Could not save this purchase.'}</div>}

      <button type="submit" className="admin-btn admin-btn--primary" disabled={submitting}>
        {submitting ? 'Saving…' : 'Record purchase'}
      </button>

      {priceQueue && (
        <PurchaseInwardPriceQueue
          pending={priceQueue.pending}
          purchaseOrderId={priceQueue.purchaseOrderId}
          onDone={() => { setPriceQueue(null); onSaved(); }}
        />
      )}
      {cameraOpen && (
        <CameraScanner
          title="Scan items received"
          hint="Each scan adds the item, or one more of it. Keep scanning, then press Done."
          onDetected={handleCameraCode}
          onClose={() => { setCameraOpen(false); skuInputRef.current?.focus(); }}
        />
      )}
    </form>
  );
}

function LineRow({ line, index, transportPercent, onUpdate, onRemove, onGenerateBarcode, onPrintBarcode, busy }) {
  const lineTotal = (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0);
  const landingUnit = transportPercent === null ? null : (Number(line.unit_cost) || 0) * (1 + transportPercent / 100);

  return (
    <>
      <tr>
        <td>
          <span className="pi-fw-semibold">{line.sku}</span>
          <div className="pi-small pi-muted">
            {line.product_name} · {line.variant_name}
            {line.is_new && <span className="status-badge status-badge--info pi-ml4">New item</span>}
          </div>
        </td>
        <td className="pi-small">
          {line.barcode ? (
            <>
              {line.barcode}<br />
              <button type="button" className="admin-btn pi-btn-xs" onClick={() => onPrintBarcode(line)}>Print</button>
            </>
          ) : (
            <button type="button" className="admin-btn pi-btn-xs" disabled={busy} onClick={() => onGenerateBarcode(index)}>
              {busy ? 'Generating…' : 'Generate'}
            </button>
          )}
        </td>
        <td>
          <input className="pi-input-sm" type="number" step="0.001" min="0.001" value={line.quantity} required
            onChange={(e) => onUpdate(index, 'quantity', e.target.value)} />
        </td>
        <td>
          <input className="pi-input-sm" type="number" step="0.001" min="0" placeholder="Same"
            title="Invoiced quantity, if different from what arrived" value={line.invoiced_quantity}
            onChange={(e) => onUpdate(index, 'invoiced_quantity', e.target.value)} />
        </td>
        <td>
          <input className="pi-input-sm" type="number" step="0.0001" min="0" value={line.unit_cost} required
            onChange={(e) => onUpdate(index, 'unit_cost', e.target.value)} />
        </td>
        <td>
          <input className="pi-input-sm" maxLength={60} placeholder="Auto" title="Leave blank to auto-generate from the GRN number"
            value={line.batch_no} onChange={(e) => onUpdate(index, 'batch_no', e.target.value)} />
        </td>
        <td>
          <input className="pi-input-sm" type="date" value={line.expiry_date} onChange={(e) => onUpdate(index, 'expiry_date', e.target.value)} />
        </td>
        <td className="pi-right pi-small">{formatMoney(lineTotal)}</td>
        {transportPercent !== null && <td className="pi-right pi-small pi-muted">{formatMoney(landingUnit)}/unit</td>}
        <td><button type="button" className="admin-btn admin-btn--danger-outline pi-btn-xs" onClick={() => onRemove(index)}>×</button></td>
      </tr>
      <tr className="pi-pricing-row">
        <td colSpan={transportPercent !== null ? 9 : 8}>
          <div className="pi-pricing-fields">
            <label className="pi-field-sm"><span>MRP</span>
              <input type="number" step="0.01" min="0" placeholder="Optional" value={line.mrp} onChange={(e) => onUpdate(index, 'mrp', e.target.value)} />
            </label>
            <label className="pi-field-sm"><span>Selling price</span>
              <input type="number" step="0.01" min="0" placeholder="Optional" value={line.selling_price} onChange={(e) => onUpdate(index, 'selling_price', e.target.value)} />
            </label>
            <label className="pi-field-sm"><span>GST %</span>
              <input type="number" step="0.01" min="0" max="28" placeholder="Optional" value={line.gst_rate} onChange={(e) => onUpdate(index, 'gst_rate', e.target.value)} />
            </label>
            <label className="pi-field-sm"><span>Line discount (₹)</span>
              <input type="number" step="0.01" min="0" placeholder="0" value={line.discount_amount} onChange={(e) => onUpdate(index, 'discount_amount', e.target.value)} />
            </label>
            {(line.mrp || line.selling_price) && (
              <span className="pi-small pi-muted">MRP/selling price here becomes this item&rsquo;s live price immediately on save.</span>
            )}
          </div>
        </td>
      </tr>
    </>
  );
}
