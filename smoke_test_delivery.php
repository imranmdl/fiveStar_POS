<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for delivery and courier selection (Phase 6).
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_delivery.php
 *
 * Takes a paid order through automatic courier selection, booking, labelling,
 * pickup, manifest and a full tracking sequence, and checks that courier scans
 * drive the order status rather than being written to it blindly.
 *
 * Requires COURIER_DRIVER=sandbox, PAYMENT_DRIVER=sandbox, APP_ENV=local,
 * OTP_EXPOSE_IN_RESPONSE=true, migrations and seeds applied, and an
 * administrator account.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$baseUrl = rtrim((string) Env::get('APP_URL', 'http://127.0.0.1:8080'), '/') . '/api/v1';
$paymentSecret = (string) Env::get('SANDBOX_PAYMENT_SECRET', 'sandbox-local-secret-change-me');
$courierSecret = (string) Env::get('SANDBOX_COURIER_SECRET', 'sandbox-courier-secret-change-me');
$passed = 0;
$failed = 0;

function call(string $method, string $url, array $body = [], ?string $token = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];

    if ($body !== []) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => json_decode((string) $raw, true) ?? []];
}

function callRaw(string $url, string $rawBody, array $extraHeaders = []): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(
            ['Accept: application/json', 'Content-Type: application/json'],
            $extraHeaders
        ),
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => json_decode((string) $raw, true) ?? []];
}

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        ++$passed;
        printf("  PASS  %s\n", $label);

        return;
    }

    ++$failed;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : ' -> ' . $detail);
}

function checkSame(string $label, mixed $expected, mixed $actual): void
{
    check($label, $expected == $actual, sprintf('expected %s, got %s',
        var_export($expected, true), var_export($actual, true)));
}

function prompt(string $question, bool $hidden = false): string
{
    echo $question;

    if (!$hidden) {
        return trim((string) fgets(STDIN));
    }

    $stty = DIRECTORY_SEPARATOR !== '\\' && shell_exec('which stty 2>/dev/null') !== null;

    if ($stty) {
        shell_exec('stty -echo');
    }

    $value = trim((string) fgets(STDIN));

    if ($stty) {
        shell_exec('stty echo');
        echo "\n";
    }

    return $value;
}

/** Registers, carts, pays and packs an order, returning its uuid. */
function paidOrder(string $baseUrl, string $adminToken, string $paymentSecret, string $pincode = '560001'): array
{
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $response = call('POST', $baseUrl . '/auth/register', [
        'full_name' => 'Delivery Test',
        'mobile' => $mobile,
        'password' => 'DeliveryPass' . random_int(1000, 9999),
    ]);

    if ($response['status'] !== 201) {
        throw new RuntimeException('Registration failed: ' . json_encode($response['body']));
    }

    $verified = call('POST', $baseUrl . '/auth/register/verify', [
        'mobile' => $mobile,
        'otp' => $response['body']['data']['verification']['debug_otp'],
        'reference_token' => $response['body']['data']['verification']['reference_token'],
    ]);
    $token = $verified['body']['data']['tokens']['access_token'];

    $address = call('POST', $baseUrl . '/addresses', [
        'contact_name' => 'Delivery Tester',
        'contact_mobile' => $mobile,
        'address_line1' => '9 Residency Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => $pincode,
    ], $token);

    if ($address['status'] !== 201) {
        throw new RuntimeException('Address failed: ' . json_encode($address['body']));
    }

    $addressUuid = $address['body']['data']['address']['uuid'];

    $product = call('GET', $baseUrl . '/products/california-almonds');
    $variant = $product['body']['data']['product']['variants'][0];
    call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $variant['uuid'], 'quantity' => 2], $token);

    $placed = call('POST', $baseUrl . '/checkout/place', ['address_uuid' => $addressUuid], $token);

    if ($placed['status'] !== 201) {
        throw new RuntimeException('Placement failed: ' . json_encode($placed['body']));
    }

    $orderUuid = $placed['body']['data']['order']['uuid'];

    call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp', [
        'otp' => $placed['body']['data']['otp']['debug_otp'],
        'reference_token' => $placed['body']['data']['otp']['reference_token'],
    ], $token);

    $payment = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
    $gatewayOrderId = $payment['body']['data']['payment']['gateway_order_id'];
    $amount = $payment['body']['data']['payment']['amount'];

    $webhookPayload = [
        'sandbox_order_id' => $gatewayOrderId,
        'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
        'sandbox_status' => 'captured',
        'sandbox_amount' => (int) round($amount * 100),
    ];
    $rawBody = json_encode($webhookPayload, JSON_UNESCAPED_SLASHES);
    callRaw($baseUrl . '/webhooks/payment', $rawBody, [
        'X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $paymentSecret),
    ]);

    call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status', ['status' => 'packed'], $adminToken);

    return ['uuid' => $orderUuid, 'token' => $token, 'mobile' => $mobile];
}

