<?php

declare(strict_types=1);

namespace App\Repositories;

final class ActivityLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'activity_logs';
    }

    protected function fillable(): array
    {
        return [
            'user_id',
            'user_role',
            'module',
            'action',
            'http_method',
            'endpoint',
            'status_code',
            'duration_ms',
            'ip_address',
            'user_agent',
            'request_id',
            'error_message',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'created_date', 'status_code', 'duration_ms'];
    }

    /**
     * Filtered listing for the Admin Privilege activity-log viewer.
     *
     * @param array{module?:string, user_id?:int, status_code?:int, from?:string, to?:string} $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateFiltered(array $filters, array $params): array
    {
        $where = ['al.is_deleted = 0'];
        $bindings = [];

        if (!empty($filters['module'])) {
            $where[] = 'al.module = :module';
            $bindings['module'] = $filters['module'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $bindings['user_id'] = (int) $filters['user_id'];
        }

        if (!empty($filters['status_code'])) {
            $where[] = 'al.status_code = :status_code';
            $bindings['status_code'] = (int) $filters['status_code'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'al.created_date >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'al.created_date <= :to';
            $bindings['to'] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM activity_logs al WHERE ' . $whereSql, $bindings);

        $items = $this->db->select(
            sprintf(
                'SELECT al.*, u.full_name AS user_name
                   FROM activity_logs al
              LEFT JOIN users u ON u.id = al.user_id
                  WHERE %s
               ORDER BY al.`%s` %s
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
}
