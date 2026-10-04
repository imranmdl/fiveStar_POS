<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\LoyaltyRepository;
use App\Repositories\SettingRepository;

/**
 * Customer Loyalty Program: points earned on purchases (and optionally
 * reviews/referrals), redeemed into wallet credit.
 *
 * Same three guarantees WalletService makes for rupees, made here for
 * points — see that class's own doc comment for the reasoning behind each:
 * nothing in loyalty_ledger is ever edited (append-only triggers), every
 * balance mutation holds a row lock, and every earn is idempotent via a
 * caller-supplied key.
 *
 * REDEMPTION PRODUCES A WALLET CREDIT, NOT A NEW DISCOUNT MECHANISM. Points
 * are their own currency with their own earn rate and expiry, but once
 * redeemed they become an ordinary wallet credit (WalletService::credit(),
 * source = redemption) — the exact tender checkout and POS already accept
 * and apply. That is a deliberate simplification: it means every place this
 * brief asks for "show points during checkout/POS" only has to show a
 * balance and a redeem action, not recompute GST or interact with the offer
 * engine, because nothing about how a wallet credit is spent has to change.
 */
final class LoyaltyService
{
    public const SOURCE_PURCHASE_ONLINE = 'purchase_online';
    public const SOURCE_PURCHASE_POS = 'purchase_pos';
    public const SOURCE_REVIEW = 'review';
    public const SOURCE_REFERRAL = 'referral';
    public const SOURCE_REDEMPTION = 'redemption';
    public const SOURCE_EXPIRY = 'expiry';
    public const SOURCE_ADMIN = 'admin_adjustment';

    public function __construct(
        private readonly LoyaltyRepository $loyalty,
        private readonly SettingRepository $settings,
        private readonly WalletService $wallet,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->boolValue('loyalty_enabled', false);
    }

    /** @return array<string, mixed> */
    public function accountFor(int $userId): array
    {
        $account = $this->loyalty->findAccountForUser($userId);

        if ($account === null) {
            $accountId = $this->loyalty->createAccount($userId);
            $account = (array) $this->loyalty->findById($accountId);
        }

        return $account;
    }

