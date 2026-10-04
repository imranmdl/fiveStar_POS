<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;

/**
 * Admin Privilege Management item 3: "Custom Permissions" — creating roles
 * and toggling view/add/edit/delete/approve/export per module.
 */
final class RoleManagementService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function listRoles(): array
    {
        return $this->roles->all();
    }

    /** @return array<int, array<string, mixed>> permissions grouped by module */
    public function listPermissions(): array
    {
        $grouped = [];

        foreach ($this->permissions->all() as $permission) {
            $module = (string) $permission['module'];
            $grouped[$module] ??= [];
            $grouped[$module][] = $permission;
        }

        $result = [];

        foreach ($grouped as $module => $items) {
            $result[] = ['module' => $module, 'permissions' => $items];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function roleDetail(string $uuid): array
    {
        $role = $this->roles->findByUuid($uuid);

        if ($role === null) {
            throw new NotFoundException('That role does not exist.');
        }

        return [
            'role' => $role,
            'permission_codes' => $this->roles->permissionCodes((int) $role['id']),
        ];
    }

    /**
     * @param array{code:string, name:string, description:?string, hierarchy:?int, permission_codes:array<int,string>} $data
     *
     * @return array<string, mixed>
     */
    public function createRole(array $data, Request $request): array
    {
        $code = strtolower(trim($data['code']));

        if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $code)) {
            throw new HttpException(
                'Role code must be lowercase letters, numbers and underscores, starting with a letter.',
                422,
                ['code' => ['Invalid role code format.']]
            );
        }

        if ($this->roles->codeExists($code)) {
            throw new HttpException('That role code is already in use.', 409, ['code' => ['Already exists.']]);
        }

        $permissionCodes = $this->validPermissionCodes($data['permission_codes'] ?? []);

        // Atomic: a role must never end up saved with no permission matrix
        // because the second call failed after the first one committed.
        $roleId = $this->db->transaction(function () use ($code, $data, $permissionCodes, $request): int {
            $roleId = $this->roles->create([
                'code' => $code,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'hierarchy' => $data['hierarchy'] ?? 100,
            ], $request->authUserId());

            $this->roles->syncPermissions($roleId, $permissionCodes, $request->authUserId());

            return $roleId;
        });

        $this->audit->log(
            entityName: 'roles',
            entityId: $roleId,
            action: 'create',
            newValues: ['code' => $code, 'name' => $data['name'], 'permission_codes' => $permissionCodes],
            request: $request,
            notes: 'Role created from the Admin Privilege panel'
        );

        $created = $this->roles->findById($roleId);

        return $this->roleDetail((string) ($created['uuid'] ?? ''));
    }

    /**
     * @param array{name?:string, description?:?string, hierarchy?:int, permission_codes?:array<int,string>} $data
     *
     * @return array<string, mixed>
     */
    public function updateRole(string $uuid, array $data, string $reason, Request $request): array
    {
        $role = $this->roles->findByUuid($uuid);

        if ($role === null) {
            throw new NotFoundException('That role does not exist.');
        }

        $oldPermissions = $this->roles->permissionCodes((int) $role['id']);

        $fields = array_intersect_key($data, array_flip(['name', 'description', 'hierarchy']));
        $newPermissions = $oldPermissions;

        $this->db->transaction(function () use ($role, $data, $fields, &$newPermissions, $request): void {
            if ($fields !== []) {
                $this->roles->update((int) $role['id'], $fields, $request->authUserId());
            }

            if (array_key_exists('permission_codes', $data)) {
                $newPermissions = $this->validPermissionCodes($data['permission_codes']);
                $this->roles->syncPermissions((int) $role['id'], $newPermissions, $request->authUserId());
            }
        });

        $this->audit->log(
            entityName: 'roles',
            entityId: (int) $role['id'],
            action: 'update',
            oldValues: ['name' => $role['name'], 'permission_codes' => $oldPermissions],
            newValues: array_merge($fields, ['permission_codes' => $newPermissions]),
            request: $request,
            notes: $reason !== '' ? $reason : null,
        );

        return $this->roleDetail($uuid);
    }

    public function deleteRole(string $uuid, string $reason, Request $request): void
    {
        $role = $this->roles->findByUuid($uuid);

        if ($role === null) {
            throw new NotFoundException('That role does not exist.');
        }

        if ((int) $role['is_system'] === 1) {
            throw new HttpException('System roles cannot be deleted.', 422);
        }

        if ($this->roles->userCount((int) $role['id']) > 0) {
            throw new HttpException(
                'This role is still assigned to one or more users. Reassign them first.',
                409
            );
        }

        $this->roles->softDelete((int) $role['id'], $request->authUserId());

        $this->audit->log(
            entityName: 'roles',
            entityId: (int) $role['id'],
            action: 'delete',
            oldValues: ['code' => $role['code'], 'name' => $role['name']],
            request: $request,
            notes: $reason !== '' ? $reason : null,
        );
    }

    /**
     * @param array<int, string> $codes
     *
     * @return array<int, string>
     */
    private function validPermissionCodes(array $codes): array
    {
        $known = array_map(static fn (array $p): string => (string) $p['code'], $this->permissions->all());

        return array_values(array_intersect($known, $codes));
    }
}
