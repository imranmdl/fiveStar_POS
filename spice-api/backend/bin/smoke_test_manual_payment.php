<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the manual UPI QR payment gateway and the admin
 * verification queue that confirms it.
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_manual_payment.php
 *
 * Walks the same "empty cart to confirmed order" path as smoke_test_checkout.php,
 * but pays with the manual gateway instead of the sandbox one, then exercises
 * the parts that only exist for manual payments:
 *
 *   - an ordinary customer cannot verify their own payment (role check)
 *   - a client-submitted "success" payload never confirms an order
 *     (there is no verifyCallback path for this gateway — see ManualGateway)
 *   - an administrator confirming with the WRONG amount is rejected
 *     (the amount-mismatch guard in PaymentService::applyVerification applies
 *     here exactly as it does to Razorpay/sandbox)
 *   - an administrator confirming with the correct amount pays the order,
 *     through the same applyVerification() choke point every other gateway uses
 *   - a second verify attempt on an already-resolved payment is refused
 *   - the /admin/settings toggle actually switches the active driver
 *
 * Requires APP_ENV=local, OTP_EXPOSE_IN_RESPONSE=true, migrations and seeds
 * applied (including 012_manual_payment_delivery.sql), and an administrator
 * account. This script switches payment_driver to 'manual' via the settings
 * API at the start and restores whatever it was before at the end, so it is
 * safe to run against an environment that is not currently in manual mode —
 * but do not run it concurrently with other traffic that starts a payment,
 * since the driver is a store-wide setting for the duration of the test.
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

    if ($raw === false) {
        throw new RuntimeException('Request failed for ' . $url);
    }

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
    check($label, $expected === $actual, sprintf('expected %s, got %s', json_encode($expected), json_encode($actual)));
}

function prompt(string $question, bool $hidden = false): string
{
    echo $question;

    if (!$hidden) {
        return trim((string) fgets(STDIN));
    }

    // 'which stty exists' is not the same as 'stty will succeed' — on a pipe
    // or non-interactive shell (CI, a piped-in credential file) stty has
    // nothing to attach to. Redirect stderr and check the exit code so a
    // failed stty call never leaves this hanging or echo disabled.
    $sttyOff = DIRECTORY_SEPARATOR !== '\\'
        && trim((string) shell_exec('stty -echo 2>/dev/null; echo $?')) === '0';

    $value = trim((string) fgets(STDIN));

    if ($sttyOff) {
        shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return $value;
}

function registerCustomer(string $baseUrl): array
{
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $response = call('POST', $baseUrl . '/auth/register', [
        'full_name' => 'Manual Pay Test ' . strtoupper(bin2hex(random_bytes(2))),
        'mobile' => $mobile,
        'password' => 'ManualPass' . random_int(1000, 9999),
    ]);

    if ($response['status'] !== 201) {
        throw new RuntimeException('Registration failed: ' . json_encode($response['body']));
    }

    $verified = call('POST', $baseUrl . '/auth/register/verify', [
        'mobile' => $mobile,
        'otp' => $response['body']['data']['verification']['debug_otp'],
        'reference_token' => $response['body']['data']['verification']['reference_token'],
    ]);

    return [
        'mobile' => $mobile,
        'token' => $verified['body']['data']['tokens']['access_token'],
    ];
}

echo "Manual payment gateway smoke test\n";
printf("Base URL: %s\n\n", $baseUrl);

// Environment variables take priority so this can run non-interactively (CI,
// a container with no tty) without touching stty at all. Uses getenv()
// rather than Env::get() deliberately — Env only reads the .env FILE and
// never the process environment, and credentials passed for a single test
// run should not have to live in a file to be picked up. Falls back to the
// interactive prompt for a developer running it by hand.
$adminIdentifier = (string) (getenv('SMOKE_ADMIN_IDENTIFIER') ?: '');
$adminPassword = (string) (getenv('SMOKE_ADMIN_PASSWORD') ?: '');

if ($adminIdentifier === '') {
    $adminIdentifier = prompt('Administrator mobile or email: ');
}

if ($adminPassword === '') {
    $adminPassword = prompt('Administrator password: ', true);
}

$response = call('POST', $baseUrl . '/auth/login', ['identifier' => $adminIdentifier, 'password' => $adminPassword]);

if ($response['status'] !== 200) {
    fwrite(STDERR, "\nAdministrator login failed: " . json_encode($response['body']) . "\n");
    exit(1);
}

$adminToken = $response['body']['data']['tokens']['access_token'];
check('administrator signed in', true);

// -----------------------------------------------------------------------
// Switch to manual mode via the admin settings API — exercising the exact
// toggle a real admin would use, not a fixture or .env edit.
// -----------------------------------------------------------------------
echo "\n-- Switching payment_driver to manual --\n";

$response = call('GET', $baseUrl . '/admin/settings', [], $adminToken);
check('settings load', $response['status'] === 200, json_encode($response['body']));
$originalDriver = $response['body']['data']['payment_driver'] ?? 'sandbox';

$response = call('PATCH', $baseUrl . '/admin/settings/payment-driver', ['driver' => 'manual'], $adminToken);
check('payment driver switched to manual', $response['status'] === 200, json_encode($response['body']));
checkSame('the setting reflects manual', 'manual', $response['body']['data']['payment_driver'] ?? null);

$response = call('PATCH', $baseUrl . '/admin/settings/payment-driver', ['driver' => 'not_a_real_driver'], $adminToken);
check('an unknown driver name is rejected', $response['status'] === 422);

$customer = registerCustomer($baseUrl);
check('test customer registered', is_string($customer['token']));
$token = $customer['token'];

// -----------------------------------------------------------------------
// Address, cart, checkout — identical path to the sandbox smoke test.
// -----------------------------------------------------------------------
echo "\n-- Building an order --\n";

$response = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Manual Pay Tester',
    'contact_mobile' => $customer['mobile'],
    'address_line1' => '12 MG Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $token);
check('address saved', $response['status'] === 201, json_encode($response['body']));
$addressUuid = $response['body']['data']['address']['uuid'] ?? null;

$response = call('GET', $baseUrl . '/products/california-almonds');
$almond = $response['body']['data']['product']['variants'][0] ?? null;
check('a product exists to buy', $almond !== null, 'seed data must include california-almonds');

call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $almond['uuid'], 'quantity' => 1], $token);

