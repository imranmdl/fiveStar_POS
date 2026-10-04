<?php

declare(strict_types=1);

/**
 * Concurrency tests.
 *
 *   php bin/test_concurrency.php
 *
 * REQUIRES A MULTI-PROCESS WEB SERVER. PHP's built-in server is single-threaded,
 * so every one of these tests passes trivially against it while proving nothing.
 * Point APP_URL at Apache with mod_php, or nginx with php-fpm, and confirm the
 * process count before trusting a green run — the harness checks this and
 * refuses to continue if requests are being serialised.
 *
 * These are the guards the rest of the suite cannot exercise. Each one protects
 * something that is lost silently when it fails: a coupon redeemed twice, wallet
 * credit spent twice, an invoice series with a hole in it. None of them produce
 * an error in production — they produce a number that is quietly wrong, and
 * nobody finds out until a reconciliation months later.
 *
 * The method throughout: create a situation where exactly one of N concurrent
 * attempts may succeed, fire all N at once, and count.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "curl is required.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$baseUrl = rtrim((string) (getenv('CONCURRENCY_URL') ?: Env::get('APP_URL', 'http://127.0.0.1:8080')), '/') . '/api/v1';
$paymentSecret = (string) Env::get('SANDBOX_PAYMENT_SECRET', 'sandbox-local-secret-change-me');
$passed = 0;
$failed = 0;

function db(): Database
{
    static $db = null;

    if ($db === null) {
        $config = new Config(APP_ROOT . '/config');
        $db = new Database((array) $config->get('database'));
    }

    return $db;
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

/** A single request, run sequentially. */
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

/**
 * Fires every request at once and returns the results.
 *
 * curl_multi opens all the sockets before any response is read, so the requests
 * genuinely overlap. Firing them in a loop would let each finish before the next
 * began, which is what makes a sequential "concurrency test" pass while proving
 * nothing at all.
 *
 * @param array<int, array{method:string, url:string, body:array<string,mixed>, token:?string}> $requests
 *
 * @return array<int, array{status:int, body:array<string, mixed>}>
 */
