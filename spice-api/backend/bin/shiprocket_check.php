<?php

declare(strict_types=1);

/**
 * Checks a Shiprocket setup without shipping anything.
 *
 *   php bin/shiprocket_check.php              credentials, pickup, couriers
 *   php bin/shiprocket_check.php --pincode 560001 --weight 500
 *
 * WHY THIS EXISTS. The Shiprocket adapter has never run against the real API —
 * every test in this project used the sandbox. The first real booking is
 * therefore the riskiest single call in the system, and it creates a live
 * shipment with a real courier for a real customer.
 *
 * This makes every read-only call the adapter would make, in the same order,
 * and stops before anything is booked. If it passes, the remaining unknown is
 * small. If it fails, it fails on your terms rather than on a customer's order.
 *
 * NOTHING HERE CREATES A SHIPMENT. It logs in, reads the pickup locations, and
 * asks for serviceability. No order is created, no AWB is assigned, no pickup is
 * scheduled.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$applicationRoot = null;

for ($directory = __DIR__, $depth = 0; $depth < 6; ++$depth) {
    if (is_file($directory . '/bootstrap/autoload.php')) {
        $applicationRoot = $directory;

        break;
    }

    $parent = dirname($directory);

    if ($parent === $directory) {
        break;
    }

    $directory = $parent;
}

if ($applicationRoot === null) {
    fwrite(STDERR, "Cannot find the application. Run this from the backend folder.\n");
    exit(1);
}

define('APP_ROOT', $applicationRoot);
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(APP_ROOT . '/.env');

const API_BASE = 'https://apiv2.shiprocket.in/v1/external';

$options = getopt('', ['pincode::', 'weight::']);
$pincode = isset($options['pincode']) ? (string) $options['pincode'] : '560001';
$weight = isset($options['weight']) ? (float) $options['weight'] : 500.0;

$problems = [];

function heading(string $text): void
{
    printf("\n%s\n%s\n", $text, str_repeat('-', strlen($text)));
}

function ok(string $text): void
{
    printf("  OK     %s\n", $text);
}

function problem(string $text, string $fix = ''): void
{
    global $problems;
    $problems[] = $text;
    printf("  NEEDS  %s\n", $text);

    if ($fix !== '') {
        printf("         %s\n", str_replace("\n", "\n         ", $fix));
    }
}

function note(string $text): void
{
    printf("         %s\n", str_replace("\n", "\n         ", $text));
}

/**
 * @return array{status:int, body:array<string, mixed>, error:?string}
 */
function call(string $path, string $method = 'GET', ?array $payload = null, ?string $token = null): array
{
    $handle = curl_init(API_BASE . $path);
    $headers = ['Content-Type: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $curlOptions = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];

    if ($payload !== null) {
        $curlOptions[CURLOPT_POSTFIELDS] = json_encode($payload);
    }

    curl_setopt_array($handle, $curlOptions);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle) ?: null;
    curl_close($handle);

    return [
        'status' => $status,
        'body' => $raw === false ? [] : (json_decode((string) $raw, true) ?? []),
        'error' => $error,
    ];
}

echo "Shiprocket connection check\n";
printf("Testing a %.0fg parcel to pincode %s\n", $weight, $pincode);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
heading('Configuration');

$driver = (string) Env::get('COURIER_DRIVER', 'sandbox');

if ($driver === 'shiprocket') {
    ok('COURIER_DRIVER is shiprocket');
} else {
    problem(sprintf('COURIER_DRIVER is "%s". Bookings will still use the sandbox.', $driver),
        'Set COURIER_DRIVER=shiprocket in .env once this check passes.');
}

$email = (string) Env::get('SHIPROCKET_EMAIL', '');
$password = (string) Env::get('SHIPROCKET_PASSWORD', '');
$pickupName = (string) Env::get('SHIPROCKET_PICKUP_LOCATION', 'Primary');
$webhookSecret = (string) Env::get('SHIPROCKET_WEBHOOK_SECRET', '');

if ($email === '' || $password === '') {
    problem('SHIPROCKET_EMAIL and SHIPROCKET_PASSWORD are not both set.',
        "These are the API user's credentials from Settings → API → Configure,\n"
        . 'NOT your normal dashboard login.');

    echo "\nCannot continue without credentials.\n";
    exit(1);
}

ok(sprintf('credentials present for %s', $email));

