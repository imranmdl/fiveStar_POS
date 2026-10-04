<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for staff operations and commission (Phase 7).
 *
 *   php bin/smoke_test_staff.php
 *
 * Creates a supervisor and two executives, runs a paid order through
 * assignment, packing, dispatch and delivery, and checks that commission
 * accrues at delivery, cannot be self-approved, and settles once.
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

/** Creates a staff user directly, since there is no public staff signup. */
function createStaffUser(string $roleCode, string $name): array
{
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $password = 'StaffPass' . random_int(1000, 9999) . '!';

    $config = new App\Core\Config(APP_ROOT . '/config');
    $db = new App\Core\Database((array) $config->get('database'));

    $roleId = (int) $db->scalar('SELECT id FROM roles WHERE code = :code', ['code' => $roleCode]);

    // referral_code is NOT NULL on users — every account gets one, staff
    // included, because a staff member can also shop.
    $id = $db->insert(
        'INSERT INTO users (uuid, role_id, full_name, mobile, password_hash, status,
                            referral_code, mobile_verified_date, created_date,
                            is_active, is_deleted, version)
         VALUES (:uuid, :role_id, :name, :mobile, :hash, \'active\',
                 :referral_code, NOW(), NOW(), 1, 0, 1)',
        [
            'uuid' => App\Helpers\Uuid::v4(),
            'role_id' => $roleId,
            'name' => $name,
            'mobile' => $mobile,
            'hash' => password_hash($password, PASSWORD_BCRYPT),
            'referral_code' => 'STF' . strtoupper(bin2hex(random_bytes(4))),
        ]
    );

    $row = $db->selectOne('SELECT uuid FROM users WHERE id = :id', ['id' => $id]);

    return ['id' => $id, 'uuid' => $row['uuid'], 'mobile' => $mobile, 'password' => $password, 'name' => $name];
}

/**
 * Clears the per-IP registration throttle.
 *
 * This suite creates a dozen customers from one machine, which is exactly what
 * the limiter exists to stop. Clearing it here keeps the test honest about what
 * it is doing rather than quietly widening the limit in configuration — and it
 * refuses outside a local environment for the same reason
 * bin/reset_rate_limits.php does.
 */
function clearThrottle(): void
{
    if (!in_array((string) Env::get('APP_ENV', 'production'), ['local', 'testing'], true)) {
        throw new RuntimeException('Refusing to clear rate limits outside a local environment.');
    }

    $config = new App\Core\Config(APP_ROOT . '/config');
    $db = new App\Core\Database((array) $config->get('database'));
    $db->execute('DELETE FROM rate_limits');
}

function login(string $baseUrl, array $user): string
{
    $response = call('POST', $baseUrl . '/auth/login',
        ['identifier' => $user['mobile'], 'password' => $user['password']]);

    if ($response['status'] !== 200) {
        throw new RuntimeException('Staff login failed: ' . json_encode($response['body']));
    }

    return $response['body']['data']['tokens']['access_token'];
}

