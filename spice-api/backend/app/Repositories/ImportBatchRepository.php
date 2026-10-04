<?php

declare(strict_types=1);

namespace App\Repositories;

final class ImportBatchRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'import_batches';
    }

    protected function fillable(): array
    {
        return [
            'file_name', 'file_type', 'status', 'warehouse_id',
            'total_rows', 'valid_rows', 'invalid_rows',
            'created_count', 'updated_count', 'skipped_count', 'error_message',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'created_date', 'total_rows'];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $params): array
    {
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM `import_batches` WHERE `is_deleted` = 0');

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT b.*, w.`name` AS `warehouse_name`
                   FROM `import_batches` b
                   LEFT JOIN `warehouses` w ON w.`id` = b.`warehouse_id`
                  WHERE b.`is_deleted` = 0
                  ORDER BY b.`%s` %s
                  LIMIT %d OFFSET %d',
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            )
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function detailByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT b.*, w.`name` AS `warehouse_name`
               FROM `import_batches` b
               LEFT JOIN `warehouses` w ON w.`id` = b.`warehouse_id`
              WHERE b.`uuid` = :uuid AND b.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }
}
