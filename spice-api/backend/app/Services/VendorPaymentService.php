<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\VendorPaymentRepository;

/**
 * A real ledger: every payment against a purchase order is its own row, with
 * its own amount/method/date/reference — not the single running total
 * `purchase_orders.amount_paid` used to be the only record of (migration
 * 019). That column, and `payment_status` derived from it, are kept exactly
 * as they were: every existing read of them keeps working, because they are
 * now recomputed from this table's SUM rather than written to directly.
 */
final class VendorPaymentService
{
    public function __construct(
        private readonly VendorPaymentRepository $payments,
        private readonly PurchaseOrderRepository $orders,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $data amount, payment_method, payment_date, reference_number?, notes?
     *
     * @return array<string, mixed>
     */
    public function record(string $purchaseOrderUuid, array $data, Request $request): array
    {
        $order = $this->orders->findByUuid($purchaseOrderUuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        $grandTotal = (float) $order['grand_total'];
        $alreadyPaid = $this->payments->totalPaidForOrder((int) $order['id']);
        $amount = (float) $data['amount'];

        if ($amount <= 0) {
            throw new HttpException('The payment amount must be greater than zero.', 422, [
                'amount' => ['Enter an amount greater than zero.'],
            ]);
        }

        if ($alreadyPaid + $amount > $grandTotal + 0.005) {
            throw new HttpException(
                sprintf(
                    'That would pay %s against a %s order with %s already paid — %s more than the order is worth.',
                    number_format($amount, 2),
                    number_format($grandTotal, 2),
                    number_format($alreadyPaid, 2),
                    number_format(($alreadyPaid + $amount) - $grandTotal, 2)
                ),
                422,
                ['amount' => ['Cannot exceed what is still owed on this purchase order.']]
            );
        }

        $paymentId = $this->payments->create([
            'purchase_order_id' => (int) $order['id'],
            'vendor_id' => (int) $order['vendor_id'],
            'amount' => number_format($amount, 2, '.', ''),
            'payment_method' => $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'reference_number' => $data['reference_number'] ?? null,
            'status' => 'completed',
            'notes' => $data['notes'] ?? null,
        ], $request->authUserId());

        $this->recomputeOrderTotals((int) $order['id'], $request);

        $this->audit->log(
            entityName: 'vendor_payments',
            entityId: $paymentId,
            action: 'create',
            newValues: ['purchase_order_id' => (int) $order['id'], 'amount' => $amount, 'method' => $data['payment_method']],
            request: $request,
        );

        return (array) $this->payments->findById($paymentId);
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(string $purchaseOrderUuid): array
    {
        $order = $this->orders->findByUuid($purchaseOrderUuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        return $this->payments->forOrder((int) $order['id']);
    }

    /**
     * Re-derives purchase_orders.amount_paid/payment_status from the ledger
     * — the same derivation PurchaseOrderService::create()/updatePayment()
     * already use, kept identical via PurchaseOrderService::derivePaymentStatus().
     */
    private function recomputeOrderTotals(int $purchaseOrderId, Request $request): void
    {
        $order = $this->orders->findById($purchaseOrderId);
        $totalPaid = $this->payments->totalPaidForOrder($purchaseOrderId);
        $status = PurchaseOrderService::derivePaymentStatus($totalPaid, (float) $order['grand_total']);

        $this->orders->updatePayment(
            $purchaseOrderId,
            $status,
            number_format($totalPaid, 2, '.', ''),
            $request->authUserId(),
        );
    }
}
