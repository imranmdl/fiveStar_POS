<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Repositories\VendorPaymentRepository;
use App\Repositories\VendorRepository;
use App\Services\Orders\NumberingService;

final class VendorService
{
    public function __construct(
        private readonly VendorRepository $vendors,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly VendorPaymentRepository $payments,
        private readonly PurchaseReturnRepository $returns,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $params): array
    {
        return $this->vendors->search($params);
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        return $this->requireByUuid($uuid);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        // Same pattern purchase orders already use for po_number — a real,
        // sequential, human-readable identifier, not the raw id/uuid.
        $data['vendor_code'] = $this->numbering->nextVendorCode();

        $id = $this->vendors->create($data, $request->authUserId());
        $vendor = (array) $this->vendors->findById($id);

        $this->audit->log(
            entityName: 'vendors',
            entityId: $id,
            action: 'create',
            newValues: $vendor,
            request: $request,
            entityUuid: (string) $vendor['uuid'],
        );

        return $vendor;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $vendor = $this->requireByUuid($uuid);
        $id = (int) $vendor['id'];

        $this->vendors->update($id, $data, $request->authUserId());
        $fresh = (array) $this->vendors->findById($id);

        $this->audit->log(
            entityName: 'vendors',
            entityId: $id,
            action: 'update',
            oldValues: $vendor,
            newValues: $fresh,
            request: $request,
            entityUuid: $uuid,
        );

        return $fresh;
    }

    public function deactivate(string $uuid, Request $request): void
    {
        $vendor = $this->requireByUuid($uuid);

        $this->vendors->update((int) $vendor['id'], ['is_active' => 0], $request->authUserId());

        $this->audit->log(
            entityName: 'vendors',
            entityId: (int) $vendor['id'],
            action: 'deactivate',
            request: $request,
            entityUuid: $uuid,
        );
    }

    /**
     * A vendor's whole relationship with the business in one place: every
     * purchase, every payment, every return, and the pending balance that
     * falls out of them — grand_total minus what's actually been paid and
     * minus what's since been returned, summed across every purchase order.
     *
     * @return array<string, mixed>
     */
    public function history(string $uuid): array
    {
        $vendor = $this->requireByUuid($uuid);
        $vendorId = (int) $vendor['id'];

        $purchases = $this->purchaseOrders->forVendor($vendorId);
        $payments = $this->payments->forVendor($vendorId);
        $returns = $this->returns->forVendor($vendorId);

        $totalPurchased = array_sum(array_map(static fn (array $p): float => (float) $p['grand_total'], $purchases));
        $totalPaid = array_sum(array_map(static fn (array $p): float => (float) $p['amount_paid'], $purchases));
        $totalReturned = array_sum(array_map(static fn (array $p): float => (float) $p['amount_returned'], $purchases));

        return [
            'vendor' => $vendor,
            'purchases' => $purchases,
            'payments' => $payments,
            'returns' => $returns,
            'summary' => [
                'purchase_count' => count($purchases),
                'total_purchased' => $totalPurchased,
                'total_paid' => $totalPaid,
                'total_returned' => $totalReturned,
                'pending_balance' => max(0.0, $totalPurchased - $totalPaid - $totalReturned),
            ],
        ];
    }

    /**
     * The Vendors screen's own dashboard — total vendors, purchases, pending
     * payments, paid amount, returns, top vendors by spend, and recent
     * purchases. Deliberately its own query rather than reusing
     * ReportingService::vendorReliability(), which answers a different
     * question (invoice-shortfall/damage-loss reliability, not spend).
     *
     * @return array<string, mixed>
     */
    public function dashboardStats(): array
    {
        $totalVendors = $this->vendors->countActive();

        $rows = $this->purchaseOrders->forVendorSpendSummary();

        $totalPurchases = count($rows);
        $totalPurchaseValue = array_sum(array_map(static fn (array $r): float => (float) $r['grand_total'], $rows));
        $totalPaid = array_sum(array_map(static fn (array $r): float => (float) $r['amount_paid'], $rows));
        $pendingPayments = array_sum(array_map(
            static fn (array $r): float => max(0.0, (float) $r['grand_total'] - (float) $r['amount_paid'] - (float) $r['amount_returned']),
            $rows
        ));

        $returnRows = $this->returns->forDashboard();
        $returnCount = count($returnRows);
        $returnValue = array_sum(array_map(static fn (array $r): float => (float) $r['total_amount'], $returnRows));

        $byVendor = [];
        foreach ($rows as $row) {
            $key = (int) $row['vendor_id'];
            $byVendor[$key] ??= ['vendor_uuid' => $row['vendor_uuid'], 'vendor_name' => $row['vendor_name'], 'total' => 0.0];
            $byVendor[$key]['total'] += (float) $row['grand_total'];
        }
        usort($byVendor, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'total_vendors' => $totalVendors,
            'total_purchases' => $totalPurchases,
            'total_purchase_value' => $totalPurchaseValue,
            'paid_amount' => $totalPaid,
            'pending_payments' => $pendingPayments,
            'purchase_returns' => ['count' => $returnCount, 'value' => $returnValue],
            'top_vendors' => array_slice($byVendor, 0, 5),
            'recent_purchases' => array_slice($rows, 0, 10),
        ];
    }

    /** @return array<string, mixed> */
    private function requireByUuid(string $uuid): array
    {
        $vendor = $this->vendors->findByUuid($uuid);

        if ($vendor === null) {
            throw new NotFoundException('That vendor does not exist.');
        }

        return $vendor;
    }
}
