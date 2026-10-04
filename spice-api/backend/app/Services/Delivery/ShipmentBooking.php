<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Helpers\Money;

/** What a courier returns when a parcel is booked. */
final class ShipmentBooking
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $awbNumber,
        public readonly ?string $courierShipmentId,
        public readonly ?string $labelUrl,
        public readonly ?Money $courierCharge,
        public readonly ?string $estimatedDeliveryDate,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {
    }

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(false, null, null, null, null, null, $reason, $raw);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'awb_number' => $this->awbNumber,
            'courier_shipment_id' => $this->courierShipmentId,
            'label_url' => $this->labelUrl,
            'courier_charge' => $this->courierCharge?->toDecimal(),
            'estimated_delivery_date' => $this->estimatedDeliveryDate,
            'failure_reason' => $this->failureReason,
        ];
    }
}
