<?php

declare(strict_types=1);

/**
 * Unit tests for Money and PricingEngine.
 *
 *   php bin/test_pricing.php
 *
 * No database, no web server, no configuration. The pricing engine is pure by
 * design, and this is the payoff: the arithmetic that decides what customers
 * are charged can be verified in under a second.
 *
 * Every expected value below was worked out by hand. If a change to the engine
 * makes one of these fail, the engine is wrong until proven otherwise.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Helpers\Money;
use App\Services\Pricing\CartLine;
use App\Services\Pricing\DeliveryQuote;
use App\Services\Pricing\PriceAdjustment;
use App\Services\Pricing\PricingEngine;

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
    check(
        $label,
        $expected === $actual,
        sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
    );
}

echo "Money\n-----\n";

checkSame('fromDecimal string keeps exact paise', 17900, Money::fromDecimal('179.00')->paise());
checkSame('fromDecimal accepts floats', 1799, Money::fromDecimal(17.99)->paise());
checkSame('fromDecimal int', 5000, Money::fromDecimal(50)->paise());
checkSame('toDecimal round trip', 269.5, Money::fromDecimal('269.50')->toDecimal());
checkSame('empty string is zero', 0, Money::fromDecimal('')->paise());

checkSame('addition is exact', 30000, Money::fromDecimal('100.00')
    ->add(Money::fromDecimal('100.00'), Money::fromDecimal('100.00'))->paise());
checkSame('multiply by quantity', 53800, Money::fromDecimal('269.00')->multiply(2)->paise());
checkSame('clampAtZero floors negatives', 0,
    Money::fromDecimal('10.00')->subtract(Money::fromDecimal('25.00'))->clampAtZero()->paise());
checkSame('min picks the smaller', 1000,
    Money::fromDecimal('50.00')->min(Money::fromDecimal('10.00'))->paise());
checkSame('formats Indian style', '₹1,599.00', Money::fromDecimal('1599.00')->format());

// The float trap this class exists to avoid: 0.1 + 0.2 !== 0.3 in binary
// floating point, but it must be exact in paise.
$floatSum = 0.1 + 0.2;
check('float arithmetic really is lossy (sanity check)', $floatSum !== 0.3);
checkSame('paise arithmetic is not', 30,
    Money::fromDecimal('0.10')->add(Money::fromDecimal('0.20'))->paise());

echo "\nGST extraction (Indian MRP is tax-inclusive)\n--------------------------------------------\n";

$split = Money::fromDecimal('105.00')->extractInclusiveTax(5.0);
checkSame('5% of an inclusive 105.00 leaves net 100.00', 10000, $split['net']->paise());
checkSame('...and tax of 5.00', 500, $split['tax']->paise());

$split = Money::fromDecimal('112.00')->extractInclusiveTax(12.0);
checkSame('12% of an inclusive 112.00 leaves net 100.00', 10000, $split['net']->paise());
checkSame('...and tax of 12.00', 1200, $split['tax']->paise());

// The important property: no rounding can make the parts miss the whole.
$allExact = true;

for ($paise = 1; $paise <= 4000; ++$paise) {
    foreach ([0.0, 5.0, 12.0, 18.0, 28.0] as $rate) {
        $amount = Money::fromPaise($paise);
        $parts = $amount->extractInclusiveTax($rate);

        if ($parts['net']->add($parts['tax'])->paise() !== $paise) {
            $allExact = false;

            break 2;
        }
    }
}

check('net + tax always equals the inclusive amount exactly (20,000 cases)', $allExact);

$zeroRate = Money::fromDecimal('100.00')->extractInclusiveTax(0.0);
checkSame('a 0% rate yields no tax', 0, $zeroRate['tax']->paise());

echo "\nAllocation (largest remainder)\n------------------------------\n";

$shares = Money::fromDecimal('10.00')->allocate([1, 1, 1]);
checkSame('10.00 across three equal lines: first gets the odd paisa', 334, $shares[0]->paise());
checkSame('...second', 333, $shares[1]->paise());
checkSame('...third', 333, $shares[2]->paise());
checkSame('...and the parts sum to the whole',
    1000, $shares[0]->add($shares[1], $shares[2])->paise());

$weighted = Money::fromDecimal('100.00')->allocate([30000, 20000]);
checkSame('weighted 60/40 split, larger share', 6000, $weighted[0]->paise());
checkSame('weighted 60/40 split, smaller share', 4000, $weighted[1]->paise());

$zeroRatios = Money::fromDecimal('1.00')->allocate([0, 0, 0]);
checkSame('zero ratios split as evenly as possible', 100,
    $zeroRatios[0]->add($zeroRatios[1], $zeroRatios[2])->paise());

checkSame('allocating across nothing returns nothing', [], Money::fromDecimal('5.00')->allocate([]));

// Property test: however awkward the numbers, allocation never leaks a paisa.
$leaks = 0;
mt_srand(20260730);

for ($trial = 0; $trial < 3000; ++$trial) {
    $total = mt_rand(1, 500000);
    $count = mt_rand(1, 9);
    $ratios = [];

    for ($i = 0; $i < $count; ++$i) {
        $ratios[] = mt_rand(1, 99999);
    }

    $sum = 0;

    foreach (Money::fromPaise($total)->allocate($ratios) as $share) {
        $sum += $share->paise();
    }

    if ($sum !== $total) {
        ++$leaks;
    }
}

checkSame('3,000 random allocations, zero paise leaked', 0, $leaks);

echo "\nPricing engine\n--------------\n";

$engine = new PricingEngine();

// --- Empty cart ---------------------------------------------------------
$empty = $engine->quote([]);
check('an empty cart reports empty', $empty->isEmpty());
checkSame('...with a zero total', 0.0, $empty->grandTotal->toDecimal());
check('...and still reconciles', $empty->reconciles());

// --- Single line, offer price, no delivery ------------------------------
$turmeric = new CartLine(
    reference: 'line-1',
    description: 'Organic Turmeric Powder - 250 g pouch',
    quantity: 2,
    unitMrp: Money::fromDecimal('349.00'),
    unitPrice: Money::fromDecimal('269.00'),
    gstRate: 5.0,
    unitWeightGrams: 295,
);

$quote = $engine->quote([$turmeric]);

checkSame('MRP total is 2 x 349.00', 698.0, $quote->itemsMrpTotal->toDecimal());
checkSame('subtotal is 2 x 269.00', 538.0, $quote->itemsSubtotal->toDecimal());
checkSame('product discount is the difference', 160.0, $quote->productDiscount->toDecimal());
checkSame('taxable value extracted at 5%', 512.38, $quote->taxableValue->toDecimal());
checkSame('GST extracted, not added', 25.62, $quote->taxTotal->toDecimal());
checkSame('grand total equals the subtotal (tax was already inside)', 538.0, $quote->grandTotal->toDecimal());
checkSame('weight is quantity x unit weight', 590, $quote->totalWeightGrams);
checkSame('unit count', 2, $quote->unitCount);
check('reconciles', $quote->reconciles());

// This is the mistake the inclusive model exists to prevent.
check('tax was NOT added on top of MRP',
    $quote->grandTotal->toDecimal() < 538.0 + 25.62);

// --- Mixed GST rates with an order-level discount -----------------------
// NOTE: lineA has an MRP of 350.00 but SELLS at 300.00. Every expectation
// below is against the selling price, because that is what a cart subtotals.
$lineA = new CartLine('a', 'Spice at 5%', 1, Money::fromDecimal('350.00'), Money::fromDecimal('300.00'), 5.0, 300);
$lineB = new CartLine('b', 'Nuts at 12%', 1, Money::fromDecimal('250.00'), Money::fromDecimal('200.00'), 12.0, 500);

$coupon = new PriceAdjustment(
    code: 'FLAT100',
    label: 'Flat ₹100 off',
    amount: Money::fromDecimal('100.00'),
);

$quote = $engine->quote([$lineA, $lineB], null, [$coupon]);

checkSame('subtotal', 500.0, $quote->itemsSubtotal->toDecimal());
checkSame('order discount applied', 100.0, $quote->orderDiscount->toDecimal());
checkSame('grand total', 400.0, $quote->grandTotal->toDecimal());
checkSame('discount apportioned 60% to the larger line', 60.0, $quote->lines[0]->apportionedDiscount->toDecimal());
checkSame('...and 40% to the smaller', 40.0, $quote->lines[1]->apportionedDiscount->toDecimal());
checkSame('line A payable after apportionment', 240.0, $quote->lines[0]->linePayable->toDecimal());
checkSame('line B payable after apportionment', 160.0, $quote->lines[1]->linePayable->toDecimal());
checkSame('line A tax at 5% of what was actually paid', 11.43, $quote->lines[0]->taxAmount->toDecimal());
checkSame('line B tax at 12% of what was actually paid', 17.14, $quote->lines[1]->taxAmount->toDecimal());
checkSame('total tax', 28.57, $quote->taxTotal->toDecimal());
check('reconciles', $quote->reconciles());

checkSame('tax is broken down per rate', 2, count($quote->taxByRate));
checkSame('lower rate reported first', 5.0, $quote->taxByRate[0]['rate']);

// --- A discount can never exceed what it discounts ----------------------
$hugeCoupon = new PriceAdjustment('BIG', 'Absurd coupon', Money::fromDecimal('9999.00'));
$quote = $engine->quote([$lineA], null, [$hugeCoupon]);

checkSame('discount is capped at the subtotal', 300.0, $quote->orderDiscount->toDecimal());
checkSame('grand total never goes negative', 0.0, $quote->grandTotal->toDecimal());
check('reconciles', $quote->reconciles());

// --- Delivery -----------------------------------------------------------
$delivery = new DeliveryQuote(
    zoneCode: 'SOUTH',
    zoneName: 'South India',
    charge: Money::fromDecimal('59.00'),
    chargeBeforeWaiver: Money::fromDecimal('59.00'),
    gstRate: 18.0,
    slaMinDays: 3,
    slaMaxDays: 5,
    chargeableWeightGrams: 300,
    isServiceable: true,
);

$quote = $engine->quote([$lineA], $delivery);

checkSame('delivery is added to the total', 359.0, $quote->grandTotal->toDecimal());
checkSame('delivery GST is extracted from the charge', 9.0, $quote->taxByRate[1]['rate'] === 18.0
    ? round($quote->taxByRate[1]['tax_amount'], 0)
    : -1.0);
check('delivery appears as its own tax rate', count($quote->taxByRate) === 2);
check('reconciles with delivery', $quote->reconciles());

// --- Free delivery waiver ----------------------------------------------
$waived = new DeliveryQuote(
    zoneCode: 'SOUTH',
    zoneName: 'South India',
    charge: Money::zero(),
    chargeBeforeWaiver: Money::fromDecimal('59.00'),
    gstRate: 18.0,
    slaMinDays: 3,
    slaMaxDays: 5,
    chargeableWeightGrams: 300,
    isServiceable: true,
    waiverReason: 'Free delivery on orders above ₹999.00',
);

$quote = $engine->quote([$lineA], $waived);

checkSame('a waived charge costs nothing', 0.0, $quote->deliveryCharge->toDecimal());
checkSame('...but the original charge is still reported', 59.0, $quote->deliveryChargeBeforeWaiver->toDecimal());
checkSame('grand total excludes waived delivery', 300.0, $quote->grandTotal->toDecimal());
check('waiver reason is carried through', $quote->delivery->waiverReason !== null);

// --- Delivery-scoped discount ------------------------------------------
$shippingCoupon = new PriceAdjustment(
    code: 'FREESHIP',
    label: 'Free shipping',
    amount: Money::fromDecimal('100.00'),
    scope: PriceAdjustment::SCOPE_DELIVERY,
);

$quote = $engine->quote([$lineA], $delivery, [$shippingCoupon]);

checkSame('a shipping coupon is capped at the actual charge', 59.0, $quote->deliveryDiscount->toDecimal());
checkSame('shipping is fully covered', 0.0, $quote->deliveryCharge->toDecimal());
checkSame('items are untouched by a delivery-scoped coupon', 300.0, $quote->grandTotal->toDecimal());
check('reconciles', $quote->reconciles());

// --- Surcharge ---------------------------------------------------------
$surcharge = new PriceAdjustment(
    code: 'GIFTWRAP',
    label: 'Gift wrapping',
    amount: Money::fromDecimal('49.00'),
    type: PriceAdjustment::TYPE_SURCHARGE,
);

$quote = $engine->quote([$lineA], null, [$surcharge]);
checkSame('a surcharge increases the total', 349.0, $quote->grandTotal->toDecimal());
check('reconciles', $quote->reconciles());

// --- Awkward numbers: many lines, odd prices, an odd discount ----------
$awkward = [];

for ($i = 1; $i <= 17; ++$i) {
    $awkward[] = new CartLine(
        reference: 'awk-' . $i,
        description: 'Line ' . $i,
        quantity: $i % 4 + 1,
        unitMrp: Money::fromPaise(9999 + $i * 137),
        unitPrice: Money::fromPaise(8888 + $i * 111),
        gstRate: [5.0, 12.0, 18.0][$i % 3],
        unitWeightGrams: 100 + $i * 37,
    );
}

$oddCoupon = new PriceAdjustment('ODD', 'Odd amount off', Money::fromPaise(33333));
$quote = $engine->quote($awkward, $delivery, [$oddCoupon]);

check('17 awkward lines still reconcile', $quote->reconciles(),
    'grand total ' . $quote->grandTotal->toDecimal());

$apportioned = Money::zero();

foreach ($quote->lines as $line) {
    $apportioned = $apportioned->add($line->apportionedDiscount);
}

checkSame('apportioned discounts sum to exactly the coupon value',
    33333, $apportioned->paise());

$lineTax = Money::zero();
$lineTaxable = Money::zero();

foreach ($quote->lines as $line) {
    $lineTax = $lineTax->add($line->taxAmount);
    $lineTaxable = $lineTaxable->add($line->taxableValue);
}

// The reported totals must be the sum of the lines plus delivery, not a
// separate calculation that could drift from them.
$deliverySplit = $quote->deliveryCharge->extractInclusiveTax($quote->delivery->gstRate);

check('reported tax total equals line taxes plus delivery tax',
    $lineTax->add($deliverySplit['tax'])->equals($quote->taxTotal),
    sprintf('lines %s + delivery %s vs reported %s',
        $lineTax, $deliverySplit['tax'], $quote->taxTotal));

check('reported taxable value equals line taxable values plus delivery net',
    $lineTaxable->add($deliverySplit['net'])->equals($quote->taxableValue),
    sprintf('lines %s + delivery %s vs reported %s',
        $lineTaxable, $deliverySplit['net'], $quote->taxableValue));

// --- Invalid input is rejected rather than silently mispriced ----------
$rejected = false;

try {
    new CartLine('bad', 'Zero quantity', 0, Money::fromDecimal('10.00'), Money::fromDecimal('10.00'), 5.0, 100);
} catch (InvalidArgumentException) {
    $rejected = true;
}

check('a zero-quantity line is rejected', $rejected);

$rejected = false;

try {
    new PriceAdjustment('NEG', 'Negative', Money::fromPaise(-100));
} catch (InvalidArgumentException) {
    $rejected = true;
}

check('a negative adjustment is rejected (direction comes from type)', $rejected);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
