<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

final class StaffRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'staff_profiles';
    }

    protected function fillable(): array
    {
        return [
            'user_id', 'employee_code', 'reports_to_user_id', 'department',
            'shift_start', 'shift_end', 'max_concurrent_orders', 'is_available',
            'unavailable_reason', 'unavailable_until', 'joined_date', 'notes',
        ];
    }

    /** @return array<string, mixed>|null */
    public function forUser(int $userId): ?array
    {
        return $this->findOneBy('user_id', $userId);
    }

    /** @return array<string, mixed>|null */
    public function findByEmployeeCode(string $code): ?array
    {
        return $this->findOneBy('employee_code', strtoupper(trim($code)));
    }

    /**
     * Executives available for assignment, with their current load.
     *
     * @param int|null $supervisorId Restricts to one supervisor's team
     *
     * @return array<int, array<string, mixed>>
     */
    public function assignableExecutives(?int $supervisorId = null): array
    {
        $sql = "SELECT w.* FROM `vw_executive_workload` w
                  INNER JOIN `users` u ON u.`id` = w.`user_id`
                  INNER JOIN `roles` r ON r.`id` = u.`role_id`
                  WHERE r.`code` = 'executive'
                    AND u.`status` = 'active'
                    AND u.`is_deleted` = 0";

        $bindings = [];

        if ($supervisorId !== null) {
            $sql .= ' AND w.`reports_to_user_id` = :supervisor_id';
            $bindings['supervisor_id'] = $supervisorId;
        }

        $sql .= ' ORDER BY w.`employee_code`';

        return $this->db->select($sql, $bindings);
    }

    /** @return array<int, array<string, mixed>> */
    public function team(int $supervisorId): array
    {
        return $this->db->select(
            'SELECT * FROM `vw_executive_workload` WHERE `reports_to_user_id` = :supervisor_id
              ORDER BY `employee_code`',
            ['supervisor_id' => $supervisorId]
        );
    }

    /** @return array<string, mixed>|null */
    public function workloadFor(int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `vw_executive_workload` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /**
     * Next employee code in sequence, e.g. EMP0007.
     *
     * Derived from the highest existing code rather than a row count, so
     * deleting a profile does not cause the next hire to collide with someone.
     */
    public function nextEmployeeCode(string $prefix = 'EMP'): string
    {
        $highest = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(`employee_code`, :length) AS UNSIGNED)), 0)
               FROM `staff_profiles`
              WHERE `employee_code` LIKE :pattern',
            ['length' => strlen($prefix) + 1, 'pattern' => $prefix . '%']
        );

        return $prefix . str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
}
