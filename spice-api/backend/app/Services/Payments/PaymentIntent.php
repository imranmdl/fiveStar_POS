<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Helpers\Money;

/**
 * What a gateway hands back when a payment is started: everything the client
 * needs to open a UPI app, and nothing it does not.
 */
final class PaymentIntent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $gateway,
        public readonly string $gatewayOrderId,
        public readonly Money $amount,
        public readonly string $currencyCode,
        public readonly ?string $upiIntentUrl,
        public readonly ?string $qrPayload,
        public readonly ?string $checkoutUrl,
        public readonly ?string $publicKey,
        public readonly ?int $expiresInSeconds,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Client-facing shape. `raw` is deliberately excluded — a gateway response
     * can carry internal identifiers and merchant details that have no business
     * reaching a browser.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'gateway_order_id' => $this->gatewayOrderId,
            'amount' => $this->amount->toDecimal(),
            'currency_code' => $this->currencyCode,
            'upi_intent_url' => $this->upiIntentUrl,
            'qr_payload' => $this->qrPayload,
            'checkout_url' => $this->checkoutUrl,
            'public_key' => $this->publicKey,
            'expires_in_seconds' => $this->expiresInSeconds,
            'methods' => ['upi'],
        ];
    }
}
