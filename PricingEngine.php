<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Helpers\Money;

/**
 * The one place in the platform that turns lines into a total.
 *
 * Cart, checkout, order creation and invoicing all call this. If any of them
 * ever computes its own total, the customer will eventually see one number on
 * the cart page and a different one on the payment screen, and the difference
 * will be the one they screenshot.
 *
 * The calculation, in order:
 *
 *   1. Per line: MRP total, price total, and the product discount between them.
 *   2. Order-level discounts are apportioned across lines in proportion to
 *      line value, so each line's taxable value reflects what was actually
 *      paid for it. This matters for GST: a flat ₹100 coupon on a cart mixing
 *      5% and 12% goods produces a different tax liability depending on how it
 *      is split, and apportioning by value is the defensible split.
 *   3. GST is EXTRACTED from each line, not added. Indian MRP is inclusive by
 *      law, so the tax is already inside the price.
 *   4. Delivery is added, then any delivery-scoped adjustment applied.
 *
 * The engine is pure: no database, no clock, no request. Same inputs, same
 * outputs, which is what makes it testable.
 */
final class PricingEngine
{
    /**
     * @param array<int, CartLine>       $lines
     * @param array<int, PriceAdjustment> $adjustments
     */
    public function quote(
        array $lines,
        ?DeliveryQuote $delivery = null,
        array $adjustments = [],
    ): PriceBreakdown {
        $delivery ??= DeliveryQuote::none();

        // ---------------------------------------------------------------
        // 1. Line subtotals
        // ---------------------------------------------------------------
        $itemsMrpTotal = Money::zero();
        $itemsSubtotal = Money::zero();
        $totalWeightGrams = 0;
        $unitCount = 0;

        foreach ($lines as $line) {
            $itemsMrpTotal = $itemsMrpTotal->add($line->lineMrp());
            $itemsSubtotal = $itemsSubtotal->add($line->linePrice());
            $totalWeightGrams += $line->lineWeightGrams();
            $unitCount += $line->quantity;
        }

        $productDiscount = $itemsMrpTotal->subtract($itemsSubtotal)->clampAtZero();

        // ---------------------------------------------------------------
        // 2. Split adjustments by scope and direction
        // ---------------------------------------------------------------
        $orderDiscount = Money::zero();
        $orderSurcharge = Money::zero();
        $deliveryDiscount = Money::zero();

        foreach ($adjustments as $adjustment) {
            if ($adjustment->scope === PriceAdjustment::SCOPE_DELIVERY) {
                if ($adjustment->isDiscount()) {
                    $deliveryDiscount = $deliveryDiscount->add($adjustment->amount);
                }

                continue;
            }

            if ($adjustment->isDiscount()) {
                $orderDiscount = $orderDiscount->add($adjustment->amount);
            } else {
                $orderSurcharge = $orderSurcharge->add($adjustment->amount);
            }
        }

        // A discount can never exceed what is being discounted.
        $orderDiscount = $orderDiscount->min($itemsSubtotal);
        $deliveryDiscount = $deliveryDiscount->min($delivery->charge);

        // ---------------------------------------------------------------
        // 3. Apportion the order discount, then extract GST per line
        // ---------------------------------------------------------------
        $ratios = [];

        foreach ($lines as $index => $line) {
            $ratios[$index] = $line->linePrice()->paise();
        }

        $discountShares = $orderDiscount->isZero()
            ? array_map(static fn (): Money => Money::zero(), $ratios)
            : $orderDiscount->allocate($ratios);

        $pricedLines = [];
        $taxableTotal = Money::zero();
        $taxTotal = Money::zero();
        /** @var array<string, array{rate:float, taxable:Money, tax:Money}> $taxByRate */
        $taxByRate = [];

        foreach ($lines as $index => $line) {
            $lineShare = $discountShares[$index] ?? Money::zero();
            $payable = $line->linePrice()->subtract($lineShare)->clampAtZero();

            $split = $payable->extractInclusiveTax($line->gstRate);

            $taxableTotal = $taxableTotal->add($split['net']);
            $taxTotal = $taxTotal->add($split['tax']);

            $rateKey = number_format($line->gstRate, 2, '.', '');
            $taxByRate[$rateKey] ??= ['rate' => $line->gstRate, 'taxable' => Money::zero(), 'tax' => Money::zero()];
            $taxByRate[$rateKey]['taxable'] = $taxByRate[$rateKey]['taxable']->add($split['net']);
            $taxByRate[$rateKey]['tax'] = $taxByRate[$rateKey]['tax']->add($split['tax']);

            $pricedLines[] = new PricedLine(
                reference: $line->reference,
                description: $line->description,
                quantity: $line->quantity,
                unitMrp: $line->unitMrp,
                unitPrice: $line->unitPrice,
                lineMrp: $line->lineMrp(),
                lineSubtotal: $line->linePrice(),
                productDiscount: $line->lineMrp()->subtract($line->linePrice())->clampAtZero(),
                apportionedDiscount: $lineShare,
                linePayable: $payable,
                taxableValue: $split['net'],
                taxAmount: $split['tax'],
                gstRate: $line->gstRate,
                lineWeightGrams: $line->lineWeightGrams(),
            );
        }

        // ---------------------------------------------------------------
        // 4. Delivery and grand total
        // ---------------------------------------------------------------
        $deliveryCharge = $delivery->charge->subtract($deliveryDiscount)->clampAtZero();
        $deliveryTaxSplit = $deliveryCharge->extractInclusiveTax($delivery->gstRate);

        if ($deliveryCharge->isPositive() && $delivery->gstRate > 0) {
            $rateKey = number_format($delivery->gstRate, 2, '.', '');
            $taxByRate[$rateKey] ??= ['rate' => $delivery->gstRate, 'taxable' => Money::zero(), 'tax' => Money::zero()];
            $taxByRate[$rateKey]['taxable'] = $taxByRate[$rateKey]['taxable']->add($deliveryTaxSplit['net']);
            $taxByRate[$rateKey]['tax'] = $taxByRate[$rateKey]['tax']->add($deliveryTaxSplit['tax']);

            $taxableTotal = $taxableTotal->add($deliveryTaxSplit['net']);
            $taxTotal = $taxTotal->add($deliveryTaxSplit['tax']);
        }

        $grandTotal = $itemsSubtotal
            ->subtract($orderDiscount)
            ->add($orderSurcharge, $deliveryCharge);

        // Sort numerically by rate. The bucket keys are formatted strings, so
        // ksort would order 12.00 before 5.00 and put the invoice lines in a
        // nonsensical order.
        uasort(
            $taxByRate,
            static fn (array $left, array $right): int => $left['rate'] <=> $right['rate']
        );

        return new PriceBreakdown(
            lines: $pricedLines,
            itemCount: count($lines),
            unitCount: $unitCount,
            totalWeightGrams: $totalWeightGrams,
            itemsMrpTotal: $itemsMrpTotal,
            itemsSubtotal: $itemsSubtotal,
            productDiscount: $productDiscount,
            orderDiscount: $orderDiscount,
            orderSurcharge: $orderSurcharge,
            deliveryChargeBeforeWaiver: $delivery->chargeBeforeWaiver,
            deliveryCharge: $deliveryCharge,
            deliveryDiscount: $deliveryDiscount,
            taxableValue: $taxableTotal,
            taxTotal: $taxTotal,
            taxByRate: array_values(array_map(
                static fn (array $bucket): array => [
                    'rate' => $bucket['rate'],
                    'taxable_value' => $bucket['taxable']->toDecimal(),
                    'tax_amount' => $bucket['tax']->toDecimal(),
                ],
                $taxByRate
            )),
            grandTotal: $grandTotal,
            totalSavings: $productDiscount->add($orderDiscount, $deliveryDiscount),
            adjustments: $adjustments,
            delivery: $delivery,
        );
    }
}
