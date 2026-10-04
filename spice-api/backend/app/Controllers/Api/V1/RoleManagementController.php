<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\RoleManagementService;

/**
 * Admin Privilege Management item 3: "Custom Permissions" — role CRUD and
 * the view/add/edit/delete/approve/export permission matrix per role.
 */
final class RoleManagementController extends BaseController
{
    public function __construct(private readonly RoleManagementService $service)
    {
    }

    /** GET /api/v1/admin-privilege/roles */
    public function index(Request $request): Response
    {
        return Response::success(['roles' => $this->service->listRoles()], 'Roles loaded');
    }

    /** GET /api/v1/admin-privilege/permissions */
    public function permissions(Request $request): Response
    {
        return Response::success(['modules' => $this->service->listPermissions()], 'Permissions loaded');
    }

    /** GET /api/v1/admin-privilege/roles/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success($this->service->roleDetail((string) $request->routeParam('uuid')), 'Role loaded');
    }

    /** POST /api/v1/admin-privilege/roles */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'code' => 'required|string|min:2|max:50',
            'name' => 'required|string|min:2|max:100',
            'description' => 'nullable|string|max:255',
            'hierarchy' => 'nullable|int|min:1|max:990',
            'permission_codes' => 'nullable|array',
        ]);

        $result = $this->service->createRole([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'hierarchy' => isset($data['hierarchy']) ? (int) $data['hierarchy'] : 100,
            'permission_codes' => $data['permission_codes'] ?? [],
        ], $request);

        return Response::created($result, 'Role created');
    }

    /** PATCH /api/v1/admin-privilege/roles/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|min:2|max:100',
            'description' => 'nullable|string|max:255',
            'hierarchy' => 'nullable|int|min:1|max:990',
            'permission_codes' => 'nullable|array',
            'reason' => 'nullable|string|max:255',
        ]);

        $fields = array_filter([
            'name' => $data['name'] ?? null,
            'description' => array_key_exists('description', $request->all()) ? $data['description'] : null,
            'hierarchy' => isset($data['hierarchy']) ? (int) $data['hierarchy'] : null,
        ], static fn ($v) => $v !== null);

        if (array_key_exists('permission_codes', $data)) {
            $fields['permission_codes'] = $data['permission_codes'];
        }

        $result = $this->service->updateRole(
            (string) $request->routeParam('uuid'),
            $fields,
            (string) ($data['reason'] ?? ''),
            $request
        );

        return Response::success($result, 'Role updated');
    }

    /** DELETE /api/v1/admin-privilege/roles/{uuid} */
    public function destroy(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
        ]);

        $this->service->deleteRole(
            (string) $request->routeParam('uuid'),
            (string) ($data['reason'] ?? ''),
            $request
        );

        return Response::success([], 'Role deleted');
    }
}
