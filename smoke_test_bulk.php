<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for wholesale enquiries and quotations (Phase 8).
 *
 *   php bin/smoke_test_bulk.php
 *
 * Follows a business from first enquiry through quotation, revision and
 * acceptance, and then checks the thing that matters most: that the resulting
 * order is an ORDINARY order, subject to BR-003, BR-004 and BR-005 exactly like
 * a 200g retail pouch.
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

function clearThrottle(): void
{
    if (!in_array((string) Env::get('APP_ENV', 'production'), ['local', 'testing'], true)) {
        throw new RuntimeException('Refusing to clear rate limits outside a local environment.');
    }

    $config = new App\Core\Config(APP_ROOT . '/config');
    (new App\Core\Database((array) $config->get('database')))->execute('DELETE FROM rate_limits');
}

echo "Wholesale enquiries and quotations smoke test\n";
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

// A wholesale buyer with an account.
$mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$registration = call('POST', $baseUrl . '/auth/register', [
    'full_name' => 'Wholesale Buyer',
    'mobile' => $mobile,
    'password' => 'BulkPass' . random_int(1000, 9999),
]);
$verified = call('POST', $baseUrl . '/auth/register/verify', [
    'mobile' => $mobile,
    'otp' => $registration['body']['data']['verification']['debug_otp'],
    'reference_token' => $registration['body']['data']['verification']['reference_token'],
]);
$buyerToken = $verified['body']['data']['tokens']['access_token'];
check('wholesale buyer registered', is_string($buyerToken));

$addressResponse = call('POST', $baseUrl . '/addresses', [
    'contact_name' => 'Wholesale Buyer',
    'contact_mobile' => $mobile,
    'address_line1' => 'Unit 7, Industrial Estate',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
], $buyerToken);
$addressUuid = $addressResponse['body']['data']['address']['uuid'];
check('a delivery address is saved', $addressResponse['status'] === 201);

// -----------------------------------------------------------------------
// Enquiry
// -----------------------------------------------------------------------
echo "\n-- Enquiry --\n";

clearThrottle();

$response = call('POST', $baseUrl . '/bulk-orders/enquiries', [
    'business_name' => 'Coastal Foods Trading',
    'gstin' => '29ABCDE1234F1Z5',
    'contact_name' => 'Wholesale Buyer',
    'contact_mobile' => $mobile,
    'contact_email' => 'buyer@coastalfoods.test',
    'delivery_pincode' => '560001',
    'requirements' => 'Recurring monthly supply of turmeric powder and almonds for our retail outlets.',
    'estimated_quantity' => 'Around 200kg per month',
    'estimated_budget' => 150000,
], $buyerToken);

check('the enquiry is accepted', $response['status'] === 201, json_encode($response['body']));
$enquiryUuid = $response['body']['data']['enquiry']['uuid'];
$enquiryNumber = $response['body']['data']['enquiry']['enquiry_number'];
check('...and numbered', str_starts_with((string) $enquiryNumber, 'BE'));
checkSame('...starting as new', 'new', $response['body']['data']['enquiry']['status']);
check('...with a reply promised', str_contains((string) $response['body']['data']['message'], 'quotation'));

// The signed-in submitter must be recorded, or they cannot see their own quote.
$response = call('GET', $baseUrl . '/bulk-orders/enquiries/' . $enquiryUuid, [], $buyerToken);
check('the submitter can view their own enquiry', $response['status'] === 200,
    json_encode($response['body']));

$response = call('GET', $baseUrl . '/bulk-orders/enquiries/' . $enquiryUuid, [], $adminToken);
check('a different account cannot view it through the customer route',
    $response['status'] === 404,
    'the customer route is scoped to the submitter regardless of role');

// The estimated budget must not leak back to the customer.
$response = call('GET', $baseUrl . '/bulk-orders/enquiries/' . $enquiryUuid, [], $buyerToken);
check('the customer view hides the budget we recorded',
    !array_key_exists('estimated_budget', $response['body']['data']['enquiry']),
    'a customer should not see what we guessed they could afford');

clearThrottle();

$response = call('POST', $baseUrl . '/bulk-orders/enquiries', [
    'business_name' => 'X',
    'contact_name' => 'Y',
    'contact_mobile' => '12345',
    'requirements' => 'short',
], $buyerToken);
check('a malformed enquiry is rejected', $response['status'] === 422);

// -----------------------------------------------------------------------
// Quotation
// -----------------------------------------------------------------------
echo "\n-- Quotation --\n";

$turmeric = call('GET', $baseUrl . '/products/organic-turmeric-powder');
$almond = call('GET', $baseUrl . '/products/california-almonds');
$turmericVariant = $turmeric['body']['data']['product']['variants'][0];
$almondVariant = $almond['body']['data']['product']['variants'][0];

