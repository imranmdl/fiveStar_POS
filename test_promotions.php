<?php

declare(strict_types=1);

/**
 * Unit tests for DiscountCalculator and its interaction with the pricing engine.
 *
 *   php bin/test_promotions.php
 *
 * No database, no server. Discount arithmetic is where a rounding slip becomes a
 * margin leak, and where an uncapped percentage becomes an unbounded liability,
 * so it gets the same treatment as the pricing engine: every expected value here
 * was worked out by hand and verified independently.
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
use App\Services\Promotions\DiscountCalculator;

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

echo "Percentage discounts\n--------------------\n";

$result = DiscountCalculator::compute('percentage', 10.0, null, Money::fromDecimal('1000.00'), Money::zero());
checkSame('10% of 1000.00', 100.0, $result['amount']->toDecimal());
checkSame('...is order-scoped', PriceAdjustment::SCOPE_ORDER, $result['scope']);

$result = DiscountCalculator::compute(
    'percentage',
    10.0,
    Money::fromDecimal('150.00'),
    Money::fromDecimal('2000.00'),
    Money::zero()
);
checkSame('10% of 2000.00 capped at 150.00', 150.0, $result['amount']->toDecimal());

$result = DiscountCalculator::compute('percentage', 7.5, null, Money::fromDecimal('269.00'), Money::zero());
checkSame('7.5% of 269.00 rounds to the paisa', 20.18, $result['amount']->toDecimal());

$result = DiscountCalculator::compute('percentage', 100.0, null, Money::fromDecimal('500.00'), Money::zero());
checkSame('100% never exceeds the eligible subtotal', 500.0, $result['amount']->toDecimal());

echo "\nFlat discounts\n--------------\n";

$result = DiscountCalculator::compute('flat', 50.0, null, Money::fromDecimal('499.00'), Money::zero());
checkSame('flat 50 on an eligible 499', 50.0, $result['amount']->toDecimal());

// The cap that stops a coupon paying the customer to shop.
$result = DiscountCalculator::compute('flat', 500.0, null, Money::fromDecimal('300.00'), Money::zero());
checkSame('flat 500 on a 300 cart is capped at 300', 300.0, $result['amount']->toDecimal());

$result = DiscountCalculator::compute('flat', 50.0, null, Money::zero(), Money::zero());
checkSame('nothing eligible means nothing off', 0.0, $result['amount']->toDecimal());

echo "\nFree delivery\n-------------\n";

$result = DiscountCalculator::compute('free_delivery', 0.0, null, Money::fromDecimal('500.00'), Money::fromDecimal('59.00'));
checkSame('worth exactly the delivery charge', 59.0, $result['amount']->toDecimal());
checkSame('...and is delivery-scoped', PriceAdjustment::SCOPE_DELIVERY, $result['scope']);

$result = DiscountCalculator::compute('free_delivery', 0.0, null, Money::fromDecimal('500.00'), Money::zero());
checkSame('worthless when delivery is already free', 0.0, $result['amount']->toDecimal());

$result = DiscountCalculator::compute(
    'free_delivery',
    0.0,
    Money::fromDecimal('40.00'),
    Money::fromDecimal('500.00'),
    Money::fromDecimal('59.00')
);
checkSame('a capped waiver covers only part of the charge', 40.0, $result['amount']->toDecimal());

$result = DiscountCalculator::compute('none', 0.0, null, Money::fromDecimal('500.00'), Money::fromDecimal('59.00'));
checkSame('a campaign with no discount takes nothing off', 0.0, $result['amount']->toDecimal());

$rejected = false;

try {
    DiscountCalculator::compute('buy_one_get_one', 0.0, null, Money::zero(), Money::zero());
} catch (InvalidArgumentException) {
    $rejected = true;
}

check('an unknown discount type throws rather than silently taking nothing off', $rejected);

echo "\nDescriptions\n------------\n";

checkSame('percentage without a cap', '10% off',
    DiscountCalculator::describe('percentage', 10.0, null));
checkSame('percentage with a cap', '15% off up to ₹500.00',
    DiscountCalculator::describe('percentage', 15.0, Money::fromDecimal('500.00')));
checkSame('flat', '₹50.00 off', DiscountCalculator::describe('flat', 50.0, null));
checkSame('free delivery', 'Free delivery', DiscountCalculator::describe('free_delivery', 0.0, null));
checkSame('trailing zeros trimmed', '7.5% off',
    DiscountCalculator::describe('percentage', 7.5, null));

echo "\nCoupon meeting the pricing engine\n---------------------------------\n";

// A real mixed-rate cart: turmeric at 5%, almonds at 12%, WELCOME10 on top.
$engine = new PricingEngine();

$turmeric = new CartLine('t', 'Turmeric 250g', 2,
    Money::fromDecimal('349.00'), Money::fromDecimal('269.00'), 5.0, 295);
$almonds = new CartLine('a', 'Almonds 250g', 1,
    Money::fromDecimal('499.00'), Money::fromDecimal('449.00'), 12.0, 290);

$subtotal = Money::fromDecimal('987.00');

$computed = DiscountCalculator::compute('percentage', 10.0, Money::fromDecimal('150.00'), $subtotal, Money::zero());
checkSame('WELCOME10 on a 987.00 cart', 98.7, $computed['amount']->toDecimal());

$coupon = new PriceAdjustment('WELCOME10', 'Welcome offer: 10% off', $computed['amount']);
$quote = $engine->quote([$turmeric, $almonds], null, [$coupon]);

checkSame('subtotal', 987.0, $quote->itemsSubtotal->toDecimal());
checkSame('grand total after the coupon', 888.3, $quote->grandTotal->toDecimal());
checkSame('discount apportioned to the 5% line', 53.8, $quote->lines[0]->apportionedDiscount->toDecimal());
checkSame('discount apportioned to the 12% line', 44.9, $quote->lines[1]->apportionedDiscount->toDecimal());
checkSame('5% line pays', 484.2, $quote->lines[0]->linePayable->toDecimal());
checkSame('12% line pays', 404.1, $quote->lines[1]->linePayable->toDecimal());

// The reason apportionment matters: tax follows what was actually paid per line.
checkSame('tax on the 5% line', 23.06, $quote->lines[0]->taxAmount->toDecimal());
checkSame('tax on the 12% line', 43.3, $quote->lines[1]->taxAmount->toDecimal());
checkSame('total tax after the discount', 66.36, $quote->taxTotal->toDecimal());
check('reconciles', $quote->reconciles());

// Without apportionment, tax would be computed on the pre-discount value and
// overstate the liability. Confirm the discount really did reduce it.
$undiscounted = $engine->quote([$turmeric, $almonds]);
check('a coupon reduces GST because it reduces the transaction value',
    $quote->taxTotal->lessThan($undiscounted->taxTotal),
    sprintf('%s vs %s', $quote->taxTotal, $undiscounted->taxTotal));

echo "\nWallet credit is a tender, not a discount\n-----------------------------------------\n";

// This is the distinction the whole wallet design rests on. Wallet credit is
// applied AFTER the total, so it changes what is collected online and nothing
// else. If it were modelled as a discount, GST would fall with it and the
// invoice would understate the liability.
$grandTotal = $quote->grandTotal;
$walletApplied = Money::fromDecimal('50.00');
$amountPayable = $grandTotal->subtract($walletApplied);

checkSame('grand total is unchanged by wallet credit', 888.3, $grandTotal->toDecimal());
checkSame('only the amount payable online changes', 838.3, $amountPayable->toDecimal());
checkSame('GST is unchanged by wallet credit', 66.36, $quote->taxTotal->toDecimal());

// The percentage cap that keeps wallet credit a supplement rather than a
// replacement for a real payment.
$cap = $grandTotal->percentage(20.0);
checkSame('20% of the order is the redemption ceiling', 177.66, $cap->toDecimal());
check('a 50.00 request sits under the ceiling', $walletApplied->lessThan($cap));

$greedy = Money::fromDecimal('900.00');
checkSame('an over-cap request clamps to the ceiling', 177.66, $greedy->min($cap)->toDecimal());

// And credit can never exceed what is actually owed.
$smallTotal = Money::fromDecimal('120.00');
checkSame('credit never exceeds the amount owed', 120.0,
    Money::fromDecimal('500.00')->min($smallTotal)->toDecimal());

echo "\nStacking: order-scoped and delivery-scoped do not compete\n"
    . "---------------------------------------------------------\n";

$delivery = new DeliveryQuote(
    zoneCode: 'SOUTH',
    zoneName: 'South India',
    charge: Money::fromDecimal('59.00'),
    chargeBeforeWaiver: Money::fromDecimal('59.00'),
    gstRate: 18.0,
    slaMinDays: 3,
    slaMaxDays: 5,
    chargeableWeightGrams: 880,
    isServiceable: true,
);

$percentOff = new PriceAdjustment('DRYFRUITWEEK', 'Dry Fruit Week',
    Money::fromDecimal('40.00'), PriceAdjustment::TYPE_DISCOUNT, PriceAdjustment::SCOPE_ORDER);
$shipFree = new PriceAdjustment('FREESHIP', 'Free delivery',
    Money::fromDecimal('59.00'), PriceAdjustment::TYPE_DISCOUNT, PriceAdjustment::SCOPE_DELIVERY);

$quote = $engine->quote([$turmeric, $almonds], $delivery, [$percentOff, $shipFree]);

checkSame('order discount applied', 40.0, $quote->orderDiscount->toDecimal());
checkSame('delivery fully waived', 0.0, $quote->deliveryCharge->toDecimal());
checkSame('grand total is subtotal minus order discount', 947.0, $quote->grandTotal->toDecimal());
check('reconciles with both scopes in play', $quote->reconciles());

// Two competing order-scoped discounts are the resolver's job to prevent, but
// the engine must still behave sanely if both arrive.
$twoOrderDiscounts = $engine->quote([$turmeric], null, [
    new PriceAdjustment('A', 'First', Money::fromDecimal('100.00')),
    new PriceAdjustment('B', 'Second', Money::fromDecimal('100.00')),
]);
checkSame('the engine sums adjustments it is given', 200.0,
    $twoOrderDiscounts->orderDiscount->toDecimal());
check('and still reconciles', $twoOrderDiscounts->reconciles());

echo "\nUncapped percentage exposure\n----------------------------\n";

// Why activation refuses an uncapped percentage coupon: the liability grows
// without bound as the order does.
$small = DiscountCalculator::compute('percentage', 15.0, null, Money::fromDecimal('1000.00'), Money::zero());
$large = DiscountCalculator::compute('percentage', 15.0, null, Money::fromDecimal('100000.00'), Money::zero());

checkSame('15% of 1,000', 150.0, $small['amount']->toDecimal());
checkSame('15% of 1,00,000 — unbounded without a cap', 15000.0, $large['amount']->toDecimal());

$capped = DiscountCalculator::compute('percentage', 15.0, Money::fromDecimal('500.00'),
    Money::fromDecimal('100000.00'), Money::zero());
checkSame('...bounded once a cap is set', 500.0, $capped['amount']->toDecimal());

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
