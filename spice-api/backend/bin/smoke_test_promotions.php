<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for coupons, offers, wallet and referrals (Phase 4).
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_promotions.php
 *
 * Registers a referrer and a referee, applies coupons, checks the stacking
 * rules and the messages that explain them, verifies that wallet credit changes
 * the amount payable but NOT the order value or GST, and walks a referral from
 * signup through qualification to wallet payout.
 *
 * Requires: migrations and seeds applied, an administrator account
 * (php bin/seed_admin.php), APP_ENV=local and OTP_EXPOSE_IN_RESPONSE=true.
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

function call(string $method, string $url, array $body = [], ?string $token = null, ?string $cartToken = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if ($cartToken !== null) {
        $headers[] = 'X-Cart-Token: ' . $cartToken;
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

/** Registers and signs in a throwaway customer. */
function registerCustomer(string $baseUrl, ?string $referralCode = null): array
{
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $password = 'PromoTest' . random_int(1000, 9999);

    $payload = [
        'full_name' => 'Promo Test ' . strtoupper(bin2hex(random_bytes(2))),
        'mobile' => $mobile,
        'password' => $password,
    ];

    if ($referralCode !== null) {
        $payload['referral_code'] = $referralCode;
    }

    $response = call('POST', $baseUrl . '/auth/register', $payload);

    if ($response['status'] !== 201) {
        throw new RuntimeException('Registration failed: ' . json_encode($response['body']));
    }

    $otp = $response['body']['data']['verification']['debug_otp'] ?? null;

    if (!is_string($otp)) {
        throw new RuntimeException('Cannot read the OTP. Set APP_ENV=local and OTP_EXPOSE_IN_RESPONSE=true.');
    }

    $verified = call('POST', $baseUrl . '/auth/register/verify', [
        'mobile' => $mobile,
        'otp' => $otp,
        'reference_token' => $response['body']['data']['verification']['reference_token'] ?? null,
    ]);

    return [
        'mobile' => $mobile,
        'token' => $verified['body']['data']['tokens']['access_token'],
        'uuid' => $verified['body']['data']['user']['uuid'],
        'referral_code' => $verified['body']['data']['user']['referral_code'],
    ];
}

echo "Coupons, offers, wallet and referrals smoke test\n";
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
check('administrator signed in',
    ($response['body']['data']['user']['role'] ?? '') === 'administrator');

// -----------------------------------------------------------------------
// Offers
// -----------------------------------------------------------------------
echo "\n-- Offers --\n";

$response = call('GET', $baseUrl . '/offers');
$offers = $response['body']['data']['offers'] ?? [];
check('live offers load', $response['status'] === 200 && count($offers) >= 3,
    (string) count($offers) . ' offers');

$response = call('GET', $baseUrl . '/offers?type=deal_of_day');
check('offers filter by type', $response['status'] === 200);

$response = call('GET', $baseUrl . '/offers?type=not_a_type');
check('an unknown offer type is rejected with 422', $response['status'] === 422);

$response = call('GET', $baseUrl . '/offers/DRYFRUITWEEK');
$dryFruitWeek = $response['body']['data']['offer'] ?? [];
check('a named offer loads', $response['status'] === 200);
checkSame('...with its discount summary', '5% off up to ₹200.00',
    $dryFruitWeek['discount']['summary'] ?? '');

$response = call('GET', $baseUrl . '/offers/DRYFRUITWEEK/products');
check('offer products listing works', $response['status'] === 200);

$response = call('GET', $baseUrl . '/offers/NOSUCHOFFER');
check('an unknown offer returns 404', $response['status'] === 404);

// -----------------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------------
echo "\n-- Fixtures --\n";

$response = call('GET', $baseUrl . '/products/organic-turmeric-powder');
$turmericVariants = $response['body']['data']['product']['variants'] ?? [];
$turmeric250 = null;

foreach ($turmericVariants as $variant) {
    if ((int) $variant['weight_grams'] === 250) {
        $turmeric250 = $variant;
    }
}

$response = call('GET', $baseUrl . '/products/california-almonds');
$almondVariants = $response['body']['data']['product']['variants'] ?? [];
$almond250 = $almondVariants[0] ?? null;

check('fixtures loaded', $turmeric250 !== null && $almond250 !== null);

// -----------------------------------------------------------------------
// Coupons require an account
// -----------------------------------------------------------------------
echo "\n-- Coupons need an account --\n";

$guestResponse = call('GET', $baseUrl . '/cart');
$guestToken = $guestResponse['body']['data']['cart']['guest_token'];

call('POST', $baseUrl . '/cart/items',
    ['variant_uuid' => $turmeric250['uuid'], 'quantity' => 2], null, $guestToken);

$response = call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'WELCOME10'], null, $guestToken);
check('a guest cannot apply a coupon', $response['status'] === 401,
    'per-customer limits are meaningless without an account');

