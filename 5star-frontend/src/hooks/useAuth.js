import { useEffect, useState } from 'react';
import { isSignedIn, onAuthChange, bootstrapSession, mergeGuestCart } from '../lib/api';

/**
 * Wraps the plain pub-sub auth state in lib/api.js (ported from the live
 * assets/js/api.js) in a React hook. Restores the session and merges any
 * guest cart before reporting ready, the same ordering ui.js's mountChrome
 * uses — querying the cart before the session restores shows the wrong cart.
 */
export function useAuth() {
  const [signedIn, setSignedIn] = useState(isSignedIn());
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let mounted = true;

    bootstrapSession().then(async () => {
      if (isSignedIn()) await mergeGuestCart();
      if (!mounted) return;
      setSignedIn(isSignedIn());
      setReady(true);
    });

    onAuthChange((value) => {
      if (mounted) setSignedIn(value);
    });

    return () => {
      mounted = false;
    };
  }, []);

  return { signedIn, ready };
}