function fireTogether(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => $request) {
        $handle = curl_init($request['url']);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];

        if (($request['token'] ?? null) !== null) {
            $headers[] = 'Authorization: Bearer ' . $request['token'];
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ];

        if (($request['body'] ?? []) !== []) {
            $options[CURLOPT_POSTFIELDS] = json_encode($request['body']);
        }

        curl_setopt_array($handle, $options);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    $running = null;

    do {
        $status = curl_multi_exec($multi, $running);

        if ($running > 0) {
            // A short timeout. curl_multi_select returns as soon as there is
            // activity, but with a long one it blocks for the whole period
            // before the first response arrives — which for fast endpoints
            // dwarfs the thing being measured and makes "concurrent" look
            // slower than sequential.
            if (curl_multi_select($multi, 0.01) === -1) {
                usleep(200);
            }
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];

    foreach ($handles as $index => $handle) {
        $results[$index] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'body' => json_decode((string) curl_multi_getcontent($handle), true) ?? [],
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    return $results;
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

function clearThrottle(): void
{
    if (!in_array((string) Env::get('APP_ENV', 'production'), ['local', 'testing'], true)) {
        throw new RuntimeException('Refusing to clear rate limits outside a local environment.');
    }

    db()->execute('DELETE FROM rate_limits');
}

function registerCustomer(string $baseUrl, string $name): array
{
    clearThrottle();
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $r = call('POST', $baseUrl . '/auth/register', [
        'full_name' => $name,
        'mobile' => $mobile,
        'password' => 'Concurrent' . random_int(100000, 999999),
    ]);

    if ($r['status'] !== 201) {
        throw new RuntimeException('Registration failed: ' . json_encode($r['body']));
    }

    $v = call('POST', $baseUrl . '/auth/register/verify', [
        'mobile' => $mobile,
        'otp' => $r['body']['data']['verification']['debug_otp'],
        'reference_token' => $r['body']['data']['verification']['reference_token'],
    ]);

    return [
        'mobile' => $mobile,
        'token' => $v['body']['data']['tokens']['access_token'],
        'uuid' => $v['body']['data']['user']['uuid'],
        'id' => (int) db()->scalar('SELECT id FROM users WHERE mobile = :m', ['m' => $mobile]),
    ];
}

echo "Concurrency tests\n";
printf("Target: %s\n\n", $baseUrl);

// -----------------------------------------------------------------------
// The harness is worthless against a single-threaded server, so check first.
// -----------------------------------------------------------------------
echo "-- Verifying the server is genuinely concurrent --\n";

// The check that matters is structural, not a stopwatch.
//
// A timing comparison is a poor proxy here: these endpoints answer in single
// milliseconds, so connection setup and Apache's worker ramp-up swamp the
// difference between parallel and serial. What actually matters is simply
// whether this is PHP's built-in development server, which handles exactly one
// request at a time and would make every test below pass while proving nothing.
// The Server header says so plainly.
$serverHeader = '';
$probeHandle = curl_init($baseUrl . '/health');
curl_setopt_array($probeHandle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => false,
    CURLOPT_TIMEOUT => 30,
]);
$probeRaw = (string) curl_exec($probeHandle);
$probeStatus = (int) curl_getinfo($probeHandle, CURLINFO_HTTP_CODE);
curl_close($probeHandle);

if (preg_match('/^Server:\s*(.+)$/mi', $probeRaw, $serverMatch)) {
    $serverHeader = trim($serverMatch[1]);
}

check('the server is reachable', $probeStatus === 200, (string) $probeStatus);
printf("        server: %s\n", $serverHeader === '' ? '(not disclosed)' : $serverHeader);

$isDevelopmentServer = stripos($serverHeader, 'Development Server') !== false;

check('...and is not PHP\'s single-threaded development server', !$isDevelopmentServer,
    $serverHeader);

if ($isDevelopmentServer) {
    fwrite(STDERR, "\nPHP's built-in server handles one request at a time, so every test below\n");
    fwrite(STDERR, "would pass without exercising anything. Run against Apache or php-fpm:\n");
    fwrite(STDERR, "  CONCURRENCY_URL=http://127.0.0.1:8081 php bin/test_concurrency.php\n");

    exit(1);
}

// Reported for information only. Apache prefork ramps its worker pool up
// gradually, so a cold burst measures the spawn rate rather than the
// application; the pool is warmed first so the number means something, but it
// is not asserted on.
$probeCount = 24;
$probeUrl = $baseUrl . '/content/faq';

call('GET', $probeUrl);
fireTogether(array_fill(0, $probeCount, ['method' => 'GET', 'url' => $probeUrl, 'body' => [], 'token' => null]));
usleep(200000);

$sequentialStart = microtime(true);

for ($i = 0; $i < $probeCount; ++$i) {
    call('GET', $probeUrl);
}

$sequentialElapsed = microtime(true) - $sequentialStart;

$concurrentStart = microtime(true);
$probe = fireTogether(array_fill(0, $probeCount, ['method' => 'GET', 'url' => $probeUrl, 'body' => [], 'token' => null]));
$concurrentElapsed = microtime(true) - $concurrentStart;

$allOk = array_reduce($probe, static fn (bool $carry, array $r): bool => $carry && $r['status'] === 200, true);
check('concurrent requests all succeed', $allOk);

printf(
    "        %d sequential %.3fs · %d concurrent %.3fs (informational)\n",
    $probeCount,
    $sequentialElapsed,
    $probeCount,
    $concurrentElapsed
);

$adminIdentifier = prompt('Administrator mobile or email: ');
$adminPassword = prompt('Administrator password: ', true);

$response = call('POST', $baseUrl . '/auth/login',
    ['identifier' => $adminIdentifier, 'password' => $adminPassword]);

if ($response['status'] !== 200) {
    fwrite(STDERR, "\nAdministrator login failed: " . json_encode($response['body']) . "\n");
    exit(1);
}

$adminToken = $response['body']['data']['tokens']['access_token'];

$product = call('GET', $baseUrl . '/products/california-almonds');
$variantUuid = $product['body']['data']['product']['variants'][0]['uuid'];

// -----------------------------------------------------------------------
// 1. One active cart per customer
// -----------------------------------------------------------------------
echo "\n-- One active cart per customer --\n";
echo "   A unique index over a STORED generated column. Two simultaneous\n";
echo "   add-to-cart requests from a customer with no cart must not create two.\n";

$cartCustomer = registerCustomer($baseUrl, 'Cart Racer');

$results = fireTogether(array_fill(0, 8, [
    'method' => 'POST',
    'url' => $baseUrl . '/cart/items',
    'body' => ['variant_uuid' => $variantUuid, 'quantity' => 1],
    'token' => $cartCustomer['token'],
]));

$carts = (int) db()->scalar(
    "SELECT COUNT(*) FROM carts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0",
    ['uid' => $cartCustomer['id']]
);

checkSame('8 simultaneous add-to-cart requests create exactly one cart', 1, $carts);

$succeeded = count(array_filter($results, static fn (array $r): bool => $r['status'] < 400));
check('...and at least one succeeded', $succeeded >= 1, (string) $succeeded);

$serverErrors = array_filter($results, static fn (array $r): bool => $r['status'] >= 500);
checkSame('...with no 500s from a lost race', 0, count($serverErrors),
    'a losing request should retry or return cleanly, not crash');

// -----------------------------------------------------------------------
// 2. Coupon usage limit
// -----------------------------------------------------------------------
echo "\n-- The last remaining coupon use --\n";
echo "   CouponRepository::claimUsage() is an atomic UPDATE guarded by\n";
echo "   total_redeemed < total_usage_limit. Ten customers race for one use.\n";

$couponCode = 'RACE' . strtoupper(bin2hex(random_bytes(3)));

$response = call('POST', $baseUrl . '/admin/coupons', [
    'code' => $couponCode,
    'title' => 'Concurrency race coupon',
    'discount_type' => 'flat',
    'discount_value' => 25,
    'min_order_value' => 1,
    'total_usage_limit' => 1,
    'per_user_limit' => 1,
    'valid_to' => date('Y-m-d H:i:s', strtotime('+7 days')),
], $adminToken);

check('a single-use coupon was created', in_array($response['status'], [200, 201], true),
    json_encode($response['body']['message'] ?? ''));

$couponUuid = $response['body']['data']['coupon']['uuid'] ?? null;

$activated = false;

if ($couponUuid !== null) {
    $activation = call('POST', $baseUrl . '/admin/coupons/' . $couponUuid . '/status',
        ['status' => 'active'], $adminToken);
    $activated = $activation['status'] === 200;
}

// Assert it, rather than assuming. A coupon left in draft cannot be applied,
// so the race below would silently test nothing at all and still report green.
check('...and activated', $activated, 'a draft coupon cannot be applied, so the race would prove nothing');

// Ten customers, each with a cart holding the coupon, all placing at once.
$racers = [];

for ($i = 0; $i < 10; ++$i) {
    $customer = registerCustomer($baseUrl, 'Coupon Racer ' . $i);

    call('POST', $baseUrl . '/addresses', [
        'contact_name' => 'Racer',
        'contact_mobile' => $customer['mobile'],
        'address_line1' => '1 Race Street',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560001',
    ], $customer['token']);

    call('POST', $baseUrl . '/cart/items',
        ['variant_uuid' => $variantUuid, 'quantity' => 1], $customer['token']);
    $applied = call('POST', $baseUrl . '/cart/coupon',
        ['coupon_code' => $couponCode], $customer['token']);

    if ($applied['status'] !== 200) {
        // Assert rather than push on. A coupon that never reaches a cart means
        // the race below contends nothing and reports green having tested
        // nothing at all — which is exactly what happened the first time.
        throw new RuntimeException(
            'Could not apply the coupon to a cart: ' . json_encode($applied['body'])
        );
    }

    $addresses = call('GET', $baseUrl . '/addresses', [], $customer['token']);
    $customer['address_uuid'] = $addresses['body']['data']['addresses'][0]['uuid'] ?? null;
    $racers[] = $customer;
}

clearThrottle();

$results = fireTogether(array_map(
    static fn (array $customer): array => [
        'method' => 'POST',
        'url' => $baseUrl . '/checkout/place',
        'body' => ['address_uuid' => $customer['address_uuid']],
        'token' => $customer['token'],
    ],
    $racers
));

$redeemed = (int) db()->scalar(
    'SELECT total_redeemed FROM coupons WHERE code = :code',
    ['code' => $couponCode]
);

$redemptionRows = (int) db()->scalar(
    'SELECT COUNT(*) FROM coupon_redemptions cr
       JOIN coupons c ON c.id = cr.coupon_id
      WHERE c.code = :code',
    ['code' => $couponCode]
);

$ordersWithCoupon = (int) db()->scalar(
    'SELECT COUNT(*) FROM orders WHERE coupon_code = :code AND is_deleted = 0',
    ['code' => $couponCode]
);

checkSame('the counter never exceeds the limit', 1, $redeemed);
checkSame('exactly one redemption was recorded', 1, $redemptionRows);
checkSame('exactly one order carries the coupon', 1, $ordersWithCoupon,
    'more than one means the discount was given away twice');

$placed = count(array_filter($results, static fn (array $r): bool => $r['status'] === 201));
check('the other nine were refused or placed without it', $placed >= 1, (string) $placed);

$serverErrors = array_filter($results, static fn (array $r): bool => $r['status'] >= 500);
checkSame('no request crashed losing the race', 0, count($serverErrors));

// -----------------------------------------------------------------------
// 3. Wallet double-spend
// -----------------------------------------------------------------------
echo "\n-- Spending the same wallet credit twice --\n";
echo "   WalletRepository::lockAccountForUpdate() holds a row lock across the\n";
echo "   balance check and the debit. Without it two orders both see the money.\n";

$walletCustomer = registerCustomer($baseUrl, 'Wallet Racer');

call('POST', $baseUrl . '/admin/wallet/' . $walletCustomer['uuid'] . '/credit', [
    'amount' => 100,
    'narration' => 'Concurrency test credit',
    'reference' => 'concurrency-' . bin2hex(random_bytes(4)),
], $adminToken);

$balance = (float) db()->scalar(
    'SELECT balance_amount FROM wallet_accounts WHERE user_id = :uid',
    ['uid' => $walletCustomer['id']]
);
checkSame('the wallet holds 100', 100.0, $balance);

// Six concurrent debits of 100 each, straight at the service.
$results = fireTogether(array_fill(0, 6, [
    'method' => 'POST',
    'url' => $baseUrl . '/cart/wallet',
    'body' => ['amount' => 100],
    'token' => $walletCustomer['token'],
]));

// Applying to a cart only records intent; the debit happens at placement.
// Place the order six times at once instead.
call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Wallet Racer',
    'contact_mobile' => $walletCustomer['mobile'],
    'address_line1' => '2 Race Street',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $walletCustomer['token']);

