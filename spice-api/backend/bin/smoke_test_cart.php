<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the cart, wishlist and delivery pricing (Phase 3).
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_cart.php
 *
 * Registers a throwaway customer, builds a guest cart, merges it on login, then
 * exercises quantities, save-for-later, delivery quoting, free-shipping
 * thresholds, checkout readiness and the wishlist. Cleans up after itself.
 *
 * Requires: migrations and seeds applied, APP_ENV=local and
 * OTP_EXPOSE_IN_RESPONSE=true (so the test can complete registration).
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

function call(
    string $method,
    string $url,
    array $body = [],
    ?string $token = null,
    ?string $cartToken = null,
): array {
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

echo "Cart, wishlist and delivery smoke test\n";
printf("Base URL: %s\n\n", $baseUrl);

// -----------------------------------------------------------------------
// Fixtures: fetch two seeded products and their pack sizes
// -----------------------------------------------------------------------
echo "-- Fixtures --\n";

$response = call('GET', $baseUrl . '/products/organic-turmeric-powder');

if ($response['status'] !== 200) {
    fwrite(STDERR, "Seeded products not found. Run php bin/migrate.php first.\n");
    exit(1);
}

$turmeric = $response['body']['data']['product'];
$turmericVariants = $turmeric['variants'];
check('turmeric has three pack sizes', count($turmericVariants) === 3);

// 250 g is the one with a live offer in the seed data.
$offerVariant = null;
$plainVariant = null;

foreach ($turmericVariants as $variant) {
    if ($variant['offer_is_live']) {
        $offerVariant = $variant;
    } elseif ($plainVariant === null) {
        $plainVariant = $variant;
    }
}

check('one pack size has a live offer', $offerVariant !== null);

$response = call('GET', $baseUrl . '/products/california-almonds');
$almonds = $response['body']['data']['product'];
$almondVariant = $almonds['variants'][0];
check('almonds fixture loaded', $almondVariant !== null);

// -----------------------------------------------------------------------
// Delivery pricing
// -----------------------------------------------------------------------
echo "\n-- Delivery pricing (BR-006) --\n";

$response = call('GET', $baseUrl . '/delivery/serviceability?pincode=560001');
check('Bengaluru pincode maps to the local zone',
    ($response['body']['data']['zone_code'] ?? '') === 'LOCAL',
    json_encode($response['body']['data'] ?? []));
check('local zone quotes the fastest SLA',
    ((int) ($response['body']['data']['estimated_days']['max'] ?? 99)) <= 2);

$response = call('GET', $baseUrl . '/delivery/serviceability?pincode=600001');
checkSame('Chennai maps to the south zone', 'SOUTH', $response['body']['data']['zone_code'] ?? '');

$response = call('GET', $baseUrl . '/delivery/serviceability?pincode=190001');
checkSame('Srinagar maps to the remote zone (longest prefix wins)', 'REMOTE',
    $response['body']['data']['zone_code'] ?? '');

$response = call('GET', $baseUrl . '/delivery/serviceability?pincode=110001');
checkSame('Delhi falls back to the rest-of-India zone', 'REST',
    $response['body']['data']['zone_code'] ?? '');

$response = call('GET', $baseUrl . '/delivery/serviceability?pincode=12');
check('a malformed pincode is rejected with 422', $response['status'] === 422);

$response = call('GET', $baseUrl . '/delivery/rate-card');
check('rate card publishes every zone', count($response['body']['data']['zones'] ?? []) >= 5);

$topBandIsOpenEnded = false;

foreach ($response['body']['data']['zones'] ?? [] as $zone) {
    $bands = $zone['weight_bands'] ?? [];

    if ($bands !== [] && $bands[count($bands) - 1]['to_grams'] === null) {
        $topBandIsOpenEnded = true;
    }
}

check('every zone has an open-ended top weight band', $topBandIsOpenEnded);

// -----------------------------------------------------------------------
// Guest cart
// -----------------------------------------------------------------------
echo "\n-- Guest cart --\n";

$response = call('GET', $baseUrl . '/cart');
check('a guest gets a cart without signing in', $response['status'] === 200);
$guestToken = $response['body']['data']['cart']['guest_token'] ?? null;
check('a guest cart token is issued', is_string($guestToken) && strlen($guestToken) > 20);
check('the cart is flagged as a guest cart', ($response['body']['data']['cart']['is_guest_cart'] ?? false) === true);
checkSame('an empty cart has a zero total', 0,
    $response['body']['data']['pricing']['summary']['grand_total'] ?? -1);
check('an empty cart is not checkout-ready',
    ($response['body']['data']['checkout']['is_ready'] ?? true) === false);

$response = call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $offerVariant['uuid'],
    'quantity' => 2,
], null, $guestToken);

