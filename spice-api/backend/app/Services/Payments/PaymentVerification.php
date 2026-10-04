<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Helpers\Money;

/**
 * The verdict on a payment, whether it arrived by webhook or by client callback.
 *
 * `signatureVerified` is the field BR-005 rests on. A payment that a client
 * merely claims succeeded is worth nothing: anyone can POST a success callback.
 * Only a signature computed with the gateway secret, or a server-to-server
 * fetch, may set this true.
 */
final class PaymentVerification
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $signatureVerified,
        public readonly string $status,
        public readonly ?string $gatewayPaymentId,
        public readonly ?string $gatewayOrderId,
        public readonly ?Money $amount,
        public readonly ?string $method = null,
        public readonly ?string $upiVpa = null,
        public readonly ?string $upiTransactionId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {
    }

    public static function rejected(string $reason, array $raw = []): self
    {
        return new self(
            signatureVerified: false,
            status: self::STATUS_FAILED,
            gatewayPaymentId: null,
            gatewayOrderId: null,
            amount: null,
            failureCode: 'signature_invalid',
            failureReason: $reason,
            raw: $raw,
        );
    }

    /** The only condition under which an order may be confirmed. */
    public function isSuccessful(): bool
    {
        return $this->signatureVerified
            && in_array($this->status, [self::STATUS_CAPTURED, self::STATUS_AUTHORIZED], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'signature_verified' => $this->signatureVerified,
            'status' => $this->status,
            'gateway_payment_id' => $this->gatewayPaymentId,
            'gateway_order_id' => $this->gatewayOrderId,
            'amount' => $this->amount?->toDecimal(),
            'method' => $this->method,
            'upi_vpa' => $this->upiVpa,
            'failure_code' => $this->failureCode,
            'failure_reason' => $this->failureReason,
        ];
    }
}