if ($webhookSecret === '') {
    problem('SHIPROCKET_WEBHOOK_SECRET is empty.',
        "Without it, a tracking webhook cannot be told apart from anyone posting\n"
        . 'to your endpoint. Set the same value here and on the Shiprocket webhook page.');
} else {
    ok('webhook secret is set');
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------
heading('Authentication');

$login = call('/auth/login', 'POST', ['email' => $email, 'password' => $password]);

if ($login['error'] !== null) {
    problem('Could not reach Shiprocket: ' . $login['error'],
        'Check that this server can make outbound HTTPS requests.');

    echo "\nCannot continue.\n";
    exit(1);
}

$token = (string) ($login['body']['token'] ?? '');

if ($login['status'] !== 200 || $token === '') {
    problem(sprintf('Login failed (HTTP %d): %s',
        $login['status'],
        (string) ($login['body']['message'] ?? 'no message')));

    note("The API user is created separately from your dashboard login:\n"
        . "Shiprocket → Settings → API → Configure → Create an API User.\n"
        . 'That user has its own email and password, and that is what goes in .env.');

    echo "\nCannot continue.\n";
    exit(1);
}

ok('logged in and received a bearer token');
note('The adapter caches this token for ten days and refreshes it on expiry.');

// ---------------------------------------------------------------------------
// Pickup location
// ---------------------------------------------------------------------------
heading('Pickup location');

$pickups = call('/settings/company/pickup', 'GET', null, $token);

if ($pickups['status'] !== 200) {
    problem(sprintf('Could not read pickup locations (HTTP %d).', $pickups['status']));
} else {
    $locations = $pickups['body']['data']['shipping_address'] ?? [];
    $names = array_map(static fn (array $row): string => (string) ($row['pickup_location'] ?? ''), $locations);

    if ($names === []) {
        problem('No pickup location is registered.',
            "Shiprocket → Settings → Company → Pickup Addresses → Add.\n"
            . 'A booking cannot say where the parcel is collected from without one.');
    } elseif (in_array($pickupName, $names, true)) {
        ok(sprintf('pickup location "%s" exists', $pickupName));

        foreach ($locations as $location) {
            if ((string) ($location['pickup_location'] ?? '') !== $pickupName) {
                continue;
            }

            $verified = (int) ($location['phone_verified'] ?? 1) === 1;

            if ($verified) {
                ok('...and its phone number is verified');
            } else {
                problem('...but its phone number is NOT verified.',
                    'Shiprocket refuses bookings from an unverified pickup address.');
            }

            printf("         %s, %s %s\n",
                (string) ($location['address'] ?? ''),
                (string) ($location['city'] ?? ''),
                (string) ($location['pin_code'] ?? ''));
        }
    } else {
        problem(sprintf('SHIPROCKET_PICKUP_LOCATION is "%s", which does not exist.', $pickupName),
            'Registered names: ' . implode(', ', $names));
    }
}

// ---------------------------------------------------------------------------
// Serviceability, and the courier ids that matter
// ---------------------------------------------------------------------------
heading('Serviceability and courier ids');

$sellerPincode = '';

if (isset($locations) && $locations !== []) {
    foreach ($locations as $location) {
        if ((string) ($location['pickup_location'] ?? '') === $pickupName) {
            $sellerPincode = (string) ($location['pin_code'] ?? '');
        }
    }
}

if ($sellerPincode === '') {
    $sellerPincode = '560001';
    note('Using 560001 as the origin because the pickup pincode could not be read.');
}

$query = sprintf(
    '/courier/serviceability/?pickup_postcode=%s&delivery_postcode=%s&weight=%s&cod=0',
    urlencode($sellerPincode),
    urlencode($pincode),
    urlencode((string) ($weight / 1000))
);

$serviceability = call($query, 'GET', null, $token);

if ($serviceability['status'] !== 200) {
    problem(sprintf('Serviceability check failed (HTTP %d): %s',
        $serviceability['status'],
        (string) ($serviceability['body']['message'] ?? '')));
} else {
    $couriers = $serviceability['body']['data']['available_courier_companies'] ?? [];

    if ($couriers === []) {
        problem(sprintf('No courier serves %s → %s at %.0fg.', $sellerPincode, $pincode, $weight),
            'Try another destination, or check your Shiprocket plan covers these couriers.');
    } else {
        ok(sprintf('%d courier(s) serve %s → %s', count($couriers), $sellerPincode, $pincode));

        echo "\n";
        printf("         %-28s %-8s %-10s %s\n", 'COURIER', 'ID', 'RATE', 'DAYS');

        foreach (array_slice($couriers, 0, 10) as $courier) {
            printf("         %-28s %-8s %-10s %s\n",
                substr((string) ($courier['courier_name'] ?? ''), 0, 27),
                (string) ($courier['courier_company_id'] ?? ''),
                number_format((float) ($courier['rate'] ?? 0), 2),
                (string) ($courier['estimated_delivery_days'] ?? '?'));
        }

        // THE GOTCHA. The seeded channel_code values are placeholders. A booking
        // matches on courier_company_id, so a courier whose channel_code does
        // not appear in this list can never be selected — the shipment would
        // simply fail to book with no obvious reason.
        echo "\n";

        try {
            $config = new Config(APP_ROOT . '/config');
            $db = new Database((array) $config->get('database'));

            $configured = $db->select(
                "SELECT `code`, `name`, `channel_code` FROM `couriers`
                  WHERE `adapter` = 'shiprocket' AND `is_deleted` = 0"
            );

            $liveIds = array_map(
                static fn (array $c): string => (string) ($c['courier_company_id'] ?? ''),
                $couriers
            );

            $mismatched = [];

            foreach ($configured as $row) {
                if (!in_array((string) $row['channel_code'], $liveIds, true)) {
                    $mismatched[] = sprintf('%s (channel_code %s)', $row['name'], $row['channel_code']);
                }
            }

            if ($mismatched === []) {
                ok('every configured courier matches a live Shiprocket courier id');
            } else {
                problem(
                    sprintf('%d configured courier(s) have a channel_code that Shiprocket does not '
                        . 'recognise, so they can never be selected.', count($mismatched)),
                    "These were seeded with placeholder ids:\n  "
                    . implode("\n  ", $mismatched)
                    . "\n\nSet each one to a real courier_company_id from the table above:\n"
                    . "  UPDATE couriers SET channel_code = '<id>' WHERE code = 'DELHIVERY';"
                );
            }
        } catch (Throwable $exception) {
            note('Could not compare against the couriers table: ' . $exception->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 70) . "\n";

if ($problems === []) {
    echo "Shiprocket is reachable and configured.\n\n";
    echo "Nothing has been booked. The next step is one REAL shipment on an order\n";
    echo "of your own — the adapter has never made a live booking, and that call is\n";
    echo "the last untested part of the delivery path.\n";

    exit(0);
}

printf("%d thing(s) need attention:\n\n", count($problems));

foreach ($problems as $index => $text) {
    printf("  %d. %s\n", $index + 1, $text);
}

echo "\nFix those and run this again. Nothing has been booked.\n";

exit(1);
