<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Helpers\Money;

/**
 * The complete costing of a cart or order draft.
 *
 * Shaped to be handed straight to a client: every money field serialises as a
 * plain decimal, and the tax breakdown is per rate because a cart mixing 5%
 * spices and 12% nuts needs both lines on the invoice.
 */
final class PriceBreakdown
{
    /**
     * @param array<int, PricedLine>       $lines
     * @param array<int, array{rate:float, taxable_value:float, tax_amount:float}> $taxByRate
     * @param array<int, PriceAdjustment>  $adjustments
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $itemCount,
        public readonly int $unitCount,
        public readonly int $totalWeightGrams,
        public readonly Money $itemsMrpTotal,
        public readonly Money $itemsSubtotal,
        public readonly Money $productDiscount,
        public readonly Money $orderDiscount,
        public readonly Money $orderSurcharge,
        public readonly Money $deliveryChargeBeforeWaiver,
        public readonly Money $deliveryCharge,
        public readonly Money $deliveryDiscount,
        public readonly Money $taxableValue,
        public readonly Money $taxTotal,
        public readonly array $taxByRate,
        public readonly Money $grandTotal,
        public readonly Money $totalSavings,
        public readonly array $adjustments,
        public readonly DeliveryQuote $delivery,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Reconciliation check. The lines must add up to the grand total; if they
     * ever do not, the engine has a rounding bug and the caller should know
     * before an invoice is issued rather than after.
     */
    public function reconciles(): bool
    {
        $fromLines = Money::zero();

        foreach ($this->lines as $line) {
            $fromLines = $fromLines->add($line->linePayable);
        }

        $expected = $fromLines->add($this->orderSurcharge, $this->deliveryCharge);

        return $expected->equals($this->grandTotal);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (PricedLine $line): array => $line->toArray(), $this->lines),
            'summary' => [
                'item_count' => $this->itemCount,
                'unit_count' => $this->unitCount,
                'total_weight_grams' => $this->totalWeightGrams,
                'items_mrp_total' => $this->itemsMrpTotal->toDecimal(),
                'items_subtotal' => $this->itemsSubtotal->toDecimal(),
                'product_discount' => $this->productDiscount->toDecimal(),
                'order_discount' => $this->orderDiscount->toDecimal(),
                'order_surcharge' => $this->orderSurcharge->toDecimal(),
                'delivery_charge' => $this->deliveryCharge->toDecimal(),
                'delivery_charge_before_waiver' => $this->deliveryChargeBeforeWaiver->toDecimal(),
                'delivery_discount' => $this->deliveryDiscount->toDecimal(),
                'taxable_value' => $this->taxableValue->toDecimal(),
                'tax_total' => $this->taxTotal->toDecimal(),
                'grand_total' => $this->grandTotal->toDecimal(),
                'total_savings' => $this->totalSavings->toDecimal(),
                // Indian MRP convention: GST is already inside the prices shown,
                // so it is reported for transparency and never added on top.
                'prices_include_gst' => true,
            ],
            'tax_breakdown' => $this->taxByRate,
            'adjustments' => array_map(
                static fn (PriceAdjustment $adjustment): array => $adjustment->toArray(),
                $this->adjustments
            ),
            'delivery' => $this->delivery->toArray(),
        ];
    }
}
