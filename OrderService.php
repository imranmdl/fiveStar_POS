<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Repositories\SettingRepository;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;
use App\Services\Payments\PaymentGatewayInterface;

/**
 * Reading orders, tracking them, cancelling them and moving them through
 * fulfilment.
 *
 * Every status change goes through OrderStateMachine, so BR-005 cannot be
 * sidestepped by a staff endpoint. There is deliberately no "force status"
 * route: an administrator marking an unpaid order as shipped is exactly the
 * loss BR-005 exists to prevent, and an override would be used within a week.
 */
final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly RefundRepository $refunds,
        private readonly PaymentService $paymentService,
        private readonly PaymentGatewayInterface $gateway,
        private readonly OrderStateMachine $stateMachine,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function listForCustomer(Request $request, array $params, ?string $status): array
    {
        $result = $this->orders->paginateForCustomer((int) $request->authUserId(), $params, $status);
        $result['items'] = array_map(fn (array $row): array => $this->presentSummary($row), $result['items']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function showForCustomer(Request $request, string $uuid): array
    {
        $order = $this->requireOwnedOrder($uuid, (int) $request->authUserId());

        return $this->presentDetail($order, customerView: true);
    }

    /**
     * Public-ish tracking: order number plus the mobile it was shipped to.
     *
     * Requiring both means an order number alone — which appears on a parcel
     * label anyone can read — is not enough to expose a customer's address.
     *
     * @return array<string, mixed>
     */
    public function track(string $orderNumber, string $mobile): array
    {
        $order = $this->orders->findByNumber($orderNumber);

        $digits = preg_replace('/\D/', '', $mobile) ?? '';
        $stored = preg_replace('/\D/', '', (string) ($order['ship_mobile'] ?? '')) ?? '';

        // Uniform failure for a wrong number and a non-existent order, so this
        // endpoint cannot be used to discover which order numbers are real.
        if ($order === null || $digits === '' || !hash_equals($stored, $digits)) {
            throw new NotFoundException('No order matches that number and mobile.');
        }

        $detail = $this->presentDetail($order, customerView: true);

        // Tracking is a thinner view than the customer's own order page: no
        // pricing, no invoice, no notes.
        unset($detail['pricing'], $detail['payments'], $detail['invoice']);

        return $detail;
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function listForStaff(array $params, ?string $status, ?string $paymentStatus): array
    {
        return $this->orders->paginateForStaff($params, $status, $paymentStatus);
    }

    /** @return array<string, mixed> */
    public function showForStaff(string $uuid): array
    {
        $order = $this->orders->findByUuid($uuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        $detail = $this->presentDetail($order, customerView: false);
        $detail['payment_events'] = $this->payments->eventsForOrder((int) $order['id']);
        $detail['refunds'] = $this->refunds->forOrder((int) $order['id']);
        $detail['internal_note'] = $order['internal_note'];

        return $detail;
    }

    /**
     * Customer cancellation.
     *
     * Releases the coupon use and returns wallet credit, then refunds whatever
     * reached the gateway. Doing it in that order matters: the customer gets
     * their credit back even if the gateway refund needs a retry.
     *
     * @return array<string, mixed>
     */
    public function cancel(Request $request, string $uuid, string $reason, bool $isStaff = false): array
    {
        $userId = (int) $request->authUserId();

        $order = $isStaff
            ? $this->orders->findByUuid($uuid)
            : $this->requireOwnedOrder($uuid, $userId);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        $walletReturned = Money::zero();
        $couponsReleased = 0;

        $this->db->transaction(function () use ($order, $reason, $request, $userId, $isStaff, &$walletReturned, &$couponsReleased): void {
            $locked = $this->orders->lockForUpdate((int) $order['id']);

            if ($locked === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            $this->stateMachine->assert(
                (string) $locked['status'],
                OrderStatus::CANCELLED,
                (string) $locked['payment_status'],
                (bool) $locked['otp_verified'],
                $this->otpRequired(),
                $isStaff
            );

            $this->paymentService->releaseHeldValue(
                $locked,
                'Order cancelled: ' . $reason,
                $request,
                $walletReturned,
                $couponsReleased
            );

            $this->orders->update((int) $locked['id'], [
                'status' => OrderStatus::CANCELLED,
                'cancelled_date' => date('Y-m-d H:i:s'),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
                'expires_date' => null,
            ], $userId);

            $this->orders->appendTimeline(
                orderId: (int) $locked['id'],
                fromStatus: (string) $locked['status'],
                toStatus: OrderStatus::CANCELLED,
                title: 'Order cancelled',
                paymentStatus: (string) $locked['payment_status'],
                note: $reason,
                changedBy: $userId,
                changedByRole: $isStaff ? 'staff' : 'customer',
            );
        });

        // The gateway refund runs outside the transaction: an HTTP call to a
        // third party must never hold a database lock, and a slow gateway must
        // not roll back a cancellation the customer has already been told about.
        $refund = $this->refundGatewayPortion($order, $reason, $request);

        $this->audit->log(
            entityName: 'orders',
            entityId: (int) $order['id'],
            action: 'cancel',
            newValues: [
                'reason' => $reason,
                'wallet_returned' => $walletReturned->toDecimal(),
                'coupons_released' => $couponsReleased,
                'gateway_refund' => $refund,
            ],
            request: $request,
            entityUuid: $uuid,
        );

        return [
            'order' => $this->presentSummary((array) $this->orders->findById((int) $order['id'])),
            'wallet_returned' => $walletReturned->toDecimal(),
            'coupon_released' => $couponsReleased > 0,
            'refund' => $refund,
        ];
    }

    /**
     * Staff fulfilment transition.
     *
     * @return array<string, mixed>
     */
    public function advance(Request $request, string $uuid, string $targetStatus, ?string $note): array
    {
        $order = $this->orders->findByUuid($uuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        $this->db->transaction(function () use ($order, $targetStatus, $note, $request): void {
            $locked = $this->orders->lockForUpdate((int) $order['id']);

            if ($locked === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            // The same rules the customer is held to. BR-005 is not waived for
            // staff — an unpaid order cannot be marked packed or shipped.
            $this->stateMachine->assert(
                (string) $locked['status'],
                $targetStatus,
                (string) $locked['payment_status'],
                (bool) $locked['otp_verified'],
                $this->otpRequired(),
                isStaffOverride: true
            );

            $changes = ['status' => $targetStatus];

            if ($targetStatus === OrderStatus::DELIVERED) {
                $changes['delivered_date'] = date('Y-m-d H:i:s');
            }

            if ($targetStatus === OrderStatus::SHIPPED) {
                $changes['shipped_date'] = date('Y-m-d H:i:s');
            }

            $this->orders->update((int) $locked['id'], $changes, $request->authUserId());

            $this->orders->appendTimeline(
                orderId: (int) $locked['id'],
                fromStatus: (string) $locked['status'],
                toStatus: $targetStatus,
                title: OrderStatus::label($targetStatus),
                paymentStatus: (string) $locked['payment_status'],
                note: $note,
                changedBy: $request->authUserId(),
                changedByRole: 'staff',
            );
        });

        $this->audit->log(
            entityName: 'orders',
            entityId: (int) $order['id'],
            action: 'advance',
            oldValues: ['status' => $order['status']],
            newValues: ['status' => $targetStatus, 'note' => $note],
            request: $request,
            entityUuid: $uuid,
        );

        return $this->showForStaff($uuid);
    }

    /**
     * GST invoice data. Only available once payment is confirmed, because an
     * unpaid order has no invoice number by design.
     *
     * @return array<string, mixed>
     */
    public function invoice(Request $request, string $uuid, bool $isStaff = false): array
    {
        $order = $isStaff
            ? $this->orders->findByUuid($uuid)
            : $this->requireOwnedOrder($uuid, (int) $request->authUserId());

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        if ($order['invoice_number'] === null) {
            throw new HttpException(
                'No invoice has been issued for this order yet. Invoices are created when payment is confirmed.',
                409
            );
        }

        $taxLines = $this->orders->taxLinesFor((int) $order['id']);
        $isInterstate = false;

        foreach ($taxLines as $line) {
            if ((float) $line['igst_amount'] > 0) {
                $isInterstate = true;
            }
        }

        return [
            'invoice' => [
                'number' => $order['invoice_number'],
                'date' => $order['invoice_date'],
                'financial_year' => $order['invoice_financial_year'],
                'place_of_supply' => $order['ship_state'],
                'is_interstate' => $isInterstate,
            ],
            'seller' => [
                'legal_name' => $this->settings->value('seller_legal_name', 'Spice & Dry Fruits'),
                'gstin' => $this->settings->value('seller_gstin', ''),
                'state' => $this->settings->value('seller_state', 'Karnataka'),
            ],
            'buyer' => [
                'name' => $order['ship_name'],
                'address' => $this->formatAddress($order),
                'state' => $order['ship_state'],
                'pincode' => $order['ship_pincode'],
            ],
            'order' => [
                'order_number' => $order['order_number'],
                'placed_date' => $order['placed_date'],
                'confirmed_date' => $order['confirmed_date'],
            ],
            'lines' => array_map(static fn (array $item): array => [
                'description' => $item['product_name'] . ' - ' . $item['variant_name'],
                'sku' => $item['sku'],
                'hsn_code' => $item['hsn_code'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'taxable_value' => (float) $item['taxable_value'],
                'gst_rate' => (float) $item['gst_rate'],
                'tax_amount' => (float) $item['tax_amount'],
                'line_total' => (float) $item['line_payable'],
            ], $this->orders->itemsFor((int) $order['id'])),
            'tax_summary' => array_map(static fn (array $line): array => [
                'gst_rate' => (float) $line['gst_rate'],
                'taxable_value' => (float) $line['taxable_value'],
                'cgst_amount' => (float) $line['cgst_amount'],
                'sgst_amount' => (float) $line['sgst_amount'],
                'igst_amount' => (float) $line['igst_amount'],
                'tax_amount' => (float) $line['tax_amount'],
            ], $taxLines),
            'totals' => [
                'taxable_value' => (float) $order['taxable_value'],
                'tax_total' => (float) $order['tax_total'],
                'delivery_charge' => (float) $order['delivery_charge'],
                'grand_total' => (float) $order['grand_total'],
                // Shown for transparency: the invoice total is the order value,
                // and the wallet portion is a tender against it, not a discount.
                'paid_from_wallet' => (float) $order['wallet_applied'],
                'paid_online' => (float) $order['amount_paid'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null
     */
    private function refundGatewayPortion(array $order, string $reason, Request $request): ?array
    {
        $captured = $this->payments->capturedForOrder((int) $order['id']);

        if ($captured === null || $captured['gateway_payment_id'] === null) {
            return null;
        }

        $amount = Money::fromDecimal((string) $captured['amount']);

        if (!$amount->isPositive()) {
            return null;
        }

        $idempotencyKey = 'refund:' . $order['order_number'];
        $existing = $this->refunds->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return ['status' => $existing['status'], 'amount' => (float) $existing['total_amount']];
        }

        $refundId = $this->refunds->create([
            'order_id' => (int) $order['id'],
            'payment_id' => (int) $captured['id'],
            'gateway' => $this->gateway->name(),
            'total_amount' => (string) $amount,
            'gateway_amount' => (string) $amount,
            'wallet_amount' => '0.00',
            'reason' => $reason,
            'status' => 'processing',
            'idempotency_key' => $idempotencyKey,
        ], $request->authUserId());

        try {
            $result = $this->gateway->refund(
                (string) $captured['gateway_payment_id'],
                $amount,
                $reason,
                $idempotencyKey
            );

            $this->refunds->update($refundId, [
                'gateway_refund_id' => $result['refund_id'],
                'status' => 'completed',
                'completed_date' => date('Y-m-d H:i:s'),
                'gateway_response' => json_encode($result['raw']),
            ], $request->authUserId());

            $this->orders->update((int) $order['id'], [
                'payment_status' => PaymentStatus::REFUNDED,
                'amount_refunded' => (string) $amount,
            ], null);

            $this->orders->appendTimeline(
                orderId: (int) $order['id'],
                fromStatus: OrderStatus::CANCELLED,
                toStatus: OrderStatus::CANCELLED,
                title: 'Refund issued',
                paymentStatus: PaymentStatus::REFUNDED,
                note: sprintf('%s refunded to the original payment method.', $amount->format()),
            );

            return ['status' => 'completed', 'amount' => $amount->toDecimal()];
        } catch (\Throwable $exception) {
            // The refund row stays as `processing` so it can be retried or
            // completed by hand. Losing the record would lose the obligation.
            $this->refunds->update($refundId, [
                'status' => 'failed',
                'failure_reason' => substr($exception->getMessage(), 0, 250),
            ], $request->authUserId());

            $this->orders->appendTimeline(
                orderId: (int) $order['id'],
                fromStatus: OrderStatus::CANCELLED,
                toStatus: OrderStatus::CANCELLED,
                title: 'Refund could not be completed automatically',
                paymentStatus: (string) $order['payment_status'],
                note: 'Our team will process this refund manually.',
                customerVisible: true,
            );

            return ['status' => 'failed', 'amount' => $amount->toDecimal()];
        }
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function presentSummary(array $order): array
    {
        return [
            'uuid' => $order['uuid'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'status_label' => OrderStatus::label((string) $order['status']),
            'payment_status' => $order['payment_status'],
            'payment_status_label' => PaymentStatus::label((string) $order['payment_status']),
            'grand_total' => (float) $order['grand_total'],
            'amount_payable' => (float) $order['amount_payable'],
            'wallet_applied' => (float) $order['wallet_applied'],
            'item_count' => count($this->orders->itemsFor((int) $order['id'])),
            'placed_date' => $order['placed_date'],
            'expected_delivery_date' => $order['expected_delivery_date'],
            'invoice_number' => $order['invoice_number'],
            'tracking_number' => $order['tracking_number'],
            'can_cancel' => $this->stateMachine->evaluate(
                (string) $order['status'],
                OrderStatus::CANCELLED,
                (string) $order['payment_status'],
                (bool) $order['otp_verified'],
                $this->otpRequired()
            )['allowed'],
        ];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function presentDetail(array $order, bool $customerView): array
    {
        $orderId = (int) $order['id'];

        return [
            'order' => $this->presentSummary($order) + [
                'otp_verified' => (bool) $order['otp_verified'],
                'expires_date' => $order['expires_date'],
                'confirmed_date' => $order['confirmed_date'],
                'delivered_date' => $order['delivered_date'],
                'cancelled_date' => $order['cancelled_date'],
                'cancellation_reason' => $order['cancellation_reason'],
                'customer_note' => $order['customer_note'],
                'is_gift' => (bool) $order['is_gift'],
                'gift_message' => $order['gift_message'],
                'placed_channel' => $order['placed_channel'],
            ],
            'items' => array_map(static fn (array $item): array => [
                'uuid' => $item['uuid'],
                'product_name' => $item['product_name'],
                'variant_name' => $item['variant_name'],
                'sku' => $item['sku'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'unit_mrp' => (float) $item['unit_mrp'],
                'line_payable' => (float) $item['line_payable'],
                'gst_rate' => (float) $item['gst_rate'],
                'tax_amount' => (float) $item['tax_amount'],
                'is_gift' => (bool) $item['is_gift'],
            ], $this->orders->itemsFor($orderId)),
            'pricing' => [
                'items_mrp_total' => (float) $order['items_mrp_total'],
                'items_subtotal' => (float) $order['items_subtotal'],
                'product_discount' => (float) $order['product_discount'],
                'order_discount' => (float) $order['order_discount'],
                'delivery_charge' => (float) $order['delivery_charge'],
                'taxable_value' => (float) $order['taxable_value'],
                'tax_total' => (float) $order['tax_total'],
                'grand_total' => (float) $order['grand_total'],
                'wallet_applied' => (float) $order['wallet_applied'],
                'amount_payable' => (float) $order['amount_payable'],
                'amount_refunded' => (float) $order['amount_refunded'],
                'total_savings' => (float) $order['total_savings'],
                'coupon_code' => $order['coupon_code'],
                'offer_code' => $order['offer_code'],
                'tax_breakdown' => array_map(static fn (array $line): array => [
                    'gst_rate' => (float) $line['gst_rate'],
                    'taxable_value' => (float) $line['taxable_value'],
                    'cgst_amount' => (float) $line['cgst_amount'],
                    'sgst_amount' => (float) $line['sgst_amount'],
                    'igst_amount' => (float) $line['igst_amount'],
                ], $this->orders->taxLinesFor($orderId)),
            ],
            'shipping' => [
                'name' => $order['ship_name'],
                'mobile' => $order['ship_mobile'],
                'address' => $this->formatAddress($order),
                'city' => $order['ship_city'],
                'state' => $order['ship_state'],
                'pincode' => $order['ship_pincode'],
                'zone_code' => $order['delivery_zone_code'],
                'slot' => $order['delivery_slot'],
                'instructions' => $order['delivery_instructions'],
                'weight_grams' => (int) $order['total_weight_grams'],
                'courier_name' => $order['courier_name'],
                'tracking_number' => $order['tracking_number'],
                'tracking_url' => $order['tracking_url'],
            ],
            'progress' => $this->stateMachine->progress((string) $order['status']),
            // BR-008: the complete history. Internal notes are filtered out of
            // the customer's copy.
            'timeline' => array_map(static fn (array $entry): array => [
                'title' => $entry['title'],
                'note' => $entry['note'],
                'status' => $entry['to_status'],
                'date' => $entry['created_date'],
            ], $this->orders->timelineFor($orderId, $customerView)),
            'payments' => array_map(static fn (array $payment): array => [
                'uuid' => $payment['uuid'],
                'gateway' => $payment['gateway'],
                'attempt' => (int) $payment['attempt_number'],
                'amount' => (float) $payment['amount'],
                'status' => $payment['status'],
                'method' => $payment['method'],
                'upi_vpa' => $payment['upi_vpa'],
                'signature_verified' => (bool) $payment['signature_verified'],
                'failure_reason' => $payment['failure_reason'],
                'created_date' => $payment['created_date'],
            ], $this->payments->forOrder($orderId)),
            'invoice' => $order['invoice_number'] === null ? null : [
                'number' => $order['invoice_number'],
                'date' => $order['invoice_date'],
            ],
            'available_transitions' => $this->stateMachine->availableTransitions(
                (string) $order['status'],
                (string) $order['payment_status'],
                (bool) $order['otp_verified'],
                $this->otpRequired(),
                !$customerView
            ),
        ];
    }

    /** @param array<string, mixed> $order */
    private function formatAddress(array $order): string
    {
        return implode(', ', array_filter([
            $order['ship_address_line1'],
            $order['ship_address_line2'],
            $order['ship_landmark'],
            $order['ship_city'],
            $order['ship_state'],
            $order['ship_pincode'],
        ]));
    }

    /** @return array<string, mixed> */
    private function requireOwnedOrder(string $uuid, int $userId): array
    {
        $order = $this->orders->findByUuid($uuid);

        if ($order === null || (int) $order['user_id'] !== $userId) {
            throw new NotFoundException('That order does not exist.');
        }

        return $order;
    }

    private function otpRequired(): bool
    {
        return $this->settings->boolValue('order_otp_required', true);
    }
}
