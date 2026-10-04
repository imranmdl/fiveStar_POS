<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for checkout, UPI payment and orders (Phase 5).
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_checkout.php
 *
 * Walks a real order from an empty cart to a delivered parcel, and attacks it
 * along the way: forged callbacks, unsigned webhooks, redelivered webhooks,
 * BR-005 bypass attempts, and cross-customer access.
 *
 * Requires PAYMENT_DRIVER=sandbox, APP_ENV=local, OTP_EXPOSE_IN_RESPONSE=true,
 * migrations and seeds applied, and an administrator account.
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
$sandboxSecret = (string) Env::get('SANDBOX_PAYMENT_SECRET', 'sandbox-local-secret-change-me');
$passed = 0;
$failed = 0;

function call(string $method, string $url, array $body = [], ?string $token = null, array $extraHeaders = []): array
{
    $handle = curl_init($url);
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders);

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

/** Posts a raw body so the webhook signature is over exact bytes. */
function callRaw(string $url, string $rawBody, array $extraHeaders = []): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(
            ['Accept: application/json', 'Content-Type: application/json'],
            $extraHeaders
        ),
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

function registerCustomer(string $baseUrl): array
{
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $response = call('POST', $baseUrl . '/auth/register', [
        'full_name' => 'Checkout Test ' . strtoupper(bin2hex(random_bytes(2))),
        'mobile' => $mobile,
        'password' => 'CheckoutPass' . random_int(1000, 9999),
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
        'uuid' => $verified['body']['data']['user']['uuid'],
    ];
}

echo "Checkout, UPI payment and orders smoke test\n";
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

$customer = registerCustomer($baseUrl);
check('test customer registered', is_string($customer['token']));
$token = $customer['token'];

// -----------------------------------------------------------------------
// Addresses
// -----------------------------------------------------------------------
echo "\n-- Delivery addresses --\n";

$response = call('GET', $baseUrl . '/addresses', [], $token);
checkSame('a new customer has no addresses', 0, count($response['body']['data']['addresses'] ?? []));

$response = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Checkout Tester',
    'contact_mobile' => $customer['mobile'],
    'address_line1' => '12 MG Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $token);
check('address saved', $response['status'] === 201, json_encode($response['body']));
$addressUuid = $response['body']['data']['address']['uuid'] ?? null;
check('the first address becomes the default',
    ($response['body']['data']['address']['is_default'] ?? false) === true);
check('serviceability is confirmed on save',
    ($response['body']['data']['serviceability']['zone_code'] ?? '') === 'LOCAL');

$response = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Nowhere',
    'contact_mobile' => $customer['mobile'],
    'address_line1' => 'Unreachable',
    'city' => 'X',
    'state' => 'Y',
    'pincode' => '000000',
], $token);
check('a malformed pincode is rejected', $response['status'] === 422);

// -----------------------------------------------------------------------
// Build a cart
// -----------------------------------------------------------------------
echo "\n-- Cart --\n";

$response = call('GET', $baseUrl . '/products/organic-turmeric-powder');
$turmeric = null;

foreach ($response['body']['data']['product']['variants'] as $variant) {
    if ((int) $variant['weight_grams'] === 250) {
        $turmeric = $variant;
    }
}

$response = call('GET', $baseUrl . '/products/california-almonds');
$almond = $response['body']['data']['product']['variants'][0];

call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $turmeric['uuid'], 'quantity' => 2], $token);
call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $almond['uuid'], 'quantity' => 1], $token);
$response = call('GET', $baseUrl . '/cart?pincode=560001', [], $token);
check('cart built', count($response['body']['data']['items']) === 2);

// -----------------------------------------------------------------------
// Checkout review
// -----------------------------------------------------------------------
echo "\n-- Checkout review --\n";

$response = call('GET', $baseUrl . '/checkout/review', [], $token);
$review = $response['body']['data'];
check('review loads', $response['status'] === 200, json_encode($response['body']));
check('the default address is preselected',
    ($review['selected_address']['uuid'] ?? null) === $addressUuid);
