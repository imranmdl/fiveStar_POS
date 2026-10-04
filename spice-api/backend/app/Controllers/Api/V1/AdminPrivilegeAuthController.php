<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AdminPrivilegeAuthService;

/**
 * Admin Privilege Management: the permission check and password
 * re-confirmation gate behind the console's Admin Privilege sidebar page
 * (admin/access-control.html).
 *
 * There used to be a separate login here (item 1's "Separate Admin Login"),
 * issuing its own isolated token pair for a standalone panel. That panel is
 * retired — the sidebar page shares the ordinary console session instead.
 * confirm() replaces it with a lighter step-up check, not a sign-in: the
 * caller is already authenticated (this route sits behind the same
 * 'adminPrivilege:admin_privilege.access' gate every other route here does),
 * they just have to type their password again before the page shows role
 * editing, staff accounts, or the audit trail.
 */
final class AdminPrivilegeAuthController extends BaseController
{
    public function __construct(private readonly AdminPrivilegeAuthService $service)
    {
    }

    /** GET /api/v1/admin-privilege/auth/me */
    public function me(Request $request): Response
    {
        return Response::success($this->service->me($request), 'Profile loaded');
    }

    /** POST /api/v1/admin-privilege/auth/confirm */
    public function confirm(Request $request): Response
    {
        $data = Validator::make($request->all(), ['password' => 'required|string|max:72']);

        return Response::success(
            $this->service->confirmAccess($request, $data['password']),
            'Confirmed'
        );
    }
}