// -----------------------------------------------------------------------
// New customer applies WELCOME10
// -----------------------------------------------------------------------
echo "\n-- WELCOME10 on a new customer --\n";

$referrer = registerCustomer($baseUrl);
check('referrer registered', is_string($referrer['token']));
check('a referral code was issued at signup', strlen((string) $referrer['referral_code']) >= 6);

call('POST', $baseUrl . '/cart/items',
    ['variant_uuid' => $turmeric250['uuid'], 'quantity' => 2], $referrer['token']);
call('POST', $baseUrl . '/cart/items',
    ['variant_uuid' => $almond250['uuid'], 'quantity' => 1], $referrer['token']);
call('POST', $baseUrl . '/cart/pincode', ['pincode' => '560001'], $referrer['token']);

$response = call('GET', $baseUrl . '/cart', [], $referrer['token']);
$before = $response['body']['data'];
$subtotalBefore = $before['pricing']['summary']['items_subtotal'];
$taxBefore = $before['pricing']['summary']['tax_total'];
check('cart has a subtotal', $subtotalBefore > 0);

$response = call('GET', $baseUrl . '/cart/coupons', [], $referrer['token']);
$availableCoupons = $response['body']['data']['coupons'] ?? [];
check('available coupons are listed', $response['status'] === 200 && count($availableCoupons) >= 1);

$welcome = null;

foreach ($availableCoupons as $entry) {
    if ($entry['code'] === 'WELCOME10') {
        $welcome = $entry;
    }
}

check('WELCOME10 is offered to a new customer', $welcome !== null);
check('...and is marked applicable', ($welcome['is_applicable'] ?? false) === true,
    (string) ($welcome['reason'] ?? ''));
check('...with an estimated saving', ($welcome['estimated_saving'] ?? 0) > 0);

$response = call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'WELCOME10'], $referrer['token']);
check('WELCOME10 applied', $response['status'] === 200, json_encode($response['body']));
$after = $response['body']['data'];

checkSame('the applied coupon is echoed on the cart', 'WELCOME10',
    $after['cart']['applied_coupon_code'] ?? '');
check('a discount was recorded', ($after['pricing']['summary']['order_discount'] ?? 0) > 0);
check('the grand total fell', $after['pricing']['summary']['grand_total'] < $before['pricing']['summary']['grand_total']);
check('totals reconcile with the coupon applied', ($after['reconciles'] ?? false) === true);

// The 10% cap in the seed data is ₹150. Assert against the COUPON's own amount:
// `order_discount` is the sum of every order-scoped discount, and a stackable
// automatic offer (DRYFRUITWEEK) legitimately contributes to it as well.
$expectedCoupon = min(round($subtotalBefore * 0.10, 2), 150.00);
checkSame('the coupon itself is 10% capped at 150', $expectedCoupon,
    $after['promotions']['applied_coupon']['discount_amount']);

