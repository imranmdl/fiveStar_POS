<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

/**
 * Loyalty accounts and the append-only points ledger.
 *
 * Structurally identical to WalletRepository (see wallet_transactions'
 * migration for the reasoning) — an append-only ledger with a cached
 * balance, kept in step inside the same transaction as every entry. Database
 * triggers reject any UPDATE or DELETE on loyalty_ledger, so there is
 * deliberately no update/delete method for entries here either.
 */
final class LoyaltyRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'loyalty_accounts';
    }

    protected function fillable(): array
    {
        return ['user_id', 'points_balance', 'lifetime_earned', 'lifetime_redeemed', 'is_frozen', 'frozen_reason'];
    }

    /** @return array<string, mixed>|null */
    public function findAccountForUser(int $userId): ?array
    {
        return $this->findOneBy('user_id', $userId);
    }

    /** @return array<string, mixed>|null */
    public function lockAccountForUpdate(int $accountId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `loyalty_accounts` WHERE `id` = :id AND `is_deleted` = 0 FOR UPDATE',
            ['id' => $accountId]
        );
    }

    /**
     * Creates the account, or returns the one another request just created.
     * See WalletRepository::createAccount() for why this is an upsert rather
     * than catch-and-reread.
     */
    public function createAccount(int $userId): int
    {
        $this->db->execute(
            'INSERT INTO `loyalty_accounts`
                 (`uuid`, `user_id`, `points_balance`, `lifetime_earned`, `lifetime_redeemed`,
                  `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :user_id, 0, 0, 0, :actor, NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE `updated_date` = NOW()',
            ['uuid' => Uuid::v4(), 'user_id' => $userId, 'actor' => $userId]
        );

        $row = $this->db->selectOne(
            'SELECT `id` FROM `loyalty_accounts` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId]
        );

        if ($row === null) {
            throw new \RuntimeException('The loyalty account could not be created.');
        }

        return (int) $row['id'];
    }

    /**
     * Appends a ledger entry and moves the cached balance in one statement
     * pair. Must be called inside a transaction that already holds the
     * account lock.
     *
     * @param array<string, mixed> $entry
     *
     * @return int The new ledger row id
     */
    public function appendEntry(array $entry): int
    {
        $ledgerId = $this->db->insert(
            'INSERT INTO `loyalty_ledger`
                 (`uuid`, `account_id`, `user_id`, `direction`, `source`, `points`,
                  `balance_after`, `reference_type`, `reference_id`, `idempotency_key`,
                  `expires_date`, `narration`, `created_by`, `created_date`,
                  `is_active`, `is_deleted`, `version`)
             VALUES
                 (:uuid, :account_id, :user_id, :direction, :source, :points,
                  :balance_after, :reference_type, :reference_id, :idempotency_key,
                  :expires_date, :narration, :actor, NOW(), 1, 0, 1)',
            [
                'uuid' => Uuid::v4(),
                'account_id' => (int) $entry['account_id'],
                'user_id' => (int) $entry['user_id'],
                'direction' => (string) $entry['direction'],
                'source' => (string) $entry['source'],
                'points' => (int) $entry['points'],
                'balance_after' => (int) $entry['balance_after'],
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
                'UPDATE `loyalty_accounts`
                    SET `points_balance` = :balance,
                        `%s` = `%s` + :points,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id',
                $isCredit ? 'lifetime_earned' : 'lifetime_redeemed',
                $isCredit ? 'lifetime_earned' : 'lifetime_redeemed'
            ),
            [
                'balance' => (int) $entry['balance_after'],
                'points' => (int) $entry['points'],
                'id' => (int) $entry['account_id'],
            ]
        );

        return $ledgerId;
    }

    /** @return array<string, mixed>|null */
    public function findEntryByIdempotencyKey(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `loyalty_ledger` WHERE `idempotency_key` = :key LIMIT 1',
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
            'SELECT COUNT(*) FROM `loyalty_ledger` WHERE `user_id` = :user_id',
            ['user_id' => $userId]
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT `uuid`, `direction`, `source`, `points`, `balance_after`,
                        `reference_type`, `reference_id`, `narration`,
                        `expires_date`, `created_date`
                   FROM `loyalty_ledger`
                  WHERE `user_id` = :user_id
                  ORDER BY `id` DESC
                  LIMIT %d OFFSET %d',
                $params['per_page'],
                $params['offset']
            ),
            ['user_id' => $userId]
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Admin list: accounts with any activity, searchable by the customer's
     * name/mobile.
     *
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function adminList(array $params, ?string $search = null): array
    {
        $where = ['la.`is_deleted` = 0'];
        $bindings = [];

        if ($search !== null && $search !== '') {
            $where[] = '(u.`full_name` LIKE :search1 OR u.`mobile` LIKE :search2)';
            $bindings['search1'] = '%' . $search . '%';
            $bindings['search2'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `loyalty_accounts` la
               INNER JOIN `users` u ON u.`id` = la.`user_id`
              WHERE {$whereSql}",
            $bindings
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                "SELECT la.`uuid`, la.`points_balance`, la.`lifetime_earned`, la.`lifetime_redeemed`,
                        la.`is_frozen`, u.`uuid` AS `user_uuid`, u.`full_name`, u.`mobile`
                   FROM `loyalty_accounts` la
                   INNER JOIN `users` u ON u.`id` = la.`user_id`
                  WHERE {$whereSql}
                  ORDER BY la.`points_balance` DESC
                  LIMIT %d OFFSET %d",
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Store-wide totals for the admin dashboard. */
    public function summary(): array
    {
        $row = $this->db->selectOne(
            "SELECT
                 COALESCE(SUM(`lifetime_earned`), 0)   AS `total_issued`,
                 COALESCE(SUM(`lifetime_redeemed`), 0) AS `total_redeemed`,
                 COALESCE(SUM(`points_balance`), 0)     AS `total_remaining`,
                 COUNT(*)                               AS `account_count`
               FROM `loyalty_accounts`
              WHERE `is_deleted` = 0"
        );

        return [
            'total_issued' => (int) ($row['total_issued'] ?? 0),
            'total_redeemed' => (int) ($row['total_redeemed'] ?? 0),
            'total_remaining' => (int) ($row['total_remaining'] ?? 0),
            'account_count' => (int) ($row['account_count'] ?? 0),
        ];
    }

    /**
     * Re-derives the balance from the ledger. See WalletRepository's own
     * verifyIntegrity() — same reasoning, same shape.
     *
     * @return array{derived_balance:int, cached_balance:int, matches:bool}
     */
    public function verifyIntegrity(int $accountId): array
    {
        $row = $this->db->selectOne(
            "SELECT
                 COALESCE(SUM(CASE WHEN `direction` = 'credit' THEN `points` ELSE 0 END), 0)
               - COALESCE(SUM(CASE WHEN `direction` = 'debit'  THEN `points` ELSE 0 END), 0)
                 AS `derived`
               FROM `loyalty_ledger`
              WHERE `account_id` = :account_id",
            ['account_id' => $accountId]
        );

        $cached = (int) $this->db->scalar(
            'SELECT `points_balance` FROM `loyalty_accounts` WHERE `id` = :id',
            ['id' => $accountId]
        );

        $derived = (int) ($row['derived'] ?? 0);

        return ['derived_balance' => $derived, 'cached_balance' => $cached, 'matches' => $derived === $cached];
    }

    /** @return array<int, array<string, mixed>> */
    public function expirableCredits(int $limit = 500): array
    {
        return $this->db->select(
            sprintf(
                "SELECT l.`id`, l.`account_id`, l.`user_id`, l.`points`, l.`uuid`, l.`source`
                   FROM `loyalty_ledger` l
                   LEFT JOIN `loyalty_point_expiries` e ON e.`ledger_id` = l.`id`
                  WHERE l.`direction` = 'credit'
                    AND l.`expires_date` IS NOT NULL
                    AND l.`expires_date` < NOW()
                    AND e.`id` IS NULL
                  ORDER BY l.`expires_date` ASC
                  LIMIT %d",
                max(1, min($limit, 5000))
            )
        );
    }

    public function markCreditExpired(int $creditLedgerId, ?int $debitLedgerId, int $points): bool
    {
        try {
            $this->db->insert(
                'INSERT INTO `loyalty_point_expiries`
                     (`uuid`, `ledger_id`, `debit_ledger_id`, `expired_points`,
                      `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :ledger_id, :debit_id, :points, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'ledger_id' => $creditLedgerId,
                    'debit_id' => $debitLedgerId,
                    'points' => $points,
                ]
            );

            return true;
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }
    }

    public function creditAlreadyExpired(int $creditLedgerId): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM `loyalty_point_expiries` WHERE `ledger_id` = :id LIMIT 1',
            ['id' => $creditLedgerId]
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
