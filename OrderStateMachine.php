<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Exceptions\HttpException;

/**
 * The one place an order's status is allowed to change.
 *
 * Two business rules are enforced here and nowhere else, which is the point:
 *
 *   BR-003  An order cannot reach `confirmed` until its OTP is verified.
 *   BR-005  An order cannot enter any fulfilment status without a settled
 *           payment. Not "the UI hides the button" — the transition is refused.
 *
 * Pure by design: it is handed the current state as plain values and returns a
 * verdict. No database, no clock, no request, so every rule can be tested
 * exhaustively in milliseconds. OrderService is responsible for persisting the
 * result and writing the timeline entry.
 *
 * If a future phase needs a new status, add it to OrderStatus::TRANSITIONS and
 * it becomes reachable everywhere. Leave it out and it is refused everywhere.
 * There is deliberately no bypass.
 */
final class OrderStateMachine
{
    /**
     * Decides whether an order may move to a new status.
     *
     * @param bool $isStaffOverride Staff may cancel a paid order that a customer
     *                              no longer could; they still cannot skip the
     *                              payment requirement.
     *
     * @return array{allowed:bool, reason:?string}
     */
    public function evaluate(
        string $currentStatus,
        string $targetStatus,
        string $paymentStatus,
        bool $otpVerified,
        bool $otpRequired = true,
        bool $isStaffOverride = false,
    ): array {
        if (!OrderStatus::exists($currentStatus)) {
            return ['allowed' => false, 'reason' => sprintf('Unknown current status "%s".', $currentStatus)];
        }

        if (!OrderStatus::exists($targetStatus)) {
            return ['allowed' => false, 'reason' => sprintf('Unknown target status "%s".', $targetStatus)];
        }

        if ($currentStatus === $targetStatus) {
            // Not an error. Retried webhooks and double-clicked buttons land
            // here constantly, and treating it as a failure would turn a
            // harmless duplicate into a support ticket.
            return ['allowed' => false, 'reason' => null];
        }

        if (OrderStatus::isTerminal($currentStatus)) {
            return [
                'allowed' => false,
                'reason' => sprintf('This order is %s and cannot change further.', OrderStatus::label($currentStatus)),
            ];
        }

        $permitted = OrderStatus::TRANSITIONS[$currentStatus];

        if (!in_array($targetStatus, $permitted, true)) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'An order that is "%s" cannot move to "%s". Allowed from here: %s.',
                    OrderStatus::label($currentStatus),
                    OrderStatus::label($targetStatus),
                    $permitted === []
                        ? 'nothing'
                        : implode(', ', array_map([OrderStatus::class, 'label'], $permitted))
                ),
            ];
        }

        // --- BR-003 -------------------------------------------------------
        if ($targetStatus === OrderStatus::CONFIRMED && $otpRequired && !$otpVerified) {
            return [
                'allowed' => false,
                'reason' => 'This order has not been verified by OTP yet, so it cannot be confirmed.',
            ];
        }

        // --- BR-005 -------------------------------------------------------
        // Deliberately not skippable by staff. An administrator marking an
        // unpaid order as shipped is exactly the loss this rule prevents, and a
        // convenience override would be used within a week of go-live.
        if (OrderStatus::requiresPayment($targetStatus) && !PaymentStatus::isSettled($paymentStatus)) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Payment for this order is "%s". No order progresses to "%s" until payment is confirmed.',
                    PaymentStatus::label($paymentStatus),
                    OrderStatus::label($targetStatus)
                ),
            ];
        }

        // --- Fulfilment is staff work --------------------------------------
        // The routes are role-guarded too, but keeping the rule here means
        // availableTransitions() never offers a customer a button the API would
        // refuse, and a future endpoint cannot forget the check.
        if (OrderStatus::isStaffOnly($targetStatus) && !$isStaffOverride) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Only our team can move an order to "%s".',
                    OrderStatus::label($targetStatus)
                ),
            ];
        }

        // --- Cancellation --------------------------------------------------
        if ($targetStatus === OrderStatus::CANCELLED
            && !$isStaffOverride
            && !OrderStatus::isCustomerCancellable($currentStatus)) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'This order is already %s and can no longer be cancelled online. Please contact support.',
                    OrderStatus::label($currentStatus)
                ),
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Same decision, but throws. Used by callers that treat a refused
     * transition as an error rather than a no-op.
     *
     * @throws HttpException
     */
    public function assert(
        string $currentStatus,
        string $targetStatus,
        string $paymentStatus,
        bool $otpVerified,
        bool $otpRequired = true,
        bool $isStaffOverride = false,
    ): void {
        $verdict = $this->evaluate(
            $currentStatus,
            $targetStatus,
            $paymentStatus,
            $otpVerified,
            $otpRequired,
            $isStaffOverride
        );

        if ($verdict['allowed']) {
            return;
        }

        throw new HttpException(
            $verdict['reason'] ?? sprintf('This order is already %s.', OrderStatus::label($targetStatus)),
            409,
            ['status' => [$verdict['reason'] ?? 'No change was needed.']]
        );
    }

    /**
     * What this order could legitimately do next, given who is asking.
     *
     * Drives both the customer's "Cancel order" button and the staff console,
     * from the same rules that enforce the transition, so a button can never
     * appear for an action that would be refused.
     *
     * @return array<int, string>
     */
    public function availableTransitions(
        string $currentStatus,
        string $paymentStatus,
        bool $otpVerified,
        bool $otpRequired = true,
        bool $isStaffOverride = false,
    ): array {
        if (!OrderStatus::exists($currentStatus)) {
            return [];
        }

        $available = [];

        foreach (OrderStatus::TRANSITIONS[$currentStatus] as $candidate) {
            $verdict = $this->evaluate(
                $currentStatus,
                $candidate,
                $paymentStatus,
                $otpVerified,
                $otpRequired,
                $isStaffOverride
            );

            if ($verdict['allowed']) {
                $available[] = $candidate;
            }
        }

        return $available;
    }

    /**
     * Progress for a tracking page: which fulfilment steps are done, current or
     * still ahead.
     *
     * @return array<int, array{status:string, label:string, state:string}>
     */
    public function progress(string $currentStatus): array
    {
        $sequence = OrderStatus::fulfilmentSequence();
        $currentIndex = array_search($currentStatus, $sequence, true);

        // Cancelled, returned and refunded orders left the happy path, so no
        // step is "current" — showing a half-filled progress bar for a
        // cancelled order reads as a system error.
        $offPath = in_array(
            $currentStatus,
            [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::REFUNDED],
            true
        );

        $steps = [];

        foreach ($sequence as $index => $status) {
            if ($offPath) {
                $state = 'inactive';
            } elseif ($currentIndex === false) {
                $state = 'pending';
            } elseif ($index < $currentIndex) {
                $state = 'complete';
            } elseif ($index === $currentIndex) {
                $state = 'current';
            } else {
                $state = 'pending';
            }

            $steps[] = [
                'status' => $status,
                'label' => OrderStatus::label($status),
                'state' => $state,
            ];
        }

        return $steps;
    }
}
