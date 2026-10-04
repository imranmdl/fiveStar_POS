import { useEffect, useRef, useState } from 'react';
import { BrowserMultiFormatReader } from '@zxing/browser';

/** Ignore a second decode of the same code within this window — a continuous scanner re-reads the same label many times a second while it's still in frame. */
const DUPLICATE_SUPPRESS_MS = 1500;

/**
 * Camera barcode scanning overlay — the one place in this migration where
 * camera scanning is the real thing, not skipped (contrast admin/MobileScan,
 * which dropped the camera path for dev-environment reasons and only ever
 * had manual/HID entry). Wraps @zxing/browser's BrowserMultiFormatReader,
 * which itself wraps getUserMedia + continuous decoding against a <video>
 * element.
 *
 * Feeds decoded codes to the SAME onDetected(code) callback the HID-scanner
 * path uses, so a scan from the camera adds to the cart exactly like a scan
 * from a physical scanner. Stays open for repeated scans (ringing up
 * several items without reopening the camera each time) until the cashier
 * closes it with "Done".
 */
export default function CameraScanner({ onDetected, onClose }) {
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
        setError('This browser/device has no camera access available. Use the scanner or type the SKU instead.');
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
          ? 'Camera permission was denied. Allow camera access and try again, or use the scanner/manual entry instead.'
          : name === 'NotFoundError'
            ? 'No camera was found on this device. Use the scanner or type the SKU instead.'
            : 'Could not start the camera. Use the scanner or type the SKU instead.';

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
    <div className="till-overlay" role="dialog" aria-modal="true">
      <div className="till-camera-sheet">
        <div className="till-camera-sheet__header">
          <h2>Scan with camera</h2>
          <button type="button" className="till-icon-btn" aria-label="Close camera" onClick={onClose}>×</button>
        </div>

        {error ? (
          <div className="till-alert till-alert--danger till-camera-sheet__error">{error}</div>
        ) : (
          <>
            <div className="till-camera-viewport">
              {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
              <video ref={videoRef} className="till-camera-video" muted playsInline />
              <div className="till-camera-reticle" />
              {status === 'starting' && <div className="till-camera-sheet__status">Starting camera…</div>}
            </div>
            <p className="till-camera-sheet__hint">
              Point the camera at a barcode. {lastSeen ? `Last scanned: ${lastSeen}` : 'It adds to the cart automatically — keep scanning, or press Done.'}
            </p>
          </>
        )}

        <button type="button" className="till-btn till-btn--block" onClick={onClose}>Done</button>
      </div>
    </div>
  );
}
