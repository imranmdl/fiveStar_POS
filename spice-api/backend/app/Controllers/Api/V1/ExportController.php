<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\ExportService;

final class ExportController extends BaseController
{
    public function __construct(private readonly ExportService $exports)
    {
    }

    /** GET /api/v1/admin/export/{entity} */
    public function export(Request $request): Response
    {
        return $this->asCsvDownload($this->exports->export((string) $request->routeParam('entity')));
    }

    /** GET /api/v1/admin/export/{entity}/template */
    public function template(Request $request): Response
    {
        return $this->asCsvDownload($this->exports->template((string) $request->routeParam('entity')));
    }

    /** @param array{filename:string, csv:string} $result */
    private function asCsvDownload(array $result): Response
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'export_');
        file_put_contents($tmpPath, $result['csv']);

        // register_shutdown_function: the temp file must outlive this method
        // (Response::file() reads it during send(), after this returns) but
        // must not be left behind once the response has gone out.
        register_shutdown_function(static function () use ($tmpPath): void {
            @unlink($tmpPath);
        });

        return Response::file($tmpPath, $result['filename'], 'text/csv');
    }
}
