<?php

declare(strict_types=1);

/**
 * Moves a sandbox shipment along by hand.
 *
 *   php bin/sandbox_track.php --list                  shipments in transit
 *   php bin/sandbox_track.php SDF2627000002           advance one step
 *   php bin/sandbox_track.php SDF2627000002 --deliver  jump straight to delivered
 *   php bin/sandbox_track.php SDF2627000002 --fail     a failed delivery attempt
 *
 * WHY THIS EXISTS. With COURIER_DRIVER=sandbox no parcel is ever collected, so
 * nothing ever sends a tracking scan. An order reaches "Handed to courier" and
 * stops there forever — which means delivery, commission accrual, the review
 * invitation and the delivered notification can never be reached while testing.
 *
 * This sends exactly the webhook a courier would send, through the real endpoint,
 * with a real signature. Nothing is written to the database directly — so what is
 * exercised is the production path: signature check, out-of-order scan handling,
 * the order state machine, commission accrual at delivery, and notifications.
 *
 * REFUSES TO RUN OUTSIDE A LOCAL ENVIRONMENT.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/**
 * Find the application root by walking up from this file.
 *
 * `dirname(__DIR__)` assumes the script sits in backend/bin/. If it has been
 * copied somewhere else — which is easy to do when a single file is downloaded
 * on its own — that resolves to a directory with no application in it and PHP
 * reports a missing autoloader, which says nothing about the actual mistake.
 */
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
    fwrite(STDERR, sprintf(
        "Cannot find the application.

"
        . "This script is at:
  %s

"
        . "It belongs in the backend's bin/ directory, alongside setup.php and
"
        . "migrate.php, and should be run from the backend folder:

"
        . "  cd path\\to\\spice-api\\backend
"
        . "  php bin\\sandbox_pay.php --list

"
        . "If you downloaded this file on its own, put it in backend\\bin\\ first.
",
        __FILE__
    ));

    exit(1);
}

define('APP_ROOT', $applicationRoot);
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$environment = (string) Env::get('APP_ENV', 'production');

if (!in_array($environment, ['local', 'testing'], true)) {
    fwrite(STDERR, sprintf(
        "Refusing to run: APP_ENV is \"%s\".\n\n"
        . "This tool moves shipments. It exists only so the sandbox courier can be\n"
        . "driven by hand during development.\n",
        $environment
    ));

    exit(1);
}

$driver = (string) Env::get('COURIER_DRIVER', 'sandbox');

if ($driver !== 'sandbox') {
    fwrite(STDERR, sprintf(
        "Refusing to run: COURIER_DRIVER is \"%s\", not \"sandbox\".\n"
        . "With a real courier configured, tracking comes from the courier.\n",
        $driver
    ));

    exit(1);
}

$config = new Config(APP_ROOT . '/config');
$db = new Database((array) $config->get('database'));

$baseUrl = rtrim((string) Env::get('APP_URL', 'http://localhost'), '/') . '/api/v1';
$secret = (string) Env::get('SANDBOX_COURIER_SECRET', '');

if ($secret === '') {
    fwrite(STDERR, "SANDBOX_COURIER_SECRET is not set. Run: php bin/setup.php\n");
    exit(1);
}

/**
 * The journey a parcel takes.
 *
 * Each run posts the NEXT scan, so repeated runs walk a shipment through exactly
 * the sequence a real courier reports rather than teleporting it to delivered.
 * Testing the intermediate states matters: "out for delivery" is what customers
 * see for most of the last day.
 */
const JOURNEY = ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'];

const LABELS = [
    'picked_up' => 'Picked up from the seller',
    'in_transit' => 'In transit',
    'out_for_delivery' => 'Out for delivery',
    'delivered' => 'Delivered',
    'failed_delivery' => 'Delivery attempted, nobody available',
];

$arguments = array_slice($argv, 1);
$listOnly = in_array('--list', $arguments, true);
$deliverNow = in_array('--deliver', $arguments, true);
$failNow = in_array('--fail', $arguments, true);
$orderNumber = null;

foreach ($arguments as $argument) {
    if (!str_starts_with($argument, '--')) {
        $orderNumber = $argument;

        break;
    }
}

$shipments = $db->select(
    "SELECT o.`order_number`, o.`status` AS `order_status`,
            s.`awb_number`, s.`status` AS `shipment_status`, c.`name` AS `courier_name`
       FROM `shipments` s
       INNER JOIN `orders` o ON o.`id` = s.`order_id`
       LEFT JOIN `couriers` c ON c.`id` = s.`courier_id`
      WHERE s.`is_deleted` = 0
        AND s.`status` NOT IN ('delivered','cancelled','rto_delivered','lost')
      ORDER BY s.`id` DESC
      LIMIT 25"
);

