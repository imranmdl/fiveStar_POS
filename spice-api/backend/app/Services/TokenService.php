<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Request;
use App\Helpers\Jwt;
use App\Helpers\Str;
use App\Repositories\RefreshTokenRepository;

/**
 * Issues the token pair consumed by web, Android and iOS clients.
 *
 * Refresh tokens rotate: each use revokes the presented token and returns a
 * new one. If a token that was already rotated is presented again, that is
 * treated as theft and every session for the user is revoked.
 */
final class TokenService
{
    public function __construct(
        private readonly Jwt $jwt,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, refresh_expires_in:int}
     */
    public function issueFor(array $user, ?Request $request = null): array
    {
        $accessToken = $this->jwt->issue([
            'sub' => (int) $user['id'],
            'uid' => (string) $user['uuid'],
            'typ' => 'access',
            'role' => (string) $user['role_code'],
            'name' => (string) $user['full_name'],
        ]);

        $refreshToken = Str::randomToken(40);
        $refreshTtl = (int) $this->config->get('auth.jwt.refresh_ttl_seconds', 2592000);

        $this->refreshTokens->create([
            'user_id' => (int) $user['id'],
            'token_hash' => RefreshTokenRepository::hash($refreshToken),
            'device_id' => $request?->input('device_id'),
            'device_name' => $request?->input('device_name'),
            'platform' => $this->platform($request),
            'ip_address' => $request?->ip,
            'user_agent' => $request?->userAgent,
            'expires_date' => date('Y-m-d H:i:s', time() + $refreshTtl),
        ], (int) $user['id']);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->accessTtl(),
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /**
     * @return array{token_row:array<string, mixed>, user_id:int}
     *
     * @throws UnauthorizedException
     */
    public function validateRefreshToken(string $token): array
    {
        $row = $this->refreshTokens->findUsable($token);

        if ($row !== null) {
            return ['token_row' => $row, 'user_id' => (int) $row['user_id']];
        }

        // Not usable. Distinguish "already rotated" (possible theft) from
        // "never existed / expired".
        $known = $this->refreshTokens->findByHashIncludingRevoked($token);

        if ($known !== null && $known['revoked_date'] !== null && $known['revoked_reason'] === 'rotated') {
            $this->refreshTokens->revokeAllForUser((int) $known['user_id'], 'reuse_detected');

            throw new UnauthorizedException(
                'This session has been closed for security reasons. Please sign in again.'
            );
        }

        throw new UnauthorizedException('Refresh token is invalid or has expired');
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, refresh_expires_in:int}
     */
    public function rotate(array $user, int $presentedTokenId, ?Request $request = null): array
    {
        $tokens = $this->issueFor($user, $request);

        $newRow = $this->refreshTokens->findUsable($tokens['refresh_token']);
        $this->refreshTokens->revoke(
            $presentedTokenId,
            'rotated',
            $newRow === null ? null : (int) $newRow['id']
        );

        return $tokens;
    }

    public function revoke(string $refreshToken, string $reason = 'logout'): bool
    {
        $row = $this->refreshTokens->findUsable($refreshToken);

        if ($row === null) {
            return false;
        }

        $this->refreshTokens->revoke((int) $row['id'], $reason);

        return true;
    }

    public function revokeAllForUser(int $userId, string $reason = 'logout_all'): int
    {
        return $this->refreshTokens->revokeAllForUser($userId, $reason);
    }

    /** @return array<int, array<string, mixed>> */
    public function activeSessions(int $userId): array
    {
        return $this->refreshTokens->activeSessionsForUser($userId);
    }

    private function platform(?Request $request): string
    {
        $declared = $request?->input('platform');

        if (is_string($declared) && in_array($declared, ['web', 'android', 'ios'], true)) {
            return $declared;
        }

        $agent = strtolower($request?->userAgent ?? '');

        return match (true) {
            str_contains($agent, 'android') => 'android',
            str_contains($agent, 'iphone') || str_contains($agent, 'ipad') => 'ios',
            default => 'web',
        };
    }
}