    /** @return array<string, mixed> */
    public function summary(int $userId): array
    {
        $account = $this->accountFor($userId);

        return [
            'balance' => (int) $account['points_balance'],
            'lifetime_earned' => (int) $account['lifetime_earned'],
            'lifetime_redeemed' => (int) $account['lifetime_redeemed'],
            'is_frozen' => (bool) $account['is_frozen'],
            'redeem_value_per_point' => $this->redeemValuePerPoint(),
            'min_redeem_points' => $this->minRedeemPoints(),
            'max_redeem_points_per_order' => $this->maxRedeemPointsPerOrder(),
        ];
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function statement(int $userId, array $params): array
    {
        $this->accountFor($userId);

        return $this->loyalty->statement($userId, $params);
    }

    /** Admin: store-wide dashboard totals. */
    public function adminSummary(): array
    {
        return $this->loyalty->summary() + [
            'enabled' => $this->isEnabled(),
        ];
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function adminList(array $params, ?string $search): array
    {
        return $this->loyalty->adminList($params, $search);
    }

    /** @return array<string, mixed> */
    public function settingsForAdmin(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'rupees_per_point' => $this->rupeesPerPoint(),
            'redeem_value_per_point' => $this->redeemValuePerPoint(),
            'min_redeem_points' => $this->minRedeemPoints(),
            'max_redeem_points_per_order' => $this->maxRedeemPointsPerOrder(),
            'points_expiry_days' => $this->pointsExpiryDays(),
            'review_points_enabled' => $this->settings->boolValue('loyalty_review_points_enabled', false),
            'points_per_review' => $this->settings->intValue('loyalty_points_per_review', 20),
            'referral_points_enabled' => $this->settings->boolValue('loyalty_referral_points_enabled', false),
            'points_per_referral' => $this->settings->intValue('loyalty_points_per_referral', 100),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateSettings(array $data, Request $request): array
    {
        $map = [
            'enabled' => ['loyalty_enabled', 'bool'],
            'rupees_per_point' => ['loyalty_rupees_per_point', 'int'],
            'redeem_value_per_point' => ['loyalty_redeem_value_per_point', 'decimal'],
            'min_redeem_points' => ['loyalty_min_redeem_points', 'int'],
            'max_redeem_points_per_order' => ['loyalty_max_redeem_points_per_order', 'int'],
            'points_expiry_days' => ['loyalty_points_expiry_days', 'int'],
            'review_points_enabled' => ['loyalty_review_points_enabled', 'bool'],
            'points_per_review' => ['loyalty_points_per_review', 'int'],
            'referral_points_enabled' => ['loyalty_referral_points_enabled', 'bool'],
            'points_per_referral' => ['loyalty_points_per_referral', 'int'],
        ];

        $changes = [];
        // Validator::make() fills every 'nullable' rule's key with null even
        // when the client never sent it (see Validator::run()), so checking
        // presence against $data would treat "not supplied" the same as
        // "explicitly cleared" and silently reset every other setting to its
        // falsy default on every partial save. Checking the RAW request body
        // instead — the same fix PromotionController::updateOffer() already
        // uses for the identical Validator behaviour — is what makes this a
        // genuine partial update.
        $raw = $request->all();

        foreach ($map as $inputKey => [$settingKey, $type]) {
            if (!array_key_exists($inputKey, $raw) || $data[$inputKey] === null) {
                continue;
            }

            $value = $type === 'bool' ? ((bool) $data[$inputKey] ? '1' : '0') : (string) $data[$inputKey];
            $this->settings->put($settingKey, $value, $request->authUserId());
            $changes[$settingKey] = $value;
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $this->audit->log(
            entityName: 'settings',
            entityId: null,
            action: 'loyalty_settings_update',
            newValues: $changes,
            request: $request,
        );

        return $this->settingsForAdmin();
    }

    /**
     * Adds points. Every earn path (purchase/review/referral/admin) funnels
     * through here so idempotency and the ledger write are handled in
     * exactly one place.
     *
     * @return array<string, mixed> The ledger entry (existing one on a retry)
     */
    public function earn(
        int $userId,
        int $points,
        string $source,
        string $narration,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?Request $request = null,
    ): array {
        if ($points <= 0) {
            throw new HttpException('A points credit must be greater than zero.', 422);
        }

        $existing = $this->loyalty->findEntryByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $account = $this->accountFor($userId);
        $expiryDays = $this->pointsExpiryDays();

        $entry = $this->db->transaction(function () use ($account, $userId, $points, $source, $narration, $idempotencyKey, $referenceType, $referenceId, $expiryDays, $request): array {
            $locked = $this->loyalty->lockAccountForUpdate((int) $account['id']);

            if ($locked === null) {
                throw new NotFoundException('Loyalty account not found.');
            }

            $balanceAfter = (int) $locked['points_balance'] + $points;

            $ledgerId = $this->loyalty->appendEntry([
                'account_id' => (int) $locked['id'],
                'user_id' => $userId,
                'direction' => 'credit',
                'source' => $source,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'expires_date' => $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $expiryDays . ' days')) : null,
                'narration' => $narration,
                'actor_id' => $request?->authUserId(),
            ]);

            return (array) $this->db->selectOne('SELECT * FROM `loyalty_ledger` WHERE `id` = :id', ['id' => $ledgerId]);
        });

        $this->audit->log(
            entityName: 'loyalty_ledger',
            entityId: (int) $entry['id'],
            action: 'earn',
            newValues: ['points' => $points, 'source' => $source, 'balance_after' => $entry['balance_after']],
            request: $request,
            entityUuid: (string) $entry['uuid'],
            notes: $narration,
        );

        return $entry;
    }

    /**
     * Points for a completed, paid purchase — online or POS, same formula.
     * Called at the moment PaymentService confirms an online order's payment,
     * or PosSaleService finalises a till sale.
     */
    public function earnForPurchase(
        int $userId,
        Money $orderValue,
        string $channel,
        string $referenceType,
        string $referenceId,
        ?Request $request = null,
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $rupeesPerPoint = $this->rupeesPerPoint();

        if ($rupeesPerPoint <= 0) {
            return;
        }

        $points = (int) floor((float) $orderValue->toDecimal() / $rupeesPerPoint);

        if ($points <= 0) {
            return;
        }

        $source = $channel === 'pos' ? self::SOURCE_PURCHASE_POS : self::SOURCE_PURCHASE_ONLINE;

        try {
            $this->earn(
                userId: $userId,
                points: $points,
                source: $source,
                narration: sprintf('%d point(s) earned on %s', $points, $referenceId),
                idempotencyKey: $source . ':' . $referenceId,
                referenceType: $referenceType,
                referenceId: $referenceId,
                request: $request,
            );
        } catch (\Throwable $exception) {
            // A loyalty-points failure must never break the sale/order it is
            // rewarding — same posture WelcomeBonusService takes at sign-in.
            $this->logger->error('Loyalty earn-for-purchase failed', [
                'user_id' => $userId,
                'reference' => $referenceId,
                'reason' => $exception->getMessage(),
            ], 'loyalty');
        }
    }

    /** Points for an approved product review, if enabled. */
    public function earnForReview(int $userId, string $reviewUuid, ?Request $request = null): void
    {
        if (!$this->isEnabled() || !$this->settings->boolValue('loyalty_review_points_enabled', false)) {
            return;
        }

        $points = $this->settings->intValue('loyalty_points_per_review', 20);

        if ($points <= 0) {
            return;
        }

        try {
            $this->earn(
                userId: $userId,
                points: $points,
                source: self::SOURCE_REVIEW,
                narration: sprintf('%d point(s) earned for an approved review', $points),
                idempotencyKey: 'review:' . $reviewUuid,
                referenceType: 'product_reviews',
                referenceId: $reviewUuid,
                request: $request,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Loyalty earn-for-review failed', [
                'user_id' => $userId,
                'review_uuid' => $reviewUuid,
                'reason' => $exception->getMessage(),
            ], 'loyalty');
        }
    }

    /** Points for the referrer of a qualifying referral, if enabled — alongside the existing wallet reward, not instead of it. */
    public function earnForReferral(int $referrerUserId, string $referralUuid, ?Request $request = null): void
    {
        if (!$this->isEnabled() || !$this->settings->boolValue('loyalty_referral_points_enabled', false)) {
            return;
        }

        $points = $this->settings->intValue('loyalty_points_per_referral', 100);

        if ($points <= 0) {
            return;
        }

        try {
            $this->earn(
                userId: $referrerUserId,
                points: $points,
                source: self::SOURCE_REFERRAL,
                narration: sprintf('%d point(s) earned for a successful referral', $points),
                idempotencyKey: 'referral:' . $referralUuid,
                referenceType: 'referrals',
                referenceId: $referralUuid,
                request: $request,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Loyalty earn-for-referral failed', [
                'user_id' => $referrerUserId,
                'referral_uuid' => $referralUuid,
                'reason' => $exception->getMessage(),
            ], 'loyalty');
        }
    }