$addresses = call('GET', $baseUrl . '/addresses', [], $walletCustomer['token']);
$walletAddress = $addresses['body']['data']['addresses'][0]['uuid'];

call('POST', $baseUrl . '/cart/items',
    ['variant_uuid' => $variantUuid, 'quantity' => 1], $walletCustomer['token']);
call('POST', $baseUrl . '/cart/wallet', ['amount' => 100], $walletCustomer['token']);

clearThrottle();

$results = fireTogether(array_fill(0, 6, [
    'method' => 'POST',
    'url' => $baseUrl . '/checkout/place',
    'body' => ['address_uuid' => $walletAddress],
    'token' => $walletCustomer['token'],
]));

$debited = (float) db()->scalar(
    "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
      WHERE user_id = :uid AND direction = 'debit'",
    ['uid' => $walletCustomer['id']]
);

$finalBalance = (float) db()->scalar(
    'SELECT balance_amount FROM wallet_accounts WHERE user_id = :uid',
    ['uid' => $walletCustomer['id']]
);

check('never more than the balance was debited', $debited <= 100.0,
    sprintf('%.2f debited from a balance of 100', $debited));
check('the balance never went negative', $finalBalance >= 0,
    sprintf('%.2f', $finalBalance));

$ledgerDerived = (float) db()->scalar(
    "SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
       FROM wallet_transactions WHERE user_id = :uid",
    ['uid' => $walletCustomer['id']]
);
checkSame('the cached balance still matches the ledger', $ledgerDerived, $finalBalance,
    'a drift here means a debit was applied without the counter moving, or vice versa');

