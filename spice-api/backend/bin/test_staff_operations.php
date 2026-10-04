<?php

declare(strict_types=1);

/**
 * Unit tests for commission calculation and assignment planning.
 *
 *   php bin/test_staff_operations.php
 *
 * No database, no server.
 *
 * Commission is the one part of this system that pays real people real money on
 * a schedule. A rounding error here is not a bug report, it is a payroll
 * dispute, so the rules are pinned down rather than sampled.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Helpers\Money;
use App\Services\Staff\AssignmentPlanner;
use App\Services\Staff\CommissionCalculator;

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

function rule(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'code' => 'TEST',
        'calculation' => 'flat_per_order',
        'flat_amount' => '15.00',
        'percentage' => null,
        'tiers' => null,
        'min_order_value' => null,
        'max_commission' => null,
        'priority' => 100,
    ], $overrides);
}

$calculator = new CommissionCalculator();

echo "Flat fee\n";
echo "--------\n";

$outcome = $calculator->calculate(rule(), Money::fromDecimal('750.00'));
checkSame('a flat rule pays its amount', 15.0, $outcome['amount']->toDecimal());
check('it applies', $outcome['applies']);
check('the calculation is explained', str_contains($outcome['note'], 'Flat fee'), $outcome['note']);

check('a flat fee ignores order value', (function () use ($calculator): bool {
    $small = $calculator->calculate(rule(), Money::fromDecimal('100.00'))['amount'];
    $large = $calculator->calculate(rule(), Money::fromDecimal('99999.00'))['amount'];

    return $small->equals($large);
})());

echo "\nPercentage\n";
echo "----------\n";

$percentageRule = rule([
    'calculation' => 'percentage_of_order',
    'flat_amount' => null,
    'percentage' => '1.50',
]);

$outcome = $calculator->calculate($percentageRule, Money::fromDecimal('10000.00'));
checkSame('1.5% of 10,000 is 150', 150.0, $outcome['amount']->toDecimal());
check('the arithmetic is shown', str_contains($outcome['note'], '1.5%'), $outcome['note']);

// Fractional paise must round consistently, not drift.
$outcome = $calculator->calculate($percentageRule, Money::fromDecimal('333.33'));
checkSame('an awkward basis still yields whole paise',
    $outcome['amount']->paise(), (int) $outcome['amount']->paise());
check('...and is a plausible figure',
    $outcome['amount']->toDecimal() > 4.9 && $outcome['amount']->toDecimal() < 5.1,
    (string) $outcome['amount']->toDecimal());

echo "\nMinimum order value gates rather than scales\n";
echo "--------------------------------------------\n";

$gatedRule = rule(['min_order_value' => '5000.00']);

$outcome = $calculator->calculate($gatedRule, Money::fromDecimal('4999.99'));
check('below the minimum the rule does not apply', !$outcome['applies']);
checkSame('...and pays nothing', 0.0, $outcome['amount']->toDecimal());
check('...with a reason given', str_contains((string) $outcome['reason'], 'below'), (string) $outcome['reason']);

$outcome = $calculator->calculate($gatedRule, Money::fromDecimal('5000.00'));
check('exactly at the minimum the rule applies', $outcome['applies'],
    'a boundary that excludes the boundary value is the classic off-by-one');

echo "\nCap is applied last and disclosed\n";
echo "---------------------------------\n";

$cappedRule = rule([
    'calculation' => 'percentage_of_order',
    'flat_amount' => null,
    'percentage' => '2.00',
    'max_commission' => '250.00',
]);

$outcome = $calculator->calculate($cappedRule, Money::fromDecimal('50000.00'));
checkSame('2% of 50,000 is capped at 250', 250.0, $outcome['amount']->toDecimal());
check('the cap is stated, not silent', str_contains($outcome['note'], 'Capped'),
    $outcome['note'] . ' — an unexplained capped figure is the commonest cause of a pay dispute');

$outcome = $calculator->calculate($cappedRule, Money::fromDecimal('1000.00'));
checkSame('below the cap the full amount is paid', 20.0, $outcome['amount']->toDecimal());
check('...and no cap is mentioned', !str_contains($outcome['note'], 'Capped'));

echo "\nTiered by volume\n";
echo "----------------\n";

$tieredRule = rule([
    'calculation' => 'tiered_by_volume',
    'flat_amount' => null,
    'tiers' => json_encode([
        ['min_orders' => 0, 'amount' => 0],
        ['min_orders' => 100, 'amount' => 500],
        ['min_orders' => 250, 'amount' => 1500],
    ]),
]);

checkSame('below the first paying tier, nothing', 0.0,
    $calculator->calculate($tieredRule, Money::fromDecimal('500.00'), 40)['amount']->toDecimal());
checkSame('at 100 orders the first tier pays', 500.0,
    $calculator->calculate($tieredRule, Money::fromDecimal('500.00'), 100)['amount']->toDecimal());
checkSame('between tiers the lower one holds', 500.0,
    $calculator->calculate($tieredRule, Money::fromDecimal('500.00'), 249)['amount']->toDecimal());
checkSame('at 250 the higher tier pays', 1500.0,
    $calculator->calculate($tieredRule, Money::fromDecimal('500.00'), 250)['amount']->toDecimal());
checkSame('far above the top tier, the top tier holds', 1500.0,
    $calculator->calculate($tieredRule, Money::fromDecimal('500.00'), 10000)['amount']->toDecimal());

// Tier order in the configuration must not change anyone's pay.
$shuffledRule = rule([
    'calculation' => 'tiered_by_volume',
    'flat_amount' => null,
    'tiers' => json_encode([
        ['min_orders' => 250, 'amount' => 1500],
        ['min_orders' => 0, 'amount' => 0],
        ['min_orders' => 100, 'amount' => 500],
    ]),
]);

checkSame('tiers written out of order pay the same',
    $calculator->calculate($tieredRule, Money::fromDecimal('500.00'), 150)['amount']->toDecimal(),
    $calculator->calculate($shuffledRule, Money::fromDecimal('500.00'), 150)['amount']->toDecimal());

$emptyTiers = rule(['calculation' => 'tiered_by_volume', 'flat_amount' => null, 'tiers' => '[]']);
checkSame('no tiers configured pays nothing rather than erroring', 0.0,
    $calculator->calculate($emptyTiers, Money::fromDecimal('500.00'), 500)['amount']->toDecimal());

echo "\nRule resolution: specific beats general\n";
echo "---------------------------------------\n";

$rules = [
    rule([
        'id' => 2,
        'code' => 'HIGHVALUE',
        'calculation' => 'percentage_of_order',
        'flat_amount' => null,
        'percentage' => '1.50',
        'min_order_value' => '5000.00',
        'max_commission' => '250.00',
        'priority' => 50,
    ]),
    rule(['id' => 1, 'code' => 'STANDARD', 'priority' => 100]),
];

$outcome = $calculator->resolve($rules, Money::fromDecimal('10000.00'));
checkSame('a large order takes the high-value rule', 'HIGHVALUE', $outcome['rule']['code']);
checkSame('...paying 1.5%', 150.0, $outcome['amount']->toDecimal());

$outcome = $calculator->resolve($rules, Money::fromDecimal('800.00'));
checkSame('a small order falls through to the standard rule', 'STANDARD', $outcome['rule']['code']);
checkSame('...paying the flat fee', 15.0, $outcome['amount']->toDecimal());
check('...and records why the first rule was skipped', $outcome['skipped'] !== []);
check('...naming it', str_contains($outcome['skipped'][0], 'HIGHVALUE'), json_encode($outcome['skipped']));

// Rules must not stack: only one pays.
check('only one rule ever pays', (function () use ($calculator, $rules): bool {
    $outcome = $calculator->resolve($rules, Money::fromDecimal('10000.00'));

    return $outcome['amount']->toDecimal() === 150.0;
})(), 'a high-value order must not also collect the flat fee');

$outcome = $calculator->resolve([], Money::fromDecimal('1000.00'));
check('no rules means no payment', $outcome['rule'] === null);
checkSame('...of zero', 0.0, $outcome['amount']->toDecimal());

echo "\nAssignment planning\n";
echo "-------------------\n";

$planner = new AssignmentPlanner();

function executive(string $code, int $open, int $max, int $today = 0, bool $available = true): array
{
    return [
        'user_id' => crc32($code) % 100000,
        'full_name' => $code . ' Person',
        'employee_code' => $code,
        'open_assignments' => $open,
        'max_concurrent_orders' => $max,
        'remaining_capacity' => max(0, $max - $open),
        'completed_today' => $today,
        'is_available' => $available ? 1 : 0,
    ];
}

$team = [
    executive('EMP0001', 20, 25),
    executive('EMP0002', 5, 25),
    executive('EMP0003', 12, 25),
];

$plan = $planner->plan($team);
checkSame('the least loaded executive is chosen', 'EMP0002', $plan['selected']['employee_code']);
checkSame('all three are eligible', 3, $plan['eligible']);
check('the choice is explained', str_contains($plan['reason'], 'slots free'), $plan['reason']);

echo "\nCapacity is a hard gate\n";
echo "-----------------------\n";

$full = [
    executive('EMP0001', 25, 25),
    executive('EMP0002', 25, 25),
];

$plan = $planner->plan($full);
check('nobody is chosen when everyone is full', $plan['selected'] === null);
check('...and the reason says so', str_contains($plan['reason'], 'capacity'), $plan['reason']);
checkSame('...with zero eligible', 0, $plan['eligible']);

$mixed = [
    executive('EMP0001', 25, 25),
    executive('EMP0002', 24, 25),
];

$plan = $planner->plan($mixed);
checkSame('one free slot is still a free slot', 'EMP0002', $plan['selected']['employee_code']);
check('...and the full colleague is listed as skipped', $plan['skipped'] !== []);

$unavailable = [
    executive('EMP0001', 0, 25, 0, available: false),
    executive('EMP0002', 20, 25),
];

$plan = $planner->plan($unavailable);
checkSame('an unavailable executive is skipped even when empty',
    'EMP0002', $plan['selected']['employee_code']);
check('...with a reason recorded',
    str_contains(implode(' ', $plan['skipped']), 'unavailable'), json_encode($plan['skipped']));

echo "\nCapacity is relative, not absolute\n";
echo "----------------------------------\n";

// Someone rated for 40 holding 15 has more room than someone rated for 20
// holding 14, even though their raw open count is higher.
$differentRatings = [
    executive('BIG', 15, 40),
    executive('SMALL', 14, 20),
];

checkSame('spare capacity decides, not raw open count',
    'BIG', $planner->plan($differentRatings)['selected']['employee_code']);

echo "\nRound robin spreads over a shift\n";
echo "--------------------------------\n";

$sameLoad = [
    executive('EMP0001', 10, 25, today: 9),
    executive('EMP0002', 10, 25, today: 2),
];

checkSame('round robin favours whoever has done least today',
    'EMP0002', $planner->plan($sameLoad, AssignmentPlanner::STRATEGY_ROUND_ROBIN)['selected']['employee_code']);

// Least-loaded ignores today's count, so with equal capacity it falls to the
// deterministic tiebreak rather than the shift count.
checkSame('least loaded ignores today\'s completions',
    'EMP0001', $planner->plan($sameLoad)['selected']['employee_code']);

echo "\nDeterminism\n";
echo "-----------\n";

$identical = [
    executive('ZULU', 10, 25),
    executive('ALPHA', 10, 25),
    executive('MIKE', 10, 25),
];

$first = $planner->plan($identical)['selected']['employee_code'];
$stable = true;

for ($i = 0; $i < 50; ++$i) {
    if ($planner->plan($identical)['selected']['employee_code'] !== $first) {
        $stable = false;
    }
}

check('identical candidates resolve the same way every time', $stable);
checkSame('...broken alphabetically by employee code', 'ALPHA', $first);

$plan = $planner->plan([]);
check('an empty team is handled', $plan['selected'] === null);
check('...with a clear message', str_contains($plan['reason'], 'No executives are configured'));

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
