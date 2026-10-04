<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Helpers\Uuid;

/**
 * Every table in this system carries the same audit contract:
 * id, uuid, created_by, created_date, updated_by, updated_date,
 * deleted_by, deleted_date, is_active, is_deleted, version.
 *
 * This base class is the only place that knows how to maintain those columns,
 * so no repository has to remember them. Deletes are always soft, and updates
 * always bump `version` for optimistic locking.
 */
abstract class BaseRepository
{
    public function __construct(protected readonly Database $db)
    {
    }

    abstract protected function table(): string;

    /**
     * Columns a caller is allowed to write. Anything else in the payload is
     * discarded, which stops mass-assignment of audit or identity columns.
     *
     * @return array<int, string>
     */
    abstract protected function fillable(): array;

    /** @return array<int, string> */
    protected function sortable(): array
    {
        return ['id', 'created_date', 'updated_date'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes, ?int $actorId = null): int
    {
        $data = $this->filterFillable($attributes);
        $data['uuid'] = $attributes['uuid'] ?? Uuid::v4();
        $data['created_by'] = $actorId;
        $data['created_date'] = $this->now();
        $data['is_active'] = (int) ($attributes['is_active'] ?? 1);
        $data['is_deleted'] = 0;
        $data['version'] = 1;

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table(),
            implode('`, `', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );

        return $this->db->insert($sql, $data);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $id, array $attributes, ?int $actorId = null): bool
    {
        $data = $this->filterFillable($attributes);

        if (array_key_exists('is_active', $attributes)) {
            $data['is_active'] = (int) $attributes['is_active'];
        }

        if ($data === []) {
            return false;
        }

        $assignments = array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($data));
        $assignments[] = '`updated_by` = :updated_by';
        $assignments[] = '`updated_date` = :updated_date';
        $assignments[] = '`version` = `version` + 1';

        $data['updated_by'] = $actorId;
        $data['updated_date'] = $this->now();
        $data['id'] = $id;

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `id` = :id AND `is_deleted` = 0',
            $this->table(),
            implode(', ', $assignments)
        );

        return $this->db->execute($sql, $data) > 0;
    }

    public function softDelete(int $id, ?int $actorId = null): bool
    {
        $sql = sprintf(
            'UPDATE `%s` SET `is_deleted` = 1, `is_active` = 0, `deleted_by` = :actor,
                    `deleted_date` = :now, `version` = `version` + 1
             WHERE `id` = :id AND `is_deleted` = 0',
            $this->table()
        );

        return $this->db->execute($sql, ['actor' => $actorId, 'now' => $this->now(), 'id' => $id]) > 0;
    }

    public function restore(int $id, ?int $actorId = null): bool
    {
        $sql = sprintf(
            'UPDATE `%s` SET `is_deleted` = 0, `is_active` = 1, `deleted_by` = NULL,
                    `deleted_date` = NULL, `updated_by` = :actor, `updated_date` = :now,
                    `version` = `version` + 1
             WHERE `id` = :id AND `is_deleted` = 1',
            $this->table()
        );

        return $this->db->execute($sql, ['actor' => $actorId, 'now' => $this->now(), 'id' => $id]) > 0;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id, bool $withTrashed = false): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `id` = :id %s LIMIT 1',
            $this->table(),
            $withTrashed ? '' : 'AND `is_deleted` = 0'
        );

        return $this->db->selectOne($sql, ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid, bool $withTrashed = false): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `uuid` = :uuid %s LIMIT 1',
            $this->table(),
            $withTrashed ? '' : 'AND `is_deleted` = 0'
        );

        return $this->db->selectOne($sql, ['uuid' => $uuid]);
    }

    /** @return array<string, mixed>|null */
    protected function findOneBy(string $column, mixed $value, bool $withTrashed = false): ?array
    {
        $this->assertColumnName($column);

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` = :value %s LIMIT 1',
            $this->table(),
            $column,
            $withTrashed ? '' : 'AND `is_deleted` = 0'
        );

        return $this->db->selectOne($sql, ['value' => $value]);
    }

    /**
     * @param array<string, mixed> $conditions Equality filters only.
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    protected function paginateWhere(array $conditions, array $params): array
    {
        $where = ['`is_deleted` = 0'];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $this->assertColumnName($column);
            $where[] = "`{$column}` = :{$column}";
            $bindings[$column] = $value;
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $this->table(), $whereSql),
            $bindings
        );

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE %s ORDER BY `%s` %s LIMIT %d OFFSET %d',
            $this->table(),
            $whereSql,
            $sort,
            $params['direction'],
            $params['per_page'],
            $params['offset']
        );

        return ['items' => $this->db->select($sql, $bindings), 'total' => $total];
    }

    public function existsWhere(string $column, mixed $value, ?int $exceptId = null): bool
    {
        $this->assertColumnName($column);

        $sql = sprintf(
            'SELECT 1 FROM `%s` WHERE `%s` = :value AND `is_deleted` = 0 %s LIMIT 1',
            $this->table(),
            $column,
            $exceptId === null ? '' : 'AND `id` <> :except'
        );

        $bindings = ['value' => $value];

        if ($exceptId !== null) {
            $bindings['except'] = $exceptId;
        }

        return $this->db->scalar($sql, $bindings) !== null;
    }

    /** @param array<string, mixed> $attributes */
    protected function filterFillable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip($this->fillable()));
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Column names are never bindable, so anything that reaches SQL as an
     * identifier is whitelisted by shape before it gets there.
     */
    private function assertColumnName(string $column): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $column) !== 1) {
            throw new \InvalidArgumentException("Illegal column name: {$column}");
        }
    }
}