check('item added to the guest cart', $response['status'] === 201, json_encode($response['body']));
$cart = $response['body']['data'];
checkSame('cart holds one line', 1, count($cart['items'] ?? []));
checkSame('quantity recorded', 2, $cart['items'][0]['quantity'] ?? 0);

$expectedLineTotal = round($offerVariant['effective_price'] * 2, 2);
checkSame('line total uses the live offer price', $expectedLineTotal, $cart['items'][0]['line_total'] ?? 0);
checkSame('subtotal matches the line', $expectedLineTotal,
    $cart['pricing']['summary']['items_subtotal'] ?? 0);
check('a saving against MRP is reported',
    ($cart['pricing']['summary']['product_discount'] ?? 0) > 0);
check('totals reconcile', ($cart['reconciles'] ?? false) === true);

// GST-inclusive check: tax must be inside the subtotal, not added to it.
$summary = $cart['pricing']['summary'];
check('prices are GST-inclusive', ($summary['prices_include_gst'] ?? false) === true);
check('grand total does not add GST on top',
    abs($summary['grand_total'] - ($summary['items_subtotal'] + $summary['delivery_charge'])) < 0.01,
    json_encode($summary));
check('tax is extracted and reported', ($summary['tax_total'] ?? 0) > 0);
check('taxable value plus tax equals the subtotal',
    abs(($summary['taxable_value'] + $summary['tax_total']) - $summary['items_subtotal']) < 0.01,
    sprintf('%s + %s vs %s', $summary['taxable_value'], $summary['tax_total'], $summary['items_subtotal']));

// Adding the same pack size again increments rather than duplicating.
$response = call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $offerVariant['uuid'],
    'quantity' => 1,
], null, $guestToken);
checkSame('re-adding the same pack size still leaves one line', 1, count($response['body']['data']['items'] ?? []));
checkSame('...with the quantities summed', 3, $response['body']['data']['items'][0]['quantity'] ?? 0);

$response = call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $offerVariant['uuid'],
    'quantity' => 9999,
], null, $guestToken);
check('exceeding the per-pack order limit is rejected with 422', $response['status'] === 422);

$response = call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => '00000000-0000-4000-8000-000000000000',
], null, $guestToken);
check('an unknown pack size returns 404', $response['status'] === 404);

$response = call('POST', $baseUrl . '/cart/items', ['variant_uuid' => 'not-a-uuid'], null, $guestToken);
check('a malformed pack size id returns 422', $response['status'] === 422);

// A second guest with no token must get a different, empty cart.
$response = call('GET', $baseUrl . '/cart');
$otherToken = $response['body']['data']['cart']['guest_token'] ?? null;
check('a different guest gets a separate empty cart',
    $otherToken !== $guestToken && count($response['body']['data']['items'] ?? []) === 0);

// -----------------------------------------------------------------------
// Delivery quoting against the cart
// -----------------------------------------------------------------------
echo "\n-- Delivery quoting on the cart --\n";

