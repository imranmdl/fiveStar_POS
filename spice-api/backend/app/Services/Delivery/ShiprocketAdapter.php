<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Core\Logger;
use App\Helpers\Money;
use App\Repositories\SettingRepository;

/**
 * Shiprocket integration.
 *
 * Shiprocket is an aggregator: one contract and one API fronting Delhivery,
 * Blue Dart, XpressBees, DTDC and others. That is why `couriers` rows for those
 * carriers all point their `adapter` at this class and differ only by
 * `channel_code` — the courier identity is a routing parameter, not a separate
 * integration.
 *
 * For a merchant at this scale the aggregator is almost always right: direct
 * carrier contracts need per-courier minimum volumes and separate
 * reconciliation. When volume justifies going direct with one carrier, that
 * carrier's row switches adapter and nothing else changes.
 *
 * Written against the REST API rather than an SDK, for the same reason as the
 * payment gateway: this project has no package manager in its deployment path.
 *
 * AUTHENTICATION: Shiprocket issues a bearer token valid for ten days from an
 * email and password. Requesting one on every call would be slow and would trip
 * their rate limits, so the token is cached in settings and refreshed on expiry
 * or on the first 401.
 */
final class ShiprocketAdapter implements CourierAdapterInterface
{
    private const API_BASE = 'https://apiv2.shiprocket.in/v1/external';
    private const TOKEN_SETTING = 'shiprocket_token';
    private const TOKEN_EXPIRY_SETTING = 'shiprocket_token_expires';

    private ?string $token = null;

    public function __construct(
        private readonly string $email,
        private readonly string $password,
        private readonly string $webhookSecret,
        private readonly string $pickupLocationName,
        private readonly SettingRepository $settings,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 25,
    ) {
        if ($email === '' || $password === '') {
            throw new \RuntimeException(
                'Shiprocket is selected but SHIPROCKET_EMAIL / SHIPROCKET_PASSWORD are not configured.'
            );
        }
    }

    public function name(): string
    {
        return 'shiprocket';
    }

    public function quote(array $courier, ParcelSpec $parcel): ?CourierQuote
    {
        $pickupPincode = (string) ($this->settings->value('pickup_pincode') ?? '');

        if ($pickupPincode === '') {
            return null;
        }

        try {
            $response = $this->request('GET', '/courier/serviceability/?' . http_build_query([
                'pickup_postcode' => $pickupPincode,
                'delivery_postcode' => $parcel->destinationPincode,
                // BR-004 makes every order prepaid, so COD is never requested.
                'cod' => 0,
                'weight' => round($parcel->chargeableWeightGrams() / 1000, 3),
                'declared_value' => $parcel->declaredValue->toDecimal(),
            ]));
        } catch (\Throwable $exception) {
            // A rate lookup failing must not stop a parcel being booked. The
            // caller falls back to the negotiated rate card.
            $this->logger->warning('Shiprocket serviceability lookup failed; falling back to the rate card', [
                'courier' => $courier['code'],
                'reason' => $exception->getMessage(),
            ], 'delivery');

            return null;
        }

        $options = $response['data']['available_courier_companies'] ?? [];

        if (!is_array($options)) {
            return null;
        }

        foreach ($options as $option) {
            if ((string) ($option['courier_company_id'] ?? '') !== (string) ($courier['channel_code'] ?? '')) {
                continue;
            }

            // Shiprocket reports an estimate as days, or occasionally as hours.
            // Where it gives neither, assume five days rather than zero — a zero
            // SLA would score as instant delivery and win every "fastest"
            // comparison on missing data.
            $days = (int) ($option['estimated_delivery_days'] ?? 0);

            if ($days <= 0 && isset($option['etd_hours'])) {
                $days = (int) ceil(((int) $option['etd_hours']) / 24);
            }

            if ($days <= 0) {
                $days = 5;
            }

            return new CourierQuote(
                courierId: (int) $courier['id'],
                courierCode: (string) $courier['code'],
                courierName: (string) $courier['name'],
                cost: Money::fromDecimal((string) ($option['rate'] ?? '0')),
                slaMinDays: max(1, $days - 1),
                slaMaxDays: max(1, $days),
                reliabilityScore: (float) $courier['reliability_score'],
                priority: (int) $courier['priority'],
                isEligible: true,
                isExpress: (bool) ($option['is_surface'] ?? false) === false,
                costFromRateCard: false,
            );
        }

        return null;
    }

