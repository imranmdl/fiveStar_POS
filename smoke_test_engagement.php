<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for reviews, support and content (Phase 10).
 *
 *   php bin/smoke_test_engagement.php
 *
 * The assertion that matters most: a customer cannot review a product they have
 * not received. Everything else here is workflow; that one is the difference
 * between a rating that means something and one that means whoever cared most.
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

function registerCustomer(string $baseUrl, string $name): array
{
    clearThrottle();
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $r = call('POST', $baseUrl . '/auth/register', [
        'full_name' => $name,
        'mobile' => $mobile,
        'password' => 'Engage' . random_int(100000, 999999),
    ]);

    if ($r['status'] !== 201) {
        throw new RuntimeException('Registration failed: ' . json_encode($r['body']));
    }

    $v = call('POST', $baseUrl . '/auth/register/verify', [
        'mobile' => $mobile,
        'otp' => $r['body']['data']['verification']['debug_otp'],
        'reference_token' => $r['body']['data']['verification']['reference_token'],
    ]);

    return ['mobile' => $mobile, 'token' => $v['body']['data']['tokens']['access_token'], 'name' => $name];
}

/** Places, pays for and delivers an order, so the customer can review. */
function deliverOrder(string $baseUrl, string $token, string $mobile, string $adminToken,
                      string $paymentSecret, string $courierSecret, string $productSlug): string
{
    $address = call('POST', $baseUrl . '/addresses', [
        'contact_name' => 'Reviewer',
        'contact_mobile' => $mobile,
        'address_line1' => '21 Cunningham Road',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'pincode' => '560001',
    ], $token);

    $product = call('GET', $baseUrl . '/products/' . $productSlug);
    call('POST', $baseUrl . '/cart/items', [
        'variant_uuid' => $product['body']['data']['product']['variants'][0]['uuid'],
        'quantity' => 1,
    ], $token);

    $placed = call('POST', $baseUrl . '/checkout/place',
        ['address_uuid' => $address['body']['data']['address']['uuid']], $token);
    $orderUuid = $placed['body']['data']['order']['uuid'];

    call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/verify-otp', [
        'otp' => $placed['body']['data']['otp']['debug_otp'],
        'reference_token' => $placed['body']['data']['otp']['reference_token'],
    ], $token);

    $payment = call('POST', $baseUrl . '/checkout/orders/' . $orderUuid . '/payment', [], $token);
    $payload = [
        'sandbox_order_id' => $payment['body']['data']['payment']['gateway_order_id'],
        'sandbox_payment_id' => 'sbox_pay_' . bin2hex(random_bytes(10)),
        'sandbox_status' => 'captured',
        'sandbox_amount' => (int) round($payment['body']['data']['payment']['amount'] * 100),
    ];
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    callRaw($baseUrl . '/webhooks/payment', $rawBody,
        ['X-Sandbox-Signature: ' . hash_hmac('sha256', $rawBody, $paymentSecret)]);

    call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/status', ['status' => 'packed'], $adminToken);
    $shipment = call('POST', $baseUrl . '/admin/orders/' . $orderUuid . '/ship', [], $adminToken);
    $awb = $shipment['body']['data']['awb_number'];

    foreach (['picked_up', 'delivered'] as $index => $status) {
        $body = json_encode([
            'awb' => $awb,
            'events' => [[
                'status' => $status,
                'title' => ucfirst(str_replace('_', ' ', $status)),
                'occurred_at' => date('Y-m-d H:i:s', time() - (7200 - ($index * 3600))),
                'event_id' => $awb . ':eng:' . $index,
            ]],
        ], JSON_UNESCAPED_SLASHES);
        callRaw($baseUrl . '/webhooks/tracking', $body,
            ['X-Sandbox-Signature: ' . hash_hmac('sha256', $body, $courierSecret)]);
    }

    return $orderUuid;
}

echo "Reviews, support and content smoke test\n";
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
// Reviews require a delivered order
// -----------------------------------------------------------------------
echo "\n-- Reviews require a delivered order --\n";

$stranger = registerCustomer($baseUrl, 'Never Bought');

$response = call('POST', $baseUrl . '/products/california-almonds/reviews',
    ['rating' => 5, 'title' => 'Great', 'body' => 'I have never bought this.'], $stranger['token']);
check('someone who never bought it cannot review it', $response['status'] === 403,
    json_encode($response['body']['message'] ?? ''));