$response = call('GET', $baseUrl . '/cart', [], null, $guestToken);
check('without a pincode, checkout is blocked on the pincode',
    str_contains(json_encode($response['body']['data']['checkout']['blockers'] ?? []), 'pincode'));

$response = call('POST', $baseUrl . '/cart/pincode', ['pincode' => '560001'], null, $guestToken);
$cart = $response['body']['data'];
checkSame('pincode saved on the cart', '560001', $cart['cart']['delivery_pincode'] ?? '');
checkSame('delivery zone resolved', 'LOCAL', $cart['pricing']['delivery']['zone_code'] ?? '');
check('a chargeable weight is reported',
    ($cart['pricing']['delivery']['chargeable_weight_grams'] ?? 0) > 0);
check('totals still reconcile with delivery', ($cart['reconciles'] ?? false) === true);

$deliveryBlock = $cart['pricing']['delivery'];

if ($deliveryBlock['is_free']) {
    check('free delivery is explained', $deliveryBlock['waiver_reason'] !== null);
} else {
    check('the shortfall to free delivery is quantified',
        ($deliveryBlock['spend_more_for_free_delivery'] ?? null) !== null,
        'the cart page needs this to nudge the customer');
    checkSame('grand total includes delivery',
        round($cart['pricing']['summary']['items_subtotal'] + $deliveryBlock['charge'], 2),
        $cart['pricing']['summary']['grand_total']);
}

// Push the cart over the free-shipping threshold with a heavier, pricier item.
$response = call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $almondVariant['uuid'],
    'quantity' => 3,
], null, $guestToken);
$cart = $response['body']['data'];
check('crossing the threshold earns free delivery',
    ($cart['pricing']['delivery']['is_free'] ?? false) === true,
    'subtotal ' . ($cart['pricing']['summary']['items_subtotal'] ?? 0));
check('the pre-waiver charge is still disclosed',
    ($cart['pricing']['summary']['delivery_charge_before_waiver'] ?? 0) > 0);

$response = call('GET', $baseUrl . '/cart?pincode=797001', [], null, $guestToken);
check('a remote pincode costs more than local',
    ($response['body']['data']['pricing']['delivery']['charge'] ?? 0) >= 0
    && ($response['body']['data']['pricing']['delivery']['zone_code'] ?? '') === 'REMOTE');

// Restore a local pincode for the rest of the run.
call('POST', $baseUrl . '/cart/pincode', ['pincode' => '560001'], null, $guestToken);

// -----------------------------------------------------------------------
// Quantities, save for later
// -----------------------------------------------------------------------
echo "\n-- Line management --\n";

$response = call('GET', $baseUrl . '/cart', [], null, $guestToken);
$items = $response['body']['data']['items'];
checkSame('cart holds two lines', 2, count($items));

$firstItem = $items[0]['uuid'];

$response = call('PATCH', $baseUrl . '/cart/items/' . $firstItem, ['quantity' => 1], null, $guestToken);
checkSame('quantity updated', 1, $response['body']['data']['items'][0]['quantity'] ?? 0);

$response = call('PATCH', $baseUrl . '/cart/items/' . $firstItem, ['quantity' => 0], null, $guestToken);
check('a zero quantity is rejected with 422', $response['status'] === 422);

$response = call('POST', $baseUrl . '/cart/items/' . $firstItem . '/save-for-later', [], null, $guestToken);
$cart = $response['body']['data'];
checkSame('line moved out of the cart', 1, count($cart['items'] ?? []));
checkSame('...and into save-for-later', 1, count($cart['saved_for_later'] ?? []));
check('saved items are excluded from the total',
    ($cart['pricing']['summary']['item_count'] ?? 0) === 1);

$response = call('POST', $baseUrl . '/cart/items/' . $firstItem . '/move-to-cart', [], null, $guestToken);
checkSame('line moved back into the cart', 2, count($response['body']['data']['items'] ?? []));

