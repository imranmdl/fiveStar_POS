<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ApprovalService;

/**
 * Admin Privilege Management item 4: "Sensitive Action Approval" queue.
 */
final class ApprovalController extends BaseController
{
    public function __construct(private readonly ApprovalService $service)
    {
    }

    /** GET /api/v1/admin-privilege/approvals */
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $module = $request->query('module');
        $params = $this->paginationParams($request, 'created_date', 50);

        $result = $this->service->list(
            is_string($status) && $status !== '' ? $status : null,
            is_string($module) && $module !== '' ? $module : null,
            $params
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Approval requests loaded');
    }

    /** GET /api/v1/admin-privilege/approvals/pending-count */
    public function pendingCount(Request $request): Response
    {
        return Response::success(['pending' => $this->service->countPending()], 'Pending count loaded');
    }

    /**
     * POST /api/v1/admin-privilege/approvals
     *
     * A generic submission endpoint any staff member with the relevant
     * module's `.add`/`.edit` permission can call directly, in addition to
     * module-specific ones like InventoryController::requestAdjustmentApproval.
     */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'module' => 'required|string|max:50',
            'action_type' => 'required|string|max:60',
            'title' => 'required|string|max:200',
            'reason' => 'required|string|min:3|max:500',
            'amount' => 'nullable|numeric|min:0',
            'entity_name' => 'nullable|string|max:100',
            'entity_uuid' => 'nullable|uuid',
            'new_values' => 'nullable|array',
        ]);

        $result = $this->service->submit([
            'module' => $data['module'],
            'action_type' => $data['action_type'],
            'title' => $data['title'],
            'reason' => $data['reason'],
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'entity_name' => $data['entity_name'] ?? null,
            'entity_uuid' => $data['entity_uuid'] ?? null,
            'new_values' => $data['new_values'] ?? null,
        ], $request);

        return Response::created($result, 'Approval requested');
    }

    /** POST /api/v1/admin-privilege/approvals/{uuid}/approve */
    public function approve(Request $request): Response
    {
        $data = Validator::make($request->all(), ['note' => 'nullable|string|max:500']);

        $result = $this->service->decide(
            (string) $request->routeParam('uuid'),
            'approved',
            (string) ($data['note'] ?? ''),
            $request
        );

        return Response::success($result, 'Request approved');
    }

    /** POST /api/v1/admin-privilege/approvals/{uuid}/reject */
    public function reject(Request $request): Response
    {
        $data = Validator::make($request->all(), ['note' => 'required|string|min:3|max:500']);

        $result = $this->service->decide(
            (string) $request->routeParam('uuid'),
            'rejected',
            (string) $data['note'],
            $request
        );

        return Response::success($result, 'Request rejected');
    }
}
