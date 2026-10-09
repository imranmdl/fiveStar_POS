import { formatMoney } from '../../lib/api';
import { buildUpiUri, qrSvg } from '../../lib/upiQr';
import { remainderDue, round2, roundToRupee } from './tillMath';
import { useShop } from './useShop';

/**
 * "Scan & Pay ₹X" on the till screen when UPI is chosen. It only shows the
 * customer what to pay; the sale is recorded when the cashier presses
 * Complete sale after seeing the money arrive, exactly as before.
 */
function UpiQrPanel({ shop, amount }) {
  if (!shop) return null;
  if (!shop.upi) {
    return (
      <div className="till-alert till-alert--warning till-alert--sm">
        No shop UPI ID is set, so no payment QR can be shown. An administrator can add it under Admin → Payments → Settings.
      </div>
    );
  }
  const uri = buildUpiUri({ vpa: shop.upi.vpa, payeeName: shop.upi.payee_name, amount, note: 'Five Star Spices bill' });
  if (!uri) return null;
  return (
    <div className="till-upi-qr">
      <div className="till-upi-qr__title">Scan &amp; Pay {formatMoney(amount)}</div>
      {/* qrSvg builds the SVG itself from the UPI link; no outside markup. */}
      <div className="till-upi-qr__code" dangerouslySetInnerHTML={{ __html: qrSvg(uri, { sizeMm: 40 }) }} />
      <div className="till-upi-qr__id">UPI ID: <strong>{shop.upi.vpa}</strong></div>
      <div className="till-upi-qr__hint">Complete the sale only after the payment shows in the shop&apos;s UPI app.</div>
    </div>
  );
}

/**
 * Totals summary, wallet application, payment method/amount and the
 * complete-sale action — ported from the right-hand cards in
 * renderSellTab() and updateChange()/updateCartSummary() in
 * admin/assets/page-till.js.
 *
 * No checkbox, no separate mode to opt into: typing less than the bill IS
 * accepting a partial payment, for any payment method, the moment there is
 * somebody (a registered customer, or the walk-in mobile) to collect the
 * rest from — see hasDueContact in the source's own comment. Typing the
 * full amount (or leaving a non-cash field blank) is still a normal,
 * fully-paid sale.
 */
export default function PaymentPanel({
  totals,
  selectedCustomer,
  walletApplied,
  onWalletAppliedChange,
  paymentMethod,
  onPaymentMethodChange,
  amountTendered,
  onAmountTenderedChange,
  delivery,
  onDeliveryChange,
  hasDueContact,
  onCompleteSale,
  onPrintBill,
  printingBill,
  busy,
  disabled,
}) {
  const due = remainderDue(totals.grandTotal, walletApplied);
  const shop = useShop();
  // UPI now: the typed amount (a part payment) or, if blank, everything due.
  const upiAmount = paymentMethod === 'upi'
    ? round2(amountTendered === '' ? due : Math.min(Number(amountTendered) || 0, due))
    : 0;
  const canUseWallet = Boolean(selectedCustomer) && !selectedCustomer.wallet.is_frozen && Number(selectedCustomer.wallet.balance) > 0;

  const raw = amountTendered;
  let changeLabel = 'Change due';
  let changeValue = '—';
  let tenderedRoundedHint = '';
  let shortfallHint = null;

  if (raw === '' && paymentMethod !== 'cash') {
    // Unchanged from the source: no amount typed for a non-cash method
    // means "cashier attests it was paid in full by that method".
  } else {
    const tendered = paymentMethod === 'cash' ? roundToRupee(raw) : (Number(raw) || 0);
    const short = round2(due - tendered);
    const willBePartial = short > 0.005;

    changeLabel = willBePartial ? 'Balance still due' : 'Change due';
    changeValue = willBePartial ? formatMoney(short) : formatMoney(Math.max(0, -short));

    if (paymentMethod === 'cash' && raw !== '' && Number(raw) !== tendered) {
      tenderedRoundedHint = `Rounded to ${formatMoney(tendered)}`;
    }

    if (willBePartial) {
      shortfallHint = hasDueContact
        ? <>This will be recorded as a Customer Due — <strong>{formatMoney(short)}</strong> will remain owed, payable later.</>
        : <>Short by <strong>{formatMoney(short)}</strong> — enter the walk-in mobile number above so the remaining balance can be tracked.</>;
    }
  }

  return (
    <div className="till-payment">
      <div className="till-summary-card">
        <div className="till-summary-row"><span>Subtotal</span><span>{formatMoney(totals.subtotal)}</span></div>
        <div className="till-summary-row"><span>Discount</span><span>{formatMoney(totals.discount)}</span></div>
        <div className="till-summary-row till-summary-row--muted"><span>Tax (est., included)</span><span>{formatMoney(totals.tax)}</span></div>
        <hr />
        <div className="till-summary-row till-summary-row--grand"><span>Total</span><span>{formatMoney(totals.grandTotal)}</span></div>

        {canUseWallet && (
          <>
            <label className="till-field till-field--sm">
              <span>From wallet</span>
              <input
                type="number"
                step="0.01"
                min="0"
                inputMode="decimal"
                max={Math.min(totals.grandTotal, Number(selectedCustomer.wallet.balance))}
                value={walletApplied || ''}
                onChange={(event) => {
                  const cap = Math.min(totals.grandTotal, Number(selectedCustomer?.wallet.balance) || 0);
                  onWalletAppliedChange(Math.max(0, Math.min(round2(Number(event.target.value) || 0), cap)));
                }}
              />
            </label>
            <div className="till-summary-row till-summary-row--bold">
              <span>Amount due</span><span>{formatMoney(due)}</span>
            </div>
          </>
        )}
      </div>

      <div className="till-payment-card">
        <label className="till-field till-field--sm">
          <span>Payment method</span>
          <select value={paymentMethod} onChange={(event) => onPaymentMethodChange(event.target.value)}>
            <option value="cash">Cash</option>
            <option value="upi">UPI</option>
            <option value="card">Card (POS machine)</option>
            <option value="other">Other</option>
          </select>
        </label>

        <label className="till-field till-field--sm">
          <span>Amount tendered</span>
          <input
            type="number"
            step="0.01"
            min="0"
            inputMode="decimal"
            value={amountTendered}
            onChange={(event) => onAmountTenderedChange(event.target.value)}
          />
          {tenderedRoundedHint && <div className="till-field__hint">{tenderedRoundedHint}</div>}
        </label>

        <div className="till-change-line">
          <span>{changeLabel}</span>: <span>{changeValue}</span>
        </div>

        {shortfallHint && <div className="till-alert till-alert--warning till-alert--sm">{shortfallHint}</div>}

        {paymentMethod === 'upi' && upiAmount > 0 && !disabled && <UpiQrPanel shop={shop} amount={upiAmount} />}

        <label className="till-field till-field--sm">
          <span>Items given to the customer?</span>
          <select value={delivery} onChange={(event) => onDeliveryChange(event.target.value)}>
            <option value="delivered">Delivered — handed over now</option>
            <option value="pending">Not delivered yet</option>
          </select>
        </label>

        {onPrintBill && (
          <button type="button" className="till-btn till-btn--block till-btn--print-bill" disabled={busy || disabled || printingBill} onClick={onPrintBill}>
            {printingBill ? 'Printing…' : 'Print bill & UPI QR (before payment)'}
          </button>
        )}

        <button type="button" className="till-btn till-btn--primary till-btn--block till-btn--lg" disabled={busy || disabled} onClick={onCompleteSale}>
          {busy ? 'Saving…' : 'Complete sale'}
        </button>
      </div>
    </div>
  );
}
