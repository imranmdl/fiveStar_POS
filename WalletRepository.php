<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

/**
 * Wallet accounts and the append-only transaction ledger.
 *
 * The ledger is the authority; `wallet_accounts.balance_amount` is a cache kept
 * in step inside the same transaction as every entry. Database triggers reject
 * any UPDATE or DELETE on wallet_transactions, so this class deliberately has
 * no update or delete method for entries — a correction is a new entry.
 */
final class WalletRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'wallet_accounts';
    }

    protected function fillable(): array
    {
        return ['user_id', 'balance_amount', 'lifetime_credited', 'lifetime_debited',
            'currency_code', 'is_frozen', 'frozen_reason'];
    }

    /** @return array<string, mixed>|null */
    public function findAccountForUser(int $userId): ?array
    {
        return $this->findOneBy('user_id', $userId);
    }

    /**
     * Locks the account row for the remainder of the current transaction.
     *
     * Every balance mutation goes through this. Two concurrent redemptions
     * without the lock could each read the same balance and both succeed,
     * spending the same credit twice.
     *
     * @return array<string, mixed>|null
     */
    public function lockAccountForUpdate(int $accountId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `wallet_accounts` WHERE `id` = :id AND `is_deleted` = 0 FOR UPDATE',
            ['id' => $accountId]
        );
    }

    /**
     * Creates the account, or returns the one another request just created.
     *
     * Wallet accounts are made lazily, so several concurrent requests for a
     * customer who has never had one all find nothing and all try to insert.
     * The unique index on `user_id` rejects every loser, and left unhandled
     * that surfaced as a 500 on something as ordinary as adding to a cart.
     *
     * An upsert rather than catch-and-reread: under REPEATABLE READ a re-read
     * can still miss the winner's row, and a locking read waits on it. The
     * ON DUPLICATE KEY clause deliberately touches only `updated_date` — the
     * balance is a cache of the ledger and must never be reset by a second
     * request arriving.
     *
     * Found by concurrency testing, hidden behind two other races in the same
     * request path.
     */
    public function createAccount(int $userId, string $currencyCode): int
    {
        $this->db->execute(
            'INSERT INTO `wallet_accounts`
                 (`uuid`, `user_id`, `balance_amount`, `lifetime_credited`, `lifetime_debited`,
                  `currency_code`, `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :user_id, \'0.00\', \'0.00\', \'0.00\', :currency_code,
                     :actor, NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE `updated_date` = NOW()',
            [
                'uuid' => \App\Helpers\Uuid::v4(),
                'user_id' => $userId,
                'currency_code' => $currencyCode,
                'actor' => $userId,
            ]
        );

        $row = $this->db->selectOne(
            'SELECT `id` FROM `wallet_accounts` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId]
        );

        if ($row === null) {
            throw new \RuntimeException('The wallet account could not be created.');
        }

        return (int) $row['id'];
    }

    /**
     * Appends a ledger entry and moves the cached balance in one statement pair.
     * Must be called inside a transaction that already holds the account lock.
     *
     * @param array<string, mixed> $entry
     *
     * @return int The new transaction id
     */
    public function appendEntry(array $entry): int
    {
        $transactionId = $this->db->insert(
            'INSERT INTO `wallet_transactions`
                 (`uuid`, `account_id`, `user_id`, `direction`, `source`, `amount`,
                  `balance_after`, `reference_type`, `reference_id`, `idempotency_key`,
                  `expires_date`, `narration`, `created_by`, `created_date`,
                  `is_active`, `is_deleted`, `version`)
             VALUES
                 (:uuid, :account_id, :user_id, :direction, :source, :amount,
                  :balance_after, :reference_type, :reference_id, :idempotency_key,
                  :expires_date, :narration, :actor, NOW(), 1, 0, 1)',
            [
                'uuid' => Uuid::v4(),
                'account_id' => (int) $entry['account_id'],
                'user_id' => (int) $entry['user_id'],
                'direction' => (string) $entry['direction'],
                'source' => (string) $entry['source'],
                'amount' => (string) $entry['amount'],
                'balance_after' => (string) $entry['balance_after'],
                'reference_type' => $entry['reference_type'] ?? null,
                'reference_id' => $entry['reference_id'] ?? null,
                'idempotency_key' => $entry['idempotency_key'] ?? null,
                'expires_date' => $entry['expires_date'] ?? null,
                'narration' => (string) $entry['narration'],
                'actor' => $entry['actor_id'] ?? null,
            ]
        );

        $isCredit = $entry['direction'] === 'credit';

        $this->db->execute(
            sprintf(
                'UPDATE `wallet_accounts`
                    SET `balance_amount` = :balance,
                        `%s` = `%s` + :amount,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id',
                $isCredit ? 'lifetime_credited' : 'lifetime_debited',
                $isCredit ? 'lifetime_credited' : 'lifetime_debited'
            ),
            [
                'balance' => (string) $entry['balance_after'],
                'amount' => (string) $entry['amount'],
                'id' => (int) $entry['account_id'],
            ]
        );

        return $transactionId;
    }

    /** @return array<string, mixed>|null */
    public function findEntryByIdempotencyKey(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `wallet_transactions` WHERE `idempotency_key` = :key LIMIT 1',
            ['key' => $key]
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function statement(int $userId, array $params): array
    {
        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `wallet_transactions` WHERE `user_id` = :user_id',
            ['user_id' => $userId]
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT t.`uuid`, t.`direction`, t.`source`, t.`amount`, t.`balance_after`,
                        t.`reference_type`, t.`reference_id`, t.`narration`,
                        t.`expires_date`, t.`created_date`,
                        e.`created_date` AS `expired_date`
                   FROM `wallet_transactions` t
                   LEFT JOIN `wallet_credit_expiries` e ON e.`transaction_id` = t.`id`
                  WHERE t.`user_id` = :user_id
                  ORDER BY t.`id` DESC
                  LIMIT %d OFFSET %d',
                $params['per_page'],
                $params['offset']
            ),
            ['user_id' => $userId]
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Re-derives the balance from the ledger. The ledger is the authority, so a
     * mismatch against the cached balance is a bug worth alerting on rather
     * than quietly correcting.
     *
     * @return array{derived_balance:string, cached_balance:string, matches:bool}
     */
    public function verifyIntegrity(int $accountId): array
    {
        $row = $this->db->selectOne(
            "SELECT
                 COALESCE(SUM(CASE WHEN `direction` = 'credit' THEN `amount` ELSE 0 END), 0)
               - COALESCE(SUM(CASE WHEN `direction` = 'debit'  THEN `amount` ELSE 0 END), 0)
                 AS `derived`
               FROM `wallet_transactions`
              WHERE `account_id` = :account_id",
            ['account_id' => $accountId]
        );

        $cached = (string) $this->db->scalar(
            'SELECT `balance_amount` FROM `wallet_accounts` WHERE `id` = :id',
            ['id' => $accountId]
        );

        $derived = (string) ($row['derived'] ?? '0.00');

        return [
            'derived_balance' => $derived,
            'cached_balance' => $cached,
            // Compared in paise to avoid a float comparison deciding money.
            'matches' => (int) round(((float) $derived) * 100) === (int) round(((float) $cached) * 100),
        ];
    }

    /**
     * Credits that have passed their expiry and not yet been written off.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expirableCredits(int $limit = 500): array
    {
        return $this->db->select(
            sprintf(
                "SELECT t.`id`, t.`account_id`, t.`user_id`, t.`amount`, t.`uuid`, t.`source`
                   FROM `wallet_transactions` t
                   LEFT JOIN `wallet_credit_expiries` e ON e.`transaction_id` = t.`id`
                  WHERE t.`direction` = 'credit'
                    AND t.`expires_date` IS NOT NULL
                    AND t.`expires_date` < NOW()
                    AND e.`id` IS NULL
                  ORDER BY t.`expires_date` ASC
                  LIMIT %d",
                max(1, min($limit, 5000))
            )
        );
    }

    /**
     * Records that an expiring credit has been written off.
     *
     * The original credit row cannot be stamped — the append-only triggers
     * forbid it — so the marker lives in a side table whose UNIQUE key on
     * transaction_id is what guarantees a credit is never expired twice. A
     * duplicate call returns false rather than double-debiting.
     */
    public function markCreditExpired(int $creditTransactionId, int $debitTransactionId, string $amount): bool
    {
        try {
            $this->db->insert(
                'INSERT INTO `wallet_credit_expiries`
                     (`uuid`, `transaction_id`, `debit_transaction_id`, `expired_amount`,
                      `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :transaction_id, :debit_id, :amount, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'transaction_id' => $creditTransactionId,
                    'debit_id' => $debitTransactionId,
                    'amount' => $amount,
                ]
            );

            return true;
        } catch (\PDOException $exception) {
            // 23000 = integrity constraint violation, i.e. already expired.
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }
    }

    /** Guard used before posting the write-off debit. */
    public function creditAlreadyExpired(int $creditTransactionId): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM `wallet_credit_expiries` WHERE `transaction_id` = :id LIMIT 1',
            ['id' => $creditTransactionId]
        ) !== null;
    }

    public function freeze(int $accountId, string $reason, ?int $actorId): void
    {
        $this->update($accountId, ['is_frozen' => 1, 'frozen_reason' => $reason], $actorId);
    }

    public function unfreeze(int $accountId, ?int $actorId): void
    {
        $this->update($accountId, ['is_frozen' => 0, 'frozen_reason' => null], $actorId);
    }
}