    public function book(array $courier, ParcelSpec $parcel, array $order): ShipmentBooking
    {
        $items = [];

        foreach ($order['items'] ?? [] as $item) {
            $items[] = [
                'name' => (string) $item['product_name'] . ' ' . (string) $item['variant_name'],
                'sku' => (string) $item['sku'],
                'units' => (int) $item['quantity'],
                'selling_price' => (float) $item['unit_price'],
                'hsn' => $item['hsn_code'] ?? '',
            ];
        }

        $payload = [
            'order_id' => (string) $order['order_number'],
            'order_date' => date('Y-m-d H:i', strtotime((string) ($order['placed_date'] ?? 'now'))),
            'pickup_location' => $this->pickupLocationName,
            'billing_customer_name' => (string) $order['ship_name'],
            'billing_last_name' => '',
            'billing_address' => (string) $order['ship_address_line1'],
            'billing_address_2' => (string) ($order['ship_address_line2'] ?? ''),
            'billing_city' => (string) $order['ship_city'],
            'billing_pincode' => (string) $order['ship_pincode'],
            'billing_state' => (string) $order['ship_state'],
            'billing_country' => (string) ($order['ship_country'] ?? 'India'),
            'billing_email' => (string) ($order['customer_email'] ?? ''),
            'billing_phone' => (string) $order['ship_mobile'],
            'shipping_is_billing' => true,
            'order_items' => $items,
            // BR-004: prepaid only. Sending 'COD' here would create a
            // cash-on-delivery consignment for an order already paid for.
            'payment_method' => 'Prepaid',
            'sub_total' => (float) $order['grand_total'],
            'length' => round($parcel->lengthMm / 10, 2),
            'breadth' => round($parcel->widthMm / 10, 2),
            'height' => round($parcel->heightMm / 10, 2),
            'weight' => round($parcel->actualWeightGrams / 1000, 3),
        ];

        try {
            $created = $this->request('POST', '/orders/create/adhoc', $payload);
        } catch (\Throwable $exception) {
            return ShipmentBooking::failed(
                'The courier could not accept this parcel: ' . $exception->getMessage()
            );
        }

        $shipmentId = $created['shipment_id'] ?? null;

        if ($shipmentId === null) {
            return ShipmentBooking::failed(
                (string) ($created['message'] ?? 'The courier did not return a shipment id.'),
                $created
            );
        }

        try {
            $assigned = $this->request('POST', '/courier/assign/awb', [
                'shipment_id' => $shipmentId,
                'courier_id' => $courier['channel_code'],
            ]);
        } catch (\Throwable $exception) {
            return ShipmentBooking::failed(
                'The parcel was created but no AWB could be assigned: ' . $exception->getMessage(),
                $created
            );
        }

        $awbData = $assigned['response']['data'] ?? [];
        $awb = $awbData['awb_code'] ?? null;

        if ($awb === null) {
            return ShipmentBooking::failed(
                (string) ($assigned['message'] ?? 'The courier did not return an AWB.'),
                $assigned
            );
        }

        return new ShipmentBooking(
            success: true,
            awbNumber: (string) $awb,
            courierShipmentId: (string) $shipmentId,
            labelUrl: null,
            courierCharge: isset($awbData['freight_charges'])
                ? Money::fromDecimal((string) $awbData['freight_charges'])
                : null,
            estimatedDeliveryDate: isset($awbData['etd'])
                ? date('Y-m-d', strtotime((string) $awbData['etd']))
                : null,
            raw: ['create' => $created, 'awb' => $assigned],
        );
    }

