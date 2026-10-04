<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Logger;
use App\Helpers\Money;

/**
 * A staff-verified UPI QR gateway.
 *
 * There is no provider on the other end of this one. The merchant uploads a
 * static UPI QR code in the admin console; a customer scans it in their own
 * banking app and pays outside this system entirely; an administrator then
 * looks at the bank/UPI statement and confirms the payment against the order.
 *
 * This is a deliberate, temporary trade against BR-005. Razorpay and the
 * sandbox gateway satisfy BR-005 with a cryptographic signature nothing
 * outside the gateway could produce. This gateway cannot do that — a static QR
 * carries no per-order signal at all. So the same guarantee is reconstructed
 * with a human instead of a signature: only an authenticated administrator,
 * identified by this application's own auth system, may confirm a manual
 * payment, and every confirmation is written to the audit log with that
 * admin's identity attached. That is a real control, not a placeholder — but
 * it depends on staff discipline in a way HMAC verification does not, which is
 * exactly why GO_LIVE.md should treat switching back to razorpay as a
 * required step, not an optional one.
 *
 * verifyCallback() and verifyWebhook() always return an UNVERIFIED result.
 * There is nothing here to check a signature against, and nothing this class
 * receives from the internet is trustworthy. The only path to a successful
 * PaymentVerification is verifyByAdmin(), called exclusively from
 * ManualPaymentService after an admin's own authenticated action — never from
 * anything a client submits.
 */