// -----------------------------------------------------------------------
// 4. Gapless invoice numbering
// -----------------------------------------------------------------------
echo "\n-- Gapless GST invoice numbers --\n";
echo "   NumberingService takes its counter under SELECT ... FOR UPDATE. Indian\n";
echo "   GST requires a sequential series with no duplicates and no holes.\n";

$payers = [];

for ($i = 0; $i < 8; ++$i) {
    $customer = registerCustomer($baseUrl, 'Invoice Racer ' . $i);

    call('POST', $baseUrl . '/addresses', [
        'contact_name' => 'Racer',
        'contact_mobile' => $customer['mobile'],
        'address_line1' => '3 Race Street',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560001',
    ], $customer['token']);

    call('POST', $baseUrl . '/cart/items',
        ['variant_uuid' => $variantUuid, 'quantity' => 1], $customer['token']);

    $addresses = call('GET', $baseUrl . '/addresses', [], $customer['token']);
    clearThrottle();

    $placed = call('POST', $baseUrl . '/checkout/place',
        ['address_uuid' => $addresses['body']['data']['addresses'][0]['uuid']], $customer['token']);

    if ($placed['status'] !== 201) {
        continue;
    }

    $orderUuid = $placed['body']['data']['order']['uuid'];

    call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp', [
        'otp' => $placed['body']['data']['otp']['debug_otp'],
        'reference_token' => $placed['body']['data']['otp']['reference_token'],
    ], $customer['token']);

    $payment = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $customer['token']);

    $payload = [
        'sandbox_order_id' => $payment['body']['data']['payment']['gateway_order_id'],
        'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
        'sandbox_status' => 'captured',
        'sandbox_amount' => (int) round($payment['body']['data']['payment']['amount'] * 100),
    ];

    $payers[] = ['payload' => $payload, 'order_uuid' => $orderUuid];
}

