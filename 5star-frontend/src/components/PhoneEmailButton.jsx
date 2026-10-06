import { useEffect, useRef, useState } from 'react';
import { Capacitor } from '@capacitor/core';
import { api } from '../lib/api';
import './PhoneEmailButton.css';

const SCRIPT_SRC = 'https://www.phone.email/sign_in_button_v1.js';

let methodsPromise = null;

/**
 * The shop's verification methods from GET /auth/methods, fetched once per
 * page load. Resolves to null when the API can't be reached.
 */
export function loadVerificationMethods() {
  if (!methodsPromise) {
    methodsPromise = api.get('/auth/methods').then((r) => r.data).catch(() => {
      methodsPromise = null;
      return null;
    });
  }
  return methodsPromise;
}

/**
 * phone.email opens its check in a pop-up window and reports back to the
 * page that opened it. Inside the Android/iOS app there is no pop-up window
 * (the link opens in the phone's browser and never reports back), so the app
 * keeps using the SMS code.
 */
function popupsWork() {
  return !Capacitor.isNativePlatform();
}

/** Whether the phone.email button should be offered on this device. */
export function usePhoneEmail() {
  const [clientId, setClientId] = useState(null);

  useEffect(() => {
    let live = true;
    if (!popupsWork()) return undefined;
    loadVerificationMethods().then((methods) => {
      if (live && methods?.phone_email?.enabled && methods.phone_email.client_id) {
        setClientId(methods.phone_email.client_id);
      }
    });
    return () => {
      live = false;
    };
  }, []);

  return clientId;
}

/**
 * phone.email "Sign in with Phone" button.
 *
 * The customer verifies their number with phone.email; we get back only a
 * user_json_url, which `onVerified` sends to our API. The server reads the
 * verified number from phone.email itself — nothing the browser says about
 * the number is trusted.
 *
 * phone.email's script draws the button into the first `.pe_signin_button`
 * on the page when it loads, so only one of these may be on screen at a time,
 * and the script is loaded again every time the button mounts (the app
 * changes pages without reloading).
 */
export default function PhoneEmailButton({ clientId, onVerified, disabled = false }) {
  const holder = useRef(null);
  const callback = useRef(onVerified);
  callback.current = onVerified;

  useEffect(() => {
    if (!clientId || !holder.current) return undefined;

    // Each load of the script adds its own message handler, so one
    // verification can be reported more than once; act on it once.
    let lastUrl = null;
    window.phoneEmailListener = (userObj) => {
      const url = userObj && userObj.user_json_url;
      if (!url || url === lastUrl) return;
      lastUrl = url;
      callback.current(url);
    };

    document.querySelectorAll(`script[src="${SCRIPT_SRC}"]`).forEach((node) => node.remove());
    const script = document.createElement('script');
    // A newly inserted script tag always runs, even from the browser cache,
    // and running is what draws the button into this new holder.
    script.src = SCRIPT_SRC;
    script.async = true;
    document.body.appendChild(script);

    const node = holder.current;
    return () => {
      script.remove();
      if (window.phoneEmailListener) delete window.phoneEmailListener;
      node.replaceChildren();
    };
  }, [clientId]);

  if (!clientId) return null;

  return (
    <div className={`pe-holder${disabled ? ' is-busy' : ''}`}>
      <div ref={holder} className="pe_signin_button" data-client-id={clientId} />
    </div>
  );
}
