<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Helpers\Money;

/**
 * Input to the pricing engine: one purchasable line, already resolved.
 *
 * The engine takes these rather than raw database rows so it has no dependency
 * on the cart schema. Phase 5 will build the same objects from an order draft
 * and get identical totals — which is the whole point of having one engine.
 */
final class CartLine
{
    public function __construct(
        public readonly string $reference,
        public readonly string $description,
        public readonly int $quantity,
        public readonly Money $unitMrp,
        public readonly Money $unitPrice,
        public readonly float $gstRate,
        public readonly int $unitWeightGrams,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('A priced line needs a quantity of at least 1.');
        }

        if ($gstRate < 0 || $gstRate > 100) {
            throw new \InvalidArgumentException('GST rate must be between 0 and 100.');
        }
    }

    public function lineMrp(): Money
    {
        return $this->unitMrp->multiply($this->quantity);
    }

    public function linePrice(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function lineWeightGrams(): int
    {
        return $this->unitWeightGrams * $this->quantity;
    }
}