    /**
     * Converts points into a wallet credit at the configured rate. This is
     * the ONLY way points leave the ledger other than expiry or an admin
     * deduction — there is no separate "apply points as a discount" path.
     *
     * @return array<string, mixed> {points_redeemed, rupees_credited, loyalty_entry, wallet_entry}
     */
    public function redeem(int $userId, int $points, Request $request): array
    {
        if (!$this->isEnabled()) {
            throw new HttpException('The loyalty program is not currently enabled.', 403);
        }

        if ($points <= 0) {
            throw new HttpException('Enter how many points to redeem.', 422);
        }

        $min = $this->minRedeemPoints();
        $max = $this->maxRedeemPointsPerOrder();

        if ($points < $min) {
            throw new HttpException(
                sprintf('A minimum of %d points can be redeemed at once.', $min),
                422,
                ['points' => [sprintf('Enter at least %d points.', $min)]]
            );
        }

        if ($max > 0 && $points > $max) {
            throw new HttpException(
                sprintf('At most %d points can be redeemed at once.', $max),
                422,
                ['points' => [sprintf('Enter at most %d points.', $max)]]
            );
        }

        $account = $this->accountFor($userId);

        if ((int) $account['is_frozen'] === 1) {
            throw new HttpException('Your loyalty account is temporarily on hold. Please contact support.', 403);
        }

        if ((int) $account['points_balance'] < $points) {
            throw new HttpException(
                sprintf('You have %d point(s) available, which is fewer than requested.', (int) $account['points_balance']),
                409,
                ['points' => ['You do not have enough points for this.']]
            );
        }

        $rupees = Money::fromDecimal(number_format($points * $this->redeemValuePerPoint(), 2, '.', ''));
        $idempotencyKey = 'redeem:' . $userId . ':' . bin2hex(random_bytes(8));

        $entry = $this->db->transaction(function () use ($account, $userId, $points, $idempotencyKey, $request): array {
            $locked = $this->loyalty->lockAccountForUpdate((int) $account['id']);

            if ($locked === null) {
                throw new NotFoundException('Loyalty account not found.');
            }

            if ((int) $locked['points_balance'] < $points) {
                throw new HttpException('You do not have enough points for this.', 409);
            }

            $balanceAfter = (int) $locked['points_balance'] - $points;

            $ledgerId = $this->loyalty->appendEntry([
                'account_id' => (int) $locked['id'],
                'user_id' => $userId,
                'direction' => 'debit',
                'source' => self::SOURCE_REDEMPTION,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'reference_type' => 'wallet_transactions',
                'reference_id' => null,
                'idempotency_key' => $idempotencyKey,
                'narration' => sprintf('%d point(s) redeemed to wallet', $points),
                'actor_id' => $request->authUserId(),
            ]);

            return (array) $this->db->selectOne('SELECT * FROM `loyalty_ledger` WHERE `id` = :id', ['id' => $ledgerId]);
        });

        $walletEntry = $this->wallet->credit(
            userId: $userId,
            amount: $rupees,
            source: WalletService::SOURCE_REDEMPTION,
            narration: sprintf('%d loyalty point(s) redeemed', $points),
            idempotencyKey: 'loyalty:' . $entry['uuid'],
            referenceType: 'loyalty_ledger',
            referenceId: (string) $entry['uuid'],
            request: $request,
        );

        // Fill in the wallet-side reference now that it exists — the ledger
        // row itself cannot be edited (append-only), so this links the two
        // records the other direction: the wallet entry already carries
        // reference_type=loyalty_ledger/reference_id=<this uuid> from the
        // credit() call above.
        $this->audit->log(
            entityName: 'loyalty_ledger',
            entityId: (int) $entry['id'],
            action: 'redeem',
            newValues: ['points' => $points, 'rupees_credited' => $rupees->toDecimal()],
            request: $request,
            entityUuid: (string) $entry['uuid'],
        );

        return [
            'points_redeemed' => $points,
            'rupees_credited' => $rupees->toDecimal(),
            'new_points_balance' => (int) $entry['balance_after'],
            'wallet_entry_uuid' => $walletEntry['uuid'],
        ];
    }

