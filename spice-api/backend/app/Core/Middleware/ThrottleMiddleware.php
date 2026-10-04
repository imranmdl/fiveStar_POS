<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\TooManyRequestsException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;

/**
 * Route usage: ['throttle:5,60'] — at most 5 requests per 60 seconds,
 * keyed by client IP + route.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next, array $arguments = []): Response
    {
        $maxAttempts = (int) ($arguments[0] ?? 60);
        $windowSeconds = (int) ($arguments[1] ?? 60);

        $key = sprintf('route:%s|%s|ip:%s', $request->method, $request->path, $request->ip);
        $result = $this->limiter->hit($key, $maxAttempts, $windowSeconds);

        if (!$result['allowed']) {
            throw new TooManyRequestsException(
                sprintf('Too many attempts. Please try again in %d seconds.', $result['retry_after']),
                $result['retry_after']
            );
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }
}
