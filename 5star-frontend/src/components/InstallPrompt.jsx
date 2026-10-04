import { useEffect, useState } from 'react';
import './InstallPrompt.css';

const DISMISSED_KEY = 'spice.install_prompt_dismissed';

/**
 * A custom "Install app" banner. Browsers suppress their own install UI once
 * you listen for beforeinstallprompt, so showing nothing here would mean no
 * install path at all on most desktop browsers.
 */
export default function InstallPrompt() {
  const [deferredEvent, setDeferredEvent] = useState(null);
  const [dismissed, setDismissed] = useState(() => {
    try {
      return localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
      return false;
    }
  });

  useEffect(() => {
    function onBeforeInstallPrompt(event) {
      event.preventDefault();
      setDeferredEvent(event);
    }

    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
    return () => window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
  }, []);

  if (!deferredEvent || dismissed) return null;

  function dismiss() {
    setDismissed(true);
    try {
      localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
      // Private browsing — fine to just not persist the dismissal.
    }
  }

  async function install() {
    deferredEvent.prompt();
    await deferredEvent.userChoice;
    setDeferredEvent(null);
  }

  return (
    <div className="install-prompt">
      <span>Install 5Star Spices for quicker access — works offline too.</span>
      <div className="install-prompt__actions">
        <button type="button" className="install-prompt__install" onClick={install}>
          Install
        </button>
        <button type="button" className="install-prompt__dismiss" onClick={dismiss} aria-label="Dismiss">
          &times;
        </button>
      </div>
    </div>
  );
}