check('...and is told why',
    str_contains(strtolower((string) ($response['body']['message'] ?? '')), 'delivered'));

$buyer = registerCustomer($baseUrl, 'Genuine Buyer');
$orderUuid = deliverOrder($baseUrl, $buyer['token'], $buyer['mobile'], $adminToken,
    $paymentSecret, $courierSecret, 'california-almonds');
check('a real order was delivered', true);

$response = call('GET', $baseUrl . '/reviews/awaiting', [], $buyer['token']);
check('the delivered product is offered for review',
    count($response['body']['data']['products'] ?? []) >= 1,
    json_encode($response['body']['data'] ?? []));

$response = call('POST', $baseUrl . '/products/california-almonds/reviews', [
    'rating' => 4,
    'title' => 'Good quality almonds',
    'body' => 'Fresh and well packed. Arrived quickly and the pack was properly sealed.',
], $buyer['token']);
check('the buyer can review', $response['status'] === 201, json_encode($response['body']));
$reviewUuid = $response['body']['data']['review']['uuid'];
check('...flagged as a verified purchase',
    ($response['body']['data']['review']['is_verified_purchase'] ?? false) === true);
check('...and held for moderation',
    ($response['body']['data']['awaiting_moderation'] ?? false) === true);
checkSame('...with status pending', 'pending', $response['body']['data']['review']['status']);

// -----------------------------------------------------------------------
// Moderation
// -----------------------------------------------------------------------
echo "\n-- Moderation --\n";

$response = call('GET', $baseUrl . '/products/california-almonds/reviews');
checkSame('an unmoderated review is not public yet', 0, count($response['body']['data'] ?? []));

$response = call('GET', $baseUrl . '/admin/reviews', [], $adminToken);
check('it appears in the moderation queue', ($response['body']['meta']['total'] ?? 0) >= 1);

$response = call('POST', $baseUrl . '/admin/reviews/' . $reviewUuid . '/moderate',
    ['decision' => 'approved'], $adminToken);
check('a moderator can approve it', $response['status'] === 200, json_encode($response['body']));

$response = call('GET', $baseUrl . '/products/california-almonds/reviews');
checkSame('it is now public', 1, count($response['body']['data'] ?? []));
$public = $response['body']['data'][0];
checkSame('...with the rating', 4, $public['rating']);
check('...marked verified', ($public['is_verified_purchase'] ?? false) === true);
check('...showing only a first name and initial',
    isset($public['author']) && !str_contains((string) $public['author'], 'Buyer'),
    (string) ($public['author'] ?? ''));
check('...and no moderation detail leaked', !array_key_exists('moderation_note', $public));

$summary = $response['body']['meta']['summary'] ?? [];
checkSame('the summary average matches', 4.0, (float) ($summary['rating_average'] ?? 0));
checkSame('...counting one review', 1, (int) ($summary['review_count'] ?? 0));
checkSame('...distributed at four stars', 1, (int) ($summary['distribution'][4] ?? 0));

$product = db()->selectOne("SELECT rating_average, rating_count FROM products WHERE slug = 'california-almonds'");
checkSame('the product aggregate was recomputed', 4.0, (float) $product['rating_average']);
checkSame('...with a count of one', 1, (int) $product['rating_count']);

// -----------------------------------------------------------------------
// One review per customer, edits return to moderation
// -----------------------------------------------------------------------
echo "\n-- Editing --\n";

$response = call('POST', $baseUrl . '/products/california-almonds/reviews', [
    'rating' => 5,
    'title' => 'Even better second time',
    'body' => 'Ordered again and the quality was just as good.',
], $buyer['token']);
check('a second submission replaces the first', $response['status'] === 201, json_encode($response['body']));
check('...rather than stacking', ($response['body']['data']['replaced'] ?? false) === true);
check('...and returns to moderation',
    ($response['body']['data']['awaiting_moderation'] ?? false) === true,
    'otherwise an approved review is an open door: post something bland, then edit it');

$count = (int) db()->scalar(
    "SELECT COUNT(*) FROM product_reviews r
       JOIN products p ON p.id = r.product_id
      WHERE p.slug = 'california-almonds' AND r.is_deleted = 0"
);
checkSame('still exactly one review row', 1, $count);

$response = call('GET', $baseUrl . '/products/california-almonds/reviews');
checkSame('the edited review is no longer public', 0, count($response['body']['data'] ?? []));

