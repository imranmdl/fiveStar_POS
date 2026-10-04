<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AdminUserManagementService;

/**
 * Admin Privilege Management module "User Management".
 */
final class AdminUserManagementController extends BaseController
{
    public function __construct(private readonly AdminUserManagementService $service)
    {
    }

    /** GET /api/v1/admin-privilege/users */
    public function index(Request $request): Response
    {
        $role = $request->query('role');
        $params = $this->paginationParams($request, 'created_date', 100);

        $result = $this->service->list($params, is_string($role) && $role !== '' ? $role : null);

        return $this->paginated($result['items'], $result['total'], $params, 'Users loaded');
    }

    /** GET /api/v1/admin-privilege/users/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            ['user' => $this->service->show((string) $request->routeParam('uuid'))],
            'User loaded'
        );
    }

    /** POST /api/v1/admin-privilege/users */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'full_name' => 'required|string|min:3|max:120',
            'mobile' => 'required|mobile_in',
            'email' => 'nullable|email|max:150',
            'password' => 'required|password|max:72',
            'role_uuid' => 'required|uuid',
        ]);

        $result = $this->service->create($data, $request);

        return Response::created(['user' => $result], 'User created');
    }

    /** PATCH /api/v1/admin-privilege/users/{uuid}/role */
    public function changeRole(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'role_uuid' => 'required|uuid',
            'reason' => 'nullable|string|max:255',
        ]);

        $result = $this->service->changeRole(
            (string) $request->routeParam('uuid'),
            $data['role_uuid'],
            (string) ($data['reason'] ?? ''),
            $request
        );

        return Response::success(['user' => $result], 'Role updated');
    }

    /** POST /api/v1/admin-privilege/users/{uuid}/activate */
    public function activate(Request $request): Response
    {
        $result = $this->service->setActive((string) $request->routeParam('uuid'), true, '', $request);

        return Response::success(['user' => $result], 'User activated');
    }

    /** POST /api/v1/admin-privilege/users/{uuid}/deactivate */
    public function deactivate(Request $request): Response
    {
        $data = Validator::make($request->all(), ['reason' => 'required|string|min:3|max:255']);

        $result = $this->service->setActive(
            (string) $request->routeParam('uuid'),
            false,
            $data['reason'],
            $request
        );

        return Response::success(['user' => $result], 'User deactivated');
    }

    /** POST /api/v1/admin-privilege/users/{uuid}/unlock */
    public function unlock(Request $request): Response
    {
        $result = $this->service->unlock((string) $request->routeParam('uuid'), $request);

        return Response::success(['user' => $result], 'Account unlocked');
    }

    /** POST /api/v1/admin-privilege/users/{uuid}/force-logout */
    public function forceLogout(Request $request): Response
    {
        $revoked = $this->service->forceLogout((string) $request->routeParam('uuid'), $request);

        return Response::success(['sessions_revoked' => $revoked], 'All sessions signed out');
    }

    /** POST /api/v1/admin-privilege/users/{uuid}/reset-password */
    public function resetPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), ['password' => 'required|password|max:72']);

        $this->service->resetPassword((string) $request->routeParam('uuid'), $data['password'], $request);

        return Response::success([], 'Password reset. Every existing session for this account has been signed out.');
    }
}
