<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AuditLogRepository;

/**
 * Admin Privilege Management item 5: "Audit Log" viewer. Reads the same
 * audit_logs/activity_logs tables every other service in this API already
 * writes to (AuditService, ActivityLogMiddleware) — nothing new is written
 * by this controller, it only exposes a filterable read view of records
 * that already carry Admin/User, Action, Module, Old Value, New Value,
 * Reason, Date & Time and IP/session information.
 */
final class AdminAuditLogController extends BaseController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
        private readonly ActivityLogRepository $activityLogs,
    ) {
    }

    /** GET /api/v1/admin-privilege/audit-logs */
    public function auditLogs(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);

        $filters = [
            'entity_name' => $request->query('entity_name'),
            'action' => $request->query('action'),
            'performed_by_user_id' => $request->query('user_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $result = $this->auditLogs->paginateFiltered($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Audit logs loaded');
    }

    /** GET /api/v1/admin-privilege/activity-logs */
    public function activityLogs(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);

        $filters = [
            'module' => $request->query('module'),
            'user_id' => $request->query('user_id'),
            'status_code' => $request->query('status_code'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $result = $this->activityLogs->paginateFiltered($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Activity logs loaded');
    }
}
