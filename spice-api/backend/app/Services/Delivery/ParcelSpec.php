<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Helpers\Money;

/**
 * A parcel as the courier will see it.
 *
 * The important field is `chargeableWeightGrams`. Couriers bill on whichever is
 * greater of actual and volumetric weight, and for a spice and dry-fruit
 * retailer both cases are common: a kilo of whole spices is dense and bills on
 * the scale, while a gift box of saffron tins is light and bulky and bills on
 * its volume. Quoting on actual weight alone means every bulky order is
 * underquoted and the margin is eaten silently.
 */
final class ParcelSpec
{
    public function __construct(
        public readonly int $actualWeightGrams,
        public readonly int $volumetricWeightGrams,
        public readonly int $lengthMm,
        public readonly int $widthMm,
        public readonly int $heightMm,
        public readonly Money $declaredValue,
        public readonly string $destinationPincode,
        public readonly string $zoneCode,
        public readonly bool $isFragile = false,
        public readonly bool $usedDefaultDimensions = false,
    ) {
    }

    public function chargeableWeightGrams(): int
    {
        return max($this->actualWeightGrams, $this->volumetricWeightGrams);
    }

    /**
     * Volumetric weight in grams.
     *
     * The industry formula is (L x W x H in cm) / divisor, giving kilograms.
     * The divisor is usually 5000, but some couriers use 4000, which makes the
     * same parcel 25% heavier on their bill — so it is a per-courier setting
     * rather than a constant.
     */
    public static function volumetricGrams(int $lengthMm, int $widthMm, int $heightMm, int $divisor): int
    {
        if ($divisor <= 0) {
            return 0;
        }

        $cubicCm = ($lengthMm / 10) * ($widthMm / 10) * ($heightMm / 10);

        return (int) ceil(($cubicCm / $divisor) * 1000);
    }

    /** Longest side first, which is what most courier size limits are stated against. */
    public function sortedDimensionsMm(): array
    {
        $dimensions = [$this->lengthMm, $this->widthMm, $this->heightMm];
        rsort($dimensions);

        return $dimensions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actual_weight_grams' => $this->actualWeightGrams,
            'volumetric_weight_grams' => $this->volumetricWeightGrams,
            'chargeable_weight_grams' => $this->chargeableWeightGrams(),
            'length_mm' => $this->lengthMm,
            'width_mm' => $this->widthMm,
            'height_mm' => $this->heightMm,
            'declared_value' => $this->declaredValue->toDecimal(),
            'destination_pincode' => $this->destinationPincode,
            'zone_code' => $this->zoneCode,
            'is_fragile' => $this->isFragile,
            'used_default_dimensions' => $this->usedDefaultDimensions,
        ];
    }
}
