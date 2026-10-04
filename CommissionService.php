<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Helpers\Uuid;
use App\Repositories\AssignmentRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SettingRepository;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use App\Services\Orders\NumberingService;
use App\Services\Staff\CommissionCalculator;

/**
 * Staff commission: accrual, approval, reversal and settlement.
 *
 * Two rules that shape everything here.
 *
 * COMMISSION IS EARNED AT DELIVERY. Not at placement, not at dispatch. An order
 * that is cancelled, refunded or returned to origin has cost the business
 * money, and paying a fulfilment fee on it rewards the wrong outcome. This is
 * the same rule the referral programme follows, and for the same reason.
 *
 * AN ACCRUED AMOUNT IS NEVER EDITED. A correction is a second entry with the
 * opposite sign, pointing at the original. The database enforces it with a
 * trigger, so the rule survives a well-meaning UPDATE written at some later
 * date by someone who has not read this file. Editing an accrual destroys the
 * only record of what someone was told they had earned, which is precisely the
 * evidence needed when they disagree.
 */
final class CommissionService
{
    public const SCOPE_FULFILMENT = 'executive_fulfilment';
    public const SCOPE_SUPERVISOR = 'supervisor_override';

    public function __construct(
        private readonly CommissionRepository $commissions,
        private readonly AssignmentRepository $assignments,
        private readonly StaffRepository $staff,
        private readonly OrderRepository $orders,
        private readonly UserRepository $users,
        private readonly CommissionCalculator $calculator,
        private readonly NumberingService $numbering,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Accrues commission for a delivered order.
     *
     * Called from the delivery path when an order reaches `delivered`. Safe to
     * call repeatedly: the idempotency key makes a replayed courier webhook a
     * no-op rather than a second payment.
     *
     * @return array<string, mixed>
     */
    public function accrueForDeliveredOrder(int $orderId, ?Request $request = null): array
    {
        if (!$this->settings->boolValue('commission_auto_accrue', true)) {
            return ['accrued' => [], 'skipped' => ['Automatic accrual is switched off.']];
        }

        $order = $this->orders->findById($orderId);

        if ($order === null) {
            return ['accrued' => [], 'skipped' => ['Order not found.']];
        }

        // The completed assignment tells us who actually did the work. Without
        // one there is nobody to pay, which is correct — an order that was never
        // assigned was not fulfilled by an executive.
        $history = $this->assignments->historyForOrder($orderId);
        $fulfiller = null;

        foreach ($history as $assignment) {
            if ($assignment['status'] === 'completed') {
                $fulfiller = $assignment;
            }
        }

        if ($fulfiller === null) {
            return ['accrued' => [], 'skipped' => ['No completed assignment; nobody to credit.']];
        }

        $orderValue = Money::fromDecimal((string) $order['grand_total']);
        $accrued = [];
        $skipped = [];

        $executiveEntry = $this->accrueFor(
            userId: (int) $fulfiller['assigned_to_user_id'],
            order: $order,
            orderValue: $orderValue,
            scope: self::SCOPE_FULFILMENT,
            roleCode: 'executive',
            request: $request,
        );

        if ($executiveEntry !== null) {
            $accrued[] = $executiveEntry;
        } else {
            $skipped[] = 'No fulfilment rule produced an amount for the executive.';
        }

        // The supervisor override goes to whoever the executive reports to, not
        // to every supervisor.
        $profile = $this->staff->forUser((int) $fulfiller['assigned_to_user_id']);

        if ($profile !== null && $profile['reports_to_user_id'] !== null) {
            $supervisorEntry = $this->accrueFor(
                userId: (int) $profile['reports_to_user_id'],
                order: $order,
                orderValue: $orderValue,
                scope: self::SCOPE_SUPERVISOR,
                roleCode: 'supervisor',
                request: $request,
            );

            if ($supervisorEntry !== null) {
                $accrued[] = $supervisorEntry;
            }
        } else {
            $skipped[] = 'The executive has no supervisor recorded; no override accrued.';
        }

        return ['accrued' => $accrued, 'skipped' => $skipped];
    }

    /**
     * Reverses commission on an order that was cancelled or returned after
     * delivery.
     *
     * A compensating entry, never an edit. The original stays visible so the
     * ledger reads as what happened rather than what someone wishes had.
     *
     * @return array<string, mixed>
     */
    public function reverseForOrder(int $orderId, string $reason, ?Request $request = null): array
    {
        $entries = $this->db->select(
            "SELECT * FROM `commission_entries`
              WHERE `order_id` = :order_id
                AND `reverses_entry_id` IS NULL
                AND `status` <> 'cancelled'
                AND `amount` > 0
                AND `is_deleted` = 0",
            ['order_id' => $orderId]
        );

        $reversed = [];

        foreach ($entries as $entry) {
            $key = 'reverse:' . $entry['uuid'];

            if ($this->commissions->findByIdempotencyKey($key) !== null) {
                continue;
            }

            $reversalId = $this->commissions->create([
                'user_id' => (int) $entry['user_id'],
                'order_id' => $orderId,
                'rule_id' => $entry['rule_id'],
                'reverses_entry_id' => (int) $entry['id'],
                'scope' => $entry['scope'],
                'amount' => '-' . $entry['amount'],
                'order_value' => $entry['order_value'],
                'calculation_note' => sprintf('Reversal of %s. %s', $entry['uuid'], $reason),
                // Settled straight away when the original was already paid:
                // there is nothing left to approve, only a balance to correct.
                'status' => $entry['status'] === 'settled' ? 'approved' : 'cancelled',
                'accrued_date' => date('Y-m-d H:i:s'),
                'idempotency_key' => $key,
            ], $request?->authUserId());

            // The original is marked cancelled only if it had not been paid.
            if ($entry['status'] !== 'settled') {
                $this->commissions->update((int) $entry['id'], ['status' => 'cancelled'], $request?->authUserId());
            }

            $reversed[] = [
                'original_uuid' => $entry['uuid'],
                'reversal_uuid' => (string) ($this->commissions->findById($reversalId)['uuid'] ?? ''),
                'amount' => -1 * (float) $entry['amount'],
            ];
        }

        if ($reversed !== []) {
            $this->logger->info('Commission reversed', [
                'order_id' => $orderId,
                'entries' => count($reversed),
                'reason' => $reason,
            ], 'operations');
        }

        return ['reversed' => $reversed, 'count' => count($reversed)];
    }

    /**
     * Supervisor approves pending accruals.
     *
     * @param array<int, string> $entryUuids
     *
     * @return array<string, mixed>
     */
    public function approve(Request $request, array $entryUuids): array
    {
        $approved = 0;
        $skipped = [];

        foreach ($entryUuids as $uuid) {
            $entry = $this->commissions->findByUuid($uuid);

            if ($entry === null) {
                $skipped[] = $uuid . ': not found';

                continue;
            }

            if ($entry['status'] !== 'pending') {
                $skipped[] = $uuid . ': already ' . $entry['status'];

                continue;
            }

            // Nobody approves their own commission. An obvious rule that is
            // trivially easy to leave out.
            if ((int) $entry['user_id'] === (int) $request->authUserId()) {
                $skipped[] = $uuid . ': you cannot approve your own commission';

                continue;
            }

            $this->commissions->update((int) $entry['id'], [
                'status' => 'approved',
                'approved_by' => $request->authUserId(),
                'approved_date' => date('Y-m-d H:i:s'),
            ], $request->authUserId());

            ++$approved;
        }

        $this->audit->log(
            entityName: 'commission_entries',
            entityId: 0,
            action: 'approve',
            newValues: ['approved' => $approved, 'skipped' => $skipped],
            request: $request,
        );

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /**
     * Builds a settlement for one person over a period.
     *
     * @return array<string, mixed>
     */
    public function settle(Request $request, string $userUuid, string $periodStart, string $periodEnd): array
    {
        $user = $this->users->findByUuid($userUuid);

        if ($user === null) {
            throw new NotFoundException('That staff member does not exist.');
        }

        if (strtotime($periodEnd) < strtotime($periodStart)) {
            throw new HttpException('The period end cannot be before its start.', 422);
        }

        $entries = $this->commissions->settleableEntries((int) $user['id'], $periodStart, $periodEnd);

        if ($entries === []) {
            throw new HttpException(
                'There are no approved, unsettled entries for that person in that period.',
                422,
                ['entries' => ['Nothing to settle. Approve pending accruals first.']]
            );
        }

        $gross = Money::zero();

        foreach ($entries as $entry) {
            $gross = $gross->add(Money::fromDecimal((string) $entry['amount']));
        }

        if (!$gross->isPositive()) {
            throw new HttpException(
                'The entries in this period net to zero or less, so there is nothing to pay.',
                422
            );
        }

        $settlementId = $this->db->transaction(function () use ($user, $entries, $gross, $periodStart, $periodEnd, $request): int {
            $number = $this->numbering->nextSettlementNumber();

            $id = (int) $this->db->insert(
                'INSERT INTO `commission_settlements`
                     (`uuid`, `settlement_number`, `user_id`, `period_start`, `period_end`,
                      `entry_count`, `gross_amount`, `deductions`, `net_amount`, `status`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :number, :user_id, :period_start, :period_end,
                      :entry_count, :gross, 0.00, :net, \'draft\',
                      :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'number' => $number,
                    'user_id' => (int) $user['id'],
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'entry_count' => count($entries),
                    'gross' => $gross->toDecimal(),
                    'net' => $gross->toDecimal(),
                    'created_by' => $request->authUserId(),
                ]
            );

            foreach ($entries as $entry) {
                $this->commissions->update((int) $entry['id'], [
                    'settlement_id' => $id,
                    'status' => 'settled',
                ], $request->authUserId());
            }

            return $id;
        });

        $settlement = (array) $this->db->selectOne(
            'SELECT * FROM `commission_settlements` WHERE `id` = :id',
            ['id' => $settlementId]
        );

        $this->audit->log(
            entityName: 'commission_settlements',
            entityId: $settlementId,
            action: 'create',
            newValues: [
                'settlement_number' => $settlement['settlement_number'],
                'user' => $user['full_name'],
                'gross' => $gross->toDecimal(),
                'entries' => count($entries),
            ],
            request: $request,
        );

        return ['settlement' => $this->presentSettlement($settlement, $user)];
    }

    /**
     * Marks a settlement paid.
     *
     * @return array<string, mixed>
     */
    public function markPaid(Request $request, string $settlementUuid, string $paymentReference): array
    {
        $settlement = $this->db->selectOne(
            'SELECT * FROM `commission_settlements` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $settlementUuid]
        );

        if ($settlement === null) {
            throw new NotFoundException('That settlement does not exist.');
        }

        if ($settlement['status'] === 'paid') {
            throw new HttpException('This settlement has already been paid.', 409);
        }

        if ($settlement['status'] === 'cancelled') {
            throw new HttpException('This settlement was cancelled.', 409);
        }

        $this->db->execute(
            'UPDATE `commission_settlements`
                SET `status` = \'paid\', `paid_date` = NOW(), `payment_reference` = :reference,
                    `approved_by` = COALESCE(`approved_by`, :approver),
                    `approved_date` = COALESCE(`approved_date`, NOW()),
                    `updated_by` = :updater, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'reference' => $paymentReference,
                'approver' => $request->authUserId(),
                'updater' => $request->authUserId(),
                'id' => (int) $settlement['id'],
            ]
        );

        return ['paid' => true, 'settlement_number' => $settlement['settlement_number']];
    }

    /** @return array<string, mixed> */
    public function statementFor(Request $request, ?string $userUuid = null): array
    {
        $userId = $userUuid === null
            ? (int) $request->authUserId()
            : (int) ($this->users->findByUuid($userUuid)['id'] ?? 0);

        if ($userId === 0) {
            throw new NotFoundException('That staff member does not exist.');
        }

        $summary = $this->commissions->summaryFor($userId);

        return [
            'summary' => [
                'total_accrued' => (float) ($summary['total_accrued'] ?? 0),
                'pending' => (float) ($summary['pending_amount'] ?? 0),
                'approved' => (float) ($summary['approved_amount'] ?? 0),
                'settled' => (float) ($summary['settled_amount'] ?? 0),
                'entry_count' => (int) ($summary['entry_count'] ?? 0),
            ],
            'entries' => array_map(static fn (array $entry): array => [
                'uuid' => $entry['uuid'],
                'order_number' => $entry['order_number'],
                'rule_code' => $entry['rule_code'],
                'scope' => $entry['scope'],
                'amount' => (float) $entry['amount'],
                'status' => $entry['status'],
                // Always shown. Staff query their pay, and the calculation is
                // the answer.
                'calculation' => $entry['calculation_note'],
                'accrued_date' => $entry['accrued_date'],
                'is_reversal' => $entry['reverses_entry_id'] !== null,
            ], $this->commissions->entriesForUser($userId)),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null
     */
    private function accrueFor(
        int $userId,
        array $order,
        Money $orderValue,
        string $scope,
        string $roleCode,
        ?Request $request,
    ): ?array {
        $key = sprintf('order:%s:%s:user:%d', $order['order_number'], $scope, $userId);

        $existing = $this->commissions->findByIdempotencyKey($key);

        if ($existing !== null) {
            // Already accrued. A replayed courier webhook must not pay twice.
            return null;
        }

        $rules = $this->commissions->activeRules($scope, $roleCode);

        if ($rules === []) {
            return null;
        }

        $periodStart = date('Y-m-01');
        $periodEnd = date('Y-m-t');
        $volume = $this->commissions->countCompletedInPeriod($userId, $periodStart, $periodEnd);

        $outcome = $this->calculator->resolve($rules, $orderValue, $volume);

        if ($outcome['rule'] === null || !$outcome['amount']->isPositive()) {
            return null;
        }

        $autoApprove = $this->settings->boolValue('commission_auto_approve', false);

        $entryId = $this->commissions->create([
            'user_id' => $userId,
            'order_id' => (int) $order['id'],
            'rule_id' => (int) $outcome['rule']['id'],
            'scope' => $scope,
            'amount' => $outcome['amount']->toDecimal(),
            'order_value' => $orderValue->toDecimal(),
            'calculation_note' => substr(
                sprintf('%s. %s', $outcome['rule']['code'], $outcome['note']),
                0,
                255
            ),
            'status' => $autoApprove ? 'approved' : 'pending',
            'accrued_date' => date('Y-m-d H:i:s'),
            'approved_by' => $autoApprove ? null : null,
            'approved_date' => $autoApprove ? date('Y-m-d H:i:s') : null,
            'idempotency_key' => $key,
        ], $request?->authUserId());

        return [
            'uuid' => (string) ($this->commissions->findById($entryId)['uuid'] ?? ''),
            'user_id' => $userId,
            'scope' => $scope,
            'amount' => $outcome['amount']->toDecimal(),
            'rule' => $outcome['rule']['code'],
            'calculation' => $outcome['note'],
        ];
    }

    /**
     * @param array<string, mixed> $settlement
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function presentSettlement(array $settlement, array $user): array
    {
        return [
            'uuid' => $settlement['uuid'],
            'settlement_number' => $settlement['settlement_number'],
            'staff_name' => $user['full_name'],
            'period_start' => $settlement['period_start'],
            'period_end' => $settlement['period_end'],
            'entry_count' => (int) $settlement['entry_count'],
            'gross_amount' => (float) $settlement['gross_amount'],
            'deductions' => (float) $settlement['deductions'],
            'net_amount' => (float) $settlement['net_amount'],
            'status' => $settlement['status'],
            'payment_reference' => $settlement['payment_reference'],
        ];
    }
}
