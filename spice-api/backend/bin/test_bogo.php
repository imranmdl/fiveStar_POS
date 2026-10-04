<?php

declare(strict_types=1);

/**
 * Unit tests for buy-X-get-Y arithmetic.
 *
 *   php bin/test_bogo.php
 *
 * No database, no server. This is where money gets given away by accident, so
 * the boundaries matter more than the happy path: does "buy 2 get 1" on three
 * items give one free or two? Does a mixed basket discount the cheapest?
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Helpers\Money;
use App\Services\Promotions\BogoCalculator;

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

function line(string $reference, int $quantity, string $unitPrice): array
{
    return [
        'reference' => $reference,
        'quantity' => $quantity,
        'unit_price' => Money::fromDecimal($unitPrice),
    ];
}

$bogo = new BogoCalculator();

echo "Buy one get one, same pack\n";
echo "--------------------------\n";

$result = $bogo->calculate([line('A', 2, '100.00')], 1, 1, 'same_variant');
checkSame('two items earns one free', 1, $result['free_units']);
checkSame('...worth the unit price', 100.00, $result['amount']->toDecimal());

$result = $bogo->calculate([line('A', 1, '100.00')], 1, 1, 'same_variant');
checkSame('one item earns nothing', 0, $result['free_units']);
check('...and says what is needed', str_contains($result['note'], 'Add'), $result['note']);

// Three items on "buy 1 get 1" is one set plus a spare, NOT one and a half.
$result = $bogo->calculate([line('A', 3, '100.00')], 1, 1, 'same_variant');
checkSame('three items still earns only one free', 1, $result['free_units'],
    'a set is buy + get, so three is one set and one spare');

$result = $bogo->calculate([line('A', 4, '100.00')], 1, 1, 'same_variant');
checkSame('four items earns two free', 2, $result['free_units']);
checkSame('...worth twice the unit price', 200.00, $result['amount']->toDecimal());

echo "\nBuy two get one\n";
echo "---------------\n";

$result = $bogo->calculate([line('A', 2, '100.00')], 2, 1, 'same_variant');
checkSame('two items is not enough', 0, $result['free_units'],
    'a set is three items: buy two, get one');

$result = $bogo->calculate([line('A', 3, '100.00')], 2, 1, 'same_variant');
checkSame('three items earns one free', 1, $result['free_units']);

$result = $bogo->calculate([line('A', 6, '100.00')], 2, 1, 'same_variant');
checkSame('six items earns two free', 2, $result['free_units']);

$result = $bogo->calculate([line('A', 5, '100.00')], 2, 1, 'same_variant');
checkSame('five items earns one free', 1, $result['free_units'],
    'one complete set of three, with two left over');

echo "\nBuy one get five\n";
echo "----------------\n";

$result = $bogo->calculate([line('A', 6, '50.00')], 1, 5, 'same_variant');
checkSame('six items earns five free', 5, $result['free_units']);
checkSame('...worth five units', 250.00, $result['amount']->toDecimal());

$result = $bogo->calculate([line('A', 5, '50.00')], 1, 5, 'same_variant');
checkSame('five items earns nothing', 0, $result['free_units'],
    'the set needs six: one bought plus five free');

echo "\nDifferent packs are counted separately\n";
echo "--------------------------------------\n";

$result = $bogo->calculate(
    [line('A', 4, '100.00'), line('B', 1, '500.00')],
    1, 1, 'same_variant'
);
checkSame('only the pack that qualifies earns free units', 2, $result['free_units']);
checkSame('...valued at that pack\'s price', 200.00, $result['amount']->toDecimal(),
    'the expensive single item never reaches the threshold on its own');

echo "\nAcross the basket, the cheapest are free\n";
echo "----------------------------------------\n";

// Two cheap and two expensive, buy one get one: two free, and they must be the
// cheap ones. Giving away the expensive items would cost the shop 1000 instead
// of 200 on the same basket.
$result = $bogo->calculate(
    [line('CHEAP', 2, '100.00'), line('DEAR', 2, '500.00')],
    1, 1, 'cheapest_eligible'
);
checkSame('four items earns two free', 2, $result['free_units']);
checkSame('...and they are the cheapest', 200.00, $result['amount']->toDecimal(),
    'discounting the dearest would cost 1000.00 on the same basket');

// When the cheap line runs out, the next cheapest is used.
$result = $bogo->calculate(
    [line('CHEAP', 1, '100.00'), line('DEAR', 5, '500.00')],
    1, 1, 'cheapest_eligible'
);
checkSame('six items earns three free', 3, $result['free_units']);
checkSame('...one cheap and two dear', 1100.00, $result['amount']->toDecimal());

$result = $bogo->calculate(
    [line('A', 1, '100.00'), line('B', 1, '200.00')],
    1, 1, 'cheapest_eligible'
);
checkSame('two different items make one set', 1, $result['free_units']);
checkSame('...the cheaper one free', 100.00, $result['amount']->toDecimal());

echo "\nThe per-order cap\n";
echo "-----------------\n";

$result = $bogo->calculate([line('A', 50, '100.00')], 1, 1, 'same_variant', 4);
checkSame('a fifty-unit order is capped', 4, $result['free_units'],
    'without a cap this gives away twenty-five units');
checkSame('...and charged accordingly', 400.00, $result['amount']->toDecimal());
check('...with the limit disclosed', str_contains($result['note'], 'limited to 4'),
    $result['note']);

$result = $bogo->calculate([line('A', 4, '100.00')], 1, 1, 'same_variant', 10);
checkSame('a cap above the entitlement changes nothing', 2, $result['free_units']);
check('...and is not mentioned', !str_contains($result['note'], 'limited'), $result['note']);

echo "\nRefusals\n";
echo "--------\n";

checkSame('an empty basket earns nothing', 0,
    $bogo->calculate([], 1, 1)['free_units']);
checkSame('a zero buy quantity is refused', 0,
    $bogo->calculate([line('A', 10, '100.00')], 0, 1)['free_units'],
    'otherwise "buy 0 get 1" gives the shop away');
checkSame('a zero get quantity earns nothing', 0,
    $bogo->calculate([line('A', 10, '100.00')], 1, 0)['free_units']);

echo "\nThe benefit never exceeds the basket\n";
echo "------------------------------------\n";

foreach ([[1, 1], [2, 1], [1, 5], [3, 2], [1, 3]] as [$buy, $get]) {
    $lines = [line('A', 7, '99.00'), line('B', 3, '250.00')];
    $basketValue = Money::fromDecimal('99.00')->multiply(7)
        ->add(Money::fromDecimal('250.00')->multiply(3));

    $result = $bogo->calculate($lines, $buy, $get, 'cheapest_eligible');

    check(
        sprintf('buy %d get %d never exceeds the basket value', $buy, $get),
        !$result['amount']->greaterThan($basketValue),
        sprintf('%s free out of %s', $result['amount']->toDecimal(), $basketValue->toDecimal())
    );
}

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