echo "Delivery and courier selection smoke test\n";
printf("Base URL: %s\n\n", $baseUrl);

$adminIdentifier = prompt('Administrator mobile or email: ');
$adminPassword = prompt('Administrator password: ', true);

$response = call('POST', $baseUrl . '/auth/login',
    ['identifier' => $adminIdentifier, 'password' => $adminPassword]);

if ($response['status'] !== 200) {
    fwrite(STDERR, "\nAdministrator login failed: " . json_encode($response['body']) . "\n");
    exit(1);
}

$adminToken = $response['body']['data']['tokens']['access_token'];
check('administrator signed in', true);

// -----------------------------------------------------------------------
// Courier configuration
// -----------------------------------------------------------------------
echo "\n-- Courier configuration --\n";

$response = call('GET', $baseUrl . '/admin/couriers', [], $adminToken);
$couriers = $response['body']['data']['couriers'] ?? [];
check('couriers are configured', count($couriers) >= 5, (string) count($couriers));

$codes = array_column($couriers, 'code');
check('the seeded carriers are present',
    in_array('DELHIVERY', $codes, true) && in_array('BLUEDART', $codes, true),
    implode(',', $codes));

$response = call('GET', $baseUrl . '/admin/couriers/BLUEDART', [], $adminToken);
check('a courier exposes its serviceability rules',
    count($response['body']['data']['serviceability'] ?? []) > 0);
check('...and its rate cards',
    count($response['body']['data']['rate_cards'] ?? []) > 0);

// -----------------------------------------------------------------------
// BR-007 selection
// -----------------------------------------------------------------------
echo "\n-- BR-007: automatic courier selection --\n";

$order = paidOrder($baseUrl, $adminToken, $paymentSecret, '560001');
$orderUuid = $order['uuid'];

$response = call('GET', $baseUrl . '/admin/orders/' . $orderUuid . '/courier-options', [], $adminToken);
$options = $response['body']['data'];
check('courier options load', $response['status'] === 200, json_encode($response['body']));
check('a courier was selected', ($options['selected'] ?? null) !== null);
check('several couriers were considered', ($options['considered'] ?? 0) >= 5, (string) ($options['considered'] ?? 0));
check('more than one was eligible', ($options['eligible'] ?? 0) >= 2, (string) ($options['eligible'] ?? 0));
check('the decision is explained in words', strlen((string) ($options['reason'] ?? '')) > 40,
    (string) ($options['reason'] ?? ''));
check('the parcel has a chargeable weight',
    ($options['parcel']['chargeable_weight_grams'] ?? 0) > 0);
check('chargeable weight is at least the actual weight',
    ($options['parcel']['chargeable_weight_grams'] ?? 0) >= ($options['parcel']['actual_weight_grams'] ?? 1));
check('every candidate is listed, eligible or not',
    count($options['candidates'] ?? []) === ($options['considered'] ?? 0));

// Not asserted here: for a Bengaluru address every seeded courier may
// legitimately be eligible. The exclusion reasoning is checked below against a
// distant pincode, where couriers really do drop out.

// A same-city Bengaluru order is legitimately won outright by the local courier
// on both cost and speed, so it cannot show strategy differences. Use a distant
// destination where cost and speed genuinely pull apart.
$distantOrder = paidOrder($baseUrl, $adminToken, $paymentSecret, '110001');
$byStrategy = [];

foreach (['cheapest', 'fastest', 'balanced', 'reliable'] as $strategy) {
    $response = call('GET', $baseUrl . '/admin/orders/' . $distantOrder['uuid'] . '/courier-options?strategy=' . $strategy, [], $adminToken);
    $byStrategy[$strategy] = $response['body']['data']['selected']['courier_code'] ?? null;
}

check('each strategy returns a courier', count(array_filter($byStrategy)) === 4, json_encode($byStrategy));
check('strategies disagree when cost and speed genuinely pull apart',
    count(array_unique($byStrategy)) > 1, json_encode($byStrategy));
check('"fastest" picks the express carrier for a distant delivery',
    $byStrategy['fastest'] === 'BLUEDART', json_encode($byStrategy));

// A local courier cannot serve Delhi, so it must appear as ineligible there.
$response = call('GET', $baseUrl . '/admin/orders/' . $distantOrder['uuid'] . '/courier-options', [], $adminToken);
$localExcluded = false;

