<?php

declare(strict_types=1);

/**
 * Completes a sandbox payment by hand.
 *
 *   php bin/sandbox_pay.php SDF2627000002            pay it
 *   php bin/sandbox_pay.php SDF2627000002 --fail     fail it
 *   php bin/sandbox_pay.php --list                   show orders awaiting payment
 *
 * WHY THIS EXISTS. With PAYMENT_DRIVER=sandbox the UPI intent points at a VPA
 * that does not exist, so no real app can pay it. The tests complete a payment
 * by posting a signed webhook; until now a person had no equivalent, which left
 * anyone testing the shop stuck on "Waiting for your payment to be confirmed"
 * with no way forward.
 *
 * This sends exactly the webhook the real gateway would send, through the real
 * endpoint, with a real signature. Nothing is written to the database directly —
 * so the confirmation path being exercised is the production one: signature
 * check, idempotency, invoice numbering, referral payout, notifications.
 *
 * REFUSES TO RUN OUTSIDE A LOCAL ENVIRONMENT. A tool that marks orders paid must
 * not exist on a production host.
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
        . "This tool marks orders as paid. It exists only so the sandbox gateway can\n"
        . "be driven by hand during development, and it must never be available where\n"
        . "real money is involved.\n",
        $environment
    ));

    exit(1);
}

$driver = (string) Env::get('PAYMENT_DRIVER', 'sandbox');

if ($driver !== 'sandbox') {
    fwrite(STDERR, sprintf(
        "Refusing to run: PAYMENT_DRIVER is \"%s\", not \"sandbox\".\n"
        . "With a real gateway configured, pay through the gateway.\n",
        $driver
    ));

    exit(1);
}

$config = new Config(APP_ROOT . '/config');
$db = new Database((array) $config->get('database'));

$baseUrl = rtrim((string) Env::get('APP_URL', 'http://localhost'), '/') . '/api/v1';
$secret = (string) Env::get('SANDBOX_PAYMENT_SECRET', '');

if ($secret === '') {
    fwrite(STDERR, "SANDBOX_PAYMENT_SECRET is not set. Run: php bin/setup.php\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$shouldFail = in_array('--fail', $arguments, true);
$listOnly = in_array('--list', $arguments, true);
$orderNumber = null;

foreach ($arguments as $argument) {
    if (!str_starts_with($argument, '--')) {
        $orderNumber = $argument;

        break;
    }
}

/** Orders that have a payment attempt waiting to settle. */
function pendingOrders(Database $db): array
{
    return $db->select(
        "SELECT o.`order_number`, o.`status`, o.`payment_status`, o.`amount_payable`,
                o.`otp_verified`, o.`expires_date`,
                p.`gateway_order_id`, p.`amount` AS `attempt_amount`, p.`status` AS `attempt_status`
           FROM `orders` o
           LEFT JOIN `payments` p
                  ON p.`order_id` = o.`id`
                 AND p.`status` IN ('created','pending','processing')
                 AND p.`is_deleted` = 0
          WHERE o.`payment_status` = 'pending'
            AND o.`status` NOT IN ('cancelled','refunded')
            AND o.`is_deleted` = 0
          ORDER BY o.`id` DESC
          LIMIT 25"
    );
}

if ($listOnly || $orderNumber === null) {
    $orders = pendingOrders($db);

    if ($orders === []) {
        echo "No orders are awaiting payment.\n\n";
        echo "Place an order in the shop first, verify the OTP, and start the payment.\n";

        exit(0);
    }

    echo "Orders awaiting payment\n";
    echo str_repeat('-', 76) . "\n";
    printf("%-18s %-12s %12s  %s\n", 'ORDER', 'STATUS', 'TO PAY', 'READY?');

    foreach ($orders as $order) {
        $ready = $order['gateway_order_id'] !== null;

        printf(
            "%-18s %-12s %12s  %s\n",
            $order['order_number'],
            $order['status'],
            number_format((float) $order['amount_payable'], 2),
            $ready
                ? 'yes'
                : ((int) $order['otp_verified'] === 0
                    ? 'no — OTP not verified yet'
                    : 'no — payment not started')
        );
    }

    echo str_repeat('-', 76) . "\n";
    echo "\nTo pay one:   php bin/sandbox_pay.php " . $orders[0]['order_number'] . "\n";
    echo "To fail one:  php bin/sandbox_pay.php " . $orders[0]['order_number'] . " --fail\n";

    exit(0);
}