    /** Admin: add or deduct points manually, with a mandatory reason. */
    public function adjustManually(string $userUuid, int $points, string $direction, string $reason, Request $request): array
    {
        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw new HttpException('Direction must be either credit or debit.', 422);
        }

        if ($points <= 0) {
            throw new HttpException('Enter a positive number of points.', 422);
        }

        if (trim($reason) === '') {
            throw new HttpException('A reason is required for a manual adjustment.', 422, [
                'reason' => ['Enter why this adjustment is being made.'],
            ]);
        }

        $userRow = $this->db->selectOne('SELECT `id` FROM `users` WHERE `uuid` = :uuid LIMIT 1', ['uuid' => $userUuid]);

        if ($userRow === null) {
            throw new NotFoundException('That customer does not exist.');
        }

        $userId = (int) $userRow['id'];

        if ($direction === 'credit') {
            $entry = $this->earn(
                userId: $userId,
                points: $points,
                source: self::SOURCE_ADMIN,
                narration: 'Manual adjustment: ' . $reason,
                idempotencyKey: 'admin:' . bin2hex(random_bytes(8)),
                request: $request,
            );

            return ['direction' => 'credit', 'points' => $points, 'entry_uuid' => $entry['uuid']];
        }

        $account = $this->accountFor($userId);

        if ((int) $account['points_balance'] < $points) {
            throw new HttpException(
                sprintf('This customer only has %d point(s) — cannot deduct %d.', (int) $account['points_balance'], $points),
                409
            );
        }