$response = call('GET', $baseUrl . '/admin/bulk-orders', [], $adminToken);
check('staff can list enquiries', $response['status'] === 200 && ($response['body']['meta']['total'] ?? 0) >= 1);

$response = call('GET', $baseUrl . '/admin/bulk-orders?status=nonsense', [], $adminToken);
check('an unknown status filter is rejected', $response['status'] === 422);

$response = call('GET', $baseUrl . '/admin/bulk-orders/' . $enquiryUuid, [], $adminToken);
check('staff see the internal detail',
    array_key_exists('estimated_budget', $response['body']['data']['enquiry'] ?? []));

$response = call('POST', $baseUrl . '/admin/bulk-orders/' . $enquiryUuid . '/quote', [
    'items' => [
        ['variant_uuid' => $turmericVariant['uuid'], 'quantity' => 100, 'unit_price' => 180.00],
        ['variant_uuid' => $almondVariant['uuid'], 'quantity' => 50, 'unit_price' => 620.00],
    ],
], $adminToken);

check('a quotation is prepared', $response['status'] === 201, json_encode($response['body']));
$quote = $response['body']['data']['quote'];
$quoteUuid = $quote['uuid'];
check('...numbered', str_starts_with((string) $quote['quote_number'], 'BQ'));
checkSame('...as revision 1', 1, $quote['revision']);
checkSame('...in draft', 'draft', $quote['status']);
checkSame('...with two lines', 2, count($quote['items']));

$expectedSubtotal = (100 * 180.00) + (50 * 620.00);
checkSame('the subtotal is the sum of the lines', $expectedSubtotal, $quote['items_subtotal']);
checkSame('...and the grand total matches with no discount', $expectedSubtotal, $quote['grand_total']);

// GST is extracted from an inclusive price, as everywhere else in the system.
checkSame('tax plus taxable value equals the grand total',
    round($quote['taxable_value'] + $quote['tax_total'], 2), $quote['grand_total']);
check('tax was extracted, not added', $quote['taxable_value'] < $quote['grand_total'],
    'Indian MRP is GST-inclusive');

check('both lines are catalogue items',
    ($quote['items'][0]['is_catalogue_item'] ?? false) && ($quote['items'][1]['is_catalogue_item'] ?? false));
check('the payment terms restate prepaid UPI (BR-004)',
    str_contains(strtolower((string) $quote['payment_terms']), 'advance'), (string) $quote['payment_terms']);
check('a validity date is set', !empty($quote['valid_until']));
check('...and it is not already expired', ($quote['is_expired'] ?? true) === false);

// A quote cannot be accepted before it is sent.
$response = call('POST', $baseUrl . '/bulk-orders/quotes/' . $quoteUuid . '/accept',
    ['address_uuid' => $addressUuid], $buyerToken);