// And the summary must equal coupon + offer, with nothing unaccounted for.
$offerAmount = $after['promotions']['applied_offer']['discount_amount'] ?? 0.0;
checkSame('order_discount equals coupon plus stacked offer',
    round($expectedCoupon + $offerAmount, 2),
    $after['pricing']['summary']['order_discount']);
checkSame('...and matches the reported promotion total',
    $after['pricing']['summary']['order_discount'],
    $after['promotions']['total_promotion_discount']);

// A coupon is a discount, so it reduces the transaction value and the GST on it.
check('GST fell with the transaction value',
    $after['pricing']['summary']['tax_total'] < $taxBefore,
    sprintf('%s vs %s', $after['pricing']['summary']['tax_total'], $taxBefore));

// Per-line apportionment is what makes mixed-rate carts tax correctly.
$apportioned = 0.0;

foreach ($after['pricing']['lines'] as $line) {
    $apportioned = round($apportioned + $line['apportioned_discount'], 2);
}

checkSame('apportioned line discounts sum to the coupon value',
    $after['pricing']['summary']['order_discount'], $apportioned);

check('promotion messages explain what happened',
    count($after['promotions']['messages'] ?? []) > 0,
    json_encode($after['promotions']['messages'] ?? []));

// -----------------------------------------------------------------------
// Rejections carry reasons
// -----------------------------------------------------------------------
echo "\n-- Rejections carry reasons --\n";

$response = call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'NOSUCHCODE'], $referrer['token']);
check('an unknown code returns 404', $response['status'] === 404);
check('...with a quotable reason', str_contains((string) ($response['body']['message'] ?? ''), 'does not exist'));

$response = call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'BULK15'], $referrer['token']);
check('a draft coupon cannot be applied', in_array($response['status'], [404, 422], true),
    'status ' . $response['status']);

$response = call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'DRYFRUIT100'], $referrer['token']);
check('a draft category coupon cannot be applied', in_array($response['status'], [404, 422], true));

// SPICE50 needs ₹499 of spices and a ₹499 order; report whichever way it lands.
$response = call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'SPICE50'], $referrer['token']);

if ($response['status'] === 200) {
    check('SPICE50 applied to a qualifying spice cart', true);
    checkSame('SPICE50 replaced WELCOME10 (one coupon per order)', 'SPICE50',
        $response['body']['data']['cart']['applied_coupon_code']);
} else {
    check('SPICE50 rejected with an actionable reason', $response['status'] === 422
        && ($response['body']['message'] ?? '') !== '',
        (string) ($response['body']['message'] ?? ''));
}

// Restore WELCOME10 for the wallet checks.
call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'WELCOME10'], $referrer['token']);

$response = call('DELETE', $baseUrl . '/cart/coupon', [], $referrer['token']);
check('a coupon can be removed', $response['status'] === 200);
checkSame('...and the cart forgets it', null, $response['body']['data']['cart']['applied_coupon_code']);

$response = call('DELETE', $baseUrl . '/cart/coupon', [], $referrer['token']);
check('removing a coupon twice returns 422', $response['status'] === 422);

call('POST', $baseUrl . '/cart/coupon', ['coupon_code' => 'WELCOME10'], $referrer['token']);

// -----------------------------------------------------------------------
// Wallet: a tender, not a discount
// -----------------------------------------------------------------------
echo "\n-- Wallet is a tender, not a discount --\n";

$response = call('GET', $baseUrl . '/wallet', [], $referrer['token']);
check('wallet loads for a new customer', $response['status'] === 200);
checkSame('...with a zero balance', 0, $response['body']['data']['wallet']['balance'] ?? -1);

$response = call('POST', $baseUrl . '/cart/wallet', ['amount' => 100], $referrer['token']);
check('redeeming with no balance is accepted but applies nothing',
    $response['status'] === 200
    && (float) $response['body']['data']['payment']['wallet_applied'] === 0.0);
check('...and the reason is stated',
    ($response['body']['data']['payment']['wallet']['message'] ?? null) !== null);

