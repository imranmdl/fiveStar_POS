<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Helpers\Money;

/**
 * Razorpay integration.
 *
 * Chosen over a static VPA QR because it gives signed webhooks. Without a
 * server-to-server confirmation signal, BR-005 ("no progress without verified
 * payment") is unenforceable — a static QR tells you nothing about whether a
 * particular customer paid, and reconciling by bank SMS is not a system.
 *
 * Written against Razorpay's REST API directly rather than the SDK, because the
 * SDK pulls a dependency tree for what amounts to four HTTP calls, and this
 * project has no package manager in its deployment path.
 *
 * Amounts cross the wire in paise, which is also how Money stores them, so no
 * conversion arithmetic is needed and none can go wrong.
 */
final class RazorpayGateway implements PaymentGatewayInterface
{
    private const API_BASE = 'https://api.razorpay.com/v1';

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
        private readonly string $webhookSecret,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 20,
    ) {
        if ($keyId === '' || $keySecret === '') {
            throw new \RuntimeException(
                'Razorpay is selected but RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET are not configured.'
            );
        }
    }

    public function name(): string
    {
        return 'razorpay';
    }

    public function createIntent(Money $amount, string $currencyCode, string $reference, array $context = []): PaymentIntent
    {
        $response = $this->request('POST', '/orders', [
            'amount' => $amount->paise(),
            'currency' => $currencyCode,
            'receipt' => $reference,
            // Razorpay enforces this server-side, so a client cannot widen it
            // to a card payment and break BR-004.
            'payment_capture' => 1,
            'notes' => array_map('strval', array_slice($context, 0, 15, true)),
        ]);

        if (!isset($response['id'])) {
            throw new HttpException('The payment provider did not return an order id.', 502);
        }

        return new PaymentIntent(
            gateway: $this->name(),
            gatewayOrderId: (string) $response['id'],
            amount: $amount,
            currencyCode: $currencyCode,
            // Razorpay's hosted checkout builds the UPI intent itself from the
            // order id; there is no separate intent URL to hand over.
            upiIntentUrl: null,
            qrPayload: null,
            checkoutUrl: null,
            publicKey: $this->keyId,
            expiresInSeconds: 900,
            raw: $response,
        );
    }

    public function verifyCallback(array $payload): PaymentVerification
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return PaymentVerification::rejected('Incomplete payment callback.', $payload);
        }

        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);

        // hash_equals, not ===: a timing-safe comparison, because this value is
        // attacker-supplied and the secret is what it protects.
        if (!hash_equals($expected, $signature)) {
            $this->logger->warning('Razorpay callback signature mismatch', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
            ], 'payment');

            return PaymentVerification::rejected('Payment signature did not match.', $payload);
        }

        // The signature proves the callback is genuine, but not what state the
        // payment is actually in. Ask the gateway.
        return $this->fetchPayment($paymentId);
    }

    public function verifyWebhook(string $rawBody, string $signature): PaymentVerification
    {
        if ($this->webhookSecret === '') {
            throw new \RuntimeException('RAZORPAY_WEBHOOK_SECRET is not configured; webhooks cannot be verified.');
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            $this->logger->warning('Razorpay webhook signature mismatch', [
                'body_length' => strlen($rawBody),
            ], 'payment');

            return PaymentVerification::rejected('Webhook signature did not match.');
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return PaymentVerification::rejected('Webhook body was not valid JSON.');
        }

        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (!is_array($entity)) {
            // A signed webhook for an event we do not act on, e.g. a settlement
            // notification. Genuine, but nothing to do.
            return new PaymentVerification(
                signatureVerified: true,
                status: PaymentVerification::STATUS_PENDING,
                gatewayPaymentId: null,
                gatewayOrderId: null,
                amount: null,
                raw: $payload,
            );
        }

        return $this->fromEntity($entity, true);
    }

    public function fetchPayment(string $gatewayPaymentId): PaymentVerification
    {
        $entity = $this->request('GET', '/payments/' . rawurlencode($gatewayPaymentId));

        // Fetched server-to-server over TLS with our own credentials, so it is
        // authenticated by construction.
        return $this->fromEntity($entity, true);
    }

    public function refund(string $gatewayPaymentId, Money $amount, string $reason, string $idempotencyKey): array
    {
        $response = $this->request(
            'POST',
            '/payments/' . rawurlencode($gatewayPaymentId) . '/refund',
            [
                'amount' => $amount->paise(),
                'speed' => 'normal',
                'notes' => ['reason' => substr($reason, 0, 250)],
                'receipt' => $idempotencyKey,
            ],
            // Razorpay honours this header, so a retried refund does not pay
            // the customer twice.
            ['X-Razorpay-Idempotency-Key: ' . $idempotencyKey]
        );

        return [
            'refund_id' => isset($response['id']) ? (string) $response['id'] : null,
            'status' => (string) ($response['status'] ?? 'pending'),
            'raw' => $response,
        ];
    }

    public function eventIdFrom(array $payload, string $signature): string
    {
        // Razorpay sends x-razorpay-event-id; fall back to hashing the signature
        // so a redelivery is still recognised as the same event.
        $eventId = $payload['id'] ?? null;

        if (is_string($eventId) && $eventId !== '') {
            return $eventId;
        }

        return 'sig:' . substr(hash('sha256', $signature), 0, 40);
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function fromEntity(array $entity, bool $verified): PaymentVerification
    {
        $status = match ((string) ($entity['status'] ?? '')) {
            'captured' => PaymentVerification::STATUS_CAPTURED,
            'authorized' => PaymentVerification::STATUS_AUTHORIZED,
            'refunded' => PaymentVerification::STATUS_REFUNDED,
            'failed' => PaymentVerification::STATUS_FAILED,
            default => PaymentVerification::STATUS_PENDING,
        };

        return new PaymentVerification(
            signatureVerified: $verified,
            status: $status,
            gatewayPaymentId: isset($entity['id']) ? (string) $entity['id'] : null,
            gatewayOrderId: isset($entity['order_id']) ? (string) $entity['order_id'] : null,
            amount: isset($entity['amount']) ? Money::fromPaise((int) $entity['amount']) : null,
            method: isset($entity['method']) ? (string) $entity['method'] : null,
            upiVpa: $entity['vpa'] ?? ($entity['upi']['vpa'] ?? null),
            upiTransactionId: $entity['acquirer_data']['upi_transaction_id'] ?? null,
            failureCode: isset($entity['error_code']) ? (string) $entity['error_code'] : null,
            failureReason: isset($entity['error_description']) ? (string) $entity['error_description'] : null,
            raw: $entity,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<int, string>   $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        $handle = curl_init(self::API_BASE . $path);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->keyId . ':' . $this->keySecret,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Never negotiable. Disabling verification here would expose the API
            // key to any machine on the path.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== []) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            $this->logger->error('Razorpay request failed', [
                'path' => $path,
                'error' => $error,
            ], 'payment');

            throw new HttpException('Could not reach the payment provider. Please try again.', 502);
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            throw new HttpException('The payment provider returned an unreadable response.', 502);
        }

        if ($status >= 400) {
            $message = $decoded['error']['description'] ?? 'The payment provider rejected the request.';

            $this->logger->error('Razorpay returned an error', [
                'path' => $path,
                'http_status' => $status,
                'code' => $decoded['error']['code'] ?? null,
                'description' => $message,
            ], 'payment');

            // Gateway wording is not always fit to show a customer, so it is
            // logged in full and summarised here.
            throw new HttpException(
                'The payment could not be started. Please try again in a moment.',
                502,
                ['gateway' => [(string) $message]]
            );
        }

        return $decoded;
    }
}
