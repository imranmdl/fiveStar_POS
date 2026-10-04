<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Barcode;
use App\Repositories\InventoryBatchRepository;
use App\Repositories\InventoryMovementRepository;
use App\Repositories\InventoryStockRepository;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\WarehouseRepository;

/**
 * The single chokepoint for every stock-changing operation. Online sales,
 * POS, mobile inward, CSV import, purchases, returns, damage and manual
 * adjustments all call recordMovement() — directly, or through the
 * convenience wrappers below — and nothing else in the codebase writes to
 * inventory_stock or inventory_movements.
 *
 * Two things this service deliberately does NOT do:
 *
 *  - It never blocks or throws for insufficient stock. inventory_stock.quantity
 *    is allowed to go negative; that is a visibility signal for staff to
 *    investigate, not an error condition, and checkout must never be blocked
 *    by a stock check.
 *
 *  - It never writes to product_variants.selling_price. Purchase price
 *    (inventory_movements.unit_cost), average cost (inventory_stock.average_cost)
 *    and selling price are three different fields; recording an inward
 *    movement recalculates the second but never touches the third.
 */
final class InventoryService
{
    public function __construct(
        private readonly InventoryStockRepository $stock,
        private readonly InventoryMovementRepository $movements,
        private readonly InventoryBatchRepository $batches,
        private readonly WarehouseRepository $warehouses,
        private readonly ProductVariantRepository $variants,
        private readonly PurchaseOrderItemRepository $purchaseOrderItems,
        private readonly ProductRepository $products,
        private readonly ProductMediaRepository $media,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /**
     * Records one stock-changing event and keeps the cached balance in
     * inventory_stock consistent with the append-only ledger in
     * inventory_movements, inside one transaction — the "use transactions so
     * the cached stock and the movement ledger remain consistent" rule.
     *
     * When $unitCost is given alongside a positive $quantityDelta (an inward),
     * the weighted-average cost is recalculated here. That is deliberately
     * part of the generic engine rather than layered on top later: every
     * inward channel needs the same arithmetic, and average_cost must never
     * be confused with unit_cost (this specific inward's price) or with
     * selling_price (untouched by this method).
     *
     * @return array<string, mixed> The resulting inventory_stock row.
     */
    public function recordMovement(
        int $variantId,
        int $warehouseId,
        string $movementType,
        float $quantityDelta,
        ?float $unitCost = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceUuid = null,
        ?string $batchNo = null,
        ?string $expiryDate = null,
        ?string $reason = null,
        ?int $performedBy = null,
        ?Request $request = null,
        ?float $mrp = null,
        ?float $sellingPrice = null,
    ): array {
        return $this->db->transaction(function () use (
            $variantId,
            $warehouseId,
            $movementType,
            $quantityDelta,
            $unitCost,
            $referenceType,
            $referenceId,
            $referenceUuid,
            $batchNo,
            $expiryDate,
            $reason,
            $performedBy,
            $request,
            $mrp,
            $sellingPrice,
        ): array {
            $locked = $this->stock->lockOrCreate($variantId, $warehouseId, $performedBy);
            $quantityBefore = (float) $locked['quantity'];
            $quantityAfter = $quantityBefore + $quantityDelta;

            $averageCostBefore = $locked['average_cost'] !== null ? (float) $locked['average_cost'] : null;
            $averageCostAfter = $averageCostBefore;

            if ($unitCost !== null && $quantityDelta > 0) {
                $averageCostAfter = $this->recalculateAverageCost(
                    $quantityBefore,
                    $averageCostBefore,
                    $quantityDelta,
                    $unitCost
                );
            }

            $this->stock->update((int) $locked['id'], [
                'quantity' => number_format($quantityAfter, 3, '.', ''),
                'average_cost' => $averageCostAfter === null ? null : number_format($averageCostAfter, 4, '.', ''),
            ], $performedBy);

            $movementId = $this->movements->create([
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'movement_type' => $movementType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_uuid' => $referenceUuid,
                'quantity_delta' => number_format($quantityDelta, 3, '.', ''),
                'quantity_after' => number_format($quantityAfter, 3, '.', ''),
                'unit_cost' => $unitCost === null ? null : number_format($unitCost, 4, '.', ''),
                'batch_no' => $batchNo,
                'expiry_date' => $expiryDate,
                'reason' => $reason,
                'performed_by' => $performedBy,
            ], $performedBy);

            if ($batchNo !== null) {
                $this->batches->applyDelta(
                    $variantId,
                    $warehouseId,
                    $batchNo,
                    $quantityDelta,
                    $expiryDate,
                    $unitCost,
                    $performedBy,
                    $mrp,
                    $sellingPrice,
                );
            }

            $this->audit->log(
                entityName: 'inventory_movements',
                entityId: $movementId,
                action: $movementType,
                oldValues: ['quantity' => $quantityBefore, 'average_cost' => $averageCostBefore],
                newValues: [
                    'quantity' => $quantityAfter,
                    'average_cost' => $averageCostAfter,
                    'unit_cost' => $unitCost,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ],
                request: $request,
                notes: $reason,
            );

            return $this->presentStock((array) $this->stock->forVariantAndWarehouse($variantId, $warehouseId));
        });
    }

    /**
     * Deducts stock for a confirmed order, one movement per line, against the
     * default warehouse. Called from the two places an order reaches
     * `confirmed`: PaymentService::transition() and OrderService::approveCod().
     * Never blocks — an insufficient-stock line still deducts and simply
     * drives the balance negative.
     *
     * @param array<int, array<string, mixed>> $orderItems order_items rows (needs variant_id, quantity)
     */
    public function saleDeduction(int $orderId, array $orderItems, ?int $performedBy, ?Request $request = null): void
    {
        if ($orderItems === []) {
            return;
        }

        $warehouseId = $this->defaultWarehouseId();

        foreach ($orderItems as $item) {
            $this->recordMovement(
                variantId: (int) $item['variant_id'],
                warehouseId: $warehouseId,
                movementType: 'sale',
                quantityDelta: -1 * (float) $item['quantity'],
                referenceType: 'order',
                referenceId: $orderId,
                performedBy: $performedBy,
                request: $request,
            );
        }
    }

    /**
     * Restocks a cancelled order. The caller (OrderService::cancel()) is
     * responsible for checking hasSaleForOrder()/hasReturnForOrder() first,
     * so this only ever runs for an order that was actually deducted, and
     * only once — this method itself does not re-check idempotency.
     *
     * @param array<int, array<string, mixed>> $orderItems order_items rows (needs variant_id, quantity)
     */
    public function restock(int $orderId, array $orderItems, ?int $performedBy, ?Request $request = null): void
    {
        if ($orderItems === []) {
            return;
        }

        $warehouseId = $this->defaultWarehouseId();

        foreach ($orderItems as $item) {
            $this->recordMovement(
                variantId: (int) $item['variant_id'],
                warehouseId: $warehouseId,
                movementType: 'return',
                quantityDelta: (float) $item['quantity'],
                referenceType: 'order',
                referenceId: $orderId,
                performedBy: $performedBy,
                request: $request,
            );
        }
    }

    /**
     * Whether an order was actually deducted and has not already been
     * restocked. OrderService::cancel() calls this before restock() so that
     * cancelling an order that never reached `confirmed` (never deducted),
     * or cancelling the same order twice, does not credit stock it never
     * took — restock() itself does not re-check this.
     */
    public function wasDeductedAndNotRestocked(int $orderId): bool
    {
        return $this->movements->hasSaleForOrder($orderId) && !$this->movements->hasReturnForOrder($orderId);
    }

    /**
     * A staff-initiated correction: restock, damage write-off, or a plain
     * count adjustment. Always carries a reason, so the ledger explains
     * itself without cross-referencing a support ticket.
     *
     * @return array<string, mixed>
     */
    public function manualAdjustment(
        int $variantId,
        int $warehouseId,
        float $quantityDelta,
        string $movementType,
        string $reason,
        ?int $performedBy,
        ?Request $request = null,
        ?string $batchNo = null,
        ?string $expiryDate = null,
        ?float $unitCost = null,
    ): array {
        return $this->recordMovement(
            variantId: $variantId,
            warehouseId: $warehouseId,
            movementType: $movementType,
            quantityDelta: $quantityDelta,
            unitCost: $unitCost,
            referenceType: 'manual',
            batchNo: $batchNo,
            expiryDate: $expiryDate,
            reason: $reason,
            performedBy: $performedBy,
            request: $request,
        );
    }

    /**
     * UUID-facing counterpart of manualAdjustment(), for the admin API —
     * ids are never exposed to callers, so the controller only ever has the
     * variant's and warehouse's UUIDs.
     *
     * @return array<string, mixed>
     */
    public function adjustByUuid(
        string $variantUuid,
        string $warehouseUuid,
        float $quantityDelta,
        string $movementType,
        string $reason,
        ?int $performedBy,
        ?Request $request = null,
        ?string $batchNo = null,
        ?string $expiryDate = null,
        ?float $unitCost = null,
    ): array {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $warehouse = $this->warehouses->findByUuid($warehouseUuid);

        if ($warehouse === null) {
            throw new NotFoundException('That warehouse does not exist.');
        }

        return $this->manualAdjustment(
            variantId: (int) $variant['id'],
            warehouseId: (int) $warehouse['id'],
            quantityDelta: $quantityDelta,
            movementType: $movementType,
            reason: $reason,
            performedBy: $performedBy,
            request: $request,
            batchNo: $batchNo,
            expiryDate: $expiryDate,
            unitCost: $unitCost,
        );
    }

    /**
     * Configuration, not a stock-changing event — writes no ledger entry.
     * Pass null to clear the threshold (stop treating this line as low-stock
     * eligible).
     */
    public function setReorderThresholdByUuid(
        string $variantUuid,
        string $warehouseUuid,
        ?float $threshold,
        ?int $performedBy,
        ?Request $request = null,
    ): void {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $warehouse = $this->warehouses->findByUuid($warehouseUuid);

        if ($warehouse === null) {
            throw new NotFoundException('That warehouse does not exist.');
        }

        $this->setReorderThreshold((int) $variant['id'], (int) $warehouse['id'], $threshold, $performedBy, $request, $variantUuid, $warehouseUuid);
    }

    /**
     * Id-based counterpart of setReorderThresholdByUuid(), for internal
     * callers (e.g. ImportService) that already have both ids and would
     * otherwise pay for a uuid round-trip on every row of a large file.
     */
    public function setReorderThreshold(
        int $variantId,
        int $warehouseId,
        ?float $threshold,
        ?int $performedBy,
        ?Request $request = null,
        ?string $variantUuid = null,
        ?string $warehouseUuid = null,
    ): void {
        $this->stock->setReorderThreshold($variantId, $warehouseId, $threshold, $performedBy);

        $this->audit->log(
            entityName: 'inventory_stock',
            entityId: $variantId,
            action: 'set_reorder_threshold',
            newValues: ['warehouse_uuid' => $warehouseUuid, 'reorder_threshold' => $threshold],
            request: $request,
            entityUuid: $variantUuid,
        );
    }

    public function defaultWarehouseId(): int
    {
        $default = $this->warehouses->findDefault();

        if ($default === null) {
            throw new \RuntimeException(
                'No default warehouse is configured. An administrator must mark one warehouse as default.'
            );
        }

        return (int) $default['id'];
    }

    // -----------------------------------------------------------------------
    // Reads. Thin delegation is fine here — the same shape ReportingService
    // uses — because there is no business rule to apply beyond the filters
    // the caller already specified. Numeric fields are always cast explicitly
    // before leaving this service, the same discipline ReportingService
    // applies, rather than left as raw DECIMAL strings from the database.
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function stockForVariant(int $variantId): array
    {
        return array_map([$this, 'presentStock'], $this->stock->forVariant($variantId));
    }

    /**
     * Free-text fallback for the POS till when a scanner isn't available —
     * matches product name, pack name or SKU, so a cashier can type "red
     * chilli" and pick the right pack size one item at a time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchVariants(string $query): array
    {
        return $this->variants->searchByName($query);
    }

    /**
     * Resolves a typed SKU or a scanned barcode to its variant plus current
     * stock — used by the purchase-inward form and the mobile scan workflow
     * so staff can confirm what they're handling before committing a line.
     *
     * @return array<string, mixed>|null
     */
    public function lookupVariantBySku(string $sku): ?array
    {
        $variant = $this->variants->findByCode($sku);

        if ($variant === null) {
            return null;
        }

        $variant['stock'] = $this->stockForVariant((int) $variant['id']);

        return $variant;
    }

    /**
     * Ensures a variant has a barcode — generating and saving an internal
     * one if it doesn't — and returns it either way, so the caller (the
     * purchase-inward "print barcode" action) can always get something
     * printable in one call, whether the variant already had a real one or
     * needed one minted just now.
     *
     * @return array<string, mixed>
     */
    public function assignBarcode(string $variantUuid, Request $request): array
    {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        if (!empty($variant['barcode'])) {
            // findByUuid() reads only `product_variants` — no product name.
            // findBySku() joins the product in, which is what the caller
            // (the barcode-generator screen) actually needs to print a
            // label with the item's name on it, not just its raw row.
            return (array) $this->variants->findBySku((string) $variant['sku']);
        }

        do {
            $barcode = Barcode::generateEan13();
        } while ($this->variants->barcodeExists($barcode));

        $this->variants->update((int) $variant['id'], ['barcode' => $barcode], $request->authUserId());

        $this->audit->log(
            entityName: 'product_variants',
            entityId: (int) $variant['id'],
            action: 'assign_barcode',
            newValues: ['barcode' => $barcode],
            request: $request,
            entityUuid: $variantUuid
        );

        return (array) $this->variants->findBySku((string) $variant['sku']);
    }

    /** @return array<int, array<string, mixed>>|null null when the variant does not exist */
    public function stockForVariantUuid(string $variantUuid): ?array
    {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            return null;
        }

        return $this->stockForVariant((int) $variant['id']);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function searchStock(array $filters, array $params): array
    {
        $result = $this->stock->search($filters, $params);
        $result['items'] = array_map([$this, 'presentStock'], $result['items']);

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function lowStock(): array
    {
        return array_map([$this, 'presentStock'], $this->stock->lowStock());
    }

    /**
     * The unique-key suffix ProductService::delete() appends to
     * product_code/slug/sku ("~d{id}") to free the original value up while
     * the row is soft-deleted. Stripped back off wherever a deleted row is
     * shown or restored, so the Recycle Bin never displays the mangled form
     * and a restored item gets its real code back.
     */
    private function stripDeleteSuffix(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/~d\d+$/', '', $value);
    }

    /**
     * Deleted pack sizes for the Inventory admin's Recycle Bin tab — see
     * InventoryStockRepository::searchDeletedVariants() for the query. Only
     * presentation is added here: the SKU is shown with the delete-time
     * suffix stripped, the same way restoreDeletedVariant() reverses it for
     * real.
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function deletedVariants(array $filters, array $params): array
    {
        $result = $this->stock->searchDeletedVariants($filters, $params);

        $result['items'] = array_map(function (array $row): array {
            $row['sku'] = $this->stripDeleteSuffix($row['sku']);

            return $row;
        }, $result['items']);

        return $result;
    }

    /**
     * Restores one Recycle Bin row by variant uuid.
     *
     * If the variant's own product was ALSO deleted — the normal case,
     * since ProductService::delete() cascades product + every variant +
     * product_media together — the whole family is restored together and
     * the unique-key suffixes that delete applied to product_code/slug/sku
     * are stripped back off every one of them, not just the row asked for;
     * restoring only one variant while leaving its parent product (and
     * siblings) deleted would produce a pack size that is active on a
     * product that still doesn't exist. If only this one pack size was
     * removed on its own (ProductService::deleteVariant(), product left
     * active), just this row is restored — there is nothing else to undo.
     *
     * The barcode ProductService::delete() clears on the way out is NOT
     * recoverable — it was set to NULL outright, not renamed, so there is
     * nothing left to strip back. A restored item simply has no barcode
     * until one is reassigned via the usual Assign Barcode action.
     *
     * @return array{variant_uuid:string, restored_with_product:bool}
     */
    public function restoreDeletedVariant(string $variantUuid, Request $request): array
    {
        $variant = $this->variants->findByUuid($variantUuid, withTrashed: true);

        if ($variant === null || (int) $variant['is_deleted'] !== 1) {
            throw new NotFoundException('That item is not in the recycle bin.');
        }

        $actorId = $request->authUserId();
        $product = $this->products->findById((int) $variant['product_id'], withTrashed: true);
        $restoredWithProduct = $product !== null && (int) $product['is_deleted'] === 1;

        $this->db->transaction(function () use ($variant, $product, $restoredWithProduct, $actorId): void {
            if ($restoredWithProduct) {
                $this->db->execute(
                    'UPDATE `products` SET `product_code` = :code, `slug` = :slug WHERE `id` = :id',
                    [
                        'code' => $this->stripDeleteSuffix($product['product_code']),
                        'slug' => $this->stripDeleteSuffix($product['slug']),
                        'id' => $product['id'],
                    ]
                );
                $this->products->restore((int) $product['id'], $actorId);

                $siblings = $this->db->select(
                    'SELECT `id`, `sku` FROM `product_variants` WHERE `product_id` = :product AND `is_deleted` = 1',
                    ['product' => $product['id']]
                );

                foreach ($siblings as $sibling) {
                    $this->db->execute(
                        'UPDATE `product_variants` SET `sku` = :sku,
                                `is_deleted` = 0, `is_active` = 1, `deleted_by` = NULL, `deleted_date` = NULL,
                                `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                          WHERE `id` = :id',
                        ['sku' => $this->stripDeleteSuffix($sibling['sku']), 'actor' => $actorId, 'id' => $sibling['id']]
                    );
                }

                $this->db->execute(
                    'UPDATE `product_media` SET `is_deleted` = 0, `is_active` = 1, `deleted_by` = NULL, `deleted_date` = NULL,
                            `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                      WHERE `product_id` = :product AND `is_deleted` = 1',
                    ['actor' => $actorId, 'product' => $product['id']]
                );
            } else {
                $this->db->execute(
                    'UPDATE `product_variants` SET `sku` = :sku WHERE `id` = :id',
                    ['sku' => $this->stripDeleteSuffix($variant['sku']), 'id' => $variant['id']]
                );
                $this->variants->restore((int) $variant['id'], $actorId);
            }
        });

        $this->audit->log(
            entityName: 'product_variants',
            entityId: (int) $variant['id'],
            action: 'restore',
            newValues: ['sku' => $this->stripDeleteSuffix($variant['sku'])],
            request: $request,
            entityUuid: $variantUuid,
            notes: $restoredWithProduct ? ('Restored together with parent product ' . $product['uuid']) : null,
        );

        return ['variant_uuid' => $variantUuid, 'restored_with_product' => $restoredWithProduct];
    }

    /**
     * Hard-deletes exactly one Recycle Bin row — irreversible, unlike every
     * other delete in this system. inventory_stock/inventory_batches rows
     * for it cascade away automatically (their FK to product_variants is ON
     * DELETE CASCADE — see database/migrations/014_inventory_foundation.sql);
     * anything that still references this variant with a RESTRICT/no-action
     * FK (chiefly order_items, cart_items, past purchase-order lines) blocks
     * the DELETE and is reported back rather than silently ignored — the
     * same "skip and report, never half-succeed silently" rule
     * DataCleanupService::run() uses for its own hard deletes.
     *
     * When this was the last remaining row of a product that was ALSO
     * deleted, the now-empty product (and its media) is purged too, so the
     * Recycle Bin doesn't leave an invisible orphaned product row behind. A
     * product that still has other deleted variants left, or is itself
     * still active (the deleteVariant()-only case), is left alone.
     *
     * @return array{deleted:bool, product_also_purged:bool}
     */
    public function permanentlyDeleteVariant(string $variantUuid, Request $request): array
    {
        $variant = $this->variants->findByUuid($variantUuid, withTrashed: true);

        if ($variant === null || (int) $variant['is_deleted'] !== 1) {
            throw new NotFoundException('That item is not in the recycle bin.');
        }

        $productId = (int) $variant['product_id'];
        $deleted = false;
        $error = null;

        try {
            $deleted = $this->db->execute('DELETE FROM `product_variants` WHERE `id` = :id', ['id' => $variant['id']]) > 0;
        } catch (\PDOException) {
            $error = 'Still referenced by past orders or other records — cannot be permanently deleted.';
        }

        $productAlsoPurged = false;

        if ($deleted) {
            $remaining = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM `product_variants` WHERE `product_id` = :product',
                ['product' => $productId]
            );
            $product = $this->products->findById($productId, withTrashed: true);

            if ($remaining === 0 && $product !== null && (int) $product['is_deleted'] === 1) {
                try {
                    $this->db->execute('DELETE FROM `product_media` WHERE `product_id` = :product', ['product' => $productId]);
                    $this->db->execute('DELETE FROM `products` WHERE `id` = :product', ['product' => $productId]);
                    $productAlsoPurged = true;
                } catch (\PDOException) {
                    // Leave the now-childless product row in place rather than fail the whole
                    // call — the variant the admin actually asked to purge is already gone.
                }
            }
        }

        $this->audit->log(
            entityName: 'product_variants',
            entityId: (int) $variant['id'],
            action: 'hard_delete',
            oldValues: ['sku' => $this->stripDeleteSuffix($variant['sku']), 'variant_name' => $variant['variant_name']],
            request: $request,
            entityUuid: $variantUuid,
            notes: $error,
        );

        if (!$deleted) {
            throw new HttpException($error ?? 'Could not be permanently deleted.', 409);
        }

        return ['deleted' => true, 'product_also_purged' => $productAlsoPurged];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function movementLedger(array $filters, array $params): array
    {
        $result = $this->movements->search($filters, $params);
        $result['items'] = array_map([$this, 'presentMovement'], $result['items']);

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function batchesForVariant(int $variantId, ?int $warehouseId = null): array
    {
        return $this->batches->forVariant($variantId, $warehouseId);
    }

    /** @return array<int, array<string, mixed>> */
    public function purchaseHistoryForVariant(int $variantId): array
    {
        return array_map(static function (array $row): array {
            $row['quantity'] = (float) $row['quantity'];
            $row['unit_cost'] = (float) $row['unit_cost'];
            $row['landing_cost'] = (float) $row['landing_cost'];

            return $row;
        }, $this->purchaseOrderItems->purchaseHistoryForVariant($variantId));
    }

    /**
     * The "trace this item" drill-down: current stock per warehouse, the
     * opportunistic batch breakdown (only ever populated when a movement
     * carried a batch_no), and the always-available purchase history — the
     * second is the fallback that makes "new vs old" visible even for stock
     * nobody ever batch-tracked.
     *
     * @return array<string, mixed>|null null when the variant does not exist
     */
    public function stockDetailForVariantUuid(string $variantUuid): ?array
    {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            return null;
        }

        $variantId = (int) $variant['id'];
        $warehouseRows = $this->stockForVariant($variantId);

        return [
            'warehouses' => $warehouseRows,
            'total_quantity' => array_sum(array_map(static fn (array $r): float => $r['quantity'], $warehouseRows)),
            'batches' => $this->batchesForVariant($variantId),
            'purchase_history' => $this->purchaseHistoryForVariant($variantId),
        ];
    }

    /**
     * Every damage/loss movement recorded against one exact batch — what
     * PurchaseOrderService::detail() attaches to a line so a purchase
     * order's own screen can show "2 units of this were marked lost on
     * <date>", not just the Damage & Loss report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lossEventsForBatch(int $variantId, int $warehouseId, string $batchNo): array
    {
        return array_map([$this, 'presentMovement'], $this->movements->forBatch($variantId, $warehouseId, $batchNo, ['damage', 'lost']));
    }

    /**
     * Resolves which vendor/PO a set of (variant, batch_no) pairs came
     * from — shared by damageLossReport() below and
     * PurchaseOrderService::detail(), so the same best-effort batch_no match
     * is used everywhere rather than two slightly different
     * implementations drifting apart. batch_no is free text, not a foreign
     * key, so this is a best-effort string match on (product_variant_id,
     * batch_no) — the same pair inventory_batches itself keys on — not a
     * guarantee; a pair with no match (or no batch_no at all) is simply
     * untraceable, which is the honest state when staff don't record one.
     *
     * @param array<int, array{product_variant_id:int, batch_no:?string}> $pairs
     *
     * @return array<string, array<string, mixed>> keyed "variantId|batchNo"
     */
    public function resolveBatchSources(array $pairs): array
    {
        $variantIds = array_values(array_unique(array_map(
            static fn (array $p): int => $p['product_variant_id'],
            array_filter($pairs, static fn (array $p): bool => !empty($p['batch_no']))
        )));

        if ($variantIds === []) {
            return [];
        }

        $map = [];

        foreach ($this->purchaseOrderItems->findBatchSourcesForVariants($variantIds) as $source) {
            $key = $source['product_variant_id'] . '|' . $source['batch_no'];

            // The most recent PO wins when the same batch_no string was
            // (mis)used more than once — recency is the best available
            // tiebreaker for a column that isn't actually unique.
            if (!isset($map[$key])) {
                $map[$key] = $source;
            }
        }

        return $map;
    }

    /**
     * Damage & loss, as a report rather than a raw filtered ledger view:
     * every 'damage'/'lost' movement in range, each resolved to its
     * originating vendor/PO where the batch_no trail allows it, plus a
     * value-lost estimate and a summary. Value-lost uses the movement's own
     * unit_cost when staff entered one at adjustment time, else falls back
     * to the variant's CURRENT average_cost for that warehouse — this
     * system never snapshots average_cost per movement (only inward
     * movements change it), so the fallback is an approximation, not an
     * exact historical figure, and is reported as such.
     *
     * @param array<string, mixed> $filters from/to/warehouse_uuid/sku
     *
     * @return array<string, mixed>
     */
    public function damageLossReport(array $filters): array
    {
        $filters['movement_type'] = ['damage', 'lost'];
        $params = ['page' => 1, 'per_page' => 500, 'offset' => 0, 'sort' => 'created_date', 'direction' => 'DESC'];

        $result = $this->movements->search($filters, $params);
        $rows = array_map([$this, 'presentMovement'], $result['items']);

        $sources = $this->resolveBatchSources(array_map(
            static fn (array $r): array => ['product_variant_id' => $r['product_variant_id'], 'batch_no' => $r['batch_no']],
            $rows
        ));

        $averageCostCache = [];
        $totalQuantity = 0.0;
        $totalValue = 0.0;
        $damageCount = 0;
        $lostCount = 0;

        $rows = array_map(function (array $row) use ($sources, &$averageCostCache, &$totalQuantity, &$totalValue, &$damageCount, &$lostCount): array {
            $quantity = abs($row['quantity_delta']);
            $valueSource = 'recorded';
            $unitValue = $row['unit_cost'];

            if ($unitValue === null) {
                $cacheKey = $row['product_variant_id'] . '|' . $row['warehouse_id'];

                if (!array_key_exists($cacheKey, $averageCostCache)) {
                    $stockRow = $this->stock->forVariantAndWarehouse((int) $row['product_variant_id'], (int) $row['warehouse_id']);
                    $averageCostCache[$cacheKey] = $stockRow !== null && $stockRow['average_cost'] !== null
                        ? (float) $stockRow['average_cost']
                        : null;
                }

                $unitValue = $averageCostCache[$cacheKey];
                $valueSource = 'current_average_cost';
            }

            $lineValue = $unitValue === null ? null : $unitValue * $quantity;

            $totalQuantity += $quantity;
            $totalValue += $lineValue ?? 0.0;

            if ($row['movement_type'] === 'damage') {
                ++$damageCount;
            } else {
                ++$lostCount;
            }

            $key = $row['product_variant_id'] . '|' . $row['batch_no'];
            $source = $row['batch_no'] !== null ? ($sources[$key] ?? null) : null;

            $row['quantity'] = $quantity;
            $row['unit_value'] = $unitValue;
            $row['value_source'] = $valueSource;
            $row['line_value'] = $lineValue;
            $row['vendor_name'] = $source['vendor_name'] ?? null;
            $row['po_number'] = $source['po_number'] ?? null;
            $row['purchase_order_uuid'] = $source['purchase_order_uuid'] ?? null;

            return $row;
        }, $rows);

        return [
            'rows' => $rows,
            'summary' => [
                'incident_count' => count($rows),
                'damage_count' => $damageCount,
                'lost_count' => $lostCount,
                'total_quantity' => $totalQuantity,
                'total_value' => round($totalValue, 2),
            ],
        ];
    }

    /**
     * Batches with a declared expiry, for the Inventory admin's Expiry tab.
     * 'expiring_soon' is a fixed 15-day window; anything already past its
     * date is 'expired'. Value falls back to the variant's current average
     * cost when the batch itself never recorded a unit_cost — same
     * approximation damageLossReport() already makes, for the same reason:
     * an itemised report should show a number rather than a blank.
     *
     * @param array<string, mixed> $filters status (all|expiring_soon|expired), sku, warehouse_uuid
     *
     * @return array<string, mixed>
     */
    public function expiryReport(array $filters): array
    {
        $status = in_array($filters['status'] ?? null, ['expiring_soon', 'expired'], true)
            ? $filters['status']
            : null;

        $rows = $this->batches->withExpiry($status, $filters);

        $averageCostCache = [];
        $expiringSoonCount = 0;
        $expiredCount = 0;
        $totalValue = 0.0;

        $rows = array_map(function (array $row) use (&$averageCostCache, &$expiringSoonCount, &$expiredCount, &$totalValue): array {
            $daysRemaining = (int) $row['days_remaining'];
            $row['status'] = $daysRemaining < 0 ? 'expired' : ($daysRemaining <= 15 ? 'expiring_soon' : 'ok');

            if ($row['status'] === 'expiring_soon') {
                ++$expiringSoonCount;
            } elseif ($row['status'] === 'expired') {
                ++$expiredCount;
            }

            $unitValue = $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null;

            if ($unitValue === null) {
                $cacheKey = $row['product_variant_id'] . '|' . $row['warehouse_id'];

                if (!array_key_exists($cacheKey, $averageCostCache)) {
                    $stockRow = $this->stock->forVariantAndWarehouse((int) $row['product_variant_id'], (int) $row['warehouse_id']);
                    $averageCostCache[$cacheKey] = $stockRow !== null && $stockRow['average_cost'] !== null
                        ? (float) $stockRow['average_cost']
                        : null;
                }

                $unitValue = $averageCostCache[$cacheKey];
            }

            $row['quantity'] = (float) $row['quantity'];
            $row['unit_value'] = $unitValue;
            $row['line_value'] = $unitValue === null ? null : $unitValue * $row['quantity'];
            $totalValue += $row['line_value'] ?? 0.0;

            return $row;
        }, $rows);

        return [
            'items' => $rows,
            'summary' => [
                'expiring_soon_count' => $expiringSoonCount,
                'expired_count' => $expiredCount,
                'total_value' => round($totalValue, 2),
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentStock(array $row): array
    {
        $row['quantity'] = (float) $row['quantity'];
        $row['reorder_threshold'] = $row['reorder_threshold'] !== null ? (float) $row['reorder_threshold'] : null;
        $row['average_cost'] = $row['average_cost'] !== null ? (float) $row['average_cost'] : null;

        if (array_key_exists('selling_price', $row)) {
            $row['selling_price'] = $row['selling_price'] !== null ? (float) $row['selling_price'] : null;
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentMovement(array $row): array
    {
        $row['quantity_delta'] = (float) $row['quantity_delta'];
        $row['quantity_after'] = (float) $row['quantity_after'];
        $row['unit_cost'] = $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null;

        return $row;
    }

    /**
     * The weighted-average purchase cost, blended with whatever is already
     * on the books. Prior stock at zero or below contributes nothing to the
     * blend (there is no meaningful "average cost of zero units"), matching
     * the brief's own worked example: 100 units @ Rs.400 already in stock,
     * 50 more inwarded @ Rs.450, gives (100*400 + 50*450) / 150 = Rs.416.67.
     */
    private function recalculateAverageCost(
        float $priorQuantity,
        ?float $priorAverageCost,
        float $inwardQuantity,
        float $unitCost,
    ): float {
        $priorValue = ($priorQuantity > 0 && $priorAverageCost !== null)
            ? $priorQuantity * $priorAverageCost
            : 0.0;
        $priorQuantityForBlend = $priorQuantity > 0 ? $priorQuantity : 0.0;

        $totalQuantity = $priorQuantityForBlend + $inwardQuantity;
        $totalValue = $priorValue + ($inwardQuantity * $unitCost);

        if ($totalQuantity <= 0.0) {
            return $unitCost;
        }

        return round($totalValue / $totalQuantity, 4, PHP_ROUND_HALF_UP);
    }
}
