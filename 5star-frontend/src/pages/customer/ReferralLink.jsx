import { useEffect } from 'react';
import { Navigate, useParams } from 'react-router-dom';

export const REFERRAL_STORAGE_KEY = 'spice.referral_code';

/** The code a visitor arrived with from a friend's share link, if any. */
export function rememberedReferralCode() {
  try {
    return localStorage.getItem(REFERRAL_STORAGE_KEY) || '';
  } catch {
    return '';
  }
}

export function forgetReferralCode() {
  try {
    localStorage.removeItem(REFERRAL_STORAGE_KEY);
  } catch {
    // ignore
  }
}

/**
 * /r/CODE — a friend's share link. Remembers the code (so it is applied even
 * if the visitor shops first and signs up later) and opens sign-up.
 */
export default function ReferralLink() {
  const { code } = useParams();
  const clean = String(code || '').trim().toUpperCase().slice(0, 40);

  useEffect(() => {
    if (!clean) return;
    try {
      localStorage.setItem(REFERRAL_STORAGE_KEY, clean);
    } catch {
      // ignore
    }
  }, [clean]);

  return <Navigate to={`/account?tab=register&ref=${encodeURIComponent(clean)}`} replace />;
}
