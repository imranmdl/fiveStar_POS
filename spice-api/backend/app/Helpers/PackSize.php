<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * How a pack's size reads to a shopper: "250 g", "1.5 kg", "500 ml", "1 L",
 * "12 pcs". One place, so the shop, cart, bills and admin screens agree.
 */
final class PackSize
{
    public const UNIT_TYPES = ['weight', 'quantity', 'volume'];

    public static function volume(float $ml): string
    {
        if ($ml >= 1000) {
            return self::trim($ml / 1000) . ' L';
        }

        return self::trim($ml) . ' ml';
    }

    public static function weight(float $grams): string
    {
        if ($grams >= 1000) {
            return self::trim($grams / 1000) . ' kg';
        }

        return self::trim($grams) . ' g';
    }

    /**
     * The size label for a variant row (needs stock_unit_type, volume_ml,
     * weight_grams). Null when the size is not a measure — sized clothing,
     * a "Free size", a plain count — so callers fall back to the pack name.
     *
     * @param array<string, mixed> $variant
     */
    public static function label(array $variant): ?string
    {
        $type = (string) ($variant['stock_unit_type'] ?? 'weight');

        if ($type === 'volume') {
            $ml = (float) ($variant['volume_ml'] ?? 0);

            return $ml > 0 ? self::volume($ml) : null;
        }

        if ($type === 'weight') {
            $grams = (float) ($variant['weight_grams'] ?? 0);

            // 1 g is the placeholder weight on items that are not weighed.
            return $grams > 1 ? self::weight($grams) : null;
        }

        return null;
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
