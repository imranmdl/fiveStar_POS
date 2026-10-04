<?php

declare(strict_types=1);

namespace App\Repositories;

final class OtpRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'otp_requests';
    }

    protected function fillable(): array
    {
        return [
            'user_id',
            'mobile',
            'email',
            'purpose',
            'otp_hash',
            'reference_token',
            'channel',
            'expires_date',
            'attempt_count',
            'max_attempts',
            'consumed_date',
            'ip_address',
            'user_agent',
            'resend_count',
        ];
    }

    /**
     * The most recent unconsumed, unexpired OTP for a destination + purpose.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(string $mobile, string $purpose): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM otp_requests
              WHERE mobile = :mobile AND purpose = :purpose
                AND consumed_date IS NULL AND is_deleted = 0
                AND expires_date > NOW()
              ORDER BY id DESC LIMIT 1',
            ['mobile' => $mobile, 'purpose' => $purpose]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $referenceToken): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM otp_requests
              WHERE reference_token = :token AND is_deleted = 0
              LIMIT 1',
            ['token' => $referenceToken]
        );
    }

    public function incrementAttempts(int $otpId): int
    {
        $this->db->execute(
            'UPDATE otp_requests
                SET attempt_count = attempt_count + 1, updated_date = NOW(), version = version + 1
              WHERE id = :id',
            ['id' => $otpId]
        );

        return (int) $this->db->scalar('SELECT attempt_count FROM otp_requests WHERE id = :id', ['id' => $otpId]);
    }

    public function markConsumed(int $otpId): void
    {
        $this->db->execute(
            'UPDATE otp_requests
                SET consumed_date = NOW(), updated_date = NOW(), version = version + 1
              WHERE id = :id AND consumed_date IS NULL',
            ['id' => $otpId]
        );
    }

    /**
     * Invalidate every outstanding OTP for a destination + purpose so that
     * only the newest code can ever be redeemed.
     */
    public function invalidateOutstanding(string $mobile, string $purpose): void
    {
        $this->db->execute(
            'UPDATE otp_requests
                SET is_deleted = 1, deleted_date = NOW(), version = version + 1
              WHERE mobile = :mobile AND purpose = :purpose
                AND consumed_date IS NULL AND is_deleted = 0',
            ['mobile' => $mobile, 'purpose' => $purpose]
        );
    }

    public function countSentSince(string $mobile, string $purpose, int $minutes): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM otp_requests
              WHERE mobile = :mobile AND purpose = :purpose
                AND created_date >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)',
            ['mobile' => $mobile, 'purpose' => $purpose, 'minutes' => $minutes]
        );
    }

    public function purgeExpired(int $olderThanDays = 30): int
    {
        return $this->db->execute(
            'DELETE FROM otp_requests WHERE created_date < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $olderThanDays]
        );
    }
}