        $entry = $this->db->transaction(function () use ($account, $userId, $points, $reason, $request): array {
            $locked = $this->loyalty->lockAccountForUpdate((int) $account['id']);

            if ($locked === null || (int) $locked['points_balance'] < $points) {
                throw new HttpException('This customer does not have enough points for this deduction.', 409);
            }

            $balanceAfter = (int) $locked['points_balance'] - $points;

            $ledgerId = $this->loyalty->appendEntry([
                'account_id' => (int) $locked['id'],
                'user_id' => $userId,
                'direction' => 'debit',
                'source' => self::SOURCE_ADMIN,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'narration' => 'Manual adjustment: ' . $reason,
                'actor_id' => $request->authUserId(),
            ]);

            return (array) $this->db->selectOne('SELECT * FROM `loyalty_ledger` WHERE `id` = :id', ['id' => $ledgerId]);
        });

        $this->audit->log(
            entityName: 'loyalty_ledger',
            entityId: (int) $entry['id'],
            action: 'admin_adjustment',
            newValues: ['direction' => 'debit', 'points' => $points, 'reason' => $reason],
            request: $request,
            entityUuid: (string) $entry['uuid'],
        );

        return ['direction' => 'debit', 'points' => $points, 'entry_uuid' => $entry['uuid']];
    }

    /**
     * Writes off points past their expiry, posting a compensating debit for
     * each. Mirrors WalletService::expireCredits() exactly.
     *
     * @return array{expired_count:int, expired_points:int}
     */
    public function expireCredits(int $batchSize = 500): array
    {
        $count = 0;
        $total = 0;

        foreach ($this->loyalty->expirableCredits($batchSize) as $credit) {
            if ($this->loyalty->creditAlreadyExpired((int) $credit['id'])) {
                continue;
            }

            $points = (int) $credit['points'];
            $userId = (int) $credit['user_id'];
            $account = $this->accountFor($userId);
            $available = (int) $account['points_balance'];
            $writeOff = min($points, $available);

            if ($writeOff <= 0) {
                $this->loyalty->markCreditExpired((int) $credit['id'], null, 0);

                continue;
            }

            try {
                $entry = $this->db->transaction(function () use ($account, $userId, $writeOff, $credit): array {
                    $locked = $this->loyalty->lockAccountForUpdate((int) $account['id']);
                    $balanceAfter = (int) $locked['points_balance'] - $writeOff;

                    $ledgerId = $this->loyalty->appendEntry([
                        'account_id' => (int) $locked['id'],
                        'user_id' => $userId,
                        'direction' => 'debit',
                        'source' => self::SOURCE_EXPIRY,
                        'points' => $writeOff,
                        'balance_after' => $balanceAfter,
                        'reference_type' => 'loyalty_ledger',
                        'reference_id' => (string) $credit['uuid'],
                        'idempotency_key' => 'expiry:' . $credit['uuid'],
                        'narration' => 'Loyalty points expired',
                    ]);

                    return (array) $this->db->selectOne('SELECT * FROM `loyalty_ledger` WHERE `id` = :id', ['id' => $ledgerId]);
                });

                $this->loyalty->markCreditExpired((int) $credit['id'], (int) $entry['id'], $writeOff);

                ++$count;
                $total += $writeOff;
            } catch (\Throwable $exception) {
                $this->logger->error('Loyalty point expiry failed', [
                    'credit_uuid' => $credit['uuid'],
                    'reason' => $exception->getMessage(),
                ], 'loyalty');
            }
        }

        return ['expired_count' => $count, 'expired_points' => $total];
    }

    private function rupeesPerPoint(): float
    {
        return (float) $this->settings->intValue('loyalty_rupees_per_point', 100);
    }

    private function redeemValuePerPoint(): float
    {
        $value = $this->settings->value('loyalty_redeem_value_per_point');

        return $value === null || $value === '' ? 0.50 : (float) $value;
    }

    private function minRedeemPoints(): int
    {
        return $this->settings->intValue('loyalty_min_redeem_points', 100);
    }

    private function maxRedeemPointsPerOrder(): int
    {
        return $this->settings->intValue('loyalty_max_redeem_points_per_order', 2000);
    }

    private function pointsExpiryDays(): int
    {
        return $this->settings->intValue('loyalty_points_expiry_days', 365);
    }
}
