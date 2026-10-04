import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api, storeTokens, isSignedIn, mergeGuestCart } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import './Account.css';

export default function Account() {
  const { signedIn, ready } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const next = searchParams.get('next') || '/';

  const [tab, setTab] = useState('signin');
  const [step, setStep] = useState('form'); // 'form' | 'verify'
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const [signinData, setSigninData] = useState({ identifier: '', password: '' });
  const [registerData, setRegisterData] = useState({ full_name: '', mobile: '', email: '', password: '' });
  const [otp, setOtp] = useState('');
  const [debugOtp, setDebugOtp] = useState(null);
  const [pendingMobile, setPendingMobile] = useState(null);
  const [pendingReference, setPendingReference] = useState(null);

  // Already signed in: there is nothing to do here, so move on.
  useEffect(() => {
    if (ready && signedIn) {
      navigate(next, { replace: true });
    }
  }, [ready, signedIn, next, navigate]);

  async function handleSignIn(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const response = await api.post('/auth/login', signinData);
      storeTokens(response.data.tokens);
      await mergeGuestCart();
      navigate(next, { replace: true });
    } catch (err) {
      setError(err);
      setBusy(false);
    }
  }

  async function handleRegister(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    const payload = { ...registerData };
    if (!payload.email) delete payload.email;

    try {
      const response = await api.post('/auth/register', payload);
      setPendingMobile(payload.mobile);
      setPendingReference(response.data.verification.reference_token);
      setDebugOtp(response.data.verification.debug_otp || null);
      setStep('verify');
      setBusy(false);
    } catch (err) {
      setError(err);
      setBusy(false);
    }
  }

  async function handleVerify(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const response = await api.post('/auth/register/verify', {
        mobile: pendingMobile,
        otp,
        reference_token: pendingReference,
      });
      storeTokens(response.data.tokens);
      await mergeGuestCart();
      navigate(next, { replace: true });
    } catch (err) {
      setError(err);
      setBusy(false);
    }
  }

  function switchTab(newTab) {
    setTab(newTab);
    setStep('form');
    setError(null);
  }

  if (!ready || (ready && signedIn)) {
    return <div className="page"><p className="state-message">Loading…</p></div>;
  }

  return (
    <div className="page account-page">
      <div className="account-card">
        {step === 'form' && (
          <>
            <ul className="account-tabs">
              <li>
                <button
                  type="button"
                  className={tab === 'signin' ? 'active' : ''}
                  onClick={() => switchTab('signin')}
                >
                  Sign in
                </button>
              </li>
              <li>
                <button
                  type="button"
                  className={tab === 'register' ? 'active' : ''}
                  onClick={() => switchTab('register')}
                >
                  Create account
                </button>
              </li>
            </ul>

            {error && (
              <div className="account-error">
                <div>{error.message}</div>
                {error.fieldMessages && error.fieldMessages().length > 0 && (
                  <ul>
                    {error.fieldMessages().map((m) => <li key={m}>{m}</li>)}
                  </ul>
                )}
              </div>
            )}

            {tab === 'signin' ? (
              <form onSubmit={handleSignIn}>
                <div className="field">
                  <label htmlFor="identifier">Mobile number or email</label>
                  <input
                    id="identifier"
                    required
                    autoComplete="username"
                    value={signinData.identifier}
                    onChange={(e) => setSigninData({ ...signinData, identifier: e.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor="password">Password</label>
                  <input
                    id="password"
                    type="password"
                    required
                    autoComplete="current-password"
                    value={signinData.password}
                    onChange={(e) => setSigninData({ ...signinData, password: e.target.value })}
                  />
                </div>
                <button type="submit" className="btn-marigold btn-block" disabled={busy}>
                  {busy ? 'Signing in…' : 'Sign in'}
                </button>
              </form>
            ) : (
              <form onSubmit={handleRegister}>
                <div className="field">
                  <label htmlFor="full_name">Your name</label>
                  <input
                    id="full_name"
                    required
                    autoComplete="name"
                    value={registerData.full_name}
                    onChange={(e) => setRegisterData({ ...registerData, full_name: e.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor="mobile">Mobile number</label>
                  <input
                    id="mobile"
                    required
                    inputMode="numeric"
                    autoComplete="tel"
                    value={registerData.mobile}
                    onChange={(e) => setRegisterData({ ...registerData, mobile: e.target.value })}
                  />
                  <div className="field-hint">We will send a verification code to this number.</div>
                </div>
                <div className="field">
                  <label htmlFor="email">Email (optional)</label>
                  <input
                    id="email"
                    type="email"
                    autoComplete="email"
                    value={registerData.email}
                    onChange={(e) => setRegisterData({ ...registerData, email: e.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor="new-password">Password</label>
                  <input
                    id="new-password"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={registerData.password}
                    onChange={(e) => setRegisterData({ ...registerData, password: e.target.value })}
                  />
                </div>
                <button type="submit" className="btn-marigold btn-block" disabled={busy}>
                  {busy ? 'Creating…' : 'Create account'}
                </button>
              </form>
            )}
          </>
        )}

        {step === 'verify' && (
          <>
            <h1 className="account-card__title">Verify your number</h1>
            <p className="text-muted">We sent a code to {pendingMobile}.</p>
            {debugOtp && (
              <div className="account-info">
                Development mode: your code is <span className="fw-semibold">{debugOtp}</span>.
              </div>
            )}

            {error && (
              <div className="account-error">
                <div>{error.message}</div>
              </div>
            )}

            <form onSubmit={handleVerify}>
              <label htmlFor="otp">Verification code</label>
              <input
                id="otp"
                className="otp-input"
                inputMode="numeric"
                maxLength={6}
                autoComplete="one-time-code"
                required
                value={otp}
                onChange={(e) => setOtp(e.target.value)}
              />
              <button type="submit" className="btn-marigold btn-block" disabled={busy}>
                {busy ? 'Verifying…' : 'Verify'}
              </button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
