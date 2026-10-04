<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for notifications and scheduling (Phase 9).
 *
 *   php bin/smoke_test_notifications.php
 *
 * Confirms that real order events queue messages, that a repeated event does
 * not notify twice, that promotional opt-out is honoured while transactional
 * messages are not, and that two schedulers running at once do not both do the
 * same work.
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
        CURLOPT_TIMEOUT => 60,
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
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders),
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

function db(): App\Core\Database
{
    static $db = null;

    if ($db === null) {
        $config = new App\Core\Config(APP_ROOT . '/config');
        $db = new App\Core\Database((array) $config->get('database'));
    }

    return $db;
}

function clearThrottle(): void
{
    if (!in_array((string) Env::get('APP_ENV', 'production'), ['local', 'testing'], true)) {
        throw new RuntimeException('Refusing to clear rate limits outside a local environment.');
    }

    db()->execute('DELETE FROM rate_limits');
}

echo "Notifications and scheduling smoke test\n";
printf("Base URL: %s\n\n", $baseUrl);

$adminIdentifier = prompt('Administrator mobile or email: ');
$adminPassword = prompt('Administrator password: ', true);

clearThrottle();

$response = call('POST', $baseUrl . '/auth/login',
    ['identifier' => $adminIdentifier, 'password' => $adminPassword]);

if ($response['status'] !== 200) {
    fwrite(STDERR, "\nAdministrator login failed: " . json_encode($response['body']) . "\n");
    exit(1);
}

$adminToken = $response['body']['data']['tokens']['access_token'];
check('administrator signed in', true);

// -----------------------------------------------------------------------
// A real order, generating real events
// -----------------------------------------------------------------------
echo "\n-- A real order --\n";

clearThrottle();
$mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$registration = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Notify Tester',
    'mobile' => $mobile,
    'password' => 'NotifyPass' . random_int(1000, 9999),
]);
$verified = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => $registration['body']['data']['verification']['debug_otp'],
    'reference_token' => $registration['body']['data']['verification']['reference_token'],
]);
$token = $verified['body']['data']['tokens']['access_token'];

$address = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Notify Tester',
    'contact_mobile' => $mobile,
    'address_line1' => '5 Lalbagh Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $token);

$product = call('GET', $baseUrl . '/products/california-almonds');
call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $product['body']['data']['product']['variants'][0]['uuid'],
    'quantity' => 1,
], $token);

$placed = call('POST', $baseUrl . '/checkout/place',
    ['address_uuid' => $address['body']['data']['address']['uuid']], $token);
$orderUuid = $placed['body']['data']['order']['uuid'];
$orderNumber = $placed['body']['data']['order']['order_number'];
check('an order is placed', $placed['status'] === 201, json_encode($placed['body']));

call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp', [
    'otp' => $placed['body']['data']['otp']['debug_otp'],
    'reference_token' => $placed['body']['data']['otp']['reference_token'],
], $token);

$payment = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
$webhookPayload = [
    'sandbox_order_id' => $payment['body']['data']['payment']['gateway_order_id'],
    'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
    'sandbox_status' => 'captured',
    'sandbox_amount' => (int) round($payment['body']['data']['payment']['amount'] * 100),
];
$rawBody = json_encode($webhookPayload, JSON_UNESCAPED_SLASHES);
$signature = hash_hmac('sha256', $rawBody, $paymentSecret);
callRaw($baseUrl . '/webhooks/payment', $rawBody, ['X-Sandbox-Signature: ' . $signature]);

echo "\n-- Queueing --\n";

$queued = db()->select(
    "SELECT * FROM notification_queue WHERE reference_id = :ref ORDER BY id",
    ['ref' => $orderNumber]
);
check('confirming the order queued a message', count($queued) >= 1, (string) count($queued));

$confirmed = null;

foreach ($queued as $row) {
    if ($row['template_code'] === 'order.confirmed') {
        $confirmed = $row;
    }
}

