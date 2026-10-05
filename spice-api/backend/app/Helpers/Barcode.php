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
        return self::gtinCheckDigit($twelveDigits);
    }

    /**
     * GS1 check digit for any GTIN body (EAN-8, UPC-A, EAN-13, GTIN-14 minus
     * its last digit): weights 3,1,3,1… from the right.
     */
    public static function gtinCheckDigit(string $body): string
    {
        $sum = 0;
        $digits = array_reverse(str_split($body));

        foreach ($digits as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private static function hasValidCheckDigit(string $code): bool
    {
        return strlen($code) > 1 && self::gtinCheckDigit(substr($code, 0, -1)) === substr($code, -1);
    }

    /**
     * Every form the same printed barcode can reach us in, most likely first.
     * One label scans differently depending on the device:
     *  - spaces, line breaks or control characters a scanner gun adds;
     *  - an AIM symbology prefix some guns send (e.g. "]E0" before EAN-13);
     *  - UPC-A (12 digits) vs EAN-13 (the same code with a leading 0) — ML Kit,
     *    browsers and guns don't agree which they report;
     *  - a GTIN-14 with a leading 0;
     *  - the check digit left off (a common scanner setting).
     * Matching against all of these makes an item created from one device
     * scan on any other.
     *
     * @return array<int, string>
     */
    public static function lookupCandidates(string $raw): array
    {
        $code = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw));
        $code = (string) preg_replace('/^\][A-Za-z][0-9A-Za-z]/', '', $code);
        $candidates = [$code];

        $digits = (string) preg_replace('/\s+/', '', $code);
        if ($digits !== $code) {
            $candidates[] = $digits;
        }

        if (preg_match('/^\d+$/', $digits) === 1) {
            $length = strlen($digits);

            if ($length === 14 && $digits[0] === '0') {
                $candidates[] = substr($digits, 1);              // GTIN-14 -> EAN-13
                if ($digits[1] === '0') {
                    $candidates[] = substr($digits, 2);          // -> UPC-A
                }
            }
            if ($length === 13 && $digits[0] === '0') {
                $candidates[] = substr($digits, 1);              // EAN-13 -> UPC-A
            }
            if ($length === 12) {
                $candidates[] = '0' . $digits;                   // UPC-A -> EAN-13
                if (!self::hasValidCheckDigit($digits)) {
                    $candidates[] = $digits . self::gtinCheckDigit($digits); // EAN-13 without its check digit
                }
            }
            if ($length === 11) {
                $upc = $digits . self::gtinCheckDigit($digits);  // UPC-A without its check digit
                $candidates[] = $upc;
                $candidates[] = '0' . $upc;
            }
            if ($length === 7) {
                $candidates[] = $digits . self::gtinCheckDigit($digits); // EAN-8 without its check digit
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $c): bool => $c !== '')));
    }
}
