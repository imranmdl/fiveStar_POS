/**
 * Promotions: coupons (code the customer types) and automatic offers (apply
 * on their own, no code). Ported faithfully from the live
 * admin/assets/page-promotions.js — this system already existed before the
 * React migration; nothing here is a redesign.
 *
 * One coupon per order, plus one automatic offer (whichever is best for the
 * customer), plus wallet credit on top of both.
 */
import { Fragment, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatMoney, ApiError } from '../../lib/api';
import { StatusBadge, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared';
import { toast } from '../../components/admin/toast';
import OfferForm from './PromotionOfferForm';
import './Promotions.css';

function reportError(error) {
  const messages = error instanceof ApiError ? error.fieldMessages() : [];
  const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Something went wrong.');
  toast(text, 'danger');
}

const COUPON_DEFAULTS = {
  code: '',
  title: '',
  discount_type: 'percentage',
  discount_value: '',
  max_discount_amount: '',
  min_order_value: '',
  valid_to: '',
  total_usage_limit: '',
  per_customer_limit: '1',
  audience: 'all',
};

function CouponForm({ onCreated, onCancel }) {
  const [fields, setFields] = useState(COUPON_DEFAULTS);
  const [busy, setBusy] = useState(false);

  const set = (name) => (event) => setFields((f) => ({ ...f, [name]: event.target.value }));

  const type = fields.discount_type;
  const showValue = type !== 'free_delivery';
  const showCap = type === 'percentage';
  const valueHint = type === 'percentage' ? 'Percent, e.g. 20 for 20% off.' : 'Amount in rupees off the order.';

  async function handleSubmit(event) {
    event.preventDefault();
    const payload = {};
    Object.entries(fields).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) payload[key] = value;
    });
    payload.code = String(payload.code || '').toUpperCase();

    if (payload.discount_type === 'free_delivery') payload.discount_value = 0;

    if (payload.discount_type === 'percentage' && Number(payload.discount_value) > 100) {
      toast('A percentage discount cannot be more than 100%.', 'danger');
      return;
    }

    setBusy(true);
    try {
      await api.post('/admin/coupons', payload);
      toast(`Coupon ${payload.code} created. Activate it when you are ready.`);
      setFields(COUPON_DEFAULTS);
      onCreated();
    } catch (error) {
      reportError(error);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="promo-card">
      <div className="promo-card__header">New coupon</div>
      <div className="promo-card__body">
        <form className="promo-form-grid" onSubmit={handleSubmit}>
          <div className="promo-field">
            <label htmlFor="code">Code *</label>
            <input id="code" required minLength={3} maxLength={30} placeholder="DIWALI20"
                   style={{ textTransform: 'uppercase' }}
                   value={fields.code} onChange={set('code')} />
            <div className="promo-hint">What the customer types at checkout.</div>
          </div>

          <div className="promo-field">
            <label htmlFor="title">Title *</label>
            <input id="title" required minLength={3} placeholder="Diwali festival discount"
                   value={fields.title} onChange={set('title')} />
          </div>

          <div className="promo-field">
            <label htmlFor="discount_type">Type *</label>
            <select id="discount_type" required value={fields.discount_type} onChange={set('discount_type')}>
              <option value="percentage">Percentage off</option>
              <option value="flat">Fixed amount off</option>
              <option value="free_delivery">Free delivery</option>
            </select>
          </div>

          {showValue && (
            <div className="promo-field">
              <label htmlFor="discount_value">Value *</label>
              <input id="discount_value" type="number" step="0.01" min="0" placeholder="20"
                     required={showValue} value={fields.discount_value} onChange={set('discount_value')} />
              <div className="promo-hint">{valueHint}</div>
            </div>
          )}

          {showCap && (
            <div className="promo-field">
              <label htmlFor="max_discount_amount">Cap the discount at</label>
              <input id="max_discount_amount" type="number" step="0.01" min="1" placeholder="500"
                     value={fields.max_discount_amount} onChange={set('max_discount_amount')} />
              <div className="promo-hint">Strongly advised on a percentage coupon, or a large order gives away a large amount.</div>
            </div>
          )}

          <div className="promo-field">
            <label htmlFor="min_order_value">Minimum order value</label>
            <input id="min_order_value" type="number" step="0.01" min="0" placeholder="199"
                   value={fields.min_order_value} onChange={set('min_order_value')} />
          </div>

          <div className="promo-field">
            <label htmlFor="valid_to">Expires on</label>
            <input id="valid_to" type="date" value={fields.valid_to} onChange={set('valid_to')} />
            <div className="promo-hint">Leave blank to run indefinitely.</div>
          </div>

          <div className="promo-field">
            <label htmlFor="total_usage_limit">Total uses allowed</label>
            <input id="total_usage_limit" type="number" min="1" placeholder="100"
                   value={fields.total_usage_limit} onChange={set('total_usage_limit')} />
            <div className="promo-hint">Blank means unlimited.</div>
          </div>

          <div className="promo-field">
            <label htmlFor="per_customer_limit">Uses per customer</label>
            <input id="per_customer_limit" type="number" min="1"
                   value={fields.per_customer_limit} onChange={set('per_customer_limit')} />
          </div>

          <div className="promo-field">
            <label htmlFor="audience">Who can use it</label>
            <select id="audience" value={fields.audience} onChange={set('audience')}>
              <option value="all">Anyone</option>
              <option value="new_customers">First-time customers only</option>
            </select>
          </div>

          <div className="promo-actions">
            <button className="admin-btn admin-btn--primary" type="submit" disabled={busy}>
              {busy ? 'Creating…' : 'Create coupon'}
            </button>
            <button className="admin-btn" type="button" onClick={onCancel}>Cancel</button>
            <span className="promo-table-note">Created paused, so nothing goes live by accident. Activate it when ready.</span>
          </div>
        </form>
      </div>
    </div>
  );
}