check('...specifically order.confirmed', $confirmed !== null);
checkSame('...as transactional', 'transactional', $confirmed['category'] ?? null);
checkSame('...still pending, not sent inline', 'pending', $confirmed['status'] ?? null);
check('...with the order number rendered into the body',
    str_contains((string) ($confirmed['body'] ?? ''), $orderNumber),
    (string) ($confirmed['body'] ?? ''));
check('...and no placeholders left behind',
    !str_contains((string) ($confirmed['body'] ?? ''), '{{'),
    'a message reading "Order {{order_number}}" looks broken and says nothing');

// A replayed webhook must not notify twice.
callRaw($baseUrl . '/webhooks/payment', $rawBody, ['X-Sandbox-Signature: ' . $signature]);
$again = db()->select(
    "SELECT COUNT(*) AS c FROM notification_queue
      WHERE template_code = 'order.confirmed' AND reference_id = :ref",
    ['ref' => $orderNumber]
);
checkSame('a replayed webhook does not queue a second message', 1, (int) $again[0]['c']);

// -----------------------------------------------------------------------
// Dispatch
// -----------------------------------------------------------------------
echo "\n-- Dispatch --\n";

$response = call('POST', $baseUrl . '/admin/notifications/dispatch', ['limit' => 100], $adminToken);
check('the queue dispatches', $response['status'] === 200, json_encode($response['body']));
check('...sending at least one', ($response['body']['data']['sent'] ?? 0) >= 1,
    json_encode($response['body']['data']));

$after = db()->selectOne(
    "SELECT status, sent_date, provider_message_id FROM notification_queue
      WHERE template_code = 'order.confirmed' AND reference_id = :ref",
    ['ref' => $orderNumber]
);
checkSame('the message is now marked sent', 'sent', $after['status']);
check('...with a send time', $after['sent_date'] !== null);
check('...and a provider reference', !empty($after['provider_message_id']));

$response = call('POST', $baseUrl . '/admin/notifications/dispatch', ['limit' => 100], $adminToken);
checkSame('dispatching again sends nothing already sent', 0,
    (int) ($response['body']['data']['sent'] ?? -1));

// -----------------------------------------------------------------------
// Preferences
// -----------------------------------------------------------------------
echo "\n-- Preferences --\n";

$response = call('GET', $baseUrl . '/notifications/preferences', [], $token);
check('preferences load', $response['status'] === 200);
check('...defaulting to opted in', ($response['body']['data']['promotional']['sms'] ?? false) === true);
check('...stating that transactional messages are always sent',
    str_contains((string) ($response['body']['data']['note'] ?? ''), 'always sent'));

$response = call('PATCH', $baseUrl . '/notifications/preferences', ['sms' => false], $token);
check('the customer can opt out of promotional SMS', $response['status'] === 200, json_encode($response['body']));
checkSame('...and it sticks', false, $response['body']['data']['promotional']['sms']);

// The opt-out must bite on promotional and NOT on transactional.
$userId = (int) db()->scalar('SELECT id FROM users WHERE mobile = :m', ['m' => $mobile]);

// The previous cart was converted by the order, and only ACTIVE carts can be
// abandoned — which is correct behaviour. Start a fresh one to abandon.
call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $product['body']['data']['product']['variants'][0]['uuid'],
    'quantity' => 2,
], $token);

db()->execute(
    "UPDATE carts SET updated_date = DATE_SUB(NOW(), INTERVAL 24 HOUR)
      WHERE user_id = :uid AND status = 'active'",
    ['uid' => $userId]
);

$response = call('POST', $baseUrl . '/admin/scheduler/run', ['task' => 'carts.abandoned'], $adminToken);
check('the abandoned-cart task runs', $response['status'] === 200, json_encode($response['body']));

$promo = db()->selectOne(
    "SELECT status, suppression_reason FROM notification_queue
      WHERE user_id = :uid AND category = 'promotional' ORDER BY id DESC LIMIT 1",
    ['uid' => $userId]
);

if ($promo !== null) {
    checkSame('a promotional message to an opted-out customer is suppressed',
        'suppressed', $promo['status']);
    check('...recording why', str_contains((string) $promo['suppression_reason'], 'opted out'),
        (string) $promo['suppression_reason']);
} else {
    check('a promotional message was evaluated for the opted-out customer', false,
        'no promotional row was created at all');
}

