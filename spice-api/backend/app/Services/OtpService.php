<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\TooManyRequestsException;
use App\Core\Request;
use App\Helpers\Str;
use App\Helpers\Uuid;
use App\Repositories\OtpRepository;
use App\Services\Notifications\SmsGatewayInterface;

/**
 * Single source of truth for one-time passwords.
 *
 * Used by registration, OTP login, password reset and — per BR-003 — order
 * confirmation. Any future module that needs an OTP calls this service rather
 * than reimplementing the flow.
 *
 * Security properties:
 *  - The code is never stored in plaintext; only a peppered SHA-256 digest is.
 *  - Verification is constant-time (hash_equals).
 *  - Issuing a new code invalidates all outstanding codes for that purpose.
 *  - Per-destination resend throttling and per-code attempt limits are enforced.
 */
final class OtpService
{
    public const PURPOSE_REGISTRATION = 'registration';
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_ORDER_CONFIRMATION = 'order_confirmation';
    public const PURPOSE_MOBILE_CHANGE = 'mobile_change';

    public const PURPOSES = [
        self::PURPOSE_REGISTRATION,
        self::PURPOSE_LOGIN,
        self::PURPOSE_PASSWORD_RESET,
        self::PURPOSE_ORDER_CONFIRMATION,
        self::PURPOSE_MOBILE_CHANGE,
    ];

    public function __construct(
        private readonly OtpRepository $repository,
        private readonly SmsGatewayInterface $sms,
        private readonly Config $config,
    ) {
    }

    /**
     * Issue and dispatch a code.
     *
     * @return array{reference_token:string, expires_in_seconds:int, resend_available_in_seconds:int, debug_otp:?string}
     */
    public function issue(
        string $mobile,
        string $purpose,
        ?int $userId = null,
        ?Request $request = null,
    ): array {
        $this->assertPurpose($purpose);
        $this->assertResendAllowed($mobile, $purpose);

        $length = (int) $this->config->get('auth.otp.length', 6);
        $ttlSeconds = (int) $this->config->get('auth.otp.ttl_seconds', 300);
        $maxAttempts = (int) $this->config->get('auth.otp.max_verify_attempts', 5);

        $code = Str::numericCode($length);
        $referenceToken = Str::randomToken(24);

        $this->repository->invalidateOutstanding($mobile, $purpose);

        $this->repository->create([
            'uuid' => Uuid::v4(),
            'user_id' => $userId,
            'mobile' => $mobile,
            'purpose' => $purpose,
            'otp_hash' => $this->hash($code),
            'reference_token' => $referenceToken,
            'channel' => 'sms',
            'expires_date' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            'attempt_count' => 0,
            'max_attempts' => $maxAttempts,
            'ip_address' => $request?->ip,
            'user_agent' => $request?->userAgent,
            'resend_count' => 0,
        ], $userId);

        $this->sms->send($mobile, $this->message($code, $purpose, $ttlSeconds), [
            'otp' => $code,
            'minutes' => (string) (int) ceil($ttlSeconds / 60),
        ]);

        return [
            'reference_token' => $referenceToken,
            'expires_in_seconds' => $ttlSeconds,
            'resend_available_in_seconds' => (int) $this->config->get('auth.otp.resend_cooldown_seconds', 60),
            // Only populated when OTP_EXPOSE_IN_RESPONSE=true, which is intended
            // for local development and automated tests only.
            'debug_otp' => $this->config->get('auth.otp.expose_in_response', false) === true ? $code : null,
        ];
    }

    /**
     * Verify a code. On success the OTP row is consumed and cannot be reused.
     *
     * @return array<string, mixed> The consumed OTP row.
     *
     * @throws HttpException on wrong, expired or exhausted codes
     */
    public function verify(string $mobile, string $purpose, string $code, ?string $referenceToken = null): array
    {
        $this->assertPurpose($purpose);

        $otp = $referenceToken !== null
            ? $this->repository->findByReference($referenceToken)
            : $this->repository->findActive($mobile, $purpose);

        if ($otp === null || $otp['mobile'] !== $mobile || $otp['purpose'] !== $purpose) {
            throw new HttpException('No active verification code found. Please request a new one.', 400);
        }

        if ($otp['consumed_date'] !== null) {
            throw new HttpException('This verification code has already been used.', 400);
        }

        if (strtotime((string) $otp['expires_date']) <= time()) {
            throw new HttpException('This verification code has expired. Please request a new one.', 400);
        }

        if ((int) $otp['attempt_count'] >= (int) $otp['max_attempts']) {
            throw new HttpException('Too many incorrect attempts. Please request a new code.', 429);
        }

        if (!hash_equals((string) $otp['otp_hash'], $this->hash($code))) {
            $attempts = $this->repository->incrementAttempts((int) $otp['id']);
            $remaining = max(0, (int) $otp['max_attempts'] - $attempts);

            if ($remaining === 0) {
                $this->repository->invalidateOutstanding($mobile, $purpose);

                throw new HttpException('Too many incorrect attempts. Please request a new code.', 429);
            }

            throw new HttpException(
                sprintf('Incorrect verification code. %d attempt(s) remaining.', $remaining),
                400
            );
        }

        $this->repository->markConsumed((int) $otp['id']);

        return $otp;
    }

    private function assertResendAllowed(string $mobile, string $purpose): void
    {
        $cooldown = (int) $this->config->get('auth.otp.resend_cooldown_seconds', 60);
        $active = $this->repository->findActive($mobile, $purpose);

        if ($active !== null) {
            $elapsed = time() - strtotime((string) $active['created_date']);

            if ($elapsed < $cooldown) {
                throw new TooManyRequestsException(
                    sprintf('Please wait %d seconds before requesting another code.', $cooldown - $elapsed),
                    $cooldown - $elapsed
                );
            }
        }

        $hourlyLimit = (int) $this->config->get('auth.otp.max_per_hour', 6);

        if ($this->repository->countSentSince($mobile, $purpose, 60) >= $hourlyLimit) {
            throw new TooManyRequestsException(
                'Hourly verification code limit reached for this number. Please try again later.',
                900
            );
        }
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new HttpException('Unsupported verification purpose: ' . $purpose, 422);
        }
    }

    /**
     * The pepper lives in the environment, not the database, so a dump of
     * otp_requests alone cannot be brute-forced offline.
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) $this->config->get('auth.otp.pepper'));
    }

    private function message(string $code, string $purpose, int $ttlSeconds): string
    {
        $minutes = (int) ceil($ttlSeconds / 60);
        $brand = (string) $this->config->get('app.brand_name', 'Spice & Dry Fruits');

        return match ($purpose) {
            self::PURPOSE_ORDER_CONFIRMATION => sprintf(
                '%s is your OTP to confirm your %s order. Valid for %d minutes. Do not share it with anyone.',
                $code,
                $brand,
                $minutes
            ),
            self::PURPOSE_PASSWORD_RESET => sprintf(
                '%s is your %s password reset code. Valid for %d minutes. Do not share it with anyone.',
                $code,
                $brand,
                $minutes
            ),
            default => sprintf(
                '%s is your %s verification code. Valid for %d minutes. Do not share it with anyone.',
                $code,
                $brand,
                $minutes
            ),
        };
    }
}
