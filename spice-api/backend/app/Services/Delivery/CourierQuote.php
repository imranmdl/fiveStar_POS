<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Helpers\Money;

/**
 * One courier's offer for one parcel, plus whether it is even eligible.
 *
 * An ineligible quote is kept rather than discarded: BR-007 requires the
 * decision to be auditable, and "Blue Dart was skipped because the parcel is
 * 26kg and their ceiling is 25kg" is exactly what operations needs when they
 * ask why a parcel went the expensive way.
 */
final class CourierQuote
{
    /** @param array<int, string> $ineligibilityReasons */
    public function __construct(
        public readonly int $courierId,
        public readonly string $courierCode,
        public readonly string $courierName,
        public readonly Money $cost,
        public readonly int $slaMinDays,
        public readonly int $slaMaxDays,
        public readonly float $reliabilityScore,
        public readonly int $priority,
        public readonly bool $isEligible,
        public readonly array $ineligibilityReasons = [],
        public readonly bool $isExpress = false,
        public readonly bool $costFromRateCard = true,
    ) {
    }

    public static function ineligible(
        int $courierId,
        string $courierCode,
        string $courierName,
        array $reasons,
    ): self {
        return new self(
            courierId: $courierId,
            courierCode: $courierCode,
            courierName: $courierName,
            cost: Money::zero(),
            slaMinDays: 0,
            slaMaxDays: 0,
            reliabilityScore: 0.0,
            priority: 9999,
            isEligible: false,
            ineligibilityReasons: $reasons,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'courier_id' => $this->courierId,
            'courier_code' => $this->courierCode,
            'courier_name' => $this->courierName,
            'cost' => $this->cost->toDecimal(),
            'sla_min_days' => $this->slaMinDays,
            'sla_max_days' => $this->slaMaxDays,
            'reliability_score' => $this->reliabilityScore,
            'is_express' => $this->isExpress,
            'is_eligible' => $this->isEligible,
            'ineligibility_reasons' => $this->ineligibilityReasons,
            'cost_source' => $this->costFromRateCard ? 'rate_card' : 'live_api',
        ];
    }
}
