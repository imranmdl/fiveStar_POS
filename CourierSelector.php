<?php

declare(strict_types=1);

namespace App\Services\Delivery;

/**
 * BR-007: chooses the courier.
 *
 * The rule names six inputs — weight, order value, destination pincode,
 * availability, cost and SLA — without saying how to weigh them against each
 * other. That decision is made here, and made explicitly:
 *
 *   ELIGIBILITY IS ABSOLUTE, SCORING IS RELATIVE. Weight limits, value ceilings,
 *   size limits, fragility and serviceability are hard gates: a courier that
 *   fails one is never chosen, however cheap. Cost, speed and reliability are
 *   then traded off against each other among whoever is left. Mixing the two —
 *   scoring a weight overrun as "expensive" — eventually books a parcel a
 *   courier will refuse at the door.
 *
 *   COST AND SPEED ARE NORMALISED, NOT COMPARED RAW. A quote of Rs 40 against
 *   Rs 80 and 2 days against 5 cannot be added together; the units are
 *   meaningless side by side. Each is mapped to 0-1 across the range actually
 *   quoted, so "cheapest available" and "fastest available" both score 1.
 *
 * Four strategies ship, because the right trade-off is a business decision that
 * changes with the season rather than a fact about the code. Festival weeks want
 * `fastest`; ordinary weeks want `balanced`.
 *
 * Pure: no database, no clock, no HTTP. Every rule below is exhaustively
 * testable, which matters because the failure mode is not a crash — it is
 * quietly picking a worse courier for months.
 */
final class CourierSelector
{
    public const STRATEGY_CHEAPEST = 'cheapest';
    public const STRATEGY_FASTEST = 'fastest';
    public const STRATEGY_BALANCED = 'balanced';
    public const STRATEGY_RELIABLE = 'reliable';

    /**
     * Weightings per strategy: [cost, speed, reliability]. Each set sums to 1.
     *
     * `balanced` leans towards cost because delivery charges are quoted to the
     * customer before the courier is chosen — every rupee saved here is margin,
     * while a day of speed is invisible to a customer who was promised a range.
     *
     * @var array<string, array{cost:float, speed:float, reliability:float}>
     */
    public const STRATEGIES = [
        self::STRATEGY_CHEAPEST => ['cost' => 1.00, 'speed' => 0.00, 'reliability' => 0.00],
        self::STRATEGY_FASTEST => ['cost' => 0.00, 'speed' => 1.00, 'reliability' => 0.00],
        self::STRATEGY_BALANCED => ['cost' => 0.50, 'speed' => 0.30, 'reliability' => 0.20],
        self::STRATEGY_RELIABLE => ['cost' => 0.20, 'speed' => 0.20, 'reliability' => 0.60],
    ];

