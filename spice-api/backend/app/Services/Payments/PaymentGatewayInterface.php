<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Helpers\Money;

/**
 * The contract every payment provider implements.
 *
 * This interface is the reason the provider choice is reversible. Nothing above
 * it knows what Razorpay is: OrderService and PaymentService speak only in
 * PaymentIntent and PaymentVerification. Swapping to Cashfree, PhonePe or a
 * direct bank integration means writing one class and changing one setting.
 *
 * Implementations MUST:
 *   - never trust client-supplied success signals;
 *   - verify signatures with the gateway secret before reporting success;
 *   - be safe to call twice with the same inputs.
 */
interface PaymentGatewayInterface
{
    /** Identifier stored on every payment row, e.g. 'razorpay'. */
    public function name(): string;

    /**
     * Starts a payment and returns what the client needs to complete it.
     *
     * @param array<string, mixed> $context Order number, customer contact, notes
     */
    public function createIntent(Money $amount, string $currencyCode, string $reference, array $context = []): PaymentIntent;

    /**
     * Verifies a client-side callback. The signature is computed with the
     * gateway secret, so a forged callback fails here.
     *
     * @param array<string, mixed> $payload
     */
    public function verifyCallback(array $payload): PaymentVerification;

    /**
     * Verifies an inbound webhook against its signature header.
     *
     * @param string $rawBody The exact bytes received; re-encoding JSON before
     *                        hashing changes the signature and breaks verification
     */
    public function verifyWebhook(string $rawBody, string $signature): PaymentVerification;

    /**
     * Asks the gateway directly what happened. The authoritative answer, used
     * when a client callback is missing or a webhook is late.
     */
    public function fetchPayment(string $gatewayPaymentId): PaymentVerification;

    /**
     * Refunds a captured payment.
     *
     * @return array{refund_id:?string, status:string, raw:array<string, mixed>}
     */
    public function refund(string $gatewayPaymentId, Money $amount, string $reason, string $idempotencyKey): array;

    /** Extracts a stable event id, so a redelivered webhook is recognised. */
    public function eventIdFrom(array $payload, string $signature): string;
}
