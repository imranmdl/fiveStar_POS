<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Helpers\Money;

/**
 * Works out what a buy-X-get-Y offer is worth on a given basket.
 *
 * Pure: no database, no clock, no request. Everything it needs is passed in,
 * which is what makes the arithmetic testable on its own — and the arithmetic is
 * where money is given away by accident.
 *
 * THE BENEFIT IS RETURNED AS AN AMOUNT, NOT AS FREE LINES. The pricing engine
 * already knows how to apply and apportion a discount, and doing it that way
 * keeps the GST base correct without special handling. See migration 010 for the
 * full reasoning.
 */
final class BogoCalculator
{
    /**
     * @param array<int, array{reference:string, quantity:int, unit_price:Money}> $lines
     *        Eligible lines only — the caller has already filtered by category
     *        or product according to the offer's targeting.
     *
     * @return array{
     *     free_units:int,
     *     amount:Money,
     *     sets:int,
     *     note:string,
     *     breakdown:array<int, array{reference:string, free_units:int, unit_price:string}>
     * }
     */
    public function calculate(
        array $lines,
        int $buyQuantity,
        int $getQuantity,
        string $scope = 'cheapest_eligible',
        ?int $maxFreeItems = null,
    ): array {
        if ($buyQuantity < 1 || $getQuantity < 1 || $lines === []) {
            return $this->nothing('This offer does not apply to anything in the basket.');
        }

        return $scope === 'same_variant'
            ? $this->perVariant($lines, $buyQuantity, $getQuantity, $maxFreeItems)
            : $this->acrossBasket($lines, $buyQuantity, $getQuantity, $maxFreeItems);
    }

    /**
     * Each pack size earns its own free units.
     *
     * "Buy 1 get 1 on 250g turmeric" means two 250g pouches, not one pouch and
     * something else. Six of one pack and one of another earns three free from
     * the first and none from the second — the second never reaches the
     * threshold on its own.
     */
    private function perVariant(array $lines, int $buy, int $get, ?int $max): array
    {
        $freeUnits = 0;
        $sets = 0;
        $amount = Money::zero();
        $breakdown = [];

        foreach ($lines as $line) {
            $lineSets = intdiv((int) $line['quantity'], $buy + $get);

            if ($lineSets < 1) {
                continue;
            }

            $lineFree = $lineSets * $get;

            $sets += $lineSets;
            $freeUnits += $lineFree;
            $amount = $amount->add($line['unit_price']->multiply($lineFree));

            $breakdown[] = [
                'reference' => (string) $line['reference'],
                'free_units' => $lineFree,
                'unit_price' => $line['unit_price']->toDecimal(),
            ];
        }

        if ($freeUnits === 0) {
            return $this->nothing(sprintf(
                'Add more to qualify — this offer gives %d free for every %d of the same pack bought.',
                $get,
                $buy
            ));
        }

        return $this->capped($freeUnits, $sets, $amount, $breakdown, $buy, $get, $max, $lines, true);
    }

    /**
     * The whole eligible basket counts towards the threshold, and the CHEAPEST
     * units are the ones given away.
     *
     * Giving away the most expensive item on a mixed basket loses money the shop
     * did not intend to lose. Every large retailer discounts the cheapest for
     * this reason, and it is what a customer expects once they think about it —
     * so it is also the honest default rather than a trick.
     */
    private function acrossBasket(array $lines, int $buy, int $get, ?int $max): array
    {
        $totalQuantity = 0;

        foreach ($lines as $line) {
            $totalQuantity += (int) $line['quantity'];
        }

        $sets = intdiv($totalQuantity, $buy + $get);

        if ($sets < 1) {
            return $this->nothing(sprintf(
                'Add %d more to qualify — this offer gives %d free for every %d bought.',
                ($buy + $get) - $totalQuantity,
                $get,
                $buy
            ));
        }

        $freeUnits = $sets * $get;

        // Cheapest first, so the units given away are the least valuable.
        $ordered = $lines;
        usort($ordered, static fn (array $a, array $b): int
            => $a['unit_price']->paise() <=> $b['unit_price']->paise());

        $remaining = $freeUnits;
        $amount = Money::zero();
        $breakdown = [];

        foreach ($ordered as $line) {
            if ($remaining < 1) {
                break;
            }

            $take = min($remaining, (int) $line['quantity']);
            $amount = $amount->add($line['unit_price']->multiply($take));
            $remaining -= $take;

            $breakdown[] = [
                'reference' => (string) $line['reference'],
                'free_units' => $take,
                'unit_price' => $line['unit_price']->toDecimal(),
            ];
        }

        return $this->capped($freeUnits, $sets, $amount, $breakdown, $buy, $get, $max, $ordered, false);
    }

    /**
     * Applies the per-order ceiling.
     *
     * Without one, "buy 1 get 1" on a fifty-unit wholesale order gives away
     * twenty-five units. The cap is disclosed in the note rather than silently
     * reducing the benefit, because a customer who counted on four free items
     * and got two deserves to know why.
     */
    private function capped(
        int $freeUnits,
        int $sets,
        Money $amount,
        array $breakdown,
        int $buy,
        int $get,
        ?int $max,
        array $orderedLines,
        bool $perVariant,
    ): array {
        $note = sprintf('Buy %d get %d free: %d item(s) free.', $buy, $get, $freeUnits);

        if ($max === null || $freeUnits <= $max) {
            return [
                'free_units' => $freeUnits,
                'amount' => $amount,
                'sets' => $sets,
                'note' => $note,
                'breakdown' => $breakdown,
            ];
        }

        // Recompute against the cap, still taking the cheapest units first.
        $remaining = $max;
        $cappedAmount = Money::zero();
        $cappedBreakdown = [];

        $source = $perVariant ? $breakdown : $this->breakdownFrom($orderedLines, $max);

        foreach ($source as $entry) {
            if ($remaining < 1) {
                break;
            }

            $take = min($remaining, (int) $entry['free_units']);
            $cappedAmount = $cappedAmount->add(
                Money::fromDecimal((string) $entry['unit_price'])->multiply($take)
            );
            $remaining -= $take;

            $cappedBreakdown[] = [
                'reference' => $entry['reference'],
                'free_units' => $take,
                'unit_price' => $entry['unit_price'],
            ];
        }

        return [
            'free_units' => $max,
            'amount' => $cappedAmount,
            'sets' => $sets,
            'note' => sprintf(
                'Buy %d get %d free: %d item(s) free (this offer is limited to %d per order).',
                $buy,
                $get,
                $max,
                $max
            ),
            'breakdown' => $cappedBreakdown,
        ];
    }

    /** @return array<int, array{reference:string, free_units:int, unit_price:string}> */
    private function breakdownFrom(array $orderedLines, int $units): array
    {
        $remaining = $units;
        $result = [];

        foreach ($orderedLines as $line) {
            if ($remaining < 1) {
                break;
            }

            $take = min($remaining, (int) $line['quantity']);
            $remaining -= $take;

            $result[] = [
                'reference' => (string) $line['reference'],
                'free_units' => $take,
                'unit_price' => $line['unit_price']->toDecimal(),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function nothing(string $note): array
    {
        return [
            'free_units' => 0,
            'amount' => Money::zero(),
            'sets' => 0,
            'note' => $note,
            'breakdown' => [],
        ];
    }
}
