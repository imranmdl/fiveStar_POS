<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;
use App\Services\Payments\ManualGateway;

/**
 * The admin side of the manual UPI QR flow.
 *
 * A customer pays a static QR code outside this system, so nothing here can
 * be confirmed by webhook. This service is the only caller of
 * ManualGateway::verifyByAdmin()/rejectByAdmin(), and it is only ever reached
 * from routes gated to the administrator role (see routes/api_v1.php). That
 * pairing — one gateway method that fabricates a "verified" PaymentVerification,
 * reachable from exactly one service, reachable from exactly one role-gated
 * route — is what stands in for the HMAC signature Razorpay and the sandbox
 * gateway use to satisfy BR-005.
 */
final class ManualPaymentService
{
    public function __construct(
        private readonly ManualGateway $gateway,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRows,
        private readonly OrderRepository $orders,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Payment attempts awaiting a human look, oldest first.
     *
     * @return array<string, mixed>
     */
    public function pendingQueue(int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->paymentRows->pendingManualVerification($perPage, $offset);
        $total = $this->paymentRows->countPendingManualVerification();

        return [
            'items' => array_map($this->present(...), $rows),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * Confirms a manual payment. Requires the admin to state the amount they
     * actually saw arrive — not just accept whatever the order says is owed —
     * so a keying error surfaces as a mismatch rather than silently paying out
     * an order for the wrong amount — and the UTR / UPI reference of the
     * transfer they matched, which is stored on the payment and can only ever
     * pay for one order.
     *
     * A UTR on its own proves nothing: this is reachable only by an
     * authenticated administrator who has checked the bank statement, and
     * every confirmation is audit-logged with their identity.
     *
     * @return array<string, mixed>
     */
    public function verify(
        Request $request,
        string $paymentUuid,
        string $confirmedAmount,
        string $utrOrReference,
        ?string $paidAt = null,
    ): array {
        $adminUserId = (int) $request->authUserId();
        $utr = self::normaliseUtr($utrOrReference);
        $payment = $this->requirePendingPayment($paymentUuid);
        $order = $this->requireOrder((int) $payment['order_id']);

        if (PaymentStatus::isSettled((string) $order['payment_status'])) {
            // The order was paid by another attempt (a Razorpay retry, or a
            // second manual attempt). Close this one so it leaves the queue
            // instead of answering "verified" while recording nothing.
            $this->paymentRows->update((int) $payment['id'], [
                'status' => 'cancelled',
                'failure_code' => 'superseded',
                'failure_reason' => 'The order was already paid by another payment.',
            ], $adminUserId);

            $this->audit->log(
                entityName: 'payments',
                entityId: (int) $payment['id'],
                action: 'manual_payment_superseded',
                newValues: ['utr_or_reference' => $utr, 'order_number' => $order['order_number']],
                request: $request,
                entityUuid: $paymentUuid,
            );

            throw new HttpException(sprintf(
                'Order %s is already paid by another payment, so there is nothing to confirm — this waiting entry has been closed. '
                . 'If the customer paid twice, refund the extra transfer (UTR %s).',
                $order['order_number'],
                $utr
            ), 409);
        }

        $verification = $this->gateway->verifyByAdmin(
            gatewayOrderId: (string) $payment['gateway_order_id'],
            amount: Money::fromDecimal($confirmedAmount),
            utrOrReference: $utr,
            adminUserId: $adminUserId,
            paidAt: $paidAt !== null ? self::normalisePaidAt($paidAt, (string) $order['placed_date']) : null,
        );

        $result = $this->payments->applyAdminVerification($order, $verification, $request, (int) $payment['id']);

        $this->audit->log(
            entityName: 'payments',
            entityId: (int) $payment['id'],
            action: 'manual_payment_verified',
            newValues: [
                'order_number' => $order['order_number'],
                'confirmed_amount' => $confirmedAmount,
                'utr_or_reference' => $utr,
                'customer_paid_at' => $verification->raw['customer_paid_at'] ?? null,
            ],
            request: $request,
            entityUuid: $paymentUuid,
        );

        return $result;
    }

    /**
     * UTR / UPI reference as banks print it: letters and digits only. Spaces
     * and dashes people copy along with it are dropped.
     */
    public static function normaliseUtr(string $value): string
    {
        $utr = strtoupper((string) preg_replace('/[\s\-]+/', '', $value));

        if (preg_match('/^[A-Z0-9]{6,30}$/', $utr) !== 1) {
            throw new ValidationException([
                'utr_or_reference' => [
                    'Enter the UTR / UPI reference number from the bank or UPI app — 6 to 30 letters and digits (a UPI UTR is usually 12 digits).',
                ],
            ]);
        }

        return $utr;
    }

    /** The transfer date staff read off the statement: not in the future, not long before the order. */
    private static function normalisePaidAt(string $value, string $placedDate): string
    {
        $ts = strtotime($value);

        if ($ts === false) {
            throw new ValidationException(['paid_at' => ['Enter a valid payment date and time.']]);
        }

        if ($ts > time() + 300) {
            throw new ValidationException(['paid_at' => ['The payment date cannot be in the future.']]);
        }

        $placed = strtotime($placedDate);

        if ($placed !== false && $ts < $placed - 86400) {
            throw new ValidationException(['paid_at' => ['The payment date is before this order was placed.']]);
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Rejects a manual payment attempt — money never arrived, wrong amount,
     * unrecognisable reference, etc. The order is left exactly where
     * PaymentService::applyVerification() puts any failed attempt: the
     * customer can retry, nothing ships.
     *
     * @return array<string, mixed>
     */
    public function reject(Request $request, string $paymentUuid, string $reason): array
    {
        $adminUserId = (int) $request->authUserId();
        $payment = $this->requirePendingPayment($paymentUuid);
        $order = $this->requireOrder((int) $payment['order_id']);

        $verification = $this->gateway->rejectByAdmin(
            gatewayOrderId: (string) $payment['gateway_order_id'],
            reason: $reason,
            adminUserId: $adminUserId,
        );

        $result = $this->payments->applyAdminVerification($order, $verification, $request, (int) $payment['id']);

        $this->audit->log(
            entityName: 'payments',
            entityId: (int) $payment['id'],
            action: 'manual_payment_rejected',
            newValues: ['reason' => $reason],
            request: $request,
            entityUuid: $paymentUuid,
        );

        return $result;
    }

    /** @return array<string, mixed> */
    private function requirePendingPayment(string $uuid): array
    {
        $payment = $this->paymentRows->findByUuid($uuid);

        if ($payment === null || $payment['gateway'] !== 'manual') {
            throw new NotFoundException('That manual payment attempt does not exist.');
        }

        // 'created' / 'pending' are the only states an admin can still act on;
        // captured, failed, cancelled and refunded are all already resolved.
        if (!in_array($payment['status'], ['created', 'pending'], true)) {
            $what = match ($payment['status']) {
                'captured', 'authorized' => 'confirmed as paid',
                'failed' => 'marked as not received',
                'cancelled' => 'closed',
                default => (string) $payment['status'],
            };

            throw new HttpException('This payment has already been ' . $what . ' — refresh to see the latest status.', 409);
        }

        return $payment;
    }

    /** @return array<string, mixed> */
    private function requireOrder(int $orderId): array
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw new NotFoundException('The order for this payment no longer exists.');
        }

        return $order;
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'order_uuid' => $row['order_uuid'],
            'order_number' => $row['order_number'],
            'customer_name' => $row['ship_name'],
            'customer_mobile' => $row['ship_mobile'],
            'amount' => $row['amount'],
            'currency_code' => $row['currency_code'],
            'gateway_order_id' => $row['gateway_order_id'],
            'attempt_number' => (int) $row['attempt_number'],
            'created_date' => $row['created_date'],
            'order_status' => $row['order_status'],
            'order_status_label' => OrderStatus::label((string) $row['order_status']),
            'order_payment_status' => $row['order_payment_status'],
            // Closed by the payment-window timer before anyone verified it;
            // confirming a real transfer reopens it (see PaymentService).
            'order_expired' => $row['order_status'] === OrderStatus::CANCELLED
                && $row['order_cancellation_reason'] === PaymentService::EXPIRY_CANCELLATION_REASON,
        ];
    }
}