$response = call('POST', $baseUrl . '/admin/wallet/' . $referrer['uuid'] . '/credit', [
    'amount' => 500,
    'narration' => 'Smoke test promotional credit',
    'source' => 'promotional',
    'reference' => 'smoke-' . bin2hex(random_bytes(4)),
], $adminToken);
check('administrator credited the wallet', $response['status'] === 201, json_encode($response['body']));
checkSame('balance after credit', 500, $response['body']['data']['balance_after'] ?? -1);

$reference = 'idem-' . bin2hex(random_bytes(4));
$first = call('POST', $baseUrl . '/admin/wallet/' . $referrer['uuid'] . '/credit', [
    'amount' => 25, 'narration' => 'Idempotency check', 'reference' => $reference,
], $adminToken);
$second = call('POST', $baseUrl . '/admin/wallet/' . $referrer['uuid'] . '/credit', [
    'amount' => 25, 'narration' => 'Idempotency check', 'reference' => $reference,
], $adminToken);
checkSame('a repeated credit with the same reference is a no-op',
    $first['body']['data']['transaction_uuid'], $second['body']['data']['transaction_uuid']);

$response = call('GET', $baseUrl . '/cart', [], $referrer['token']);
$cart = $response['body']['data'];
$grandTotal = $cart['pricing']['summary']['grand_total'];
$taxWithCoupon = $cart['pricing']['summary']['tax_total'];
$maxRedeemable = $cart['payment']['wallet']['max_redeemable'];

check('a redemption ceiling is published', $maxRedeemable > 0);
checkSame('the ceiling is 20% of the order', round($grandTotal * 0.20, 2), $maxRedeemable);

$response = call('POST', $baseUrl . '/cart/wallet', ['amount' => 50], $referrer['token']);
$cart = $response['body']['data'];

checkSame('wallet credit applied', 50, $cart['payment']['wallet_applied']);
checkSame('THE ORDER VALUE IS UNCHANGED', $grandTotal, $cart['payment']['grand_total']);
checkSame('only the amount payable falls', round($grandTotal - 50, 2), $cart['payment']['amount_payable']);
checkSame('GST IS UNCHANGED by wallet credit', $taxWithCoupon, $cart['pricing']['summary']['tax_total']);
checkSame('the grand total in pricing is untouched too', $grandTotal,
    $cart['pricing']['summary']['grand_total']);

$response = call('POST', $baseUrl . '/cart/wallet', ['amount' => 9999], $referrer['token']);
$cart = $response['body']['data'];
checkSame('an over-cap request clamps to the ceiling', $maxRedeemable, $cart['payment']['wallet_applied']);
check('...and the customer is told why',
    str_contains((string) ($cart['payment']['wallet']['message'] ?? ''), 'capped'),
    (string) ($cart['payment']['wallet']['message'] ?? ''));

$response = call('POST', $baseUrl . '/cart/wallet', ['amount' => -5], $referrer['token']);
check('a negative redemption is rejected with 422', $response['status'] === 422);

call('POST', $baseUrl . '/cart/wallet', ['amount' => 0], $referrer['token']);

$response = call('GET', $baseUrl . '/wallet/statement', [], $referrer['token']);
check('the statement lists ledger entries', ($response['body']['meta']['total'] ?? 0) >= 2);
check('every entry carries a running balance',
    ($response['body']['data'][0]['balance_after'] ?? null) !== null);

$response = call('GET', $baseUrl . '/admin/wallet/' . $referrer['uuid'], [], $adminToken);
check('the ledger reconciles against the cached balance',
    ($response['body']['data']['integrity']['matches'] ?? false) === true,
    json_encode($response['body']['data']['integrity'] ?? []));

$response = call('POST', $baseUrl . '/admin/wallet/' . $referrer['uuid'] . '/debit', [
    'amount' => 10000, 'narration' => 'Overdraw attempt',
], $adminToken);
check('the ledger cannot be overdrawn', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/wallet/' . $referrer['uuid'] . '/freeze', [
    'reason' => 'Smoke test fraud review',
], $adminToken);
check('a wallet can be frozen', $response['status'] === 200);

