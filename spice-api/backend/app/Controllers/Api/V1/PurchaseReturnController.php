<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\PurchaseReturnService;

final class PurchaseReturnController extends BaseController
{
    public function __construct(private readonly PurchaseReturnService $returns)
    {
    }

    /** GET /api/v1/admin/purchase-returns */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'vendor_uuid' => 'nullable|uuid',
            'purchase_order_uuid' => 'nullable|uuid',
        ]);

        $params = $this->paginationParams($request, 'return_date', 50);
        $result = $this->returns->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Purchase returns loaded');
    }

    /** GET /api/v1/admin/purchase-returns/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->returns->detail((string) $request->routeParam('uuid')),
            'Purchase return loaded'
        );
    }

    /** POST /api/v1/admin/purchase-returns */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'purchase_order_uuid' => 'required|uuid',
            'return_date' => 'required|date',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $lines = $this->readLines($request);

        $result = $this->returns->create(
            purchaseOrderUuid: $data['purchase_order_uuid'],
            returnDate: $data['return_date'],
            reason: $data['reason'],
            lines: $lines,
            request: $request,
        );

        return Response::created($result, 'Return recorded');
    }

    /** @return array<int, array<string, mixed>> */
    private function readLines(Request $request): array
    {
        $rows = $request->input('lines');

        if (!is_array($rows) || $rows === []) {
            throw new HttpException('A return needs at least one line.', 422, [
                'lines' => ['Send a `lines` array with at least one entry.'],
            ]);
        }

        if (count($rows) > 100) {
            throw new HttpException('A return can have at most 100 lines.', 422);
        }

        $validated = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new HttpException(sprintf('Line %d is not an object.', $index + 1), 422);
            }

            $validated[] = Validator::make($row, [
                'purchase_order_item_uuid' => 'required|uuid',
                'quantity' => 'required|numeric|min:0.001',
            ]);
        }

        return $validated;
    }
}
