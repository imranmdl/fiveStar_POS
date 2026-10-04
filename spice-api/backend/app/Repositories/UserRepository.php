<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'users';
    }

    protected function fillable(): array
    {
        return [
            'role_id',
            'full_name',
            'mobile',
            'email',
            'password_hash',
            'status',
            'mobile_verified_date',
            'email_verified_date',
            'referral_code',
            'referred_by_user_id',
            'last_login_date',
            'last_login_ip',
            'failed_login_attempts',
            'locked_until_date',
            'tokens_valid_from',
            'profile_image_path',
            'gender',
            'date_of_birth',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'full_name', 'mobile', 'status', 'created_date', 'last_login_date'];
    }

    private const SELECT_WITH_ROLE = 'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
            ';

    /** @return array<string, mixed>|null */
    public function findById(int $id, bool $withTrashed = false): ?array
    {
        return $this->db->selectOne(
            self::SELECT_WITH_ROLE . ' WHERE u.id = :id' . ($withTrashed ? '' : ' AND u.is_deleted = 0') . ' LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid, bool $withTrashed = false): ?array
    {
        return $this->db->selectOne(
            self::SELECT_WITH_ROLE . ' WHERE u.uuid = :uuid' . ($withTrashed ? '' : ' AND u.is_deleted = 0') . ' LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByMobile(string $mobile): ?array
    {
        return $this->db->selectOne(
            self::SELECT_WITH_ROLE . ' WHERE u.mobile = :mobile AND u.is_deleted = 0 LIMIT 1',
            ['mobile' => $mobile]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            self::SELECT_WITH_ROLE . ' WHERE u.email = :email AND u.is_deleted = 0 LIMIT 1',
            ['email' => strtolower($email)]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByIdentifier(string $identifier): ?array
    {
        return str_contains($identifier, '@')
            ? $this->findByEmail($identifier)
            : $this->findByMobile(preg_replace('/\D/', '', $identifier) ?? $identifier);
    }

    /** @return array<string, mixed>|null */
    public function findByReferralCode(string $code): ?array
    {
        return $this->findOneBy('referral_code', strtoupper($code));
    }

    public function mobileExists(string $mobile): bool
    {
        return $this->existsWhere('mobile', $mobile);
    }

    public function emailExists(string $email): bool
    {
        return $this->existsWhere('email', strtolower($email));
    }

    public function referralCodeExists(string $code): bool
    {
        return $this->existsWhere('referral_code', $code);
    }

    /**
     * Finds a customer by mobile, or creates one on the spot — the POS's
     * "walk-in owes the rest of the bill" path, so a cashier never has to
     * send someone through the storefront's own sign-up flow just to record
     * a due. Nobody knows the generated password; the customer sets their
     * own later via "Forgot password" if they ever want to sign in.
     * Idempotent on mobile: an existing account is returned unchanged.
     *
     * @return array{row: array<string, mixed>, created: bool}
     */
    public function findOrCreateWalkIn(string $fullName, string $mobile, ?int $actorId): array
    {
        $existing = $this->findByMobile($mobile);

        if ($existing !== null) {
            return ['row' => $existing, 'created' => false];
        }

        $roleId = (int) $this->db->scalar(
            "SELECT `id` FROM `roles` WHERE `code` = 'customer' AND `is_deleted` = 0 LIMIT 1"
        );

        do {
            $referral = 'C' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
        } while ($this->referralCodeExists($referral));

        $userId = $this->create([
            'role_id' => $roleId,
            'full_name' => $fullName,
            'mobile' => $mobile,
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, ['cost' => 12]),
            'status' => 'active',
            'mobile_verified_date' => date('Y-m-d H:i:s'),
            'referral_code' => $referral,
        ], $actorId);

        return ['row' => (array) $this->findById($userId), 'created' => true];
    }

    public function markMobileVerified(int $userId): void
    {
        $this->db->execute(
            'UPDATE users
                SET mobile_verified_date = NOW(),
                    status = CASE WHEN status = \'pending_verification\' THEN \'active\' ELSE status END,
                    updated_date = NOW(),
                    version = version + 1
              WHERE id = :id',
            ['id' => $userId]
        );
    }

    public function recordSuccessfulLogin(int $userId, string $ip): void
    {
        $this->db->execute(
            'UPDATE users
                SET last_login_date = NOW(), last_login_ip = :ip,
                    failed_login_attempts = 0, locked_until_date = NULL,
                    updated_date = NOW(), version = version + 1
              WHERE id = :id',
            ['ip' => $ip, 'id' => $userId]
        );
    }

    /**
     * Returns the new failure count so the caller can decide about locking.
     */
    public function recordFailedLogin(int $userId): int
    {
        $this->db->execute(
            'UPDATE users
                SET failed_login_attempts = failed_login_attempts + 1,
                    updated_date = NOW(), version = version + 1
              WHERE id = :id',
            ['id' => $userId]
        );

        return (int) $this->db->scalar('SELECT failed_login_attempts FROM users WHERE id = :id', ['id' => $userId]);
    }

    public function lockAccount(int $userId, int $minutes): void
    {
        $this->db->execute(
            'UPDATE users
                SET locked_until_date = DATE_ADD(NOW(), INTERVAL :minutes MINUTE),
                    updated_date = NOW(), version = version + 1
              WHERE id = :id',
            ['minutes' => $minutes, 'id' => $userId]
        );
    }

    /**
     * Admin Privilege Management: clears a lockout early instead of waiting
     * out LOGIN_LOCKOUT_MINUTES. Additive — the automatic lockout/unlock
     * flow in AuthService is untouched.
     */
    public function unlock(int $userId): void
    {
        $this->db->execute(
            'UPDATE users
                SET failed_login_attempts = 0, locked_until_date = NULL,
                    updated_date = NOW(), version = version + 1
              WHERE id = :id',
            ['id' => $userId]
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateAll(array $params, ?string $roleCode = null): array
    {
        $where = ['u.is_deleted = 0'];
        $bindings = [];

        if ($roleCode !== null) {
            $where[] = 'r.code = :role_code';
            $bindings['role_code'] = $roleCode;
        }

        if ($params['search'] !== null) {
            $where[] = '(u.full_name LIKE :search OR u.mobile LIKE :search OR u.email LIKE :search)';
            $bindings['search'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE ' . $whereSql,
            $bindings
        );

        $items = $this->db->select(
            sprintf(
                '%s WHERE %s ORDER BY u.`%s` %s LIMIT %d OFFSET %d',
                self::SELECT_WITH_ROLE,
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        // tokens_valid_from invalidates every access token issued before the
        // password change.
        $this->db->execute(
            'UPDATE users
                SET password_hash = :hash, tokens_valid_from = NOW(),
                    failed_login_attempts = 0, locked_until_date = NULL,
                    updated_by = :actor, updated_date = NOW(), version = version + 1
              WHERE id = :id',
            ['hash' => $passwordHash, 'actor' => $userId, 'id' => $userId]
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateStaff(array $params, ?string $roleCode = null): array
    {
        $where = ['u.is_deleted = 0', "r.code <> 'customer'"];
        $bindings = [];

        if ($roleCode !== null) {
            $where[] = 'r.code = :role_code';
            $bindings['role_code'] = $roleCode;
        }

        if ($params['search'] !== null) {
            $where[] = '(u.full_name LIKE :search OR u.mobile LIKE :search OR u.email LIKE :search)';
            $bindings['search'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE ' . $whereSql,
            $bindings
        );

        $items = $this->db->select(
            sprintf(
                '%s WHERE %s ORDER BY u.`%s` %s LIMIT %d OFFSET %d',
                self::SELECT_WITH_ROLE,
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }
}
