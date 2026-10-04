<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Immutable money value, stored as an integer number of paise.
 *
 * Floats are never used for arithmetic. 0.1 + 0.2 is not 0.3 in binary floating
 * point, and a cart that sums thirty float lines will eventually be a paisa off
 * the invoice. Integers make that impossible.
 *
 * Two operations here carry most of the weight:
 *
 *  - extractInclusiveTax(): Indian MRP is GST-inclusive by law, so tax is
 *    *extracted* from the price rather than added on top. Adding GST to an MRP
 *    overcharges the customer and misstates the liability.
 *
 *  - allocate(): distributes an order-level amount (a coupon, a rounding
 *    difference, a shipping charge) across lines by ratio, using the largest
 *    remainder method so the parts always sum back to exactly the whole. Naive
 *    per-line rounding leaks paise and makes totals fail to reconcile.
 */
final class Money implements \JsonSerializable
{
    private function __construct(private readonly int $paise)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromPaise(int $paise): self
    {
        return new self($paise);
    }

    /**
     * Accepts the DECIMAL strings that come back from MySQL, as well as ints
     * and floats. Rounds half up, which is what Indian invoicing expects.
     */
    public static function fromDecimal(int|float|string $amount): self
    {
        if (is_int($amount)) {
            return new self($amount * 100);
        }

        if (is_string($amount)) {
            $amount = trim($amount);

            if ($amount === '') {
                return new self(0);
            }

            if (!is_numeric($amount)) {
                throw new \InvalidArgumentException("Not a numeric amount: {$amount}");
            }

            $amount = (float) $amount;
        }

        if (!is_finite($amount)) {
            throw new \InvalidArgumentException('Amount must be a finite number.');
        }

        return new self((int) round($amount * 100, 0, PHP_ROUND_HALF_UP));
    }

    public function paise(): int
    {
        return $this->paise;
    }

    public function toDecimal(): float
    {
        return round($this->paise / 100, 2);
    }

    public function format(string $symbol = '₹'): string
    {
        $sign = $this->paise < 0 ? '-' : '';
        $absolute = abs($this->paise);

        return sprintf('%s%s%s.%02d', $sign, $symbol, number_format(intdiv($absolute, 100), 0, '.', ','), $absolute % 100);
    }

    public function add(self ...$others): self
    {
        $total = $this->paise;

        foreach ($others as $other) {
            $total += $other->paise;
        }

        return new self($total);
    }

    public function subtract(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    public function multiply(int $factor): self
    {
        return new self($this->paise * $factor);
    }

    /** Percentage of this amount, rounded half up. */
    public function percentage(float $percent): self
    {
        return new self((int) round($this->paise * $percent / 100, 0, PHP_ROUND_HALF_UP));
    }

    /**
     * Splits a GST-inclusive amount into its net (taxable) and tax components.
     *
     *   net = inclusive / (1 + rate/100),  tax = inclusive - net
     *
     * Deriving tax by subtraction rather than a second rounding guarantees
     * net + tax == inclusive, exactly, every time.
     *
     * @return array{net:self, tax:self}
     */
    public function extractInclusiveTax(float $ratePercent): array
    {
        if ($ratePercent <= 0.0) {
            return ['net' => new self($this->paise), 'tax' => new self(0)];
        }

        $net = (int) round($this->paise / (1 + $ratePercent / 100), 0, PHP_ROUND_HALF_UP);

        return ['net' => new self($net), 'tax' => new self($this->paise - $net)];
    }

    /**
     * Distributes this amount across the given ratios so that the results sum
     * to exactly this amount.
     *
     * Largest remainder method: floor every share, then hand the leftover paise
     * one at a time to the shares with the biggest discarded fraction. With
     * ₹10.00 across three equal lines this yields 3.34 / 3.33 / 3.33 rather
     * than 3.33 / 3.33 / 3.33 and a missing paisa.
     *
     * @param array<int|string, int|float> $ratios
     *
     * @return array<int|string, self> Same keys as $ratios
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            return [];
        }

        $totalRatio = array_sum($ratios);

        // Nothing to weight by: split as evenly as possible.
        if ($totalRatio <= 0) {
            $count = count($ratios);
            $base = intdiv($this->paise, $count);
            $remainder = $this->paise - ($base * $count);
            $result = [];

            foreach (array_keys($ratios) as $index => $key) {
                $result[$key] = new self($base + ($index < $remainder ? 1 : 0));
            }

            return $result;
        }

        $shares = [];
        $fractions = [];
        $assigned = 0;

        foreach ($ratios as $key => $ratio) {
            $exact = $this->paise * $ratio / $totalRatio;
            $floor = (int) floor($exact);

            $shares[$key] = $floor;
            $fractions[$key] = $exact - $floor;
            $assigned += $floor;
        }

        $leftover = $this->paise - $assigned;

        // Hand out remaining paise to the largest discarded fractions first.
        arsort($fractions);

        foreach (array_keys($fractions) as $key) {
            if ($leftover <= 0) {
                break;
            }

            ++$shares[$key];
            --$leftover;
        }

        return array_map(static fn (int $paise): self => new self($paise), $shares);
    }

    /** Never returns a negative amount; used to floor discounts at the line total. */
    public function clampAtZero(): self
    {
        return $this->paise < 0 ? new self(0) : new self($this->paise);
    }

    public function min(self $other): self
    {
        return $this->paise <= $other->paise ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    public function equals(self $other): bool
    {
        return $this->paise === $other->paise;
    }

    public function greaterThan(self $other): bool
    {
        return $this->paise > $other->paise;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->paise >= $other->paise;
    }

    public function lessThan(self $other): bool
    {
        return $this->paise < $other->paise;
    }

    /** Serialises as a plain decimal number, so clients never parse paise. */
    public function jsonSerialize(): float
    {
        return $this->toDecimal();
    }

    public function __toString(): string
    {
        return number_format($this->toDecimal(), 2, '.', '');
    }
}
