<?php

declare(strict_types=1);

namespace App\Repositories;

final class RateLimitRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'rate_limits';
    }

    protected function fillable(): array
    {
        return ['limit_key', 'hit_count', 'window_started_date', 'window_expires_date'];
    }

    /**
     * Atomic counter. The UPSERT resets the window when the stored one has
     * already expired, so no separate cleanup job is required for correctness.
     *
     * @return array{hits:int, window_expires_date:string}
     */
    public function hit(string $key, int $windowSeconds): array
    {
        $this->db->execute(
            'INSERT INTO rate_limits
                 (uuid, limit_key, hit_count, window_started_date, window_expires_date,
                  created_date, is_active, is_deleted, version)
             VALUES
                 (:uuid, :key, 1, NOW(), DATE_ADD(NOW(), INTERVAL :seconds SECOND),
                  NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE
                 hit_count = IF(window_expires_date <= NOW(), 1, hit_count + 1),
                 window_started_date = IF(window_expires_date <= NOW(), NOW(), window_started_date),
                 window_expires_date = IF(
                     window_expires_date <= NOW(),
                     DATE_ADD(NOW(), INTERVAL :seconds_reset SECOND),
                     window_expires_date
                 ),
                 version = version + 1',
            [
                'uuid' => \App\Helpers\Uuid::v4(),
                'key' => $key,
                'seconds' => $windowSeconds,
                'seconds_reset' => $windowSeconds,
            ]
        );

        $row = $this->db->selectOne(
            'SELECT hit_count, window_expires_date FROM rate_limits WHERE limit_key = :key LIMIT 1',
            ['key' => $key]
        );

        return [
            'hits' => (int) ($row['hit_count'] ?? 1),
            'window_expires_date' => (string) ($row['window_expires_date'] ?? date('Y-m-d H:i:s', time() + $windowSeconds)),
        ];
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM rate_limits WHERE window_expires_date < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}
