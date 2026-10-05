<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ApprovalService;
use App\Services\InventoryService;

final class InventoryController extends BaseController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ApprovalService $approvals,
    ) {
    }

    /** GET /api/v1/admin/inventory/stock */
    public function stock(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'warehouse_uuid' => 'nullable|uuid',
            'category_slug' => 'nullable|string|max:140',
            'sku' => 'nullable|string|max:50',
            'barcode' => 'nullable|string|max:64',
            'stock_status' => 'nullable|in:low,negative,out,alert',
        ]);

        $params = $this->paginationParams($request, 'quantity', 100);
        $result = $this->inventory->searchStock($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Stock loaded');
    }

    /**
     * GET /api/v1/admin/inventory/stock/{variantUuid}
     * The "trace this item" view: stock per warehouse, the batch breakdown
     * (when batches were tracked) and the full purchase history (always
     * available) — see InventoryService::stockDetailForVariantUuid().
     */
    public function stockForVariant(Request $request): Response
    {
        $detail = $this->inventory->stockDetailForVariantUuid((string) $request->routeParam('variantUuid'));

        if ($detail === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        return Response::success($detail, 'Stock loaded');
    }

    /** GET /api/v1/admin/inventory/lookup?sku= */
    public function lookup(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'sku' => 'required|string|max:50',
        ]);

        $variant = $this->inventory->lookupVariantBySku($data['sku']);

        if ($variant === null) {
            throw new NotFoundException('No item has that barcode or SKU yet. Add it with Mobile Scan or Purchase Inward → New item.');
        }

        return Response::success($variant, 'Pack size found');
    }

    /**
     * GET /api/v1/admin/inventory/search?q=
     * Name/SKU search for a till with no working scanner — the cashier
     * types an item name instead of scanning, and picks from the matches.
     */
    public function search(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:80',
        ]);

        return Response::success(
            $this->inventory->searchVariants($data['q']),
            'Matches loaded'
        );
    }

    /**
     * POST /api/v1/admin/inventory/variants/{variantUuid}/barcode
     * Generates and saves a barcode for this pack size if it doesn't have
     * one yet; returns the existing one unchanged otherwise. Safe to call
     * whenever staff wants to print a label — it always has something to
     * hand back.
     */
    public function assignBarcode(Request $request): Response
    {
        $variant = $this->inventory->assignBarcode((string) $request->routeParam('variantUuid'), $request);

        return Response::success(['variant' => $variant], 'Barcode ready');
    }

    /** GET /api/v1/admin/inventory/low-stock */
    public function lowStock(Request $request): Response
    {
        return Response::success($this->inventory->lowStock(), 'Low-stock items loaded');
    }

    /** GET /api/v1/admin/inventory/deleted — the Recycle Bin tab's list. */
    public function deleted(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'product' => 'nullable|string|max:140',
            'sku' => 'nullable|string|max:50',
            'deleted_from' => 'nullable|date',
            'deleted_to' => 'nullable|date',
        ]);

        $params = $this->paginationParams($request, 'deleted_date', 100);
        $result = $this->inventory->deletedVariants($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Deleted items loaded');
    }

    /** POST /api/v1/admin/inventory/deleted/{variantUuid}/restore */
    public function restoreDeleted(Request $request): Response
    {
        $result = $this->inventory->restoreDeletedVariant((string) $request->routeParam('variantUuid'), $request);

        return Response::success($result, 'Item restored');
    }

    /**
     * DELETE /api/v1/admin/inventory/deleted/{variantUuid}
     * Permanent — see InventoryService::permanentlyDeleteVariant()'s own
     * doc comment for exactly what that means here.
     */
    public function permanentlyDelete(Request $request): Response
    {
        if ($request->input('confirm') !== 'yes' && $request->input('confirm') !== true) {
            throw new HttpException(
                'Confirm before running — this permanently deletes the item and cannot be undone.',
                422,
                ['confirm' => ['Send confirm=yes to proceed.']]
            );
        }

        $result = $this->inventory->permanentlyDeleteVariant((string) $request->routeParam('variantUuid'), $request);

        return Response::success($result, 'Item permanently deleted');
    }

    /** GET /api/v1/admin/inventory/reports/damage-loss */
    public function damageLoss(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'warehouse_uuid' => 'nullable|uuid',
            'sku' => 'nullable|string|max:50',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return Response::success($this->inventory->damageLossReport($filters), 'Damage & loss report loaded');
    }

    /** GET /api/v1/admin/inventory/reports/expiry */
    public function expiry(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'status' => 'nullable|in:all,expiring_soon,expired',
            'warehouse_uuid' => 'nullable|uuid',
            'sku' => 'nullable|string|max:50',
        ]);

        return Response::success($this->inventory->expiryReport($filters), 'Expiry report loaded');
    }

    /** GET /api/v1/admin/inventory/movements */
    public function movements(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'variant_uuid' => 'nullable|uuid',
            'warehouse_uuid' => 'nullable|uuid',
            'movement_type' => 'nullable|in:inward,sale,return,damage,lost,adjustment,transfer_in,transfer_out',
            'batch_no' => 'nullable|string|max:60',
            'sku' => 'nullable|string|max:50',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $params = $this->paginationParams($request, 'created_date', 100);
        $result = $this->inventory->movementLedger($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Movement ledger loaded');
    }

    /** PATCH /api/v1/admin/inventory/reorder-threshold */
    public function setReorderThreshold(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'required|uuid',
            'warehouse_uuid' => 'required|uuid',
            'reorder_threshold' => 'nullable|numeric|min:0',
        ]);

        $this->inventory->setReorderThresholdByUuid(
            variantUuid: $data['variant_uuid'],
            warehouseUuid: $data['warehouse_uuid'],
            threshold: $data['reorder_threshold'] !== null ? (float) $data['reorder_threshold'] : null,
            performedBy: $request->authUserId(),
            request: $request,
        );

        return Response::success([], 'Reorder threshold updated');
    }

    /** POST /api/v1/admin/inventory/adjust */
    public function adjust(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'required|uuid',
            'warehouse_uuid' => 'required|uuid',
            'movement_type' => 'required|in:inward,adjustment,damage,lost,return',
            'quantity_delta' => 'required|numeric',
            'reason' => 'required|string|min:3|max:255',
            'unit_cost' => 'nullable|numeric|min:0',
            'batch_no' => 'nullable|string|max:60',
            'expiry_date' => 'nullable|date',
        ]);

        $result = $this->inventory->adjustByUuid(
            variantUuid: $data['variant_uuid'],
            warehouseUuid: $data['warehouse_uuid'],
            quantityDelta: (float) $data['quantity_delta'],
            movementType: $data['movement_type'],
            reason: $data['reason'],
            performedBy: $request->authUserId(),
            request: $request,
            batchNo: $data['batch_no'] ?? null,
            expiryDate: $data['expiry_date'] ?? null,
            unitCost: isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
        );

        return Response::success($result, 'Stock adjusted');
    }

    /**
     * POST /api/v1/admin/inventory/adjustments/request-approval
     *
     * Admin Privilege Management item 4 ("Sensitive Action Approval": stock
     * adjustments) applied to this module, as a reference example for the
     * other six action types — see ApprovalService's own doc comment.
     *
     * This is additive: it raises a pending row in the approval queue for a
     * Super Admin/Administrator/Manager to review from the Admin Privilege
     * panel. It does not call InventoryService::adjustByUuid() itself and
     * does not change what POST /admin/inventory/adjust above does or who
     * may call it — an approver still performs the actual adjustment
     * through that unchanged endpoint once they decide to approve it.
     */
    public function requestAdjustmentApproval(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'required|uuid',
            'warehouse_uuid' => 'required|uuid',
            'movement_type' => 'required|in:inward,adjustment,damage,lost,return',
            'quantity_delta' => 'required|numeric',
            'reason' => 'required|string|min:3|max:255',
        ]);

        $result = $this->approvals->submit([
            'module' => 'inventory',
            'action_type' => 'stock_adjustment',
            'entity_name' => 'inventory_stock',
            'title' => sprintf('Stock adjustment (%s) requested', $data['movement_type']),
            'reason' => $data['reason'],
            'new_values' => [
                'variant_uuid' => $data['variant_uuid'],
                'warehouse_uuid' => $data['warehouse_uuid'],
                'movement_type' => $data['movement_type'],
                'quantity_delta' => (float) $data['quantity_delta'],
            ],
            'amount' => abs((float) $data['quantity_delta']),
        ], $request);

        return Response::created($result, 'Approval requested');
    }
}
