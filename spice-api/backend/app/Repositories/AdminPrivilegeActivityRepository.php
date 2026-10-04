<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Heartbeat table backing the Admin Privilege panel's own inactivity
 * timeout (item 6: "Automatically expire inactive admin sessions").
 *
 * Deliberately a plain repository, not a BaseRepository subclass: this table
 * carries no soft-delete/audit columns, because it holds nothing worth
 * auditing — only "when did this admin last touch the panel".
 */
final class AdminPrivilegeActivityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function touch(int $userId, ?string $ip, ?string $userAgent): void
    {
        $this->db->execute(
            'INSERT INTO admin_privilege_activity (user_id, last_activity_date, ip_address, user_agent)
             VALUES (:user_id, NOW(), :ip, :agent)
             ON DUPLICATE KEY UPDATE
                last_activity_date = NOW(),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent)',
            ['user_id' => $userId, 'ip' => $ip, 'agent' => $userAgent]
        );
    }

    /** Seconds since this user's last recorded activity, or null if never recorded. */
    public function idleSeconds(int $userId): ?int
    {
        $lastActivity = $this->db->scalar(
            'SELECT last_activity_date FROM admin_privilege_activity WHERE user_id = :id',
            ['id' => $userId]
        );

        if ($lastActivity === null) {
            return null;
        }

        return max(0, time() - strtotime((string) $lastActivity));
    }

    public function clear(int $userId): void
    {
        $this->db->execute('DELETE FROM admin_privilege_activity WHERE user_id = :id', ['id' => $userId]);
    }
}