// -----------------------------------------------------------------------
// Scheduler
// -----------------------------------------------------------------------
echo "\n-- Scheduler --\n";

$response = call('GET', $baseUrl . '/admin/scheduler/tasks', [], $adminToken);
check('the schedule loads', $response['status'] === 200);
$tasks = $response['body']['data']['tasks'] ?? [];
check('...with the seeded tasks', count($tasks) >= 7, (string) count($tasks));

$codes = array_column($tasks, 'code');
check('...including notification dispatch', in_array('notifications.dispatch', $codes, true));
check('...and unpaid-order release', in_array('orders.expire_unpaid', $codes, true));

$response = call('POST', $baseUrl . '/admin/scheduler/run', [], $adminToken);
check('a full scheduler pass runs', $response['status'] === 200, json_encode($response['body']));
// A full pass legitimately finds nothing due when everything ran moments ago;
// that is the schedule working, not a failure. What matters is that it does not
// error and that nothing it does run fails.
check('...without error', isset($response['body']['data']['tasks_run']));

$failures = array_filter(
    $response['body']['data']['results'] ?? [],
    static fn (array $r): bool => $r['status'] === 'failed'
);
checkSame('...with no task failing', [], array_values(array_map(
    static fn (array $r): string => $r['code'] . ': ' . $r['summary'],
    $failures
)));

$response = call('GET', $baseUrl . '/admin/scheduler/tasks', [], $adminToken);
$runs = $response['body']['data']['recent_runs'] ?? [];
check('every run is recorded', count($runs) >= 1);
check('...with a duration', isset($runs[0]['duration_ms']));
check('...and a human summary', !empty($runs[0]['summary']));

// The lock is what makes cron safe on two application servers.
echo "\n-- Locking --\n";

db()->execute(
    "UPDATE scheduled_tasks
        SET locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
            locked_by = 'other-server:999', next_run_date = NOW()
      WHERE code = 'notifications.dispatch'"
);

$response = call('POST', $baseUrl . '/admin/scheduler/run', ['task' => 'notifications.dispatch'], $adminToken);
$result = $response['body']['data']['results'][0] ?? null;
checkSame('a task locked by another runner is skipped', 'skipped', $result['status'] ?? null);
check('...naming the holder', str_contains((string) ($result['summary'] ?? ''), 'other-server'),
    (string) ($result['summary'] ?? ''));

db()->execute("UPDATE scheduled_tasks SET locked_until = NULL, locked_by = NULL");

$response = call('POST', $baseUrl . '/admin/scheduler/run', ['task' => 'notifications.dispatch'], $adminToken);
checkSame('once released it runs again', 'success',
    $response['body']['data']['results'][0]['status'] ?? null);

$stillLocked = db()->scalar(
    "SELECT COUNT(*) FROM scheduled_tasks WHERE locked_until IS NOT NULL"
);
checkSame('no task is left holding its lock after running', 0, (int) $stillLocked,
    'a lock leaked after a failure silently disables the task');

// -----------------------------------------------------------------------
// Health and access
// -----------------------------------------------------------------------
echo "\n-- Health and access --\n";

$response = call('GET', $baseUrl . '/admin/notifications/health', [], $adminToken);
check('notification health loads', $response['status'] === 200);
check('...reporting per channel', count($response['body']['data']['channels'] ?? []) >= 1);

$response = call('GET', $baseUrl . '/notifications/history', [], $token);
check('a customer can see their own message history', $response['status'] === 200);
check('...containing the confirmation', count($response['body']['data']['notifications'] ?? []) >= 1);

$response = call('POST', $baseUrl . '/admin/scheduler/run', [], $token);
check('a customer cannot run the scheduler', $response['status'] === 403);

$response = call('GET', $baseUrl . '/admin/notifications/health', [], $token);
check('a customer cannot read notification health', $response['status'] === 403);

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: queued messages and task runs remain for inspection.\n";

exit($failed === 0 ? 0 : 1);
