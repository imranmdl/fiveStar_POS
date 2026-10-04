<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\UnauthorizedException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Helpers\Jwt;

final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Jwt $jwt,
        private readonly UserRepository $users,
    ) {
    }

    public function handle(Request $request, callable $next, array $arguments = []): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new UnauthorizedException('Authorization token is missing');
        }

        try {
            $claims = $this->jwt->verify($token);
        } catch (\RuntimeException $exception) {
            throw new UnauthorizedException($exception->getMessage());
        }

        if (($claims['typ'] ?? null) !== 'access') {
            throw new UnauthorizedException('A refresh token cannot be used to access resources');
        }

        $user = $this->users->findById((int) ($claims['sub'] ?? 0));

        if ($user === null || (int) $user['is_active'] !== 1) {
            throw new UnauthorizedException('This account is no longer active');
        }

        if ($user['status'] !== 'active') {
            throw new UnauthorizedException('This account is ' . $user['status']);
        }

        // A password change or forced logout invalidates tokens issued earlier
        // without needing a blacklist.
        $issuedAt = (int) ($claims['iat'] ?? 0);
        $invalidBefore = $user['tokens_valid_from'] ?? null;

        if ($invalidBefore !== null && $issuedAt < strtotime((string) $invalidBefore)) {
            throw new UnauthorizedException('Session expired. Please sign in again.');
        }

        $request->setAttribute('auth_user', $user);
        $request->setAttribute('auth_claims', $claims);

        return $next($request);
    }
}
