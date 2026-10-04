<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'audit_logs';
    }

    protected function fillable(): array
    {
        return [
            'entity_name',
            'entity_id',
            'entity_uuid',
            'action',
            'old_values',
            'new_values',
            'performed_by_user_id',
            'performed_by_role',
            'ip_address',
            'user_agent',
            'request_id',
            'notes',
        ];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForEntity(string $entityName, int $entityId, array $params): array
    {
        return $this->paginateWhere(
            ['entity_name' => $entityName, 'entity_id' => $entityId],
            $params
        );
    }

    /**
     * General-purpose filtered listing for the Admin Privilege audit-log
     * viewer. Additive: paginateForEntity() above is untouched and still
     * used exactly as before wherever it already is.
     *
     * @param array{entity_name?:string, action?:string, performed_by_user_id?:int, from?:string, to?:string} $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateFiltered(array $filters, array $params): array
    {
        $where = ['al.is_deleted = 0'];
        $bindings = [];

        if (!empty($filters['entity_name'])) {
            $where[] = 'al.entity_name = :entity_name';
            $bindings['entity_name'] = $filters['entity_name'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $bindings['action'] = $filters['action'];
        }

        if (!empty($filters['performed_by_user_id'])) {
            $where[] = 'al.performed_by_user_id = :performed_by_user_id';
            $bindings['performed_by_user_id'] = (int) $filters['performed_by_user_id'];
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

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs al WHERE ' . $whereSql, $bindings);

        $items = $this->db->select(
            sprintf(
                'SELECT al.*, u.full_name AS performed_by_name
                   FROM audit_logs al
              LEFT JOIN users u ON u.id = al.performed_by_user_id
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