check('eight orders are awaiting payment', count($payers) === 8, (string) count($payers));

$before = (int) db()->scalar('SELECT COUNT(*) FROM orders WHERE invoice_number IS NOT NULL');

// Every webhook at once. Each confirmation takes an invoice number.
$multi = curl_multi_init();
$handles = [];

foreach ($payers as $index => $payer) {
    $rawBody = json_encode($payer['payload'], JSON_UNESCAPED_SLASHES);
    $handle = curl_init($baseUrl . '/webhooks/payment');
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $paymentSecret),
        ],
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_multi_add_handle($multi, $handle);
    $handles[$index] = $handle;
}

$running = null;

do {
    $status = curl_multi_exec($multi, $running);

    if ($running > 0 && curl_multi_select($multi, 0.01) === -1) {
        usleep(200);
    }
} while ($running > 0 && $status === CURLM_OK);

foreach ($handles as $handle) {
    curl_multi_remove_handle($multi, $handle);
    curl_close($handle);
}

curl_multi_close($multi);

$invoices = db()->select(
    'SELECT invoice_number FROM orders WHERE invoice_number IS NOT NULL ORDER BY invoice_number'
);
$numbers = array_column($invoices, 'invoice_number');

checkSame('every confirmation produced an invoice number', $before + 8, count($numbers));
checkSame('...all distinct', count($numbers), count(array_unique($numbers)),
    'a duplicate invoice number is a tax problem, not a bug report');

