<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Request;
use App\Core\Response;

/**
 * Route usage: ['auth', 'role:administrator,supervisor']
 */
final class AuthorizeRoleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $arguments = []): Response
    {
        $user = $request->attribute('auth_user');

        if ($user === null) {
            throw new UnauthorizedException();
        }

        $allowed = array_map('strtolower', $arguments);

        if ($allowed !== [] && !in_array(strtolower((string) $user['role_code']), $allowed, true)) {
            throw new ForbiddenException();
        }

        return $next($request);
    }
}