$response = call('GET', $baseUrl . '/cart', [], $referrer['token']);
checkSame('a frozen wallet cannot be redeemed', 0.0,
    (float) ($response['body']['data']['payment']['wallet']['max_redeemable'] ?? -1));

call('POST', $baseUrl . '/admin/wallet/' . $referrer['uuid'] . '/unfreeze', [], $adminToken);

// -----------------------------------------------------------------------
// Referrals
// -----------------------------------------------------------------------
echo "\n-- Referrals --\n";

$response = call('GET', $baseUrl . '/referrals', [], $referrer['token']);
$referralPanel = $response['body']['data']['referral'] ?? [];
check('the referral panel loads', $response['status'] === 200);
checkSame('...with the customer\'s own code', $referrer['referral_code'],
    $referralPanel['referral_code'] ?? '');
check('...a shareable message', str_contains((string) ($referralPanel['share_message'] ?? ''),
    (string) $referrer['referral_code']));
check('...and stated terms', count($referralPanel['terms'] ?? []) >= 3);
checkSame('nobody invited yet', 0, $referralPanel['progress']['total_invited'] ?? -1);

$referee = registerCustomer($baseUrl, (string) $referrer['referral_code']);
check('referee registered with the code', is_string($referee['token']));

$response = call('GET', $baseUrl . '/referrals', [], $referrer['token']);
checkSame('the referral is now counted', 1,
    $response['body']['data']['referral']['progress']['total_invited'] ?? -1);
checkSame('...and is pending, not paid', 1,
    $response['body']['data']['referral']['progress']['pending'] ?? -1);
checkSame('nothing has been earned yet', 0,
    $response['body']['data']['referral']['progress']['total_earned'] ?? -1);

$response = call('GET', $baseUrl . '/referrals/history', [], $referrer['token']);
$history = $response['body']['data'] ?? [];
checkSame('history shows the friend', 1, count($history));
check('the friend\'s mobile is masked, not exposed',
    str_contains((string) ($history[0]['friend']['mobile_masked'] ?? ''), 'X'));
checkSame('status explains the wait', 'pending', $history[0]['status'] ?? '');

$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Bad Code',
    'mobile' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
    'password' => 'BadCode1234',
    'referral_code' => 'ZZZZZZZZ',
]);
check('an invalid referral code is rejected at registration', $response['status'] === 422);

// Qualify the referral. Phase 5 will do this automatically on payment
// confirmation; until then the administrator route is the wired path.
$response = call('GET', $baseUrl . '/admin/referrals?status=pending', [], $adminToken);
$pending = $response['body']['data'] ?? [];
$referralUuid = null;

foreach ($pending as $row) {
    if (($row['referrer_uuid'] ?? '') === $referrer['uuid']) {
        $referralUuid = $row['uuid'];
    }
}

check('the referral appears in the administrator list', $referralUuid !== null);

$refereeWalletBefore = call('GET', $baseUrl . '/wallet', [], $referee['token']);
checkSame('the referee starts with nothing', 0,
    $refereeWalletBefore['body']['data']['wallet']['balance'] ?? -1);

$response = call('POST', $baseUrl . '/admin/referrals/' . $referralUuid . '/qualify', [
    'order_reference' => 'SMOKE-' . strtoupper(bin2hex(random_bytes(3))),
    'order_value' => 750,
], $adminToken);
check('the referral qualified and paid out', $response['status'] === 200, json_encode($response['body']));
checkSame('...and is marked rewarded', 'rewarded', $response['body']['data']['referral']['status'] ?? '');

$response = call('GET', $baseUrl . '/wallet', [], $referee['token']);
check('the referee received their welcome credit',
    ($response['body']['data']['wallet']['balance'] ?? 0) > 0);

