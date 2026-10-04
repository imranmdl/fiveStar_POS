<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Uuid;
use App\Repositories\AssignmentRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SettingRepository;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use App\Services\Orders\NumberingService;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;
use App\Services\Staff\AssignmentPlanner;

/**
 * Getting work to the people who do it: assignment, the executive queue and
 * packing slips.
 *
 * One rule governs the whole class: ONLY PAID, CONFIRMED ORDERS ARE ASSIGNABLE.
 * Putting an unpaid order into someone's picking queue invites exactly the
 * mistake BR-005 exists to prevent — a parcel packed and out of the door before
 * anyone notices the payment failed.
 */
final class StaffOperationsService
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly StaffRepository $staff,
        private readonly OrderRepository $orders,
        private readonly UserRepository $users,
        private readonly AssignmentPlanner $planner,
        private readonly NumberingService $numbering,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Assigns one order, automatically or to a named executive.
     *
     * @return array<string, mixed>
     */
    public function assign(Request $request, string $orderUuid, ?string $assigneeUuid = null, ?string $note = null): array
    {
        $order = $this->orders->findByUuid($orderUuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        $this->assertAssignable($order);

        if ($this->assignments->activeForOrder((int) $order['id']) !== null) {
            throw new HttpException(
                'This order is already assigned. Reassign it instead if it needs to move.',
                409
            );
        }

        $assignee = null;
        $method = 'manual';
        $reason = null;

        if ($assigneeUuid !== null) {
            $assignee = $this->users->findByUuid($assigneeUuid);

            if ($assignee === null) {
                throw new NotFoundException('That staff member does not exist.');
            }

            $workload = $this->staff->workloadFor((int) $assignee['id']);

            if ($workload === null) {
                throw new HttpException(
                    'That user has no staff profile, so they cannot take assignments.',
                    422
                );
            }

            // A manual assignment may exceed capacity — a supervisor sometimes
            // has to — but it is flagged rather than silently allowed, because
            // an overloaded queue is the thing capacity exists to surface.
            if ((int) $workload['remaining_capacity'] <= 0) {
                $reason = sprintf(
                    'Assigned manually despite being at capacity (%d of %d).',
                    (int) $workload['open_assignments'],
                    (int) $workload['max_concurrent_orders']
                );

                $this->logger->warning('Order assigned to an executive already at capacity', [
                    'order_number' => $order['order_number'],
                    'assignee' => $assignee['full_name'],
                    'open' => (int) $workload['open_assignments'],
                ], 'operations');
            }
        } else {
            $method = 'auto';
            $plan = $this->planner->plan(
                $this->staff->assignableExecutives(),
                (string) ($this->settings->value('assignment_strategy') ?? AssignmentPlanner::STRATEGY_LEAST_LOADED)
            );

            if ($plan['selected'] === null) {
                throw new HttpException(
                    'No executive can take this order right now: ' . $plan['reason'],
                    422,
                    ['assignment' => [$plan['reason']]]
                );
            }

            $assignee = $this->users->findById((int) $plan['selected']['user_id']);
            $reason = $plan['reason'];
        }

        $slaHours = max(1, $this->settings->intValue('assignment_sla_hours', 8));

        $assignmentId = $this->db->transaction(function () use ($order, $assignee, $method, $reason, $note, $slaHours, $request): int {
            return $this->assignments->create([
                'order_id' => (int) $order['id'],
                'assigned_to_user_id' => (int) $assignee['id'],
                'assigned_by_user_id' => $request->authUserId(),
                'status' => 'assigned',
                'assignment_method' => $method,
                'assigned_date' => date('Y-m-d H:i:s'),
                'due_date' => date('Y-m-d H:i:s', strtotime('+' . $slaHours . ' hours')),
                'notes' => $note ?? $reason,
            ], $request->authUserId());
        });

        $this->orders->appendTimeline(
            orderId: (int) $order['id'],
            fromStatus: (string) $order['status'],
            toStatus: (string) $order['status'],
            title: 'Assigned for fulfilment',
            paymentStatus: (string) $order['payment_status'],
            note: sprintf('Assigned to %s.', $assignee['full_name']),
            // Internal: a customer does not need to know which colleague is
            // picking their parcel.
            customerVisible: false,
            changedBy: $request->authUserId(),
            changedByRole: 'staff',
        );

        $this->audit->log(
            entityName: 'order_assignments',
            entityId: $assignmentId,
            action: 'assign',
            newValues: [
                'order_number' => $order['order_number'],
                'assigned_to' => $assignee['full_name'],
                'method' => $method,
                'reason' => $reason,
            ],
            request: $request,
        );

        return [
            'assignment' => $this->presentAssignment((array) $this->assignments->findById($assignmentId)),
            'assignee' => [
                'uuid' => $assignee['uuid'],
                'full_name' => $assignee['full_name'],
            ],
            'method' => $method,
            'reason' => $reason,
        ];
    }

    /**
     * Assigns everything currently waiting.
     *
     * @return array<string, mixed>
     */
    public function assignPending(Request $request, int $limit = 50): array
    {
        $assigned = 0;
        $failed = 0;
        $failures = [];

        foreach ($this->assignments->unassignedOrders($limit) as $order) {
            try {
                $this->assign($request, (string) $order['uuid']);
                ++$assigned;
            } catch (HttpException $exception) {
                ++$failed;

                // Recorded rather than thrown: one order nobody can take must
                // not stop the rest of the queue being distributed.
                $failures[] = $order['order_number'] . ': ' . $exception->getMessage();
            }
        }

        return [
            'assigned' => $assigned,
            'failed' => $failed,
            'failures' => array_slice($failures, 0, 10),
        ];
    }

    /**
     * Moves an order to someone else, keeping the history.
     *
     * @return array<string, mixed>
     */
    public function reassign(Request $request, string $orderUuid, string $assigneeUuid, string $reason): array
    {
        $order = $this->orders->findByUuid($orderUuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        $current = $this->assignments->activeForOrder((int) $order['id']);

        if ($current === null) {
            throw new HttpException('This order is not currently assigned to anyone.', 409);
        }

        $this->db->transaction(function () use ($current, $reason, $request): void {
            $this->assignments->update((int) $current['id'], [
                'status' => 'reassigned',
                'released_date' => date('Y-m-d H:i:s'),
                'release_reason' => $reason,
            ], $request->authUserId());
        });

        $result = $this->assign($request, $orderUuid, $assigneeUuid, 'Reassigned: ' . $reason);
        $result['previous_assignment_released'] = true;

        return $result;
    }

    /** Executive accepts a queued assignment. */
    public function accept(Request $request, string $assignmentUuid): array
    {
        $assignment = $this->requireOwnAssignment($request, $assignmentUuid);

        if ($assignment['status'] !== 'assigned') {
            throw new HttpException(
                'This assignment is already ' . $assignment['status'] . '.',
                409
            );
        }

        $this->assignments->update((int) $assignment['id'], [
            'status' => 'accepted',
            'accepted_date' => date('Y-m-d H:i:s'),
        ], $request->authUserId());

        return ['assignment' => $this->presentAssignment(
            (array) $this->assignments->findById((int) $assignment['id'])
        )];
    }

    /**
     * Executive hands an assignment back.
     *
     * Allowed on purpose: someone who cannot complete a job should say so
     * rather than sit on it until it is late. The reason is mandatory.
     */
    public function release(Request $request, string $assignmentUuid, string $reason): array
    {
        $assignment = $this->requireOwnAssignment($request, $assignmentUuid);

        if (!in_array($assignment['status'], ['assigned', 'accepted'], true)) {
            throw new HttpException('This assignment is no longer open.', 409);
        }

        $this->assignments->update((int) $assignment['id'], [
            'status' => 'released',
            'released_date' => date('Y-m-d H:i:s'),
            'release_reason' => $reason,
        ], $request->authUserId());

        $this->logger->info('Assignment released', [
            'assignment' => $assignment['uuid'],
            'reason' => $reason,
        ], 'operations');

        return ['released' => true, 'reason' => $reason];
    }

    /**
     * Marks an assignment complete. Called when the order is handed to a
     * courier, which is the point at which the executive's work is done.
     */
    public function complete(Request $request, int $orderId, ?int $actorId = null): ?int
    {
        $assignment = $this->assignments->activeForOrder($orderId);

        if ($assignment === null) {
            return null;
        }

        $this->assignments->update((int) $assignment['id'], [
            'status' => 'completed',
            'completed_date' => date('Y-m-d H:i:s'),
        ], $actorId);

        return (int) $assignment['assigned_to_user_id'];
    }

    /** @return array<string, mixed> */
    public function myQueue(Request $request): array
    {
        $userId = (int) $request->authUserId();
        $workload = $this->staff->workloadFor($userId);

        if ($workload === null) {
            throw new HttpException(
                'You do not have a staff profile, so you have no assignment queue.',
                403
            );
        }

        $queue = $this->assignments->queueFor($userId);
        $overdue = 0;

        foreach ($queue as $item) {
            if ($item['due_date'] !== null && strtotime((string) $item['due_date']) < time()) {
                ++$overdue;
            }
        }

        return [
            'workload' => [
                'open_assignments' => (int) $workload['open_assignments'],
                'max_concurrent_orders' => (int) $workload['max_concurrent_orders'],
                'remaining_capacity' => (int) $workload['remaining_capacity'],
                'completed_today' => (int) $workload['completed_today'],
                'overdue' => $overdue,
                'is_available' => (bool) $workload['is_available'],
            ],
            'queue' => array_map(static fn (array $item): array => [
                'assignment_uuid' => $item['uuid'],
                'status' => $item['status'],
                'order_uuid' => $item['order_uuid'],
                'order_number' => $item['order_number'],
                'order_status' => $item['order_status'],
                'grand_total' => (float) $item['grand_total'],
                'item_count' => (int) $item['item_count'],
                'weight_grams' => (int) $item['total_weight_grams'],
                'destination' => $item['ship_city'] . ' ' . $item['ship_pincode'],
                'assigned_date' => $item['assigned_date'],
                'due_date' => $item['due_date'],
                'is_overdue' => $item['due_date'] !== null && strtotime((string) $item['due_date']) < time(),
            ], $queue),
        ];
    }

    /**
     * Generates a packing slip.
     *
     * The slip is a snapshot, not a live view: it records what the picker was
     * told to pick. If the order is later edited, the slip still shows what was
     * actually in the box.
     *
     * @return array<string, mixed>
     */
    public function generatePackingSlip(Request $request, string $orderUuid): array
    {
        $order = $this->orders->findByUuid($orderUuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        if (!PaymentStatus::isSettled((string) $order['payment_status'])) {
            throw new HttpException(
                'This order has not been paid for, so nothing should be picked for it.',
                409
            );
        }

        $items = $this->orders->itemsFor((int) $order['id']);

        $existing = $this->db->selectOne(
            'SELECT * FROM `packing_slips` WHERE `order_id` = :order_id AND `is_deleted` = 0 LIMIT 1',
            ['order_id' => (int) $order['id']]
        );

        if ($existing !== null) {
            // Reprints are counted, not blocked: a jammed printer is not fraud.
            // A slip printed nine times is still worth a supervisor noticing.
            $this->db->execute(
                'UPDATE `packing_slips`
                    SET `print_count` = `print_count` + 1, `last_printed_date` = NOW(),
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id',
                ['id' => (int) $existing['id']]
            );

            return [
                'slip' => $this->presentSlip(
                    (array) $this->db->selectOne('SELECT * FROM `packing_slips` WHERE `id` = :id', ['id' => (int) $existing['id']]),
                    $order
                ),
                'reprint' => true,
            ];
        }

        $payload = array_map(static fn (array $item): array => [
            'sku' => $item['sku'],
            'product' => $item['product_name'],
            'variant' => $item['variant_name'],
            'quantity' => (int) $item['quantity'],
            'weight_grams' => (int) $item['line_weight_grams'],
            'is_gift' => (bool) $item['is_gift'],
            'gift_message' => $item['gift_message'],
        ], $items);

        $slipId = $this->db->transaction(function () use ($order, $items, $payload, $request): int {
            $number = $this->numbering->nextPackingSlipNumber();

            return (int) $this->db->insert(
                'INSERT INTO `packing_slips`
                     (`uuid`, `slip_number`, `order_id`, `generated_by`, `generated_date`,
                      `print_count`, `last_printed_date`, `item_count`, `unit_count`,
                      `total_weight_grams`, `payload`, `created_by`, `created_date`,
                      `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :number, :order_id, :generated_by, NOW(), 1, NOW(),
                      :item_count, :unit_count, :weight, :payload, :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'number' => $number,
                    'order_id' => (int) $order['id'],
                    'generated_by' => $request->authUserId(),
                    'item_count' => count($items),
                    'unit_count' => array_sum(array_map(static fn (array $i): int => (int) $i['quantity'], $items)),
                    'weight' => (int) $order['total_weight_grams'],
                    'payload' => json_encode($payload),
                    'created_by' => $request->authUserId(),
                ]
            );
        });

        return [
            'slip' => $this->presentSlip(
                (array) $this->db->selectOne('SELECT * FROM `packing_slips` WHERE `id` = :id', ['id' => $slipId]),
                $order
            ),
            'reprint' => false,
        ];
    }

    /**
     * The supervisor's view: what is waiting, what is late, who is free.
     *
     * @return array<string, mixed>
     */
    public function supervisorBoard(Request $request, ?int $supervisorId = null): array
    {
        $team = $supervisorId === null
            ? $this->staff->assignableExecutives()
            : $this->staff->team($supervisorId);

        $unassigned = $this->assignments->unassignedOrders(100);
        $overdue = $this->assignments->overdue($supervisorId);

        return [
            'summary' => [
                'unassigned_orders' => count($unassigned),
                'overdue_assignments' => count($overdue),
                'team_size' => count($team),
                'available_now' => count(array_filter(
                    $team,
                    static fn (array $m): bool => (int) $m['is_available'] === 1 && (int) $m['remaining_capacity'] > 0
                )),
                'total_open' => array_sum(array_map(static fn (array $m): int => (int) $m['open_assignments'], $team)),
                'total_capacity' => array_sum(array_map(static fn (array $m): int => (int) $m['max_concurrent_orders'], $team)),
            ],
            'team' => array_map(static fn (array $member): array => [
                'user_uuid' => $member['user_uuid'],
                'full_name' => $member['full_name'],
                'employee_code' => $member['employee_code'],
                'open_assignments' => (int) $member['open_assignments'],
                'remaining_capacity' => (int) $member['remaining_capacity'],
                'completed_today' => (int) $member['completed_today'],
                'is_available' => (bool) $member['is_available'],
            ], $team),
            'unassigned' => array_map(static fn (array $order): array => [
                'uuid' => $order['uuid'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'grand_total' => (float) $order['grand_total'],
                'destination' => $order['ship_city'] . ' ' . $order['ship_pincode'],
                'confirmed_date' => $order['confirmed_date'],
            ], array_slice($unassigned, 0, 25)),
            'overdue' => array_map(static fn (array $item): array => [
                'assignment_uuid' => $item['uuid'],
                'order_number' => $item['order_number'],
                'assignee_name' => $item['assignee_name'],
                'due_date' => $item['due_date'],
                'hours_overdue' => (int) $item['hours_overdue'],
            ], array_slice($overdue, 0, 25)),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $order */
    private function assertAssignable(array $order): void
    {
        if (!PaymentStatus::isSettled((string) $order['payment_status'])) {
            throw new HttpException(
                'This order has not been paid for. Assigning it would put unpaid goods into a picking queue.',
                409,
                ['payment_status' => [(string) $order['payment_status']]]
            );
        }

        if (!in_array($order['status'], [OrderStatus::CONFIRMED, OrderStatus::PACKED], true)) {
            throw new HttpException(
                sprintf(
                    'Only confirmed or packed orders can be assigned. This one is %s.',
                    OrderStatus::label((string) $order['status'])
                ),
                409
            );
        }
    }

    /** @return array<string, mixed> */
    private function requireOwnAssignment(Request $request, string $uuid): array
    {
        $assignment = $this->assignments->findByUuid($uuid);

        if ($assignment === null) {
            throw new NotFoundException('That assignment does not exist.');
        }

        if ((int) $assignment['assigned_to_user_id'] !== (int) $request->authUserId()) {
            throw new NotFoundException('That assignment does not exist.');
        }

        return $assignment;
    }

    /**
     * @param array<string, mixed> $assignment
     *
     * @return array<string, mixed>
     */
    private function presentAssignment(array $assignment): array
    {
        return [
            'uuid' => $assignment['uuid'],
            'status' => $assignment['status'],
            'method' => $assignment['assignment_method'],
            'assigned_date' => $assignment['assigned_date'],
            'accepted_date' => $assignment['accepted_date'],
            'completed_date' => $assignment['completed_date'],
            'due_date' => $assignment['due_date'],
            'notes' => $assignment['notes'],
        ];
    }

    /**
     * @param array<string, mixed> $slip
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function presentSlip(array $slip, array $order): array
    {
        return [
            'uuid' => $slip['uuid'],
            'slip_number' => $slip['slip_number'],
            'order_number' => $order['order_number'],
            'generated_date' => $slip['generated_date'],
            'print_count' => (int) $slip['print_count'],
            'item_count' => (int) $slip['item_count'],
            'unit_count' => (int) $slip['unit_count'],
            'total_weight_grams' => (int) $slip['total_weight_grams'],
            'ship_to' => [
                'name' => $order['ship_name'],
                'address' => implode(', ', array_filter([
                    $order['ship_address_line1'],
                    $order['ship_address_line2'],
                    $order['ship_city'],
                    $order['ship_state'],
                    $order['ship_pincode'],
                ])),
            ],
            'is_gift' => (bool) $order['is_gift'],
            'gift_message' => $order['gift_message'],
            'delivery_instructions' => $order['delivery_instructions'],
            'items' => json_decode((string) $slip['payload'], true),
        ];
    }
}
