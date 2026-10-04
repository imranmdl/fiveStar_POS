import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import './Loyalty.css';

const SOURCE_LABEL = {
  purchase_online: 'Order placed',
  purchase_pos: 'In-store purchase',
  review: 'Review approved',
  referral: 'Referral bonus',
  redemption: 'Redeemed to wallet',
  expiry: 'Expired',
  admin_adjustment: 'Adjustment',
};

function LedgerRow({ row }) {
  const isCredit = row.direction === 'credit';
  return (
    <div className="ledger-row">
      <div>
        <div className="ledger-row__source">{SOURCE_LABEL[row.source] || row.source}</div>
        <div className="ledger-row__narration">{row.narration}</div>
        <div className="ledger-row__date">{String(row.created_date || '').slice(0, 16).replace('T', ' ')}</div>
      </div>
      <div className={`ledger-row__points ${isCredit ? 'ledger-row__points--credit' : 'ledger-row__points--debit'}`}>
        {isCredit ? '+' : '−'}{row.points}
      </div>
    </div>
  );
}

export default function Loyalty() {
  const { signedIn, ready } = useAuth();
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);
  const [loyalty, setLoyalty] = useState(null);
  const [items, setItems] = useState([]);
  const [redeemPoints, setRedeemPoints] = useState('');
  const [redeeming, setRedeeming] = useState(false);
  const [toast, setToastMsg] = useState(null);

  const load = useCallback(async () => {
    setStatus('loading');
    try {
      const [summaryResponse, statementResponse] = await Promise.all([
        api.get('/loyalty'),
        api.get('/loyalty/statement', { page: 1, per_page: 20 }),
      ]);
      setLoyalty(summaryResponse.data.loyalty);
      setItems(statementResponse.data || []);
      setStatus('ready');
    } catch (err) {
      setError(err.message);
      setStatus('error');
    }
  }, []);

  useEffect(() => {
    if (!ready) return;
    if (!signedIn) {
      setStatus('signed-out');
      return;
    }
    load();
  }, [ready, signedIn, load]);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToastMsg(null), 4000);
    return () => clearTimeout(timer);
  }, [toast]);

  async function handleRedeem(event) {
    event.preventDefault();
    setRedeeming(true);
    try {
      const response = await api.post('/loyalty/redeem', { points: Number(redeemPoints) });
      setToastMsg({ text: `₹${response.data.rupees_credited} added to your wallet.`, variant: 'success' });
      setRedeemPoints('');
      await load();
    } catch (err) {
      setToastMsg({ text: err.message, variant: 'danger' });
    } finally {
      setRedeeming(false);
    }
  }

  if (!ready || status === 'loading') {
    return <div className="page"><p className="state-message">Loading your loyalty points…</p></div>;
  }

  if (status === 'signed-out') {
    return (
      <div className="page loyalty-empty">
        <h1 className="page-title">Sign in to see your loyalty points</h1>
        <Link className="btn-marigold" to="/account?next=/loyalty">Sign in</Link>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load loyalty points: {error}</p></div>;
  }

  const canRedeem = loyalty.balance >= loyalty.min_redeem_points;

  return (
    <div className="page loyalty-page">
      <h1 className="page-title">Loyalty Points</h1>

      <div className="loyalty-balance-card">
        <div className="text-muted small">Your balance</div>
        <div className="loyalty-balance-card__value">{loyalty.balance}</div>
        <div className="text-muted small">points</div>
        <div className="text-muted small loyalty-balance-card__lifetime">
          Lifetime earned {loyalty.lifetime_earned} · Lifetime redeemed {loyalty.lifetime_redeemed}
        </div>
      </div>

      {loyalty.is_frozen ? (
        <div className="alert alert-warning">Your loyalty account is temporarily on hold. Please contact support.</div>
      ) : (
        <div className="checkout-panel">
          <h2>Redeem for wallet credit</h2>
          <p className="text-muted small">
            Each point is worth ₹{loyalty.redeem_value_per_point} of wallet credit. Redeem at least{' '}
            {loyalty.min_redeem_points}
            {loyalty.max_redeem_points_per_order > 0 ? ` and up to ${loyalty.max_redeem_points_per_order}` : ''} points
            at a time.
          </p>
          <form className="redeem-form" onSubmit={handleRedeem}>
            <input
              type="number"
              min={loyalty.min_redeem_points}
              max={loyalty.max_redeem_points_per_order > 0 ? loyalty.max_redeem_points_per_order : loyalty.balance}
              placeholder="Points to redeem"
              required
              disabled={!canRedeem}
              value={redeemPoints}
              onChange={(e) => setRedeemPoints(e.target.value)}
            />
            <button type="submit" className="btn-marigold" disabled={!canRedeem || redeeming}>
              {redeeming ? 'Redeeming…' : 'Redeem'}
            </button>
          </form>
          {!canRedeem && (
            <div className="text-muted small">You need at least {loyalty.min_redeem_points} points to redeem.</div>
          )}
        </div>
      )}

      <div className="checkout-panel">
        <h2>History</h2>
        {items.length === 0 ? (
          <p className="text-muted small">No activity yet — points appear here as you shop.</p>
        ) : (
          items.map((row, index) => <LedgerRow key={index} row={row} />)
        )}
      </div>

      {toast && <div className={`toast-pop toast-pop--${toast.variant}`}>{toast.text}</div>}
    </div>
  );
}
