const CHECKOUT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';
let scriptPromise = null;

/** Loads Razorpay's payment screen script once per page. */
export function loadRazorpay() {
  if (window.Razorpay) return Promise.resolve(window.Razorpay);
  if (!scriptPromise) {
    scriptPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = CHECKOUT_SRC;
      script.async = true;
      script.onload = () => (window.Razorpay ? resolve(window.Razorpay) : reject(new Error('Razorpay did not load.')));
      script.onerror = () => {
        scriptPromise = null;
        script.remove();
        reject(new Error('Could not load the payment screen. Check your internet connection and try again.'));
      };
      document.head.appendChild(script);
    });
  }
  return scriptPromise;
}

/**
 * Opens Razorpay's payment screen for one payment attempt, UPI only (GPay,
 * PhonePe, Paytm, BHIM, any UPI app or QR). Resolves with Razorpay's
 * { razorpay_order_id, razorpay_payment_id, razorpay_signature } when the
 * customer pays, or null when they close the screen.
 */
export async function openRazorpay({ payment, order, brand }) {
  const Razorpay = await loadRazorpay();

  return new Promise((resolve, reject) => {
    const checkout = new Razorpay({
      key: payment.public_key,
      order_id: payment.gateway_order_id,
      amount: Math.round(Number(payment.amount) * 100),
      currency: payment.currency_code || 'INR',
      name: brand,
      description: `Order ${order.order_number}`,
      prefill: {
        name: payment.prefill?.name || undefined,
        contact: payment.prefill?.contact || undefined,
      },
      notes: { order_number: order.order_number },
      theme: { color: '#c62d1f' },
      method: { upi: true, card: false, netbanking: false, wallet: false, emi: false, paylater: false },
      handler: (result) => resolve(result),
      modal: {
        ondismiss: () => resolve(null),
        confirm_close: true,
      },
    });
    checkout.on('payment.failed', () => {
      // Razorpay shows the failure and lets the customer retry inside the
      // same screen; nothing to do here.
    });
    try {
      checkout.open();
    } catch (err) {
      reject(err);
    }
  });
}
