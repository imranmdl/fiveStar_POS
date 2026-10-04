<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * HS256 JSON Web Tokens with no external dependency.
 *
 * Access tokens are stateless and short-lived. Refresh tokens are opaque
 * random strings stored hashed in the database (see RefreshTokenRepository),
 * which is what makes logout and token revocation real rather than cosmetic.
 */
final class Jwt
{
    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly int $accessTtlSeconds,
        private readonly int $leewaySeconds = 30,
    ) {
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters long.');
        }
    }

    public function accessTtl(): int
    {
        return $this->accessTtlSeconds;
    }

    /** @param array<string, mixed> $claims */
    public function issue(array $claims): string
    {
        $now = time();

        $payload = $claims + [
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->accessTtlSeconds,
            'jti' => bin2hex(random_bytes(12)),
        ];

        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->base64UrlEncode((string) json_encode($payload));
        $signature = $this->sign($header . '.' . $body);

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the token is malformed, forged or expired
     */
    public function verify(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new \RuntimeException('Malformed token');
        }

        [$header, $body, $signature] = $segments;

        if (!hash_equals($this->sign($header . '.' . $body), $signature)) {
            throw new \RuntimeException('Invalid token signature');
        }

        $decodedHeader = json_decode((string) $this->base64UrlDecode($header), true);

        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== 'HS256') {
            throw new \RuntimeException('Unsupported token algorithm');
        }

        $payload = json_decode((string) $this->base64UrlDecode($body), true);

        if (!is_array($payload)) {
            throw new \RuntimeException('Malformed token payload');
        }

        $now = time();

        if (isset($payload['nbf']) && $now + $this->leewaySeconds < (int) $payload['nbf']) {
            throw new \RuntimeException('Token is not valid yet');
        }

        if (isset($payload['exp']) && $now - $this->leewaySeconds >= (int) $payload['exp']) {
            throw new \RuntimeException('Token has expired');
        }

        if (isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
            throw new \RuntimeException('Token issuer mismatch');
        }

        return $payload;
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
