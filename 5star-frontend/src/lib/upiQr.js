import QRCode from 'qrcode';

/**
 * Dynamic UPI payment QR codes (standard NPCI "upi://pay" link).
 *
 * The QR only ASKS for a payment: scanning it opens GPay / PhonePe / Paytm /
 * BHIM with the shop's UPI ID and the amount filled in. Nothing is marked
 * paid because a QR was shown or scanned — the cashier still confirms the
 * money arrived and completes the sale (or records the due payment) as before.
 */

/** Same rule as the server's SettingsService::isValidVpa(). */
export function isValidVpa(vpa) {
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]{1,255}@[a-zA-Z][a-zA-Z0-9]{1,63}$/.test(String(vpa || ''));
}

/** Largest single UPI payment most apps accept from a scanned QR. */
export const UPI_MAX_AMOUNT = 100000;

/**
 * Builds the upi://pay link for an amount, or returns null (no QR should be
 * shown) when the UPI ID or the amount isn't usable.
 *
 * @param {{ vpa: string, payeeName?: string, amount: number|string, note?: string }} p
 */
export function buildUpiUri({ vpa, payeeName, amount, note }) {
  const value = Math.round(Number(amount) * 100) / 100;
  if (!isValidVpa(vpa)) return null;
  if (!Number.isFinite(value) || value < 1 || value > UPI_MAX_AMOUNT) return null;

  const params = [
    ['pa', vpa],
    ['pn', String(payeeName || '').trim().slice(0, 50) || vpa],
    ['am', value.toFixed(2)],
    ['cu', 'INR'],
  ];
  if (note) params.push(['tn', String(note).slice(0, 50)]);

  // "@" stays literal in the UPI ID: some scanner apps don't decode "%40".
  return 'upi://pay?' + params.map(([k, v]) => `${k}=${encodeURIComponent(v).replace(/%40/g, '@')}`).join('&');
}

/**
 * The QR as a self-contained black-on-white SVG string (crisp on thermal
 * printers: square modules, no anti-aliasing, quiet zone included).
 */
export function qrSvg(text, { sizeMm = 34, quiet = 3 } = {}) {
  const qr = QRCode.create(text, { errorCorrectionLevel: 'M' });
  const n = qr.modules.size;
  const total = n + quiet * 2;
  let path = '';
  for (let r = 0; r < n; r += 1) {
    for (let c = 0; c < n; c += 1) {
      if (qr.modules.get(r, c)) path += `M${c + quiet} ${r + quiet}h1v1h-1z`;
    }
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total} ${total}" width="${sizeMm}mm" height="${sizeMm}mm" shape-rendering="crispEdges" role="img" aria-label="UPI payment QR code"><rect width="${total}" height="${total}" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
}
