<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ImportService;
use App\Services\PurchaseOrderService;

final class PurchaseOrderController extends BaseController
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly ImportService $imports,
    ) {
    }

    /** GET /api/v1/admin/purchase-orders */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'vendor_uuid' => 'nullable|uuid',
            'warehouse_uuid' => 'nullable|uuid',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $params = $this->paginationParams($request, 'purchase_date', 50);
        $result = $this->purchaseOrders->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Purchase orders loaded');
    }

    /** GET /api/v1/admin/purchase-orders/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->purchaseOrders->detail((string) $request->routeParam('uuid')),
            'Purchase order loaded'
        );
    }

    /** POST /api/v1/admin/purchase-orders */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'vendor_uuid' => 'required|uuid',
            'warehouse_uuid' => 'required|uuid',
            'purchase_date' => 'required|date',
            'invoice_reference' => 'nullable|string|max:80',
            'discount_amount' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'transport_included_in_cost' => 'nullable|boolean',
            'transport_charge' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $lines = $this->readLines($request);

        $result = $this->purchaseOrders->create(
            vendorUuid: $data['vendor_uuid'],
            warehouseUuid: $data['warehouse_uuid'],
            purchaseDate: $data['purchase_date'],
            invoiceReference: $data['invoice_reference'] ?? null,
            lines: $lines,
            discountAmount: (float) ($data['discount_amount'] ?? 0),
            otherCharges: (float) ($data['other_charges'] ?? 0),
            transportIncludedInCost: (bool) ($data['transport_included_in_cost'] ?? false),
            transportCharge: (float) ($data['transport_charge'] ?? 0),
            taxAmount: (float) ($data['tax_amount'] ?? 0),
            amountPaid: (float) ($data['amount_paid'] ?? 0),
            notes: $data['notes'] ?? null,
            request: $request,
        );

        return Response::created($result, 'Purchase order recorded');
    }

    /** PATCH /api/v1/admin/purchase-orders/{uuid}/payment */
    public function updatePayment(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'amount_paid' => 'required|numeric|min:0',
        ]);

        return Response::success(
            $this->purchaseOrders->updatePayment(
                (string) $request->routeParam('uuid'),
                (float) $data['amount_paid'],
                $request,
            ),
            'Payment updated'
        );
    }

    /**
     * DELETE /api/v1/admin/purchase-orders/{uuid}/items/{itemUuid}
     * Removes a mis-entered line entirely — see PurchaseOrderService::
     * deleteItem() for the stock reversal and the cases this refuses.
     */
    public function destroyItem(Request $request): Response
    {
        return Response::success(
            $this->purchaseOrders->deleteItem(
                (string) $request->routeParam('uuid'),
                (string) $request->routeParam('itemUuid'),
                $request,
            ),
            'Line deleted'
        );
    }

    /**
     * PATCH /api/v1/admin/purchase-orders/{uuid}/items/{itemUuid}
     * Corrects a line's quantity/unit_cost/batch_no/expiry_date after the
     * purchase order was already recorded — see PurchaseOrderService::
     * updateItem() for what this does and does not reconstruct.
     */
    public function updateItem(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'quantity' => 'nullable|numeric|min:0.001',
            'invoiced_quantity' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'batch_no' => 'nullable|string|max:60',
            'expiry_date' => 'nullable|date',
        ]);

        // Validator::make() sets an omitted `nullable` field to null, which
        // would otherwise read as "clear this value" for quantity/unit_cost
        // — those two must stay untouched when not actually supplied.
        $changes = array_intersect_key($data, $request->all());

        return Response::success(
            $this->purchaseOrders->updateItem(
                (string) $request->routeParam('uuid'),
                (string) $request->routeParam('itemUuid'),
                $changes,
                $request,
            ),
            'Line corrected'
        );
    }

    /**
     * POST /api/v1/admin/purchase-orders/items/parse-csv
     * multipart/form-data: `file`. Resolves each row against items that
     * already exist by SKU or barcode — see ImportService::
     * parsePurchaseOrderItems() for why this deliberately never creates a
     * new item from a spreadsheet row.
     */
    public function parseItemsCsv(Request $request): Response
    {
        if (!isset($request->files['file'])) {
            throw new HttpException('No file was received.', 422, [
                'file' => ['Attach the file as a multipart field named "file".'],
            ]);
        }

        return Response::success(
            $this->imports->parsePurchaseOrderItems($request->files['file']),
            'File parsed'
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function readLines(Request $request): array
    {
        $rows = $request->input('lines');

        if (!is_array($rows) || $rows === []) {
            throw new HttpException('A purchase order needs at least one line.', 422, [
                'lines' => ['Send a `lines` array with at least one entry.'],
            ]);
        }

        if (count($rows) > 100) {
            throw new HttpException('A purchase order can have at most 100 lines.', 422);
        }

        $validated = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new HttpException(sprintf('Line %d is not an object.', $index + 1), 422);
            }

            $validated[] = Validator::make($row, [
                'variant_uuid' => 'required|uuid',
                'quantity' => 'required|numeric|min:0.001',
                'invoiced_quantity' => 'nullable|numeric|min:0',
                'unit_cost' => 'required|numeric|min:0',
                'batch_no' => 'nullable|string|max:60',
                'expiry_date' => 'nullable|date',
                'mrp' => 'nullable|numeric|min:0',
                'selling_price' => 'nullable|numeric|min:0',
                'gst_rate' => 'nullable|numeric|min:0|max:28',
                'discount_amount' => 'nullable|numeric|min:0',
            ]);
        }

        return $validated;
    }
}