$product = db()->selectOne("SELECT rating_average FROM products WHERE slug = 'california-almonds'");
checkSame('...and the aggregate dropped back to zero', 0.0, (float) $product['rating_average'],
    'a recomputed average cannot drift; an incremented one would still say 4.0');

call('POST', $baseUrl . '/admin/reviews/' . $reviewUuid . '/moderate', ['decision' => 'approved'], $adminToken);

// -----------------------------------------------------------------------
// Reporting and voting
// -----------------------------------------------------------------------
echo "\n-- Reporting and voting --\n";

$response = call('POST', $baseUrl . '/reviews/' . $reviewUuid . '/report',
    ['reason' => 'fake'], $buyer['token']);
check('you cannot report your own review', $response['status'] === 422);

$response = call('POST', $baseUrl . '/reviews/' . $reviewUuid . '/vote',
    ['helpful' => true], $buyer['token']);
check('you cannot vote on your own review', $response['status'] === 422);

$voters = [];

for ($i = 0; $i < 3; ++$i) {
    $voters[] = registerCustomer($baseUrl, 'Voter ' . $i);
}

$response = call('POST', $baseUrl . '/reviews/' . $reviewUuid . '/vote',
    ['helpful' => true], $voters[0]['token']);
check('another customer can mark it helpful', $response['status'] === 200, json_encode($response['body']));
checkSame('...counted once', 1, $response['body']['data']['helpful_count']);

$response = call('POST', $baseUrl . '/reviews/' . $reviewUuid . '/vote',
    ['helpful' => false], $voters[0]['token']);
checkSame('changing a vote does not double count, helpful', 0, $response['body']['data']['helpful_count']);
checkSame('...and moves to not helpful', 1, $response['body']['data']['not_helpful_count']);

foreach ($voters as $index => $voter) {
    $response = call('POST', $baseUrl . '/reviews/' . $reviewUuid . '/report',
        ['reason' => 'spam', 'detail' => 'Reported by voter ' . $index], $voter['token']);
    check(sprintf('report %d accepted', $index + 1), $response['status'] === 200,
        json_encode($response['body']));
}

$response = call('POST', $baseUrl . '/reviews/' . $reviewUuid . '/report',
    ['reason' => 'spam'], $voters[0]['token']);
check('the same person reporting twice is a no-op',
    ($response['body']['data']['already_reported'] ?? false) === true,
    'one determined complainant must not be able to bury a review');

$review = db()->selectOne('SELECT status FROM product_reviews WHERE uuid = :u', ['u' => $reviewUuid]);
checkSame('three reports hide the review pending moderation', 'hidden', $review['status']);

$response = call('GET', $baseUrl . '/products/california-almonds/reviews');
checkSame('...so it is no longer public', 0, count($response['body']['data'] ?? []));

// -----------------------------------------------------------------------
// Support tickets
// -----------------------------------------------------------------------
echo "\n-- Support tickets --\n";

$response = call('POST', $baseUrl . '/support/tickets', [
    'subject' => 'Parcel arrived with a torn pack',
    'message' => 'One of the almond packs was torn on arrival. Photographs attached separately.',
    'category' => 'delivery',
    'order_uuid' => $orderUuid,
    'contact_name' => 'Genuine Buyer',
    'contact_mobile' => $buyer['mobile'],
], $buyer['token']);

check('a ticket is raised', $response['status'] === 201, json_encode($response['body']));
$ticketUuid = $response['body']['data']['ticket']['uuid'];
check('...numbered', str_starts_with((string) $response['body']['data']['ticket']['ticket_number'], 'TKT'));
checkSame('...starting open', 'open', $response['body']['data']['ticket']['status']);

$response = call('POST', $baseUrl . '/support/tickets', [
    'subject' => 'About an order that is not mine',
    'message' => 'Trying to attach someone else\'s order to my ticket.',
    'order_uuid' => $orderUuid,
    'contact_name' => 'Never Bought',
    'contact_mobile' => $stranger['mobile'],
], $stranger['token']);
check('a ticket cannot be attached to someone else\'s order', $response['status'] === 404,
    'a ticket carries order details into the reply thread');

$response = call('GET', $baseUrl . '/support/tickets/' . $ticketUuid, [], $buyer['token']);
check('the customer sees their ticket', $response['status'] === 200);
checkSame('...with their opening message', 1, count($response['body']['data']['messages'] ?? []));

