<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PurchaseCsvService;

final class PurchaseCsvController extends BaseController
{
    public function __construct(private readonly PurchaseCsvService $csv)
    {
    }

    /** POST /api/v1/admin/purchase-orders/items/parse-bill */
    public function parse(Request $request): Response
    {
        $file = $request->files['file'] ?? null;

        if (!is_array($file)) {
            throw new HttpException('Choose a CSV or Excel file.', 422, ['file' => ['Required.']]);
        }

        $mode = (string) ($request->all()['mode'] ?? 'purchase') === 'products' ? 'products' : 'purchase';

        return Response::success($this->csv->parse($file, $mode), 'File reviewed');
    }

    /** GET /api/v1/admin/purchase-orders/items/bill-template */
    public function template(Request $request): Response
    {
        return $this->download($this->csv->toCsv($this->csv->templateRows()), 'purchase-bill-template.csv');
    }

    /** GET /api/v1/admin/purchase-orders/items/bill-sample?rows=30&errors=1 */
    public function sample(Request $request): Response
    {
        $rows = (int) ($request->query('rows') ?: 30);
        $errors = in_array((string) $request->query('errors'), ['1', 'true', 'yes'], true);

        return $this->download(
            $this->csv->toCsv($this->csv->sampleRows($rows, $errors)),
            'purchase-bill-test-' . date('Ymd-His') . '.csv'
        );
    }

    /** POST /api/v1/admin/inventory/quick-create-batch */
    public function createItems(Request $request): Response
    {
        $items = $request->all()['items'] ?? null;

        if (!is_array($items) || $items === []) {
            throw new HttpException('No items to create.', 422, ['items' => ['Required.']]);
        }

        return Response::created(['results' => $this->csv->createItems($items, $request)], 'Items processed');
    }

    private function download(string $csv, string $name): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bill_');
        file_put_contents($tmp, $csv);
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });

        return Response::file($tmp, $name, 'text/csv');
    }
}
