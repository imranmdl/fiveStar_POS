<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the authentication module. Exercises the real HTTP
 * API, so it verifies routing, middleware, validation, services and the schema
 * together.
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test.php
 *
 * Requires APP_ENV=local and OTP_EXPOSE_IN_RESPONSE=true so the test can read
 * back the generated code.
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
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($body !== []) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        throw new RuntimeException("Request failed: {$error}");
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

$mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$password = 'SmokeTest' . random_int(1000, 9999);

echo "Authentication smoke test\n";
printf("Base URL: %s\nTest mobile: %s\n\n", $baseUrl, $mobile);

// 1. Health
$response = call('GET', $baseUrl . '/health');
check('health endpoint returns 200', $response['status'] === 200, json_encode($response['body']));

// 2. Registration
$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Smoke Test User',
    'mobile' => $mobile,
    'email' => 'smoke' . random_int(1000, 999999) . '@example.com',
    'password' => $password,
]);
check('registration returns 201', $response['status'] === 201, json_encode($response['body']));

$otp = $response['body']['data']['verification']['debug_otp'] ?? null;
$reference = $response['body']['data']['verification']['reference_token'] ?? null;
check('debug OTP is exposed in local mode', is_string($otp), 'set APP_ENV=local and OTP_EXPOSE_IN_RESPONSE=true');

// 3. Validation rejects a bad mobile
$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Bad Number',
    'mobile' => '12345',
    'password' => $password,
]);
check('invalid mobile is rejected with 422', $response['status'] === 422);

// Regression: a valid Indian mobile that happens to START with 91 must be
// accepted. Stripping the country code unconditionally used to mangle
// 91xxxxxxxx into 8 digits and reject roughly 1% of real customers.
$ninetyOne = '91' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Ninety One',
    'mobile' => $ninetyOne,
    'password' => 'NinetyOnePass1',
]);
check('a valid mobile beginning with 91 is accepted', $response['status'] === 201,
    sprintf('%s -> %s', $ninetyOne, json_encode($response['body']['errors'] ?? [])));

// And the same number written with a country code must resolve to the same
// account, so a duplicate is detected rather than creating a second customer.
$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Ninety One Again',
    'mobile' => '91' . $ninetyOne,
    'password' => 'NinetyOnePass1',
]);
check('the same number with a +91 country code is recognised as a duplicate',
    $response['status'] === 409,
    sprintf('91%s -> status %d', $ninetyOne, $response['status']));

// 4. Duplicate mobile
$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Duplicate',
    'mobile' => $mobile,
    'password' => $password,
]);
check('duplicate mobile is rejected with 409', $response['status'] === 409);

// 5. Wrong OTP
$wrongOtp = $otp === '000000' ? '111111' : '000000';
$response = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => $wrongOtp,
    'reference_token' => $reference,
]);
check('incorrect OTP is rejected with 400', $response['status'] === 400);

// 6. Correct OTP -> verified + signed in
$response = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => (string) $otp,
    'reference_token' => $reference,
]);
check('correct OTP verifies and returns tokens', $response['status'] === 200
    && isset($response['body']['data']['tokens']['access_token']), json_encode($response['body']));

$accessToken = $response['body']['data']['tokens']['access_token'] ?? '';
$refreshToken = $response['body']['data']['tokens']['refresh_token'] ?? '';

// 7. OTP replay
$response = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => (string) $otp,
    'reference_token' => $reference,
]);
check('used OTP cannot be replayed', $response['status'] === 400);

// 8. Authenticated profile
$response = call('GET', $baseUrl . '/auth/me', [], $accessToken);
check('authenticated profile loads', $response['status'] === 200
    && ($response['body']['data']['user']['mobile'] ?? '') === $mobile);

// 9. Missing token
$response = call('GET', $baseUrl . '/auth/me');
check('unauthenticated profile request returns 401', $response['status'] === 401);

// 10. Tampered token
$response = call('GET', $baseUrl . '/auth/me', [], $accessToken . 'x');
check('tampered token is rejected', $response['status'] === 401);

// 11. Password login
$response = call('POST', $baseUrl . '/auth/login', ['identifier' => $mobile, 'password' => $password]);
check('password login succeeds', $response['status'] === 200, json_encode($response['body']));

// 12. Wrong password
$response = call('POST', $baseUrl . '/auth/login', ['identifier' => $mobile, 'password' => 'wrong-password-1']);
check('wrong password returns 401', $response['status'] === 401);

// 13. Refresh rotation
$response = call('POST', $baseUrl . '/auth/token/refresh', ['refresh_token' => $refreshToken]);
check('refresh token rotates', $response['status'] === 200
    && ($response['body']['data']['tokens']['refresh_token'] ?? '') !== $refreshToken);

$rotated = $response['body']['data']['tokens']['refresh_token'] ?? '';

// 14. Reuse detection
$response = call('POST', $baseUrl . '/auth/token/refresh', ['refresh_token' => $refreshToken]);
check('reusing a rotated refresh token is rejected', $response['status'] === 401);

// 15. Reuse detection revoked all sessions, so the rotated token dies too.
$response = call('POST', $baseUrl . '/auth/token/refresh', ['refresh_token' => $rotated]);
check('reuse detection revoked every session', $response['status'] === 401);

// 16. Role guard
$response = call('GET', $baseUrl . '/admin/ping', [], $accessToken);
check('customer cannot reach an administrator endpoint', $response['status'] === 403);

// 17. Unknown route
$response = call('GET', $baseUrl . '/does-not-exist');
check('unknown route returns 404', $response['status'] === 404);

// 18. Method not allowed
$response = call('GET', $baseUrl . '/auth/login');
check('wrong HTTP method returns 405', $response['status'] === 405);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
