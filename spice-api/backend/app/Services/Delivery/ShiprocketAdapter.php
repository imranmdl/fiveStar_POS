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

    private const USER_AGENT = 'FiveStarSpices-Store/1.0 (+https://fivestarspices.com)';
    private const TOKEN_SETTING = 'shiprocket_token';
    private const TOKEN_EXPIRY_SETTING = 'shiprocket_token_expires';

    private ?string $token = null;

    /** @var array{name: string, pincode: string}|null */
    private ?array $pickup = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $courierOptions = [];

    public function __construct(
        private readonly string $email,
        private readonly string $password,
        private readonly string $webhookSecret,
        private readonly string $pickupLocationName,
        private readonly SettingRepository $settings,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 25,
        private readonly string $apiBase = self::API_BASE,
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
        try {
            $options = $this->availableCouriers(
                $parcel->destinationPincode,
                round($parcel->chargeableWeightGrams() / 1000, 3),
                (string) $parcel->declaredValue->toDecimal()
            );
        } catch (\Throwable $exception) {
            // A rate lookup failing must not stop a parcel being booked. The
            // caller falls back to the negotiated rate card.
            $this->logger->warning('Shiprocket serviceability lookup failed; falling back to the rate card', [
                'courier' => $courier['code'],
                'reason' => $exception->getMessage(),
            ], 'delivery');

            return null;
        }

        $option = $this->matchCourier($courier, $options);

        if ($option !== null) {
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

        // Shiprocket answered and this courier is not on its list for the
        // route, so it cannot be booked — say so rather than letting the
        // static rate card make it look available (and get auto-selected).
        return CourierQuote::ineligible(
            (int) $courier['id'],
            (string) $courier['code'],
            (string) $courier['name'],
            [$options === []
                ? 'Shiprocket has no courier for this pincode'
                : 'Not offered by your Shiprocket account for this pincode'],
        );
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
            'pickup_location' => $this->pickup()['name'],
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
            // ShipmentService adds "The courier could not accept this parcel:".
            return ShipmentBooking::failed($exception->getMessage());
        }

        $shipmentId = $created['shipment_id'] ?? null;

        if ($shipmentId === null) {
            return ShipmentBooking::failed(
                (string) ($created['message'] ?? 'The courier did not return a shipment id.'),
                $created
            );
        }

        // Shiprocket's courier ids are per account, so the one to ask for is
        // looked up live: the option on this route whose name matches the
        // courier staff chose (Delhivery, Blue Dart…). Booking a courier
        // Shiprocket does not offer here fails with the list of those it does.
        $assignBody = ['shipment_id' => $shipmentId];

        try {
            $options = $this->availableCouriers(
                (string) $order['ship_pincode'],
                round($parcel->chargeableWeightGrams() / 1000, 3),
                (string) $order['grand_total']
            );
        } catch (\Throwable $exception) {
            return ShipmentBooking::failed(
                'The parcel was created in Shiprocket, but its courier list could not be read: ' . $exception->getMessage(),
                $created
            );
        }

        $match = $this->matchCourier($courier, $options);

        if ($match === null) {
            return ShipmentBooking::failed($this->notOfferedMessage($courier, (string) $order['ship_pincode'], $options), $created);
        }

        $assignBody['courier_id'] = (int) $match['courier_company_id'];

        try {
            $assigned = $this->request('POST', '/courier/assign/awb', $assignBody);
        } catch (\Throwable $exception) {
            return ShipmentBooking::failed(
                'The parcel was created but no AWB could be assigned: ' . $exception->getMessage(),
                $created
            );
        }

        $awbData = $assigned['response']['data'] ?? [];
        $awb = $awbData['awb_code'] ?? null;

        if ($awb === null || $awb === '') {
            // Shiprocket answers a refused assignment with HTTP 200 and the
            // reason tucked inside the response (wallet balance, KYC,
            // serviceability…). Show that reason, not a generic line.
            $reason = $awbData['awb_assign_error']
                ?? ($assigned['response']['message'] ?? null)
                ?? ($assigned['message'] ?? null)
                ?? null;

            $this->logger->error('Shiprocket did not assign an AWB', [
                'order_number' => $order['order_number'],
                'shipment_id' => $shipmentId,
                'courier_id' => $assignBody['courier_id'],
                'response' => $assigned,
            ], 'delivery');

            return ShipmentBooking::failed(
                $reason !== null && $reason !== ''
                    ? sprintf('Shiprocket did not assign %s: %s', (string) ($match['courier_name'] ?? $courier['name']), (string) $reason)
                    : sprintf('Shiprocket did not assign an AWB for %s (no reason given). Check the order in the Shiprocket panel.', (string) ($match['courier_name'] ?? $courier['name'])),
                ['create' => $created, 'awb' => $assigned]
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

    /**
     * The Shiprocket pickup address parcels leave from: the one named in
     * SHIPROCKET_PICKUP_LOCATION, or — when that name does not exist and the
     * account has exactly one pickup address — that one, so a wrong or unset
     * name ("Primary") doesn't stop every booking. Its pincode drives rates
     * and serviceability. Falls back to the configured name and the
     * pickup_pincode setting if the addresses can't be read.
     *
     * @return array{name: string, pincode: string}
     */
    private function pickup(): array
    {
        if ($this->pickup !== null) {
            return $this->pickup;
        }

        $fallback = [
            'name' => $this->pickupLocationName,
            'pincode' => (string) ($this->settings->value('pickup_pincode') ?? ''),
        ];

        try {
            $response = $this->request('GET', '/settings/company/pickup');
            $addresses = $response['data']['shipping_address'] ?? ($response['data']['data'] ?? []);
            $addresses = is_array($addresses) ? array_values(array_filter($addresses, 'is_array')) : [];

            foreach ($addresses as $address) {
                if (strcasecmp((string) ($address['pickup_location'] ?? ''), $this->pickupLocationName) === 0) {
                    return $this->pickup = [
                        'name' => (string) $address['pickup_location'],
                        'pincode' => (string) ($address['pin_code'] ?? $fallback['pincode']),
                    ];
                }
            }

            if (count($addresses) === 1) {
                $this->logger->warning('SHIPROCKET_PICKUP_LOCATION does not match; using the only pickup address on the account', [
                    'configured' => $this->pickupLocationName,
                    'using' => $addresses[0]['pickup_location'] ?? '',
                ], 'delivery');

                return $this->pickup = [
                    'name' => (string) ($addresses[0]['pickup_location'] ?? $this->pickupLocationName),
                    'pincode' => (string) ($addresses[0]['pin_code'] ?? $fallback['pincode']),
                ];
            }

            $this->logger->warning('Shiprocket pickup location not found', [
                'configured' => $this->pickupLocationName,
                'available' => array_map(static fn (array $a): string => (string) ($a['pickup_location'] ?? ''), $addresses),
            ], 'delivery');
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not read Shiprocket pickup addresses; using configured values', [
                'reason' => $exception->getMessage(),
            ], 'delivery');
        }

        return $this->pickup = $fallback;
    }

    private function pickupPincode(): string
    {
        return $this->pickup()['pincode'];
    }

    /**
     * Couriers Shiprocket offers on this account from the pickup address to
     * $deliveryPincode, as Shiprocket lists them (id, name, rate, ETA).
     * Cached per destination for the request, since quoting asks once per
     * courier.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableCouriers(string $deliveryPincode, float $weightKg, string $declaredValue): array
    {
        $pickup = $this->pickupPincode();

        if ($pickup === '') {
            throw new \RuntimeException('No pickup pincode is known — check SHIPROCKET_PICKUP_LOCATION.');
        }

        $key = $pickup . '|' . $deliveryPincode . '|' . $weightKg;

        if (isset($this->courierOptions[$key])) {
            return $this->courierOptions[$key];
        }

        $response = $this->request('GET', '/courier/serviceability/?' . http_build_query([
            'pickup_postcode' => $pickup,
            'delivery_postcode' => $deliveryPincode,
            // BR-004 makes every order prepaid, so COD is never requested.
            'cod' => 0,
            'weight' => max(0.05, $weightKg),
            'declared_value' => $declaredValue,
        ]));

        $options = $response['data']['available_courier_companies'] ?? [];

        return $this->courierOptions[$key] = is_array($options) ? array_values(array_filter($options, 'is_array')) : [];
    }

    /**
     * The Shiprocket option for one of our couriers: an exact id match on
     * channel_code if that is a real Shiprocket id, otherwise the cheapest
     * option whose name carries the courier's brand ("Delhivery Surface",
     * "Blue Dart Air" …).
     *
     * @param array<string, mixed>             $courier
     * @param array<int, array<string, mixed>> $options
     *
     * @return array<string, mixed>|null
     */
    private function matchCourier(array $courier, array $options): ?array
    {
        $channel = (string) ($courier['channel_code'] ?? '');

        foreach ($options as $option) {
            if ($channel !== '' && (string) ($option['courier_company_id'] ?? '') === $channel) {
                return $option;
            }
        }

        $brand = self::brandKey((string) ($courier['name'] ?? $courier['code'] ?? ''));

        if ($brand === '') {
            return null;
        }

        $best = null;

        foreach ($options as $option) {
            if (!str_contains(self::brandKey((string) ($option['courier_name'] ?? '')), $brand)) {
                continue;
            }

            if ($best === null || (float) ($option['rate'] ?? INF) < (float) ($best['rate'] ?? INF)) {
                $best = $option;
            }
        }

        return $best;
    }

    /** "Blue Dart" / "BLUEDART" / "Blue Dart Air" all reduce to "bluedart". */
    private static function brandKey(string $name): string
    {
        return (string) preg_replace('/[^a-z]/', '', strtolower($name));
    }

    /**
     * @param array<string, mixed>             $courier
     * @param array<int, array<string, mixed>> $options
     */
    private function notOfferedMessage(array $courier, string $pincode, array $options): string
    {
        if ($options === []) {
            return sprintf(
                'Shiprocket has no courier for pincode %s from your pickup address (%s). Check the pincode, or book this order manually.',
                $pincode,
                $this->pickupPincode()
            );
        }

        $names = [];

        foreach ($options as $option) {
            $names[] = sprintf('%s (₹%s)', (string) ($option['courier_name'] ?? '?'), (string) ($option['rate'] ?? '?'));
        }

        return sprintf(
            '%s is not available on your Shiprocket account for pincode %s. Shiprocket offers: %s. Choose another courier.',
            (string) $courier['name'],
            $pincode,
            implode(', ', array_slice(array_unique($names), 0, 8))
        );
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

        $handle = curl_init(rtrim($this->apiBase, '/') . '/auth/login');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            // Shiprocket's firewall answers a bare 403 to requests that carry
            // no User-Agent (PHP's curl sends none by default).
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_POSTFIELDS => json_encode(['email' => $this->email, 'password' => $this->password]),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        $decoded = json_decode((string) $raw, true);

        if ($status >= 400 || !is_array($decoded) || !isset($decoded['token'])) {
            $reason = $this->loginFailureReason($status, $raw === false ? null : (string) $raw, $curlError);

            $this->logger->error('Shiprocket login failed', [
                'http_status' => $status,
                'email' => $this->maskedEmail(),
                'shiprocket_message' => is_array($decoded) ? ($decoded['message'] ?? null) : null,
                // First bytes of a non-JSON reply (e.g. a firewall page) — never contains the password.
                'body_start' => is_array($decoded) || $raw === false ? null : substr(preg_replace('/\s+/', ' ', strip_tags((string) $raw)) ?? '', 0, 200),
                'curl_error' => $curlError !== '' ? $curlError : null,
            ], 'delivery');

            throw new \RuntimeException('Could not sign in to Shiprocket: ' . $reason);
        }

        $this->token = (string) $decoded['token'];

        // Tokens last ten days; cached for nine to leave room for clock drift.
        $this->settings->put(self::TOKEN_SETTING, $this->token);
        $this->settings->put(self::TOKEN_EXPIRY_SETTING, (string) (time() + (9 * 86400)));

        return $this->token;
    }

    /** Plain-words reason a Shiprocket login failed, safe to show staff. */
    private function loginFailureReason(int $status, ?string $raw, string $curlError): string
    {
        if ($raw === null || $status === 0) {
            return 'Shiprocket could not be reached' . ($curlError !== '' ? ' (' . $curlError . ')' : '') . '. Try again in a minute.';
        }

        $decoded = json_decode($raw, true);
        $message = is_array($decoded) && isset($decoded['message']) ? trim((string) $decoded['message']) : '';
        $said = $message !== '' ? ' Shiprocket said: "' . $message . '".' : '';

        if (!str_contains($this->email, '@')) {
            return 'SHIPROCKET_EMAIL "' . $this->email . '" is not a full email address.' . $said;
        }

        // A 403 with no Shiprocket JSON (an HTML/blank page) comes from the
        // firewall in front of Shiprocket's API, not from the password check:
        // this server's IP address is being blocked.
        if ($status === 403 && !is_array($decoded)) {
            $ip = $this->outboundIp();

            return 'Shiprocket\'s firewall blocked this server (403 without a Shiprocket reply) — this is about the server\'s '
                . 'IP address, not the email or password.'
                . ($ip !== null ? ' This server reaches the internet from IP ' . $ip . '.' : '')
                . ' Ask Shiprocket support to allow this IP for API access (or add it under the API user\'s allowed IPs), '
                . 'or use a fixed outbound IP for the server.';
        }

        return match (true) {
            $status === 403 => 'Shiprocket refused this login (403). Use the API user from Shiprocket → Settings → API '
                . '→ Create an API User — not your normal dashboard login — in SHIPROCKET_EMAIL / SHIPROCKET_PASSWORD.' . $said,
            $status === 400, $status === 401, $status === 422 => 'Wrong email or password for ' . $this->maskedEmail()
                . ' (' . $status . '). Check SHIPROCKET_EMAIL / SHIPROCKET_PASSWORD (the API user\'s).' . $said,
            $status === 429 => 'Too many login attempts; Shiprocket asks to wait a few minutes.' . $said,
            default => 'Shiprocket answered ' . $status . '.' . $said,
        };
    }

    /** The public IP this server's requests come from (for Shiprocket's allow-list), or null. */
    private function outboundIp(): ?string
    {
        $handle = curl_init('https://api.ipify.org');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
        $ip = trim((string) curl_exec($handle));
        curl_close($handle);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function maskedEmail(): string
    {
        $at = strpos($this->email, '@');

        return $at === false || $at < 2
            ? $this->email
            : substr($this->email, 0, 2) . str_repeat('*', max(1, $at - 2)) . substr($this->email, $at);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = [], bool $isRetry = false): array
    {
        $handle = curl_init(rtrim($this->apiBase, '/') . $path);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->authenticate(),
            ],
            CURLOPT_USERAGENT => self::USER_AGENT,
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
            $message = (string) ($decoded['message'] ?? 'The courier rejected the request.');

            // Validation failures list the bad fields under "errors".
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $details = [];
                array_walk_recursive($decoded['errors'], static function ($v) use (&$details): void {
                    $details[] = (string) $v;
                });
                $details = array_unique(array_filter($details));

                if ($details !== []) {
                    $message = rtrim($message, '. ') . ': ' . implode(' ', $details);
                }
            }

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
