<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CommissionRepository;
use App\Services\CommissionService;

/**
 * Commission: statements, approval and settlement.
 */
final class CommissionController extends BaseController
{
    public function __construct(
        private readonly CommissionService $commissions,
        private readonly CommissionRepository $entries,
    ) {
    }

    /** GET /api/v1/staff/commission — the caller's own statement. */
    public function myStatement(Request $request): Response
    {
        return Response::success($this->commissions->statementFor($request), 'Commission statement loaded');
    }

    /** GET /api/v1/admin/commission/pending */
    public function pending(Request $request): Response
    {
        $supervisorId = $request->authRole() === 'supervisor' ? (int) $request->authUserId() : null;

        return Response::success(
            ['entries' => array_map(static fn (array $entry): array => [
                'uuid' => $entry['uuid'],
                'staff_name' => $entry['full_name'],
                'order_number' => $entry['order_number'],
                'rule_code' => $entry['rule_code'],
                'scope' => $entry['scope'],
                'amount' => (float) $entry['amount'],
                'calculation' => $entry['calculation_note'],
                'accrued_date' => $entry['accrued_date'],
            ], $this->entries->pendingApproval($supervisorId))],
            'Pending commission loaded'
        );
    }

    /** POST /api/v1/admin/commission/approve */
    public function approve(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'entry_uuids' => 'required|array|min:1',
        ]);

        return Response::success(
            $this->commissions->approve($request, array_map('strval', $data['entry_uuids'])),
            'Commission approved'
        );
    }

    /** GET /api/v1/admin/commission/{uuid}/statement */
    public function statement(Request $request): Response
    {
        return Response::success(
            $this->commissions->statementFor($request, (string) $request->routeParam('uuid')),
            'Commission statement loaded'
        );
    }

    /** POST /api/v1/admin/commission/settle */
    public function settle(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'user_uuid' => 'required|uuid',
            'period_start' => 'required|date',
            'period_end' => 'required|date',
        ]);

        return Response::created(
            $this->commissions->settle($request, $data['user_uuid'], $data['period_start'], $data['period_end']),
            'Settlement created'
        );
    }

    /** POST /api/v1/admin/commission/settlements/{uuid}/pay */
    public function markPaid(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'payment_reference' => 'required|string|min:3|max:120',
        ]);

        return Response::success(
            $this->commissions->markPaid($request, (string) $request->routeParam('uuid'), $data['payment_reference']),
            'Settlement marked paid'
        );
    }
}