$response = call('GET', $baseUrl . '/support/tickets/' . $ticketUuid, [], $stranger['token']);
check('another customer cannot read it', $response['status'] === 404);

// Internal notes must never reach the customer.
$response = call('POST', $baseUrl . '/admin/support/tickets/' . $ticketUuid . '/reply', [
    'body' => 'Courier has a history of rough handling on this route. Check before replying.',
    'internal_note' => true,
], $adminToken);
check('staff can add an internal note', $response['status'] === 200, json_encode($response['body']));

$response = call('GET', $baseUrl . '/support/tickets/' . $ticketUuid, [], $buyer['token']);
checkSame('the customer does not see the internal note', 1, count($response['body']['data']['messages'] ?? []));

$response = call('GET', $baseUrl . '/admin/support/tickets/' . $ticketUuid, [], $adminToken);
checkSame('staff do see it', 2, count($response['body']['data']['messages'] ?? []));

$ticket = db()->selectOne('SELECT first_response_date FROM support_tickets WHERE uuid = :u', ['u' => $ticketUuid]);
check('an internal note does not stop the first-response clock', $ticket['first_response_date'] === null,
    'recording it as a response would make the SLA report flattering and useless');

$response = call('POST', $baseUrl . '/admin/support/tickets/' . $ticketUuid . '/reply', [
    'body' => 'Sorry about that. We are sending a replacement pack today at no charge.',
], $adminToken);
check('staff can reply to the customer', $response['status'] === 200);

$ticket = db()->selectOne('SELECT first_response_date, status FROM support_tickets WHERE uuid = :u', ['u' => $ticketUuid]);
check('a real reply starts the first-response clock', $ticket['first_response_date'] !== null);
checkSame('...and moves it to awaiting customer', 'awaiting_customer', $ticket['status']);

$response = call('POST', $baseUrl . '/support/tickets/' . $ticketUuid . '/reply',
    ['body' => 'Thank you, that works.'], $buyer['token']);
check('the customer can reply', $response['status'] === 200);

$response = call('POST', $baseUrl . '/admin/support/tickets/' . $ticketUuid . '/resolve',
    ['note' => 'Replacement pack dispatched and customer confirmed.'], $adminToken);
check('the ticket resolves', $response['status'] === 200, json_encode($response['body']));

$response = call('POST', $baseUrl . '/support/tickets/' . $ticketUuid . '/rate',
    ['rating' => 5, 'comment' => 'Quick and helpful.'], $buyer['token']);
check('the customer can rate the support', $response['status'] === 200);

// A reply to a resolved ticket reopens it rather than vanishing.
$response = call('POST', $baseUrl . '/support/tickets/' . $ticketUuid . '/reply',
    ['body' => 'The replacement has not arrived yet.'], $buyer['token']);
check('replying to a resolved ticket is allowed', $response['status'] === 200);

$ticket = db()->selectOne('SELECT status, reopened_count FROM support_tickets WHERE uuid = :u', ['u' => $ticketUuid]);
checkSame('...and reopens it', 'open', $ticket['status']);
checkSame('...counting the reopen', 1, (int) $ticket['reopened_count']);

$response = call('GET', $baseUrl . '/admin/support/tickets?status=open', [], $adminToken);
check('staff can filter by status', $response['status'] === 200);

$response = call('GET', $baseUrl . '/admin/support/tickets?status=nonsense', [], $adminToken);
check('an unknown status is rejected', $response['status'] === 422);

// -----------------------------------------------------------------------
// Content
// -----------------------------------------------------------------------
echo "\n-- Content --\n";

$response = call('GET', $baseUrl . '/content/pages');
check('published pages load', $response['status'] === 200);
check('...including the policy pages', count($response['body']['data']['pages'] ?? []) >= 4);

$response = call('GET', $baseUrl . '/content/pages/returns-and-refunds');
check('a policy page loads by slug', $response['status'] === 200);
check('...marked as a system page', ($response['body']['data']['page']['is_system_page'] ?? false) === true);

$response = call('DELETE', $baseUrl . '/admin/content/pages/returns-and-refunds', [], $adminToken);
check('a policy page cannot be deleted', $response['status'] === 409,
    'it has to stay reproducible as it stood on the day of a disputed order');

