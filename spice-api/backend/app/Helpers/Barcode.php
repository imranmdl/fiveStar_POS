<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Generates internal EAN-13 barcodes for pack sizes that don't have a real
 * manufacturer barcode yet. Uses the "20"-"29" prefix range GS1 reserves for
 * restricted, in-store circulation — never allocated to genuine products —
 * so a generated code can never collide with a real one, only (rarely) with
 * another generated one, which the caller checks for.
 */
final class Barcode
{
    public static function generateEan13(): string
    {
        $body = '2' . str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);

        return $body . self::checkDigit($body);
    }

    private static function checkDigit(string $twelveDigits): string
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
