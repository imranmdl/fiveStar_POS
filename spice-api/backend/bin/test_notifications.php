<?php

declare(strict_types=1);

/**
 * Unit tests for notification policy: DND, quiet hours, rendering and SMS cost.
 *
 *   php bin/test_notifications.php
 *
 * No database, no server.
 *
 * These rules are legal ones, not preferences. A promotional SMS to a
 * DND-registered Indian number is an offence, and promotional messages may not
 * be delivered between 9pm and 9am. Getting the wrap-around window wrong by one
 * comparison operator means either breaking the law nightly or withholding
 * every promotional message ever sent.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Services\Notifications\NotificationPolicy;

$passed = 0;
$failed = 0;

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
    check($label, $expected === $actual, sprintf('expected %s, got %s',
        var_export($expected, true), var_export($actual, true)));
}

$policy = new NotificationPolicy();

/** 3pm — comfortably outside any quiet window. */
$daytime = strtotime('2026-08-14 15:00:00');
/** 11pm — inside it. */
$night = strtotime('2026-08-14 23:00:00');
/** 7am — also inside a 21:00-09:00 window. */
$earlyMorning = strtotime('2026-08-14 07:00:00');

echo "Transactional messages are exempt from everything\n";
echo "-------------------------------------------------\n";

$verdict = $policy->evaluate(
    NotificationPolicy::CATEGORY_TRANSACTIONAL,
    channelEnabled: true,
    optedOut: true,
    onDndRegister: true,
    quietStart: '21:00',
    quietEnd: '09:00',
    now: $night
);

checkSame('an OTP goes out at 11pm to an opted-out DND number',
    NotificationPolicy::ALLOW, $verdict['decision']);
check('...because a customer cannot unsubscribe from their own OTP', $verdict['reason'] === null);

echo "\nPromotional messages respect DND\n";
echo "--------------------------------\n";

$verdict = $policy->evaluate(
    NotificationPolicy::CATEGORY_PROMOTIONAL,
    channelEnabled: true,
    optedOut: false,
    onDndRegister: true,
    quietStart: '21:00',
    quietEnd: '09:00',
    now: $daytime
);

checkSame('a promotional message to a DND number is suppressed',
    NotificationPolicy::SUPPRESS, $verdict['decision']);
check('...citing the register', str_contains((string) $verdict['reason'], 'Do Not Disturb'),
    (string) $verdict['reason']);

$verdict = $policy->evaluate(
    NotificationPolicy::CATEGORY_PROMOTIONAL,
    channelEnabled: true,
    optedOut: true,
    onDndRegister: false,
    quietStart: '21:00',
    quietEnd: '09:00',
    now: $daytime
);

checkSame('an opted-out customer gets no promotional message',
    NotificationPolicy::SUPPRESS, $verdict['decision']);
check('...citing the opt-out', str_contains((string) $verdict['reason'], 'opted out'));

$verdict = $policy->evaluate(
    NotificationPolicy::CATEGORY_PROMOTIONAL,
    channelEnabled: true,
    optedOut: false,
    onDndRegister: false,
    quietStart: '21:00',
    quietEnd: '09:00',
    now: $daytime
);

checkSame('a willing customer gets it in the afternoon',
    NotificationPolicy::ALLOW, $verdict['decision']);

checkSame('a disabled channel suppresses even a transactional message',
    NotificationPolicy::SUPPRESS,
    $policy->evaluate(
        NotificationPolicy::CATEGORY_TRANSACTIONAL,
        channelEnabled: false,
        optedOut: false,
        onDndRegister: false,
        quietStart: '21:00',
        quietEnd: '09:00',
        now: $daytime
    )['decision']);

echo "\nQuiet hours wrap around midnight\n";
echo "--------------------------------\n";

// The window runs 21:00 to 09:00, so it spans midnight. A naive
// "between start and end" test is wrong for every hour of it.
check('11pm is a quiet hour', $policy->isQuietHour($night, '21:00', '09:00'));
check('7am is a quiet hour', $policy->isQuietHour($earlyMorning, '21:00', '09:00'));
check('3pm is not', !$policy->isQuietHour($daytime, '21:00', '09:00'));
check('exactly 21:00 is quiet',
    $policy->isQuietHour(strtotime('2026-08-14 21:00:00'), '21:00', '09:00'));
check('exactly 09:00 is not quiet, the window has ended',
    !$policy->isQuietHour(strtotime('2026-08-14 09:00:00'), '21:00', '09:00'),
    'an inclusive end would hold every message for an extra minute daily');
check('08:59 is still quiet',
    $policy->isQuietHour(strtotime('2026-08-14 08:59:00'), '21:00', '09:00'));

// A same-day window must also work, for a merchant who configures one.
check('a same-day window (01:00-06:00) holds at 03:00',
    $policy->isQuietHour(strtotime('2026-08-14 03:00:00'), '01:00', '06:00'));
check('...and not at 23:00',
    !$policy->isQuietHour($night, '01:00', '06:00'));

check('an empty window is never quiet',
    !$policy->isQuietHour($night, '09:00', '09:00'),
    'start equal to end must mean "no quiet hours", not "always quiet"');

