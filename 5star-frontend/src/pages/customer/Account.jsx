import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api, storeTokens, isSignedIn, mergeGuestCart } from '../../lib/api';
import { useAuth } from '../../hooks/useAuth';
import PhoneEmailButton, { loadVerificationMethods, usePhoneEmail } from '../../components/PhoneEmailButton';
import MyAccount from './MyAccount';
import { forgetReferralCode, rememberedReferralCode } from './ReferralLink';
import './Account.css';

export default function Account() {
  const { signedIn, ready } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  // Signed in with no ?next= → this page is "My account". Signing in from
  // here with no ?next= lands on My account too.
  const nextParam = searchParams.get('next');
  const next = nextParam && nextParam.startsWith('/') && !nextParam.startsWith('//') ? nextParam : '/account';

  // Arriving from a friend's share link (/r/CODE): open sign-up with the
  // code filled in.
  const initialReferral = (searchParams.get('ref') || rememberedReferralCode() || '').toUpperCase();
  const [tab, setTab] = useState(searchParams.get('tab') === 'register' ? 'register' : 'signin');
  const [step, setStep] = useState('form'); // 'form' | 'verify'
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const [signinData, setSigninData] = useState({ identifier: '', password: '' });
  const [registerData, setRegisterData] = useState({ full_name: '', mobile: '', email: '', password: '', referral_code: initialReferral });
  const [otp, setOtp] = useState('');
  const [debugOtp, setDebugOtp] = useState(null);
  const [otpNotSent, setOtpNotSent] = useState(false);
  const [otpFailed, setOtpFailed] = useState(false);
  const [resending, setResending] = useState(false);
  const [pendingMobile, setPendingMobile] = useState(null);
  const [pendingReference, setPendingReference] = useState(null);
  const phoneEmailClient = usePhoneEmail();
  const [otpEnabled, setOtpEnabled] = useState(true);

  useEffect(() => {
    loadVerificationMethods().then((methods) => {
      if (methods && methods.otp_enabled === false) setOtpEnabled(false);
    });
  }, []);

  // Signed in and sent here to sign in first: carry on to where they were going.
  useEffect(() => {
    if (ready && signedIn && nextParam && next !== '/account') {
      navigate(next, { replace: true });
    }
  }, [ready, signedIn, nextParam, next, navigate]);

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
    payload.referral_code = (payload.referral_code || '').trim().toUpperCase();
    if (!payload.referral_code) delete payload.referral_code;

    try {
      const response = await api.post('/auth/register', payload);

      // Mobile OTP switched off by the shop: the account is ready and signed in.
      if (response.data.tokens) {
        forgetReferralCode();
        storeTokens(response.data.tokens);
        await mergeGuestCart();
        navigate(next, { replace: true });
        return;
      }

      setPendingMobile(payload.mobile);
      setPendingReference(response.data.verification.reference_token);
      setDebugOtp(response.data.verification.debug_otp || null);
      setOtpNotSent(response.data.verification.delivery === 'not_sent' && !response.data.verification.debug_otp);
      setOtpFailed(response.data.verification.delivery === 'failed');
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
      forgetReferralCode();
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

  // phone.email verified a number: the server reads it from phone.email and
  // signs that number in, creating the account if it is new.
  async function handlePhoneVerified(userJsonUrl) {
    setBusy(true);
    setError(null);

    try {
      const referral = (registerData.referral_code || '').trim().toUpperCase();
      const response = await api.post('/auth/login/phone-email', {
        user_json_url: userJsonUrl,
        ...(referral ? { referral_code: referral } : {}),
      });
      if (response.data.new_account) forgetReferralCode();
      storeTokens(response.data.tokens);
      await mergeGuestCart();
      navigate(next, { replace: true });
    } catch (err) {
      setError(err);
      setBusy(false);
    }
  }

  const phoneButton = phoneEmailClient ? (
    <>
      <PhoneEmailButton clientId={phoneEmailClient} onVerified={handlePhoneVerified} disabled={busy} />
      <div className="pe-divider">or</div>
    </>
  ) : null;

  async function handleResend() {
    setResending(true);
    setError(null);
    try {
      const response = await api.post('/auth/otp/request', { mobile: pendingMobile, purpose: 'registration' });
      setPendingReference(response.data.reference_token);
      setDebugOtp(response.data.debug_otp || null);
      setOtpFailed(response.data.delivery === 'failed');
    } catch (err) {
      setError(err);
    } finally {
      setResending(false);
    }
  }

  function switchTab(newTab) {
    setTab(newTab);
    setStep('form');
    setError(null);
  }

  if (ready && signedIn && (!nextParam || next === '/account')) {
    return <MyAccount />;
  }

  if (!ready || signedIn) {
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

            {tab === 'register' && registerData.referral_code && (
              <div className="account-info">Referral code <b>{registerData.referral_code}</b> will be applied — you get wallet credit after your first order.</div>
            )}
            {phoneButton && (
              <p className="text-muted account-phone-hint">
                {tab === 'signin'
                  ? 'Verify your mobile number to sign in — no password needed. New here? This creates your account.'
                  : 'Quickest: verify your mobile number and your account is created — no password needed.'}
              </p>
            )}
            {phoneButton}

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
                  {otpEnabled && <div className="field-hint">We will send a verification code to this number.</div>}
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
                  <label htmlFor="referral_code">Referral code (optional)</label>
                  <input
                    id="referral_code"
                    autoCapitalize="characters"
                    autoComplete="off"
                    maxLength={40}
                    value={registerData.referral_code}
                    onChange={(e) => setRegisterData({ ...registerData, referral_code: e.target.value.toUpperCase() })}
                  />
                  <div className="field-hint">Got a code from a friend? You both get wallet credit after your first order.</div>
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
            {otpNotSent ? (
              <p className="text-muted">
                We couldn&apos;t text a code to {pendingMobile} — this shop hasn&apos;t switched on text messages yet.
                {phoneEmailClient ? ' Verify your number with the button below instead.' : ' Please contact the shop.'}
              </p>
            ) : otpFailed ? (
              <div className="account-error">We couldn&apos;t send the code to {pendingMobile} just now. Tap &quot;Resend code&quot; below to try again.</div>
            ) : (
              <p className="text-muted">We sent a code to {pendingMobile}.</p>
            )}
            {debugOtp && (
              <div className="account-info">
                Test mode: your code is <span className="fw-semibold">{debugOtp}</span>.
              </div>
            )}

            {error && (
              <div className="account-error">
                <div>{error.message}</div>
              </div>
            )}

            {phoneEmailClient && (
              <>
                <PhoneEmailButton clientId={phoneEmailClient} onVerified={handlePhoneVerified} disabled={busy} />
                {!otpNotSent && <div className="pe-divider">or enter the code</div>}
              </>
            )}

            {!(otpNotSent && phoneEmailClient) && (
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
              <button type="button" className="btn-link" onClick={handleResend} disabled={resending} style={{ marginTop: 10, background: 'none', border: 0, color: 'var(--sf-red, #c62d1f)', fontWeight: 700, cursor: 'pointer' }}>
                {resending ? 'Sending…' : 'Resend code'}
              </button>
            </form>
            )}
          </>
        )}
      </div>
    </div>
  );
}
