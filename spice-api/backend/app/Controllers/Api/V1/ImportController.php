<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ImportService;

final class ImportController extends BaseController
{
    public function __construct(private readonly ImportService $imports)
    {
    }

    /** GET /api/v1/admin/imports */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $result = $this->imports->list($params);

        return $this->paginated($result['items'], $result['total'], $params, 'Import history loaded');
    }

    /** GET /api/v1/admin/imports/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success($this->imports->detail((string) $request->routeParam('uuid')), 'Import batch loaded');
    }

    /**
     * POST /api/v1/admin/imports/preview
     * multipart/form-data: `file`, optional `warehouse_uuid`, optional `column_mapping` (JSON string).
     */
    public function preview(Request $request): Response
    {
        if (!isset($request->files['file'])) {
            throw new HttpException('No file was received.', 422, [
                'file' => ['Attach the file as a multipart field named "file".'],
            ]);
        }

        $data = Validator::make($request->all(), [
            'warehouse_uuid' => 'nullable|uuid',
            'column_mapping' => 'nullable|string',
        ]);

        return Response::success(
            $this->imports->preview($request->files['file'], $this->decodeMapping($data['column_mapping'] ?? null), $data['warehouse_uuid'] ?? null),
            'Preview ready'
        );
    }

    /** POST /api/v1/admin/imports/confirm */
    public function confirm(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'token' => 'required|string',
            'file_type' => 'required|in:csv,xlsx',
            'file_name' => 'required|string|max:255',
            'warehouse_uuid' => 'nullable|uuid',
            'column_mapping' => 'nullable|string',
        ]);

        return Response::success(
            $this->imports->confirm(
                token: $data['token'],
                fileType: $data['file_type'],
                columnMapping: $this->decodeMapping($data['column_mapping'] ?? null),
                warehouseUuid: $data['warehouse_uuid'] ?? null,
                originalFileName: $data['file_name'],
                request: $request,
            ),
            'Import confirmed'
        );
    }

    /**
     * POST /api/v1/admin/inventory/quick-create
     * The brief's "controlled product/barcode mapping or creation workflow"
     * for a barcode the mobile scan workflow doesn't recognise.
     */
    public function quickCreate(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            // Optional — a pack with no barcode on it gets one generated
            // rather than blocking the item from being created at all.
            'barcode' => 'nullable|string|max:64',
            'sku' => 'nullable|string|max:50',
            'category_uuid' => 'required|uuid',
            'product_name' => 'required|string|min:2|max:180',
            'brand' => 'nullable|string|max:120',
            'short_description' => 'nullable|string|max:320',
            'hsn_code' => 'nullable|string|max:15',
            'gst_rate' => 'nullable|numeric|min:0|max:40',
            // Flat (single-variant) fields — the mobile scan form. Each is
            // checked in ImportService::createFromScan, which also accepts a
            // `variants` list (size x colour grid) that the validator does
            // not model, so the raw list is read from the request below.
            'variant_name' => 'nullable|string|max:80',
            'weight_grams' => 'nullable|int|min:0|max:100000',
            'mrp' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'pack_type' => 'nullable|in:pouch,jar,box,tin,gift_box,refill,other',
            'stock_unit_type' => 'nullable|in:weight,quantity',
            'unit_label' => 'nullable|string|max:20',
        ]);

        $variants = $request->all()['variants'] ?? null;
        $options = $request->all()['options'] ?? null;
        $data['variants'] = is_array($variants) ? $variants : null;
        $data['options'] = is_array($options) ? $options : [];

        return Response::created($this->imports->createFromScan($data, $request), 'Pack size created');
    }

    /** @return array<string, string>|null */
    private function decodeMapping(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new HttpException('column_mapping must be a JSON object.', 422, [
                'column_mapping' => ['Expected a JSON object of {field: header text}.'],
            ]);
        }

        return $decoded;
    }
}