    public function label(array $courier, string $awbNumber): ?string
    {
        try {
            $response = $this->request('POST', '/courier/generate/label', ['awbs' => [$awbNumber]]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Shiprocket label generation failed', [
                'awb' => $awbNumber,
                'reason' => $exception->getMessage(),
            ], 'delivery');

            return null;
        }

        return isset($response['label_url']) ? (string) $response['label_url'] : null;
    }

    public function schedulePickup(array $courier, array $awbNumbers, string $pickupDate, array $contact): array
    {
        try {
            $response = $this->request('POST', '/courier/generate/pickup', [
                'shipment_id' => $awbNumbers,
                'pickup_date' => [$pickupDate],
            ]);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'reference' => null,
                'message' => $exception->getMessage(),
                'raw' => [],
            ];
        }

        return [
            'success' => true,
            'reference' => isset($response['pickup_token_number'])
                ? (string) $response['pickup_token_number']
                : null,
            'message' => (string) ($response['pickup_status'] ?? 'Pickup requested.'),
            'raw' => $response,
        ];
    }

    public function track(array $courier, string $awbNumber): array
    {
        try {
            $response = $this->request('GET', '/courier/track/awb/' . rawurlencode($awbNumber));
        } catch (\Throwable $exception) {
            $this->logger->warning('Shiprocket tracking lookup failed', [
                'awb' => $awbNumber,
                'reason' => $exception->getMessage(),
            ], 'delivery');

            return [];
        }

        $activities = $response['tracking_data']['shipment_track_activities'] ?? [];

        if (!is_array($activities)) {
            return [];
        }

        $updates = [];

        // Shiprocket returns newest first; the rest of the platform stores
        // oldest first so a timeline reads downwards.
        foreach (array_reverse($activities) as $index => $activity) {
            $updates[] = new TrackingUpdate(
                status: $this->normaliseStatus((string) ($activity['sr-status'] ?? $activity['status'] ?? '')),
                title: (string) ($activity['activity'] ?? 'Update'),
                description: $activity['activity'] ?? null,
                location: $activity['location'] ?? null,
                occurredAt: date('Y-m-d H:i:s', strtotime((string) ($activity['date'] ?? 'now'))),
                courierEventId: $awbNumber . ':' . md5((string) ($activity['date'] ?? '') . (string) ($activity['activity'] ?? '')),
                eventCode: isset($activity['sr-status']) ? (string) $activity['sr-status'] : null,
                raw: is_array($activity) ? $activity : [],
            );
        }

        return $updates;
    }

    public function cancel(array $courier, string $awbNumber): array
    {
        try {
            $this->request('POST', '/orders/cancel/shipment/awbs', ['awbs' => [$awbNumber]]);

            return ['success' => true, 'message' => 'Shipment cancelled with the courier.'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    public function parseWebhook(string $rawBody, string $signature): ?array
    {
        if ($this->webhookSecret === '') {
            throw new \RuntimeException(
                'SHIPROCKET_WEBHOOK_SECRET is not configured; tracking webhooks cannot be verified.'
            );
        }

        // Shiprocket sends the configured token verbatim rather than an HMAC.
        // Compared with hash_equals anyway: the value is attacker-supplied, and
        // a timing-safe comparison costs nothing.
        if ($signature === '' || !hash_equals($this->webhookSecret, $signature)) {
            $this->logger->warning('Shiprocket webhook token mismatch', [
                'body_length' => strlen($rawBody),
            ], 'delivery');

            return null;
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return null;
        }

        $awb = $payload['awb'] ?? ($payload['awb_code'] ?? null);

        if ($awb === null) {
            return null;
        }

        $status = $this->normaliseStatus((string) ($payload['current_status'] ?? $payload['status'] ?? ''));
        $occurredAt = date('Y-m-d H:i:s', strtotime((string) ($payload['current_timestamp'] ?? 'now')));

        return [
            'awb' => (string) $awb,
            'updates' => [
                new TrackingUpdate(
                    status: $status,
                    title: (string) ($payload['current_status'] ?? 'Tracking update'),
                    description: $payload['current_status_description'] ?? null,
                    location: $payload['location'] ?? ($payload['current_location'] ?? null),
                    occurredAt: $occurredAt,
                    courierEventId: (string) $awb . ':' . md5($occurredAt . $status),
                    eventCode: isset($payload['status_code']) ? (string) $payload['status_code'] : null,
                    raw: $payload,
                ),
            ],
        ];
    }

    public function manifest(array $courier, array $awbNumbers): ?string
    {
        try {
            $response = $this->request('POST', '/manifests/generate', ['shipment_id' => $awbNumbers]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Shiprocket manifest generation failed', [
                'reason' => $exception->getMessage(),
            ], 'delivery');

            return null;
        }

        return isset($response['manifest_url']) ? (string) $response['manifest_url'] : null;
    }

    /**
     * Maps a courier's own scan vocabulary onto the platform's.
     *
     * Every courier invents its own codes. Keeping the translation in one place
     * means a new aggregator changes this method and nothing else; letting raw
     * codes through would put the problem in every query and report instead.
     */
    private function normaliseStatus(string $raw): string
    {
        $value = strtolower(trim($raw));

        return match (true) {
            str_contains($value, 'delivered') && str_contains($value, 'rto') => TrackingUpdate::RTO_DELIVERED,
            str_contains($value, 'rto') => TrackingUpdate::RTO_INITIATED,
            str_contains($value, 'delivered') => TrackingUpdate::DELIVERED,
            str_contains($value, 'out for delivery'), $value === 'ofd' => TrackingUpdate::OUT_FOR_DELIVERY,
            str_contains($value, 'undelivered'),
            str_contains($value, 'failed'),
            str_contains($value, 'attempt') => TrackingUpdate::FAILED_DELIVERY,
            str_contains($value, 'picked') => TrackingUpdate::PICKED_UP,
            str_contains($value, 'lost'), str_contains($value, 'damaged') => TrackingUpdate::LOST,
            str_contains($value, 'cancel') => TrackingUpdate::CANCELLED,
            str_contains($value, 'transit'),
            str_contains($value, 'shipped'),
            str_contains($value, 'dispatch') => TrackingUpdate::IN_TRANSIT,
            default => TrackingUpdate::PENDING,
        };
    }

    private function authenticate(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $cached = $this->settings->value(self::TOKEN_SETTING);
        $expires = $this->settings->value(self::TOKEN_EXPIRY_SETTING);

        if ($cached !== null && $cached !== '' && $expires !== null && (int) $expires > time() + 3600) {
            $this->token = $cached;

            return $cached;
        }

        $handle = curl_init(self::API_BASE . '/auth/login');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['email' => $this->email, 'password' => $this->password]),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = json_decode((string) $raw, true);

        if ($status >= 400 || !is_array($decoded) || !isset($decoded['token'])) {
            throw new \RuntimeException('Could not authenticate with Shiprocket.');
        }

        $this->token = (string) $decoded['token'];

        // Tokens last ten days; cached for nine to leave room for clock drift.
        $this->settings->put(self::TOKEN_SETTING, $this->token);
        $this->settings->put(self::TOKEN_EXPIRY_SETTING, (string) (time() + (9 * 86400)));

        return $this->token;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = [], bool $isRetry = false): array
    {
        $handle = curl_init(self::API_BASE . $path);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->authenticate(),
            ],
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
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
            throw new \RuntimeException('Could not reach the courier: ' . $error);
        }

        // A cached token can expire early if it was revoked. One retry with a
        // fresh token, then give up — retrying forever on a bad password would
        // lock the account.
        if ($status === 401 && !$isRetry) {
            $this->token = null;
            $this->settings->put(self::TOKEN_EXPIRY_SETTING, '0');

            return $this->request($method, $path, $body, true);
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('The courier returned an unreadable response.');
        }

        if ($status >= 400) {
            $message = $decoded['message'] ?? 'The courier rejected the request.';

            $this->logger->error('Shiprocket returned an error', [
                'path' => $path,
                'http_status' => $status,
                'message' => $message,
            ], 'delivery');

            throw new \RuntimeException((string) $message);
        }

        return $decoded;
    }
}