foreach ($response['body']['data']['candidates'] as $candidate) {
    if ($candidate['courier_code'] === 'SHADOWFAX') {
        $localExcluded = !$candidate['is_eligible'] && $candidate['ineligibility_reasons'] !== [];
    }
}

check('a same-city-only courier is excluded from a distant delivery, with a reason',
    $localExcluded);
check('"cheapest" really is the cheapest', (function () use ($baseUrl, $adminToken, $distantOrder): bool {
    $response = call('GET', $baseUrl . '/admin/orders/' . $distantOrder['uuid'] . '/courier-options?strategy=cheapest', [], $adminToken);
    $data = $response['body']['data'];
    $selectedCost = (float) $data['selected']['cost'];

    foreach ($data['candidates'] as $candidate) {
        if ($candidate['is_eligible'] && (float) $candidate['cost'] < $selectedCost) {
            return false;
        }
    }

    return true;
})());

// -----------------------------------------------------------------------
// Booking
// -----------------------------------------------------------------------
echo "\n-- Booking --\n";

$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/ship', [], $adminToken);
check('the parcel is booked', $response['status'] === 201, json_encode($response['body']));
$shipment = $response['body']['data'];
$shipmentUuid = $shipment['uuid'];

check('an AWB was issued', is_string($shipment['awb_number']) && $shipment['awb_number'] !== '');
check('a shipment number was issued', str_starts_with((string) $shipment['shipment_number'], 'SHP'));
checkSame('the shipment is booked', 'booked', $shipment['status']);
check('a tracking URL was built', str_contains((string) $shipment['tracking_url'], (string) $shipment['awb_number']));
check('the chargeable weight was recorded', $shipment['chargeable_weight_grams'] > 0);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $order['token']);
checkSame('the order moved to assigned', 'assigned', $response['body']['data']['order']['status']);
check('the order carries the tracking number',
    $response['body']['data']['shipping']['tracking_number'] === $shipment['awb_number']);

// Double booking must be refused.
$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/ship', [], $adminToken);
check('the same order cannot be booked twice', $response['status'] === 409);

// The BR-007 audit trail must survive on the shipment.
$response = call('GET', $baseUrl . '/admin/shipments/' . $shipmentUuid, [], $adminToken);
$selection = $response['body']['data']['selection'] ?? null;
check('the selection decision is stored against the shipment', $selection !== null);
check('...with the candidates that were considered',
    is_array($selection['candidates'] ?? null) && count($selection['candidates']) >= 5);
check('...and was not a manual override', (int) ($selection['was_manual_override'] ?? 1) === 0);

// -----------------------------------------------------------------------
// BR-005 still applies
// -----------------------------------------------------------------------
echo "\n-- BR-005 applies to booking too --\n";

$mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$unpaid = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Unpaid Tester',
    'mobile' => $mobile,
    'password' => 'UnpaidPass1234',
]);
$verified = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => $unpaid['body']['data']['verification']['debug_otp'],
    'reference_token' => $unpaid['body']['data']['verification']['reference_token'],
]);
$unpaidToken = $verified['body']['data']['tokens']['access_token'];

$address = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Unpaid Tester',
    'contact_mobile' => $mobile,
    'address_line1' => '3 Brigade Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $unpaidToken);

$product = call('GET', $baseUrl . '/products/california-almonds');
call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $product['body']['data']['product']['variants'][0]['uuid'],
    'quantity' => 1,
], $unpaidToken);

$placed = call('POST', $baseUrl . '/checkout/place', [
    'address_uuid' => $address['body']['data']['address']['uuid'],
], $unpaidToken);
$unpaidUuid = $placed['body']['data']['order']['uuid'];

$response = call('POST', $baseUrl . '/admin/orders/' . $unpaidUuid . '/ship', [], $adminToken);
check('an unpaid order cannot be handed to a courier', $response['status'] === 409,
    json_encode($response['body']['message'] ?? ''));
check('...and the refusal names the payment status',
    str_contains(strtolower((string) ($response['body']['message'] ?? '')), 'paid'));

// -----------------------------------------------------------------------
// Label, pickup, manifest
// -----------------------------------------------------------------------
echo "\n-- Label, pickup and manifest --\n";

$response = call('POST', $baseUrl . '/admin/shipments/' . $shipmentUuid . '/label', [], $adminToken);
check('a label is produced', $response['status'] === 200 && !empty($response['body']['data']['label_url']));

