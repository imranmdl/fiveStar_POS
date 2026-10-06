import { useEffect, useState } from 'react';

/**
 * An <img> that shows `fallback` instead of the browser's broken-image icon
 * when the file can't be loaded (deleted, or lost from server storage).
 */
export default function SafeImage({ src, fallback = null, ...props }) {
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    setFailed(false);
  }, [src]);

  if (!src || failed) return fallback;
  return <img src={src} onError={() => setFailed(true)} {...props} />;
}