$response = call('GET', $baseUrl . '/checkout/review', [], $token);
$grandTotal = $response['body']['data']['cart']['pricing']['summary']['grand_total'] ?? null;
check('review loads with a total', $grandTotal !== null, json_encode($response['body']));

$response = call('POST', $baseUrl . '/checkout/place', [
    'address_uuid' => $addressUuid,
    'expected_grand_total' => $grandTotal,
], $token);
check('order placed', $response['status'] === 201, json_encode($response['body']));
$placement = $response['body']['data'];
$orderUuid = $placement['order']['uuid'];
$otpReference = $placement['otp']['reference_token'] ?? null;
$otpCode = $placement['otp']['debug_otp'] ?? null;

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp',
    ['otp' => $otpCode, 'reference_token' => $otpReference], $token);
check('OTP verified', $response['status'] === 200, json_encode($response['body']));

// -----------------------------------------------------------------------
// Start payment — should come back shaped for the manual gateway.
// -----------------------------------------------------------------------
echo "\n-- Starting manual payment --\n";

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
check('payment intent created', $response['status'] === 201, json_encode($response['body']));
$intent = $response['body']['data']['payment'];
$paymentUuid = $response['body']['data']['payment_uuid'];

checkSame('the gateway is manual', 'manual', $intent['gateway'] ?? null);
check('no live countdown is shown (nothing actually expires client-side)',
    array_key_exists('expires_in_seconds', $intent) && $intent['expires_in_seconds'] === null);

// -----------------------------------------------------------------------
// A client cannot confirm its own manual payment.
// -----------------------------------------------------------------------
echo "\n-- A forged client callback must not confirm the order --\n";

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment/callback', [
    'manual_status' => 'captured',
    'manual_payment_id' => 'fake_customer_supplied_id',
], $token);
check('the manual gateway rejects a client callback', $response['status'] === 422,
    'ManualGateway::verifyCallback() must always return unverified');

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('the order is still unpaid', 'pending', $response['body']['data']['order']['payment_status'] ?? null);

// -----------------------------------------------------------------------
// An ordinary customer cannot reach the admin verification queue.
// -----------------------------------------------------------------------
echo "\n-- Role check on the verification endpoints --\n";

$response = call('GET', $baseUrl . '/admin/payments/pending', [], $token);
check('a customer cannot list pending manual payments', $response['status'] === 403);

$response = call('POST', $baseUrl . '/admin/payments/' . $paymentUuid . '/verify',
    ['confirmed_amount' => $grandTotal], $token);
check('a customer cannot verify their own payment', $response['status'] === 403);

// -----------------------------------------------------------------------
// Admin queue and verification.
// -----------------------------------------------------------------------
echo "\n-- Administrator reviews and confirms the payment --\n";

