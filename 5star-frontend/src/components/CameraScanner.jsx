import { useEffect, useRef, useState } from 'react';
import { BrowserMultiFormatReader } from '@zxing/browser';
import './CameraScanner.css';

/**
 * A continuous scanner re-reads the same label many times a second while it
 * stays in frame. A code counts again only after it has been OUT of view for
 * this long — so holding the camera on one item adds it once, and moving
 * away and back adds another.
 */
const DUPLICATE_SUPPRESS_MS = 1500;

/**
 * Camera barcode scanning overlay, shared by the Till and Mobile Scan.
 * Wraps @zxing/browser's BrowserMultiFormatReader (getUserMedia + continuous
 * decoding against a <video> element), so it works in a phone's browser and
 * inside the Android app alike — the app declares the CAMERA permission and
 * Android asks the person the first time.
 *
 * Feeds decoded codes to the SAME onDetected(code) callback a USB/Bluetooth
 * scanner or typing uses, so a camera scan behaves exactly like any other.
 * Stays open for repeated scans until "Done" (or the caller closes it).
 */
export default function CameraScanner({
  onDetected,
  onClose,
  title = 'Scan with camera',
  hint = 'It adds to the cart automatically — keep scanning, or press Done.',
}) {
  const videoRef = useRef(null);
  const controlsRef = useRef(null);
  const lastRef = useRef({ code: null, at: 0 });
  const [error, setError] = useState(null);
  const [status, setStatus] = useState('starting'); // starting | running
  const [lastSeen, setLastSeen] = useState(null);

  // The parent (SellTab, via ScanInput) recreates its detect handler on
  // every re-render — e.g. right after a scan updates feedback state. Read
  // it through a ref so the effect below never needs it in its dependency
  // array, otherwise the stream would stop and restart after every single
  // scan instead of staying open for the next one.
  const onDetectedRef = useRef(onDetected);
  onDetectedRef.current = onDetected;

  useEffect(() => {
    let cancelled = false;

    async function start() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setError('This browser/device has no camera access available. Use a barcode scanner or type the code instead. (A phone browser needs the site on https.)');
        return;
      }

      try {
        const reader = new BrowserMultiFormatReader();

        const controls = await reader.decodeFromVideoDevice(
          undefined, // let zxing prefer the environment-facing (rear) camera
          videoRef.current,
          (result) => {
            if (!result) return;
            const code = result.getText();
            const now = Date.now();

            if (lastRef.current.code === code && now - lastRef.current.at < DUPLICATE_SUPPRESS_MS) {
              // Still in view: keep the window open, don't count it again.
              lastRef.current.at = now;
              return;
            }

            lastRef.current = { code, at: now };
            setLastSeen(code);
            onDetectedRef.current(code);
          },
        );

        if (cancelled) {
          controls.stop();
          return;
        }

        controlsRef.current = controls;
        setStatus('running');
      } catch (err) {
        if (cancelled) return;

        const name = err && err.name;
        const message = name === 'NotAllowedError'
          ? 'Camera permission was denied. Allow camera access (in the app: Android Settings → Apps → 5Star Spices → Permissions → Camera) and try again, or type the code instead.'
          : name === 'NotFoundError'
            ? 'No camera was found on this device. Use a barcode scanner or type the code instead.'
            : 'Could not start the camera. Close other apps using it, then try again — or type the code instead.';

        setError(message);
      }
    }

    start();

    return () => {
      cancelled = true;

      // Belt-and-suspenders: stop the zxing controls AND stop any tracks
      // still attached to the video element directly, so a camera that
      // somehow outlives the controls handle doesn't keep the light on.
      try { controlsRef.current?.stop(); } catch { /* already stopped */ }

      const video = videoRef.current;
      const stream = video && video.srcObject;
      if (stream && typeof stream.getTracks === 'function') {
        stream.getTracks().forEach((track) => track.stop());
      }
    };
    // Intentionally empty: the camera starts once per mount (per overlay
    // open) and stays running across parent re-renders — onDetected is
    // read through onDetectedRef above, not captured here.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="cam-overlay" role="dialog" aria-modal="true">
      <div className="cam-sheet">
        <div className="cam-sheet__header">
          <h2>{title}</h2>
          <button type="button" className="cam-close" aria-label="Close camera" onClick={onClose}>×</button>
        </div>

        {error ? (
          <div className="cam-error cam-sheet__error">{error}</div>
        ) : (
          <>
            <div className="cam-viewport">
              {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
              <video ref={videoRef} className="cam-video" muted playsInline />
              <div className="cam-reticle" />
              {status === 'starting' && <div className="cam-sheet__status">Starting camera…</div>}
            </div>
            <p className="cam-sheet__hint">
              Point the camera at a barcode. {lastSeen ? `Last scanned: ${lastSeen}` : hint}
            </p>
          </>
        )}

        <button type="button" className="cam-done" onClick={onClose}>Done</button>
      </div>
    </div>
  );
}
