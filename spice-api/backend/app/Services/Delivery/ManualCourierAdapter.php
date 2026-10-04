<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Core\Logger;
use App\Helpers\Money;

/**
 * A staff-run delivery mode with no courier API behind it.
 *
 * Used while Shiprocket isn't wired up yet (or is deliberately switched off).
 * Booking a parcel here does not talk to any courier — it records that the
 * order has moved into fulfilment and gives the admin a placeholder AWB slot
 * to fill in by hand once they've actually handed the parcel to whichever
 * local courier or delivery person they're using outside this system.
 *
 * Unlike SandboxCourierAdapter, this class is explicitly allowed to run in
 * production — that's the entire point of it. It never invents tracking scans:
 * track() returns whatever has actually been recorded (nothing, until staff
 * add something), rather than a plausible-looking fake history. Fabricating
 * scan events for a real customer's real order would be a lie the sandbox
 * adapter is only allowed to tell because it can never run in production.
 *
 * NOTE: the customer-facing "delivers in N-M days" estimate does NOT come
 * from this class. It comes from DeliveryChargeService / DeliveryZoneRepository
 * (the delivery_zones table), which is already courier-independent and stays
 * correct regardless of which CourierAdapterInterface is active. This class
 * only needs a rough figure for its own internal ShipmentBooking record.
 */
final class ManualCourierAdapter implements CourierAdapterInterface
{
    public function __construct(
        private readonly Logger $logger,
        private readonly int $fallbackDeliveryDays = 7,
    ) {
    }

    public function name(): string
    {
        return 'manual';
    }

    /** No live rates. Callers fall back to the configured flat/rate-card delivery charge. */
    public function quote(array $courier, ParcelSpec $parcel): ?CourierQuote
    {
        return null;
    }

    public function book(array $courier, ParcelSpec $parcel, array $order): ShipmentBooking
    {
        $this->logger->info('Manual shipment booking recorded', [
            'order_number' => $order['order_number'] ?? null,
        ], 'delivery');

        // No AWB yet — staff assign one from the admin console once the
        // parcel is actually handed off. estimatedDeliveryDate reflects the
        // published 3-8 day window rather than a courier's SLA promise.
        return new ShipmentBooking(
            success: true,
            awbNumber: null,
            courierShipmentId: null,
            labelUrl: null,
            courierCharge: null,
            estimatedDeliveryDate: date('Y-m-d', strtotime('+' . $this->fallbackDeliveryDays . ' days')),
            raw: [
                'manual' => true,
                'note' => 'Awaiting manual dispatch. Add tracking details from the admin console once shipped.',
            ],
        );
    }

    public function label(array $courier, string $awbNumber): ?string
    {
        // There is no generated label for a manually dispatched parcel.
        return null;
    }

    public function schedulePickup(array $courier, array $awbNumbers, string $pickupDate, array $contact): array
    {
        return [
            'success' => true,
            'reference' => null,
            'message' => 'No courier pickup to schedule in manual delivery mode. '
                . 'Hand the parcel to your courier directly and record the AWB once you have it.',
            'raw' => ['manual' => true, 'awbs' => $awbNumbers],
        ];
    }

    /**
     * Manual mode keeps no scan feed of its own. Whatever staff have entered
     * against the shipment (via the admin console's tracking-note field) is
     * what the repository layer already returns to the customer; this adapter
     * has nothing further to add, so it returns no updates rather than
     * fabricating a plausible-looking history.
     */
    public function track(array $courier, string $awbNumber): array
    {
        return [];
    }

    public function cancel(array $courier, string $awbNumber): array
    {
        return ['success' => true, 'message' => 'Shipment cancelled.'];
    }

    /** Nothing sends this adapter a webhook. */
    public function parseWebhook(string $rawBody, string $signature): ?array
    {
        return null;
    }

    public function manifest(array $courier, array $awbNumbers): ?string
    {
        return null;
    }
}