$response = call('GET', $baseUrl . '/admin/payments/pending', [], $adminToken);
check('the pending queue loads', $response['status'] === 200, json_encode($response['body']));
$queueUuids = array_column($response['body']['data']['items'] ?? [], 'uuid');
check('this payment appears in the queue', in_array($paymentUuid, $queueUuids, true));

$response = call('POST', $baseUrl . '/admin/payments/' . $paymentUuid . '/verify', [
    'confirmed_amount' => '1.00',
    'utr_or_reference' => 'WRONGAMOUNTTEST',
], $adminToken);
check('confirming the wrong amount is rejected', $response['status'] === 409,
    'the amount-mismatch guard in PaymentService::applyVerification must still apply');

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('the order is still unpaid after a mismatched confirmation', 'pending',
    $response['body']['data']['order']['payment_status'] ?? null);

$response = call('POST', $baseUrl . '/admin/payments/' . $paymentUuid . '/verify', [
    'confirmed_amount' => (string) $grandTotal,
    'utr_or_reference' => 'TESTUTR123456',
], $adminToken);
check('the correct amount is accepted', $response['status'] === 200, json_encode($response['body']));
checkSame('the order is now confirmed', 'confirmed', $response['body']['data']['order']['status'] ?? null);
checkSame('the order is now paid', 'paid', $response['body']['data']['order']['payment_status'] ?? null);
check('an invoice number was issued', !empty($response['body']['data']['order']['invoice_number'] ?? null));

// -----------------------------------------------------------------------
// A resolved payment cannot be verified again.
// -----------------------------------------------------------------------
echo "\n-- A settled payment cannot be re-verified --\n";

$response = call('POST', $baseUrl . '/admin/payments/' . $paymentUuid . '/verify', [
    'confirmed_amount' => (string) $grandTotal,
], $adminToken);
check('re-verifying an already-resolved payment is refused', $response['status'] === 409);

$response = call('GET', $baseUrl . '/admin/payments/pending', [], $adminToken);
$queueUuids = array_column($response['body']['data']['items'] ?? [], 'uuid');
check('the resolved payment has left the pending queue', !in_array($paymentUuid, $queueUuids, true));

// -----------------------------------------------------------------------
// Rejection path, on a second order.
// -----------------------------------------------------------------------
echo "\n-- Rejecting a manual payment --\n";

$customer2 = registerCustomer($baseUrl);
$response = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Manual Pay Tester 2',
    'contact_mobile' => $customer2['mobile'],
    'address_line1' => '12 MG Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $customer2['token']);
$addressUuid2 = $response['body']['data']['address']['uuid'] ?? null;

call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $almond['uuid'], 'quantity' => 1], $customer2['token']);
$response = call('GET', $baseUrl . '/checkout/review', [], $customer2['token']);
$grandTotal2 = $response['body']['data']['cart']['pricing']['summary']['grand_total'] ?? null;

$response = call('POST', $baseUrl . '/checkout/place', [
    'address_uuid' => $addressUuid2,
    'expected_grand_total' => $grandTotal2,
], $customer2['token']);
$order2Uuid = $response['body']['data']['order']['uuid'];
$otp2Reference = $response['body']['data']['otp']['reference_token'] ?? null;
$otp2Code = $response['body']['data']['otp']['debug_otp'] ?? null;

call('POST', $baseUrl . '/checkout/orders/' . $order2Uuid . '/verify-otp',
    ['otp' => $otp2Code, 'reference_token' => $otp2Reference], $customer2['token']);

$response = call('POST', $baseUrl . '/checkout/orders/' . $order2Uuid . '/payment', [], $customer2['token']);
$payment2Uuid = $response['body']['data']['payment_uuid'];

$response = call('POST', $baseUrl . '/admin/payments/' . $payment2Uuid . '/reject',
    ['reason' => 'No matching transfer found in the bank statement.'], $adminToken);
check('rejection succeeds', $response['status'] === 200, json_encode($response['body']));
checkSame('the order payment status is failed, not paid', 'failed',
    $response['body']['data']['order']['payment_status'] ?? null);

$response = call('GET', $baseUrl . '/orders/' . $order2Uuid, [], $customer2['token']);
check('the order was not cancelled — the customer can retry',
    !in_array($response['body']['data']['order']['status'] ?? '', ['cancelled'], true));

// -----------------------------------------------------------------------
// Restore whatever driver was active before this test ran.
// -----------------------------------------------------------------------
echo "\n-- Restoring the original payment driver --\n";

$response = call('PATCH', $baseUrl . '/admin/settings/payment-driver', ['driver' => $originalDriver], $adminToken);
check('payment driver restored to ' . $originalDriver, $response['status'] === 200, json_encode($response['body']));

// -----------------------------------------------------------------------
printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