// ---------------------------------------------------------------------------
// Find the payment attempt
// ---------------------------------------------------------------------------
$order = $db->selectOne(
    "SELECT o.`id`, o.`order_number`, o.`status`, o.`payment_status`, o.`amount_payable`,
            o.`otp_verified`,
            p.`gateway_order_id`, p.`amount` AS `attempt_amount`
       FROM `orders` o
       LEFT JOIN `payments` p
              ON p.`order_id` = o.`id`
             AND p.`status` IN ('created','pending','processing')
             AND p.`is_deleted` = 0
      WHERE o.`order_number` = :number AND o.`is_deleted` = 0
      LIMIT 1",
    ['number' => $orderNumber]
);

if ($order === null) {
    fwrite(STDERR, sprintf("No order numbered %s.\n\nRun with --list to see what is waiting.\n", $orderNumber));
    exit(1);
}

if ($order['payment_status'] === 'paid') {
    printf("Order %s is already paid.\n", $order['order_number']);
    exit(0);
}

if ($order['gateway_order_id'] === null) {
    fwrite(STDERR, sprintf(
        "Order %s has no payment attempt to settle.\n\n%s\n",
        $order['order_number'],
        (int) $order['otp_verified'] === 0
            ? "Its OTP has not been verified yet. In the shop: confirm the order with the\n"
              . 'code, then start the payment — that is what creates the attempt.'
            : "Start the payment in the shop first (the \"Pay\" step), then run this again."
    ));

    exit(1);
}

// ---------------------------------------------------------------------------
// Send the webhook the gateway would send
// ---------------------------------------------------------------------------
$payload = [
    'sandbox_order_id' => $order['gateway_order_id'],
    'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
    'sandbox_status' => $shouldFail ? 'failed' : 'captured',
    'sandbox_amount' => (int) round((float) $order['attempt_amount'] * 100),
];

$body = json_encode($payload, JSON_UNESCAPED_SLASHES);
$signature = hash_hmac('sha256', (string) $body, $secret);

printf("%s order %s (%s)\n",
    $shouldFail ? 'Failing' : 'Paying',
    $order['order_number'],
    number_format((float) $order['attempt_amount'], 2));

$handle = curl_init($baseUrl . '/webhooks/payment');
curl_setopt_array($handle, [
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Sandbox-Signature: ' . $signature,
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 30,
]);

$raw = curl_exec($handle);
$status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
$curlError = curl_error($handle);
curl_close($handle);

if ($raw === false) {
    fwrite(STDERR, sprintf(
        "Could not reach %s\n  %s\n\n"
        . "Is the web server running, and is APP_URL in .env the address you actually\n"
        . "open the site on? The webhook is sent over HTTP like a real one.\n",
        $baseUrl . '/webhooks/payment',
        $curlError
    ));

    exit(1);
}

$response = json_decode((string) $raw, true) ?? [];

if ($status !== 200) {
    fwrite(STDERR, sprintf("The webhook was refused (HTTP %d):\n  %s\n",
        $status, $response['message'] ?? substr((string) $raw, 0, 300)));

    exit(1);
}

// ---------------------------------------------------------------------------
// Report what actually changed
// ---------------------------------------------------------------------------
$after = $db->selectOne(
    'SELECT `status`, `payment_status`, `invoice_number` FROM `orders` WHERE `id` = :id',
    ['id' => (int) $order['id']]
);

printf("\n  order status   : %s\n", $after['status']);
printf("  payment status : %s\n", $after['payment_status']);

if ($after['invoice_number'] !== null) {
    printf("  invoice number : %s\n", $after['invoice_number']);
}

if ($shouldFail) {
    echo "\nThe payment was rejected, as a real failure would be. The order stays unpaid\n";
    echo "and the customer can try again until its payment window closes.\n";

    exit(0);
}

if ($after['payment_status'] === 'paid') {
    echo "\nPaid. Refresh the browser tab — it polls the order, so it should show the\n";
    echo "confirmation within a couple of seconds without you doing anything.\n";
    echo "\nA confirmation message has been queued. To send it:\n";
    echo "  php bin/scheduler.php --task=notifications.dispatch\n";

    exit(0);
}

fwrite(STDERR, "\nThe webhook was accepted but the order is still not paid. Check:\n");
fwrite(STDERR, "  tail -n 20 storage/logs/payment-" . date('Y-m-d') . ".log\n");

exit(1);