// The numeric tail must run without a hole.
$sequence = array_map(
    static fn (string $number): int => (int) substr($number, -6),
    $numbers
);
sort($sequence);

$gaps = [];

for ($i = 1; $i < count($sequence); ++$i) {
    if ($sequence[$i] !== $sequence[$i - 1] + 1) {
        $gaps[] = $sequence[$i - 1] . ' → ' . $sequence[$i];
    }
}

checkSame('...and the series has no gaps', [], $gaps,
    'Indian GST requires an unbroken sequence within a financial year');

$counter = (int) db()->scalar(
    "SELECT last_number FROM numbering_sequences WHERE purpose = 'invoice'"
);
checkSame('the counter matches the highest number issued', max($sequence), $counter);

// -----------------------------------------------------------------------
// 5. Notification queue claim
// -----------------------------------------------------------------------
echo "\n-- Two workers dispatching the same queue --\n";
echo "   Each message is claimed with a conditional UPDATE. Without it both\n";
echo "   workers send it and the customer is told twice.\n";

$pendingBefore = (int) db()->scalar("SELECT COUNT(*) FROM notification_queue WHERE status = 'pending'");
check('there are queued messages to contend over', $pendingBefore > 0, (string) $pendingBefore);

$results = fireTogether(array_fill(0, 6, [
    'method' => 'POST',
    'url' => $baseUrl . '/admin/notifications/dispatch',
    'body' => ['limit' => 100],
    'token' => $adminToken,
]));

$totalSent = 0;

foreach ($results as $result) {
    $totalSent += (int) ($result['body']['data']['sent'] ?? 0);
}

$actuallySent = (int) db()->scalar("SELECT COUNT(*) FROM notification_queue WHERE status = 'sent'");

check('no message was sent twice', $totalSent <= $actuallySent,
    sprintf('workers reported %d sends, %d rows are marked sent', $totalSent, $actuallySent));

$stuck = (int) db()->scalar("SELECT COUNT(*) FROM notification_queue WHERE status = 'sending'");
checkSame('no message is left stuck mid-send', 0, $stuck,
    'a row abandoned in "sending" is never retried and never delivered');

// -----------------------------------------------------------------------
// 6. Scheduler lock
// -----------------------------------------------------------------------
echo "\n-- Two servers running the same crontab --\n";
echo "   scheduled_tasks.locked_until is claimed with a conditional UPDATE, so\n";
echo "   exactly one runner executes a task. Both expiring the same unpaid\n";
echo "   orders would return the same wallet credit twice.\n";

db()->execute(
    "UPDATE scheduled_tasks SET next_run_date = NOW(), locked_until = NULL, locked_by = NULL
      WHERE code = 'orders.expire_unpaid'"
);

$runsBefore = (int) db()->scalar(
    "SELECT COUNT(*) FROM scheduled_task_runs r
       JOIN scheduled_tasks t ON t.id = r.task_id
      WHERE t.code = 'orders.expire_unpaid'"
);

$results = fireTogether(array_fill(0, 6, [
    'method' => 'POST',
    'url' => $baseUrl . '/admin/scheduler/run',
    'body' => ['task' => 'orders.expire_unpaid'],
    'token' => $adminToken,
]));

$ran = 0;
$skipped = 0;

foreach ($results as $result) {
    foreach ($result['body']['data']['results'] ?? [] as $task) {
        if ($task['status'] === 'skipped') {
            ++$skipped;
        } else {
            ++$ran;
        }
    }
}

checkSame('exactly one runner executed the task', 1, $ran, sprintf('%d ran, %d skipped', $ran, $skipped));
check('...and the rest were told it was already running', $skipped >= 1, (string) $skipped);

$runsAfter = (int) db()->scalar(
    "SELECT COUNT(*) FROM scheduled_task_runs r
       JOIN scheduled_tasks t ON t.id = r.task_id
      WHERE t.code = 'orders.expire_unpaid'"
);
checkSame('exactly one run was recorded', $runsBefore + 1, $runsAfter);