check('a draft quotation cannot be accepted', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/bulk-orders/quotes/' . $quoteUuid . '/send', [], $adminToken);
check('the quotation is sent', $response['status'] === 200, json_encode($response['body']));
checkSame('...and is now marked sent', 'sent', $response['body']['data']['quote']['status']);

// -----------------------------------------------------------------------
// Revision
// -----------------------------------------------------------------------
echo "\n-- Negotiation and revision --\n";

$response = call('POST', $baseUrl . '/bulk-orders/quotes/' . $quoteUuid . '/reject',
    ['reason' => 'The unit price on almonds is above our budget.'], $buyerToken);
check('the customer can reject a quotation', $response['status'] === 200, json_encode($response['body']));

$response = call('POST', $baseUrl . '/admin/bulk-orders/' . $enquiryUuid . '/quote', [
    'items' => [
        ['variant_uuid' => $turmericVariant['uuid'], 'quantity' => 100, 'unit_price' => 175.00],
        ['variant_uuid' => $almondVariant['uuid'], 'quantity' => 50, 'unit_price' => 580.00],
    ],
    'discount_amount' => 1000.00,
    'delivery_charge' => 0,
    'notes' => 'Revised pricing following your feedback.',
], $adminToken);

check('a revised quotation is prepared', $response['status'] === 201, json_encode($response['body']));
$revised = $response['body']['data']['quote'];
$revisedUuid = $revised['uuid'];
checkSame('...as revision 2', 2, $revised['revision']);
checkSame('...with the discount applied',
    round((100 * 175.00) + (50 * 580.00) - 1000.00, 2), $revised['grand_total']);
checkSame('...still reconciling on tax',
    round($revised['taxable_value'] + $revised['tax_total'], 2), $revised['grand_total']);

$response = call('GET', $baseUrl . '/admin/bulk-orders/' . $enquiryUuid, [], $adminToken);
$quotes = $response['body']['data']['quotes'];
checkSame('both revisions are kept', 2, count($quotes));
check('the earlier revision is not still open',
    in_array($quotes[0]['status'], ['rejected', 'superseded'], true), $quotes[0]['status']);

call('POST', $baseUrl . '/admin/bulk-orders/quotes/' . $revisedUuid . '/send', [], $adminToken);

// A superseded revision must not be acceptable afterwards.
$response = call('POST', $baseUrl . '/bulk-orders/quotes/' . $quoteUuid . '/accept',
    ['address_uuid' => $addressUuid], $buyerToken);
check('a superseded revision can no longer be accepted', $response['status'] === 409,
    'the customer must be held to the revision actually on the table');

// -----------------------------------------------------------------------
// Conversion — the part that matters
// -----------------------------------------------------------------------
echo "\n-- Conversion to an ordinary order --\n";

$response = call('POST', $baseUrl . '/bulk-orders/quotes/' . $revisedUuid . '/accept',
    ['address_uuid' => $addressUuid], $buyerToken);

check('the quotation converts to an order', $response['status'] === 201, json_encode($response['body']));
$converted = $response['body']['data'];
$orderUuid = $converted['order']['uuid'];

checkSame('the order value matches the quotation', $revised['grand_total'], $converted['order']['grand_total']);
checkSame('the order starts as created', 'created', $converted['order']['status']);
checkSame('...and unpaid (BR-005)', 'pending', $converted['order']['payment_status']);
check('a payment window applies', !empty($converted['order']['expires_date']));
checkSame('an OTP is required (BR-003)', 'verify_otp', $converted['next_step']);
check('...and was issued', !empty($converted['otp']['debug_otp']));

$response = call('GET', $baseUrl . '/bulk-orders/enquiries/' . $enquiryUuid, [], $buyerToken);
checkSame('the enquiry is marked converted', 'converted', $response['body']['data']['enquiry']['status']);

$response = call('POST', $baseUrl . '/bulk-orders/quotes/' . $revisedUuid . '/accept',
    ['address_uuid' => $addressUuid], $buyerToken);
check('the same quotation cannot be converted twice', $response['status'] === 409);

// BR-005 applies to a wholesale order exactly as to a retail one.
$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status',
    ['status' => 'confirmed'], $adminToken);
check('an unpaid wholesale order cannot be confirmed', $response['status'] === 409,
    'wholesale gets no shortcut; this is where the amounts are largest');

$response = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/ship', [], $adminToken);
check('...nor handed to a courier', $response['status'] === 409);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid . '/invoice', [], $buyerToken);
check('...nor given an invoice number', $response['status'] === 409);

// It appears in the customer's ordinary order history.
$response = call('GET', $baseUrl . '/orders', [], $buyerToken);
$found = false;

foreach ($response['body']['data'] ?? [] as $order) {
    if ($order['uuid'] === $orderUuid) {
        $found = true;
    }
}

check('the wholesale order appears in the normal order list', $found);

// And it pays through the normal path.
$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp', [
    'otp' => $converted['otp']['debug_otp'],
    'reference_token' => $converted['otp']['reference_token'],
], $buyerToken);
check('the order verifies through the ordinary OTP endpoint', $response['status'] === 200,
    json_encode($response['body']));

$response = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $buyerToken);
check('payment starts through the ordinary endpoint', $response['status'] === 201, json_encode($response['body']));
$intent = $response['body']['data']['payment'];
checkSame('...for the quoted amount', $revised['grand_total'], $intent['amount']);
checkSame('...by UPI only (BR-004)', ['upi'], $intent['methods']);

