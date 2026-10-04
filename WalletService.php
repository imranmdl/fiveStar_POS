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
use App\Repositories\SettingRepository;
use App\Repositories\WalletRepository;

/**
 * Wallet credit: an append-only ledger with a cached balance.
 *
 * Three properties this service exists to guarantee:
 *
 * 1. NOTHING IS EVER EDITED. Database triggers reject UPDATE and DELETE on
 *    wallet_transactions. A correction is a new compensating entry. That is what
 *    makes the ledger answerable when a customer disputes a balance.
 *
 * 2. EVERY MUTATION HOLDS A ROW LOCK. Balance changes run inside a transaction
 *    that takes SELECT ... FOR UPDATE on the account first. Without it, two
 *    concurrent redemptions could each read the same balance and both succeed,
 *    spending the same credit twice.
 *
 * 3. CREDITS ARE IDEMPOTENT. Every credit carries a caller-supplied key with a
 *    UNIQUE index behind it. A retried referral payout or a redelivered webhook
 *    returns the original entry instead of paying twice.
 *
 * And one thing this service deliberately does NOT do: wallet credit is never
 * modelled as a discount. It is a payment tender applied after the total is
 * computed, so it does not reduce the transaction value and therefore does not
 * reduce GST. Treating it as a discount would understate tax liability.
 */
final class WalletService
{
    public const SOURCE_REFERRAL_REWARD = 'referral_reward';
    public const SOURCE_REFERRAL_SIGNUP = 'referral_signup_bonus';
    public const SOURCE_ORDER_REFUND = 'order_refund';
    public const SOURCE_PROMOTIONAL = 'promotional';
    public const SOURCE_CASHBACK = 'cashback';
    public const SOURCE_REDEMPTION = 'redemption';
    public const SOURCE_EXPIRY = 'expiry';
    public const SOURCE_ADMIN = 'admin_adjustment';

