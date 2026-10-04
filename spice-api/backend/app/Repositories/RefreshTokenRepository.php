<?php

declare(strict_types=1);

namespace App\Repositories;

final class RefreshTokenRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'refresh_tokens';
    }

    protected function fillable(): array
    {
        return [
            'user_id',
            'token_hash',
            'device_id',
            'device_name',
            'platform',
            'ip_address',
            'user_agent',
            'expires_date',
            'revoked_date',
            'revoked_reason',
            'replaced_by_token_id',
        ];
    }

    /**
     * Tokens are stored as SHA-256 digests: a database leak does not hand an
     * attacker usable refresh tokens.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed>|null */
    public function findUsable(string $token): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM refresh_tokens
              WHERE token_hash = :hash AND revoked_date IS NULL
                AND is_deleted = 0 AND expires_date > NOW()
              LIMIT 1',
            ['hash' => self::hash($token)]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByHashIncludingRevoked(string $token): ?array
    {
        return $this->findOneBy('token_hash', self::hash($token), true);
    }

    public function revoke(int $tokenId, string $reason, ?int $replacedById = null): void
    {
        $this->db->execute(
            'UPDATE refresh_tokens
                SET revoked_date = NOW(), revoked_reason = :reason,
                    replaced_by_token_id = :replaced, updated_date = NOW(), version = version + 1
              WHERE id = :id AND revoked_date IS NULL',
            ['reason' => $reason, 'replaced' => $replacedById, 'id' => $tokenId]
        );
    }

    public function revokeAllForUser(int $userId, string $reason): int
    {
        return $this->db->execute(
            'UPDATE refresh_tokens
                SET revoked_date = NOW(), revoked_reason = :reason,
                    updated_date = NOW(), version = version + 1
              WHERE user_id = :user_id AND revoked_date IS NULL AND is_deleted = 0',
            ['reason' => $reason, 'user_id' => $userId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function activeSessionsForUser(int $userId): array
    {
        return $this->db->select(
            'SELECT uuid, device_id, device_name, platform, ip_address, created_date, expires_date
               FROM refresh_tokens
              WHERE user_id = :user_id AND revoked_date IS NULL
                AND is_deleted = 0 AND expires_date > NOW()
              ORDER BY created_date DESC',
            ['user_id' => $userId]
        );
    }
}
