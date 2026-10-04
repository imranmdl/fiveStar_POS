<?php

declare(strict_types=1);

/**
 * Unit tests for BR-007 courier selection and volumetric weight.
 *
 *   php bin/test_courier_selection.php
 *
 * No database, no server.
 *
 * These matter more than they look. A selection bug does not crash anything —
 * it quietly picks a worse courier on every order, and the loss shows up months
 * later as a margin problem nobody can attribute. So the rules are pinned down
 * exhaustively rather than sampled.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Helpers\Money;
use App\Services\Delivery\CourierQuote;
use App\Services\Delivery\CourierSelector;
use App\Services\Delivery\ParcelSpec;

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

function quote(
    string $code,
    string $cost,
    int $slaMin,
    int $slaMax,
    float $reliability = 80.0,
    int $priority = 100,
    bool $eligible = true,
    array $reasons = [],
): CourierQuote {
    static $id = 0;

    return new CourierQuote(
        courierId: ++$id,
        courierCode: $code,
        courierName: $code,
        cost: Money::fromDecimal($cost),
        slaMinDays: $slaMin,
        slaMaxDays: $slaMax,
        reliabilityScore: $reliability,
        priority: $priority,
        isEligible: $eligible,
        ineligibilityReasons: $reasons,
    );
}

$selector = new CourierSelector();

echo "Strategy weightings\n";
echo "-------------------\n";

foreach (CourierSelector::STRATEGIES as $name => $weights) {
    $sum = round($weights['cost'] + $weights['speed'] + $weights['reliability'], 6);
    checkSame(sprintf('"%s" weightings sum to 1', $name), 1.0, $sum);
}

echo "\nCheapest strategy\n";
echo "-----------------\n";

$quotes = [
    quote('DEAR_FAST', '120.00', 1, 2, 95.0),
    quote('CHEAP_SLOW', '40.00', 5, 8, 70.0),
    quote('MIDDLE', '75.00', 3, 4, 85.0),
];

$outcome = $selector->select($quotes, CourierSelector::STRATEGY_CHEAPEST);
checkSame('the cheapest wins on cost', 'CHEAP_SLOW', $outcome['selected']->courierCode);
checkSame('...scoring a full 1', 1.0, $outcome['score']);
check('...and the reason says so', str_contains($outcome['reason'], 'lowest cost'), $outcome['reason']);

echo "\nFastest strategy\n";
echo "----------------\n";

$outcome = $selector->select($quotes, CourierSelector::STRATEGY_FASTEST);
checkSame('the fastest wins on speed', 'DEAR_FAST', $outcome['selected']->courierCode);
check('...and the reason says so', str_contains($outcome['reason'], 'fastest'), $outcome['reason']);

echo "\nReliable strategy\n";
echo "-----------------\n";

$outcome = $selector->select($quotes, CourierSelector::STRATEGY_RELIABLE);
checkSame('the most reliable wins', 'DEAR_FAST', $outcome['selected']->courierCode);

echo "\nBalanced strategy\n";
echo "-----------------\n";

$outcome = $selector->select($quotes, CourierSelector::STRATEGY_BALANCED);
check('balanced picks a real candidate', $outcome['selected'] !== null);
check('balanced does not simply copy cheapest or fastest', (function () use ($selector, $quotes): bool {
    $balanced = $selector->select($quotes, CourierSelector::STRATEGY_BALANCED)['selected']->courierCode;
    $cheapest = $selector->select($quotes, CourierSelector::STRATEGY_CHEAPEST)['selected']->courierCode;
    $fastest = $selector->select($quotes, CourierSelector::STRATEGY_FASTEST)['selected']->courierCode;

    // It may legitimately agree with one, but must not agree with both — that
    // would mean the weightings are doing nothing.
    return !($balanced === $cheapest && $balanced === $fastest);
})());

// A courier that is cheapest AND fastest AND most reliable must win everywhere.
$dominant = [
    quote('DOMINANT', '30.00', 1, 1, 99.0),
    quote('WORSE_A', '90.00', 4, 6, 60.0),
    quote('WORSE_B', '85.00', 5, 7, 65.0),
];

$strategyResults = [];

foreach (array_keys(CourierSelector::STRATEGIES) as $strategy) {
    $strategyResults[$strategy] = $selector->select($dominant, $strategy)['selected']->courierCode;
}

checkSame('a dominant courier wins under every strategy',
    ['cheapest' => 'DOMINANT', 'fastest' => 'DOMINANT', 'balanced' => 'DOMINANT', 'reliable' => 'DOMINANT'],
    $strategyResults);

echo "\nEligibility is absolute\n";
echo "-----------------------\n";

$withIneligible = [
    quote('TOO_HEAVY', '10.00', 1, 1, 100.0, 1, false, ['parcel is 26000g, above their 25000g ceiling']),
    quote('VIABLE', '95.00', 4, 6, 70.0),
];

$outcome = $selector->select($withIneligible, CourierSelector::STRATEGY_CHEAPEST);
checkSame('an ineligible courier never wins, however cheap', 'VIABLE', $outcome['selected']->courierCode);
checkSame('...and is not counted as eligible', 1, $outcome['eligible']);
checkSame('...but is still counted as considered', 2, $outcome['considered']);

$reasonsRecorded = false;

foreach ($outcome['candidates'] as $candidate) {
    if ($candidate['courier_code'] === 'TOO_HEAVY' && $candidate['ineligibility_reasons'] !== []) {
        $reasonsRecorded = true;
    }
}

check('...with its reason kept for the audit trail', $reasonsRecorded);

echo "\nNothing available\n";
echo "-----------------\n";

$noneViable = [
    quote('A', '10.00', 1, 1, 100.0, 1, false, ['does not serve pincode 799001']),
    quote('B', '20.00', 1, 1, 100.0, 1, false, ['parcel is 40000g, above their 30000g ceiling']),
];

$outcome = $selector->select($noneViable);
check('no courier is selected', $outcome['selected'] === null);
checkSame('...and none counted eligible', 0, $outcome['eligible']);
check('...and the reason names the failures',
    str_contains($outcome['reason'], 'does not serve') && str_contains($outcome['reason'], 'ceiling'),
    $outcome['reason']);

$outcome = $selector->select([]);
check('an empty candidate list is handled', $outcome['selected'] === null);
check('...with a sensible message', str_contains($outcome['reason'], 'No couriers are configured'));

echo "\nSingle candidate\n";
echo "----------------\n";

$outcome = $selector->select([quote('ONLY', '250.00', 7, 12, 50.0)]);
checkSame('the only option is chosen', 'ONLY', $outcome['selected']->courierCode);
check('...and the reason says it was the only one',
    str_contains($outcome['reason'], 'only courier'), $outcome['reason']);

echo "\nDeterminism and tie-breaking\n";
echo "----------------------------\n";

$identical = [
    quote('ZEBRA', '50.00', 2, 3, 80.0, 100),
    quote('ALPHA', '50.00', 2, 3, 80.0, 100),
    quote('MIKE', '50.00', 2, 3, 80.0, 100),
];

$first = $selector->select($identical)['selected']->courierCode;
$stable = true;

for ($i = 0; $i < 50; ++$i) {
    if ($selector->select($identical)['selected']->courierCode !== $first) {
        $stable = false;
    }
}

check('identical quotes resolve to the same courier every time', $stable);
checkSame('...broken alphabetically as the last resort', 'ALPHA', $first);

// Priority is a commercial lever and must beat the alphabet.
$byPriority = [
    quote('ZULU', '50.00', 2, 3, 80.0, priority: 10),
    quote('ALPHA', '50.00', 2, 3, 80.0, priority: 90),
];

checkSame('priority breaks a tie before the alphabet does',
    'ZULU', $selector->select($byPriority)['selected']->courierCode);

// Cost is a stronger tiebreaker than priority.
$costBeatsPriority = [
    quote('PREFERRED_DEAR', '60.00', 2, 3, 80.0, priority: 1),
    quote('OTHER_CHEAP', '59.00', 2, 3, 80.0, priority: 99),
];

checkSame('a cheaper courier beats a preferred one at equal score',
    'OTHER_CHEAP', $selector->select($costBeatsPriority, CourierSelector::STRATEGY_CHEAPEST)['selected']->courierCode);

echo "\nScoring edges\n";
echo "-------------\n";

$sameCost = [
    quote('FAST', '50.00', 1, 2, 80.0),
    quote('SLOW', '50.00', 6, 9, 80.0),
];

checkSame('when cost ties, speed decides under balanced',
    'FAST', $selector->select($sameCost, CourierSelector::STRATEGY_BALANCED)['selected']->courierCode);

$sameSpeed = [
    quote('DEAR', '150.00', 3, 4, 80.0),
    quote('CHEAP', '45.00', 3, 4, 80.0),
];

checkSame('when speed ties, cost decides under balanced',
    'CHEAP', $selector->select($sameSpeed, CourierSelector::STRATEGY_BALANCED)['selected']->courierCode);

// Every eligible courier must appear in the audit trail with a score.
$outcome = $selector->select($quotes, CourierSelector::STRATEGY_BALANCED);
$scoredCount = 0;

foreach ($outcome['candidates'] as $candidate) {
    if ($candidate['score'] !== null) {
        ++$scoredCount;
    }
}

checkSame('every eligible candidate is scored in the audit trail', 3, $scoredCount);

check('scores never fall outside 0 to 1', (function () use ($selector, $quotes): bool {
    foreach (array_keys(CourierSelector::STRATEGIES) as $strategy) {
        foreach ($selector->select($quotes, $strategy)['candidates'] as $candidate) {
            if ($candidate['score'] !== null && ($candidate['score'] < 0 || $candidate['score'] > 1)) {
                return false;
            }
        }
    }

    return true;
})());

$outcome = $selector->select($quotes, 'nonsense_strategy');
checkSame('an unknown strategy falls back to balanced', 'balanced', $outcome['strategy']);

echo "\nEligibility gates\n";
echo "-----------------\n";

$baseCourier = [
    'is_enabled' => 1,
    'disabled_reason' => null,
    'min_weight_grams' => 50,
    'max_weight_grams' => 25000,
    'max_order_value' => 50000.00,
    'handles_fragile' => 1,
    'max_length_mm' => null,
    'max_width_mm' => null,
    'max_height_mm' => null,
];

function parcel(int $weight = 1000, string $value = '500.00', bool $fragile = false,
                int $l = 250, int $w = 200, int $h = 120): ParcelSpec
{
    return new ParcelSpec(
        actualWeightGrams: $weight,
        volumetricWeightGrams: 0,
        lengthMm: $l,
        widthMm: $w,
        heightMm: $h,
        declaredValue: Money::fromDecimal($value),
        destinationPincode: '560001',
        zoneCode: 'LOCAL',
        isFragile: $fragile,
    );
}

checkSame('a normal parcel passes every gate', [], $selector->ineligibilityReasons($baseCourier, parcel()));

$reasons = $selector->ineligibilityReasons($baseCourier, parcel(weight: 30000));
check('an overweight parcel is refused', $reasons !== []);
check('...with the numbers stated', str_contains($reasons[0], '30000g') && str_contains($reasons[0], '25000g'), $reasons[0]);

$reasons = $selector->ineligibilityReasons($baseCourier, parcel(weight: 10));
check('an underweight parcel is refused', $reasons !== []);

$reasons = $selector->ineligibilityReasons($baseCourier, parcel(value: '80000.00'));
check('a parcel above the insurance limit is refused', $reasons !== []);
check('...mentioning insurance', str_contains($reasons[0], 'insurance'), $reasons[0]);

$noFragile = $baseCourier;
$noFragile['handles_fragile'] = 0;
check('a fragile parcel is refused by a courier that will not take them',
    $selector->ineligibilityReasons($noFragile, parcel(fragile: true)) !== []);
checkSame('...but a non-fragile parcel is fine', [],
    $selector->ineligibilityReasons($noFragile, parcel(fragile: false)));

$disabled = $baseCourier;
$disabled['is_enabled'] = 0;
$disabled['disabled_reason'] = 'Contract under renegotiation';
$reasons = $selector->ineligibilityReasons($disabled, parcel());
check('a disabled courier is refused', $reasons !== []);
check('...quoting the reason it was disabled',
    str_contains($reasons[0], 'Contract under renegotiation'), $reasons[0]);

// Every failure at once, so a misconfigured courier shows all its problems.
$reasons = $selector->ineligibilityReasons($noFragile, parcel(weight: 40000, value: '90000.00', fragile: true));
check('all failures are reported together, not just the first', count($reasons) >= 3,
    json_encode($reasons));

echo "\nSize limits compare longest against longest\n";
echo "-------------------------------------------\n";

$sized = $baseCourier;
$sized['max_length_mm'] = 600;
$sized['max_width_mm'] = 400;
$sized['max_height_mm'] = 300;

checkSame('a parcel within limits passes', [],
    $selector->ineligibilityReasons($sized, parcel(l: 500, w: 350, h: 250)));

// The same box handed over on its side must not be refused: dimensions are
// sorted before comparison.
checkSame('...and still passes when its axes are swapped', [],
    $selector->ineligibilityReasons($sized, parcel(l: 250, w: 500, h: 350)));

check('a genuinely oversized parcel is refused',
    $selector->ineligibilityReasons($sized, parcel(l: 700, w: 350, h: 250)) !== []);

echo "\nVolumetric weight\n";
echo "-----------------\n";

// 30 x 20 x 10 cm at divisor 5000 = 6000/5000 = 1.2kg
checkSame('a standard box computes 1.2kg volumetric',
    1200, ParcelSpec::volumetricGrams(300, 200, 100, 5000));

// The same box at divisor 4000 is 25% heavier to that courier.
checkSame('a divisor of 4000 makes the same box heavier',
    1500, ParcelSpec::volumetricGrams(300, 200, 100, 4000));

checkSame('a zero divisor cannot divide by zero',
    0, ParcelSpec::volumetricGrams(300, 200, 100, 0));

// Dense goods bill on actual weight; bulky goods bill on volume. Both cases are
// routine for a spice and dry-fruit retailer.
$dense = new ParcelSpec(2000, 500, 200, 150, 100, Money::fromDecimal('900.00'), '560001', 'LOCAL');
checkSame('a dense parcel is billed on actual weight', 2000, $dense->chargeableWeightGrams());

$bulky = new ParcelSpec(400, 3200, 500, 400, 200, Money::fromDecimal('900.00'), '560001', 'LOCAL');
checkSame('a bulky parcel is billed on volumetric weight', 3200, $bulky->chargeableWeightGrams());
check('...which is what makes quoting on actual weight alone wrong',
    $bulky->chargeableWeightGrams() > $bulky->actualWeightGrams * 4);

checkSame('dimensions sort longest first', [500, 400, 200], $bulky->sortedDimensionsMm());

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
