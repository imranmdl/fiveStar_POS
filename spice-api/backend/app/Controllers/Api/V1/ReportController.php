<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReportingService;

/**
 * Dashboards and reports. Staff only, and read-only throughout.
 */
final class ReportController extends BaseController
{
    public function __construct(private readonly ReportingService $reports)
    {
    }

    /** GET /api/v1/admin/dashboard */
    public function dashboard(Request $request): Response
    {
        return Response::success($this->reports->dashboard(), 'Dashboard loaded');
    }

    /** GET /api/v1/admin/dashboard/collections/{method} */
    public function collectionHistory(Request $request): Response
    {
        $date = $request->query('date');

        return Response::success(
            ['transactions' => $this->reports->collectionHistory(
                (string) $request->routeParam('method'),
                is_string($date) && $date !== '' ? $date : date('Y-m-d')
            )],
            'Collection history loaded'
        );
    }

    /**
     * GET /api/v1/admin/dashboard/today/{kind}
     * Drill-down behind the Dashboard's "Today's Overview" and "Profit &
     * Loss" stat tiles — {kind} is one of sales|delivered|cancelled|
     * collection|profit|loss, matching dashboard()'s own 'today'/'profit_loss'
     * keys so a tile and its drill-down are never out of step with each other.
     */
    public function today(Request $request): Response
    {
        $kind = (string) $request->routeParam('kind');
        $today = date('Y-m-d');

        $items = match ($kind) {
            'sales' => $this->reports->salesToday(),
            'delivered' => $this->reports->deliveredToday(),
            'cancelled' => $this->reports->cancelledToday(),
            'collection' => $this->reports->collectionToday(),
            'profit' => $this->reports->itemProfitLoss($today, $today),
            'loss' => $this->reports->lossToday(),
            default => throw new HttpException('Unknown dashboard drill-down: ' . $kind, 422),
        };

        return Response::success(['items' => $items], 'Details loaded');
    }

    /** GET /api/v1/admin/reports/profit-loss */
    public function profitLoss(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success(
            ['from' => $from, 'to' => $to] + $this->reports->profitLoss($from, $to),
            'Profit & loss report loaded'
        );
    }

    /** GET /api/v1/admin/reports/sales */
    public function sales(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success(
            ['from' => $from, 'to' => $to, 'series' => $this->reports->salesSeries($from, $to)],
            'Sales report loaded'
        );
    }

    /** GET /api/v1/admin/reports/products */
    public function products(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success(
            ['from' => $from, 'to' => $to, 'products' => $this->reports->topProducts($from, $to)],
            'Product report loaded'
        );
    }

    /** GET /api/v1/admin/reports/customers */
    public function customers(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success([
            'from' => $from,
            'to' => $to,
            'top_customers' => $this->reports->topCustomers($from, $to),
            'growth' => $this->reports->customerGrowth($from, $to),
        ], 'Customer report loaded');
    }

    /** GET /api/v1/admin/reports/promotions */
    public function promotions(Request $request): Response
    {
        return Response::success($this->reports->promotions(), 'Promotion report loaded');
    }

    /** GET /api/v1/admin/reports/operations */
    public function operations(Request $request): Response
    {
        return Response::success($this->reports->operations(), 'Operations report loaded');
    }

    /** GET /api/v1/admin/reports/pos */
    public function pos(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success([
            'from' => $from,
            'to' => $to,
            'by_cashier' => $this->reports->cashierDailyReport($from, $to),
            'by_payment_method' => $this->reports->posPaymentMethodDailyReport($from, $to),
        ], 'POS report loaded');
    }

    /** GET /api/v1/admin/reports/cancellations */
    public function cancellations(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Response::success(
            ['from' => $from, 'to' => $to] + $this->reports->cancellations($from, $to),
            'Cancellation report loaded'
        );
    }

    /**
     * Reads the range, defaulting to the last 30 days.
     *
     * @return array{0:string, 1:string}
     */
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
