<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Core\Logger;
use App\Helpers\Money;

/**
 * A deterministic courier for local development and automated tests.
 *
 * Complete rather than a stub: it books, labels, schedules pickups, emits a
 * realistic scan sequence and signs its webhooks with the same HMAC
 * construction a real courier would. That is what makes the delivery path
 * genuinely testable — the alternative is that booking and tracking are only
 * ever exercised in production, against a courier account, with real parcels.
 *
 * SAFETY: refuses to construct outside a local or testing environment. A
 * merchant who leaves `courier_driver` on `sandbox` in production gets a loud
 * failure at boot rather than a warehouse full of parcels with fake AWBs that
 * no courier will ever collect.
 */
final class SandboxCourierAdapter implements CourierAdapterInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly string $environment,
        private readonly Logger $logger,
    ) {
        if (!in_array($environment, ['local', 'testing'], true)) {
            throw new \RuntimeException(
                'The sandbox courier adapter cannot run in the "' . $environment . '" environment. '
                . 'Point courier_driver at a real integration (shiprocket) before going live.'
            );
        }

        if ($secret === '') {
            throw new \RuntimeException('SANDBOX_COURIER_SECRET must be set to use the sandbox courier.');
        }
    }

    public function name(): string
    {
        return 'sandbox';
    }

    /**
     * No live quote. Returning null makes the caller fall back to the rate card,
     * which exercises the same fallback path a real courier's outage would.
     */
    public function quote(array $courier, ParcelSpec $parcel): ?CourierQuote
    {
        return null;
    }

    public function book(array $courier, ParcelSpec $parcel, array $order): ShipmentBooking
    {
        // Derived from the order number so a retried booking returns the same
        // AWB rather than a second parcel.
        $awb = 'SBX' . strtoupper(substr(
            hash('sha256', (string) ($order['order_number'] ?? '') . '|' . $courier['code']),
            0,
            12
        ));

        $this->logger->info('Sandbox courier booking created', [
            'awb' => $awb,
            'courier' => $courier['code'],
            'chargeable_weight_grams' => $parcel->chargeableWeightGrams(),
        ], 'delivery');

        return new ShipmentBooking(
            success: true,
            awbNumber: $awb,
            courierShipmentId: 'sbox_shp_' . substr(hash('sha256', $awb), 0, 16),
            labelUrl: 'https://example.test/labels/' . $awb . '.pdf',
            courierCharge: null,
            estimatedDeliveryDate: date('Y-m-d', strtotime('+3 days')),
            raw: ['sandbox' => true, 'parcel' => $parcel->toArray()],
        );
    }

    public function label(array $courier, string $awbNumber): ?string
    {
        return 'https://example.test/labels/' . $awbNumber . '.pdf';
    }

    public function schedulePickup(array $courier, array $awbNumbers, string $pickupDate, array $contact): array
    {
        return [
            'success' => true,
            'reference' => 'SBXPKP' . strtoupper(substr(hash('sha256', $pickupDate . implode(',', $awbNumbers)), 0, 10)),
            'message' => sprintf('%d parcel(s) scheduled for collection on %s.', count($awbNumbers), $pickupDate),
            'raw' => ['sandbox' => true, 'awbs' => $awbNumbers],
        ];
    }

    /**
     * A plausible scan history, derived deterministically from the AWB so the
     * same parcel always tells the same story.
     */
    public function track(array $courier, string $awbNumber): array
    {
        $seed = hexdec(substr(hash('sha256', $awbNumber), 0, 6));
        $stages = (int) ($seed % 5) + 1;
        $base = time() - (86400 * 3);

        $script = [
            [TrackingUpdate::PICKED_UP, 'Picked up', 'Collected from the merchant', 'Bengaluru'],
            [TrackingUpdate::IN_TRANSIT, 'In transit', 'Departed the origin facility', 'Bengaluru Hub'],
            [TrackingUpdate::IN_TRANSIT, 'In transit', 'Arrived at the destination facility', 'Destination Hub'],
            [TrackingUpdate::OUT_FOR_DELIVERY, 'Out for delivery', 'With the delivery agent', 'Destination'],
            [TrackingUpdate::DELIVERED, 'Delivered', 'Handed to the recipient', 'Destination'],
        ];

        $updates = [];

        for ($i = 0; $i < $stages; ++$i) {
            [$status, $title, $description, $location] = $script[$i];

            $updates[] = new TrackingUpdate(
                status: $status,
                title: $title,
                description: $description,
                location: $location,
                occurredAt: date('Y-m-d H:i:s', $base + ($i * 14400)),
                courierEventId: $awbNumber . ':' . $i,
                eventCode: 'SBX' . $i,
                raw: ['sandbox' => true],
            );
        }

        return $updates;
    }

    public function cancel(array $courier, string $awbNumber): array
    {
        return ['success' => true, 'message' => 'Sandbox shipment cancelled.'];
    }

    public function parseWebhook(string $rawBody, string $signature): ?array
    {
        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload) || !isset($payload['awb'])) {
            return null;
        }

        $updates = [];

        foreach ($payload['events'] ?? [] as $index => $event) {
            $updates[] = new TrackingUpdate(
                status: (string) ($event['status'] ?? TrackingUpdate::IN_TRANSIT),
                title: (string) ($event['title'] ?? 'Update'),
                description: $event['description'] ?? null,
                location: $event['location'] ?? null,
                occurredAt: (string) ($event['occurred_at'] ?? date('Y-m-d H:i:s')),
                courierEventId: (string) ($event['event_id'] ?? ($payload['awb'] . ':wh:' . $index)),
                eventCode: $event['code'] ?? null,
                raw: is_array($event) ? $event : [],
            );
        }

        return ['awb' => (string) $payload['awb'], 'updates' => $updates];
    }

    public function manifest(array $courier, array $awbNumbers): ?string
    {
        return 'https://example.test/manifests/' . substr(hash('sha256', implode(',', $awbNumbers)), 0, 16) . '.pdf';
    }

    /**
     * Builds a correctly signed webhook body, for tests.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{body:string, signature:string}
     */
    public function buildWebhook(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \RuntimeException('Could not encode the sandbox courier webhook payload.');
        }

        return ['body' => $body, 'signature' => hash_hmac('sha256', $body, $this->secret)];
    }
}
