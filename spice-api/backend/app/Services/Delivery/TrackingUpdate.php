<?php

declare(strict_types=1);

namespace App\Services\Delivery;

/**
 * One scan from a courier's tracking feed.
 *
 * `status` is the platform's own vocabulary, not the courier's. Every courier
 * invents its own scan codes — "OFD", "Out For Delivery", "18" — and letting
 * those reach the database would put the translation problem in every query
 * and every report. Adapters normalise on the way in.
 */
final class TrackingUpdate
{
    public const IN_TRANSIT = 'in_transit';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const FAILED_DELIVERY = 'failed_delivery';
    public const RTO_INITIATED = 'rto_initiated';
    public const RTO_DELIVERED = 'rto_delivered';
    public const PICKED_UP = 'picked_up';
    public const LOST = 'lost';
    public const CANCELLED = 'cancelled';
    public const PENDING = 'pending';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $status,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly string $occurredAt,
        public readonly ?string $courierEventId = null,
        public readonly ?string $eventCode = null,
        public readonly bool $isCustomerVisible = true,
        public readonly array $raw = [],
    ) {
    }

    /** Statuses after which no further scan should be expected. */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::DELIVERED, self::RTO_DELIVERED, self::LOST, self::CANCELLED], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'occurred_at' => $this->occurredAt,
            'courier_event_id' => $this->courierEventId,
            'event_code' => $this->eventCode,
        ];
    }
}
