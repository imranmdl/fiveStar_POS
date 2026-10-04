<?php

declare(strict_types=1);

namespace App\Repositories;

final class ReferralRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'referrals';
    }

    protected function fillable(): array
    {
        return [
            'referrer_user_id', 'referee_user_id', 'referral_code_used', 'status',
            'qualifying_order_reference', 'qualifying_order_value',
            'referrer_reward_amount', 'referee_reward_amount',
            'qualified_date', 'rewarded_date', 'cancelled_reason', 'signup_ip',
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByReferee(int $refereeUserId): ?array
    {
        return $this->findOneBy('referee_user_id', $refereeUserId);
    }

    /**
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForReferrer(int $referrerUserId, array $params): array
    {
        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `referrals`
              WHERE `referrer_user_id` = :user_id AND `is_deleted` = 0',
            ['user_id' => $referrerUserId]
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        // Only the referee's first name and masked mobile are exposed: the
        // referrer does not need, and should not get, a contact list.
        $items = $this->db->select(
            sprintf(
                'SELECT r.`uuid`, r.`status`, r.`referrer_reward_amount`,
                        r.`qualified_date`, r.`rewarded_date`, r.`created_date`,
                        SUBSTRING_INDEX(u.`full_name`, \' \', 1) AS `referee_first_name`,
                        CONCAT(SUBSTRING(u.`mobile`, 1, 2), \'XXXXXX\', SUBSTRING(u.`mobile`, -2)) AS `referee_mobile_masked`
                   FROM `referrals` r
                   INNER JOIN `users` u ON u.`id` = r.`referee_user_id`
                  WHERE r.`referrer_user_id` = :user_id AND r.`is_deleted` = 0
                  ORDER BY r.`created_date` DESC
                  LIMIT %d OFFSET %d',
                $params['per_page'],
                $params['offset']
            ),
            ['user_id' => $referrerUserId]
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function summaryForUser(int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `vw_referral_summary` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /**
     * Atomically moves a referral from pending to qualified.
     *
     * Doing the state check and the write in one statement means a retried
     * webhook or a double-clicked admin button cannot pay out twice.
     */
    public function markQualified(
        int $referralId,
        string $orderReference,
        string $orderValue,
        string $referrerReward,
        string $refereeReward,
    ): bool {
        return $this->db->execute(
            "UPDATE `referrals`
                SET `status` = 'qualified',
                    `qualifying_order_reference` = :order_reference,
                    `qualifying_order_value` = :order_value,
                    `referrer_reward_amount` = :referrer_reward,
                    `referee_reward_amount` = :referee_reward,
                    `qualified_date` = NOW(),
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id AND `status` = 'pending' AND `is_deleted` = 0",
            [
                'order_reference' => $orderReference,
                'order_value' => $orderValue,
                'referrer_reward' => $referrerReward,
                'referee_reward' => $refereeReward,
                'id' => $referralId,
            ]
        ) === 1;
    }

    public function markRewarded(int $referralId): bool
    {
        return $this->db->execute(
            "UPDATE `referrals`
                SET `status` = 'rewarded', `rewarded_date` = NOW(),
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id AND `status` = 'qualified' AND `is_deleted` = 0",
            ['id' => $referralId]
        ) === 1;
    }

    public function cancel(int $referralId, string $reason, ?int $actorId): bool
    {
        return $this->db->execute(
            "UPDATE `referrals`
                SET `status` = 'cancelled', `cancelled_reason` = :reason,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id AND `status` IN ('pending','qualified') AND `is_deleted` = 0",
            ['reason' => $reason, 'actor' => $actorId, 'id' => $referralId]
        ) === 1;
    }

    /**
     * Fraud signal: several signups from one IP against the same code.
     *
     * Not auto-blocking — a family sharing a connection looks identical to abuse
     * — but it is what an administrator needs in order to look.
     */
    public function countSignupsFromIp(string $ip, int $withinHours = 24): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `referrals`
              WHERE `signup_ip` = :ip
                AND `created_date` >= DATE_SUB(NOW(), INTERVAL :hours HOUR)',
            ['ip' => $ip, 'hours' => $withinHours]
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $status = null): array
    {
        $where = ['r.`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 'r.`status` = :status';
            $bindings['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `referrals` r WHERE {$whereSql}",
            $bindings
        );

        $items = $this->db->select(
            sprintf(
                'SELECT r.`uuid`, r.`status`, r.`referral_code_used`,
                        r.`qualifying_order_reference`, r.`qualifying_order_value`,
                        r.`referrer_reward_amount`, r.`referee_reward_amount`,
                        r.`qualified_date`, r.`rewarded_date`, r.`cancelled_reason`,
                        r.`signup_ip`, r.`created_date`,
                        referrer.`uuid` AS `referrer_uuid`, referrer.`full_name` AS `referrer_name`,
                        referee.`uuid`  AS `referee_uuid`,  referee.`full_name`  AS `referee_name`
                   FROM `referrals` r
                   INNER JOIN `users` referrer ON referrer.`id` = r.`referrer_user_id`
                   INNER JOIN `users` referee  ON referee.`id`  = r.`referee_user_id`
                  WHERE %s
                  ORDER BY r.`created_date` %s
                  LIMIT %d OFFSET %d',
                $whereSql,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }
}
