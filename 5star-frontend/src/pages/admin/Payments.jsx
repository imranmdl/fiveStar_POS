import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
import ManualPaymentForm from '../../components/admin/ManualPaymentForm';
import './Payments.css';

/**
 * Payments — manual UPI/bank-transfer verification queue, the Cash on
 * Delivery approval queue, and the payment/delivery driver settings panel.
 *
 * Ported from admin/assets/page-payments.js. Every confirm/reject here calls
 * the same /admin/payments/{uuid}/verify|reject and
 * /admin/orders/{uuid}/cod/approve|decline endpoints the live console uses —
 * this is just a UI on top of that API, no new business logic.
 */
export default function Payments() {
  const [tab, setTab] = useState('manual');
  const [settingsOpen, setSettingsOpen] = useState(false);

  return (
    <div className="payments-page">
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title">Payments</h1>
        <button type="button" className="admin-btn" onClick={() => setSettingsOpen((open) => !open)}>
          Payment &amp; delivery settings
        </button>
      </div>

      {settingsOpen && <SettingsPanel />}

      <div className="payments-tabs">
        <button
          type="button"
          className={tab === 'manual' ? 'active' : ''}
          onClick={() => setTab('manual')}
        >
          Manual UPI payments
        </button>
        <button
          type="button"
          className={tab === 'cod' ? 'active' : ''}
          onClick={() => setTab('cod')}
        >
          Cash on Delivery
        </button>
      </div>

      <div style={{ display: tab === 'manual' ? 'block' : 'none' }}>
        <ManualQueue />
      </div>
      <div style={{ display: tab === 'cod' ? 'block' : 'none' }}>
        <CodQueue active={tab === 'cod'} />
      </div>
    </div>
  );
}

function errorToast(error, fallback) {
  const detail = error && error.fieldMessages ? error.fieldMessages() : [];
  const message = [(error && error.message) || fallback, ...detail].filter(Boolean).join(' ');
  toast(message, 'danger');
}

/** The staff-checked UPI QR verification queue — the core of this page. */
function ManualQueue() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [items, setItems] = useState([]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/payments/pending', { per_page: 50 });
      setItems((response.data && response.data.items) || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;
  if (items.length === 0) {
    return <EmptyState title="Nothing waiting" hint="No manual payments need review right now." />;
  }

  return (
    <div>
      {items.map((payment) => (
        <PaymentCard key={payment.uuid} payment={payment} onChanged={load} />
      ))}
    </div>
  );
}

function PaymentCard({ payment, onChanged }) {
  return (
    <div className="payment-card">
      <div className="payment-card__head">
        <div>
          <div className="payment-card__order">
            Order {payment.order_number}
            {payment.order_status && payment.order_status !== 'awaiting_payment' && (
              <span className="payment-card__status"> · {payment.order_expired ? 'Closed — payment window passed' : payment.order_status_label}</span>
            )}
          </div>
          <div className="payment-card__meta">
            {payment.customer_name || 'Customer'} · {payment.customer_mobile || ''}
          </div>
          <div className="payment-card__meta">
            Placed {String(payment.created_date || '').slice(0, 16).replace('T', ' ')}
          </div>
        </div>
        <div className="payment-card__amount">
          <div className="payment-card__amount-value">₹{payment.amount}</div>
          <div className="payment-card__meta">Attempt {payment.attempt_number}</div>
        </div>
      </div>

      <div className="admin-alert admin-alert--warning">
        Check your bank or UPI app for a transfer of exactly <strong>₹{payment.amount}</strong> referencing{' '}
        <code>{payment.order_number}</code>, then enter its UTR. A UTR alone is not proof of payment — only confirm a
        transfer you can see in the account. The wrong amount, or a UTR already used for another order, is refused.
      </div>

      <ManualPaymentForm payment={payment} onDone={onChanged} />
    </div>
  );
}

/** Cash on Delivery approval queue — a separate tab sharing this page, as the source does. */
function CodQueue({ active }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [items, setItems] = useState([]);
  const [loadedOnce, setLoadedOnce] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/orders/cod/pending', { per_page: 50 });
      setItems((response.data && response.data.items) || []);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (active && !loadedOnce) {
      setLoadedOnce(true);
      load();
    }
  }, [active, loadedOnce, load]);

  if (!loadedOnce) return null;
  if (loading) return <LoadingState />;
  if (error) return <ErrorState error={error} />;
  if (items.length === 0) {
    return <EmptyState title="Nothing waiting" hint="No Cash on Delivery orders need review right now." />;
  }

  return (
    <div>
      {items.map((order) => (
        <CodCard key={order.uuid} order={order} onChanged={load} />
      ))}
    </div>
  );
}