echo "\nDeferral rather than deletion\n";
echo "-----------------------------\n";

$verdict = $policy->evaluate(
    NotificationPolicy::CATEGORY_PROMOTIONAL,
    channelEnabled: true,
    optedOut: false,
    onDndRegister: false,
    quietStart: '21:00',
    quietEnd: '09:00',
    now: $night
);

checkSame('a promotional message at 11pm is deferred, not dropped',
    NotificationPolicy::DEFER, $verdict['decision']);
check('...to a stated time', $verdict['send_after'] !== null);
checkSame('...which is 9am the next morning',
    '2026-08-15 09:00:00', $verdict['send_after']);

$verdict = $policy->evaluate(
    NotificationPolicy::CATEGORY_PROMOTIONAL,
    channelEnabled: true,
    optedOut: false,
    onDndRegister: false,
    quietStart: '21:00',
    quietEnd: '09:00',
    now: $earlyMorning
);

checkSame('one queued at 7am waits only until 9am the same day',
    '2026-08-14 09:00:00', $verdict['send_after']);

echo "\nTemplate rendering\n";
echo "------------------\n";

$rendered = $policy->render(
    'Order {{order_number}} shipped via {{courier}}.',
    ['order_number' => 'SDF2627000042', 'courier' => 'Delhivery'],
    ['order_number', 'courier']
);

checkSame('placeholders are replaced',
    'Order SDF2627000042 shipped via Delhivery.', $rendered['body']);
checkSame('...with nothing missing', [], $rendered['missing']);

$rendered = $policy->render(
    'Order {{order_number}} shipped via {{courier}}.',
    ['order_number' => 'SDF2627000042'],
    ['order_number', 'courier']
);

checkSame('a missing declared variable is reported', ['courier'], $rendered['missing']);

// An undeclared gap must also be caught, or a template edit that adds a
// placeholder starts reaching customers with braces in it.
$rendered = $policy->render('Hello {{name}}, your code is {{code}}.', ['name' => 'Asha']);
checkSame('an undeclared leftover placeholder is caught', ['code'], $rendered['missing']);

$rendered = $policy->render('No placeholders here.', []);
checkSame('a template with no placeholders renders unchanged', 'No placeholders here.', $rendered['body']);
checkSame('...and reports nothing missing', [], $rendered['missing']);

$rendered = $policy->render('Value: {{amount}}', ['amount' => 0]);
checkSame('a zero value renders rather than counting as missing', 'Value: 0', $rendered['body']);

$rendered = $policy->render('Hi {{name}}', ['name' => '', 'other' => 'x'], ['name']);
check('an empty required value is treated as missing', in_array('name', $rendered['missing'], true),
    'an SMS reading "Hi " helps nobody');

$rendered = $policy->render('Items: {{list}}', ['list' => ['a', 'b']]);
check('an array variable is skipped rather than rendered as "Array"',
    str_contains($rendered['body'], '{{list}}'));

echo "\nSMS segments decide the bill\n";
echo "----------------------------\n";

checkSame('a short message is one segment', 1, $policy->smsSegments('Order confirmed.'));
checkSame('exactly 160 characters is still one', 1, $policy->smsSegments(str_repeat('a', 160)));
checkSame('161 characters costs two', 2, $policy->smsSegments(str_repeat('a', 161)),
    'a template that creeps past 160 doubles the cost of every message sent');
checkSame('306 characters is two', 2, $policy->smsSegments(str_repeat('a', 306)));
checkSame('307 characters is three', 3, $policy->smsSegments(str_repeat('a', 307)));

// Unicode drops the segment size to 70, which is a nasty surprise the first
// time a customer name is transliterated into an Indic script.
checkSame('a short Unicode message is one segment', 1, $policy->smsSegments('ऑर्डर पुष्ट हुआ'));
checkSame('71 Unicode characters costs two', 2, $policy->smsSegments(str_repeat('अ', 71)));
check('the same length costs more in Unicode than in ASCII',
    $policy->smsSegments(str_repeat('अ', 100)) > $policy->smsSegments(str_repeat('a', 100)));

// The seeded templates must all fit in one segment.
$seeded = [
    'Order {{order_number}} received. Pay {{amount}} to confirm. We will notify you once payment is received.',
    'Payment received for order {{order_number}}. We are preparing your order and will notify you when it ships.',
    'Order {{order_number}} shipped via {{courier_name}}. Track: {{tracking_number}}. Expected by {{expected_date}}.',
    'Order {{order_number}} delivered. Thank you for shopping with us.',
];

$oversized = [];

foreach ($seeded as $template) {
    // Placeholders expand, so measure with realistic values rather than braces.
    $filled = str_replace(
        ['{{order_number}}', '{{amount}}', '{{courier_name}}', '{{tracking_number}}', '{{expected_date}}'],
        ['SDF2627000042', 'Rs 1,234.00', 'Delhivery', 'SBX1234567890', '2026-08-20'],
        $template
    );

    if ($policy->smsSegments($filled) > 1) {
        $oversized[] = substr($filled, 0, 40) . '... (' . mb_strlen($filled) . ' chars)';
    }
}

checkSame('every seeded transactional template fits one segment', [], $oversized);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