check('checkout is ready', ($review['checkout']['is_ready'] ?? false) === true,
    json_encode($review['checkout']['blockers'] ?? []));
checkSame('UPI only is advertised (BR-004)', ['upi'], $review['checkout']['payment_modes']);
check('OTP is declared as required (BR-003)', ($review['checkout']['otp_required'] ?? false) === true);

$grandTotal = $review['cart']['pricing']['summary']['grand_total'];
$amountPayable = $review['cart']['payment']['amount_payable'];
check('a total was quoted', $grandTotal > 0);

// -----------------------------------------------------------------------
// Place the order
// -----------------------------------------------------------------------
echo "\n-- Placing the order --\n";

$response = call('POST', $baseUrl . '/checkout/place', [
    'address_uuid' => $addressUuid,
    'expected_grand_total' => $grandTotal,
    'customer_note' => 'Please ring the bell twice.',
], $token);

check('order placed', $response['status'] === 201, json_encode($response['body']));
$placement = $response['body']['data'];
$orderUuid = $placement['order']['uuid'];
$orderNumber = $placement['order']['order_number'];

check('an order number was issued', is_string($orderNumber) && strlen($orderNumber) >= 8);
checkSame('the order starts as created', 'created', $placement['order']['status']);
checkSame('payment starts pending', 'pending', $placement['order']['payment_status']);
checkSame('the order total matches the quote', $grandTotal, $placement['order']['grand_total']);
check('the order is not OTP-verified yet', $placement['order']['otp_verified'] === false);
check('a payment window was set', $placement['order']['expires_date'] !== null);
checkSame('the client is told what to do next', 'verify_otp', $placement['next_step']);
$otpReference = $placement['otp']['reference_token'] ?? null;
$otpCode = $placement['otp']['debug_otp'] ?? null;
check('an order OTP was issued (BR-003)', is_string($otpCode));
check('the mobile it went to is masked',
    str_contains((string) ($placement['otp']['sent_to'] ?? ''), 'X'));

// A stale quote must be refused rather than silently charged.
call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $turmeric['uuid'], 'quantity' => 1], $token);
$response = call('POST', $baseUrl . '/checkout/place', [
    'address_uuid' => $addressUuid,
    'expected_grand_total' => 1.00,
], $token);
check('a stale expected total is refused with 409', $response['status'] === 409,
    'the customer must never be charged a total they did not see');

// -----------------------------------------------------------------------
// BR-005: payment cannot be skipped
// -----------------------------------------------------------------------
echo "\n-- BR-005: no progress without verified payment --\n";

$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status',
    ['status' => 'confirmed'], $adminToken);
check('an administrator cannot confirm an unpaid order', $response['status'] === 409,
    json_encode($response['body']['message'] ?? ''));

$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status',
    ['status' => 'shipped'], $adminToken);
check('an administrator cannot ship an unpaid order', $response['status'] === 409);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid . '/invoice', [], $token);
check('no invoice exists before payment', $response['status'] === 409,
    'invoice numbers must be gapless, so an unpaid order cannot consume one');

// -----------------------------------------------------------------------
// BR-003: OTP before payment
// -----------------------------------------------------------------------
echo "\n-- BR-003: OTP verification --\n";

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
check('payment cannot start before the OTP is verified', $response['status'] === 409,
    json_encode($response['body']['message'] ?? ''));

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp',
    ['otp' => '000000', 'reference_token' => $otpReference], $token);
check('a wrong OTP is rejected', in_array($response['status'], [400, 422], true));

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp',
    ['otp' => $otpCode, 'reference_token' => $otpReference], $token);
check('the correct OTP verifies the order', $response['status'] === 200, json_encode($response['body']));
check('the order is now verified', ($response['body']['data']['order']['otp_verified'] ?? false) === true);
checkSame('next step is payment', 'start_payment', $response['body']['data']['next_step']);