$response = call('GET', $baseUrl . '/referrals', [], $referrer['token']);
checkSame('the referrer is now marked rewarded', 1,
    $response['body']['data']['referral']['progress']['rewarded'] ?? -1);
check('...and has earned credit',
    ($response['body']['data']['referral']['progress']['total_earned'] ?? 0) > 0);

$response = call('POST', $baseUrl . '/admin/referrals/' . $referralUuid . '/qualify', [
    'order_reference' => 'SMOKE-DUP',
    'order_value' => 750,
], $adminToken);
check('a referral cannot be qualified twice', $response['status'] === 409,
    'double payout must be impossible');

// -----------------------------------------------------------------------
// Administrator coupon management
// -----------------------------------------------------------------------
echo "\n-- Administrator coupon management --\n";

$newCode = 'SMOKE' . strtoupper(bin2hex(random_bytes(3)));

$response = call('POST', $baseUrl . '/admin/coupons', [
    'code' => $newCode,
    'title' => 'Smoke test coupon',
    'discount_type' => 'percentage',
    'discount_value' => 10,
    'min_order_value' => 100,
], $adminToken);
check('coupon created', $response['status'] === 201, json_encode($response['body']));
$couponUuid = $response['body']['data']['coupon']['uuid'] ?? null;
checkSame('new coupons start as drafts', 'draft', $response['body']['data']['coupon']['status'] ?? '');

$response = call('POST', $baseUrl . '/admin/coupons/' . $couponUuid . '/status',
    ['status' => 'active'], $adminToken);
check('activating an uncapped, undated percentage coupon is refused',
    $response['status'] === 422,
    'an unbounded discount must not go live by accident');
check('...listing exactly what is missing',
    count($response['body']['errors']['activation'] ?? []) >= 2,
    json_encode($response['body']['errors'] ?? []));

$response = call('PATCH', $baseUrl . '/admin/coupons/' . $couponUuid, [
    'max_discount_amount' => 100,
    'valid_to' => date('Y-m-d H:i:s', strtotime('+30 days')),
], $adminToken);
check('the gaps can be filled', $response['status'] === 200, json_encode($response['body']));

$response = call('POST', $baseUrl . '/admin/coupons/' . $couponUuid . '/status',
    ['status' => 'active'], $adminToken);
check('now it activates', $response['status'] === 200, json_encode($response['body']['errors'] ?? []));

$response = call('POST', $baseUrl . '/admin/coupons', [
    'code' => $newCode,
    'title' => 'Duplicate',
    'discount_type' => 'flat',
    'discount_value' => 10,
], $adminToken);
check('a duplicate coupon code returns 409', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/coupons', [
    'code' => 'SMOKEBAD' . strtoupper(bin2hex(random_bytes(2))),
    'title' => 'Impossible percentage',
    'discount_type' => 'percentage',
    'discount_value' => 150,
], $adminToken);
check('a percentage above 100 is rejected', $response['status'] === 422);

$response = call('GET', $baseUrl . '/admin/coupons?status=active', [], $adminToken);
check('coupons list with performance figures', $response['status'] === 200
    && isset($response['body']['data'][0]['performance']));

$response = call('GET', $baseUrl . '/admin/coupons/' . $couponUuid . '/redemptions', [], $adminToken);
check('redemption history endpoint works', $response['status'] === 200);

$response = call('GET', $baseUrl . '/admin/coupons', [], $referrer['token']);
check('a customer cannot reach coupon administration', $response['status'] === 403);

$response = call('DELETE', $baseUrl . '/admin/coupons/' . $couponUuid, [], $adminToken);
check('coupon deleted', $response['status'] === 200);

// -----------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------
echo "\n-- Cleanup --\n";

$response = call('POST', $baseUrl . '/cart/clear', ['include_saved_for_later' => true], $referrer['token']);
check('cart cleared', $response['status'] === 200);

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: the test customers, their wallet entries and the referral remain in\n";
echo "      the database. Wallet entries are append-only and cannot be deleted.\n";

exit($failed === 0 ? 0 : 1);
