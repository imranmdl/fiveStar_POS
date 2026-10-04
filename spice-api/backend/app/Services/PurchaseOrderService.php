<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Repositories\ProductVariantRepository;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Repositories\SettingRepository;
use App\Repositories\VendorPaymentRepository;
use App\Repositories\VendorRepository;
use App\Repositories\WarehouseRepository;
use App\Services\Orders\NumberingService;

/**
 * Records a purchase from a vendor. Immediate effect, by design (confirmed
 * with the product owner rather than assumed): saving a purchase order posts
 * inventory straight away, one InventoryService::recordMovement() call per
 * line — the same, unmodified engine Phase 1 built and verified. This
 * service adds no new stock-writing code path; it is a new caller of an
 * existing one.
 *
 * Purchase history is never silently overwritten. There is still no
 * delete() — but updateItem() below does let a line's quantity/cost/batch
 * be corrected after the fact, because staff genuinely need that. It never
 * rewrites the ledger in place: it reverses the line's original inventory
 * effect and posts the corrected one as new movements (the same "a
 * correction is a new movement" principle InventoryService's manual
 * adjustment already follows), then updates the stored line to match. See
 * updateItem()'s own doc comment for exactly what it does and does not
 * reconstruct.
 *
 * Phase 3 addition: once inventory is safely recorded, and only if the
 * `inventory_price_change_mode` setting isn't 'never', each line is checked
 * against the configured pricing strategy — see PricingService. That check
 * runs AFTER the inventory transaction commits, the same way
 * OrderService::cancel() runs its gateway refund outside its transaction: a
 * pricing problem (or an auto-apply rule that fails to resolve) must not roll
 * back inventory that has already, correctly, been recorded.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderItemRepository $items,
        private readonly VendorRepository $vendors,
        private readonly WarehouseRepository $warehouses,
        private readonly ProductVariantRepository $variants,
        private readonly InventoryService $inventory,
        private readonly PricingService $pricing,
        private readonly ProductService $products,
        private readonly SettingRepository $settings,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly VendorPaymentRepository $paymentsLedger,
        private readonly PurchaseReturnRepository $returnsLedger,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines Each: variant_uuid, quantity, unit_cost,
     *                                                 batch_no?, expiry_date?, mrp?, selling_price?, gst_rate?, discount_amount?
     *
     * @return array<string, mixed>
     */
    public function create(
        string $vendorUuid,
        string $warehouseUuid,
        string $purchaseDate,
        ?string $invoiceReference,
        array $lines,
        float $discountAmount,
        float $otherCharges,
        bool $transportIncludedInCost,
        float $transportCharge,
        float $taxAmount,
        float $amountPaid,
        ?string $notes,
        Request $request,
    ): array {
        $vendor = $this->vendors->findByUuid($vendorUuid);

        if ($vendor === null) {
            throw new NotFoundException('That vendor does not exist.');
        }

        $warehouse = $this->warehouses->findByUuid($warehouseUuid);

        if ($warehouse === null) {
            throw new NotFoundException('That warehouse does not exist.');
        }

        // Resolved up front, outside the transaction: a bad SKU should fail
        // fast with a clear message rather than roll back a half-built PO.
        $resolvedLines = [];

        foreach ($lines as $index => $line) {
            $variant = $this->variants->findByUuid((string) $line['variant_uuid']);

            if ($variant === null) {
                throw new HttpException(
                    sprintf('Line %d: that pack size does not exist.', $index + 1),
                    422,
                    ['lines' => ['Every line needs a valid pack size.']]
                );
            }

            $newSellingPrice = isset($line['selling_price']) ? (float) $line['selling_price'] : null;
            $newMrp = isset($line['mrp']) ? (float) $line['mrp'] : null;

            if ($newMrp !== null && $newSellingPrice !== null && $newSellingPrice > $newMrp) {
                throw new HttpException(
                    sprintf('Line %d: the selling price cannot exceed the MRP.', $index + 1),
                    422,
                    ['lines' => ['Check the MRP and selling price on that line.']]
                );
            }

            $gstRate = isset($line['gst_rate']) ? (float) $line['gst_rate'] : null;
            $discountAmountLine = (float) ($line['discount_amount'] ?? 0);

            $resolvedLines[] = [
                'variant_id' => (int) $variant['id'],
                'variant_uuid' => $variant['uuid'],
                'sku' => $variant['sku'],
                // The variant's CURRENT price, before this purchase — what the
                // existing pricing-strategy check below compares against. Not
                // to be confused with new_mrp/new_selling_price, which is what
                // the admin is declaring on this line right now.
                'selling_price' => (float) $variant['selling_price'],
                'new_mrp' => $newMrp,
                'new_selling_price' => $newSellingPrice,
                'gst_rate' => $gstRate,
                'discount_amount' => $discountAmountLine,
                'quantity' => (float) $line['quantity'],
                'invoiced_quantity' => isset($line['invoiced_quantity']) ? (float) $line['invoiced_quantity'] : null,
                'unit_cost' => (float) $line['unit_cost'],
                'batch_no' => $line['batch_no'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
            ];
        }

        $itemsSubtotal = array_sum(array_map(
            static fn (array $l): float => $l['quantity'] * $l['unit_cost'],
            $resolvedLines
        ));

        // The transportation percentage is computed once, by value, across the
        // whole shipment, then applied uniformly to every line. It is never
        // asked for when the checkbox says unit_cost already carries it, and
        // never divides by a zero/negative subtotal.
        $transportPercent = (!$transportIncludedInCost && $itemsSubtotal > 0 && $transportCharge > 0)
            ? ($transportCharge / $itemsSubtotal) * 100
            : null;

        foreach ($resolvedLines as $index => $line) {
            $resolvedLines[$index]['landing_cost'] = $transportIncludedInCost || $transportPercent === null
                ? $line['unit_cost']
                : $line['unit_cost'] * (1 + $transportPercent / 100);
        }

        $grandTotal = $itemsSubtotal - $discountAmount + $transportCharge + $otherCharges + $taxAmount;

        if ($amountPaid > $grandTotal + 0.005) {
            throw new HttpException(
                'Amount paid cannot be more than the purchase order total.',
                422,
                ['amount_paid' => ['Must not exceed the grand total.']]
            );
        }

        $paymentStatus = self::derivePaymentStatus($amountPaid, $grandTotal);

        $averageCostByVariant = [];

        $purchaseOrderId = $this->db->transaction(function () use (
            $vendor,
            $warehouse,
            $purchaseDate,
            $invoiceReference,
            $resolvedLines,
            $itemsSubtotal,
            $discountAmount,
            $otherCharges,
            $transportIncludedInCost,
            $transportCharge,
            $transportPercent,
            $taxAmount,
            $grandTotal,
            $paymentStatus,
            $amountPaid,
            $notes,
            $request,
            &$averageCostByVariant,
        ): int {
            $poNumber = $this->numbering->nextPurchaseOrderNumber();

            $poId = $this->orders->create([
                'po_number' => $poNumber,
                'vendor_id' => (int) $vendor['id'],
                'warehouse_id' => (int) $warehouse['id'],
                'purchase_date' => $purchaseDate,
                'invoice_reference' => $invoiceReference,
                'items_subtotal' => number_format($itemsSubtotal, 2, '.', ''),
                'discount_amount' => number_format($discountAmount, 2, '.', ''),
                'other_charges' => number_format($otherCharges, 2, '.', ''),
                'transport_charge' => number_format($transportCharge, 2, '.', ''),
                'transport_included_in_cost' => $transportIncludedInCost ? 1 : 0,
                'transport_percent' => $transportPercent === null ? null : number_format($transportPercent, 4, '.', ''),
                'tax_amount' => number_format($taxAmount, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'payment_status' => $paymentStatus,
                'amount_paid' => number_format($amountPaid, 2, '.', ''),
                'notes' => $notes,
            ], $request->authUserId());

            foreach ($resolvedLines as $lineIndex => $line) {
                $lineSubtotal = $line['quantity'] * $line['unit_cost'];
                $gstAmount = $line['gst_rate'] === null ? null : $lineSubtotal * $line['gst_rate'] / 100;

                // Every purchase line is batch-tracked, unconditionally: if
                // staff left batch_no blank, one is derived from the GRN
                // number itself (PO2627000012-L1) rather than leaving this
                // purchase untraceable at the batch level — see
                // InventoryBatchRepository, which is otherwise opportunistic
                // (only populated when a movement happens to carry one).
                $batchNo = $line['batch_no'] ?? sprintf('%s-L%d', $poNumber, $lineIndex + 1);

                $this->items->create([
                    'purchase_order_id' => $poId,
                    'product_variant_id' => $line['variant_id'],
                    'quantity' => number_format($line['quantity'], 3, '.', ''),
                    'invoiced_quantity' => $line['invoiced_quantity'] === null ? null : number_format($line['invoiced_quantity'], 3, '.', ''),
                    'unit_cost' => number_format($line['unit_cost'], 4, '.', ''),
                    'landing_cost' => number_format($line['landing_cost'], 4, '.', ''),
                    'mrp' => $line['new_mrp'] === null ? null : number_format($line['new_mrp'], 2, '.', ''),
                    'selling_price' => $line['new_selling_price'] === null ? null : number_format($line['new_selling_price'], 2, '.', ''),
                    'gst_rate' => $line['gst_rate'] === null ? null : number_format($line['gst_rate'], 2, '.', ''),
                    'gst_amount' => $gstAmount === null ? null : number_format($gstAmount, 2, '.', ''),
                    'discount_amount' => number_format($line['discount_amount'], 2, '.', ''),
                    'line_subtotal' => number_format($lineSubtotal, 2, '.', ''),
                    'batch_no' => $batchNo,
                    'expiry_date' => $line['expiry_date'],
                ], $request->authUserId());

                // Landing cost — not the raw vendor price — is what reaches
                // inventory: the same, unmodified InventoryService every other
                // inward channel uses, just handed the landed figure so the
                // weighted-average cost reflects what stock actually cost to
                // get on the shelf. mrp/sellingPrice here only ever reach the
                // BATCH record (inventory_batches) — recordMovement() never
                // touches the variant's own live price from this argument.
                $stockAfter = $this->inventory->recordMovement(
                    variantId: $line['variant_id'],
                    warehouseId: (int) $warehouse['id'],
                    movementType: 'inward',
                    quantityDelta: $line['quantity'],
                    unitCost: $line['landing_cost'],
                    referenceType: 'purchase_order',
                    referenceId: $poId,
                    batchNo: $batchNo,
                    expiryDate: $line['expiry_date'],
                    reason: 'Purchase inward ' . $poNumber,
                    performedBy: $request->authUserId(),
                    request: $request,
                    mrp: $line['new_mrp'],
                    sellingPrice: $line['new_selling_price'],
                );

                $averageCostByVariant[$line['variant_id']] = $stockAfter['average_cost'];
            }

            return $poId;
        });

        $this->audit->log(
            entityName: 'purchase_orders',
            entityId: $purchaseOrderId,
            action: 'create',
            newValues: [
                'vendor_uuid' => $vendorUuid,
                'warehouse_uuid' => $warehouseUuid,
                'line_count' => count($resolvedLines),
                'grand_total' => $grandTotal,
            ],
            request: $request,
        );

        // Run after the transaction commits, same reasoning as
        // evaluatePricingImpact() just below: a rejected price update must
        // not undo inventory that has already, correctly, posted.
        $this->applyDeclaredPrices($resolvedLines, $request);

        // A line where the admin explicitly declared mrp/selling_price has
        // already had that price applied above — the rule-based pricing
        // strategy below exists to SUGGEST a price when none was given, not
        // to second-guess one a human just typed in.
        $linesForPricingStrategy = array_values(array_filter(
            $resolvedLines,
            static fn (array $l): bool => $l['new_selling_price'] === null
        ));

        $result = $this->detail((string) $this->orders->findById($purchaseOrderId)['uuid']);
        $result['price_decisions_pending'] = $this->evaluatePricingImpact(
            $linesForPricingStrategy,
            $averageCostByVariant,
            $purchaseOrderId,
            $request,
        );

        return $result;
    }

    /**
     * Pushes each line's explicitly-declared mrp/selling_price straight to
     * the variant — reuses ProductService::updateVariant() rather than
     * writing to product_variants directly, so this goes through the same
     * SKU/coherence validation and audit trail an ordinary catalogue edit
     * does. One bad line must not undo the others or the inventory already
     * posted, so each is applied independently and a failure is logged, not
     * thrown.
     *
     * @param array<int, array<string, mixed>> $resolvedLines
     */
    private function applyDeclaredPrices(array $resolvedLines, Request $request): void
    {
        foreach ($resolvedLines as $line) {
            if ($line['new_mrp'] === null && $line['new_selling_price'] === null) {
                continue;
            }

            $data = [];
            if ($line['new_mrp'] !== null) {
                $data['mrp'] = number_format($line['new_mrp'], 2, '.', '');
            }
            if ($line['new_selling_price'] !== null) {
                $data['selling_price'] = number_format($line['new_selling_price'], 2, '.', '');
            }

            try {
                $this->products->updateVariant($line['variant_uuid'], $data, $request);
            } catch (HttpException $exception) {
                $this->logger->error('Live price update failed after purchase inward', [
                    'variant_uuid' => $line['variant_uuid'],
                    'reason' => $exception->getMessage(),
                ], 'pricing');
            }
        }
    }

    /**
     * One pricing check per line, run after inventory is safely committed —
     * see the class doc comment for why this happens outside the transaction.
     * Returns only the lines that actually need a human decision; 'never'
     * mode returns immediately with none, and 'auto_apply' resolves itself
     * here without ever appearing in the result.
     *
     * @param array<int, array<string, mixed>> $resolvedLines
     * @param array<int, float> $averageCostByVariant Keyed by variant id
     *
     * @return array<int, array<string, mixed>>
     */
    private function evaluatePricingImpact(
        array $resolvedLines,
        array $averageCostByVariant,
        int $purchaseOrderId,
        Request $request,
    ): array {
        $mode = (string) $this->settings->value('inventory_price_change_mode', 'never');

        if ($mode === 'never') {
            return [];
        }

        $pending = [];

        foreach ($resolvedLines as $line) {
            $averageCost = (float) ($averageCostByVariant[$line['variant_id']] ?? $line['unit_cost']);

            if ($mode === 'auto_apply') {
                try {
                    $this->pricing->applyDecision(
                        variantId: $line['variant_id'],
                        decision: 'auto_apply',
                        manualPrice: null,
                        purchasePrice: $line['unit_cost'],
                        averageCost: $averageCost,
                        referenceType: 'purchase_order',
                        referenceId: $purchaseOrderId,
                        reason: 'Automatic pricing rule applied on purchase inward',
                        performedBy: $request->authUserId(),
                        request: $request,
                    );
                } catch (HttpException $exception) {
                    // A pricing problem (e.g. the rule's result would exceed
                    // MRP) must not undo inventory that already, correctly,
                    // posted. Logged, not thrown.
                    $this->logger->error('Auto-apply pricing failed after purchase inward', [
                        'variant_id' => $line['variant_id'],
                        'purchase_order_id' => $purchaseOrderId,
                        'reason' => $exception->getMessage(),
                    ], 'pricing');
                }

                continue;
            }

            $impact = $this->pricing->evaluatePurchaseImpact(
                variantId: $line['variant_id'],
                purchasePrice: $line['unit_cost'],
                averageCost: $averageCost,
                currentSellingPrice: $line['selling_price'],
                mode: $mode,
            );

            if ($impact['should_prompt']) {
                $pending[] = array_merge($impact, [
                    'variant_uuid' => $line['variant_uuid'],
                    'sku' => $line['sku'],
                ]);
            }
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, array $params): array
    {
        return $this->orders->search($filters, $params);
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        $order = $this->orders->detailByUuid($uuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        $order['items'] = array_map(function (array $item) use ($order): array {
            // "Was any of this line later damaged or lost" — resolved by
            // batch_no, the same best-effort match the Damage & Loss report
            // uses (InventoryService::lossEventsForBatch()). A line with no
            // batch_no was never batch-tracked, so it's simply untraceable
            // here, not an empty-but-wrong answer.
            $item['loss_events'] = $item['batch_no'] !== null
                ? $this->inventory->lossEventsForBatch((int) $item['product_variant_id'], (int) $order['warehouse_id'], (string) $item['batch_no'])
                : [];

            return $item;
        }, $this->items->forOrder((int) $order['id']));

        $order['movements'] = $this->db->select(
            "SELECT * FROM inventory_movements WHERE reference_type = 'purchase_order' AND reference_id = ?
              ORDER BY id ASC",
            [(int) $order['id']]
        );

        $order['payments_list'] = $this->paymentsLedger->forOrder((int) $order['id']);
        $order['returns_list'] = $this->returnsLedger->search(
            ['purchase_order_uuid' => $uuid],
            ['page' => 1, 'per_page' => 100, 'offset' => 0, 'sort' => 'return_date', 'direction' => 'DESC']
        )['items'];

        return $order;
    }

    /**
     * Records how much of this purchase order has actually been paid.
     * payment_status is never accepted from a caller — it is derived here,
     * the same way it was derived at create() — so the two can never drift
     * apart into "marked Paid but amount_paid still reads 0".
     *
     * @return array<string, mixed>
     */
    public function updatePayment(string $uuid, float $amountPaid, Request $request): array
    {
        $order = $this->orders->findByUuid($uuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        $grandTotal = (float) $order['grand_total'];

        if ($amountPaid < 0 || $amountPaid > $grandTotal + 0.005) {
            throw new HttpException(
                'Amount paid must be between 0 and the purchase order total.',
                422,
                ['amount_paid' => ['Must not be negative or exceed the grand total.']]
            );
        }

        $paymentStatus = self::derivePaymentStatus($amountPaid, $grandTotal);

        $this->orders->updatePayment(
            (int) $order['id'],
            $paymentStatus,
            number_format($amountPaid, 2, '.', ''),
            $request->authUserId(),
        );

        $this->audit->log(
            entityName: 'purchase_orders',
            entityId: (int) $order['id'],
            action: 'update_payment',
            oldValues: ['payment_status' => $order['payment_status'], 'amount_paid' => $order['amount_paid']],
            newValues: ['payment_status' => $paymentStatus, 'amount_paid' => $amountPaid],
            request: $request,
        );

        return $this->detail($uuid);
    }

    /**
     * Corrects one line's quantity/unit_cost/batch_no/expiry_date after the
     * purchase order has already been recorded and posted to inventory.
     *
     * What this does NOT do: reconstruct history. It reverses the line's
     * original inventory effect (a 'return' movement for -oldQuantity — a
     * negative delta never touches average_cost, see
     * InventoryService::recalculateAverageCost()) and posts the corrected
     * line as a fresh 'inward' movement, blended against whatever the
     * average cost happens to be *right now* — not "what it would have
     * been had this been entered correctly the first time," which isn't
     * reconstructable once other sales/purchases have happened since. This
     * is the same honesty every other correction in this system already
     * has (POS void/refund, inventory adjustments); it is simply applied to
     * a purchase-order line for the first time here.
     *
     * The PO's transport_percent/transport_included_in_cost are read as
     * already stored (from creation time), not recomputed across every
     * other line in the order — only the edited line's own landing_cost
     * changes. Recomputing transport% PO-wide on every edit would ripple
     * into every sibling line's average-cost effect too, which is a much
     * larger blast radius than "fix this one line" calls for.
     *
     * @param array<string, mixed> $changes quantity?, unit_cost?, batch_no?, expiry_date?
     *
     * @return array<string, mixed>
     */
    public function updateItem(string $poUuid, string $itemUuid, array $changes, Request $request): array
    {
        $order = $this->orders->findByUuid($poUuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        $item = $this->items->findByUuid($itemUuid);

        if ($item === null || (int) $item['purchase_order_id'] !== (int) $order['id']) {
            throw new NotFoundException('That line does not belong to this purchase order.');
        }

        $oldQuantity = (float) $item['quantity'];
        $oldUnitCost = (float) $item['unit_cost'];
        $oldBatchNo = $item['batch_no'];

        $newQuantity = array_key_exists('quantity', $changes) ? (float) $changes['quantity'] : $oldQuantity;
        $newUnitCost = array_key_exists('unit_cost', $changes) ? (float) $changes['unit_cost'] : $oldUnitCost;
        $newBatchNo = array_key_exists('batch_no', $changes) ? $changes['batch_no'] : $oldBatchNo;
        $newExpiryDate = array_key_exists('expiry_date', $changes) ? $changes['expiry_date'] : $item['expiry_date'];
        $newInvoicedQuantity = array_key_exists('invoiced_quantity', $changes)
            ? $changes['invoiced_quantity']
            : $item['invoiced_quantity'];

        if ($newQuantity <= 0) {
            throw new HttpException('Quantity must be greater than zero.', 422, ['quantity' => ['Must be positive.']]);
        }

        if ($newUnitCost < 0) {
            throw new HttpException('Unit cost cannot be negative.', 422, ['unit_cost' => ['Must be zero or more.']]);
        }

        $transportIncluded = (bool) $order['transport_included_in_cost'];
        $transportPercent = $order['transport_percent'] !== null ? (float) $order['transport_percent'] : null;
        $newLandingCost = ($transportIncluded || $transportPercent === null)
            ? $newUnitCost
            : $newUnitCost * (1 + $transportPercent / 100);

        $warehouseId = (int) $order['warehouse_id'];
        $variantId = (int) $item['product_variant_id'];

        $this->db->transaction(function () use (
            $order,
            $item,
            $oldQuantity,
            $oldBatchNo,
            $newQuantity,
            $newUnitCost,
            $newLandingCost,
            $newBatchNo,
            $newExpiryDate,
            $newInvoicedQuantity,
            $warehouseId,
            $variantId,
            $request,
        ): void {
            $this->inventory->recordMovement(
                variantId: $variantId,
                warehouseId: $warehouseId,
                movementType: 'return',
                quantityDelta: -$oldQuantity,
                referenceType: 'purchase_order',
                referenceId: (int) $order['id'],
                batchNo: $oldBatchNo,
                reason: 'Correction: reversing original line before edit (' . $order['po_number'] . ')',
                performedBy: $request->authUserId(),
                request: $request,
            );

            $this->inventory->recordMovement(
                variantId: $variantId,
                warehouseId: $warehouseId,
                movementType: 'inward',
                quantityDelta: $newQuantity,
                unitCost: $newLandingCost,
                referenceType: 'purchase_order',
                referenceId: (int) $order['id'],
                batchNo: $newBatchNo,
                expiryDate: $newExpiryDate,
                reason: 'Correction: corrected line (' . $order['po_number'] . ')',
                performedBy: $request->authUserId(),
                request: $request,
            );

            $this->items->update((int) $item['id'], [
                'quantity' => number_format($newQuantity, 3, '.', ''),
                'invoiced_quantity' => $newInvoicedQuantity === null ? null : number_format((float) $newInvoicedQuantity, 3, '.', ''),
                'unit_cost' => number_format($newUnitCost, 4, '.', ''),
                'landing_cost' => number_format($newLandingCost, 4, '.', ''),
                'line_subtotal' => number_format($newQuantity * $newUnitCost, 2, '.', ''),
                'batch_no' => $newBatchNo,
                'expiry_date' => $newExpiryDate,
            ], $request->authUserId());

            $allItems = $this->items->forOrder((int) $order['id']);
            $itemsSubtotal = array_sum(array_map(static fn (array $i): float => (float) $i['line_subtotal'], $allItems));

            $grandTotal = $itemsSubtotal
                - (float) $order['discount_amount']
                + (float) $order['transport_charge']
                + (float) $order['other_charges']
                + (float) $order['tax_amount'];

            // A correction that shrinks the total must not leave amount_paid
            // reading more than what's now owed.
            $amountPaid = min((float) $order['amount_paid'], $grandTotal);
            $paymentStatus = self::derivePaymentStatus($amountPaid, $grandTotal);

            $this->orders->update((int) $order['id'], [
                'items_subtotal' => number_format($itemsSubtotal, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'amount_paid' => number_format($amountPaid, 2, '.', ''),
                'payment_status' => $paymentStatus,
            ], $request->authUserId());
        });

        $this->audit->log(
            entityName: 'purchase_order_items',
            entityId: (int) $item['id'],
            action: 'update_correction',
            oldValues: ['quantity' => $oldQuantity, 'unit_cost' => $oldUnitCost, 'batch_no' => $oldBatchNo],
            newValues: ['quantity' => $newQuantity, 'unit_cost' => $newUnitCost, 'batch_no' => $newBatchNo],
            request: $request,
        );

        return $this->detail($poUuid);
    }

    /**
     * Removes a line from an already-recorded purchase order — the stock it
     * brought in is reversed first, so deleting a mis-entered line never
     * leaves phantom stock behind. Refused once any part of the line has
     * gone back to the vendor (a purchase return references it, and its
     * own quantity math assumes the line still exists) or if it is the
     * order's only line, since a purchase order with nothing on it is not a
     * state the rest of this system expects.
     *
     * @return array<string, mixed>
     */
    public function deleteItem(string $poUuid, string $itemUuid, Request $request): array
    {
        $order = $this->orders->findByUuid($poUuid);

        if ($order === null) {
            throw new NotFoundException('That purchase order does not exist.');
        }

        $item = $this->items->findByUuid($itemUuid);

        if ($item === null || (int) $item['purchase_order_id'] !== (int) $order['id']) {
            throw new NotFoundException('That line does not belong to this purchase order.');
        }

        $siblingCount = count($this->items->forOrder((int) $order['id']));

        if ($siblingCount <= 1) {
            throw new HttpException(
                'A purchase order needs at least one line. Delete the whole purchase order instead, or add a replacement line before removing this one.',
                422
            );
        }

        $alreadyReturned = $this->returnsLedger->returnedQuantityForOrderItem((int) $item['id']);

        if ($alreadyReturned > 0.0005) {
            throw new HttpException(
                'Some of this line has already been returned to the vendor, so it cannot simply be deleted. '
                    . 'Reduce its quantity with "Edit" instead if it was over-recorded.',
                422
            );
        }

        $this->db->transaction(function () use ($order, $item, $request): void {
            $this->inventory->recordMovement(
                variantId: (int) $item['product_variant_id'],
                warehouseId: (int) $order['warehouse_id'],
                movementType: 'return',
                quantityDelta: -(float) $item['quantity'],
                referenceType: 'purchase_order',
                referenceId: (int) $order['id'],
                batchNo: $item['batch_no'],
                reason: 'Line deleted from ' . $order['po_number'],
                performedBy: $request->authUserId(),
                request: $request,
            );

            $this->items->softDelete((int) $item['id'], $request->authUserId());

            $allItems = $this->items->forOrder((int) $order['id']);
            $itemsSubtotal = array_sum(array_map(static fn (array $i): float => (float) $i['line_subtotal'], $allItems));

            $grandTotal = $itemsSubtotal
                - (float) $order['discount_amount']
                + (float) $order['transport_charge']
                + (float) $order['other_charges']
                + (float) $order['tax_amount'];

            // Same rule updateItem() applies: a correction that shrinks the total
            // must not leave amount_paid reading more than what's now owed.
            $amountPaid = min((float) $order['amount_paid'], $grandTotal);
            $paymentStatus = self::derivePaymentStatus($amountPaid, $grandTotal);

            $this->orders->update((int) $order['id'], [
                'items_subtotal' => number_format($itemsSubtotal, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'amount_paid' => number_format($amountPaid, 2, '.', ''),
                'payment_status' => $paymentStatus,
            ], $request->authUserId());
        });

        $this->audit->log(
            entityName: 'purchase_order_items',
            entityId: (int) $item['id'],
            action: 'delete',
            oldValues: [
                'sku' => $item['sku'] ?? null,
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
                'batch_no' => $item['batch_no'],
            ],
            request: $request,
        );

        return $this->detail($poUuid);
    }

    /** Public: VendorPaymentService reuses this so a payment's effect on payment_status is derived identically everywhere, never duplicated. */
    public static function derivePaymentStatus(float $amountPaid, float $grandTotal): string
    {
        if ($amountPaid <= 0.005) {
            return 'unpaid';
        }

        return $amountPaid >= $grandTotal - 0.005 ? 'paid' : 'partial';
    }
}
