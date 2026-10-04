<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\RoleRepository;

/**
 * Admin Privilege Management: permission lookup and the password
 * re-confirmation gate behind the console's Admin Privilege sidebar page
 * (admin/access-control.html).
 *
 * Used to also hold a separate password sign-in for a standalone panel
 * (item 1: "Separate Admin Login") — its own token pair, only issued to a
 * role carrying admin_privilege.access, revoked again immediately if not.
 * That panel is retired; the sidebar page shares the ordinary console
 * session (the same JWT/refresh-token flow as every other admin screen).
 * What replaced the separate login is confirmAccess(): not a sign-in, but a
 * step-up check — "you're already signed into the console, now type your
 * password again" — so that anyone who merely finds an unattended console
 * tab open cannot click straight into role/permission editing without
 * knowing that password. See AuthService::verifyOwnPassword().
 */
final class AdminPrivilegeAuthService
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RoleRepository $roles,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function me(Request $request): array
    {
        $user = (array) $request->attribute('auth_user');
        $roleCode = (string) ($user['role_code'] ?? '');

        return [
            'user' => $this->auth->publicUser($user),
            'permissions' => $this->permissionsForRole($roleCode),
        ];
    }

    /**
     * The gate itself. By the time this runs, 'auth' + 'adminPrivilege:
     * admin_privilege.access' middleware has already confirmed the bearer
     * token belongs to a role allowed into this section at all — this only
     * adds the "prove it's really you, right now" step on top.
     *
     * @return array<string, mixed>
     */
    public function confirmAccess(Request $request, string $password): array
    {
        $userId = (int) $request->authUserId();

        try {
            $this->auth->verifyOwnPassword($userId, $password, $request);
        } catch (\Throwable $exception) {
            $this->audit->log(
                entityName: 'admin_privilege',
                entityId: $userId,
                action: 'reauth_denied',
                request: $request,
                notes: 'Wrong password on the Admin Privilege confirmation screen.',
            );

            throw $exception;
        }

        $this->audit->log(
            entityName: 'admin_privilege',
            entityId: $userId,
            action: 'reauth_confirmed',
            request: $request,
        );

        return $this->me($request);
    }

    /** @return array<int, string> */
    private function permissionsForRole(string $roleCode): array
    {
        $role = $this->roles->findByCode($roleCode);

        if ($role === null) {
            return [];
        }

        return $this->roles->permissionCodes((int) $role['id']);
    }
}