function CodCard({ order, onChanged }) {
  const [busy, setBusy] = useState(false);

  async function handleApprove() {
    if (!window.confirm('Approve this Cash on Delivery order? It will move straight into packing.')) return;

    setBusy(true);
    try {
      await api.post(`/admin/orders/${encodeURIComponent(order.uuid)}/cod/approve`, {});
      toast('Order approved and confirmed.');
      onChanged();
    } catch (error) {
      setBusy(false);
      errorToast(error, 'Could not approve order.');
    }
  }

  async function handleDecline() {
    const reason = window.prompt('Why is this being declined? (shown in the audit log)');
    if (!reason || reason.trim().length < 3) return;

    setBusy(true);
    try {
      await api.post(`/admin/orders/${encodeURIComponent(order.uuid)}/cod/decline`, { reason });
      toast('Order declined. The customer can retry with UPI.');
      onChanged();
    } catch (error) {
      setBusy(false);
      errorToast(error, 'Could not decline order.');
    }
  }

  return (
    <div className="payment-card">
      <div className="payment-card__head">
        <div>
          <div className="payment-card__order">Order {order.order_number}</div>
          <div className="payment-card__meta">
            Placed {String(order.placed_date || '').slice(0, 16).replace('T', ' ')}
          </div>
        </div>
        <div className="payment-card__amount">
          <div className="payment-card__amount-value">{formatMoney(order.amount_payable)}</div>
          <div className="payment-card__meta">Cash on delivery</div>
        </div>
      </div>

      <div className="admin-alert admin-alert--warning">
        No payment has been collected yet. Approving confirms this order for packing and shipping; the cash is
        collected by the courier on delivery.
      </div>

      <div className="payment-card__actions">
        <button type="button" className="admin-btn admin-btn--primary" onClick={handleApprove} disabled={busy}>
          Approve
        </button>
        <button type="button" className="admin-btn" onClick={handleDecline} disabled={busy}>
          Decline
        </button>
      </div>
    </div>
  );
}

const DELIVERY_DRIVER_LABELS = {
  shiprocket: 'Shiprocket ON — book couriers through Shiprocket',
  manual: 'Shiprocket OFF — I ship myself and enter the tracking number',
  india_post: 'India Post — I book at the post office and enter the tracking number',
  sandbox: 'Sandbox courier (local testing only)',
};

const PAYMENT_DRIVER_LABELS = {
  razorpay: 'Razorpay (online UPI)',
  manual: 'Manual UPI QR (staff confirm)',
  sandbox: 'Sandbox (local testing only)',
};

/**
 * India Post rate card: delivery charge by parcel weight, delivery days and
 * the tracking link customers open. Used at checkout when shipping is set to
 * India Post.
 */