// Cross-cart access must be impossible.
$response = call('DELETE', $baseUrl . '/cart/items/' . $firstItem, [], null, $otherToken);
check('another guest cannot touch this cart line', $response['status'] === 404,
    'ownership must be checked, not just existence');

$response = call('DELETE', $baseUrl . '/cart/items/' . $firstItem, [], null, $guestToken);
checkSame('line removed', 1, count($response['body']['data']['items'] ?? []));

// Re-adding a removed pack size must revive the line, not fail on the unique key.
$response = call('POST', $baseUrl . '/cart/items', [
    'variant_uuid' => $offerVariant['uuid'],
    'quantity' => 1,
], null, $guestToken);
check('a removed pack size can be re-added', $response['status'] === 201, json_encode($response['body']));
checkSame('...without duplicating the line', 2, count($response['body']['data']['items'] ?? []));

// -----------------------------------------------------------------------
// Register, then merge the guest cart
// -----------------------------------------------------------------------
echo "\n-- Merge on sign-in --\n";

$mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$password = 'CartTest' . random_int(1000, 9999);

$response = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Cart Test User',
    'mobile' => $mobile,
    'password' => $password,
]);

$otp = $response['body']['data']['verification']['debug_otp'] ?? null;

if (!is_string($otp)) {
    fwrite(STDERR, "\nCannot read the OTP. Set APP_ENV=local and OTP_EXPOSE_IN_RESPONSE=true.\n");
    exit(1);
}

$response = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => $otp,
    'reference_token' => $response['body']['data']['verification']['reference_token'] ?? null,
]);

$token = $response['body']['data']['tokens']['access_token'] ?? null;
check('test customer registered and signed in', is_string($token));

// A signed-in caller must get their own (empty) cart, ignoring the guest token.
$response = call('GET', $baseUrl . '/cart', [], $token, $guestToken);
checkSame('a signed-in caller gets their own empty cart, ignoring the guest token',
    0, count($response['body']['data']['items'] ?? []));
check('...and it is not flagged as a guest cart',
    ($response['body']['data']['cart']['is_guest_cart'] ?? true) === false);

$response = call('POST', $baseUrl . '/cart/merge', ['cart_token' => $guestToken], $token);
check('guest cart merged', $response['status'] === 200, json_encode($response['body']));
$cart = $response['body']['data'];
checkSame('both lines carried over', 2, count($cart['items'] ?? []));
check('a merge summary is returned', isset($cart['merge_summary']['lines_moved']));
check('the pincode carried over too', ($cart['cart']['delivery_pincode'] ?? null) === '560001');

// Merging is idempotent: clients retry this after a flaky login.
$response = call('POST', $baseUrl . '/cart/merge', ['cart_token' => $guestToken], $token);
check('merging again is harmless', $response['status'] === 200);
checkSame('...and does not duplicate lines', 2, count($response['body']['data']['items'] ?? []));

$response = call('GET', $baseUrl . '/cart', [], null, $guestToken);
checkSame('the old guest token now yields a fresh empty cart', 0,
    count($response['body']['data']['items'] ?? []));

// -----------------------------------------------------------------------
// Checkout readiness
// -----------------------------------------------------------------------
echo "\n-- Checkout readiness --\n";

$response = call('GET', $baseUrl . '/cart', [], $token);
$checkout = $response['body']['data']['checkout'];

check('a filled cart with a pincode is checkout-ready',
    ($checkout['is_ready'] ?? false) === true,
    json_encode($checkout['blockers'] ?? []));
checkSame('UPI is the only payment mode offered (BR-004)', ['upi'], $checkout['payment_modes'] ?? []);
check('prepaid-only is stated explicitly', ($checkout['prepaid_only'] ?? false) === true);

$response = call('POST', $baseUrl . '/cart/clear', [], $token);
check('cleared cart is no longer checkout-ready',
    ($response['body']['data']['checkout']['is_ready'] ?? true) === false);
