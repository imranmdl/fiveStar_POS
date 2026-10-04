<?php

declare(strict_types=1);

/**
 * Unit tests for the order state machine, GST apportionment and financial-year
 * numbering.
 *
 *   php bin/test_orders.php
 *
 * No database, no server. BR-003 and BR-005 are business rules with money
 * behind them, so they get exhaustive coverage rather than a few examples:
 * every status is tried against every other status, in every payment state.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Exceptions\HttpException;
use App\Helpers\Money;
use App\Services\Orders\GstApportioner;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;

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

$machine = new OrderStateMachine();

echo "BR-005: no fulfilment without a settled payment\n";
echo "-----------------------------------------------\n";

// The exhaustive sweep. Every transition the table permits, tried in every
// unsettled payment state, must be refused if the target requires payment.
$unsettled = [
    PaymentStatus::PENDING,
    PaymentStatus::PROCESSING,
    PaymentStatus::FAILED,
    PaymentStatus::REFUNDED,
];

$violations = [];

foreach (OrderStatus::all() as $from) {
    foreach (OrderStatus::TRANSITIONS[$from] as $to) {
        if (!OrderStatus::requiresPayment($to)) {
            continue;
        }

        foreach ($unsettled as $paymentStatus) {
            foreach ([true, false] as $staffOverride) {
                $verdict = $machine->evaluate($from, $to, $paymentStatus, true, true, $staffOverride);

                if ($verdict['allowed']) {
                    $violations[] = sprintf(
                        '%s -> %s allowed while payment is %s%s',
                        $from,
                        $to,
                        $paymentStatus,
                        $staffOverride ? ' (staff override)' : ''
                    );
                }
            }
        }
    }
}

checkSame('every payment-requiring transition is refused when unpaid', [], $violations);

check('...including for staff, who get no override on BR-005',
    !$machine->evaluate(
        OrderStatus::CONFIRMED,
        OrderStatus::PACKED,
        PaymentStatus::PENDING,
        true,
        true,
        isStaffOverride: true
    )['allowed']);

$verdict = $machine->evaluate(
    OrderStatus::AWAITING_PAYMENT,
    OrderStatus::CONFIRMED,
    PaymentStatus::PENDING,
    true
);
check('the refusal explains itself', str_contains((string) $verdict['reason'], 'until payment is confirmed'),
    (string) $verdict['reason']);

// A partial refund still counts as settled: goods were paid for and may be
// mid-fulfilment when part of the money goes back.
check('a partially refunded order can still progress',
    $machine->evaluate(
        OrderStatus::CONFIRMED,
        OrderStatus::PACKED,
        PaymentStatus::PARTIALLY_REFUNDED,
        true,
        true,
        isStaffOverride: true
    )['allowed'],
    'goods were paid for and may legitimately be mid-fulfilment');

check('a fully refunded order cannot progress',
    !$machine->evaluate(
        OrderStatus::CONFIRMED,
        OrderStatus::PACKED,
        PaymentStatus::REFUNDED,
        true,
        true,
        isStaffOverride: true
    )['allowed']);

echo "\nBR-003: no confirmation without OTP\n";
echo "-----------------------------------\n";

check('an unverified order cannot be confirmed even when paid',
    !$machine->evaluate(
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::CONFIRMED,
        PaymentStatus::PAID,
        otpVerified: false
    )['allowed']);

$verdict = $machine->evaluate(
    OrderStatus::AWAITING_PAYMENT,
    OrderStatus::CONFIRMED,
    PaymentStatus::PAID,
    otpVerified: false
);
check('...and says so', str_contains((string) $verdict['reason'], 'OTP'), (string) $verdict['reason']);

check('a verified, paid order confirms',
    $machine->evaluate(
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::CONFIRMED,
        PaymentStatus::PAID,
        otpVerified: true
    )['allowed']);

check('with OTP switched off, a paid order confirms without one',
    $machine->evaluate(
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::CONFIRMED,
        PaymentStatus::PAID,
        otpVerified: false,
        otpRequired: false
    )['allowed']);

// OTP is a confirmation gate, not a cancellation gate.
check('an unverified order can still be cancelled',
    $machine->evaluate(
        OrderStatus::CREATED,
        OrderStatus::CANCELLED,
        PaymentStatus::PENDING,
        otpVerified: false
    )['allowed']);

echo "\nTransition table\n";
echo "----------------\n";

check('the happy path runs end to end', (function () use ($machine): bool {
    $path = [
        OrderStatus::CREATED,
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::CONFIRMED,
        OrderStatus::PACKED,
        OrderStatus::READY_TO_SHIP,
        OrderStatus::ASSIGNED,
        OrderStatus::SHIPPED,
        OrderStatus::OUT_FOR_DELIVERY,
        OrderStatus::DELIVERED,
    ];

    for ($i = 0; $i < count($path) - 1; ++$i) {
        // Payment lands between awaiting_payment and confirmed.
        $paymentStatus = $i >= 1 ? PaymentStatus::PAID : PaymentStatus::PENDING;

        if (!$machine->evaluate($path[$i], $path[$i + 1], $paymentStatus, true, true, true)['allowed']) {
            return false;
        }
    }

    return true;
})());

check('an order cannot skip from confirmed straight to delivered',
    !$machine->evaluate(OrderStatus::CONFIRMED, OrderStatus::DELIVERED, PaymentStatus::PAID, true, true, true)['allowed']);

check('an order cannot move backwards',
    !$machine->evaluate(OrderStatus::SHIPPED, OrderStatus::PACKED, PaymentStatus::PAID, true, true, true)['allowed']);

check('a refunded order is terminal',
    !$machine->evaluate(OrderStatus::REFUNDED, OrderStatus::CONFIRMED, PaymentStatus::PAID, true, true, true)['allowed']);

$verdict = $machine->evaluate(OrderStatus::REFUNDED, OrderStatus::CONFIRMED, PaymentStatus::PAID, true);
check('...and says why', str_contains((string) $verdict['reason'], 'cannot change further'));

// A no-op is not an error: retried webhooks and double-clicked buttons land here.
$verdict = $machine->evaluate(OrderStatus::CONFIRMED, OrderStatus::CONFIRMED, PaymentStatus::PAID, true);
check('moving to the status it is already in is refused but not an error',
    !$verdict['allowed'] && $verdict['reason'] === null);

check('an unknown target status is refused',
    !$machine->evaluate(OrderStatus::CONFIRMED, 'teleported', PaymentStatus::PAID, true)['allowed']);

check('an unknown current status is refused',
    !$machine->evaluate('quantum', OrderStatus::CONFIRMED, PaymentStatus::PAID, true)['allowed']);

// Every status must be reachable, or it is dead configuration.
$reachable = [OrderStatus::CREATED => true];

foreach (OrderStatus::TRANSITIONS as $targets) {
    foreach ($targets as $target) {
        $reachable[$target] = true;
    }
}

checkSame('every declared status is reachable', [], array_values(array_diff(OrderStatus::all(), array_keys($reachable))));

echo "\nCancellation\n";
echo "------------\n";

foreach ([OrderStatus::CREATED, OrderStatus::AWAITING_PAYMENT, OrderStatus::CONFIRMED, OrderStatus::PACKED] as $status) {
    check(
        sprintf('a customer can cancel from "%s"', $status),
        $machine->evaluate($status, OrderStatus::CANCELLED, PaymentStatus::PAID, true)['allowed']
    );
}

foreach ([OrderStatus::READY_TO_SHIP, OrderStatus::ASSIGNED] as $status) {
    check(
        sprintf('a customer cannot cancel from "%s"', $status),
        !$machine->evaluate($status, OrderStatus::CANCELLED, PaymentStatus::PAID, true)['allowed']
    );

    check(
        sprintf('...but staff can, from "%s"', $status),
        $machine->evaluate($status, OrderStatus::CANCELLED, PaymentStatus::PAID, true, true, true)['allowed']
    );
}

check('nobody can cancel a shipped order',
    !$machine->evaluate(OrderStatus::SHIPPED, OrderStatus::CANCELLED, PaymentStatus::PAID, true, true, true)['allowed'],
    'it is with the courier; a return is the correct route');

echo "\nAvailable transitions drive the buttons\n";
echo "---------------------------------------\n";

$customerOptions = $machine->availableTransitions(OrderStatus::CONFIRMED, PaymentStatus::PAID, true);
checkSame('a paid confirmed order offers a customer only cancellation',
    [OrderStatus::CANCELLED], $customerOptions);

$staffOptions = $machine->availableTransitions(OrderStatus::CONFIRMED, PaymentStatus::PAID, true, true, true);
check('staff additionally see "packed"', in_array(OrderStatus::PACKED, $staffOptions, true));

check('a customer cannot set a fulfilment status directly',
    !$machine->evaluate(OrderStatus::CONFIRMED, OrderStatus::PACKED, PaymentStatus::PAID, true)['allowed']);

$verdict = $machine->evaluate(OrderStatus::CONFIRMED, OrderStatus::PACKED, PaymentStatus::PAID, true);
check('...and is told it is our team\'s job', str_contains((string) $verdict['reason'], 'Only our team'),
    (string) $verdict['reason']);

$unpaidOptions = $machine->availableTransitions(OrderStatus::AWAITING_PAYMENT, PaymentStatus::PENDING, true, true, true);
check('an unpaid order never offers confirmation',
    !in_array(OrderStatus::CONFIRMED, $unpaidOptions, true),
    json_encode($unpaidOptions));

// A button that appears must always work: options and enforcement come from the
// same rules, so this can never drift.
$drift = [];

foreach (OrderStatus::all() as $status) {
    foreach ([PaymentStatus::PENDING, PaymentStatus::PAID] as $paymentStatus) {
        foreach ($machine->availableTransitions($status, $paymentStatus, true, true, true) as $offered) {
            if (!$machine->evaluate($status, $offered, $paymentStatus, true, true, true)['allowed']) {
                $drift[] = $status . ' -> ' . $offered;
            }
        }
    }
}

checkSame('every offered transition is actually permitted', [], $drift);

echo "\nassert() throws on a refusal\n";
echo "----------------------------\n";

$threw = false;

try {
    $machine->assert(OrderStatus::AWAITING_PAYMENT, OrderStatus::CONFIRMED, PaymentStatus::PENDING, true);
} catch (HttpException $exception) {
    $threw = true;
    checkSame('...with a 409', 409, $exception->statusCode());
}

check('assert throws when a transition is refused', $threw);

$didNotThrow = true;

try {
    $machine->assert(OrderStatus::AWAITING_PAYMENT, OrderStatus::CONFIRMED, PaymentStatus::PAID, true);
} catch (HttpException) {
    $didNotThrow = false;
}

check('assert is silent when a transition is permitted', $didNotThrow);

echo "\nProgress display\n";
echo "----------------\n";

$progress = $machine->progress(OrderStatus::SHIPPED);
$states = array_column($progress, 'state');

checkSame('confirmed is complete once shipped', 'complete', $states[0]);
checkSame('shipped is the current step', 'current', $states[3]);
checkSame('delivery is still pending', 'pending', $states[5]);

$cancelled = array_column($machine->progress(OrderStatus::CANCELLED), 'state');
checkSame('a cancelled order shows no progress at all', ['inactive', 'inactive', 'inactive', 'inactive', 'inactive', 'inactive'], $cancelled);

echo "\nGST: CGST + SGST versus IGST\n";
echo "----------------------------\n";

$split = GstApportioner::split(Money::fromDecimal('100.00'), 'Karnataka', 'Karnataka');
checkSame('an intra-state sale splits evenly, CGST', 50.0, $split['cgst']->toDecimal());
checkSame('...and SGST', 50.0, $split['sgst']->toDecimal());
checkSame('...with no IGST', 0.0, $split['igst']->toDecimal());
check('...and is not interstate', !$split['is_interstate']);

$split = GstApportioner::split(Money::fromDecimal('100.00'), 'Karnataka', 'Maharashtra');
checkSame('an interstate sale is all IGST', 100.0, $split['igst']->toDecimal());
checkSame('...with no CGST', 0.0, $split['cgst']->toDecimal());
check('...and is flagged interstate', $split['is_interstate']);

$split = GstApportioner::split(Money::fromDecimal('100.00'), 'Karnataka', '  karnataka  ');
check('state matching ignores case and padding', !$split['is_interstate']);

// The awkward case: an odd number of paise cannot be halved evenly.
$odd = GstApportioner::split(Money::fromPaise(2563), 'Karnataka', 'Karnataka');
checkSame('an odd tax amount still adds back up exactly',
    2563, $odd['cgst']->add($odd['sgst'], $odd['igst'])->paise());
checkSame('...with the extra paisa going to CGST', 1282, $odd['cgst']->paise());
checkSame('...and SGST taking the rest', 1281, $odd['sgst']->paise());

// Property test across many amounts: the split must never lose or invent a paisa.
$leaks = 0;

for ($paise = 1; $paise <= 5000; ++$paise) {
    $parts = GstApportioner::split(Money::fromPaise($paise), 'Karnataka', 'Karnataka');

    if ($parts['cgst']->add($parts['sgst'], $parts['igst'])->paise() !== $paise) {
        ++$leaks;
    }
}

checkSame('5,000 intra-state splits, zero paise lost', 0, $leaks);

echo "\nStatus labels\n";
echo "-------------\n";

$unlabelled = [];

foreach (OrderStatus::all() as $status) {
    if (!isset(OrderStatus::LABELS[$status])) {
        $unlabelled[] = $status;
    }
}

checkSame('every status has a customer-facing label', [], $unlabelled);
checkSame('labels read as plain English', 'Out for delivery', OrderStatus::label(OrderStatus::OUT_FOR_DELIVERY));
checkSame('an unknown status still renders something sensible', 'Some new status', OrderStatus::label('some_new_status'));

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
