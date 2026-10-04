<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The sensitive-action approval queue (Admin Privilege Management item 4:
 * large discounts, refunds, wallet adjustments, stock adjustments, price
 * changes, customer credit/dues, payment adjustments).
 */
final class ApprovalRequestRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'approval_requests';
    }

    protected function fillable(): array
    {
        return [
            'module',
            'action_type',
            'entity_name',
            'entity_id',
            'entity_uuid',
            'title',
            'reason',
            'old_values',
            'new_values',
            'amount',
            'status',
            'requested_by_user_id',
            'decided_by_user_id',
            'decided_date',
            'decision_note',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'created_date', 'decided_date', 'amount', 'status'];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginate(?string $status, ?string $module, array $params): array
    {
        $where = ['ar.is_deleted = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 'ar.status = :status';
            $bindings['status'] = $status;
        }

        if ($module !== null) {
            $where[] = 'ar.module = :module';
            $bindings['module'] = $module;
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM approval_requests ar WHERE ' . $whereSql,
            $bindings
        );

        $items = $this->db->select(
            sprintf(
                'SELECT ar.*,
                        req.full_name AS requested_by_name,
                        decider.full_name AS decided_by_name
                   FROM approval_requests ar
                   JOIN users req ON req.id = ar.requested_by_user_id
              LEFT JOIN users decider ON decider.id = ar.decided_by_user_id
                  WHERE %s
               ORDER BY ar.`%s` %s
                  LIMIT %d OFFSET %d',
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM approval_requests WHERE status = 'pending' AND is_deleted = 0"
        );
    }
}