function paidOrder(string $baseUrl, string $paymentSecret): array
{
    clearThrottle();
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $r = call('POST', $baseUrl . '/auth/register', [
        'full_name' => 'Ops Test Customer',
        'mobile' => $mobile,
        'password' => 'OpsPass' . random_int(1000, 9999),
    ]);

    if ($r['status'] !== 201) {
        throw new RuntimeException('Registration failed: ' . json_encode($r['body']));
    }

    $v = call('POST', $baseUrl . '/auth/register/verify', [
        'mobile' => $mobile,
        'otp' => $r['body']['data']['verification']['debug_otp'],
        'reference_token' => $r['body']['data']['verification']['reference_token'],
    ]);
    $token = $v['body']['data']['tokens']['access_token'];

    $a = call('POST', $baseUrl . '/addresses', [
        'contact_name' => 'Ops Tester',
        'contact_mobile' => $mobile,
        'address_line1' => '44 Church Street',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560001',
    ], $token);

    $p = call('GET', $baseUrl . '/products/california-almonds');
    call('POST', $baseUrl . '/cart/items', [
        'variant_uuid' => $p['body']['data']['product']['variants'][0]['uuid'],
        'quantity' => 2,
    ], $token);

    $placed = call('POST', $baseUrl . '/checkout/place',
        ['address_uuid' => $a['body']['data']['address']['uuid']], $token);
    $orderUuid = $placed['body']['data']['order']['uuid'];

    call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp', [
        'otp' => $placed['body']['data']['otp']['debug_otp'],
        'reference_token' => $placed['body']['data']['otp']['reference_token'],
    ], $token);

    $pay = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
    $payload = [
        'sandbox_order_id' => $pay['body']['data']['payment']['gateway_order_id'],
        'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
        'sandbox_status' => 'captured',
        'sandbox_amount' => (int) round($pay['body']['data']['payment']['amount'] * 100),
    ];
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    callRaw($baseUrl . '/webhooks/payment', $rawBody,
        ['X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $paymentSecret)]);

    return ['uuid' => $orderUuid, 'token' => $token];
}

echo "Staff operations and commission smoke test\n";
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
// Staff profiles
// -----------------------------------------------------------------------
echo "\n-- Staff profiles --\n";

clearThrottle();
$supervisor = createStaffUser('supervisor', 'Ops Supervisor');
$executiveA = createStaffUser('executive', 'Executive A');
$executiveB = createStaffUser('executive', 'Executive B');

$response = call('POST', $baseUrl . '/admin/staff', ['user_uuid' => $supervisor['uuid']], $adminToken);
check('a supervisor profile is created', $response['status'] === 201, json_encode($response['body']));

$response = call('POST', $baseUrl . '/admin/staff', [
    'user_uuid' => $executiveA['uuid'],
    'reports_to_uuid' => $supervisor['uuid'],
    'max_concurrent_orders' => 2,
], $adminToken);
check('an executive profile is created', $response['status'] === 201, json_encode($response['body']));
check('an employee code is issued',
    str_starts_with((string) ($response['body']['data']['staff']['employee_code'] ?? ''), 'EMP'));

$response = call('POST', $baseUrl . '/admin/staff', [
    'user_uuid' => $executiveB['uuid'],
    'reports_to_uuid' => $supervisor['uuid'],
    'max_concurrent_orders' => 5,
], $adminToken);
check('a second executive is created', $response['status'] === 201);

$response = call('POST', $baseUrl . '/admin/staff', ['user_uuid' => $executiveA['uuid']], $adminToken);
check('the same user cannot have two profiles', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/staff', [
    'user_uuid' => $supervisor['uuid'],
    'reports_to_uuid' => $supervisor['uuid'],
], $adminToken);
check('nobody can report to themselves', in_array($response['status'], [409, 422], true));

$supervisorToken = login($baseUrl, $supervisor);
$executiveAToken = login($baseUrl, $executiveA);
$executiveBToken = login($baseUrl, $executiveB);
check('staff can sign in', true);

// -----------------------------------------------------------------------
// Assignment
// -----------------------------------------------------------------------
echo "\n-- Assignment --\n";

$order = paidOrder($baseUrl, $paymentSecret);

$response = call('GET', $baseUrl . '/staff/board', [], $supervisorToken);
check('the supervisor board loads', $response['status'] === 200, json_encode($response['body']));
check('it shows the unassigned order', ($response['body']['data']['summary']['unassigned_orders'] ?? 0) >= 1);
check('it shows the team', ($response['body']['data']['summary']['team_size'] ?? 0) >= 2);

$response = call('POST', $baseUrl . '/staff/orders/' . $order['uuid'] . '/assign', [], $supervisorToken);
check('the order is assigned automatically', $response['status'] === 201, json_encode($response['body']));
check('the choice is explained', strlen((string) ($response['body']['data']['reason'] ?? '')) > 10);
checkSame('...as an automatic assignment', 'auto', $response['body']['data']['method']);
$assigneeName = $response['body']['data']['assignee']['full_name'];

// Executive B has more capacity (5 vs 2) so should be chosen.
checkSame('the executive with more spare capacity is chosen', 'Executive B', $assigneeName);

$response = call('POST', $baseUrl . '/staff/orders/' . $order['uuid'] . '/assign', [], $supervisorToken);
check('the same order cannot be assigned twice', $response['status'] === 409);

$response = call('POST', $baseUrl . '/staff/orders/' . $order['uuid'] . '/assign', [], $executiveAToken);
check('an executive cannot assign orders', $response['status'] === 403,
    'distributing work is a supervisory act');

// -----------------------------------------------------------------------
// Executive queue
// -----------------------------------------------------------------------
echo "\n-- Executive queue --\n";

$response = call('GET', $baseUrl . '/staff/queue', [], $executiveBToken);
check('the executive sees their queue', $response['status'] === 200);
checkSame('...containing one order', 1, count($response['body']['data']['queue'] ?? []));
checkSame('...with capacity reported', 5, $response['body']['data']['workload']['max_concurrent_orders'] ?? 0);
$assignmentUuid = $response['body']['data']['queue'][0]['assignment_uuid'];

$response = call('GET', $baseUrl . '/staff/queue', [], $executiveAToken);
checkSame('the other executive sees an empty queue', 0, count($response['body']['data']['queue'] ?? []));