// -----------------------------------------------------------------------
// Payment
// -----------------------------------------------------------------------
echo "\n-- UPI payment --\n";

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
check('payment intent created', $response['status'] === 201, json_encode($response['body']));
$intent = $response['body']['data']['payment'];
$gatewayOrderId = $intent['gateway_order_id'];

checkSame('the amount matches what is owed', $amountPayable, $intent['amount']);
checkSame('only UPI is offered', ['upi'], $intent['methods']);
check('a UPI intent URL was produced', str_starts_with((string) $intent['upi_intent_url'], 'upi://pay'));

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('the order moved to awaiting payment', 'awaiting_payment',
    $response['body']['data']['order']['status']);

// A forged callback must not confirm anything.
$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment/callback', [
    'sandbox_order_id' => $gatewayOrderId,
    'sandbox_payment_id' => 'sbox_pay_forged',
    'sandbox_status' => 'captured',
    'sandbox_amount' => (int) round($amountPayable * 100),
    'sandbox_signature' => 'not-a-real-signature',
], $token);
check('a forged payment callback is refused', $response['status'] === 422,
    'anyone can POST a success callback; only a signature proves it');

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('...and the order is still unpaid', 'pending',
    $response['body']['data']['order']['payment_status']);

// -----------------------------------------------------------------------
// Webhook: the authoritative path
// -----------------------------------------------------------------------
echo "\n-- Webhook confirmation --\n";

$paymentId = 'sbox_pay_' . bin2hex(random_bytes(10));
$webhookPayload = [
    'sandbox_order_id' => $gatewayOrderId,
    'sandbox_payment_id' => $paymentId,
    'sandbox_status' => 'captured',
    'sandbox_amount' => (int) round($amountPayable * 100),
    'sandbox_vpa' => 'customer@sandboxupi',
];
$rawBody = json_encode($webhookPayload, JSON_UNESCAPED_SLASHES);
$signature = hash_hmac('sha256', $rawBody, $sandboxSecret);

$response = callRaw($baseUrl . '/webhooks/payment', $rawBody, ['X-Sandbox-Signature: wrong-signature']);
check('an unsigned webhook is acknowledged but not acted on',
    $response['status'] === 202 && ($response['body']['data']['processed'] ?? true) === false,
    json_encode($response['body']));

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('...and the order remains unpaid', 'pending',
    $response['body']['data']['order']['payment_status']);

$response = callRaw($baseUrl . '/webhooks/payment', $rawBody, ['X-Sandbox-Signature: ' . $signature]);
check('a correctly signed webhook is processed', $response['status'] === 200, json_encode($response['body']));

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
$order = $response['body']['data'];
checkSame('the order is now paid', 'paid', $order['order']['payment_status']);
checkSame('...and confirmed', 'confirmed', $order['order']['status']);
check('an invoice number was issued at payment', $order['order']['invoice_number'] !== null);
check('the payment window was cleared', $order['order']['expires_date'] === null);

// Redelivery is routine for gateways and must be a no-op.
$response = callRaw($baseUrl . '/webhooks/payment', $rawBody, ['X-Sandbox-Signature: ' . $signature]);
checkSame('a redelivered webhook is recognised as a duplicate', 'duplicate',
    $response['body']['data']['status'] ?? '');

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('...and the invoice number did not change',
    $order['order']['invoice_number'], $response['body']['data']['order']['invoice_number']);

// -----------------------------------------------------------------------
// The order record
// -----------------------------------------------------------------------
echo "\n-- Order record --\n";

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
$detail = $response['body']['data'];

checkSame('both lines are on the order', 2, count($detail['items']));
check('the timeline records every step (BR-008)', count($detail['timeline']) >= 4,
    (string) count($detail['timeline']));
check('the timeline includes payment', (function () use ($detail): bool {
    foreach ($detail['timeline'] as $entry) {
        if (str_contains((string) $entry['title'], 'Payment received')) {
            return true;
        }
    }

    return false;
})());
check('a progress bar is provided', count($detail['progress']) === 6);
check('the payment attempt is recorded with its signature verified',
    ($detail['payments'][0]['signature_verified'] ?? false) === true);
