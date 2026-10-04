<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'jwt' => [
        'secret' => Env::get('JWT_SECRET', ''),
        'issuer' => Env::get('JWT_ISSUER', 'spice-commerce-api'),
        // Short access token life; clients refresh silently.
        'access_ttl_seconds' => Env::int('JWT_ACCESS_TTL_SECONDS', 900),
        'refresh_ttl_seconds' => Env::int('JWT_REFRESH_TTL_SECONDS', 2592000),
        'leeway_seconds' => Env::int('JWT_LEEWAY_SECONDS', 30),
    ],

    'otp' => [
        'length' => Env::int('OTP_LENGTH', 6),
        'ttl_seconds' => Env::int('OTP_TTL_SECONDS', 300),
        'resend_cooldown_seconds' => Env::int('OTP_RESEND_COOLDOWN_SECONDS', 60),
        'max_verify_attempts' => Env::int('OTP_MAX_VERIFY_ATTEMPTS', 5),
        'max_per_hour' => Env::int('OTP_MAX_PER_HOUR', 6),
        'pepper' => Env::get('OTP_PEPPER', ''),
        // Never enable outside local development.
        'expose_in_response' => Env::bool('OTP_EXPOSE_IN_RESPONSE', false)
            && Env::get('APP_ENV', 'production') === 'local',
    ],

    'password' => [
        'bcrypt_cost' => Env::int('BCRYPT_COST', 12),
    ],

    'lockout' => [
        'max_attempts' => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'duration_minutes' => Env::int('LOGIN_LOCKOUT_MINUTES', 15),
    ],

    // Admin Privilege Management panel (separate login + dashboard). Reuses
    // every setting above (same password hashing, same lockout, same JWT
    // access tokens) — this only adds the panel's own inactivity timeout,
    // enforced by AdminPrivilegeMiddleware against admin_privilege_activity,
    // independent of the JWT's own expiry.
    'admin_privilege' => [
        'idle_timeout_minutes' => Env::int('ADMIN_PRIVILEGE_IDLE_MINUTES', 20),
    ],
];
