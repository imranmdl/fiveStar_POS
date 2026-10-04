<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\StockAuditService;

final class StockAuditController extends BaseController
{
    public function __construct(private readonly StockAuditService $audit)
    {
    }

    /** GET /api/v1/admin/reports/stock-audit */
    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $group = (string) ($request->query('group') ?: 'item');

        return Response::success($this->audit->report($from, $to, $group, [
            'vendor_uuid' => $request->query('vendor_uuid'),
            'category_uuid' => $request->query('category_uuid'),
            'search' => $request->query('search'),
        ]), 'Stock audit loaded');
    }

    /** GET /api/v1/admin/reports/stock-audit/movements */
    public function movements(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success(
            $this->audit->movements((string) $request->query('variant_uuid'), $from, $to),
            'Movements loaded'
        );
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            is_string($from) && $from !== '' ? $from : date('Y-m-d', strtotime('-29 days')),
            is_string($to) && $to !== '' ? $to : date('Y-m-d'),
        ];
    }
}