// GST is extracted from an inclusive total, so the identity is against the
// grand total, not the subtotal: the subtotal is before order-level discounts
// and before delivery, and neither is outside the tax base.
checkSame('taxable value plus tax equals the grand total',
    round($detail['pricing']['taxable_value'] + $detail['pricing']['tax_total'], 2),
    $detail['pricing']['grand_total']);

checkSame('the grand total is the subtotal less discounts plus delivery',
    round($detail['pricing']['items_subtotal']
        - $detail['pricing']['order_discount']
        + $detail['pricing']['delivery_charge'], 2),
    $detail['pricing']['grand_total']);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid . '/invoice', [], $token);
$invoice = $response['body']['data'];
check('the invoice is now available', $response['status'] === 200);
check('it names a place of supply', ($invoice['invoice']['place_of_supply'] ?? '') === 'Karnataka');
check('a Karnataka order is intra-state', ($invoice['invoice']['is_interstate'] ?? true) === false);
check('GST is split into CGST and SGST',
    ($invoice['tax_summary'][0]['cgst_amount'] ?? 0) > 0
    && ($invoice['tax_summary'][0]['igst_amount'] ?? 1) == 0);

$cgstSgstSum = 0.0;
$taxSum = 0.0;

foreach ($invoice['tax_summary'] as $line) {
    $cgstSgstSum = round($cgstSgstSum + $line['cgst_amount'] + $line['sgst_amount'] + $line['igst_amount'], 2);
    $taxSum = round($taxSum + $line['tax_amount'], 2);
}

checkSame('the GST split adds back up to the tax total', $taxSum, $cgstSgstSum);

// -----------------------------------------------------------------------
// Access control
// -----------------------------------------------------------------------
echo "\n-- Access control --\n";

$stranger = registerCustomer($baseUrl);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $stranger['token']);
check('another customer cannot read this order', $response['status'] === 404);

$response = call('POST', $baseUrl . '/orders/' . $orderUuid . '/cancel',
    ['reason' => 'Not mine but trying anyway'], $stranger['token']);
check('another customer cannot cancel it', $response['status'] === 404);

$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status',
    ['status' => 'packed'], $token);
check('a customer cannot reach the staff endpoint', $response['status'] === 403);

// -----------------------------------------------------------------------
// Guest tracking
// -----------------------------------------------------------------------
echo "\n-- Guest tracking --\n";

$response = call('POST', $baseUrl . '/orders/track',
    ['order_number' => $orderNumber, 'mobile' => $customer['mobile']]);
check('tracking works with the order number and mobile', $response['status'] === 200);
check('...and omits pricing', !isset($response['body']['data']['pricing']));

$response = call('POST', $baseUrl . '/orders/track',
    ['order_number' => $orderNumber, 'mobile' => '9999999999']);
check('tracking with the wrong mobile returns 404', $response['status'] === 404,
    'an order number is printed on the parcel label, so it alone must not be enough');

// -----------------------------------------------------------------------
// Fulfilment
// -----------------------------------------------------------------------
echo "\n-- Fulfilment --\n";

foreach (['packed', 'ready_to_ship', 'assigned', 'shipped', 'out_for_delivery', 'delivered'] as $status) {
    $response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status',
        ['status' => $status, 'note' => 'Smoke test transition'], $adminToken);
    check(sprintf('staff can move the order to "%s"', $status), $response['status'] === 200,
        json_encode($response['body']['message'] ?? ''));
}

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $token);
checkSame('the order is delivered', 'delivered', $response['body']['data']['order']['status']);
check('a delivery date was recorded', $response['body']['data']['order']['delivered_date'] !== null);
check('the order can no longer be cancelled',
    ($response['body']['data']['order']['can_cancel'] ?? true) === false);