$response = call('POST', $baseUrl . '/staff/assignments/' . $assignmentUuid . '/accept', [], $executiveAToken);
check('an executive cannot accept someone else\'s assignment', $response['status'] === 404);

$response = call('POST', $baseUrl . '/staff/assignments/' . $assignmentUuid . '/accept', [], $executiveBToken);
check('the assignee can accept it', $response['status'] === 200, json_encode($response['body']));
checkSame('...moving it to accepted', 'accepted', $response['body']['data']['assignment']['status']);

// -----------------------------------------------------------------------
// Packing slip
// -----------------------------------------------------------------------
echo "\n-- Packing slip --\n";

$response = call('POST', $baseUrl . '/staff/orders/' . $order['uuid'] . '/packing-slip', [], $executiveBToken);
check('a packing slip is generated', $response['status'] === 200, json_encode($response['body']));
check('...numbered', str_starts_with((string) ($response['body']['data']['slip']['slip_number'] ?? ''), 'PS'));
check('...listing the items', count($response['body']['data']['slip']['items'] ?? []) >= 1);
check('...with a shipping address', !empty($response['body']['data']['slip']['ship_to']['address']));
checkSame('...on its first print', 1, $response['body']['data']['slip']['print_count']);

$response = call('POST', $baseUrl . '/staff/orders/' . $order['uuid'] . '/packing-slip', [], $executiveBToken);
check('a reprint is allowed', $response['status'] === 200);
check('...and flagged as a reprint', ($response['body']['data']['reprint'] ?? false) === true);
checkSame('...with the count incremented', 2, $response['body']['data']['slip']['print_count']);

// -----------------------------------------------------------------------
// Dispatch and commission accrual
// -----------------------------------------------------------------------
echo "\n-- Dispatch --\n";

call('POST', $baseUrl . '/admin/orders/' . $order['uuid'] . '/status', ['status' => 'packed'], $adminToken);
$response = call('POST', $baseUrl . '/admin/orders/' . $order['uuid'] . '/ship', [], $adminToken);
check('the order is booked with a courier', $response['status'] === 201, json_encode($response['body']));
$awb = $response['body']['data']['awb_number'];

$response = call('GET', $baseUrl . '/staff/commission', [], $executiveBToken);
checkSame('no commission has accrued before delivery', 0.0,
    (float) ($response['body']['data']['summary']['total_accrued'] ?? -1));

