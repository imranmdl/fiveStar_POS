<?php

declare(strict_types=1);

namespace App\Repositories;

final class CommissionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'commission_entries';
    }

    protected function fillable(): array
    {
        return [
            'user_id', 'order_id', 'rule_id', 'settlement_id', 'reverses_entry_id',
            'scope', 'amount', 'order_value', 'calculation_note', 'status',
            'accrued_date', 'approved_by', 'approved_date', 'idempotency_key',
        ];
    }

    /**
     * Active rules for a scope, cheapest priority first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeRules(string $scope, ?string $roleCode = null): array
    {
        $sql = "SELECT * FROM `commission_rules`
                 WHERE `scope` = :scope
                   AND `status` = 'active'
                   AND `is_active` = 1 AND `is_deleted` = 0
                   AND (`effective_from`  IS NULL OR `effective_from`  <= CURDATE())
                   AND (`effective_until` IS NULL OR `effective_until` >= CURDATE())";

        $bindings = ['scope' => $scope];

        if ($roleCode !== null) {
            $sql .= ' AND (`applies_to_role` IS NULL OR `applies_to_role` = :role_code)';
            $bindings['role_code'] = $roleCode;
        }

        $sql .= ' ORDER BY `priority` ASC, `id` ASC';

        return $this->db->select($sql, $bindings);
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->findOneBy('idempotency_key', $key);
    }

    /** @return array<int, array<string, mixed>> */
    public function entriesForUser(int $userId, ?string $status = null, int $limit = 200): array
    {
        $sql = 'SELECT ce.*, o.`order_number`, cr.`code` AS `rule_code`
                  FROM `commission_entries` ce
                  LEFT JOIN `orders` o ON o.`id` = ce.`order_id`
                  LEFT JOIN `commission_rules` cr ON cr.`id` = ce.`rule_id`
                 WHERE ce.`user_id` = :user_id AND ce.`is_deleted` = 0';

        $bindings = ['user_id' => $userId];

        if ($status !== null) {
            $sql .= ' AND ce.`status` = :status';
            $bindings['status'] = $status;
        }

        $sql .= sprintf(' ORDER BY ce.`accrued_date` DESC LIMIT %d', max(1, min($limit, 1000)));

        return $this->db->select($sql, $bindings);
    }

    /** @return array<string, mixed>|null */
    public function summaryFor(int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `vw_commission_summary` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /**
     * Approved, unsettled entries in a period.
     *
     * Only approved entries are swept into a settlement: a pending accrual has
     * not been reviewed, and paying it would make review pointless.
     *
     * @return array<int, array<string, mixed>>
     */
    public function settleableEntries(int $userId, string $periodStart, string $periodEnd): array
    {
        return $this->db->select(
            "SELECT * FROM `commission_entries`
              WHERE `user_id` = :user_id
                AND `status` = 'approved'
                AND `settlement_id` IS NULL
                AND DATE(`accrued_date`) BETWEEN :period_start AND :period_end
                AND `is_deleted` = 0
              ORDER BY `accrued_date`",
            ['user_id' => $userId, 'period_start' => $periodStart, 'period_end' => $periodEnd]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function pendingApproval(?int $supervisorId = null): array
    {
        $sql = "SELECT ce.*, u.`full_name`, o.`order_number`, cr.`code` AS `rule_code`
                  FROM `commission_entries` ce
                  INNER JOIN `users` u ON u.`id` = ce.`user_id`
                  LEFT JOIN `orders` o ON o.`id` = ce.`order_id`
                  LEFT JOIN `commission_rules` cr ON cr.`id` = ce.`rule_id`
                  LEFT JOIN `staff_profiles` sp ON sp.`user_id` = ce.`user_id`
                 WHERE ce.`status` = 'pending' AND ce.`is_deleted` = 0";

        $bindings = [];

        if ($supervisorId !== null) {
            $sql .= ' AND sp.`reports_to_user_id` = :supervisor_id';
            $bindings['supervisor_id'] = $supervisorId;
        }

        $sql .= ' ORDER BY ce.`accrued_date`';

        return $this->db->select($sql, $bindings);
    }

    public function countCompletedInPeriod(int $userId, string $periodStart, string $periodEnd): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `order_assignments`
              WHERE `assigned_to_user_id` = :user_id
                AND `status` = 'completed'
                AND DATE(`completed_date`) BETWEEN :period_start AND :period_end
                AND `is_deleted` = 0",
            ['user_id' => $userId, 'period_start' => $periodStart, 'period_end' => $periodEnd]
        );
    }
}
