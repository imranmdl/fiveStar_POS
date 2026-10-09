import { useCallback, useEffect, useState } from 'react';
import { api, formatMoney } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { EmptyState, ErrorState, LoadingState } from '../../components/admin/shared';
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
  const [confirmedAmount, setConfirmedAmount] = useState(payment.amount);
  const [utr, setUtr] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleConfirm(event) {
    event.preventDefault();
    if (!window.confirm('Confirm this payment for order shown above as paid?')) return;

    setBusy(true);
    try {
      await api.post(`/admin/payments/${encodeURIComponent(payment.uuid)}/verify`, {
        confirmed_amount: confirmedAmount,
        utr_or_reference: utr,
      });
      toast('Payment verified — order confirmed.');
      onChanged();
    } catch (error) {
      setBusy(false);
      errorToast(error, 'Could not verify payment.');
    }
  }

  async function handleReject() {
    const reason = window.prompt(
      'Why is this being rejected? (shown in the audit log, e.g. "no matching transfer found")',
    );
    if (!reason || reason.trim().length < 3) return;

    setBusy(true);
    try {
      await api.post(`/admin/payments/${encodeURIComponent(payment.uuid)}/reject`, { reason });
      toast('Payment rejected. The customer can retry.');
      onChanged();
    } catch (error) {
      setBusy(false);
      errorToast(error, 'Could not reject payment.');
    }
  }

  return (
    <div className="payment-card">
      <div className="payment-card__head">
        <div>
          <div className="payment-card__order">Order {payment.order_number}</div>
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
        <code>{payment.order_number}</code> before confirming. Confirming with the wrong amount is refused
        automatically, but confirming a transfer that never happened is not — this decision is the only check.
      </div>

      <form className="payment-card__form" onSubmit={handleConfirm}>
        <label className="payment-card__field">
          <span>Amount received</span>
          <input
            value={confirmedAmount}
            onChange={(event) => setConfirmedAmount(event.target.value)}
            required
            inputMode="decimal"
          />
        </label>
        <label className="payment-card__field">
          <span>UTR / reference (optional)</span>
          <input
            value={utr}
            onChange={(event) => setUtr(event.target.value)}
            placeholder="Bank reference number"
          />
        </label>
        <div className="payment-card__actions">
          <button type="submit" className="admin-btn admin-btn--primary" disabled={busy}>
            {busy ? 'Working…' : 'Confirm payment'}
          </button>
          <button type="button" className="admin-btn" onClick={handleReject} disabled={busy}>
            Reject
          </button>
        </div>
      </form>
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
                {opt}
              </option>
            ))}
          </select>
        </label>
        <label className="settings-field">
          <span>Delivery mode</span>
          <select
            value={form.delivery_driver}
            onChange={(event) => setForm((f) => ({ ...f, delivery_driver: event.target.value }))}
          >
            {(settings.delivery_driver_options || []).map((opt) => (
              <option key={opt} value={opt}>
                {opt}
              </option>
            ))}
          </select>
        </label>
      </div>

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
            {form.payment_driver !== 'razorpay' && ' Choose “razorpay” above and save to start taking payments through it.'}
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
  const initial = () => ({
    ...Object.fromEntries(SHOP_FIELDS.map(([key, field]) => [key, (shop && shop[field]) || ''])),
    receipt: { cashier: true, counter: true, customer: true, ...((shop && shop.receipt) || {}) },
  });
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
      <div className="settings-label" style={{ marginTop: 12 }}>Print on the receipt (only when the sale has it)</div>
      {[['cashier', 'Cashier name'], ['counter', 'Counter / shop label'], ['customer', 'Customer name and mobile']].map(([k, label]) => (
        <label className="settings-check" key={k}>
          <input
            type="checkbox"
            checked={Boolean(form.receipt[k])}
            onChange={(event) => setForm((f) => ({ ...f, receipt: { ...f.receipt, [k]: event.target.checked } }))}
          />
          <span>{label}</span>
        </label>
      ))}
      <div className="payment-card__actions">
        <button type="button" className="admin-btn admin-btn--primary" onClick={save} disabled={saving}>
          {saving ? 'Saving…' : 'Save shop details'}
        </button>
      </div>
    </div>
  );
}
