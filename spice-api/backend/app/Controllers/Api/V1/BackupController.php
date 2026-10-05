<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BackupService;
use App\Services\DataCleanupService;
use App\Services\DataResetService;

/**
 * Whole-database backup/restore and the hard-delete cleanup of already
 * soft-deleted rows. Administrator-only throughout — see BackupService and
 * DataCleanupService's own doc comments for why each action is shaped the
 * way it is.
 */
final class BackupController extends BaseController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly DataCleanupService $cleanup,
        private readonly DataResetService $reset,
    ) {
    }

    /** GET /api/v1/admin/backups */
    public function index(): Response
    {
        return Response::success(['backups' => $this->backups->list()], 'Backups loaded');
    }

    /** POST /api/v1/admin/backups */
    public function store(Request $request): Response
    {
        return Response::created(
            ['backup' => $this->backups->create($request)],
            'Backup created'
        );
    }

    /** GET /api/v1/admin/backups/{filename}/download */
    public function download(Request $request): Response
    {
        $filename = (string) $request->routeParam('filename');
        $path = $this->backups->pathFor($filename);

        return Response::file($path, $filename, 'application/sql');
    }

    /** DELETE /api/v1/admin/backups/{filename} */
    public function destroy(Request $request): Response
    {
        $this->backups->delete((string) $request->routeParam('filename'), $request);

        return Response::success([], 'Backup deleted');
    }

    /**
     * POST /api/v1/admin/backups/restore
     * multipart/form-data with a `file` field carrying the .sql to restore.
     */
    public function restore(Request $request): Response
    {
        if (!isset($request->files['file'])) {
            throw new HttpException('No file was received.', 422, [
                'file' => ['Attach the .sql file as a multipart field named "file".'],
            ]);
        }

        if ($request->input('confirm') !== 'yes' && $request->input('confirm') !== true) {
            throw new HttpException(
                'Confirm the restore before it runs — it replaces live data.',
                422,
                ['confirm' => ['Send confirm=yes to proceed.']]
            );
        }

        return Response::success(
            $this->backups->restore($request->files['file'], $request),
            'Restore complete. A safety backup was taken beforehand.'
        );
    }

    /** POST /api/v1/admin/backups/{filename}/restore — restore a backup saved on the server. */
    public function restoreSaved(Request $request): Response
    {
        if ($request->input('confirm') !== 'yes' && $request->input('confirm') !== true) {
            throw new HttpException(
                'Confirm the restore before it runs — it replaces live data.',
                422,
                ['confirm' => ['Send confirm=yes to proceed.']]
            );
        }

        return Response::success(
            $this->backups->restoreSaved((string) $request->routeParam('filename'), $request),
            'Restore complete. A safety backup was taken beforehand.'
        );
    }

    /**
     * GET /api/v1/admin/data-reset/preview?groups=orders,catalogue
     * Row counts per group, and which extra groups a selection pulls in.
     */
    public function resetPreview(Request $request): Response
    {
        return Response::success(
            $this->reset->preview($this->groupList($request->input('groups'))),
            'Preview computed — nothing was deleted'
        );
    }

    /**
     * POST /api/v1/admin/data-reset/run
     * { "groups": ["orders", ...], "confirm": "RESET" }
     */
    public function resetRun(Request $request): Response
    {
        return Response::success(
            $this->reset->run($this->groupList($request->input('groups')), (string) $request->input('confirm', ''), $request),
            'Data reset complete. A full backup was taken first.'
        );
    }

    /** @return array<int, string> */
    private function groupList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return is_array($raw)
            ? array_values(array_filter(array_map(static fn ($g): string => trim((string) $g), $raw)))
            : [];
    }

    /** GET /api/v1/admin/data-cleanup/preview?retention_days=90 */
    public function cleanupPreview(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'retention_days' => 'required|int|min:0|max:3650',
        ]);

        return Response::success(
            $this->cleanup->preview((int) $data['retention_days']),
            'Preview computed — nothing was deleted'
        );
    }

    /**
     * POST /api/v1/admin/data-cleanup/run
     * `selections`, when sent, is `{table: [id, id, ...]}` — exactly which
     * rows to delete, built by the admin reviewing preview()'s full row
     * list and unchecking whatever they want to keep. Left out entirely,
     * this falls back to the original "everything past retention" sweep.
     * Not run through Validator::make() (that's built for flat fields, not
     * an object keyed by arbitrary table names) — sanitised by hand instead,
     * and DataCleanupService::run() re-verifies every id server-side
     * regardless of what's sent here.
     */
    public function cleanupRun(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'retention_days' => 'required|int|min:0|max:3650',
        ]);

        if ($request->input('confirm') !== 'yes' && $request->input('confirm') !== true) {
            throw new HttpException(
                'Confirm before running — this permanently deletes rows.',
                422,
                ['confirm' => ['Send confirm=yes to proceed.']]
            );
        }

        $rawSelections = $request->input('selections');
        $selections = null;

        if (is_array($rawSelections)) {
            $selections = [];

            foreach ($rawSelections as $table => $ids) {
                if (is_string($table) && is_array($ids)) {
                    $selections[$table] = array_values(array_filter($ids, 'is_numeric'));
                }
            }
        }

        return Response::success(
            $this->cleanup->run((int) $data['retention_days'], $request, $selections),
            'Cleanup complete'
        );
    }
}