final class ManualGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $qrImageUrl,
        private readonly string $payeeVpa,
        private readonly string $payeeName,
        private readonly Logger $logger,
    ) {
    }

    public function name(): string
    {
        return 'manual';
    }

    public function createIntent(Money $amount, string $currencyCode, string $reference, array $context = []): PaymentIntent
    {
        // No live UPI intent link — the QR is static artwork uploaded once by
        // the admin, not generated per order. The reference number is what the
        // customer is asked to carry into the payment note so the eventual
        // manual match-up is unambiguous.
        $this->logger->info('Manual payment intent shown', [
            'reference' => $reference,
            'amount' => $amount->toDecimal(),
        ], 'payment');

        return new PaymentIntent(
            gateway: $this->name(),
            gatewayOrderId: 'manual_' . substr(hash('sha256', $reference . '|' . $amount->paise()), 0, 24),
            amount: $amount,
            currencyCode: $currencyCode,
            upiIntentUrl: $this->payeeVpa !== ''
                ? sprintf(
                    'upi://pay?pa=%s&pn=%s&tr=%s&am=%s&cu=%s',
                    rawurlencode($this->payeeVpa),
                    rawurlencode($this->payeeName !== '' ? $this->payeeName : 'Anjeera Dry Fruits'),
                    rawurlencode($reference),
                    $amount->toDecimal(),
                    $currencyCode
                )
                : null,
            qrPayload: $this->qrImageUrl !== '' ? $this->qrImageUrl : null,
            checkoutUrl: null,
            publicKey: null,
            // No countdown: a manual payment isn't tied to a live gateway
            // session, so there's nothing that actually expires client-side.
            expiresInSeconds: null,
            raw: [
                'manual' => true,
                'reference' => $reference,
                'instructions' => 'Scan the QR code, pay the exact amount, and keep the payment '
                    . 'reference visible. We confirm manual payments within a few hours.',
            ],
        );
    }

    public function verifyCallback(array $payload): PaymentVerification
    {
        // A client cannot confirm its own manual payment. This exists only so
        // the interface is fully implemented; the checkout flow for the manual
        // gateway should not call it, and if it does, the answer is always no.
        return PaymentVerification::rejected(
            'Manual payments are confirmed by our team after we verify the transfer, not automatically.'
        );
    }

    public function verifyWebhook(string $rawBody, string $signature): PaymentVerification
    {
        // Nothing sends this gateway a webhook. Any request that reaches this
        // method is either misconfiguration or a probe, and gets the same
        // unverified answer as an unsigned webhook on any other gateway.
        return PaymentVerification::rejected('The manual gateway does not accept webhooks.');
    }

    public function fetchPayment(string $gatewayPaymentId): PaymentVerification
    {
        return new PaymentVerification(
            signatureVerified: false,
            status: PaymentVerification::STATUS_PENDING,
            gatewayPaymentId: $gatewayPaymentId,
            gatewayOrderId: null,
            amount: null,
            failureReason: 'Manual payments have no gateway-side record to fetch; check the admin queue instead.',
        );
    }

    public function refund(string $gatewayPaymentId, Money $amount, string $reason, string $idempotencyKey): array
    {
        // A manual payment was never captured through any processor, so there
        // is nothing to call. A refund here means the admin transfers money
        // back to the customer directly and records it — logged so that
        // action is visible in the same place every other refund is.
        $this->logger->info('Manual refund recorded (no processor call made)', [
            'gateway_payment_id' => $gatewayPaymentId,
            'amount' => $amount->toDecimal(),
            'reason' => $reason,
        ], 'payment');

        return [
            'refund_id' => 'manual_rfnd_' . substr(hash('sha256', $idempotencyKey), 0, 20),
            'status' => 'requires_manual_transfer',
            'raw' => [
                'manual' => true,
                'note' => 'No payment processor was involved. Transfer the refund to the customer '
                    . 'directly and keep proof against this reference.',
            ],
        ];
    }

    public function eventIdFrom(array $payload, string $signature): string
    {
        return 'manual:' . substr(hash('sha256', json_encode($payload) . '|' . $signature), 0, 40);
    }

    /**
     * The only path to a successful verification for this gateway.
     *
     * Called from ManualPaymentService after an authenticated administrator
     * reviews the transfer and confirms it — never from a request body.
     * signatureVerified is true here on purpose: the human review IS the
     * signature for this gateway, and PaymentService::applyVerification()
     * gates order confirmation on exactly that flag regardless of which
     * gateway produced it.
     */
    public function verifyByAdmin(
        string $gatewayOrderId,
        Money $amount,
        string $utrOrReference,
        int $adminUserId,
    ): PaymentVerification {
        $paymentId = 'manual_pay_' . substr(hash('sha256', $gatewayOrderId . '|' . $adminUserId . '|' . microtime()), 0, 24);

        $this->logger->info('Manual payment verified by admin', [
            'gateway_order_id' => $gatewayOrderId,
            'amount' => $amount->toDecimal(),
            'admin_user_id' => $adminUserId,
        ], 'payment');

        return new PaymentVerification(
            signatureVerified: true,
            status: PaymentVerification::STATUS_CAPTURED,
            gatewayPaymentId: $paymentId,
            gatewayOrderId: $gatewayOrderId,
            amount: $amount,
            method: 'upi',
            upiTransactionId: $utrOrReference !== '' ? $utrOrReference : null,
            raw: [
                'manual' => true,
                'verified_by_admin_user_id' => $adminUserId,
                'utr_or_reference' => $utrOrReference,
            ],
        );
    }

    /** A rejection produced by an admin, e.g. money never arrived. */
    public function rejectByAdmin(string $gatewayOrderId, string $reason, int $adminUserId): PaymentVerification
    {
        $this->logger->info('Manual payment rejected by admin', [
            'gateway_order_id' => $gatewayOrderId,
            'reason' => $reason,
            'admin_user_id' => $adminUserId,
        ], 'payment');

        return new PaymentVerification(
            signatureVerified: false,
            status: PaymentVerification::STATUS_FAILED,
            gatewayPaymentId: null,
            gatewayOrderId: $gatewayOrderId,
            amount: null,
            failureCode: 'manual_rejected',
            failureReason: $reason,
            raw: ['manual' => true, 'rejected_by_admin_user_id' => $adminUserId],
        );
    }
}