$response = call('POST', $baseUrl . '/admin/shipments/' . $shipmentUuid . '/label', [], $adminToken);
check('asking again returns the same label rather than a second one',
    ($response['body']['data']['already_generated'] ?? false) === true);

$courierCode = $shipment['courier_code'];

$response = call('POST', $baseUrl . '/admin/couriers/' . $courierCode . '/pickup',
    ['pickup_date' => date('Y-m-d', strtotime('+1 day'))], $adminToken);
check('a pickup is scheduled', $response['status'] === 201, json_encode($response['body']));
check('...covering at least one parcel', ($response['body']['data']['shipment_count'] ?? 0) >= 1);

$response = call('POST', $baseUrl . '/admin/couriers/' . $courierCode . '/pickup',
    ['pickup_date' => date('Y-m-d', strtotime('+2 day'))], $adminToken);
check('a second pickup with nothing waiting is refused', $response['status'] === 422);

$response = call('POST', $baseUrl . '/admin/couriers/' . $courierCode . '/manifest', [], $adminToken);
check('a manifest is generated', $response['status'] === 201, json_encode($response['body']));
check('...numbered', str_starts_with((string) ($response['body']['data']['manifest']['manifest_number'] ?? ''), 'MFT'));

// -----------------------------------------------------------------------
// Tracking
// -----------------------------------------------------------------------
echo "\n-- Tracking --\n";

$awb = $shipment['awb_number'];

$response = callRaw($baseUrl . '/webhooks/tracking',
    json_encode(['awb' => $awb, 'events' => [['status' => 'picked_up', 'title' => 'Picked up']]], JSON_UNESCAPED_SLASHES),
    ['X-Sandbox-Signature: wrong']);
check('an unsigned tracking webhook is acknowledged but not acted on',
    $response['status'] === 202 && ($response['body']['data']['processed'] ?? true) === false);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $order['token']);
checkSame('...and the order has not moved', 'assigned', $response['body']['data']['order']['status']);

$sequence = [
    ['picked_up', 'Picked up', 'Bengaluru', 'shipped'],
    ['in_transit', 'In transit', 'Bengaluru Hub', 'shipped'],
    ['out_for_delivery', 'Out for delivery', 'Destination', 'out_for_delivery'],
    ['delivered', 'Delivered', 'Destination', 'delivered'],
];

foreach ($sequence as $index => [$status, $title, $location, $expectedOrderStatus]) {
    $payload = [
        'awb' => $awb,
        'events' => [[
            'status' => $status,
            'title' => $title,
            'location' => $location,
            'occurred_at' => date('Y-m-d H:i:s', time() - (86400 - ($index * 3600))),
            'event_id' => $awb . ':seq:' . $index,
        ]],
    ];
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $response = callRaw($baseUrl . '/webhooks/tracking', $rawBody, [
        'X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $courierSecret),
    ]);

    check(sprintf('a signed "%s" scan is processed', $status),
        $response['status'] === 200, json_encode($response['body']));

    $orderResponse = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $order['token']);
    checkSame(sprintf('...moving the order to "%s"', $expectedOrderStatus),
        $expectedOrderStatus, $orderResponse['body']['data']['order']['status']);
}

// Replay is routine for couriers and must be a no-op.
$payload = [
    'awb' => $awb,
    'events' => [[
        'status' => 'delivered',
        'title' => 'Delivered',
        'location' => 'Destination',
        'occurred_at' => date('Y-m-d H:i:s', time() - (86400 - 10800)),
        'event_id' => $awb . ':seq:3',
    ]],
];
$rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
$response = callRaw($baseUrl . '/webhooks/tracking', $rawBody, [
    'X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $courierSecret),
]);
checkSame('a replayed scan is recognised as a duplicate', 'duplicate',
    $response['body']['data']['status'] ?? '');

// A late scan that contradicts the timeline must be recorded, not applied.
$payload = [
    'awb' => $awb,
    'events' => [[
        'status' => 'in_transit',
        'title' => 'In transit (late sync)',
        'location' => 'Somewhere',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_id' => $awb . ':late:1',
    ]],
];
$rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
$response = callRaw($baseUrl . '/webhooks/tracking', $rawBody, [
    'X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $courierSecret),
]);
check('an out-of-order scan is accepted without error', in_array($response['status'], [200, 202], true));

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $order['token']);
checkSame('...and does not drag a delivered order backwards', 'delivered',
    $response['body']['data']['order']['status']);

// -----------------------------------------------------------------------
// Customer view
// -----------------------------------------------------------------------
echo "\n-- Customer view --\n";

