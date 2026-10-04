<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\PosSalePaymentRepository;
use App\Repositories\PosSaleRepository;
use App\Repositories\UserRepository;

/**
 * Recording and reading back payments against a POS credit sale — the same
 * "real ledger, recompute the header row's derived total" shape
 * VendorPaymentService already is for purchase orders. A sale's
 * payment_status/amount_paid are never written here except through
 * recomputeSaleTotals(), which re-sums pos_sale_payments from scratch every
 * time rather than trusting a running total.
 *
 * A sale that was never opened as a credit sale (is_credit_sale = 0) already
 * has amount_paid = grand_total, so record()'s own overpayment guard refuses
 * any further payment against it without needing a separate check — the
 * same reasoning VendorPaymentService::record() relies on for a fully paid
 * purchase order.
 */
final class PosDuePaymentService
{
    /** A credit sale left open this long counts as overdue on the dues dashboard/report. */
    private const OVERDUE_AFTER_DAYS = 7;

    public function __construct(
        private readonly PosSalePaymentRepository $payments,
        private readonly PosSaleRepository $sales,
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $data amount, payment_method, payment_date, reference_number?, notes?
     *
     * @return array<string, mixed>
     */
    public function record(string $saleUuid, array $data, Request $request): array
    {
        $sale = $this->sales->detailByUuid($saleUuid);

        if ($sale === null) {
            throw new NotFoundException('That sale does not exist.');
        }

        if ($sale['customer_id'] === null) {
            throw new HttpException(
                'This sale has no registered customer on it, so there is no one to record a due payment against.',
                422
            );
        }

        if ($sale['status'] !== 'completed') {
            throw new HttpException('A voided sale cannot take a payment.', 409);
        }

        $grandTotal = (float) $sale['grand_total'];
        $alreadyPaid = $this->payments->totalPaidForSale((int) $sale['id']);
        $amount = (float) $data['amount'];

        if ($amount <= 0) {
            throw new HttpException('The payment amount must be greater than zero.', 422, [
                'amount' => ['Enter an amount greater than zero.'],
            ]);
        }

        if ($alreadyPaid + $amount > $grandTotal + 0.005) {
            throw new HttpException(
                sprintf(
                    'That would pay %s against a %s bill with %s already paid — %s more than is still due.',
                    number_format($amount, 2),
                    number_format($grandTotal, 2),
                    number_format($alreadyPaid, 2),
                    number_format(($alreadyPaid + $amount) - $grandTotal, 2)
                ),
                422,
                ['amount' => ['Cannot exceed the remaining balance on this sale.']]
            );
        }

        $referenceNumber = isset($data['reference_number']) && trim((string) $data['reference_number']) !== ''
            ? trim((string) $data['reference_number'])
            : null;

        // Duplicate-entry guard: the same reference (a UPI/card transaction
        // ID) recorded twice against the same sale is almost certainly the
        // same payment entered twice, not two genuine payments that happen
        // to share a reference.
        if ($referenceNumber !== null && $this->payments->referenceExistsForSale((int) $sale['id'], $referenceNumber)) {
            throw new HttpException(
                "Reference \"{$referenceNumber}\" is already recorded against this sale.",
                422,
                ['reference_number' => ['Already used for a payment on this sale.']]
            );
        }

        $paymentId = $this->payments->create([
            'pos_sale_id' => (int) $sale['id'],
            'customer_id' => (int) $sale['customer_id'],
            'amount' => number_format($amount, 2, '.', ''),
            'payment_method' => $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'reference_number' => $referenceNumber,
            'status' => 'completed',
            'notes' => $data['notes'] ?? null,
        ], $request->authUserId());

        $this->recomputeSaleTotals((int) $sale['id'], $request);

        $this->audit->log(
            entityName: 'pos_sale_payments',
            entityId: $paymentId,
            action: 'create',
            newValues: ['pos_sale_id' => (int) $sale['id'], 'amount' => $amount, 'method' => $data['payment_method']],
            request: $request,
        );

        return (array) $this->payments->findById($paymentId);
    }

    /** @return array<int, array<string, mixed>> */
    public function forSale(string $saleUuid): array
    {
        $sale = $this->sales->detailByUuid($saleUuid);

        if ($sale === null) {
            throw new NotFoundException('That sale does not exist.');
        }

        return $this->payments->forSale((int) $sale['id']);
    }

    /**
     * Every open (and closed) credit sale for one customer, plus the total
     * still outstanding across all of them — "if a customer has multiple
     * unpaid invoices, show the total outstanding balance" (the invoices
     * themselves are what the caller picks one of to allocate a payment
     * against, via record() above).
     *
     * @return array<string, mixed>
     */
    public function customerDues(string $customerUuid): array
    {
        $customer = $this->users->findByUuid($customerUuid);

        if ($customer === null) {
            throw new NotFoundException('That customer does not exist.');
        }

        $sales = $this->sales->creditSalesForCustomer((int) $customer['id']);
        $totalOutstanding = 0.0;

        $invoices = array_map(static function (array $sale) use (&$totalOutstanding): array {
            $balanceDue = round((float) $sale['grand_total'] - (float) $sale['amount_paid'], 2);
            $totalOutstanding += $balanceDue;

            return [
                'uuid' => $sale['uuid'],
                'sale_number' => $sale['sale_number'],
                'warehouse_name' => $sale['warehouse_name'],
                'grand_total' => (float) $sale['grand_total'],
                'amount_paid' => (float) $sale['amount_paid'],
                'balance_due' => $balanceDue,
                'payment_status' => $sale['payment_status'],
                'created_date' => $sale['created_date'],
                'is_overdue' => in_array($sale['payment_status'], ['unpaid', 'partial'], true)
                    && strtotime((string) $sale['created_date']) <= strtotime('-' . self::OVERDUE_AFTER_DAYS . ' days'),
            ];
        }, $sales);

        return [
            'customer' => [
                'uuid' => $customer['uuid'],
                'full_name' => $customer['full_name'],
                'mobile' => $customer['mobile'],
            ],
            'total_outstanding' => round($totalOutstanding, 2),
            'invoices' => $invoices,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, array $params): array
    {
        if (!empty($filters['overdue_only'])) {
            $filters['overdue_days'] = self::OVERDUE_AFTER_DAYS;
        }

        return $this->sales->searchDues($filters, $params);
    }

    public static function overdueAfterDays(): int
    {
        return self::OVERDUE_AFTER_DAYS;
    }

    /**
     * Re-derives pos_sales.amount_paid/payment_status from the ledger —
     * PurchaseOrderService::derivePaymentStatus() is reused as-is: "unpaid
     * below a paisa, paid within a paisa of the total, partial in between"
     * means exactly the same thing for a bill a customer owes as it does for
     * one this store owes a vendor.
     */
    private function recomputeSaleTotals(int $posSaleId, Request $request): void
    {
        $sale = $this->sales->findById($posSaleId);
        $totalPaid = $this->payments->totalPaidForSale($posSaleId);
        $status = PurchaseOrderService::derivePaymentStatus($totalPaid, (float) $sale['grand_total']);

        $this->sales->updatePayment(
            $posSaleId,
            $status,
            number_format($totalPaid, 2, '.', ''),
            $request->authUserId(),
        );
    }
}
