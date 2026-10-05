import { useEffect, useRef, useState } from 'react';
import { Capacitor } from '@capacitor/core';
import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';
import './CameraScanner.css';

/**
 * A code counts again only after it has been OUT of view for this long — so
 * holding the camera on one item adds it once, and moving away and back
 * adds another.
 */
const DUPLICATE_SUPPRESS_MS = 1500;
const DECODE_EVERY_MS = 120;

/** Product barcodes, plus QR. Fewer formats = faster, fewer misreads. */
const ZXING_FORMATS = [
  BarcodeFormat.EAN_13, BarcodeFormat.EAN_8, BarcodeFormat.UPC_A, BarcodeFormat.UPC_E,
  BarcodeFormat.CODE_128, BarcodeFormat.CODE_39, BarcodeFormat.ITF, BarcodeFormat.QR_CODE,
];
const DETECTOR_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];
const NATIVE_FORMATS = ['EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'CODE_128', 'CODE_39', 'ITF', 'QR_CODE'];

/**
 * The guide box, as a share of the on-screen viewport (keep in step with
 * .cam-reticle in CameraScanner.css). Only what's inside it is decoded, at
 * the camera's full resolution: a small label then fills far more of the
 * image the decoder sees than it would in the whole frame.
 */
const BOX = { x: 0.06, y: 0.28, w: 0.88, h: 0.44 };

/**
 * Maps the on-screen box to camera-frame pixels. The video is shown with
 * object-fit: cover, so part of the frame is off-screen; work out which part
 * is visible, then take the box within it.
 */
function boxInFrame(vw, vh, viewW, viewH) {
  const viewAspect = viewW / viewH;
  let x0 = 0;
  let y0 = 0;
  let visW = vw;
  let visH = vh;
  if (vw / vh > viewAspect) {
    visW = vh * viewAspect;
    x0 = (vw - visW) / 2;
  } else {
    visH = vw / viewAspect;
    y0 = (vh - visH) / 2;
  }
  return {
    sx: Math.round(x0 + visW * BOX.x),
    sy: Math.round(y0 + visH * BOX.y),
    sw: Math.round(visW * BOX.w),
    sh: Math.round(visH * BOX.h),
  };
}

function isNativeApp() {
  return Capacitor.isNativePlatform() && Capacitor.isPluginAvailable('BarcodeScanner');
}

/**
 * Camera barcode scanning, shared by the Till and Mobile Scan. Feeds decoded
 * codes to the SAME onDetected(code) callback a USB/Bluetooth scanner or
 * typing uses.
 *
 * Two engines:
 *  - In the Android/iOS app: Google ML Kit's scanner (native screen, with
 *    auto-zoom), which reads small printed barcodes far better than any
 *    in-browser decoder. Reopens after each scan while `continuous`.
 *  - In a browser: the camera at full HD with continuous focus and 2× zoom
 *    where the phone allows it (so a small label can be read from a
 *    distance the lens can still focus at), decoding only the guide box at
 *    full resolution — with the browser's own BarcodeDetector when it has
 *    one (Chrome on Android), otherwise ZXing.
 */
export default function CameraScanner({
  onDetected,
  onClose,
  title = 'Scan with camera',
  hint = 'It adds to the cart automatically — keep scanning, or press Done.',
  continuous = true,
}) {
  const onDetectedRef = useRef(onDetected);
  onDetectedRef.current = onDetected;
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  return isNativeApp()
    ? <NativeScanner title={title} continuous={continuous} onDetectedRef={onDetectedRef} onCloseRef={onCloseRef} />
    : <WebScanner title={title} hint={hint} onDetectedRef={onDetectedRef} onClose={onClose} />;
}

// ---------------------------------------------------------------------------
// App: Google ML Kit
// ---------------------------------------------------------------------------

function NativeScanner({ title, continuous, onDetectedRef, onCloseRef }) {
  const [status, setStatus] = useState('Opening the scanner…');
  const [lastSeen, setLastSeen] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const { BarcodeScanner } = await import('@capacitor-mlkit/barcode-scanning');

      try {
        // Google's scanner is a Play Services module, downloaded once.
        const { available } = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable();
        if (!available) {
          setStatus('Downloading the barcode scanner (one time only)…');
          await BarcodeScanner.installGoogleBarcodeScannerModule();
          for (let i = 0; i < 60 && !cancelled; i += 1) {
            // eslint-disable-next-line no-await-in-loop
            const check = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable();
            if (check.available) break;
            // eslint-disable-next-line no-await-in-loop
            await new Promise((resolve) => setTimeout(resolve, 1000));
          }
        }
      } catch {
        // Fall through: scan() reports a clear error if it truly can't run.
      }

      while (!cancelled) {
        setStatus('Scanner open — point it at the barcode.');
        try {
          // eslint-disable-next-line no-await-in-loop
          const { barcodes } = await BarcodeScanner.scan({ formats: NATIVE_FORMATS, autoZoom: true });
          if (cancelled) return;
          const code = barcodes && barcodes[0] && (barcodes[0].rawValue || barcodes[0].displayValue);
          if (code) {
            setLastSeen(code);
            // eslint-disable-next-line no-await-in-loop
            await onDetectedRef.current(code);
          }
          if (!continuous) {
            onCloseRef.current();
            return;
          }
        } catch (err) {
          if (cancelled) return;
          const message = String((err && err.message) || '');
          if (/cancel/i.test(message)) {
            onCloseRef.current();
          } else {
            setError(`The scanner could not start: ${message || 'unknown error'}. Type the code instead.`);
          }
          return;
        }
      }
    }

    run();
    return () => { cancelled = true; };
  }, [continuous, onDetectedRef, onCloseRef]);

  return (
    <div className="cam-overlay" role="dialog" aria-modal="true">
      <div className="cam-sheet">
        <div className="cam-sheet__header">
          <h2>{title}</h2>
          <button type="button" className="cam-close" aria-label="Close camera" onClick={() => onCloseRef.current()}>×</button>
        </div>
        {error ? <div className="cam-error">{error}</div> : <p className="cam-sheet__hint">{status}</p>}
        {lastSeen && <p className="cam-sheet__hint">Last scanned: <b>{lastSeen}</b></p>}
        <button type="button" className="cam-done" onClick={() => onCloseRef.current()}>Done</button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Browser: getUserMedia + BarcodeDetector / ZXing
// ---------------------------------------------------------------------------

async function makeDecoder() {
  if (typeof window !== 'undefined' && 'BarcodeDetector' in window) {
    try {
      const supported = await window.BarcodeDetector.getSupportedFormats();
      const formats = DETECTOR_FORMATS.filter((f) => supported.includes(f));
      if (formats.includes('ean_13')) {
        const detector = new window.BarcodeDetector({ formats });
        return async (canvas) => {
          const found = await detector.detect(canvas);
          return found.length > 0 ? found[0].rawValue : null;
        };
      }
    } catch {
      // Fall back to ZXing below.
    }
  }

  const hints = new Map();
  hints.set(DecodeHintType.POSSIBLE_FORMATS, ZXING_FORMATS);
  hints.set(DecodeHintType.TRY_HARDER, true);
  const reader = new BrowserMultiFormatReader(hints);
  return async (canvas) => {
    try {
      return reader.decodeFromCanvas(canvas).getText();
    } catch {
      return null; // nothing readable in this frame
    }
  };
}

function WebScanner({ title, hint, onDetectedRef, onClose }) {
  const videoRef = useRef(null);
  const trackRef = useRef(null);
  const lastRef = useRef({ code: null, at: 0 });
  const [error, setError] = useState(null);
  const [status, setStatus] = useState('starting'); // starting | running
  const [lastSeen, setLastSeen] = useState(null);
  const [zoom, setZoom] = useState(null); // { min, max, step, value } when the camera supports it
  const [torch, setTorch] = useState(null); // null = unsupported, else on/off

  useEffect(() => {
    let cancelled = false;
    let timer = null;
    let stream = null;
    const video = videoRef.current;
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    async function start() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setError('This browser/device has no camera access available. Use a barcode scanner or type the code instead. (A phone browser needs the site on https.)');
        return;
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1920 },
            height: { ideal: 1080 },
          },
        });
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop());
          return;
        }

        const track = stream.getVideoTracks()[0];
        trackRef.current = track;
        const caps = typeof track.getCapabilities === 'function' ? track.getCapabilities() : {};
        const advanced = {};
        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) advanced.focusMode = 'continuous';
        if (caps.zoom && caps.zoom.max > 1) {
          // 2× (or the most the camera has) lets a small label be read from a
          // distance the lens can still focus at.
          const value = Math.min(2, caps.zoom.max);
          advanced.zoom = value;
          setZoom({ min: caps.zoom.min || 1, max: caps.zoom.max, step: caps.zoom.step || 0.1, value });
        }
        if (caps.torch) setTorch(false);
        if (Object.keys(advanced).length > 0) {
          try { await track.applyConstraints({ advanced: [advanced] }); } catch { /* not fatal */ }
        }

        video.srcObject = stream;
        await video.play();
        const decode = await makeDecoder();
        if (cancelled) return;
        setStatus('running');

        const tick = async () => {
          if (cancelled) return;
          const vw = video.videoWidth;
          const vh = video.videoHeight;
          if (vw && vh && video.clientWidth && video.clientHeight) {
            const { sx, sy, sw, sh } = boxInFrame(vw, vh, video.clientWidth, video.clientHeight);
            canvas.width = sw;
            canvas.height = sh;
            context.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);

            const code = await decode(canvas);
            if (code && !cancelled) {
              const now = Date.now();
              if (lastRef.current.code === code && now - lastRef.current.at < DUPLICATE_SUPPRESS_MS) {
                lastRef.current.at = now; // still in view — don't count it again
              } else {
                lastRef.current = { code, at: now };
                setLastSeen(code);
                if (navigator.vibrate) navigator.vibrate(60);
                onDetectedRef.current(code);
              }
            }
          }
          if (!cancelled) timer = setTimeout(tick, DECODE_EVERY_MS);
        };
        tick();
      } catch (err) {
        if (cancelled) return;
        const name = err && err.name;
        setError(name === 'NotAllowedError'
          ? 'Camera permission was denied. Allow camera access (in the app: Android Settings → Apps → 5Star Spices → Permissions → Camera) and try again, or type the code instead.'
          : name === 'NotFoundError'
            ? 'No camera was found on this device. Use a barcode scanner or type the code instead.'
            : 'Could not start the camera. Close other apps using it, then try again — or type the code instead.');
      }
    }

    start();

    return () => {
      cancelled = true;
      clearTimeout(timer);
      if (stream) stream.getTracks().forEach((t) => t.stop());
      if (video) video.srcObject = null;
    };
  }, [onDetectedRef]);

  async function changeZoom(value) {
    setZoom((z) => ({ ...z, value }));
    try { await trackRef.current?.applyConstraints({ advanced: [{ zoom: value }] }); } catch { /* ignore */ }
  }

  async function toggleTorch() {
    const next = !torch;
    try {
      await trackRef.current?.applyConstraints({ advanced: [{ torch: next }] });
      setTorch(next);
    } catch { /* ignore */ }
  }

  return (
    <div className="cam-overlay" role="dialog" aria-modal="true">
      <div className="cam-sheet">
        <div className="cam-sheet__header">
          <h2>{title}</h2>
          <button type="button" className="cam-close" aria-label="Close camera" onClick={onClose}>×</button>
        </div>

        {error ? (
          <div className="cam-error">{error}</div>
        ) : (
          <>
            <div className="cam-viewport">
              {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
              <video ref={videoRef} className="cam-video" muted playsInline />
              <div className="cam-reticle" />
              {status === 'starting' && <div className="cam-sheet__status">Starting camera…</div>}
            </div>

            {(zoom || torch !== null) && (
              <div className="cam-controls">
                {zoom && (
                  <label className="cam-zoom">
                    <span>Zoom {Number(zoom.value).toFixed(1)}×</span>
                    <input type="range" min={zoom.min} max={zoom.max} step={zoom.step} value={zoom.value}
                           onChange={(e) => changeZoom(Number(e.target.value))} />
                  </label>
                )}
                {torch !== null && (
                  <button type="button" className="cam-torch" aria-pressed={torch} onClick={toggleTorch}>
                    {torch ? 'Light off' : 'Light on'}
                  </button>
                )}
              </div>
            )}

            <p className="cam-sheet__hint">
              Fit the barcode inside the box. Small barcode? Hold the phone about 15–20 cm away
              {zoom ? ' and zoom in' : ''} rather than very close — the camera can&apos;t focus closer than that.
              {lastSeen ? <> Last scanned: <b>{lastSeen}</b>.</> : ` ${hint}`}
            </p>
          </>
        )}

        <button type="button" className="cam-done" onClick={onClose}>Done</button>
      </div>
    </div>
  );
}