$response = call('GET', $baseUrl . '/orders/' . $orderUuid . '/shipments', [], $order['token']);
$customerShipments = $response['body']['data']['shipments'] ?? [];
check('the customer can see their shipment', count($customerShipments) === 1);
check('...with tracking events', count($customerShipments[0]['events'] ?? []) >= 4);
check('...and no courier cost', !array_key_exists('courier_charge', $customerShipments[0]),
    'what the merchant pays a courier is not the customer\'s business');
check('...and no label URL', !array_key_exists('label_url', $customerShipments[0]));

$stranger = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Nosy Stranger',
    'mobile' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
    'password' => 'StrangerPass99',
]);
$strangerVerified = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $stranger['body']['data']['user']['mobile'] ?? '',
    'otp' => $stranger['body']['data']['verification']['debug_otp'],
    'reference_token' => $stranger['body']['data']['verification']['reference_token'],
]);

if (isset($strangerVerified['body']['data']['tokens']['access_token'])) {
    $response = call('GET', $baseUrl . '/orders/' . $orderUuid . '/shipments', [],
        $strangerVerified['body']['data']['tokens']['access_token']);
    check('another customer cannot see it', $response['status'] === 404);
}

// -----------------------------------------------------------------------
// Staff reporting
// -----------------------------------------------------------------------
echo "\n-- Staff reporting --\n";

$response = call('GET', $baseUrl . '/admin/shipments', [], $adminToken);
check('staff can list shipments', $response['status'] === 200 && ($response['body']['meta']['total'] ?? 0) >= 1);

$response = call('GET', $baseUrl . '/admin/shipments?courier=' . $courierCode, [], $adminToken);
check('staff can filter by courier', $response['status'] === 200);

$response = call('GET', $baseUrl . '/admin/shipments?courier=NOPE', [], $adminToken);
check('an unknown courier filter is rejected', $response['status'] === 422);

$response = call('GET', $baseUrl . '/admin/couriers/performance', [], $adminToken);
check('courier performance loads', $response['status'] === 200);
check('...covering every courier', count($response['body']['data']['performance'] ?? []) >= 5);

$response = call('POST', $baseUrl . '/admin/couriers/recalculate-reliability',
    ['minimum_shipments' => 5], $adminToken);
check('reliability recalculation runs', $response['status'] === 200);
check('...and reports honestly that there is too little history',
    ($response['body']['data']['couriers_updated'] ?? -1) === 0,
    'one delivered parcel is not evidence of reliability');

// -----------------------------------------------------------------------
// Courier administration
// -----------------------------------------------------------------------
echo "\n-- Courier administration --\n";

$response = call('PATCH', $baseUrl . '/admin/couriers/DTDC', ['is_enabled' => 0], $adminToken);
check('disabling a courier without a reason is refused', $response['status'] === 422,
    'the next person needs to know whether it was a rate dispute or an outage');

$response = call('PATCH', $baseUrl . '/admin/couriers/DTDC',
    ['is_enabled' => 0, 'disabled_reason' => 'Rate contract under renegotiation'], $adminToken);
check('disabling with a reason succeeds', $response['status'] === 200, json_encode($response['body']));

$order2 = paidOrder($baseUrl, $adminToken, $paymentSecret, '560001');
$response = call('GET', $baseUrl . '/admin/orders/' . $order2['uuid'] . '/courier-options', [], $adminToken);
$disabledSeen = false;

foreach ($response['body']['data']['candidates'] as $candidate) {
    if ($candidate['courier_code'] === 'DTDC') {
        $disabledSeen = !$candidate['is_eligible']
            && str_contains(implode(' ', $candidate['ineligibility_reasons']), 'renegotiation');
    }
}

check('a disabled courier is excluded, quoting the reason', $disabledSeen);

// A manual override must be recorded as one.
$response = call('POST', $baseUrl . '/admin/orders/' . $order2['uuid'] . '/ship',
    ['courier_code' => 'BLUEDART'], $adminToken);
check('staff can override the courier choice', $response['status'] === 201, json_encode($response['body']));
checkSame('...and get the courier they asked for', 'BLUEDART', $response['body']['data']['courier_code']);

$response = call('GET', $baseUrl . '/admin/shipments/' . $response['body']['data']['uuid'], [], $adminToken);
checkSame('...recorded as a manual override', 1,
    (int) ($response['body']['data']['selection']['was_manual_override'] ?? 0));

call('PATCH', $baseUrl . '/admin/couriers/DTDC', ['is_enabled' => 1], $adminToken);

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: test orders and shipments remain for inspection.\n";

exit($failed === 0 ? 0 : 1);
