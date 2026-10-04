<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Helpers\Money;

/**
 * A deterministic gateway for local development and automated tests.
 *
 * This is not a stub or a placeholder — it is a complete implementation of the
 * interface that settles payments locally instead of over the network. It
 * exists so the entire checkout path (intent, signed callback, signed webhook,
 * idempotent redelivery, refunds) can be exercised end to end without live
 * credentials, which is the difference between the payment flow being tested
 * and merely being written.
 *
 * SAFETY: the constructor refuses to build outside a local or testing
 * environment. If someone points production at `sandbox` by mistake, the
 * application fails loudly at boot rather than silently accepting orders that
 * nobody ever paid for.
 *
 * Signatures use the same HMAC-SHA256 construction Razorpay uses, so callback
 * and webhook verification exercise the real code path rather than a shortcut.
 */
final class SandboxGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly string $environment,
        private readonly Logger $logger,
    ) {
        if (!in_array($environment, ['local', 'testing'], true)) {
            throw new \RuntimeException(
                'The sandbox payment gateway cannot run in the "' . $environment . '" environment. '
                . 'Set payment_gateway to a real provider (razorpay) before going live.'
            );
        }

        if ($secret === '') {
            throw new \RuntimeException('SANDBOX_PAYMENT_SECRET must be set to use the sandbox gateway.');
        }
    }

    public function name(): string
    {
        return 'sandbox';
    }

    public function createIntent(Money $amount, string $currencyCode, string $reference, array $context = []): PaymentIntent
    {
        $gatewayOrderId = 'sbox_order_' . substr(hash('sha256', $reference . '|' . $amount->paise()), 0, 24);

        $this->logger->info('Sandbox payment intent created', [
            'reference' => $reference,
            'amount' => $amount->toDecimal(),
            'gateway_order_id' => $gatewayOrderId,
        ], 'payment');

        // Shaped like a real UPI intent URL so client code that parses it is
        // exercised properly rather than special-cased for tests.
        $upiIntent = sprintf(
            'upi://pay?pa=sandbox@upi&pn=%s&tr=%s&am=%s&cu=%s',
            rawurlencode((string) ($context['merchant_name'] ?? 'Spice & Dry Fruits')),
            rawurlencode($reference),
            $amount->toDecimal(),
            $currencyCode
        );

        return new PaymentIntent(
            gateway: $this->name(),
            gatewayOrderId: $gatewayOrderId,
            amount: $amount,
            currencyCode: $currencyCode,
            upiIntentUrl: $upiIntent,
            qrPayload: $upiIntent,
            checkoutUrl: null,
            publicKey: 'sandbox_key',
            expiresInSeconds: 900,
            raw: ['sandbox' => true, 'reference' => $reference],
        );
    }

    /**
     * Simulates a payment result and returns a correctly signed payload.
     *
     * Only reachable from test code and the sandbox-only API route; there is no
     * path from a customer request to this method.
     *
     * @return array<string, mixed> A payload verifyCallback() will accept
     */
    public function simulatePayment(string $gatewayOrderId, Money $amount, bool $succeeds = true): array
    {
        $paymentId = 'sbox_pay_' . bin2hex(random_bytes(10));

        $payload = [
            'sandbox_order_id' => $gatewayOrderId,
            'sandbox_payment_id' => $paymentId,
            'sandbox_status' => $succeeds ? 'captured' : 'failed',
            'sandbox_amount' => $amount->paise(),
            'sandbox_method' => 'upi',
            'sandbox_vpa' => 'customer@sandboxupi',
        ];

        $payload['sandbox_signature'] = $this->sign($gatewayOrderId, $paymentId, $payload['sandbox_status']);

        return $payload;
    }

    public function verifyCallback(array $payload): PaymentVerification
    {
        $orderId = (string) ($payload['sandbox_order_id'] ?? '');
        $paymentId = (string) ($payload['sandbox_payment_id'] ?? '');
        $status = (string) ($payload['sandbox_status'] ?? '');
        $signature = (string) ($payload['sandbox_signature'] ?? '');

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return PaymentVerification::rejected('Incomplete sandbox callback.', $payload);
        }

        if (!hash_equals($this->sign($orderId, $paymentId, $status), $signature)) {
            return PaymentVerification::rejected('Sandbox signature did not match.', $payload);
        }

        return $this->verificationFrom($payload, true);
    }

    public function verifyWebhook(string $rawBody, string $signature): PaymentVerification
    {
        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return PaymentVerification::rejected('Sandbox webhook signature did not match.');
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return PaymentVerification::rejected('Sandbox webhook body was not valid JSON.');
        }

        return $this->verificationFrom($payload, true);
    }

    public function fetchPayment(string $gatewayPaymentId): PaymentVerification
    {
        // Nothing to fetch from: the sandbox holds no state of its own. Callers
        // that reach here are asking about a payment that never completed.
        return new PaymentVerification(
            signatureVerified: true,
            status: PaymentVerification::STATUS_PENDING,
            gatewayPaymentId: $gatewayPaymentId,
            gatewayOrderId: null,
            amount: null,
            failureReason: 'The sandbox gateway keeps no server-side payment record.',
        );
    }

    public function refund(string $gatewayPaymentId, Money $amount, string $reason, string $idempotencyKey): array
    {
        $this->logger->info('Sandbox refund issued', [
            'gateway_payment_id' => $gatewayPaymentId,
            'amount' => $amount->toDecimal(),
            'reason' => $reason,
        ], 'payment');

        return [
            'refund_id' => 'sbox_rfnd_' . substr(hash('sha256', $idempotencyKey), 0, 20),
            'status' => 'processed',
            'raw' => ['sandbox' => true, 'idempotency_key' => $idempotencyKey],
        ];
    }

    public function eventIdFrom(array $payload, string $signature): string
    {
        $paymentId = (string) ($payload['sandbox_payment_id'] ?? '');
        $status = (string) ($payload['sandbox_status'] ?? '');

        if ($paymentId !== '') {
            return 'sbox:' . $paymentId . ':' . $status;
        }

        return 'sbox:sig:' . substr(hash('sha256', $signature), 0, 40);
    }

    /**
     * Builds the signed webhook body a gateway would post. Used by the smoke
     * test to exercise webhook handling, including redelivery.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{body:string, signature:string}
     */
    public function buildWebhook(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new HttpException('Could not encode the sandbox webhook payload.', 500);
        }

        return ['body' => $body, 'signature' => hash_hmac('sha256', $body, $this->secret)];
    }

    /** @param array<string, mixed> $payload */
    private function verificationFrom(array $payload, bool $verified): PaymentVerification
    {
        $status = match ((string) ($payload['sandbox_status'] ?? '')) {
            'captured' => PaymentVerification::STATUS_CAPTURED,
            'authorized' => PaymentVerification::STATUS_AUTHORIZED,
            'refunded' => PaymentVerification::STATUS_REFUNDED,
            'failed' => PaymentVerification::STATUS_FAILED,
            default => PaymentVerification::STATUS_PENDING,
        };

        return new PaymentVerification(
            signatureVerified: $verified,
            status: $status,
            gatewayPaymentId: isset($payload['sandbox_payment_id']) ? (string) $payload['sandbox_payment_id'] : null,
            gatewayOrderId: isset($payload['sandbox_order_id']) ? (string) $payload['sandbox_order_id'] : null,
            amount: isset($payload['sandbox_amount']) ? Money::fromPaise((int) $payload['sandbox_amount']) : null,
            method: 'upi',
            upiVpa: $payload['sandbox_vpa'] ?? null,
            upiTransactionId: isset($payload['sandbox_payment_id'])
                ? 'SBOXUTR' . strtoupper(substr((string) $payload['sandbox_payment_id'], -10))
                : null,
            failureCode: $status === PaymentVerification::STATUS_FAILED ? 'sandbox_declined' : null,
            failureReason: $status === PaymentVerification::STATUS_FAILED
                ? 'The sandbox gateway was asked to decline this payment.'
                : null,
            raw: $payload,
        );
    }

    private function sign(string $orderId, string $paymentId, string $status): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId . '|' . $status, $this->secret);
    }
}
