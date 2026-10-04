<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Roles for the Admin Privilege Management panel's role editor.
 *
 * This is additive to the existing `roles` table used everywhere else in the
 * system (route-level `role:administrator,supervisor` gates, JWT `role`
 * claims). Creating/editing a role here never changes what an existing
 * route already grants that role — it only affects the new
 * `module.action` permission checks the Admin Privilege panel enforces on
 * its own endpoints.
 */
final class RoleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'roles';
    }

    protected function fillable(): array
    {
        return ['code', 'name', 'description', 'hierarchy'];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'hierarchy', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.is_deleted = 0) AS user_count
               FROM roles r
              WHERE r.is_deleted = 0
              ORDER BY r.hierarchy ASC, r.name ASC'
        );
    }

    public function findByCode(string $code): ?array
    {
        return $this->findOneBy('code', $code);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->existsWhere('code', $code, $exceptId);
    }

    public function userCount(int $roleId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE role_id = :id AND is_deleted = 0',
            ['id' => $roleId]
        );
    }

    /** @return array<int, string> permission codes currently granted to this role */
    public function permissionCodes(int $roleId): array
    {
        $rows = $this->db->select(
            'SELECT p.code
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id AND p.is_deleted = 0
              WHERE rp.role_id = :role_id AND rp.is_deleted = 0',
            ['role_id' => $roleId]
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /**
     * Replaces the full permission set for a role with exactly the given
     * codes. Unknown codes are silently ignored rather than failing the
     * whole request over one typo.
     *
     * @param array<int, string> $permissionCodes
     */
    public function syncPermissions(int $roleId, array $permissionCodes, ?int $actorId): void
    {
        $this->db->transaction(function () use ($roleId, $permissionCodes, $actorId): void {
            $this->db->execute(
                'UPDATE role_permissions
                    SET is_deleted = 1, is_active = 0, deleted_by = :actor, deleted_date = NOW(),
                        version = version + 1
                  WHERE role_id = :role_id AND is_deleted = 0',
                ['actor' => $actorId, 'role_id' => $roleId]
            );

            if ($permissionCodes === []) {
                return;
            }

            // Real (non-emulated) prepared statements reject a bound name
            // that has no matching placeholder in the SQL, so this bindings
            // array must contain nothing but the :codeN placeholders below —
            // no role_id, no anything else.
            $placeholders = [];
            $codeBindings = [];

            foreach (array_values($permissionCodes) as $index => $code) {
                $placeholders[] = ":code{$index}";
                $codeBindings["code{$index}"] = $code;
            }

            $permissionIds = $this->db->select(
                'SELECT id, code FROM permissions WHERE code IN (' . implode(',', $placeholders) . ') AND is_deleted = 0',
                $codeBindings
            );

            foreach ($permissionIds as $permission) {
                // A previously-deleted pairing for the same role+permission may
                // still exist (unique key on role_id+permission_id); reactivate
                // it instead of inserting a duplicate.
                $existing = $this->db->selectOne(
                    'SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id LIMIT 1',
                    ['role_id' => $roleId, 'permission_id' => $permission['id']]
                );

                if ($existing !== null) {
                    $this->db->execute(
                        'UPDATE role_permissions
                            SET is_deleted = 0, is_active = 1, deleted_by = NULL, deleted_date = NULL,
                                updated_by = :actor, updated_date = NOW(), version = version + 1
                          WHERE id = :id',
                        ['actor' => $actorId, 'id' => $existing['id']]
                    );

                    continue;
                }

                $this->db->execute(
                    'INSERT INTO role_permissions (uuid, role_id, permission_id, created_by, created_date, is_active, is_deleted, version)
                     VALUES (UUID(), :role_id, :permission_id, :actor, NOW(), 1, 0, 1)',
                    ['role_id' => $roleId, 'permission_id' => $permission['id'], 'actor' => $actorId]
                );
            }
        });
    }

    /**
     * Whether the given role code carries the given permission code.
     * Used by AdminPrivilegeMiddleware on every gated request.
     */
    public function roleHasPermission(string $roleCode, string $permissionCode): bool
    {
        return $this->db->scalar(
            'SELECT 1
               FROM roles r
               JOIN role_permissions rp ON rp.role_id = r.id AND rp.is_deleted = 0
               JOIN permissions p ON p.id = rp.permission_id AND p.is_deleted = 0
              WHERE r.code = :role_code AND p.code = :permission_code
                AND r.is_deleted = 0 AND r.is_active = 1
              LIMIT 1',
            ['role_code' => $roleCode, 'permission_code' => $permissionCode]
        ) !== null;
    }
}
