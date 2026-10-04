<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Helpers\Money;

/**
 * Result of resolving a delivery charge for a weight and a destination
 * (BR-006). Produced by DeliveryChargeService and consumed by the pricing
 * engine; the two are separate so charge policy can change without touching
 * the arithmetic.
 */
final class DeliveryQuote
{
    public function __construct(
        public readonly string $zoneCode,
        public readonly string $zoneName,
        public readonly Money $charge,
        public readonly Money $chargeBeforeWaiver,
        public readonly float $gstRate,
        public readonly int $slaMinDays,
        public readonly int $slaMaxDays,
        public readonly int $chargeableWeightGrams,
        public readonly bool $isServiceable,
        public readonly ?string $waiverReason = null,
        public readonly ?Money $spendMoreForFreeDelivery = null,
    ) {
    }

    /** A quote for an empty cart or an unpriceable destination. */
    public static function none(string $reason = 'No delivery destination provided'): self
    {
        return new self(
            zoneCode: 'UNKNOWN',
            zoneName: $reason,
            charge: Money::zero(),
            chargeBeforeWaiver: Money::zero(),
            gstRate: 0.0,
            slaMinDays: 0,
            slaMaxDays: 0,
            chargeableWeightGrams: 0,
            isServiceable: false,
        );
    }

    public function isFree(): bool
    {
        return $this->charge->isZero();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'zone_code' => $this->zoneCode,
            'zone_name' => $this->zoneName,
            'is_serviceable' => $this->isServiceable,
            'charge' => $this->charge->toDecimal(),
            'charge_before_waiver' => $this->chargeBeforeWaiver->toDecimal(),
            'is_free' => $this->isFree(),
            'waiver_reason' => $this->waiverReason,
            'spend_more_for_free_delivery' => $this->spendMoreForFreeDelivery?->toDecimal(),
            'chargeable_weight_grams' => $this->chargeableWeightGrams,
            'estimated_days' => [
                'min' => $this->slaMinDays,
                'max' => $this->slaMaxDays,
            ],
        ];
    }
}