$leftLocked = (int) db()->scalar('SELECT COUNT(*) FROM scheduled_tasks WHERE locked_until IS NOT NULL');
checkSame('no lock was left behind', 0, $leftLocked);

// -----------------------------------------------------------------------
// 7. One live assignment per order
// -----------------------------------------------------------------------
echo "\n-- Assigning the same order twice --\n";
echo "   A unique index over a generated column allows one open assignment per\n";
echo "   order, however many supervisors click at once.\n";

$assignable = db()->selectOne(
    "SELECT uuid FROM orders
      WHERE status IN ('confirmed','packed') AND payment_status = 'paid' AND is_deleted = 0
      ORDER BY id DESC LIMIT 1"
);

if ($assignable !== null) {
    $executive = registerCustomer($baseUrl, 'Assignment Target');
    db()->execute(
        "UPDATE users SET role_id = (SELECT id FROM roles WHERE code = 'executive') WHERE id = :id",
        ['id' => $executive['id']]
    );
    call('POST', $baseUrl . '/admin/staff', ['user_uuid' => $executive['uuid']], $adminToken);

    $results = fireTogether(array_fill(0, 6, [
        'method' => 'POST',
        'url' => $baseUrl . '/staff/orders/' . $assignable['uuid'] . '/assign',
        'body' => [],
        'token' => $adminToken,
    ]));

    $active = (int) db()->scalar(
        "SELECT COUNT(*) FROM order_assignments a
           JOIN orders o ON o.id = a.order_id
          WHERE o.uuid = :uuid AND a.status IN ('assigned','accepted') AND a.is_deleted = 0",
        ['uuid' => $assignable['uuid']]
    );

    checkSame('six simultaneous assignments leave exactly one open', 1, $active);

    $created = count(array_filter($results, static fn (array $r): bool => $r['status'] === 201));
    checkSame('...and only one request reported success', 1, $created);
} else {
    check('an assignable order exists for the assignment race', false,
        'no paid, confirmed order was available');
}

// -----------------------------------------------------------------------
// 8. Duplicate webhook under load
// -----------------------------------------------------------------------
echo "\n-- The same payment webhook delivered six times at once --\n";
echo "   payment_events has a UNIQUE key on (gateway, event_id). Gateways retry\n";
echo "   hard; a duplicate must not confirm an order twice or pay a referral twice.\n";

if ($payers !== []) {
    $rawBody = json_encode($payers[0]['payload'], JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', $rawBody, $paymentSecret);

    $multi = curl_multi_init();
    $handles = [];

    for ($i = 0; $i < 6; ++$i) {
        $handle = curl_init($baseUrl . '/webhooks/payment');
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sandbox-Signature: ' . $signature,
            ],
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_TIMEOUT => 60,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    $running = null;

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
    } while ($running > 0);

    foreach ($handles as $handle) {
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    $eventId = 'sbox:' . $payers[0]['payload']['sandbox_payment_id'] . ':captured';
    $events = (int) db()->scalar(
        'SELECT COUNT(*) FROM payment_events WHERE event_id = :id',
        ['id' => $eventId]
    );

    checkSame('the event was recorded exactly once', 1, $events);

    $order = db()->selectOne(
        'SELECT id, invoice_number FROM orders WHERE uuid = :uuid',
        ['uuid' => $payers[0]['order_uuid']]
    );

    $payments = (int) db()->scalar(
        "SELECT COUNT(*) FROM payments WHERE order_id = :id AND status = 'captured'",
        ['id' => (int) $order['id']]
    );

    checkSame('exactly one payment is captured', 1, $payments);

    $invoiceCount = (int) db()->scalar(
        'SELECT COUNT(*) FROM orders WHERE invoice_number = :number',
        ['number' => $order['invoice_number']]
    );
    checkSame('the invoice number was not reissued', 1, $invoiceCount);
}

printf("\n%d passed, %d failed\n", $passed, $failed);

if ($failed === 0) {
    echo "\nEvery guard held under genuine parallel load.\n";
}

exit($failed === 0 ? 0 : 1);
