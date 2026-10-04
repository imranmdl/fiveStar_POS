import { useCallback, useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { toast } from '../../components/admin/toast';
import { StatusBadge, EmptyState, LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import './Reviews.css';

/** Review moderation: approve, reject, hide, reply. Ported from admin/assets/page-reviews.js. */

const FILTERS = [
  ['', 'Needs a decision'],
  ['approved', 'Published'],
  ['rejected', 'Rejected'],
  ['hidden', 'Hidden'],
];

function Stars({ rating }) {
  const n = Number(rating) || 0;
  return (
    <span className="review-stars" aria-label={`${n} out of 5 stars`}>
      {'★'.repeat(n)}
      {'☆'.repeat(5 - n)}
    </span>
  );
}

/** Product reviews card: product name, verified-purchase badge, report count, our reply, and the moderation actions. */
function ProductReviewCard({ review, busy, onModerate, onReply }) {
  return (
    <div className="review-card">
      <div className="review-card__top">
        <div className="review-card__meta">
          <span className="review-card__title">{review.product_name || 'Product'}</span>
          <StatusBadge status={review.status} />
          {review.is_verified_purchase ? (
            <span className="review-chip review-chip--success">Verified purchase</span>
          ) : (
            <span className="review-chip review-chip--warning">Not a verified purchase</span>
          )}
          {Number(review.report_count) > 0 && (
            <span className="review-chip review-chip--danger">{review.report_count} report(s)</span>
          )}
        </div>
        <Stars rating={review.rating} />
      </div>

      <div className="review-card__byline">
        {review.author || 'Customer'} · {String(review.created_date || '').slice(0, 10)}
      </div>

      {review.title && <div className="review-card__headline">{review.title}</div>}
      {review.body ? (
        <p className="review-card__body">{review.body}</p>
      ) : (
        <p className="review-card__body review-card__body--muted">Rating only, no text.</p>
      )}

      {review.merchant_reply && (
        <div className="review-card__reply">
          <span className="fw-semibold">Our reply:</span> {review.merchant_reply}
        </div>
      )}

      <div className="review-card__actions">
        {review.status !== 'approved' && (
          <button className="admin-btn admin-btn--success" disabled={busy} onClick={() => onModerate(review, 'approved')}>
            Publish
          </button>
        )}
        {review.status !== 'rejected' && (
          <button className="admin-btn admin-btn--danger-outline" disabled={busy} onClick={() => onModerate(review, 'rejected')}>
            Reject
          </button>
        )}
        {review.status === 'approved' && (
          <button className="admin-btn" disabled={busy} onClick={() => onModerate(review, 'hidden')}>
            Hide
          </button>
        )}
        {review.status === 'approved' && !review.merchant_reply && (
          <button className="admin-btn" disabled={busy} onClick={() => onReply(review)}>
            Reply publicly
          </button>
        )}
      </div>
    </div>
  );
}

/** Store reviews card: ratings left on the shop home page, not tied to a product. */
function StoreReviewCard({ review, busy, onModerate, onReply }) {
  return (
    <div className="review-card">
      <div className="review-card__top">
        <div className="review-card__meta">
          <span className="review-card__title">{review.reviewer_name}</span>
          <StatusBadge status={review.status} />
          {review.reviewer_mobile && <span className="review-card__muted">{review.reviewer_mobile}</span>}
        </div>
        <Stars rating={review.rating} />
      </div>

      <div className="review-card__byline">{String(review.created_date || '').slice(0, 16).replace('T', ' ')}</div>

      {review.body ? (
        <p className="review-card__body">{review.body}</p>
      ) : (
        <p className="review-card__body review-card__body--muted">Rating only, no text.</p>
      )}

      {review.merchant_reply && (
        <div className="review-card__reply">
          <span className="fw-semibold">Our reply:</span> {review.merchant_reply}
        </div>
      )}

      <div className="review-card__actions">
        {review.status !== 'approved' && (
          <button className="admin-btn admin-btn--success" disabled={busy} onClick={() => onModerate(review, 'approved')}>
            Publish
          </button>
        )}
        {review.status !== 'rejected' && (
          <button className="admin-btn admin-btn--danger-outline" disabled={busy} onClick={() => onModerate(review, 'rejected')}>
            Reject
          </button>
        )}
        {review.status === 'approved' && (
          <button className="admin-btn" disabled={busy} onClick={() => onModerate(review, 'hidden')}>
            Hide
          </button>
        )}
        {review.status === 'approved' && !review.merchant_reply && (
          <button className="admin-btn" disabled={busy} onClick={() => onReply(review)}>
            Reply publicly
          </button>
        )}
      </div>
    </div>
  );
}

export default function Reviews() {
  const [tab, setTab] = useState('product');
  const [filter, setFilter] = useState('');
  const [reviews, setReviews] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [busyUuid, setBusyUuid] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setActionError(null);

    try {
      if (tab === 'store') {
        const response = await api.get('/admin/store-reviews', { status: filter });
        setReviews(response.data || []);
      } else {
        const response = await api.get('/admin/reviews', { status: filter, per_page: 50 });
        setReviews(response.data || []);
      }
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [tab, filter]);

  useEffect(() => {
    load();
  }, [load]);

  function switchTab(value) {
    setTab(value);
    setFilter('');
  }

  async function moderateProduct(review, decision) {
    // A rejection is explained. The note is internal, but "why did we reject
    // this" is asked far more often than anyone expects.
    let note = null;
    if (decision !== 'approved') {
      note = window.prompt('Why? (internal note, optional)') || null;
    }

    setBusyUuid(review.uuid);
    setActionError(null);

    try {
      await api.post(`/admin/reviews/${encodeURIComponent(review.uuid)}/moderate`, { decision, note });
      toast(decision === 'approved' ? 'Review published.' : `Review ${decision}.`);
      await load();
    } catch (err) {
      setActionError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  async function replyProduct(review) {
    const body = window.prompt('Your reply. This is shown publicly under the review.');
    if (!body) return;

    setBusyUuid(review.uuid);
    setActionError(null);

    try {
      await api.post(`/admin/reviews/${encodeURIComponent(review.uuid)}/reply`, { body });
      toast('Reply published.');
      await load();
    } catch (err) {
      setActionError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  async function moderateStore(review, decision) {
    const note = decision === 'approved' ? null : (window.prompt('Why? (internal note, optional)') || null);

    setBusyUuid(review.uuid);
    setActionError(null);

    try {
      await api.post(`/admin/store-reviews/${encodeURIComponent(review.uuid)}/moderate`, { decision, note });
      toast(decision === 'approved' ? 'Review published.' : `Review ${decision}.`);
      await load();
    } catch (err) {
      setActionError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  async function replyStore(review) {
    const reply = window.prompt('Your reply. This is shown publicly under the review.');
    if (!reply) return;

    setBusyUuid(review.uuid);
    setActionError(null);

    try {
      await api.post(`/admin/store-reviews/${encodeURIComponent(review.uuid)}/moderate`, { decision: 'approved', reply });
      toast('Reply published.');
      await load();
    } catch (err) {
      setActionError(err);
    } finally {
      setBusyUuid(null);
    }
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Reviews</h1>

        <div className="review-tabs">
          <button
            type="button"
            className={`admin-btn ${tab === 'product' ? 'admin-btn--primary' : ''}`}
            onClick={() => switchTab('product')}
          >
            Product reviews
          </button>
          <button
            type="button"
            className={`admin-btn ${tab === 'store' ? 'admin-btn--primary' : ''}`}
            onClick={() => switchTab('store')}
          >
            Store reviews
          </button>
        </div>

        <div className="review-tabs">
          {FILTERS.map(([value, label]) => (
            <button
              key={value || 'pending'}
              type="button"
              className={`admin-btn ${filter === value ? 'admin-btn--primary' : ''}`}
              onClick={() => setFilter(value)}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {actionError && <div className="mb-3"><ErrorState error={actionError} /></div>}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} />
      ) : reviews.length === 0 ? (
        <EmptyState
          title={filter ? 'Nothing with that status' : 'Nothing waiting'}
          hint={
            tab === 'store'
              ? 'Ratings left on the shop home page appear here.'
              : filter
                ? 'Try another filter.'
                : 'No reviews are awaiting moderation or have been reported.'
          }
        />
      ) : tab === 'store' ? (
        reviews.map((review) => (
          <StoreReviewCard
            key={review.uuid}
            review={review}
            busy={busyUuid === review.uuid}
            onModerate={moderateStore}
            onReply={replyStore}
          />
        ))
      ) : (
        reviews.map((review) => (
          <ProductReviewCard
            key={review.uuid}
            review={review}
            busy={busyUuid === review.uuid}
            onModerate={moderateProduct}
            onReply={replyProduct}
          />
        ))
      )}
    </div>
  );
}
