<?php

declare(strict_types=1);

namespace App\Services\Staff;

/**
 * Decides which executive should take an order.
 *
 * Pure, so the fairness rules can be tested without a warehouse.
 *
 * Capacity is a hard gate, not a scoring input. Handing a 26th order to
 * someone already holding 25 does not get it packed sooner; it gets it buried.
 * If everyone is full the planner says so and the order stays in the unassigned
 * queue where a supervisor can see it, which is the honest outcome — silently
 * overloading one person hides the fact that the team is short-staffed.
 */
final class AssignmentPlanner
{
    public const STRATEGY_LEAST_LOADED = 'least_loaded';
    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    /**
     * @param array<int, array<string, mixed>> $candidates Rows from vw_executive_workload
     *
     * @return array{
     *     selected: ?array<string, mixed>,
     *     reason: string,
     *     eligible: int,
     *     skipped: array<int, string>
     * }
     */
    public function plan(array $candidates, string $strategy = self::STRATEGY_LEAST_LOADED): array
    {
        $eligible = [];
        $skipped = [];

        foreach ($candidates as $candidate) {
            $name = (string) $candidate['full_name'];

            if ((int) $candidate['is_available'] !== 1) {
                $skipped[] = $name . ' is marked unavailable.';

                continue;
            }

            if ((int) $candidate['remaining_capacity'] <= 0) {
                $skipped[] = sprintf(
                    '%s is at capacity (%d of %d).',
                    $name,
                    (int) $candidate['open_assignments'],
                    (int) $candidate['max_concurrent_orders']
                );

                continue;
            }

            $eligible[] = $candidate;
        }

        if ($eligible === []) {
            return [
                'selected' => null,
                'reason' => $candidates === []
                    ? 'No executives are configured.'
                    : 'Every executive is unavailable or at capacity. '
                      . implode(' ', array_slice($skipped, 0, 5)),
                'eligible' => 0,
                'skipped' => $skipped,
            ];
        }

        usort($eligible, function (array $a, array $b) use ($strategy): int {
            if ($strategy === self::STRATEGY_ROUND_ROBIN) {
                // Fewest completed today, so work is spread evenly over a shift
                // rather than by who happens to be emptiest right now.
                $comparison = (int) $a['completed_today'] <=> (int) $b['completed_today'];

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            // Most spare capacity first. Comparing remaining capacity rather
            // than raw open count means someone rated for 40 orders is not
            // treated as busier than someone rated for 10 holding the same load.
            $comparison = (int) $b['remaining_capacity'] <=> (int) $a['remaining_capacity'];

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = (int) $a['open_assignments'] <=> (int) $b['open_assignments'];

            if ($comparison !== 0) {
                return $comparison;
            }

            // Deterministic last resort, so the same inputs always give the same
            // person and the assignment log means something.
            return strcmp((string) $a['employee_code'], (string) $b['employee_code']);
        });

        $selected = $eligible[0];

        return [
            'selected' => $selected,
            'reason' => sprintf(
                '%s (%s) has %d of %d slots free%s.',
                $selected['full_name'],
                $selected['employee_code'],
                (int) $selected['remaining_capacity'],
                (int) $selected['max_concurrent_orders'],
                $strategy === self::STRATEGY_ROUND_ROBIN
                    ? sprintf(' and has completed %d today', (int) $selected['completed_today'])
                    : ''
            ),
            'eligible' => count($eligible),
            'skipped' => $skipped,
        ];
    }
}
