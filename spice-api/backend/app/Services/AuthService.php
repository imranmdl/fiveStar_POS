<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Request;
use App\Helpers\Str;
use App\Repositories\UserRepository;

/**
 * All authentication business logic lives here. Controllers only translate
 * HTTP to service calls, which is what lets the Flutter app and the web app
 * share one implementation.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
        private readonly AuditService $audit,
        private readonly ReferralService $referrals,
        private readonly WelcomeBonusService $welcomeBonus,
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Registration does not create a session. The account starts as
     * pending_verification and only becomes active once the mobile OTP is
     * verified (SRS Module 1).
     *
     * @param array{full_name:string, mobile:string, email:?string, password:string, referral_code:?string} $data
     *
     * @return array<string, mixed>
     */
    public function register(array $data, Request $request): array
    {
        if ($this->users->mobileExists($data['mobile'])) {
            throw new HttpException(
                'This mobile number is already registered. Please sign in instead.',
                409,
                ['mobile' => ['This mobile number is already registered.']]
            );
        }

        if (!empty($data['email']) && $this->users->emailExists($data['email'])) {
            throw new HttpException(
                'This email address is already registered.',
                409,
                ['email' => ['This email address is already registered.']]
            );
        }

        $referrer = null;

        if (!empty($data['referral_code'])) {
            $referrer = $this->users->findByReferralCode((string) $data['referral_code']);

            if ($referrer === null) {
                throw new HttpException(
                    'The referral code entered is not valid.',
                    422,
                    ['referral_code' => ['This referral code does not exist.']]
                );
            }
        }

        $customerRoleId = (int) $this->db->scalar(
            "SELECT id FROM roles WHERE code = 'customer' AND is_deleted = 0 LIMIT 1"
        );

        if ($customerRoleId === 0) {
            throw new HttpException('Customer role is not configured. Run the database seed.', 500);
        }

        $userId = $this->db->transaction(function () use ($data, $customerRoleId, $referrer): int {
            return $this->users->create([
                'role_id' => $customerRoleId,
                'full_name' => $data['full_name'],
                'mobile' => $data['mobile'],
                'email' => empty($data['email']) ? null : strtolower((string) $data['email']),
                'password_hash' => $this->hashPassword($data['password']),
                'status' => 'pending_verification',
                'referral_code' => $this->generateReferralCode($data['full_name']),
                'referred_by_user_id' => $referrer === null ? null : (int) $referrer['id'],
                'is_active' => 1,
            ]);
        });

        $this->audit->log(
            entityName: 'users',
            entityId: $userId,
            action: 'register',
            newValues: [
                'full_name' => $data['full_name'],
                'mobile' => Str::maskMobile($data['mobile']),
                'email' => Str::maskEmail($data['email'] ?? null),
                'referred_by_user_id' => $referrer === null ? null : (int) $referrer['id'],
            ],
            request: $request,
            notes: 'Self-service customer registration'
        );

        // Referral bookkeeping is best-effort by design: it must never fail a
        // registration that has already succeeded. The reward is not paid here —
        // it waits for the new customer's first qualifying order.
        if ($referrer !== null) {
            $this->referrals->recordSignup(
                refereeUserId: $userId,
                referrerUserId: (int) $referrer['id'],
                codeUsed: (string) $data['referral_code'],
                request: $request,
            );
        }

        $challenge = $this->otp->issue($data['mobile'], OtpService::PURPOSE_REGISTRATION, $userId, $request);
        $user = $this->users->findById($userId);

        return [
            'user' => $this->publicUser((array) $user),
            'verification' => $challenge,
        ];
    }

    /**
     * Completes registration (or any mobile-verification flow) and signs the
     * customer in, so the client needs one round trip instead of two.
     *
     * @return array<string, mixed>
     */
    public function verifyRegistration(
        string $mobile,
        string $code,
        ?string $referenceToken,
        Request $request,
    ): array {
        $user = $this->users->findByMobile($mobile);

        if ($user === null) {
            throw new HttpException('No account exists for this mobile number.', 404);
        }

        $this->otp->verify($mobile, OtpService::PURPOSE_REGISTRATION, $code, $referenceToken);
        $this->users->markMobileVerified((int) $user['id']);

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'mobile_verified',
            request: $request
        );

        // Best-effort, same as the referral bookkeeping above it in
        // register(): a wallet problem must never turn a successful
        // verification into a failed one. See WelcomeBonusService's own
        // doc comment — off unless an admin has turned it on.
        $this->welcomeBonus->creditIfEligible((int) $user['id'], $request);

        $user = $this->users->findById((int) $user['id']);
        $tokens = $this->tokens->issueFor((array) $user, $request);
        $this->users->recordSuccessfulLogin((int) $user['id'], $request->ip);

        return ['user' => $this->publicUser((array) $user), 'tokens' => $tokens];
    }

    /**
     * Step 1 of OTP login. Deliberately reports the same result whether or not
     * the number exists, so the endpoint cannot be used to enumerate customers.
     *
     * @return array<string, mixed>
     */
    public function requestLoginOtp(string $mobile, Request $request): array
    {
        $user = $this->users->findByMobile($mobile);

        if ($user === null) {
            return [
                'reference_token' => null,
                'expires_in_seconds' => (int) $this->config->get('auth.otp.ttl_seconds', 300),
                'resend_available_in_seconds' => (int) $this->config->get('auth.otp.resend_cooldown_seconds', 60),
                'debug_otp' => null,
            ];
        }

        $this->assertUsable($user);

        return $this->otp->issue($mobile, OtpService::PURPOSE_LOGIN, (int) $user['id'], $request);
    }

    /**
     * Step 2 of OTP login.
     *
     * @return array<string, mixed>
     */
    public function loginWithOtp(string $mobile, string $code, ?string $referenceToken, Request $request): array
    {
        $user = $this->users->findByMobile($mobile);

        if ($user === null) {
            throw new UnauthorizedException('Invalid mobile number or verification code');
        }

        $this->assertUsable($user);
        $this->otp->verify($mobile, OtpService::PURPOSE_LOGIN, $code, $referenceToken);

        if ($user['mobile_verified_date'] === null) {
            $this->users->markMobileVerified((int) $user['id']);
            // Same first-verification hook as verifyRegistration() — a
            // customer who never went through the registration-OTP screen
            // and instead verifies here, on their first OTP login, is still
            // a brand-new customer and still eligible exactly once
            // (WalletService::credit()'s idempotency key is per user, not
            // per call site).
            $this->welcomeBonus->creditIfEligible((int) $user['id'], $request);
            $user = (array) $this->users->findById((int) $user['id']);
        }

        return $this->completeLogin($user, $request, 'otp');
    }

    /**
     * Password login with progressive lockout (SRS Module 2: Account Lock).
     *
     * @return array<string, mixed>
     */
    public function loginWithPassword(string $identifier, string $password, Request $request): array
    {
        $user = $this->users->findByIdentifier($identifier);

        if ($user === null) {
            // Uniform timing and message: no account enumeration.
            $this->wasteTime();

            throw new UnauthorizedException('Invalid credentials');
        }

        $this->assertNotLocked($user);

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->handleFailedPassword($user, $request);

            throw new UnauthorizedException('Invalid credentials');
        }

        $this->assertUsable($user);

        if ($user['mobile_verified_date'] === null) {
            $challenge = $this->otp->issue(
                (string) $user['mobile'],
                OtpService::PURPOSE_REGISTRATION,
                (int) $user['id'],
                $request
            );

            throw new HttpException(
                'Your mobile number is not verified yet. A verification code has been sent.',
                403,
                ['verification' => [json_encode($challenge, JSON_UNESCAPED_SLASHES)]]
            );
        }

        // Rehash if the configured cost has since increased.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT, $this->passwordOptions())) {
            $this->users->updatePassword((int) $user['id'], $this->hashPassword($password));
        }

        return $this->completeLogin($user, $request, 'password');
    }

    /**
     * A step-up check: re-confirms the ALREADY-AUTHENTICATED caller's own
     * password without issuing a new session. Used to gate a sensitive
     * screen (e.g. Admin Privilege Management) behind a fresh "type your
     * password again" for a console session that's already signed in —
     * not a sign-in itself, so nothing here calls TokenService or touches
     * last_login_date/ip.
     *
     * Reuses the same lockout counters as an ordinary password login:
     * repeated wrong attempts here lock the account exactly the way a bad
     * /auth/login attempt would, since this is reachable by anyone already
     * holding a valid bearer token for the account and should not become an
     * unthrottled way to brute-force that account's password.
     */
    public function verifyOwnPassword(int $userId, string $password, Request $request): void
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new UnauthorizedException('Invalid credentials');
        }

        $this->assertNotLocked($user);

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->handleFailedPassword($user, $request);

            throw new UnauthorizedException('Incorrect password.');
        }
    }

    /**
     * @return array{reference_token:?string, expires_in_seconds:int, resend_available_in_seconds:int, debug_otp:?string}
     */
    public function forgotPassword(string $mobile, Request $request): array
    {
        $user = $this->users->findByMobile($mobile);

        if ($user === null) {
            return [
                'reference_token' => null,
                'expires_in_seconds' => (int) $this->config->get('auth.otp.ttl_seconds', 300),
                'resend_available_in_seconds' => (int) $this->config->get('auth.otp.resend_cooldown_seconds', 60),
                'debug_otp' => null,
            ];
        }

        return $this->otp->issue($mobile, OtpService::PURPOSE_PASSWORD_RESET, (int) $user['id'], $request);
    }

    public function resetPassword(
        string $mobile,
        string $code,
        string $newPassword,
        ?string $referenceToken,
        Request $request,
    ): void {
        $user = $this->users->findByMobile($mobile);

        if ($user === null) {
            throw new HttpException('No account exists for this mobile number.', 404);
        }

        $this->otp->verify($mobile, OtpService::PURPOSE_PASSWORD_RESET, $code, $referenceToken);

        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new HttpException(
                'The new password must be different from your current password.',
                422,
                ['password' => ['Choose a password you have not used before.']]
            );
        }

        $this->db->transaction(function () use ($user, $newPassword): void {
            $this->users->updatePassword((int) $user['id'], $this->hashPassword($newPassword));
            // Every existing session dies with the old password.
            $this->tokens->revokeAllForUser((int) $user['id'], 'password_reset');
        });

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'password_reset',
            request: $request,
            notes: 'Reset via mobile OTP'
        );
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, Request $request): void
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new UnauthorizedException();
        }

        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new HttpException(
                'Your current password is incorrect.',
                422,
                ['current_password' => ['This does not match your current password.']]
            );
        }

        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new HttpException(
                'The new password must be different from your current password.',
                422,
                ['password' => ['Choose a password you have not used before.']]
            );
        }

        $this->db->transaction(function () use ($userId, $newPassword): void {
            $this->users->updatePassword($userId, $this->hashPassword($newPassword));
            $this->tokens->revokeAllForUser($userId, 'password_changed');
        });

        $this->audit->log(
            entityName: 'users',
            entityId: $userId,
            action: 'password_changed',
            request: $request
        );
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken, Request $request): array
    {
        $validated = $this->tokens->validateRefreshToken($refreshToken);
        $user = $this->users->findById($validated['user_id']);

        if ($user === null) {
            throw new UnauthorizedException('Account no longer exists');
        }

        $this->assertUsable($user);

        $tokens = $this->tokens->rotate($user, (int) $validated['token_row']['id'], $request);

        return ['user' => $this->publicUser($user), 'tokens' => $tokens];
    }

    public function logout(int $userId, ?string $refreshToken, bool $allDevices, Request $request): int
    {
        $revoked = $allDevices
            ? $this->tokens->revokeAllForUser($userId)
            : (int) ($refreshToken !== null && $this->tokens->revoke($refreshToken));

        $this->audit->log(
            entityName: 'users',
            entityId: $userId,
            action: $allDevices ? 'logout_all_devices' : 'logout',
            request: $request
        );

        return $revoked;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function publicUser(array $user): array
    {
        return [
            'uuid' => $user['uuid'],
            'full_name' => $user['full_name'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'role' => $user['role_code'] ?? null,
            'role_name' => $user['role_name'] ?? null,
            'status' => $user['status'],
            'mobile_verified' => $user['mobile_verified_date'] !== null,
            'email_verified' => $user['email_verified_date'] !== null,
            'referral_code' => $user['referral_code'],
            'profile_image_path' => $user['profile_image_path'] ?? null,
            'last_login_date' => $user['last_login_date'] ?? null,
            'created_date' => $user['created_date'],
        ];
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function completeLogin(array $user, Request $request, string $method): array
    {
        $tokens = $this->tokens->issueFor($user, $request);
        $this->users->recordSuccessfulLogin((int) $user['id'], $request->ip);

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'login',
            newValues: ['method' => $method, 'ip' => $request->ip],
            request: $request
        );

        $fresh = $this->users->findById((int) $user['id']);

        return ['user' => $this->publicUser((array) $fresh), 'tokens' => $tokens];
    }

    /** @param array<string, mixed> $user */
    private function handleFailedPassword(array $user, Request $request): void
    {
        $attempts = $this->users->recordFailedLogin((int) $user['id']);
        $threshold = (int) $this->config->get('auth.lockout.max_attempts', 5);
        $lockMinutes = (int) $this->config->get('auth.lockout.duration_minutes', 15);

        if ($attempts >= $threshold) {
            $this->users->lockAccount((int) $user['id'], $lockMinutes);

            $this->audit->log(
                entityName: 'users',
                entityId: (int) $user['id'],
                action: 'account_locked',
                newValues: ['failed_attempts' => $attempts, 'locked_minutes' => $lockMinutes],
                request: $request
            );
        }
    }

    /** @param array<string, mixed> $user */
    private function assertNotLocked(array $user): void
    {
        if ($user['locked_until_date'] === null) {
            return;
        }

        $lockedUntil = strtotime((string) $user['locked_until_date']);

        if ($lockedUntil > time()) {
            $minutes = (int) ceil(($lockedUntil - time()) / 60);

            throw new ForbiddenException(
                sprintf('This account is temporarily locked. Try again in %d minute(s).', $minutes)
            );
        }
    }

    /** @param array<string, mixed> $user */
    private function assertUsable(array $user): void
    {
        if ((int) $user['is_active'] !== 1 || (int) $user['is_deleted'] === 1) {
            throw new ForbiddenException('This account has been deactivated. Please contact support.');
        }

        if (in_array($user['status'], ['suspended', 'blocked'], true)) {
            throw new ForbiddenException('This account is ' . $user['status'] . '. Please contact support.');
        }
    }

    private function generateReferralCode(string $name): string
    {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $code = Str::referralCode($name);

            if (!$this->users->referralCodeExists($code)) {
                return $code;
            }
        }

        throw new HttpException('Unable to allocate a referral code. Please retry.', 500);
    }

    private function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, $this->passwordOptions());
    }

    /** @return array<string, int> */
    private function passwordOptions(): array
    {
        return ['cost' => (int) $this->config->get('auth.password.bcrypt_cost', 12)];
    }

    /**
     * Equalises response time for unknown accounts so that timing does not
     * reveal whether an identifier exists.
     */
    private function wasteTime(): void
    {
        // Hashing (rather than verifying against a literal) guarantees the same
        // bcrypt work factor is actually spent, whatever the configured cost.
        password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, $this->passwordOptions());
    }
}
