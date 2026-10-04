<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Helpers\Money;

/**
 * A fully costed line. Every number the invoice needs is here, so Phase 5 can
 * copy these straight onto order_items without recalculating anything.
 */
final class PricedLine
{
    public function __construct(
        public readonly string $reference,
        public readonly string $description,
        public readonly int $quantity,
        public readonly Money $unitMrp,
        public readonly Money $unitPrice,
        public readonly Money $lineMrp,
        public readonly Money $lineSubtotal,
        public readonly Money $productDiscount,
        public readonly Money $apportionedDiscount,
        public readonly Money $linePayable,
        public readonly Money $taxableValue,
        public readonly Money $taxAmount,
        public readonly float $gstRate,
        public readonly int $lineWeightGrams,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_mrp' => $this->unitMrp->toDecimal(),
            'unit_price' => $this->unitPrice->toDecimal(),
            'line_mrp' => $this->lineMrp->toDecimal(),
            'line_subtotal' => $this->lineSubtotal->toDecimal(),
            'product_discount' => $this->productDiscount->toDecimal(),
            'apportioned_discount' => $this->apportionedDiscount->toDecimal(),
            'line_payable' => $this->linePayable->toDecimal(),
            'taxable_value' => $this->taxableValue->toDecimal(),
            'tax_amount' => $this->taxAmount->toDecimal(),
            'gst_rate' => $this->gstRate,
            'line_weight_grams' => $this->lineWeightGrams,
        ];
    }
}