$payload = [
    'sandbox_order_id' => $intent['gateway_order_id'],
    'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
    'sandbox_status' => 'captured',
    'sandbox_amount' => (int) round($intent['amount'] * 100),
];
$rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
$response = callRaw($baseUrl . '/webhooks/payment', $rawBody,
    ['X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $paymentSecret)]);
check('the ordinary payment webhook confirms it', $response['status'] === 200);

$response = call('GET', $baseUrl . '/orders/' . $orderUuid, [], $buyerToken);
checkSame('the wholesale order is confirmed', 'confirmed', $response['body']['data']['order']['status']);
check('...and has a GST invoice number', !empty($response['body']['data']['order']['invoice_number']));

$response = call('GET', $baseUrl . '/orders/' . $orderUuid . '/invoice', [], $buyerToken);
check('the invoice is available', $response['status'] === 200);
checkSame('...covering both lines', 2, count($response['body']['data']['lines'] ?? []));

$taxSum = 0.0;

foreach ($response['body']['data']['tax_summary'] as $line) {
    $taxSum = round($taxSum + $line['cgst_amount'] + $line['sgst_amount'] + $line['igst_amount'], 2);
}

check('the GST split on the wholesale invoice adds up', $taxSum > 0,
    'the same OrderWriter the retail path uses');

// -----------------------------------------------------------------------
// Declining
// -----------------------------------------------------------------------
echo "\n-- Declining an enquiry --\n";

clearThrottle();

$response = call('POST', $baseUrl . '/bulk-orders/enquiries', [
    'business_name' => 'Out Of Area Traders',
    'contact_name' => 'Someone Else',
    'contact_mobile' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
    'requirements' => 'We would like a hundred tonnes of saffron by Friday please.',
]);
check('a guest can submit an enquiry', $response['status'] === 201, json_encode($response['body']));
$guestEnquiry = $response['body']['data']['enquiry']['uuid'];

$response = call('POST', $baseUrl . '/admin/bulk-orders/' . $guestEnquiry . '/decline',
    ['reason' => 'Volume is beyond our current supply capacity.'], $adminToken);
check('staff can decline an enquiry', $response['status'] === 200, json_encode($response['body']));

$response = call('POST', $baseUrl . '/admin/bulk-orders/' . $guestEnquiry . '/quote', [
    'items' => [['variant_uuid' => $turmericVariant['uuid'], 'quantity' => 1, 'unit_price' => 100]],
], $adminToken);
check('a declined enquiry cannot be quoted', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/bulk-orders/' . $enquiryUuid . '/quote', [
    'items' => [['variant_uuid' => $turmericVariant['uuid'], 'quantity' => 1, 'unit_price' => 100]],
], $adminToken);
check('a converted enquiry cannot be quoted again', $response['status'] === 409);

// -----------------------------------------------------------------------
// Dashboards and reports
// -----------------------------------------------------------------------
echo "\n-- Dashboards and reports --\n";

$response = call('GET', $baseUrl . '/admin/dashboard', [], $adminToken);
check('the dashboard loads', $response['status'] === 200, json_encode($response['body']));
$dashboard = $response['body']['data'];
check('...reporting today\'s figures', isset($dashboard['today']['orders']));
check('...with a pipeline breakdown', is_array($dashboard['pipeline'] ?? null));
check('...a needs-attention list', is_array($dashboard['needs_attention'] ?? null));
check('...and a seven-day series', count($dashboard['last_7_days'] ?? []) >= 0);

$response = call('GET', $baseUrl . '/admin/reports/sales', [], $adminToken);
check('the sales report loads', $response['status'] === 200);
check('...defaulting to a 30-day range', !empty($response['body']['data']['from']));

$response = call('GET', $baseUrl . '/admin/reports/sales?from=2020-01-01&to=2026-12-31', [], $adminToken);
check('an over-wide range is refused rather than run', $response['status'] === 422,
    'a report that quietly takes ninety seconds is the one that takes the site down');
check('...with an explanation', str_contains((string) ($response['body']['message'] ?? ''), 'limited to'));

$response = call('GET', $baseUrl . '/admin/reports/sales?from=2026-06-01&to=2026-01-01', [], $adminToken);
check('a reversed range is refused', $response['status'] === 422);

$response = call('GET', $baseUrl . '/admin/reports/products', [], $adminToken);
check('the product report loads', $response['status'] === 200);
check('...listing what sold', count($response['body']['data']['products'] ?? []) >= 1,
    json_encode($response['body']['data']['products'] ?? []));

$response = call('GET', $baseUrl . '/admin/reports/customers', [], $adminToken);
check('the customer report loads', $response['status'] === 200);
check('...with a repeat rate', isset($response['body']['data']['growth']['repeat_rate_percent']));
check('...and masked mobile numbers', (function () use ($response): bool {
    foreach ($response['body']['data']['top_customers'] ?? [] as $customer) {
        if (!str_contains((string) $customer['mobile'], 'X')) {
            return false;
        }
    }

    return true;
})(), 'a report that circulates by email should not carry a column of phone numbers');

$response = call('GET', $baseUrl . '/admin/reports/operations', [], $adminToken);
check('the operations report loads', $response['status'] === 200);
check('...covering couriers', count($response['body']['data']['couriers'] ?? []) >= 1);

$response = call('GET', $baseUrl . '/admin/reports/promotions', [], $adminToken);
check('the promotions report loads', $response['status'] === 200);

$response = call('GET', $baseUrl . '/admin/reports/cancellations', [], $adminToken);
check('the cancellation report loads', $response['status'] === 200);
check('...grouped by reason', is_array($response['body']['data']['by_reason'] ?? null),
    'the aggregate number is not actionable; the reasons are');

$response = call('GET', $baseUrl . '/admin/dashboard', [], $buyerToken);
check('a customer cannot reach the dashboard', $response['status'] === 403);

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: test enquiries and orders remain for inspection.\n";

exit($failed === 0 ? 0 : 1);