if ($listOnly || $orderNumber === null) {
    if ($shipments === []) {
        echo "No shipments are in transit.\n\n";
        echo "Book a courier for a paid order first: console -> Orders -> open one -> Book a courier.\n";

        exit(0);
    }

    echo "Shipments in transit\n";
    echo str_repeat('-', 78) . "\n";
    printf("%-18s %-18s %-20s %s\n", 'ORDER', 'AWB', 'SHIPMENT', 'NEXT SCAN');

    foreach ($shipments as $shipment) {
        $position = array_search($shipment['shipment_status'], JOURNEY, true);
        $next = $position === false ? JOURNEY[0] : (JOURNEY[$position + 1] ?? '(delivered)');

        printf("%-18s %-18s %-20s %s\n",
            $shipment['order_number'], $shipment['awb_number'],
            $shipment['shipment_status'], $next);
    }

    echo str_repeat('-', 78) . "\n";
    echo "\nAdvance one step : php bin/sandbox_track.php " . $shipments[0]['order_number'] . "\n";
    echo "Deliver it now   : php bin/sandbox_track.php " . $shipments[0]['order_number'] . " --deliver\n";
    echo "Failed attempt   : php bin/sandbox_track.php " . $shipments[0]['order_number'] . " --fail\n";

    exit(0);
}

$shipment = $db->selectOne(
    "SELECT s.`awb_number`, s.`status`, o.`order_number`, o.`status` AS `order_status`
       FROM `shipments` s
       INNER JOIN `orders` o ON o.`id` = s.`order_id`
      WHERE o.`order_number` = :number AND s.`is_deleted` = 0
      ORDER BY s.`id` DESC LIMIT 1",
    ['number' => $orderNumber]
);

if ($shipment === null) {
    fwrite(STDERR, sprintf(
        "Order %s has no shipment.\n\nBook a courier first: console -> Orders -> open it -> Book a courier.\n",
        $orderNumber
    ));

    exit(1);
}

// Which scans to send.
$position = array_search($shipment['status'], JOURNEY, true);

if ($failNow) {
    $toSend = ['failed_delivery'];
} elseif ($deliverNow) {
    // Every remaining scan, in order. A courier does not report "delivered"
    // without having reported a pickup first, and the state machine agrees.
    $from = $position === false ? 0 : $position + 1;
    $toSend = array_slice(JOURNEY, $from);
} else {
    $next = $position === false ? JOURNEY[0] : (JOURNEY[$position + 1] ?? null);
    $toSend = $next === null ? [] : [$next];
}

if ($toSend === []) {
    printf("Shipment for %s is already %s.\n", $shipment['order_number'], $shipment['status']);
    exit(0);
}

printf("Order %s (AWB %s)\n", $shipment['order_number'], $shipment['awb_number']);

foreach ($toSend as $index => $status) {
    $payload = [
        'awb' => $shipment['awb_number'],
        'events' => [[
            'status' => $status,
            'title' => LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'occurred_at' => date('Y-m-d H:i:s', time() - ((count($toSend) - $index) * 900)),
            'event_id' => $shipment['awb_number'] . ':manual:' . $status . ':' . time(),
        ]],
    ];

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $handle = curl_init($baseUrl . '/webhooks/tracking');
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Sandbox-Signature: ' . hash_hmac('sha256', (string) $body, $secret),
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        fwrite(STDERR, sprintf("\nCould not reach %s\n  %s\n\nIs the web server running, and is APP_URL correct?\n",
            $baseUrl . '/webhooks/tracking', $curlError));

        exit(1);
    }

    printf("  %-18s -> HTTP %d\n", $status, $code);
}

$after = $db->selectOne(
    "SELECT s.`status` AS `shipment_status`, o.`status` AS `order_status`
       FROM `shipments` s INNER JOIN `orders` o ON o.`id` = s.`order_id`
      WHERE s.`awb_number` = :awb ORDER BY s.`id` DESC LIMIT 1",
    ['awb' => $shipment['awb_number']]
);

printf("\n  shipment : %s\n  order    : %s\n", $after['shipment_status'], $after['order_status']);

if ($after['order_status'] === 'delivered') {
    echo "\nDelivered. Commission has accrued and the customer can now review what they\n";
    echo "bought. To send the queued messages:\n";
    echo "  php bin/scheduler.php --task=notifications.dispatch\n";
}

exit(0);
