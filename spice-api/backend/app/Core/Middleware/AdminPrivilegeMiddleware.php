<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Config;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AdminPrivilegeActivityRepository;
use App\Repositories\RoleRepository;
use App\Services\AuditService;

/**
 * Authorisation for the Admin Privilege Management panel, separate from the
 * ordinary `role:` gate used everywhere else in this API.
 *
 * Route usage (after 'auth', which authenticates the bearer token exactly as
 * it does for every other endpoint):
 *
 *   ['auth', 'adminPrivilege:role_management.edit']
 *
 * Three things happen here that AuthorizeRoleMiddleware does not do:
 *   1. Permission is resolved from the live role_permissions matrix, not a
 *      hard-coded role list — so revoking a permission in the role editor
 *      takes effect on the very next request, with no new token needed.
 *   2. Every request refreshes admin_privilege_activity, and a request that
 *      arrives after too long an idle gap is rejected even though the JWT
 *      itself has not expired (item 6: auto-expire inactive admin sessions).
 *   3. A denial is written to the audit trail — an ordinary 403 anywhere
 *      else in the API is not, but "someone without Admin Privilege access
 *      tried the panel" is exactly the kind of event this panel exists to
 *      surface.
 */
final class AdminPrivilegeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly AdminPrivilegeActivityRepository $activity,
        private readonly AuditService $audit,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, callable $next, array $arguments = []): Response
    {
        $user = $request->attribute('auth_user');

        if ($user === null) {
            throw new UnauthorizedException();
        }

        $userId = (int) $user['id'];
        $roleCode = (string) ($user['role_code'] ?? '');
        $requiredPermission = $arguments[0] ?? 'admin_privilege.access';

        // The dashboard gate itself. Every finer-grained module.action check
        // below implies it, but checking it explicitly first gives a clear,
        // consistent denial reason regardless of which specific permission a
        // route also asks for.
        if (!$this->roles->roleHasPermission($roleCode, 'admin_privilege.access')) {
            $this->audit->log(
                entityName: 'admin_privilege',
                entityId: $userId,
                action: 'access_denied',
                newValues: ['reason' => 'no admin_privilege.access permission', 'path' => $request->path],
                request: $request,
            );

            throw new ForbiddenException('This account is not authorised for the Admin Privilege panel.');
        }

        if ($requiredPermission !== 'admin_privilege.access'
            && !$this->roles->roleHasPermission($roleCode, (string) $requiredPermission)) {
            $this->audit->log(
                entityName: 'admin_privilege',
                entityId: $userId,
                action: 'permission_denied',
                newValues: ['permission' => $requiredPermission, 'path' => $request->path],
                request: $request,
            );

            throw new ForbiddenException("You do not have the '{$requiredPermission}' permission.");
        }

        $idleLimitMinutes = (int) $this->config->get('auth.admin_privilege.idle_timeout_minutes', 20);
        $idleSeconds = $this->activity->idleSeconds($userId);

        if ($idleSeconds !== null && $idleSeconds > $idleLimitMinutes * 60) {
            $this->activity->clear($userId);

            $this->audit->log(
                entityName: 'admin_privilege',
                entityId: $userId,
                action: 'session_idle_timeout',
                newValues: ['idle_seconds' => $idleSeconds],
                request: $request,
            );

            throw new UnauthorizedException('Your Admin Privilege session expired after a period of inactivity. Please sign in again.');
        }

        $this->activity->touch($userId, $request->ip, $request->userAgent);

        return $next($request);
    }
}