    /**
     * Picks a courier from a set of quotes.
     *
     * @param array<int, CourierQuote> $quotes
     *
     * @return array{
     *     selected: ?CourierQuote,
     *     score: ?float,
     *     reason: string,
     *     strategy: string,
     *     considered: int,
     *     eligible: int,
     *     candidates: array<int, array<string, mixed>>
     * }
     */
    public function select(array $quotes, string $strategy = self::STRATEGY_BALANCED): array
    {
        $weights = self::STRATEGIES[$strategy] ?? self::STRATEGIES[self::STRATEGY_BALANCED];
        $strategy = isset(self::STRATEGIES[$strategy]) ? $strategy : self::STRATEGY_BALANCED;

        $eligible = array_values(array_filter($quotes, static fn (CourierQuote $q): bool => $q->isEligible));

        if ($eligible === []) {
            return [
                'selected' => null,
                'score' => null,
                'reason' => $quotes === []
                    ? 'No couriers are configured for this destination.'
                    : 'No courier can carry this parcel to this destination. '
                      . 'Reasons: ' . $this->summariseFailures($quotes),
                'strategy' => $strategy,
                'considered' => count($quotes),
                'eligible' => 0,
                'candidates' => array_map(
                    static fn (CourierQuote $q): array => $q->toArray() + ['score' => null],
                    $quotes
                ),
            ];
        }

        $costs = array_map(static fn (CourierQuote $q): int => $q->cost->paise(), $eligible);
        $speeds = array_map(static fn (CourierQuote $q): int => $q->slaMaxDays, $eligible);

        $minCost = min($costs);
        $maxCost = max($costs);
        $minSpeed = min($speeds);
        $maxSpeed = max($speeds);

        $scored = [];

        foreach ($eligible as $quote) {
            // Normalise so cheapest and fastest both score 1. When every quote
            // is identical the range is zero, so everyone scores 1 and the tie
            // is broken further down rather than dividing by zero.
            $costScore = $maxCost === $minCost
                ? 1.0
                : 1.0 - (($quote->cost->paise() - $minCost) / ($maxCost - $minCost));

            $speedScore = $maxSpeed === $minSpeed
                ? 1.0
                : 1.0 - (($quote->slaMaxDays - $minSpeed) / ($maxSpeed - $minSpeed));

            $reliabilityScore = max(0.0, min(1.0, $quote->reliabilityScore / 100));

            $total = ($costScore * $weights['cost'])
                + ($speedScore * $weights['speed'])
                + ($reliabilityScore * $weights['reliability']);

            $scored[] = [
                'quote' => $quote,
                'score' => round($total, 4),
                'cost_score' => round($costScore, 4),
                'speed_score' => round($speedScore, 4),
                'reliability_score' => round($reliabilityScore, 4),
            ];
        }

        // Sort by score, then by the tiebreakers in order of how much they
        // matter commercially. Deterministic throughout: the same inputs must
        // always give the same courier, or the audit trail proves nothing.
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            $costComparison = $a['quote']->cost->paise() <=> $b['quote']->cost->paise();

            if ($costComparison !== 0) {
                return $costComparison;
            }

            $slaComparison = $a['quote']->slaMaxDays <=> $b['quote']->slaMaxDays;

            if ($slaComparison !== 0) {
                return $slaComparison;
            }

            $priorityComparison = $a['quote']->priority <=> $b['quote']->priority;

            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }

