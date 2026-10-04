<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Helpers\Money;

/**
 * Works out what a commission rule pays on one order.
 *
 * Pure: given a rule, an order value and a volume count, it returns an amount
 * and a sentence explaining how that amount was reached. No database, no clock.
 *
 * The explanation is not decoration. Staff query their commission, and "the
 * system calculated it" is not an answer anyone accepts about their own pay.
 * Every entry in the ledger carries the sentence that produced it, so a
 * disagreement is about the rule rather than about whether the software works.
 */
final class CommissionCalculator
{
    public const FLAT = 'flat_per_order';
    public const PERCENTAGE = 'percentage_of_order';
    public const TIERED = 'tiered_by_volume';

    /**
     * @param array<string, mixed> $rule
     * @param int $completedVolume Orders already completed in the period, for tiering
     *
     * @return array{amount:Money, note:string, applies:bool, reason:?string}
     */
    public function calculate(array $rule, Money $orderValue, int $completedVolume = 0): array
    {
        // A minimum order value is a gate, not a scaling factor: below it the
        // rule simply does not apply and a different one may.
        if ($rule['min_order_value'] !== null
            && $orderValue->toDecimal() < (float) $rule['min_order_value']) {
            return [
                'amount' => Money::zero(),
                'note' => '',
                'applies' => false,
                'reason' => sprintf(
                    'Order value %s is below the %s minimum for this rule.',
                    $orderValue->format(),
                    Money::fromDecimal((string) $rule['min_order_value'])->format()
                ),
            ];
        }

        [$amount, $note] = match ((string) $rule['calculation']) {
            self::FLAT => $this->flat($rule),
            self::PERCENTAGE => $this->percentage($rule, $orderValue),
            self::TIERED => $this->tiered($rule, $completedVolume),
            default => [Money::zero(), 'Unknown calculation type; nothing accrued.'],
        };

        // The cap is applied last and disclosed, because a capped figure that
        // silently differs from the stated percentage is the single most common
        // cause of a commission dispute.
        if ($rule['max_commission'] !== null) {
            $cap = Money::fromDecimal((string) $rule['max_commission']);

            if ($amount->paise() > $cap->paise()) {
                $note = sprintf('%s Capped at %s.', $note, $cap->format());
                $amount = $cap;
            }
        }

        return [
            'amount' => $amount,
            'note' => $note,
            'applies' => true,
            'reason' => null,
        ];
    }

    /**
     * Picks the rule that should apply.
     *
     * Lowest `priority` wins, so a specific rule beats a general one — the
     * high-value incentive is meant to replace the flat fee on large orders,
     * not stack with it. Rules that do not apply are skipped rather than
     * scoring zero, so a matching rule further down the list still gets its
     * chance.
     *
     * @param array<int, array<string, mixed>> $rules Ordered by priority ascending
     *
     * @return array{rule:?array<string, mixed>, amount:Money, note:string, skipped:array<int, string>}
     */
    public function resolve(array $rules, Money $orderValue, int $completedVolume = 0): array
    {
        $skipped = [];

        foreach ($rules as $rule) {
            $outcome = $this->calculate($rule, $orderValue, $completedVolume);

            if (!$outcome['applies']) {
                $skipped[] = $rule['code'] . ': ' . $outcome['reason'];

                continue;
            }

            if (!$outcome['amount']->isPositive()) {
                $skipped[] = $rule['code'] . ': calculated to zero.';

                continue;
            }

            return [
                'rule' => $rule,
                'amount' => $outcome['amount'],
                'note' => $outcome['note'],
                'skipped' => $skipped,
            ];
        }

        return ['rule' => null, 'amount' => Money::zero(), 'note' => '', 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array{0:Money, 1:string}
     */
    private function flat(array $rule): array
    {
        $amount = Money::fromDecimal((string) ($rule['flat_amount'] ?? '0'));

        return [$amount, sprintf('Flat fee of %s per order.', $amount->format())];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array{0:Money, 1:string}
     */
    private function percentage(array $rule, Money $orderValue): array
    {
        $percent = (float) ($rule['percentage'] ?? 0);
        $amount = $orderValue->percentage($percent);

        return [
            $amount,
            sprintf('%s%% of %s = %s.', rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
                $orderValue->format(), $amount->format()),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array{0:Money, 1:string}
     */
    private function tiered(array $rule, int $completedVolume): array
    {
        $tiers = is_string($rule['tiers']) ? json_decode($rule['tiers'], true) : $rule['tiers'];

        if (!is_array($tiers) || $tiers === []) {
            return [Money::zero(), 'No tiers configured; nothing accrued.'];
        }

        // Sort ascending and take the highest tier the volume has reached, so
        // the order tiers are written in does not change what anyone is paid.
        usort($tiers, static fn (array $a, array $b): int => ($a['min_orders'] ?? 0) <=> ($b['min_orders'] ?? 0));

        $matched = null;

        foreach ($tiers as $tier) {
            if ($completedVolume >= (int) ($tier['min_orders'] ?? 0)) {
                $matched = $tier;
            }
        }

        if ($matched === null) {
            return [
                Money::zero(),
                sprintf('%d order(s) completed, below the lowest tier.', $completedVolume),
            ];
        }

        $amount = Money::fromDecimal((string) ($matched['amount'] ?? '0'));

        return [
            $amount,
            sprintf(
                '%d order(s) completed reaches the %d+ tier, paying %s.',
                $completedVolume,
                (int) ($matched['min_orders'] ?? 0),
                $amount->format()
            ),
        ];
    }
}
