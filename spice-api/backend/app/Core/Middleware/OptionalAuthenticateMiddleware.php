<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Authenticates the caller when a token is present, but allows anonymous
 * callers through.
 *
 * The cart routes need exactly this. They are public because a guest must be
 * able to fill a cart before signing in — but without this middleware a
 * signed-in customer is *also* treated as a guest, because nothing ever
 * populates the auth context on a public route. That produced a subtle and
 * total failure: `Request::authUserId()` returned null for every cart
 * operation, so a logged-in customer got a guest cart keyed by their cart
 * token, coupons were refused as "sign in to use a coupon", and wallet credit
 * never appeared.
 *
 * Route usage: ['auth.optional']
 *
 * A token that is present but INVALID is still rejected with 401 rather than
 * being downgraded to guest. Silently treating an expired session as anonymous
 * would show the customer an empty cart with no explanation; a 401 lets the
 * client refresh the token and retry, which is what it is built to do.
 *
 * All the actual verification rules live in AuthenticateMiddleware and are
 * delegated to, so there is exactly one implementation of "is this token good".
 */
final class OptionalAuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthenticateMiddleware $authenticate)
    {
    }

    public function handle(Request $request, callable $next, array $arguments = []): Response
    {
        if ($request->bearerToken() === null) {
            // A genuine anonymous caller. Continue with no auth context.
            return $next($request);
        }

        return $this->authenticate->handle($request, $next, $arguments);
    }
}
