<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\AssignmentRepository;
use App\Repositories\OrderRepository;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\StaffOperationsService;

/**
 * Staff operations: assignment, the executive queue and packing slips.
 */
final class StaffController extends BaseController
{
    public function __construct(
        private readonly StaffOperationsService $operations,
        private readonly StaffRepository $staff,
        private readonly AssignmentRepository $assignments,
        private readonly OrderRepository $orders,
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {
    }

    /** GET /api/v1/staff/queue */
    public function myQueue(Request $request): Response
    {
        return Response::success($this->operations->myQueue($request), 'Queue loaded');
    }

    /** POST /api/v1/staff/assignments/{uuid}/accept */
    public function accept(Request $request): Response
    {
        return Response::success(
            $this->operations->accept($request, (string) $request->routeParam('uuid')),
            'Assignment accepted'
        );
    }

    /** POST /api/v1/staff/assignments/{uuid}/release */
    public function release(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->operations->release($request, (string) $request->routeParam('uuid'), $data['reason']),
            'Assignment released'
        );
    }

    /** POST /api/v1/staff/orders/{uuid}/packing-slip */
    public function packingSlip(Request $request): Response
    {
        return Response::success(
            $this->operations->generatePackingSlip($request, (string) $request->routeParam('uuid')),
            'Packing slip ready'
        );
    }

    /** GET /api/v1/staff/board */
    public function board(Request $request): Response
    {
        // A supervisor sees their own team; an administrator sees everyone.
        $role = $request->authRole();
        $supervisorId = $role === 'supervisor' ? (int) $request->authUserId() : null;

        return Response::success(
            $this->operations->supervisorBoard($request, $supervisorId),
            'Operations board loaded'
        );
    }

    /** POST /api/v1/staff/orders/{uuid}/assign */
    public function assign(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            // Omit to let the planner choose, which is the normal path.
            'assignee_uuid' => 'nullable|uuid',
            'note' => 'nullable|string|max:500',
        ]);

        return Response::created(
            $this->operations->assign(
                $request,
                (string) $request->routeParam('uuid'),
                $data['assignee_uuid'] ?? null,
                $data['note'] ?? null
            ),
            'Order assigned'
        );
    }

    /** POST /api/v1/staff/orders/{uuid}/reassign */
    public function reassign(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'assignee_uuid' => 'required|uuid',
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->operations->reassign(
                $request,
                (string) $request->routeParam('uuid'),
                $data['assignee_uuid'],
                $data['reason']
            ),
            'Order reassigned'
        );
    }

    /** POST /api/v1/staff/assign-pending */
    public function assignPending(Request $request): Response
    {
        $limit = max(1, min(200, (int) $request->input('limit', 50)));

        return Response::success(
            $this->operations->assignPending($request, $limit),
            'Pending orders distributed'
        );
    }

    /** GET /api/v1/staff/orders/{uuid}/assignments */
    public function history(Request $request): Response
    {
        $order = $this->orders->findByUuid((string) $request->routeParam('uuid'));

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        return Response::success([
            'assignments' => array_map(static fn (array $row): array => [
                'uuid' => $row['uuid'],
                'assignee_name' => $row['assignee_name'],
                'employee_code' => $row['employee_code'],
                'status' => $row['status'],
                'method' => $row['assignment_method'],
                'assigned_date' => $row['assigned_date'],
                'completed_date' => $row['completed_date'],
                'released_date' => $row['released_date'],
                'release_reason' => $row['release_reason'],
            ], $this->assignments->historyForOrder((int) $order['id'])),
        ], 'Assignment history loaded');
    }

    // -----------------------------------------------------------------------
    // Staff profile administration
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/staff */
    public function index(Request $request): Response
    {
        return Response::success(
            ['staff' => $this->staff->assignableExecutives()],
            'Staff loaded'
        );
    }

    /** POST /api/v1/admin/staff */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'user_uuid' => 'required|uuid',
            'reports_to_uuid' => 'nullable|uuid',
            'department' => 'nullable|string|max:60',
            'max_concurrent_orders' => 'nullable|int|min:1|max:500',
            'employee_code' => 'nullable|string|max:30',
        ]);

        $user = $this->users->findByUuid($data['user_uuid']);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        if ($this->staff->forUser((int) $user['id']) !== null) {
            throw new HttpException('That user already has a staff profile.', 409);
        }

        $manager = null;

        if (($data['reports_to_uuid'] ?? null) !== null) {
            $manager = $this->users->findByUuid($data['reports_to_uuid']);

            if ($manager === null) {
                throw new NotFoundException('That manager does not exist.');
            }

            if ((int) $manager['id'] === (int) $user['id']) {
                throw new HttpException('Somebody cannot report to themselves.', 422);
            }
        }

        $profileId = $this->staff->create([
            'user_id' => (int) $user['id'],
            'employee_code' => strtoupper($data['employee_code'] ?? $this->staff->nextEmployeeCode()),
            'reports_to_user_id' => $manager === null ? null : (int) $manager['id'],
            'department' => $data['department'] ?? null,
            'max_concurrent_orders' => (int) ($data['max_concurrent_orders'] ?? 25),
            'is_available' => 1,
            'joined_date' => date('Y-m-d'),
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'staff_profiles',
            entityId: $profileId,
            action: 'create',
            newValues: ['user' => $user['full_name']],
            request: $request,
        );

        return Response::created(
            ['staff' => $this->staff->workloadFor((int) $user['id'])],
            'Staff profile created'
        );
    }

    /** PATCH /api/v1/admin/staff/{uuid} */
    public function update(Request $request): Response
    {
        $profile = $this->staff->findByUuid((string) $request->routeParam('uuid'));

        if ($profile === null) {
            throw new NotFoundException('That staff profile does not exist.');
        }

        $data = Validator::make($request->all(), [
            'max_concurrent_orders' => 'nullable|int|min:1|max:500',
            'is_available' => 'nullable|boolean',
            'unavailable_reason' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:60',
        ]);

        $changes = array_intersect_key($data, $request->all());

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        // Marking someone unavailable without a reason leaves a supervisor
        // guessing whether they are on leave or have left.
        if (isset($changes['is_available']) && (int) $changes['is_available'] === 0
            && ($changes['unavailable_reason'] ?? $profile['unavailable_reason']) === null) {
            throw new HttpException(
                'Give a reason when marking someone unavailable.',
                422,
                ['unavailable_reason' => ['A reason is required.']]
            );
        }

        if (isset($changes['is_available']) && (int) $changes['is_available'] === 1) {
            $changes['unavailable_reason'] = null;
            $changes['unavailable_until'] = null;
        }

        $this->staff->update((int) $profile['id'], $changes, $request->authUserId());

        return Response::success(
            ['staff' => $this->staff->workloadFor((int) $profile['user_id'])],
            'Staff profile updated'
        );
    }
}
