<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\ApprovalRequestRepository;
use App\Repositories\RoleRepository;

/**
 * Admin Privilege Management item 4: "Sensitive Action Approval" — large
 * discounts, refunds, wallet adjustments, stock adjustments, price changes,
 * customer credit/dues, and payment adjustments all raise a request here
 * instead of (or alongside) whatever their own module already does, and a
 * Super Admin/Administrator/Manager decides it from this panel.
 *
 * Scope note for whoever wires the next module in: this service is the
 * generic queue — submit, list, approve, reject, all fully audited. It does
 * NOT itself reach into CouponService/WalletService/InventoryService/etc. to
 * block their existing write endpoints; those already have their own
 * carefully-reasoned role gates (see routes/api_v1.php's $manager/$administrator
 * groups), and changing seven mature services' control flow to hard-block on
 * this queue is a bigger, riskier change than this pass makes. What's wired
 * end-to-end as the reference example is inventory stock adjustment
 * (see InventoryController::requestAdjustmentApproval) — copy that pattern
 * for the remaining six action types when each module owner is ready.
 */
final class ApprovalService
{
    public function __construct(
        private readonly ApprovalRequestRepository $requests,
        private readonly RoleRepository $roles,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array{module:string, action_type:string, entity_name?:?string, entity_id?:?int, entity_uuid?:?string, title:string, reason:string, old_values?:?array<string,mixed>, new_values?:?array<string,mixed>, amount?:?float} $data
     *
     * @return array<string, mixed>
     */
    public function submit(array $data, Request $request): array
    {
        $requestedBy = (int) $request->authUserId();

        $id = $this->requests->create([
            'module' => $data['module'],
            'action_type' => $data['action_type'],
            'entity_name' => $data['entity_name'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'entity_uuid' => $data['entity_uuid'] ?? null,
            'title' => $data['title'],
            'reason' => $data['reason'],
            'old_values' => isset($data['old_values']) ? json_encode($data['old_values'], JSON_UNESCAPED_UNICODE) : null,
            'new_values' => isset($data['new_values']) ? json_encode($data['new_values'], JSON_UNESCAPED_UNICODE) : null,
            'amount' => $data['amount'] ?? null,
            'status' => 'pending',
            'requested_by_user_id' => $requestedBy,
        ], $requestedBy);

        $this->audit->log(
            entityName: 'approval_requests',
            entityId: $id,
            action: 'submitted',
            newValues: ['module' => $data['module'], 'action_type' => $data['action_type'], 'title' => $data['title']],
            request: $request,
            notes: $data['reason'],
        );

        return $this->show($id);
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(?string $status, ?string $module, array $params): array
    {
        return $this->requests->paginate($status, $module, $params);
    }

    public function countPending(): int
    {
        return $this->requests->countPending();
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        $row = $this->requests->findById($id);

        if ($row === null) {
            throw new NotFoundException('That approval request does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function decide(string $uuid, string $decision, string $note, Request $request): array
    {
        $row = $this->requests->findByUuid($uuid);

        if ($row === null) {
            throw new NotFoundException('That approval request does not exist.');
        }

        if ($row['status'] !== 'pending') {
            throw new HttpException('This request has already been decided.', 409);
        }

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException('Decision must be either approved or rejected.', 422);
        }

        // This queue spans every sensitive-action module, so — unlike the
        // rest of the Admin Privilege panel, which is gated per-route by
        // AdminPrivilegeMiddleware — deciding a specific request needs the
        // {module}.approve permission for THIS row's own module, resolved
        // here rather than hard-coded onto the route.
        $roleCode = (string) $request->authRole();
        $requiredPermission = $row['module'] . '.approve';

        if (!$this->roles->roleHasPermission($roleCode, $requiredPermission)) {
            throw new ForbiddenException("You do not have the '{$requiredPermission}' permission to decide this request.");
        }

        $decidedBy = (int) $request->authUserId();

        $this->requests->update((int) $row['id'], [
            'status' => $decision,
            'decided_by_user_id' => $decidedBy,
            'decided_date' => date('Y-m-d H:i:s'),
            'decision_note' => $note !== '' ? $note : null,
        ], $decidedBy);

        $this->audit->log(
            entityName: 'approval_requests',
            entityId: (int) $row['id'],
            action: $decision,
            oldValues: ['status' => 'pending'],
            newValues: ['status' => $decision],
            request: $request,
            notes: $note !== '' ? $note : null,
        );

        return $this->show((int) $row['id']);
    }
}
