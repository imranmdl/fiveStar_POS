import { useState } from 'react';
import { api, storeTokens, clearTokens, ApiError } from '../../lib/api';

/** Only these roles may open the till — same list requireTillSignIn() checks server-side identity against. */
const ALLOWED_ROLES = ['administrator', 'supervisor', 'manager', 'cashier'];

/**
 * The till's own sign-in screen — deliberately NOT the admin console's.
 * Ported from requireTillSignIn() in admin/assets/page-till.js: whoever
 * wants to open the till, admin included, must type a mobile/email AND
 * password of their own, every time this tab hasn't already done so. An
 * admin console session sitting open in another tab of the same browser
 * grants nothing here — a fresh /auth/login is always required.
 *
 * lib/api.js's IS_TILL already points token storage at separate
 * sessionStorage/localStorage keys as long as this is mounted at /till, so
 * storeTokens()/clearTokens() here never touch an admin console session.
 */
export default function TillSignIn({ onSignedIn }) {
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const login = await api.post('/auth/login', { identifier, password });
      storeTokens(login.data.tokens);

      const me = await api.get('/auth/me');
      const user = me.data.user;

      if (!ALLOWED_ROLES.includes(String(user.role))) {
        clearTokens();
        setBusy(false);
        setError('That account cannot open the till.');
        return;
      }

      try {
        sessionStorage.setItem('till_session_user', JSON.stringify(user));
      } catch {
        // Per-tab convenience only — sign-in still succeeds without it, it
        // just re-prompts on the next reload of this tab.
      }

      onSignedIn(user);
    } catch (err) {
      setBusy(false);
      setError(err instanceof ApiError ? err.message : 'Sign-in failed.');
    }
  }

  return (
    <div className="till-signin">
      <div className="till-signin__card">
        <h1 className="till-signin__title">Till sign-in</h1>
        <p className="till-signin__hint">
          Separate from the admin console — sign in with your own staff login, whoever you are, to open the till.
        </p>

        {error && <div className="till-alert till-alert--danger">{error}</div>}

        <form onSubmit={handleSubmit}>
          <label className="till-field">
            <span>Mobile or email</span>
            <input
              name="identifier"
              autoComplete="username"
              required
              autoFocus
              value={identifier}
              onChange={(event) => setIdentifier(event.target.value)}
            />
          </label>

          <label className="till-field">
            <span>Password</span>
            <input
              name="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </label>

          <button type="submit" className="till-btn till-btn--primary till-btn--block" disabled={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  );
}

export { ALLOWED_ROLES };
