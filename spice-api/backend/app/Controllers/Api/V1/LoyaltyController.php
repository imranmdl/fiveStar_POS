<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\UserRepository;
use App\Services\LoyaltyService;

/**
 * Loyalty points endpoints.
 *
 * Customers see their own balance, statement and can redeem into wallet
 * credit. Administrators view store-wide totals, any customer's ledger,
 * edit the program's settings and manually adjust a balance — every one of
 * which lands in the append-only ledger and the audit trail.
 */
final class LoyaltyController extends BaseController
{
    public function __construct(
        private readonly LoyaltyService $loyalty,
        private readonly UserRepository $users,
    ) {
    }

    // -----------------------------------------------------------------------
    // Customer
    // -----------------------------------------------------------------------

    /** GET /api/v1/loyalty */
    public function show(Request $request): Response
    {
        return Response::success(
            ['loyalty' => $this->loyalty->summary((int) $request->authUserId())],
            'Loyalty points loaded'
        );
    }

    /** GET /api/v1/loyalty/statement */
    public function statement(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $result = $this->loyalty->statement((int) $request->authUserId(), $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Statement loaded');
    }

    /** POST /api/v1/loyalty/redeem */
    public function redeem(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'points' => 'required|int|min:1',
        ]);

        return Response::success(
            $this->loyalty->redeem((int) $request->authUserId(), (int) $data['points'], $request),
            'Points redeemed to wallet'
        );
    }

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/loyalty/summary */
    public function adminSummary(Request $request): Response
    {
        return Response::success(['summary' => $this->loyalty->adminSummary()], 'Summary loaded');
    }

    /** GET /api/v1/admin/loyalty/settings */
    public function adminSettings(Request $request): Response
    {
        return Response::success(['settings' => $this->loyalty->settingsForAdmin()], 'Settings loaded');
    }

    /** PATCH /api/v1/admin/loyalty/settings */
    public function updateSettings(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'enabled' => 'nullable|boolean',
            'rupees_per_point' => 'nullable|int|min:1|max:100000',
            'redeem_value_per_point' => 'nullable|numeric|min:0.01|max:1000',
            'min_redeem_points' => 'nullable|int|min:1|max:1000000',
            'max_redeem_points_per_order' => 'nullable|int|min:0|max:1000000',
            'points_expiry_days' => 'nullable|int|min:0|max:3650',
            'review_points_enabled' => 'nullable|boolean',
            'points_per_review' => 'nullable|int|min:0|max:100000',
            'referral_points_enabled' => 'nullable|boolean',
            'points_per_referral' => 'nullable|int|min:0|max:100000',
        ]);

        return Response::success(
            ['settings' => $this->loyalty->updateSettings($data, $request)],
            'Settings updated'
        );
    }

    /** GET /api/v1/admin/loyalty/accounts?search=&page= */
    public function adminList(Request $request): Response
    {
        $params = $this->paginationParams($request, 'points_balance', 50);
        $search = trim((string) $request->query('search', ''));
        $result = $this->loyalty->adminList($params, $search === '' ? null : $search);

        return $this->paginated($result['items'], $result['total'], $params, 'Accounts loaded');
    }

    /** GET /api/v1/admin/loyalty/accounts/{userUuid}/ledger */
    public function adminLedger(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));
        $params = $this->paginationParams($request, 'created_date', 200);
        $result = $this->loyalty->statement((int) $user['id'], $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Ledger loaded');
    }

    /** POST /api/v1/admin/loyalty/accounts/{userUuid}/adjust */
    public function adjust(Request $request): Response
    {
        $userUuid = (string) $request->routeParam('userUuid');

        $data = Validator::make($request->all(), [
            'points' => 'required|int|min:1',
            'direction' => 'required|in:credit,debit',
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->loyalty->adjustManually($userUuid, (int) $data['points'], $data['direction'], $data['reason'], $request),
            'Points adjusted'
        );
    }

    private function requireUser(string $uuid): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That customer does not exist.');
        }

        return $user;
    }
}