function IndiaPostRates({ value, onSaved }) {
  const initial = value || {};
  const [slabs, setSlabs] = useState(() => (initial.slabs || []).map((r) => ({ up_to_grams: String(r.up_to_grams), charge: String(r.charge) })));
  const [extra, setExtra] = useState(String(initial.extra_per_500g ?? ''));
  const [minDays, setMinDays] = useState(String(initial.min_days ?? 3));
  const [maxDays, setMaxDays] = useState(String(initial.max_days ?? 7));
  const [url, setUrl] = useState(initial.tracking_url || 'https://www.indiapost.gov.in/');
  const [saving, setSaving] = useState(false);
  const [testWeight, setTestWeight] = useState('750');

  const setSlab = (i, k) => (e) => setSlabs((list) => list.map((r, j) => (j === i ? { ...r, [k]: e.target.value } : r)));

  function preview(grams) {
    const g = Number(grams);
    if (!(g > 0)) return null;
    const rows = slabs.map((r) => ({ up: Number(r.up_to_grams), charge: Number(r.charge) })).filter((r) => r.up > 0);
    if (rows.length === 0) return null;
    const hit = rows.find((r) => g <= r.up);
    if (hit) return hit.charge;
    const last = rows[rows.length - 1];
    return last.charge + Math.ceil((g - last.up) / 500) * Number(extra || 0);
  }

  async function save() {
    setSaving(true);
    try {
      await api.put('/admin/settings/india-post', {
        slabs: slabs.map((r) => ({ up_to_grams: Number(r.up_to_grams), charge: Number(r.charge) })),
        extra_per_500g: Number(extra || 0),
        min_days: Number(minDays),
        max_days: Number(maxDays),
        tracking_url: url.trim(),
      });
      toast('India Post rates saved. Checkout uses them from now on.');
      onSaved();
    } catch (error) {
      errorToast(error, 'Could not save the India Post rates.');
    } finally {
      setSaving(false);
    }
  }

  const sample = preview(testWeight);

  return (
    <div className="india-post">
      <h3>India Post rate card</h3>
      {initial.is_placeholder && (
        <div className="settings-note settings-note--warn">
          These are starting placeholder rates. Enter your post office&apos;s Speed Post / parcel charges before customers order.
        </div>
      )}
      <p className="settings-note">
        Delivery charge at checkout, by total parcel weight. Orders above your free-delivery amount still ship free.
      </p>
      <table className="admin-table india-post__table">
        <thead>
          <tr><th>Parcel weight up to (g)</th><th>Charge (₹)</th><th aria-label="Remove" /></tr>
        </thead>
        <tbody>
          {slabs.map((r, i) => (
            // eslint-disable-next-line react/no-array-index-key
            <tr key={i}>
              <td><input type="number" min="1" step="1" inputMode="numeric" value={r.up_to_grams} onChange={setSlab(i, 'up_to_grams')} aria-label={`Row ${i + 1} weight`} /></td>
              <td><input type="number" min="0" step="0.01" inputMode="decimal" value={r.charge} onChange={setSlab(i, 'charge')} aria-label={`Row ${i + 1} charge`} /></td>
              <td>
                <button type="button" className="admin-btn" disabled={slabs.length <= 1} onClick={() => setSlabs((list) => list.filter((_, j) => j !== i))}>Remove</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <button
        type="button"
        className="admin-btn"
        onClick={() => setSlabs((list) => [...list, { up_to_grams: String((Number(list[list.length - 1]?.up_to_grams) || 0) + 500), charge: '' }])}
      >
        + Add weight band
      </button>
      <div className="india-post__grid">
        <label className="settings-field">
          <span>Each further 500 g above the last band (₹)</span>
          <input type="number" min="0" step="0.01" value={extra} onChange={(e) => setExtra(e.target.value)} />
        </label>
        <label className="settings-field">
          <span>Delivery days (from)</span>
          <input type="number" min="1" max="60" value={minDays} onChange={(e) => setMinDays(e.target.value)} />
        </label>
        <label className="settings-field">
          <span>Delivery days (to)</span>
          <input type="number" min="1" max="60" value={maxDays} onChange={(e) => setMaxDays(e.target.value)} />
        </label>
        <label className="settings-field india-post__url">
          <span>Tracking link customers open</span>
          <input type="url" value={url} onChange={(e) => setUrl(e.target.value)} />
          <small>Write {'{awb}'} where the tracking number goes, if the page accepts it in the link.</small>
        </label>
      </div>
      <div className="india-post__check">
        <span>Check: a parcel of</span>
        <input type="number" min="1" value={testWeight} onChange={(e) => setTestWeight(e.target.value)} aria-label="Test weight in grams" />
        <span>g costs <b>{sample == null ? '—' : formatMoney(sample)}</b></span>
      </div>
      <button type="button" className="admin-btn admin-btn--primary" disabled={saving} onClick={save}>
        {saving ? 'Saving…' : 'Save India Post rates'}
      </button>
    </div>
  );
}

/** The driver toggle + manual QR/logo settings, collapsed by default so the queue stays the focus. */
function SettingsPanel() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [settings, setSettings] = useState(null);
  const [form, setForm] = useState({
    payment_driver: '',
    delivery_driver: '',
    cod_enabled: false,
    manual_payment_vpa: '',
    manual_payment_payee_name: '',
  });
  const [saving, setSaving] = useState(false);
  const [qrFile, setQrFile] = useState(null);
  const [logoFile, setLogoFile] = useState(null);
  const [uploadingQr, setUploadingQr] = useState(false);
  const [uploadingLogo, setUploadingLogo] = useState(false);
  const [logoUrlDisplay, setLogoUrlDisplay] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get('/admin/settings');
      const s = response.data;
      setSettings(s);
      setForm({
        payment_driver: s.payment_driver,
        delivery_driver: s.delivery_driver,
        cod_enabled: Boolean(s.cod_enabled),
        manual_payment_vpa: s.manual_payment_vpa || '',
        manual_payment_payee_name: s.manual_payment_payee_name || '',
      });
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function handleSave() {
    setSaving(true);
    try {
      await api.patch('/admin/settings/payment-driver', { driver: form.payment_driver });
      await api.patch('/admin/settings/delivery-driver', { driver: form.delivery_driver });
      await api.patch('/admin/settings/cod', { enabled: form.cod_enabled });
      await api.patch('/admin/settings/manual', {
        manual_payment_vpa: form.manual_payment_vpa,
        manual_payment_payee_name: form.manual_payment_payee_name,
      });
      toast('Settings saved.');
      load();
    } catch (error) {
      errorToast(error, 'Could not save settings.');
    } finally {
      setSaving(false);
    }
  }

  async function handleUploadQr() {
    if (!qrFile) {
      toast('Choose an image file first.', 'warning');
      return;
    }
    setUploadingQr(true);
    try {
      const formData = new FormData();
      formData.append('image', qrFile);
      await api.upload('/admin/settings/manual/qr-image', formData);
      toast('QR code updated.');
      setQrFile(null);
      load();
    } catch (error) {
      errorToast(error, 'Could not upload QR code.');
    } finally {
      setUploadingQr(false);
    }
  }

  async function handleUploadLogo() {
    if (!logoFile) {
      toast('Choose an image file first.', 'warning');
      return;
    }
    setUploadingLogo(true);
    try {
      const formData = new FormData();
      formData.append('image', logoFile);
      const response = await api.upload('/admin/settings/logo', formData);
      const url = (response.data && response.data.store_logo_url) || '';
      toast('Logo uploaded. Copy the URL into config.js as shown below.');
      setLogoUrlDisplay(url);
      setLogoFile(null);
      load();
    } catch (error) {
      errorToast(error, 'Could not upload logo.');
    } finally {
      setUploadingLogo(false);
    }
  }

  if (loading) {
    return (
      <div className="settings-panel">
        <LoadingState />
      </div>
    );
  }
  if (error) {
    return (
      <div className="settings-panel">
        <ErrorState error={error} />
      </div>
    );
  }
  if (!settings) return null;

  return (
    <div className="settings-panel">
      <div className="settings-grid">
        <label className="settings-field">
          <span>Payment gateway</span>
          <select
            value={form.payment_driver}
            onChange={(event) => setForm((f) => ({ ...f, payment_driver: event.target.value }))}
          >
            {(settings.payment_driver_options || []).map((opt) => (
              <option key={opt} value={opt}>
                {PAYMENT_DRIVER_LABELS[opt] || opt}
              </option>
            ))}
          </select>
        </label>
        <label className="settings-field">
          <span>Shipping (Shiprocket / India Post / own courier)</span>
          <select
            value={form.delivery_driver}
            onChange={(event) => setForm((f) => ({ ...f, delivery_driver: event.target.value }))}
          >
            {(settings.delivery_driver_options || []).map((opt) => (
              <option key={opt} value={opt}>
                {DELIVERY_DRIVER_LABELS[opt] || opt}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="settings-note">
        {form.delivery_driver === 'shiprocket'
          ? 'Orders offer “Book with Shiprocket”, and “Ship with my own courier” for parcels you send another way.'
          : form.delivery_driver === 'india_post'
            ? 'Pack the order, book it at the post office, then on the order press “Shipped by India Post — enter tracking no.” and type the article number (e.g. EA123456789IN). The customer gets the number and the India Post tracking link. Checkout charges delivery from the India Post rate card below.'
            : 'Orders offer “Ship with my own courier”: pick the courier (India Post, DTDC, local delivery…), enter the tracking number from its receipt, and the customer is sent it. Mark the parcel delivered from the order when it arrives.'}
      </div>

      {(form.delivery_driver === 'india_post' || settings.delivery_driver === 'india_post') && (
        <IndiaPostRates value={settings.india_post} onSaved={load} />
      )}

      {form.payment_driver === 'manual' && !settings.manual_payment_qr_url && !form.manual_payment_vpa && (
        <div className="settings-note settings-note--warn">
          Manual UPI is selected but there is no QR code or UPI ID below, so customers see nothing to pay with.
          Upload a QR code / enter a UPI ID, or choose “Razorpay (online UPI)”.
        </div>
      )}
      {(() => {
        const rz = settings.razorpay || {};
        if (!rz.configured) {
          return form.payment_driver === 'razorpay' ? (
            <div className="settings-note settings-note--warn">
              Razorpay keys are not set on the server. Add <code>RAZORPAY_KEY_ID</code> and <code>RAZORPAY_KEY_SECRET</code> in Railway → Variables, then choose razorpay here.
            </div>
          ) : null;
        }
        return (
          <div className={`settings-note${rz.mode === 'test' || !rz.webhook_configured ? ' settings-note--warn' : ''}`}>
            Razorpay keys are set — <b>{rz.mode === 'live' ? 'LIVE mode (real payments)' : 'TEST mode (no real money)'}</b>.
            {form.payment_driver !== 'razorpay' && ' Choose “Razorpay (online UPI)” above and save to start taking payments through it.'}
            {!rz.webhook_configured && (
              <> Webhook secret not set: add <code>RAZORPAY_WEBHOOK_SECRET</code> so payments are still confirmed when a customer closes the payment screen early.</>
            )}
          </div>
        );
      })()}

      <label className="settings-check">
        <input
          type="checkbox"
          checked={form.cod_enabled}
          onChange={(event) => setForm((f) => ({ ...f, cod_enabled: event.target.checked }))}
        />
        <span>Offer Cash on Delivery at checkout, alongside QR payment</span>
      </label>

      <div className="settings-grid">
        <label className="settings-field">
          <span>Shop UPI ID — used for payment QR codes</span>
          <input
            value={form.manual_payment_vpa}
            onChange={(event) => setForm((f) => ({ ...f, manual_payment_vpa: event.target.value }))}
          />
        </label>
        <label className="settings-field">
          <span>Name shown in UPI apps</span>
          <input
            value={form.manual_payment_payee_name}
            onChange={(event) => setForm((f) => ({ ...f, manual_payment_payee_name: event.target.value }))}
          />
        </label>
      </div>

      <div className="payment-card__actions">
        <button type="button" className="admin-btn admin-btn--primary" onClick={handleSave} disabled={saving}>
          {saving ? 'Saving…' : 'Save'}
        </button>
      </div>

      <hr />

      <div className="settings-grid">
        <div>
          <div className="settings-label">Manual payment QR code</div>
          {settings.manual_payment_qr_url ? (
            <div className="settings-image">
              <img src={settings.manual_payment_qr_url} alt="Current QR" />
            </div>
          ) : (
            <div className="small-muted">No QR code uploaded yet.</div>
          )}
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            onChange={(event) => setQrFile(event.target.files[0] || null)}
          />
          <div>
            <button type="button" className="admin-btn" onClick={handleUploadQr} disabled={uploadingQr}>
              {uploadingQr ? 'Uploading…' : 'Upload'}
            </button>
          </div>
        </div>

        <div>
          <div className="settings-label">Store logo</div>
          {settings.store_logo_url ? (
            <div className="settings-image settings-image--logo">
              <img src={settings.store_logo_url} alt="Current logo" />
            </div>
          ) : (
            <div className="small-muted">No logo uploaded yet.</div>
          )}
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            onChange={(event) => setLogoFile(event.target.files[0] || null)}
          />
          <div>
            <button type="button" className="admin-btn" onClick={handleUploadLogo} disabled={uploadingLogo}>
              {uploadingLogo ? 'Uploading…' : 'Upload'}
            </button>
          </div>
          <p className="small-muted">
            After uploading, copy the URL shown below into <code>logoUrl</code> in both{' '}
            <code>assets/js/config.js</code> and <code>admin/assets/config.js</code> — the storefront and
            console read the logo from those files, not live from here.
          </p>
          {logoUrlDisplay && (
            <div className="small-muted">
              <code>{logoUrlDisplay}</code>
            </div>
          )}
        </div>
      </div>

      <hr />

      <ShopDetailsCard shop={settings.shop} onSaved={load} />
    </div>
  );
}

const SHOP_FIELDS = [
  ['store_name', 'name', 'Shop name', 'Five Star Spices & Dry Fruits'],
  ['store_address_line1', 'address_line1', 'Address line 1', 'Shop no., street'],
  ['store_address_line2', 'address_line2', 'Address line 2', 'Area / landmark (optional)'],
  ['store_city', 'city', 'City', 'Bengaluru'],
  ['store_state', 'state', 'State', 'Karnataka'],
  ['store_pincode', 'pincode', 'PIN code', '560001'],
  ['store_phone', 'phone', 'Phone / mobile', '98XXXXXXXX'],
  ['store_email', 'email', 'Email (optional)', 'shop@example.com'],
  ['store_website', 'website', 'Website', 'https://fivestarspices.com'],
  ['seller_gstin', 'gstin', 'GSTIN (optional)', '29ABCDE1234F1Z5'],
];

/**
 * Shop details printed on till receipts (name, address, contact, website,
 * GSTIN). Blank address/phone fall back to the default warehouse's.
 */
function ShopDetailsCard({ shop, onSaved }) {
  const initial = () => Object.fromEntries(SHOP_FIELDS.map(([key, field]) => [key, (shop && shop[field]) || '']));
  const [form, setForm] = useState(initial);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    setForm(initial());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [shop]);

  async function save() {
    setSaving(true);
    setFieldErrors({});
    try {
      await api.patch('/admin/settings/shop', form);
      toast('Shop details saved. New receipts will use them.');
      onSaved();
    } catch (error) {
      setFieldErrors(error?.errors && typeof error.errors === 'object' ? error.errors : {});
      errorToast(error, 'Could not save shop details.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <div className="settings-label">Shop details — printed on receipts</div>
      <p className="small-muted">
        Shown at the top and bottom of every till receipt. The UPI ID for payment QR codes is the
        &ldquo;Shop UPI ID&rdquo; above.
        {shop && !shop.upi && ' No valid UPI ID is set yet, so receipts and the till will not show a payment QR.'}
      </p>
      <div className="settings-grid">
        {SHOP_FIELDS.map(([key, , label, placeholder]) => (
          <label className="settings-field" key={key}>
            <span>{label}</span>
            <input
              value={form[key]}
              placeholder={placeholder}
              onChange={(event) => setForm((f) => ({ ...f, [key]: event.target.value }))}
            />
            {fieldErrors[key] && <span className="settings-error">{[].concat(fieldErrors[key])[0]}</span>}
          </label>
        ))}
      </div>
      <div className="payment-card__actions">
        <button type="button" className="admin-btn admin-btn--primary" onClick={save} disabled={saving}>
          {saving ? 'Saving…' : 'Save shop details'}
        </button>
      </div>
    </div>
  );
}
