<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Admin Privilege Management item 2/module "User Management": list, create,
 * change the role of, lock/unlock and force-logout any account — with every
 * change written to the audit trail (item 5).
 *
 * This never touches password verification or token issuance itself
 * (AuthService/TokenService still own that entirely); it only manages the
 * `users` row and, where a change should end existing sessions, delegates to
 * TokenService exactly the way AuthService's own password-reset path does.
 */
final class AdminUserManagementService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly TokenService $tokens,
        private readonly AuditService $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * Columns stripped from every row this service returns to a controller.
     * password_hash is the one that actually matters; the rest are internal
     * bookkeeping nobody outside this service needs.
     *
     * @var array<int, string>
     */
    private const HIDDEN_COLUMNS = ['password_hash'];

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $params, ?string $roleCode): array
    {
        $result = $this->users->paginateAll($params, $roleCode);
        $result['items'] = array_map([$this, 'sanitize'], $result['items']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function show(string $uuid): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        return $this->sanitize($user);
    }

    /** @param array<string, mixed> $user */
    private function sanitize(array $user): array
    {
        foreach (self::HIDDEN_COLUMNS as $column) {
            unset($user[$column]);
        }

        return $user;
    }

    /**
     * @param array{full_name:string, mobile:string, email:?string, password:string, role_uuid:string} $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        $role = $this->roles->findByUuid($data['role_uuid']);

        if ($role === null) {
            throw new HttpException('That role does not exist.', 422, ['role_uuid' => ['Unknown role.']]);
        }

        if ($this->users->mobileExists($data['mobile'])) {
            throw new HttpException('This mobile number is already registered.', 409, ['mobile' => ['Already in use.']]);
        }

        if (!empty($data['email']) && $this->users->emailExists($data['email'])) {
            throw new HttpException('This email address is already registered.', 409, ['email' => ['Already in use.']]);
        }

        $referral = 'STF' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9));

        while ($this->users->referralCodeExists($referral)) {
            $referral = 'STF' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9));
        }

        $userId = $this->users->create([
            'role_id' => (int) $role['id'],
            'full_name' => $data['full_name'],
            'mobile' => $data['mobile'],
            'email' => empty($data['email']) ? null : strtolower((string) $data['email']),
            'password_hash' => $this->hashPassword($data['password']),
            'status' => 'active',
            'mobile_verified_date' => date('Y-m-d H:i:s'),
            'email_verified_date' => empty($data['email']) ? null : date('Y-m-d H:i:s'),
            'referral_code' => $referral,
            'is_active' => 1,
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'users',
            entityId: $userId,
            action: 'admin_create_user',
            newValues: ['full_name' => $data['full_name'], 'role' => $role['code']],
            request: $request,
            notes: 'Created from the Admin Privilege panel'
        );

        $created = $this->users->findById($userId);

        return $this->show((string) ($created['uuid'] ?? ''));
    }

    public function changeRole(string $uuid, string $roleUuid, string $reason, Request $request): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        $role = $this->roles->findByUuid($roleUuid);

        if ($role === null) {
            throw new HttpException('That role does not exist.', 422);
        }

        $oldRole = $user['role_code'];
        $this->users->update((int) $user['id'], ['role_id' => (int) $role['id']], $request->authUserId());
        // A role change is a privilege change: every existing session must
        // re-authenticate under the new role rather than keep using a JWT
        // whose `role` claim still says the old one.
        $this->tokens->revokeAllForUser((int) $user['id'], 'role_changed');

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'role_changed',
            oldValues: ['role' => $oldRole],
            newValues: ['role' => $role['code']],
            request: $request,
            notes: $reason !== '' ? $reason : null,
        );

        return $this->show($uuid);
    }

    public function setActive(string $uuid, bool $active, string $reason, Request $request): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        if (!$active && $reason === '') {
            throw new HttpException('A reason is required to deactivate an account.', 422, [
                'reason' => ['This field is required.'],
            ]);
        }

        $this->users->update((int) $user['id'], [
            'is_active' => $active ? 1 : 0,
            'status' => $active ? 'active' : 'suspended',
        ], $request->authUserId());

        if (!$active) {
            $this->tokens->revokeAllForUser((int) $user['id'], 'deactivated_by_admin');
        }

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: $active ? 'reactivated' : 'deactivated',
            oldValues: ['status' => $user['status']],
            newValues: ['status' => $active ? 'active' : 'suspended'],
            request: $request,
            notes: $reason !== '' ? $reason : null,
        );

        return $this->show($uuid);
    }

    public function unlock(string $uuid, Request $request): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        $this->users->unlock((int) $user['id']);

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'unlocked',
            request: $request,
            notes: 'Lockout cleared from the Admin Privilege panel'
        );

        return $this->show($uuid);
    }

    public function forceLogout(string $uuid, Request $request): int
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        $revoked = $this->tokens->revokeAllForUser((int) $user['id'], 'admin_forced_logout');

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'force_logout',
            newValues: ['sessions_revoked' => $revoked],
            request: $request,
        );

        return $revoked;
    }

    /**
     * Sets a new password an admin chooses, and ends every existing session
     * for that account — same effect as AuthService::resetPassword, just
     * triggered by an admin instead of an OTP.
     */
    public function resetPassword(string $uuid, string $newPassword, Request $request): void
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That user does not exist.');
        }

        $this->users->updatePassword((int) $user['id'], $this->hashPassword($newPassword));
        $this->tokens->revokeAllForUser((int) $user['id'], 'admin_password_reset');

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'admin_password_reset',
            request: $request,
        );
    }

    private function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) $this->config->get('auth.password.bcrypt_cost', 12)]);
    }
}