$response = call('POST', $baseUrl . '/admin/content/pages', [
    'title' => 'About Our Sourcing',
    'body' => 'We buy directly from growers in Kerala, Karnataka and Kashmir. This is a test page.',
    'status' => 'draft',
], $adminToken);
check('a new page can be created', $response['status'] === 201, json_encode($response['body']));
$pageSlug = $response['body']['data']['page']['slug'];

$response = call('GET', $baseUrl . '/content/pages/' . $pageSlug);
check('a draft is not publicly readable, even by direct link', $response['status'] === 404,
    'a half-written policy page is exactly what gets shared before it is ready');

call('PATCH', $baseUrl . '/admin/content/pages/' . $pageSlug, ['status' => 'published'], $adminToken);
$response = call('GET', $baseUrl . '/content/pages/' . $pageSlug);
check('once published it is readable', $response['status'] === 200);

$response = call('DELETE', $baseUrl . '/admin/content/pages/' . $pageSlug, [], $adminToken);
check('a non-system page can be deleted', $response['status'] === 200);

$response = call('POST', $baseUrl . '/admin/content/posts', [
    'title' => 'How to store whole spices',
    'body' => str_repeat('Keep them airtight and out of the sun. ', 30),
    'excerpt' => 'Simple storage that keeps aroma for a year.',
    'category' => 'guides',
    'tags' => ['storage', 'spices'],
    'status' => 'published',
], $adminToken);
check('an article can be published', $response['status'] === 201, json_encode($response['body']));
$postSlug = $response['body']['data']['post']['slug'];
check('...with a reading estimate', ($response['body']['data']['post']['reading_minutes'] ?? 0) >= 1);

$response = call('GET', $baseUrl . '/content/posts');
check('articles list publicly', $response['status'] === 200 && ($response['body']['meta']['total'] ?? 0) >= 1);

$response = call('GET', $baseUrl . '/content/posts?q=spices');
check('articles are searchable', $response['status'] === 200);

$response = call('GET', $baseUrl . '/content/posts/' . $postSlug);
check('an article loads by slug', $response['status'] === 200);
checkSame('...with its tags', ['storage', 'spices'], $response['body']['data']['post']['tags']);

$response = call('GET', $baseUrl . '/content/faq');
check('the FAQ loads', $response['status'] === 200);
check('...grouped', count($response['body']['data']['groups'] ?? []) >= 3,
    json_encode(array_column($response['body']['data']['groups'] ?? [], 'code')));
check('...with entries', ($response['body']['data']['total'] ?? 0) >= 8);

$response = call('GET', $baseUrl . '/content/faq?group=delivery');
$groups = $response['body']['data']['groups'] ?? [];
check('the FAQ can be filtered by group',
    count($groups) === 1 && $groups[0]['code'] === 'delivery', json_encode(array_column($groups, 'code')));

$firstFaq = $groups[0]['entries'][0]['uuid'] ?? null;

if ($firstFaq !== null) {
    $response = call('POST', $baseUrl . '/content/faq/' . $firstFaq . '/helpful');
    check('an FAQ entry can be marked helpful', $response['status'] === 200);
}

// -----------------------------------------------------------------------
// Access control
// -----------------------------------------------------------------------
echo "\n-- Access control --\n";

$response = call('GET', $baseUrl . '/admin/reviews', [], $buyer['token']);
check('a customer cannot open the moderation queue', $response['status'] === 403);

$response = call('POST', $baseUrl . '/admin/reviews/' . $reviewUuid . '/moderate',
    ['decision' => 'approved'], $buyer['token']);
check('a customer cannot moderate', $response['status'] === 403);

$response = call('POST', $baseUrl . '/support/tickets/' . $ticketUuid . '/reply',
    ['body' => 'Trying an internal note', 'internal_note' => true], $buyer['token']);
check('a customer reply is never treated as an internal note', $response['status'] === 200);

$internal = (int) db()->scalar(
    'SELECT COUNT(*) FROM support_ticket_messages m
       JOIN support_tickets t ON t.id = m.ticket_id
      WHERE t.uuid = :u AND m.author_type = :type AND m.is_internal_note = 1',
    ['u' => $ticketUuid, 'type' => 'customer']
);
checkSame('...and no customer-authored internal note exists', 0, $internal);

printf("\n%d passed, %d failed\n", $passed, $failed);
echo "Note: test reviews, tickets and content remain for inspection.\n";

exit($failed === 0 ? 0 : 1);
