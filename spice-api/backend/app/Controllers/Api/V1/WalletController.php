<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Helpers\Money;
use App\Repositories\RefundRepository;
use App\Repositories\UserRepository;
use App\Services\ReferralService;
use App\Services\WalletService;

/**
 * Wallet and referral endpoints.
 *
 * Customers see their own balance, statement and referral progress.
 * Administrators can credit, adjust, freeze and qualify — every one of which
 * lands in the append-only ledger and the audit trail.
 */
final class WalletController extends BaseController
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly ReferralService $referrals,
        private readonly UserRepository $users,
        private readonly RefundRepository $refunds,
    ) {
    }

    // -----------------------------------------------------------------------
    // Dashboard drill-down (store-wide, not one customer) — what each of the
    // Wallets page's summary tiles now opens into, so "Total wallet credits"
    // or "Pending refunds" is a real list, not just a number.
    // -----------------------------------------------------------------------

    /**
     * GET /api/v1/admin/wallet/transactions?flow=&source=&page=
     * `flow` (credit|debit), not `direction` — paginationParams() already
     * reads a `direction` query param for sort order (ASC/DESC); reusing
     * that name for this filter would silently collide with it.
     */
    public function adminTransactions(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $filters = [
            'direction' => (string) $request->query('flow', ''),
            'source' => (string) $request->query('source', ''),
        ];

        $result = $this->wallet->adminTransactions($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Transactions loaded');
    }

    /** GET /api/v1/admin/wallet/accounts?page= */
    public function adminAccounts(Request $request): Response
    {
        $params = $this->paginationParams($request, 'balance_amount', 50);
        $result = $this->wallet->topAccounts($params);

        return $this->paginated($result['items'], $result['total'], $params, 'Accounts loaded');
    }

    /** GET /api/v1/admin/wallet/pending-refunds?page= */
    public function adminPendingRefunds(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $result = $this->refunds->pendingForAdmin($params);

        return $this->paginated($result['items'], $result['total'], $params, 'Pending refunds loaded');
    }

    // -----------------------------------------------------------------------
    // Customer
    // -----------------------------------------------------------------------

    /** GET /api/v1/wallet */
    public function show(Request $request): Response
    {
        return Response::success(
            ['wallet' => $this->wallet->summary((int) $request->authUserId())],
            'Wallet loaded'
        );
    }

    /** GET /api/v1/wallet/statement */
    public function statement(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $result = $this->wallet->statement((int) $request->authUserId(), $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Statement loaded');
    }

    /** GET /api/v1/referrals */
    public function referralOverview(Request $request): Response
    {
        return Response::success(
            ['referral' => $this->referrals->overviewFor((int) $request->authUserId())],
            'Referral details loaded'
        );
    }

    /** GET /api/v1/referrals/history */
    public function referralHistory(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $result = $this->referrals->myReferrals((int) $request->authUserId(), $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Referral history loaded');
    }

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/wallet/{userUuid} */
    public function adminShow(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));
        $userId = (int) $user['id'];

        return Response::success(
            [
                'customer' => [
                    'uuid' => $user['uuid'],
                    'full_name' => $user['full_name'],
                    'mobile' => $user['mobile'],
                ],
                'wallet' => $this->wallet->summary($userId),
                'integrity' => $this->wallet->verifyIntegrity($userId),
            ],
            'Wallet loaded'
        );
    }

    /** GET /api/v1/admin/wallet/{userUuid}/statement */
    public function adminStatement(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));
        $params = $this->paginationParams($request, 'created_date', 200);

        $result = $this->wallet->statement((int) $user['id'], $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Statement loaded');
    }

    /** POST /api/v1/admin/wallet/{userUuid}/credit */
    public function adminCredit(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));

        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:100000',
            'narration' => 'required|string|min:3|max:255',
            'source' => 'nullable|in:promotional,cashback,order_refund,admin_adjustment',
            'expiry_days' => 'nullable|int|min:1|max:3650',
            'reference' => 'nullable|string|max:60',
        ]);

        // The idempotency key is caller-supplied so a double-clicked admin form
        // credits once. Without a reference, the amount and minute are used,
        // which stops the obvious accidental double-submit.
        $idempotencyKey = 'admin:' . $user['uuid'] . ':' . ($data['reference']
            ?? md5($data['narration'] . '|' . $data['amount'] . '|' . date('YmdHi')));

        $entry = $this->wallet->credit(
            userId: (int) $user['id'],
            amount: Money::fromDecimal($data['amount']),
            source: $data['source'] ?? WalletService::SOURCE_ADMIN,
            narration: $data['narration'],
            idempotencyKey: $idempotencyKey,
            referenceType: 'admin',
            referenceId: $data['reference'] ?? null,
            expiryDays: $data['expiry_days'] ?? null,
            request: $request,
        );

        return Response::created(
            [
                'transaction_uuid' => $entry['uuid'],
                'amount' => (float) $entry['amount'],
                'balance_after' => (float) $entry['balance_after'],
            ],
            'Wallet credited'
        );
    }

    /**
     * POST /api/v1/admin/wallet/{userUuid}/debit
     *
     * A correction, not an edit: the ledger is append-only, so recovering credit
     * means posting a compensating debit that stays visible beside the original.
     */
    public function adminDebit(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));

        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:100000',
            'narration' => 'required|string|min:3|max:255',
            'reference' => 'nullable|string|max:60',
        ]);

        $idempotencyKey = 'admin-debit:' . $user['uuid'] . ':' . ($data['reference']
            ?? md5($data['narration'] . '|' . $data['amount'] . '|' . date('YmdHi')));

        $entry = $this->wallet->debit(
            userId: (int) $user['id'],
            amount: Money::fromDecimal($data['amount']),
            source: WalletService::SOURCE_ADMIN,
            narration: $data['narration'],
            idempotencyKey: $idempotencyKey,
            referenceType: 'admin',
            referenceId: $data['reference'] ?? null,
            request: $request,
        );

        return Response::created(
            [
                'transaction_uuid' => $entry['uuid'],
                'amount' => (float) $entry['amount'],
                'balance_after' => (float) $entry['balance_after'],
            ],
            'Wallet debited'
        );
    }

    /** POST /api/v1/admin/wallet/{userUuid}/freeze */
    public function adminFreeze(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));

        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:255',
        ]);

        $this->wallet->freeze((int) $user['id'], $data['reason'], $request);

        return Response::success([], 'Wallet frozen. Credits still post; redemption is blocked.');
    }

    /** POST /api/v1/admin/wallet/{userUuid}/unfreeze */
    public function adminUnfreeze(Request $request): Response
    {
        $user = $this->requireUser((string) $request->routeParam('userUuid'));

        $this->wallet->unfreeze((int) $user['id'], $request);

        return Response::success([], 'Wallet unfrozen');
    }

    /** POST /api/v1/admin/wallet/expire-credits */
    public function adminExpireCredits(Request $request): Response
    {
        return Response::success(
            $this->wallet->expireCredits(500),
            'Expired credits written off'
        );
    }

    /** GET /api/v1/admin/referrals */
    public function adminReferrals(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 200);
        $status = $request->query('status');

        $result = $this->referrals->paginateForAdmin(
            $params,
            is_string($status) && $status !== '' ? $status : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Referrals loaded');
    }

    /** POST /api/v1/admin/referrals/{uuid}/qualify */
    public function adminQualifyReferral(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'order_reference' => 'required|string|min:3|max:50',
            'order_value' => 'required|numeric|min:1',
        ]);

        return Response::success(
            [
                'referral' => $this->referrals->qualifyManually(
                    (string) $request->routeParam('uuid'),
                    $data['order_reference'],
                    Money::fromDecimal($data['order_value']),
                    $request
                ),
            ],
            'Referral qualified and rewards paid'
        );
    }

    /** POST /api/v1/admin/referrals/{uuid}/cancel */
    public function adminCancelReferral(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:255',
        ]);

        $this->referrals->cancel((string) $request->routeParam('uuid'), $data['reason'], $request);

        return Response::success(
            [],
            'Referral cancelled. Credit already paid is not reversed automatically — '
            . 'post a wallet adjustment if recovery is warranted.'
        );
    }

    /** @return array<string, mixed> */
    private function requireUser(string $uuid): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw new NotFoundException('That customer does not exist.');
        }

        return $user;
    }
}
