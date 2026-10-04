/** Sign in, register, and OTP verification. */

import { api, storeTokens, bootstrapSession, isSignedIn, mergeGuestCart } from './api.js';
import { mountChrome, mountFooter, escapeHtml, showError, toast, setBusy, queryParam } from './ui.js';

const root = document.querySelector('[data-page-root]');
const next = queryParam('next') || 'index.html';
let pendingMobile = null;
let pendingReference = null;

function tabs(active) {
  return `
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link ${active === 'signin' ? 'active' : ''}" href="#" data-tab="signin">Sign in</a></li>
      <li class="nav-item"><a class="nav-link ${active === 'register' ? 'active' : ''}" href="#" data-tab="register">Create account</a></li>
    </ul>`;
}

function wrap(inner) {
  return `<div class="row justify-content-center"><div class="col-12 col-md-7 col-lg-5">
    <div class="card"><div class="card-body">${inner}</div></div></div></div>`;
}

function renderSignIn() {
  root.innerHTML = wrap(`
    ${tabs('signin')}
    <form data-signin-form>
      <div class="mb-3">
        <label class="form-label" for="identifier">Mobile number or email</label>
        <input class="form-control" id="identifier" name="identifier" required autocomplete="username">
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-spice w-100" type="submit">Sign in</button>
    </form>`);

  bindTabs();

  root.querySelector('[data-signin-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button');
    setBusy(button, true, 'Signing in');

    try {
      const response = await api.post('/auth/login',
        Object.fromEntries(new FormData(event.currentTarget).entries()));
      storeTokens(response.data.tokens);
      // Before navigating: anything added anonymously must follow them in.
      await mergeGuestCart();
      window.location.href = next;
    } catch (error) {
      showError(error);
      setBusy(button, false);
    }
  });
}

function renderRegister() {
  root.innerHTML = wrap(`
    ${tabs('register')}
    <form data-register-form>
      <div class="mb-3">
        <label class="form-label" for="full_name">Your name</label>
        <input class="form-control" id="full_name" name="full_name" required autocomplete="name">
      </div>
      <div class="mb-3">
        <label class="form-label" for="mobile">Mobile number</label>
        <input class="form-control" id="mobile" name="mobile" required inputmode="numeric" autocomplete="tel">
        <div class="form-text">We will send a verification code to this number.</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="email">Email (optional)</label>
        <input class="form-control" id="email" name="email" type="email" autocomplete="email">
      </div>
      <div class="mb-3">
        <label class="form-label" for="new-password">Password</label>
        <input class="form-control" id="new-password" name="password" type="password" required autocomplete="new-password">
      </div>
      <button class="btn btn-spice w-100" type="submit">Create account</button>
    </form>`);

  bindTabs();

  root.querySelector('[data-register-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button');
    setBusy(button, true, 'Creating');

    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (!payload.email) delete payload.email;

    try {
      const response = await api.post('/auth/register', payload);
      pendingMobile = payload.mobile;
      pendingReference = response.data.verification.reference_token;
      renderVerify(response.data.verification.debug_otp);
    } catch (error) {
      showError(error);
      setBusy(button, false);
    }
  });
}

function renderVerify(debugOtp) {
  root.innerHTML = wrap(`
    <h1 class="h5">Verify your number</h1>
    <p class="text-muted small">We sent a code to ${escapeHtml(pendingMobile)}.</p>
    ${debugOtp ? `<div class="alert alert-info small">Development mode: your code is
      <span class="fw-semibold">${escapeHtml(debugOtp)}</span>.</div>` : ''}
    <form data-verify-form>
      <label class="form-label" for="otp">Verification code</label>
      <input class="form-control form-control-lg text-center" id="otp" name="otp"
             inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
      <button class="btn btn-spice w-100 mt-3" type="submit">Verify</button>
    </form>`);

  root.querySelector('[data-verify-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('button');
    setBusy(button, true, 'Verifying');

    try {
      const response = await api.post('/auth/register/verify', {
        mobile: pendingMobile,
        otp: new FormData(event.currentTarget).get('otp'),
        reference_token: pendingReference,
      });
      storeTokens(response.data.tokens);
      await mergeGuestCart();
      toast('Welcome aboard.');
      window.location.href = next;
    } catch (error) {
      showError(error);
      setBusy(button, false);
    }
  });
}

function bindTabs() {
  root.querySelectorAll('[data-tab]').forEach((tab) => {
    tab.addEventListener('click', (event) => {
      event.preventDefault();
      if (tab.dataset.tab === 'signin') renderSignIn(); else renderRegister();
    });
  });
}

async function start() {
  await bootstrapSession();

  if (isSignedIn()) {
    root.innerHTML = wrap(`
      <h1 class="h5">You are signed in</h1>
      <p class="text-muted small">Nothing to do here.</p>
      <a class="btn btn-spice" href="${escapeHtml(next)}">Continue</a>`);
    return;
  }

  renderSignIn();
}

mountChrome('account.html');
mountFooter();
start();
