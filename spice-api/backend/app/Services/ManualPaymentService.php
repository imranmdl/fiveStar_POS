<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
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
     * an order for the wrong amount.
     *
     * @return array<string, mixed>
     */
    public function verify(Request $request, string $paymentUuid, string $confirmedAmount, string $utrOrReference): array
    {
        $adminUserId = (int) $request->authUserId();
        $payment = $this->requirePendingPayment($paymentUuid);
        $order = $this->requireOrder((int) $payment['order_id']);

        $verification = $this->gateway->verifyByAdmin(
            gatewayOrderId: (string) $payment['gateway_order_id'],
            amount: Money::fromDecimal($confirmedAmount),
            utrOrReference: $utrOrReference,
            adminUserId: $adminUserId,
        );

        $result = $this->payments->applyAdminVerification($order, $verification, $request);

        $this->audit->log(
            entityName: 'payments',
            entityId: (int) $payment['id'],
            action: 'manual_payment_verified',
            newValues: [
                'confirmed_amount' => $confirmedAmount,
                'utr_or_reference' => $utrOrReference,
            ],
            request: $request,
            entityUuid: $paymentUuid,
        );

        return $result;
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

        $result = $this->payments->applyAdminVerification($order, $verification, $request);

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
            throw new HttpException('This payment has already been resolved.', 409);
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
        ];
    }
}
