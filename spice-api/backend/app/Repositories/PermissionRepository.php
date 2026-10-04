<?php

declare(strict_types=1);

namespace App\Repositories;

final class PermissionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'permissions';
    }

    protected function fillable(): array
    {
        return ['code', 'module', 'action', 'name', 'description'];
    }

    /**
     * All permissions, grouped by module, in the fixed order the Admin
     * Privilege panel's role editor renders its module.action grid in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT * FROM permissions WHERE is_deleted = 0 ORDER BY module ASC, action ASC'
        );
    }

    /** @return array<int, string> the distinct module codes, in seed order */
    public function modules(): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT module FROM permissions WHERE is_deleted = 0 ORDER BY module ASC'
        );

        return array_map(static fn (array $row): string => (string) $row['module'], $rows);
    }
}