    public function __construct(
        private readonly WalletRepository $wallet,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function accountFor(int $userId): array
    {
        $account = $this->wallet->findAccountForUser($userId);

        if ($account === null) {
            // Created lazily: most customers never earn credit, and an empty row
            // per registration is pure noise.
            $accountId = $this->wallet->createAccount(
                $userId,
                (string) $this->config->get('app.currency', 'INR')
            );
            $account = (array) $this->wallet->findById($accountId);
        }

        return $account;
    }

    public function balance(int $userId): Money
    {
        return Money::fromDecimal((string) $this->accountFor($userId)['balance_amount']);
    }

    /** @return array<string, mixed> */
    public function summary(int $userId): array
    {
        $account = $this->accountFor($userId);

        return [
            'balance' => Money::fromDecimal((string) $account['balance_amount'])->toDecimal(),
            'lifetime_credited' => Money::fromDecimal((string) $account['lifetime_credited'])->toDecimal(),
            'lifetime_debited' => Money::fromDecimal((string) $account['lifetime_debited'])->toDecimal(),
            'currency_code' => $account['currency_code'],
            'is_frozen' => (bool) $account['is_frozen'],
            'frozen_reason' => $account['frozen_reason'],
            'redemption' => [
                'enabled' => $this->redemptionEnabled(),
                'max_percent_of_order' => $this->maxRedeemPercent(),
                'min_amount' => $this->minRedeemAmount()->toDecimal(),
            ],
        ];
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function statement(int $userId, array $params): array
    {
        $account = $this->accountFor($userId);
        $result = $this->wallet->statement($userId, $params);

        $result['items'] = array_map(static fn (array $row): array => [
            'uuid' => $row['uuid'],
            'direction' => $row['direction'],
            'source' => $row['source'],
            'amount' => Money::fromDecimal((string) $row['amount'])->toDecimal(),
            'balance_after' => Money::fromDecimal((string) $row['balance_after'])->toDecimal(),
            'narration' => $row['narration'],
            'reference' => [
                'type' => $row['reference_type'],
                'id' => $row['reference_id'],
            ],
            'expires_date' => $row['expires_date'],
            'expired_date' => $row['expired_date'],
            'created_date' => $row['created_date'],
        ], $result['items']);

        $result['account_uuid'] = $account['uuid'];

        return $result;
    }

    /**
     * Adds credit.
     *
     * @param string $idempotencyKey Must be stable for a given logical event,
     *                               e.g. "referral:{uuid}:referrer". A retry with
     *                               the same key is a no-op.
     *
     * @return array<string, mixed> The ledger entry (existing one on a retry)
     */
    public function credit(
        int $userId,
        Money $amount,
        string $source,
        string $narration,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?int $expiryDays = null,
        ?Request $request = null,
    ): array {
        if (!$amount->isPositive()) {
            throw new HttpException('A wallet credit must be greater than zero.', 422);
        }

        $existing = $this->wallet->findEntryByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            $this->logger->info('Wallet credit suppressed as a duplicate', [
                'idempotency_key' => $idempotencyKey,
                'existing_uuid' => $existing['uuid'],
            ], 'wallet');

            return $existing;
        }

        $account = $this->accountFor($userId);

        $entry = $this->db->transaction(function () use ($account, $userId, $amount, $source, $narration, $idempotencyKey, $referenceType, $referenceId, $expiryDays, $request): array {
            $locked = $this->wallet->lockAccountForUpdate((int) $account['id']);

            if ($locked === null) {
                throw new NotFoundException('Wallet account not found.');
            }

            $balanceAfter = Money::fromDecimal((string) $locked['balance_amount'])->add($amount);

            $transactionId = $this->wallet->appendEntry([
                'account_id' => (int) $locked['id'],
                'user_id' => $userId,
                'direction' => 'credit',
                'source' => $source,
                'amount' => (string) $amount,
                'balance_after' => (string) $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'expires_date' => $expiryDays === null
                    ? null
                    : date('Y-m-d H:i:s', strtotime('+' . $expiryDays . ' days')),
                'narration' => $narration,
                'actor_id' => $request?->authUserId(),
            ]);

            return (array) $this->db->selectOne(
                'SELECT * FROM `wallet_transactions` WHERE `id` = :id LIMIT 1',
                ['id' => $transactionId]
            );
        });

        $this->audit->log(
            entityName: 'wallet_transactions',
            entityId: (int) $entry['id'],
            action: 'credit',
            newValues: [
                'amount' => $amount->toDecimal(),
                'source' => $source,
                'balance_after' => $entry['balance_after'],
            ],
            request: $request,
            entityUuid: (string) $entry['uuid'],
            notes: $narration
        );

        return $entry;
    }

    /**
     * Spends credit. Fails rather than overdrawing.
     *
     * @return array<string, mixed> The ledger entry
     */
    public function debit(
        int $userId,
        Money $amount,
        string $source,
        string $narration,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?Request $request = null,
    ): array {
        if (!$amount->isPositive()) {
            throw new HttpException('A wallet debit must be greater than zero.', 422);
        }

        $existing = $this->wallet->findEntryByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $account = $this->accountFor($userId);

        if ((int) $account['is_frozen'] === 1 && $source !== self::SOURCE_EXPIRY) {
            throw new HttpException(
                'Your wallet is temporarily on hold. Please contact support.',
                403
            );
        }

        $entry = $this->db->transaction(function () use ($account, $userId, $amount, $source, $narration, $idempotencyKey, $referenceType, $referenceId, $request): array {
            $locked = $this->wallet->lockAccountForUpdate((int) $account['id']);

            if ($locked === null) {
                throw new NotFoundException('Wallet account not found.');
            }

            $balance = Money::fromDecimal((string) $locked['balance_amount']);

            // Checked under the lock, so a concurrent debit cannot slip past.
            if ($amount->greaterThan($balance)) {
                throw new HttpException(
                    sprintf(
                        'Insufficient wallet balance. Available %s, requested %s.',
                        $balance->format(),
                        $amount->format()
                    ),
                    409,
                    ['amount' => ['You do not have enough wallet credit for this.']]
                );
            }

            $balanceAfter = $balance->subtract($amount);

            $transactionId = $this->wallet->appendEntry([
                'account_id' => (int) $locked['id'],
                'user_id' => $userId,
                'direction' => 'debit',
                'source' => $source,
                'amount' => (string) $amount,
                'balance_after' => (string) $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'expires_date' => null,
                'narration' => $narration,
                'actor_id' => $request?->authUserId(),
            ]);

            return (array) $this->db->selectOne(
                'SELECT * FROM `wallet_transactions` WHERE `id` = :id LIMIT 1',
                ['id' => $transactionId]
            );
        });

        $this->audit->log(
            entityName: 'wallet_transactions',
            entityId: (int) $entry['id'],
            action: 'debit',
            newValues: [
                'amount' => $amount->toDecimal(),
                'source' => $source,
                'balance_after' => $entry['balance_after'],
            ],
            request: $request,
            entityUuid: (string) $entry['uuid'],
            notes: $narration
        );

        return $entry;
    }

    /**
     * How much wallet credit may be applied to an order of this value.
     *
     * The percentage cap exists so wallet credit supplements a payment rather
     * than replacing it: an order paid entirely from promotional credit brings
     * in no cash and is trivially farmable.
     *
     * @return array<string, mixed>
     */
    public function redeemableFor(int $userId, Money $orderValue): array
    {
        $balance = $this->balance($userId);
        $account = $this->accountFor($userId);

        if (!$this->redemptionEnabled()) {
            return [
                'max_redeemable' => 0.0,
                'balance' => $balance->toDecimal(),
                'reason' => 'Wallet redemption is currently disabled.',
            ];
        }

        if ((int) $account['is_frozen'] === 1) {
            return [
                'max_redeemable' => 0.0,
                'balance' => $balance->toDecimal(),
                'reason' => 'Your wallet is temporarily on hold.',
            ];
        }

        $percentCap = $orderValue->percentage($this->maxRedeemPercent());
        $maximum = $balance->min($percentCap);
        $minimum = $this->minRedeemAmount();

        if ($maximum->lessThan($minimum)) {
            return [
                'max_redeemable' => 0.0,
                'balance' => $balance->toDecimal(),
                'reason' => $balance->isPositive()
                    ? sprintf(
                        'A minimum of %s can be redeemed, and this order allows up to %s.',
                        $minimum->format(),
                        $percentCap->format()
                    )
                    : 'You have no wallet credit yet.',
            ];
        }

        return [
            'max_redeemable' => $maximum->toDecimal(),
            'balance' => $balance->toDecimal(),
            'percent_cap' => $this->maxRedeemPercent(),
            'percent_cap_amount' => $percentCap->toDecimal(),
            'min_redeem_amount' => $minimum->toDecimal(),
            'reason' => null,
        ];
    }

    /**
     * Clamps a requested redemption to what is actually permitted, rather than
     * rejecting it. A customer who asks for more credit than the cap allows
     * should get the cap, with an explanation.
     *
     * @return array{amount:Money, message:?string}
     */
    public function clampRedemption(int $userId, Money $requested, Money $orderValue): array
    {
        if (!$requested->isPositive()) {
            return ['amount' => Money::zero(), 'message' => null];
        }

        $allowance = $this->redeemableFor($userId, $orderValue);
        $maximum = Money::fromDecimal($allowance['max_redeemable']);

        if (!$maximum->isPositive()) {
            return ['amount' => Money::zero(), 'message' => $allowance['reason']];
        }

        if ($requested->greaterThan($maximum)) {
            return [
                'amount' => $maximum,
                'message' => sprintf(
                    'Wallet credit was capped at %s for this order (up to %s%% of the order value).',
                    $maximum->format(),
                    rtrim(rtrim(number_format($this->maxRedeemPercent(), 2, '.', ''), '0'), '.')
                ),
            ];
        }

        return ['amount' => $requested, 'message' => null];
    }

    /**
     * Writes off credits past their expiry, posting a compensating debit for
     * each. Intended for the Phase 9 scheduler; safe to run repeatedly.
     *
     * @return array{expired_count:int, expired_amount:float}
     */
    public function expireCredits(int $batchSize = 500): array
    {
        $count = 0;
        $total = Money::zero();

        foreach ($this->wallet->expirableCredits($batchSize) as $credit) {
            if ($this->wallet->creditAlreadyExpired((int) $credit['id'])) {
                continue;
            }

            $amount = Money::fromDecimal((string) $credit['amount']);
            $userId = (int) $credit['user_id'];
            $available = $this->balance($userId);

            // Only write off what is still there. If the customer already spent
            // the credit, there is nothing to expire.
            $writeOff = $amount->min($available);

            if (!$writeOff->isPositive()) {
                $this->wallet->markCreditExpired((int) $credit['id'], 0, '0.00');

                continue;
            }

            try {
                $debit = $this->debit(
                    userId: $userId,
                    amount: $writeOff,
                    source: self::SOURCE_EXPIRY,
                    narration: 'Promotional credit expired',
                    idempotencyKey: 'expiry:' . $credit['uuid'],
                    referenceType: 'wallet_transactions',
                    referenceId: (string) $credit['uuid'],
                );

                $this->wallet->markCreditExpired(
                    (int) $credit['id'],
                    (int) $debit['id'],
                    (string) $writeOff
                );

                ++$count;
                $total = $total->add($writeOff);
            } catch (\Throwable $exception) {
                $this->logger->error('Wallet credit expiry failed', [
                    'credit_uuid' => $credit['uuid'],
                    'reason' => $exception->getMessage(),
                ], 'wallet');
            }
        }

        return ['expired_count' => $count, 'expired_amount' => $total->toDecimal()];
    }

    /**
     * Re-derives the balance from the ledger and reports drift. Worth running as
     * a scheduled check: a mismatch means a bug, and finding it before a
     * customer does is the whole point.
     *
     * @return array<string, mixed>
     */
    public function verifyIntegrity(int $userId): array
    {
        $account = $this->accountFor($userId);
        $result = $this->wallet->verifyIntegrity((int) $account['id']);

        if (!$result['matches']) {
            $this->logger->error('Wallet balance does not match the ledger', [
                'user_id' => $userId,
                'account_uuid' => $account['uuid'],
                'cached' => $result['cached_balance'],
                'derived' => $result['derived_balance'],
            ], 'wallet');
        }

        return $result + ['account_uuid' => $account['uuid']];
    }

    public function freeze(int $userId, string $reason, Request $request): void
    {
        $account = $this->accountFor($userId);
        $this->wallet->freeze((int) $account['id'], $reason, $request->authUserId());

        $this->audit->log(
            entityName: 'wallet_accounts',
            entityId: (int) $account['id'],
            action: 'freeze',
            newValues: ['reason' => $reason],
            request: $request,
            entityUuid: (string) $account['uuid']
        );
    }

    public function unfreeze(int $userId, Request $request): void
    {
        $account = $this->accountFor($userId);
        $this->wallet->unfreeze((int) $account['id'], $request->authUserId());

        $this->audit->log(
            entityName: 'wallet_accounts',
            entityId: (int) $account['id'],
            action: 'unfreeze',
            request: $request,
            entityUuid: (string) $account['uuid']
        );
    }

    private function redemptionEnabled(): bool
    {
        return $this->settings->boolValue('wallet_enabled', true);
    }

    private function maxRedeemPercent(): float
    {
        $value = $this->settings->value('wallet_max_redeem_percent');

        return $value === null || $value === ''
            ? (float) $this->config->get('commerce.wallet_max_redeem_percent', 20.0)
            : (float) $value;
    }

    private function minRedeemAmount(): Money
    {
        $value = $this->settings->value('wallet_min_redeem_amount');

        return Money::fromDecimal(
            $value === null || $value === ''
                ? (string) $this->config->get('commerce.wallet_min_redeem_amount', '10')
                : $value
        );
    }
}