// Pick up, then deliver.
foreach (['picked_up', 'delivered'] as $index => $status) {
    $payload = [
        'awb' => $awb,
        'events' => [[
            'status' => $status,
            'title' => ucfirst(str_replace('_', ' ', $status)),
            'occurred_at' => date('Y-m-d H:i:s', time() - (7200 - ($index * 3600))),
            'event_id' => $awb . ':ops:' . $index,
        ]],
    ];
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    callRaw($baseUrl . '/webhooks/tracking', $rawBody,
        ['X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $courierSecret)]);
}

$response = call('GET', $baseUrl . '/staff/queue', [], $executiveBToken);
checkSame('the assignment leaves the queue once shipped', 0, count($response['body']['data']['queue'] ?? []));
check('...counting towards work completed today',
    ($response['body']['data']['workload']['completed_today'] ?? 0) >= 1);

echo "\n-- Commission --\n";

$response = call('GET', $baseUrl . '/staff/commission', [], $executiveBToken);
$summary = $response['body']['data']['summary'];
check('commission accrued at delivery', (float) $summary['total_accrued'] > 0,
    json_encode($summary));
checkSame('...and is pending review', (float) $summary['total_accrued'], (float) $summary['pending']);

$entries = $response['body']['data']['entries'];
check('the entry explains its own calculation',
    strlen((string) ($entries[0]['calculation'] ?? '')) > 10, json_encode($entries[0] ?? []));
check('...naming the rule that produced it', !empty($entries[0]['rule_code']));

$response = call('GET', $baseUrl . '/staff/commission', [], $supervisorToken);
check('the supervisor earned an override', (float) ($response['body']['data']['summary']['total_accrued'] ?? 0) > 0);

$response = call('GET', $baseUrl . '/admin/commission/pending', [], $supervisorToken);
$pending = $response['body']['data']['entries'] ?? [];
check('pending commission is listed for approval', count($pending) >= 1);

$ownEntry = null;
$executiveEntry = null;

foreach ($pending as $entry) {
    if ($entry['staff_name'] === 'Ops Supervisor') {
        $ownEntry = $entry['uuid'];
    }

    if ($entry['staff_name'] === 'Executive B') {
        $executiveEntry = $entry['uuid'];
    }
}

if ($ownEntry !== null) {
    $response = call('POST', $baseUrl . '/admin/commission/approve',
        ['entry_uuids' => [$ownEntry]], $supervisorToken);
    checkSame('nobody can approve their own commission', 0, $response['body']['data']['approved']);
    check('...and is told why',
        str_contains(implode(' ', $response['body']['data']['skipped']), 'your own'),
        json_encode($response['body']['data']['skipped']));
}

$response = call('POST', $baseUrl . '/admin/commission/approve',
    ['entry_uuids' => [$executiveEntry]], $supervisorToken);
checkSame('a supervisor can approve their team\'s commission', 1, $response['body']['data']['approved']);

$response = call('POST', $baseUrl . '/admin/commission/approve',
    ['entry_uuids' => [$executiveEntry]], $supervisorToken);
checkSame('approving twice is a no-op', 0, $response['body']['data']['approved']);

// -----------------------------------------------------------------------
// Settlement
// -----------------------------------------------------------------------
echo "\n-- Settlement --\n";

$periodStart = date('Y-m-01');
$periodEnd = date('Y-m-t');

$response = call('POST', $baseUrl . '/admin/commission/settle', [
    'user_uuid' => $executiveB['uuid'],
    'period_start' => $periodStart,
    'period_end' => $periodEnd,
], $adminToken);
check('a settlement is created', $response['status'] === 201, json_encode($response['body']));
$settlement = $response['body']['data']['settlement'];
check('...numbered', str_starts_with((string) $settlement['settlement_number'], 'STL'));
check('...with a positive net amount', (float) $settlement['net_amount'] > 0);
checkSame('...covering one entry', 1, (int) $settlement['entry_count']);

$response = call('POST', $baseUrl . '/admin/commission/settle', [
    'user_uuid' => $executiveB['uuid'],
    'period_start' => $periodStart,
    'period_end' => $periodEnd,
], $adminToken);
check('settling the same period twice is refused', $response['status'] >= 400,
    'the entries are already settled, so there is nothing left to pay');

$response = call('GET', $baseUrl . '/staff/commission', [], $executiveBToken);
check('the entry now shows as settled',
    (float) ($response['body']['data']['summary']['settled'] ?? 0) > 0);
checkSame('...and nothing remains pending', 0.0,
    (float) ($response['body']['data']['summary']['pending'] ?? -1));

$response = call('POST', $baseUrl . '/admin/commission/settlements/' . $settlement['uuid'] . '/pay',
    ['payment_reference' => 'NEFT-' . strtoupper(bin2hex(random_bytes(4)))], $adminToken);
check('the settlement is marked paid', $response['status'] === 200, json_encode($response['body']));

$response = call('POST', $baseUrl . '/admin/commission/settlements/' . $settlement['uuid'] . '/pay',
    ['payment_reference' => 'NEFT-DUPLICATE'], $adminToken);
check('paying twice is refused', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/commission/settle', [
    'user_uuid' => $executiveB['uuid'],
    'period_start' => $periodStart,
    'period_end' => $periodEnd,
], $supervisorToken);
check('a supervisor cannot create settlements', $response['status'] === 403,
    'paying money out is an administrator action');

// -----------------------------------------------------------------------
// Capacity
// -----------------------------------------------------------------------
echo "\n-- Capacity --\n";

// Executive B is rated for 5 concurrent orders and A for 2, so B legitimately
// wins the first several rounds — it has more room. Enough orders are placed to
// fill B and prove the work then flows to A rather than piling up.
$orders = [];

for ($i = 0; $i < 6; ++$i) {
    $orders[] = paidOrder($baseUrl, $paymentSecret);
}

$assignedTo = [];

foreach ($orders as $each) {
    $response = call('POST', $baseUrl . '/staff/orders/' . $each['uuid'] . '/assign', [], $supervisorToken);

    if ($response['status'] === 201) {
        $assignedTo[] = $response['body']['data']['assignee']['full_name'];
    }
}

check('work reaches both executives once the roomier one fills up',
    count(array_unique($assignedTo)) >= 2, json_encode($assignedTo));
check('the executive with more capacity takes more of the load',
    count(array_filter($assignedTo, static fn (string $n): bool => $n === 'Executive B'))
    > count(array_filter($assignedTo, static fn (string $n): bool => $n === 'Executive A')),
    json_encode($assignedTo));

$response = call('GET', $baseUrl . '/staff/board', [], $supervisorToken);
$team = $response['body']['data']['team'];
$overloaded = false;

foreach ($team as $member) {
    if ((int) $member['open_assignments'] > (int) ($member['open_assignments'] + $member['remaining_capacity'])) {
        $overloaded = true;
    }
}

check('nobody was pushed past their capacity', !$overloaded, json_encode($team));

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: test staff, orders and commission entries remain for inspection.\n";

exit($failed === 0 ? 0 : 1);