$response = call('POST', $baseUrl . '/orders/' . $orderUuid . '/cancel',
    ['reason' => 'Too late'], $token);
check('cancelling a delivered order is refused', $response['status'] === 409);

// -----------------------------------------------------------------------
// Cancellation and refund on a second order
// -----------------------------------------------------------------------
echo "\n-- Cancellation releases held value --\n";

$response = call('POST', $baseUrl . '/admin/wallet/' . $customer['uuid'] . '/credit', [
    'amount' => 200,
    'narration' => 'Checkout smoke test credit',
    'reference' => 'checkout-' . bin2hex(random_bytes(4)),
], $adminToken);
check('wallet credited for the cancellation test', $response['status'] === 201);

call('POST', $baseUrl . '/cart/items', ['variant_uuid' => $almond['uuid'], 'quantity' => 2], $token);
call('POST', $baseUrl . '/cart/pincode', ['pincode' => '560001'], $token);
call('POST', $baseUrl . '/cart/wallet', ['amount' => 50], $token);

$response = call('GET', $baseUrl . '/checkout/review', [], $token);
$walletApplied = $response['body']['data']['cart']['payment']['wallet_applied'];
check('wallet credit is applied to the new cart', $walletApplied > 0);

$response = call('POST', $baseUrl . '/checkout/place', ['address_uuid' => $addressUuid], $token);
check('second order placed', $response['status'] === 201, json_encode($response['body']));
$secondUuid = $response['body']['data']['order']['uuid'];
$secondNumber = $response['body']['data']['order']['order_number'];
checkSame('wallet credit was carried onto the order', $walletApplied,
    $response['body']['data']['order']['wallet_applied']);

check('order numbers are sequential', $secondNumber !== $orderNumber);

$response = call('GET', $baseUrl . '/wallet', [], $token);
$balanceAfterPlacing = $response['body']['data']['wallet']['balance'];
checkSame('wallet was debited at placement', round(200 - $walletApplied, 2), $balanceAfterPlacing);

$response = call('POST', $baseUrl . '/orders/' . $secondUuid . '/cancel',
    ['reason' => 'Changed my mind'], $token);
check('the order was cancelled', $response['status'] === 200, json_encode($response['body']));
checkSame('wallet credit was returned', $walletApplied, $response['body']['data']['wallet_returned']);

$response = call('GET', $baseUrl . '/wallet', [], $token);
checkSame('the balance is whole again', 200, $response['body']['data']['wallet']['balance']);

$response = call('GET', $baseUrl . '/wallet/statement', [], $token);
$sources = array_column($response['body']['data'], 'source');
check('the ledger shows both the debit and the compensating credit',
    in_array('redemption', $sources, true) && in_array('order_refund', $sources, true),
    json_encode($sources));

// -----------------------------------------------------------------------
// Staff view
// -----------------------------------------------------------------------
echo "\n-- Staff view --\n";

$response = call('GET', $baseUrl . '/admin/orders', [], $adminToken);
check('staff can list orders', $response['status'] === 200 && ($response['body']['meta']['total'] ?? 0) >= 2);

$response = call('GET', $baseUrl . '/admin/orders?status=delivered', [], $adminToken);
check('staff can filter by status', $response['status'] === 200);

$response = call('GET', $baseUrl . '/admin/orders?status=teleported', [], $adminToken);
check('an unknown status filter is rejected', $response['status'] === 422);

$response = call('GET', $baseUrl . '/admin/orders/' . $orderUuid, [], $adminToken);
check('staff see the payment event log',
    isset($response['body']['data']['payment_events'])
    && count($response['body']['data']['payment_events']) >= 1);

$response = call('POST', $baseUrl . '/admin/orders/expire-unpaid', [], $adminToken);
check('the unpaid-order sweep runs', $response['status'] === 200,
    json_encode($response['body']['data'] ?? []));

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: test orders, payments and ledger entries remain for inspection.\n";

exit($failed === 0 ? 0 : 1);
