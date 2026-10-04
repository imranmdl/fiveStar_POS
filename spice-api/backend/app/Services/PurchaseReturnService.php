<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\PurchaseReturnItemRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Services\Orders\NumberingService;

/**
 * Goods sent back to a vendor. Modelled on PosSaleService::refund()'s shape
 * (line-level array + reason), the customer-facing return pattern this app
 * already has — applied here to the vendor side for the first time.
 *
 * What it does: reverses inventory for exactly the lines/quantities
 * returned (InventoryService::recordMovement(), the same unmodified engine
 * every other stock change in this system goes through — a negative delta
 * never touches average_cost, see InventoryService::recalculateAverageCost()),
 * and reduces what the business still owes the vendor via
 * purchase_orders.amount_returned.
 *
 * What it does NOT do: touch amount_paid. A return changes what is owed, not
 * what has already changed hands — if cash was already paid for stock that
 * is now going back, reconciling that is a vendor-payment/credit-note
 * decision for a human to make separately, not something this service
 * assumes.
 */
final class PurchaseReturnService
{
    public function __construct(
        private readonly PurchaseReturnRepository $returns,
        private readonly PurchaseReturnItemRepository $returnItems,
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderItemRepository $orderItems,
        private readonly InventoryService $inventory,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines Each: purchase_order_item_uuid, quantity
     *
     * @return array<string, mixed>
     */
    public function create(
        string $purchaseOrderUuid,
        string $returnDate,
        string $reason,
        array $lines,
        Request $request,
    ): array {
        $order = $this->orders->findByUuid($purchaseOrderUuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        if ($lines === []) {
            throw new HttpException('A return needs at least one line.', 422, [
                'lines' => ['Select at least one item to return.'],
            ]);
        }

        // Resolved and quantity-checked up front, outside the transaction —
        // same "fail fast, don't roll back a half-built record" reasoning
        // PurchaseOrderService::create() already applies to its own lines.
        $resolvedLines = [];
        $totalAmount = 0.0;

        foreach ($lines as $index => $line) {
            $item = $this->orderItems->findByUuid((string) $line['purchase_order_item_uuid']);

            if ($item === null || (int) $item['purchase_order_id'] !== (int) $order['id']) {
                throw new HttpException(
                    sprintf('Line %d: that item does not belong to this purchase order.', $index + 1),
                    422,
                    ['lines' => ['Every line must be an item from the selected purchase order.']]
                );
            }

            $quantity = (float) $line['quantity'];
            $alreadyReturned = $this->returns->returnedQuantityForOrderItem((int) $item['id']);
            $available = (float) $item['quantity'] - $alreadyReturned;

            if ($quantity <= 0 || $quantity > $available + 0.0005) {
                throw new HttpException(
                    sprintf(
                        'Line %d (%s): only %s is available to return (%s already returned of %s purchased).',
                        $index + 1,
                        $item['batch_no'] ?? 'no batch',
                        rtrim(rtrim(number_format($available, 3), '0'), '.'),
                        rtrim(rtrim(number_format($alreadyReturned, 3), '0'), '.'),
                        rtrim(rtrim(number_format((float) $item['quantity'], 3), '0'), '.')
                    ),
                    422,
                    ['lines' => ['Check the quantity on that line.']]
                );
            }

            $unitCost = (float) $item['unit_cost'];
            $lineAmount = $quantity * $unitCost;
            $totalAmount += $lineAmount;

            $resolvedLines[] = [
                'purchase_order_item_id' => (int) $item['id'],
                'product_variant_id' => (int) $item['product_variant_id'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_amount' => $lineAmount,
                'batch_no' => $item['batch_no'],
            ];
        }

        $returnId = $this->db->transaction(function () use (
            $order,
            $returnDate,
            $reason,
            $resolvedLines,
            $totalAmount,
            $request,
        ): int {
            $returnNumber = $this->numbering->nextPurchaseReturnNumber();

            $returnId = $this->returns->create([
                'return_number' => $returnNumber,
                'purchase_order_id' => (int) $order['id'],
                'vendor_id' => (int) $order['vendor_id'],
                'return_date' => $returnDate,
                'reason' => $reason,
                'total_amount' => number_format($totalAmount, 2, '.', ''),
            ], $request->authUserId());

            foreach ($resolvedLines as $line) {
                $this->returnItems->create([
                    'purchase_return_id' => $returnId,
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'quantity' => number_format($line['quantity'], 3, '.', ''),
                    'unit_cost' => number_format($line['unit_cost'], 4, '.', ''),
                    'line_amount' => number_format($line['line_amount'], 2, '.', ''),
                    'batch_no' => $line['batch_no'],
                ], $request->authUserId());

                $this->inventory->recordMovement(
                    variantId: $line['product_variant_id'],
                    warehouseId: (int) $order['warehouse_id'],
                    movementType: 'return_to_vendor',
                    quantityDelta: -$line['quantity'],
                    unitCost: $line['unit_cost'],
                    referenceType: 'purchase_return',
                    referenceId: $returnId,
                    batchNo: $line['batch_no'],
                    reason: 'Return to vendor ' . $returnNumber,
                    performedBy: $request->authUserId(),
                    request: $request,
                );
            }

            $this->orders->incrementAmountReturned((int) $order['id'], $totalAmount, $request->authUserId());

            return $returnId;
        });

        $this->audit->log(
            entityName: 'purchase_returns',
            entityId: $returnId,
            action: 'create',
            newValues: [
                'purchase_order_id' => (int) $order['id'],
                'line_count' => count($resolvedLines),
                'total_amount' => $totalAmount,
                'reason' => $reason,
            ],
            request: $request,
        );

        return $this->detail((string) $this->returns->findById($returnId)['uuid']);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, array $params): array
    {
        return $this->returns->search($filters, $params);
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        $return = $this->returns->detailByUuid($uuid);

        if ($return === null) {
            throw new NotFoundException('That return does not exist.');
        }

        $return['items'] = $this->returnItems->forReturn((int) $return['id']);

        return $return;
    }
}
