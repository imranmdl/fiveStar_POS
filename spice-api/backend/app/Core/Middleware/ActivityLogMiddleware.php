<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogService;

/**
 * BR-009: every activity must be logged. Applied to the whole API group so
 * no endpoint can silently skip it.
 */
final class ActivityLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    public function handle(Request $request, callable $next, array $arguments = []): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
            $this->record($request, $response->status(), $startedAt, null);

            return $response;
        } catch (\Throwable $exception) {
            $status = $exception instanceof \App\Core\Exceptions\HttpException
                ? $exception->statusCode()
                : 500;

            $this->record($request, $status, $startedAt, $exception->getMessage());

            throw $exception;
        }
    }

    private function record(Request $request, int $status, float $startedAt, ?string $error): void
    {
        $this->activityLog->record(
            request: $request,
            statusCode: $status,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            error: $error,
        );
    }
}
