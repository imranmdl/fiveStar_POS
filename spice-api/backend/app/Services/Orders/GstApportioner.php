<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Helpers\Money;

/**
 * Splits GST into CGST + SGST or IGST, depending on where the order is going.
 *
 * Indian GST is destination-based. A sale within the seller's own state is
 * split evenly into Central and State GST; a sale to another state is a single
 * Integrated GST at the full rate. Getting this wrong does not change what the
 * customer pays, but it does misstate the return, which is the kind of error
 * that surfaces at audit rather than at checkout.
 *
 * Pure, so it can be tested against the awkward cases: odd rates and amounts
 * where half of the tax is not a whole number of paise.
 */
final class GstApportioner
{
    /**
     * @return array{cgst:Money, sgst:Money, igst:Money, is_interstate:bool}
     */
    public static function split(Money $taxAmount, string $sellerState, string $buyerState): array
    {
        $interstate = self::normalise($sellerState) !== self::normalise($buyerState);

        if ($interstate) {
            return [
                'cgst' => Money::zero(),
                'sgst' => Money::zero(),
                'igst' => $taxAmount,
                'is_interstate' => true,
            ];
        }

        // Allocate rather than halve: an odd number of paise must still add back
        // up to the total, and allocate() gives the extra paisa to CGST
        // deterministically instead of losing it to rounding.
        $halves = $taxAmount->allocate([1, 1]);

        return [
            'cgst' => $halves[0],
            'sgst' => $halves[1],
            'igst' => Money::zero(),
            'is_interstate' => false,
        ];
    }

    private static function normalise(string $state): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $state) ?? $state));
    }
}
