<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\ReferralRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;

/**
 * Referral programme.
 *
 * The one rule that matters: A REFERRAL PAYS OUT ONLY AFTER THE REFEREE HAS
 * COMPLETED A QUALIFYING PAID ORDER. Rewarding at signup is an open invitation
 * to farm accounts, and by the time you notice, the wallet liability is real
 * money. So the lifecycle is:
 *
 *   pending  -> a friend signed up with the code
 *   qualified -> that friend paid for a qualifying order
 *   rewarded  -> wallet credit posted to both parties
 *   cancelled -> reversed by an administrator (fraud, refund, chargeback)
 *
 * Phase 5 calls qualifyForOrder() when an order's payment is verified. Until
 * then, an administrator can qualify a referral manually, which is also how
 * genuine edge cases get resolved after go-live.
 */
final class ReferralService
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly UserRepository $users,
        private readonly WalletService $wallet,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Records that a new customer signed up using someone's code.
     *
     * Called from AuthService at registration. Failures are logged rather than
     * thrown: a referral bookkeeping problem must never block a registration
     * that has otherwise succeeded.
     */
    public function recordSignup(int $refereeUserId, int $referrerUserId, string $codeUsed, ?Request $request = null): void
    {
        if ($refereeUserId === $referrerUserId) {
            // Also blocked by a CHECK constraint; caught here for a clean log.
            $this->logger->warning('Self-referral attempt ignored', [
                'user_id' => $refereeUserId,
                'code' => $codeUsed,
            ], 'referral');

            return;
        }

        try {
            if ($this->referrals->findByReferee($refereeUserId) !== null) {
                return;
            }

            $referralId = $this->referrals->create([
                'referrer_user_id' => $referrerUserId,
                'referee_user_id' => $refereeUserId,
                'referral_code_used' => strtoupper($codeUsed),
                'status' => 'pending',
                'signup_ip' => $request?->ip,
            ], $refereeUserId);

            $this->audit->log(
                entityName: 'referrals',
                entityId: $referralId,
                action: 'signup',
                newValues: ['referrer_user_id' => $referrerUserId, 'code' => strtoupper($codeUsed)],
                request: $request,
                notes: 'Pending until the new customer completes a qualifying order'
            );

            // Not a block, just a flag for review. A family sharing one
            // connection looks identical to abuse, so a human decides.
            if ($request !== null) {
                $fromSameIp = $this->referrals->countSignupsFromIp($request->ip, 24);

                if ($fromSameIp >= 3) {
                    $this->logger->warning('Multiple referral signups from one IP', [
                        'ip' => $request->ip,
                        'count_24h' => $fromSameIp,
                        'code' => strtoupper($codeUsed),
                    ], 'referral');
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Referral signup could not be recorded', [
                'referee_user_id' => $refereeUserId,
                'referrer_user_id' => $referrerUserId,
                'reason' => $exception->getMessage(),
            ], 'referral');
        }
    }

    /**
     * Qualifies and pays out a referral once the referee's order is confirmed.
     *
     * Wired into Phase 5 order confirmation. Idempotent at two levels: the
     * status transition is a conditional UPDATE, and the wallet credits carry
     * idempotency keys derived from the referral UUID.
     *
     * @return array<string, mixed>|null Null when there is nothing to qualify
     */
    public function qualifyForOrder(
        int $refereeUserId,
        string $orderReference,
        Money $orderValue,
        ?Request $request = null,
    ): ?array {
        $referral = $this->referrals->findByReferee($refereeUserId);

        if ($referral === null || $referral['status'] !== 'pending') {
            return null;
        }

        $minimum = $this->minimumOrderValue();

        if ($minimum !== null && $orderValue->lessThan($minimum)) {
            $this->logger->info('Referral not qualified: order below the threshold', [
                'referral_uuid' => $referral['uuid'],
                'order_value' => $orderValue->toDecimal(),
                'minimum' => $minimum->toDecimal(),
            ], 'referral');

            return null;
        }

        $referrerReward = $this->referrerReward();
        $refereeReward = $this->refereeReward();

        $qualified = $this->referrals->markQualified(
            (int) $referral['id'],
            $orderReference,
            (string) $orderValue,
            (string) $referrerReward,
            (string) $refereeReward
        );

        if (!$qualified) {
            // Another concurrent caller got there first.
            return null;
        }

        return $this->payout((int) $referral['id'], $request);
    }

    /**
     * Posts the wallet credits for a qualified referral and marks it rewarded.
     *
     * @return array<string, mixed>
     */
    public function payout(int $referralId, ?Request $request = null): array
    {
        $referral = $this->referrals->findById($referralId);

        if ($referral === null) {
            throw new NotFoundException('That referral does not exist.');
        }

        if ($referral['status'] === 'rewarded') {
            return $this->presentAdmin($referral);
        }

        if ($referral['status'] !== 'qualified') {
            throw new HttpException(
                'Only a qualified referral can be paid out. This one is ' . $referral['status'] . '.',
                409
            );
        }

        $referrerReward = Money::fromDecimal((string) $referral['referrer_reward_amount']);
        $refereeReward = Money::fromDecimal((string) $referral['referee_reward_amount']);
        $expiryDays = $this->rewardExpiryDays();

        // Both credits and the status change are one transaction: a partial
        // payout would leave one party paid and the other not, with no record.
        $this->db->transaction(function () use ($referral, $referrerReward, $refereeReward, $expiryDays, $request): void {
            if ($referrerReward->isPositive()) {
                $this->wallet->credit(
                    userId: (int) $referral['referrer_user_id'],
                    amount: $referrerReward,
                    source: WalletService::SOURCE_REFERRAL_REWARD,
                    narration: 'Referral reward — your friend placed their first order',
                    idempotencyKey: 'referral:' . $referral['uuid'] . ':referrer',
                    referenceType: 'referrals',
                    referenceId: (string) $referral['uuid'],
                    expiryDays: $expiryDays,
                    request: $request,
                );
            }

            if ($refereeReward->isPositive()) {
                $this->wallet->credit(
                    userId: (int) $referral['referee_user_id'],
                    amount: $refereeReward,
                    source: WalletService::SOURCE_REFERRAL_SIGNUP,
                    narration: 'Welcome bonus for joining through a referral',
                    idempotencyKey: 'referral:' . $referral['uuid'] . ':referee',
                    referenceType: 'referrals',
                    referenceId: (string) $referral['uuid'],
                    expiryDays: $expiryDays,
                    request: $request,
                );
            }

            $this->referrals->markRewarded((int) $referral['id']);
        });

        $this->audit->log(
            entityName: 'referrals',
            entityId: (int) $referral['id'],
            action: 'rewarded',
            newValues: [
                'referrer_reward' => $referrerReward->toDecimal(),
                'referee_reward' => $refereeReward->toDecimal(),
            ],
            request: $request,
            entityUuid: (string) $referral['uuid']
        );

        return $this->presentAdmin((array) $this->referrals->findById($referralId));
    }

    /**
     * Administrator override for genuine edge cases, and the manual path until
     * Phase 5 wires the automatic trigger.
     *
     * @return array<string, mixed>
     */
    public function qualifyManually(
        string $uuid,
        string $orderReference,
        Money $orderValue,
        Request $request,
    ): array {
        $referral = $this->referrals->findByUuid($uuid);

        if ($referral === null) {
            throw new NotFoundException('That referral does not exist.');
        }

        if ($referral['status'] !== 'pending') {
            throw new HttpException(
                'Only a pending referral can be qualified. This one is ' . $referral['status'] . '.',
                409
            );
        }

        $qualified = $this->referrals->markQualified(
            (int) $referral['id'],
            $orderReference,
            (string) $orderValue,
            (string) $this->referrerReward(),
            (string) $this->refereeReward()
        );

        if (!$qualified) {
            throw new HttpException('This referral was already qualified.', 409);
        }

        $this->audit->log(
            entityName: 'referrals',
            entityId: (int) $referral['id'],
            action: 'qualified_manually',
            newValues: ['order_reference' => $orderReference, 'order_value' => $orderValue->toDecimal()],
            request: $request,
            entityUuid: $uuid,
            notes: 'Manual qualification by an administrator'
        );

        return $this->payout((int) $referral['id'], $request);
    }

    /**
     * Cancels a referral. Does not claw back credit already spent — that would
     * push a customer's balance negative, which the schema forbids. An
     * administrator posts a compensating wallet adjustment if recovery is
     * warranted, leaving both actions visible in the ledger.
     */
    public function cancel(string $uuid, string $reason, Request $request): void
    {
        $referral = $this->referrals->findByUuid($uuid);

        if ($referral === null) {
            throw new NotFoundException('That referral does not exist.');
        }

        if (!$this->referrals->cancel((int) $referral['id'], $reason, $request->authUserId())) {
            throw new HttpException(
                'This referral cannot be cancelled; it is ' . $referral['status'] . '.',
                409
            );
        }

        $this->audit->log(
            entityName: 'referrals',
            entityId: (int) $referral['id'],
            action: 'cancelled',
            oldValues: ['status' => $referral['status']],
            newValues: ['reason' => $reason],
            request: $request,
            entityUuid: $uuid,
            notes: 'Any credit already paid is not reversed automatically'
        );
    }

    /**
     * The customer-facing referral panel: their code, share text, terms and
     * progress.
     *
     * @return array<string, mixed>
     */
    public function overviewFor(int $userId): array
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new NotFoundException('Account not found.');
        }

        $summary = $this->referrals->summaryForUser($userId);
        $brand = (string) $this->config->get('app.brand_name', 'Spice & Dry Fruits');
        $code = (string) $user['referral_code'];

        $referrerReward = $this->referrerReward();
        $refereeReward = $this->refereeReward();
        $minimum = $this->minimumOrderValue();

        return [
            'referral_code' => $code,
            'share_url' => rtrim((string) $this->config->get('app.url'), '/') . '/r/' . $code,
            'share_message' => sprintf(
                'I shop for spices and dry fruits at %s. Use my code %s on your first order and we both get %s in credit.',
                $brand,
                $code,
                $refereeReward->format()
            ),
            'rewards' => [
                'you_get' => $referrerReward->toDecimal(),
                'friend_gets' => $refereeReward->toDecimal(),
                'minimum_first_order' => $minimum?->toDecimal(),
                'credit_expires_after_days' => $this->rewardExpiryDays(),
            ],
            'progress' => [
                'total_invited' => (int) ($summary['total_invited'] ?? 0),
                'pending' => (int) ($summary['pending_count'] ?? 0),
                'qualified' => (int) ($summary['qualified_count'] ?? 0),
                'rewarded' => (int) ($summary['rewarded_count'] ?? 0),
                'total_earned' => (float) ($summary['total_earned'] ?? 0),
            ],
            'terms' => [
                sprintf('Your friend must be new to %s.', $brand),
                $minimum === null
                    ? 'Credit is released once your friend completes their first order.'
                    : sprintf(
                        'Credit is released once your friend completes a first order of %s or more.',
                        $minimum->format()
                    ),
                sprintf('Wallet credit expires %d days after it is issued.', $this->rewardExpiryDays()),
                'Wallet credit can be used for part of an order, not the whole amount.',
                'Referrals found to be self-referrals or duplicate accounts will be cancelled.',
            ],
        ];
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function myReferrals(int $userId, array $params): array
    {
        $result = $this->referrals->paginateForReferrer($userId, $params);

        $result['items'] = array_map(static fn (array $row): array => [
            'uuid' => $row['uuid'],
            'friend' => [
                'first_name' => $row['referee_first_name'],
                'mobile_masked' => $row['referee_mobile_masked'],
            ],
            'status' => $row['status'],
            'status_label' => match ($row['status']) {
                'pending' => 'Waiting for their first order',
                'qualified' => 'Order placed — credit on its way',
                'rewarded' => 'Credit added to your wallet',
                'cancelled' => 'Cancelled',
                default => ucfirst((string) $row['status']),
            },
            'reward_amount' => (float) $row['referrer_reward_amount'],
            'joined_date' => $row['created_date'],
            'qualified_date' => $row['qualified_date'],
            'rewarded_date' => $row['rewarded_date'],
        ], $result['items']);

        return $result;
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $status = null): array
    {
        return $this->referrals->paginateForAdmin($params, $status);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentAdmin(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'status' => $row['status'],
            'referral_code_used' => $row['referral_code_used'],
            'qualifying_order_reference' => $row['qualifying_order_reference'],
            'qualifying_order_value' => $row['qualifying_order_value'] === null
                ? null
                : (float) $row['qualifying_order_value'],
            'referrer_reward_amount' => (float) $row['referrer_reward_amount'],
            'referee_reward_amount' => (float) $row['referee_reward_amount'],
            'qualified_date' => $row['qualified_date'],
            'rewarded_date' => $row['rewarded_date'],
            'created_date' => $row['created_date'],
        ];
    }

    private function referrerReward(): Money
    {
        return Money::fromDecimal($this->settings->value('referral_referrer_reward', '50') ?? '50');
    }

    private function refereeReward(): Money
    {
        return Money::fromDecimal($this->settings->value('referral_referee_reward', '50') ?? '50');
    }

    private function minimumOrderValue(): ?Money
    {
        $value = $this->settings->value('referral_min_order_value');

        if ($value === null || $value === '' || (float) $value <= 0) {
            return null;
        }

        return Money::fromDecimal($value);
    }

    private function rewardExpiryDays(): int
    {
        return $this->settings->intValue('referral_reward_expiry_days', 180);
    }
}