function couponDiscountLabel(coupon) {
  return coupon.discount_type === 'percentage'
    ? `${coupon.discount_value}% off`
    : formatMoney(coupon.discount_value) + ' off';
}

function CouponsTable({ coupons, onToggleStatus, busyUuid }) {
  if (coupons.length === 0) {
    return <EmptyState title="No coupons" hint="Create one through the API." />;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead>
          <tr><th>Code</th><th>Discount</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          {coupons.map((coupon) => {
            const used = Number(coupon.total_redeemed || 0);
            const limit = coupon.total_usage_limit;
            const exhausted = limit && used >= limit;
            return (
              <tr key={coupon.uuid}>
                <td>
                  <div style={{ fontWeight: 600 }}>{coupon.code}</div>
                  <div className="promo-table-note">{coupon.title || ''}</div>
                </td>
                <td>{couponDiscountLabel(coupon)}</td>
                <td>
                  {used}{limit ? ` / ${limit}` : ''}
                  {exhausted && <div style={{ color: '#c0392b' }}>Exhausted</div>}
                </td>
                <td>{String(coupon.valid_to || '—').slice(0, 10)}</td>
                <td><StatusBadge status={coupon.status === 'active' ? 'approved' : 'pending'} label={coupon.status} /></td>
                <td>
                  <button className="admin-btn" disabled={busyUuid === coupon.uuid}
                          onClick={() => onToggleStatus(coupon)}>
                    {coupon.status === 'active' ? 'Pause' : 'Activate'}
                  </button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function offerDiscountLabel(offer) {
  if (offer.discount && offer.discount.summary) return offer.discount.summary;
  return offer.discount_type === 'percentage' ? `${offer.discount_value}%` : formatMoney(offer.discount_value);
}

function OffersTable({ offers, onEdit, onToggleStatus, busyUuid, rowErrors }) {
  if (offers.length === 0) {
    return <EmptyState title="No offers" hint="Automatic offers apply without a code." />;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="admin-table">
        <thead>
          <tr><th>Offer</th><th>Discount</th><th>Runs until</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          {offers.map((offer) => {
            const usage = offer.usage || {};
            const schedule = offer.schedule || {};
            return (
              <Fragment key={offer.uuid}>
                <tr>
                  <td>
                    <div style={{ fontWeight: 600 }}>{offer.title}</div>
                    <div className="promo-table-note">{offer.code || ''}</div>
                    <div className="promo-table-note">
                      {offer.applies_to === 'categories' ? 'Scoped: specific categories'
                        : offer.applies_to === 'products' ? 'Scoped: specific products' : ''}
                      {offer.audience === 'new_customers' ? ' · First-time customers only' : ''}
                    </div>
                  </td>
                  <td>{offerDiscountLabel(offer)}</td>
                  <td>
                    {String(schedule.ends_date || '—').slice(0, 10)}
                    {usage.limit !== null && usage.limit !== undefined && (
                      <div className="promo-table-note">{usage.used} / {usage.limit} used</div>
                    )}
                  </td>
                  <td><StatusBadge status={offer.status === 'active' ? 'approved' : 'pending'} label={offer.status} /></td>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    <button className="admin-btn" onClick={() => onEdit(offer)}>Edit</button>{' '}
                    <button className="admin-btn" disabled={busyUuid === offer.uuid}
                            onClick={() => onToggleStatus(offer)}>
                      {offer.status === 'active' ? 'Pause' : 'Activate'}
                    </button>
                  </td>
                </tr>
                {rowErrors[offer.uuid] && (
                  <tr className="promo-error-row">
                    <td colSpan={5}>
                      <div className="admin-alert admin-alert--danger">{rowErrors[offer.uuid]}</div>
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export default function Promotions() {
  const [searchParams, setSearchParams] = useSearchParams();

  const [coupons, setCoupons] = useState([]);
  const [couponsLoading, setCouponsLoading] = useState(true);
  const [couponsError, setCouponsError] = useState(null);
  const [couponBusyUuid, setCouponBusyUuid] = useState(null);
  const [couponFormOpen, setCouponFormOpen] = useState(false);

  const [offers, setOffers] = useState([]);
  const [offersLoading, setOffersLoading] = useState(true);
  const [offersError, setOffersError] = useState(null);
  const [offerBusyUuid, setOfferBusyUuid] = useState(null);
  const [offerRowErrors, setOfferRowErrors] = useState({});

  const [offerFormOpen, setOfferFormOpen] = useState(false);
  const [editingOffer, setEditingOffer] = useState(null);
  const [offerPrefill, setOfferPrefill] = useState(null);

  const loadCoupons = useCallback(async () => {
    setCouponsLoading(true);
    setCouponsError(null);
    try {
      const response = await api.get('/admin/coupons', { per_page: 50 });
      setCoupons(response.data || []);
    } catch (error) {
      setCouponsError(error);
    } finally {
      setCouponsLoading(false);
    }
  }, []);

  const loadOffers = useCallback(async () => {
    setOffersLoading(true);
    setOffersError(null);
    try {
      const response = await api.get('/admin/offers', { per_page: 50 });
      setOffers(response.data || []);
    } catch (error) {
      setOffersError(error);
    } finally {
      setOffersLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCoupons();
    loadOffers();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Deep link from the Dashboard's recommended-offers panel:
  // /admin/promotions?new_offer=1&title=...&discount_value=... opens a blank
  // offer editor pre-filled with the suggested title/discount. Still just a
  // draft — staff still set the scope and Activate it themselves.
  useEffect(() => {
    if (searchParams.get('new_offer') === '1') {
      setEditingOffer(null);
      setOfferPrefill({
        title: searchParams.get('title') || '',
        discount_value: searchParams.get('discount_value') || '',
      });
      setOfferFormOpen(true);

      const next = new URLSearchParams(searchParams);
      next.delete('new_offer');
      next.delete('title');
      next.delete('discount_value');
      setSearchParams(next, { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function toggleCouponStatus(coupon) {
    const next = coupon.status === 'active' ? 'paused' : 'active';
    setCouponBusyUuid(coupon.uuid);
    try {
      await api.post(`/admin/coupons/${encodeURIComponent(coupon.uuid)}/status`, { status: next });
      toast(next === 'active' ? 'Coupon is live.' : 'Coupon paused.');
      await loadCoupons();
    } catch (error) {
      reportError(error);
    } finally {
      setCouponBusyUuid(null);
    }
  }

  async function toggleOfferStatus(offer) {
    const next = offer.status === 'active' ? 'paused' : 'active';
    setOfferBusyUuid(offer.uuid);
    setOfferRowErrors((rows) => ({ ...rows, [offer.uuid]: null }));
    try {
      await api.post(`/admin/offers/${encodeURIComponent(offer.uuid)}/status`, { status: next });
      toast(next === 'active' ? 'Offer activated.' : 'Offer paused.');
      await loadOffers();
    } catch (error) {
      // The API's own reasons (e.g. "Set an end date.", "Set a maximum
      // discount.") are the real explanation — shown inline under the row
      // instead of a generic toast.
      const messages = error instanceof ApiError ? error.fieldMessages() : [];
      const text = messages.length > 0 ? `${error.message} ${messages.join(' ')}` : (error.message || 'Could not update the offer.');
      setOfferRowErrors((rows) => ({ ...rows, [offer.uuid]: text }));
    } finally {
      setOfferBusyUuid(null);
    }
  }

  function openNewOffer() {
    if (offerFormOpen && !editingOffer) {
      setOfferFormOpen(false);
      return;
    }
    setEditingOffer(null);
    setOfferPrefill(null);
    setOfferFormOpen(true);
  }

  function openEditOffer(offer) {
    setEditingOffer(offer);
    setOfferPrefill(null);
    setOfferFormOpen(true);
  }

  function closeOfferForm() {
    setOfferFormOpen(false);
    setEditingOffer(null);
    setOfferPrefill(null);
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title">Promotions</h1>
        <div className="promo-toolbar-right">
          <button className="admin-btn" type="button" onClick={openNewOffer}>Create an offer</button>
          <button className="admin-btn admin-btn--primary" type="button"
                  onClick={() => setCouponFormOpen((v) => !v)}>Create a coupon</button>
        </div>
      </div>

      {couponFormOpen && (
        <CouponForm
          onCreated={() => { setCouponFormOpen(false); loadCoupons(); }}
          onCancel={() => setCouponFormOpen(false)}
        />
      )}

      {offerFormOpen && (
        <OfferForm
          key={editingOffer ? editingOffer.uuid : 'new'}
          offer={editingOffer}
          prefill={offerPrefill}
          onCancel={closeOfferForm}
          onSaved={() => { closeOfferForm(); loadOffers(); }}
        />
      )}

      <div className="promo-card">
        <div className="promo-card__header">Coupons</div>
        <div className="promo-card__body" style={{ padding: 0 }}>
          {couponsLoading ? <LoadingState /> : couponsError ? <ErrorState error={couponsError} /> : (
            <CouponsTable coupons={coupons} onToggleStatus={toggleCouponStatus} busyUuid={couponBusyUuid} />
          )}
        </div>
      </div>

      <div className="promo-card">
        <div className="promo-card__header">Automatic offers</div>
        <div className="promo-card__body" style={{ padding: 0 }}>
          {offersLoading ? <LoadingState /> : offersError ? <ErrorState error={offersError} /> : (
            <OffersTable offers={offers} onEdit={openEditOffer} onToggleStatus={toggleOfferStatus}
                         busyUuid={offerBusyUuid} rowErrors={offerRowErrors} />
          )}
        </div>
      </div>

      <p className="promo-table-note">
        One coupon per order, plus one automatic offer — whichever is best for the customer. Wallet credit applies on top of both.
      </p>
    </div>
  );
}