            // Last resort, so the order never depends on how the database
            // happened to return rows.
            return strcmp($a['quote']->courierCode, $b['quote']->courierCode);
        });

        $winner = $scored[0];
        $runnerUp = $scored[1] ?? null;

        $candidates = [];

        foreach ($scored as $entry) {
            $candidates[] = $entry['quote']->toArray() + [
                'score' => $entry['score'],
                'cost_score' => $entry['cost_score'],
                'speed_score' => $entry['speed_score'],
                'reliability_component' => $entry['reliability_score'],
            ];
        }

        foreach ($quotes as $quote) {
            if (!$quote->isEligible) {
                $candidates[] = $quote->toArray() + ['score' => null];
            }
        }

        return [
            'selected' => $winner['quote'],
            'score' => $winner['score'],
            'reason' => $this->explain($winner, $runnerUp, $strategy, count($eligible)),
            'strategy' => $strategy,
            'considered' => count($quotes),
            'eligible' => count($eligible),
            'candidates' => $candidates,
        ];
    }

    /**
     * A sentence a human can act on.
     *
     * "Chosen by the algorithm" is not an explanation. When a customer complains
     * about a slow delivery, the person answering needs to know whether the
     * courier was chosen because it was cheapest by a wide margin or because
     * nothing else served that pincode.
     *
     * @param array<string, mixed>  $winner
     * @param array<string, mixed>|null $runnerUp
     */
    private function explain(array $winner, ?array $runnerUp, string $strategy, int $eligibleCount): string
    {
        /** @var CourierQuote $quote */
        $quote = $winner['quote'];

        if ($eligibleCount === 1) {
            return sprintf(
                '%s was the only courier able to carry this parcel to this destination (%s, %d-%d days).',
                $quote->courierName,
                $quote->cost->format(),
                $quote->slaMinDays,
                $quote->slaMaxDays
            );
        }

        $basis = match ($strategy) {
            self::STRATEGY_CHEAPEST => 'lowest cost',
            self::STRATEGY_FASTEST => 'fastest delivery',
            self::STRATEGY_RELIABLE => 'best delivery record',
            default => 'best balance of cost, speed and reliability',
        };

        $sentence = sprintf(
            '%s chosen on %s: %s, %d-%d days, %.0f%% reliability. Scored %.3f against %d eligible couriers.',
            $quote->courierName,
            $basis,
            $quote->cost->format(),
            $quote->slaMinDays,
            $quote->slaMaxDays,
            $quote->reliabilityScore,
            $winner['score'],
            $eligibleCount
        );

        if ($runnerUp !== null) {
            /** @var CourierQuote $second */
            $second = $runnerUp['quote'];
            $costGap = $second->cost->subtract($quote->cost);

            $sentence .= sprintf(
                ' Next best was %s at %s (%.3f)%s.',
                $second->courierName,
                $second->cost->format(),
                $runnerUp['score'],
                $costGap->isPositive() ? ', ' . $costGap->format() . ' dearer' : ''
            );
        }

        return $sentence;
    }

    /** @param array<int, CourierQuote> $quotes */
    private function summariseFailures(array $quotes): string
    {
        $parts = [];

        foreach ($quotes as $quote) {
            if ($quote->isEligible || $quote->ineligibilityReasons === []) {
                continue;
            }

            $parts[] = $quote->courierName . ' - ' . implode('; ', $quote->ineligibilityReasons);
        }

        return $parts === [] ? 'none given' : implode('. ', array_slice($parts, 0, 5));
    }

    /**
     * Eligibility gates, evaluated against a courier's declared limits.
     *
     * Returns every reason a courier fails rather than the first, so a
     * misconfigured courier shows all its problems at once instead of one per
     * fix-and-retry cycle.
     *
     * @param array<string, mixed> $courier
     *
     * @return array<int, string>
     */
    public function ineligibilityReasons(array $courier, ParcelSpec $parcel): array
    {
        $reasons = [];
        $chargeable = $parcel->chargeableWeightGrams();

        if ((int) $courier['is_enabled'] !== 1) {
            $reasons[] = 'currently disabled'
                . ($courier['disabled_reason'] !== null ? ' (' . $courier['disabled_reason'] . ')' : '');
        }

        if ($chargeable < (int) $courier['min_weight_grams']) {
            $reasons[] = sprintf(
                'parcel is %dg, below their %dg minimum',
                $chargeable,
                (int) $courier['min_weight_grams']
            );
        }

        if ($chargeable > (int) $courier['max_weight_grams']) {
            $reasons[] = sprintf(
                'parcel is %dg, above their %dg ceiling',
                $chargeable,
                (int) $courier['max_weight_grams']
            );
        }

        if ($courier['max_order_value'] !== null
            && $parcel->declaredValue->toDecimal() > (float) $courier['max_order_value']) {
            $reasons[] = sprintf(
                'declared value %s exceeds their insurance limit of Rs %s',
                $parcel->declaredValue->format(),
                number_format((float) $courier['max_order_value'], 2)
            );
        }

        if ($parcel->isFragile && (int) $courier['handles_fragile'] !== 1) {
            $reasons[] = 'does not accept fragile consignments';
        }

        [$longest, $middle, $shortest] = $parcel->sortedDimensionsMm();

        // Compare longest against longest so a parcel is not rejected merely for
        // being handed over on its side.
        $limits = array_filter([
            $courier['max_length_mm'] === null ? null : (int) $courier['max_length_mm'],
            $courier['max_width_mm'] === null ? null : (int) $courier['max_width_mm'],
            $courier['max_height_mm'] === null ? null : (int) $courier['max_height_mm'],
        ], static fn (?int $value): bool => $value !== null);

        if (count($limits) === 3) {
            rsort($limits);

            if ($longest > $limits[0] || $middle > $limits[1] || $shortest > $limits[2]) {
                $reasons[] = sprintf(
                    'parcel %dx%dx%dmm exceeds their size limit',
                    $longest,
                    $middle,
                    $shortest
                );
            }
        }

        return $reasons;
    }
}