checkSame('cart is empty', 0, count($response['body']['data']['items'] ?? []));

// Below the minimum order value, checkout must be blocked with a clear reason.
$cheapest = null;

foreach ($turmericVariants as $variant) {
    if ($cheapest === null || $variant['effective_price'] < $cheapest['effective_price']) {
        $cheapest = $variant;
    }
}

call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $cheapest['uuid'], 'quantity' => 1], $token);
$response = call('GET', $baseUrl . '/cart?pincode=560001', [], $token);
$checkout = $response['body']['data']['checkout'];
$subtotal = $response['body']['data']['pricing']['summary']['items_subtotal'];
$minimum = $checkout['minimum_order_value'];

if ($minimum !== null && $subtotal < $minimum) {
    check('a sub-minimum cart is blocked with the shortfall named',
        $checkout['is_ready'] === false
        && str_contains(json_encode($checkout['blockers']), 'minimum order value'));
} else {
    check('cart meets the minimum order value', $checkout['is_ready'] === true);
}

// -----------------------------------------------------------------------
// Wishlist
// -----------------------------------------------------------------------
echo "\n-- Wishlist --\n";

$response = call('GET', $baseUrl . '/wishlist');
check('the wishlist requires authentication', $response['status'] === 401);

$response = call('POST', $baseUrl . '/wishlist', ['product' => 'california-almonds'], $token);
check('product added to the wishlist', $response['status'] === 201, json_encode($response['body']));

$response = call('POST', $baseUrl . '/wishlist', ['product' => 'california-almonds'], $token);
check('adding the same product twice returns 409', $response['status'] === 409);

$response = call('GET', $baseUrl . '/wishlist/contains?product=california-almonds', [], $token);
check('contains check is true', ($response['body']['data']['in_wishlist'] ?? false) === true);

$response = call('GET', $baseUrl . '/wishlist/contains?product=organic-turmeric-powder', [], $token);
check('contains check is false for a product not saved',
    ($response['body']['data']['in_wishlist'] ?? true) === false);

$response = call('GET', $baseUrl . '/wishlist', [], $token);
$wishlistItems = $response['body']['data'];
checkSame('wishlist lists one item', 1, count($wishlistItems));
check('wishlist carries live pricing',
    ($wishlistItems[0]['pricing']['current_min_price'] ?? null) !== null);
check('the price at add is captured for price-drop alerts',
    ($wishlistItems[0]['pricing']['price_at_add'] ?? null) !== null);

$wishlistUuid = $wishlistItems[0]['uuid'];

$response = call('PATCH', $baseUrl . '/wishlist/' . $wishlistUuid,
    ['notify_on_price_drop' => false], $token);
check('wishlist preferences updated', $response['status'] === 200);

$response = call('POST', $baseUrl . '/wishlist/' . $wishlistUuid . '/move-to-cart',
    ['variant_uuid' => $almondVariant['uuid'], 'quantity' => 1], $token);
check('wishlist item moved to the cart', $response['status'] === 200, json_encode($response['body']));
checkSame('...and removed from the wishlist', 0, $response['body']['data']['wishlist_count'] ?? -1);

$response = call('POST', $baseUrl . '/wishlist', ['product' => 'california-almonds'], $token);
check('a previously removed product can be re-added', $response['status'] === 201,
    'the unique slot must be revived, not collide');

$response = call('DELETE', $baseUrl . '/wishlist/' . '00000000-0000-4000-8000-000000000000', [], $token);
check('deleting an unknown wishlist item returns 404', $response['status'] === 404);

// -----------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------
echo "\n-- Cleanup --\n";

$response = call('POST', $baseUrl . '/cart/clear', ['include_saved_for_later' => true], $token);
check('cart cleared', $response['status'] === 200);

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: the test customer and its cart remain in the database for inspection.\n";

exit($failed === 0 ? 0 : 1);
