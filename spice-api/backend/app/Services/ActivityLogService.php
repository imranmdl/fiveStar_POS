<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Request;
use App\Repositories\ActivityLogRepository;

final class ActivityLogService
{
    public function __construct(
        private readonly ActivityLogRepository $repository,
        private readonly Logger $logger,
    ) {
    }

    public function record(Request $request, int $statusCode, int $durationMs, ?string $error): void
    {
        $user = $request->attribute('auth_user');

        try {
            $this->repository->create([
                'user_id' => $user === null ? null : (int) $user['id'],
                'user_role' => $user === null ? null : (string) $user['role_code'],
                'module' => $this->moduleFromPath($request->path),
                'action' => $request->method . ' ' . $request->path,
                'http_method' => $request->method,
                'endpoint' => substr($request->path, 0, 255),
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip,
                'user_agent' => $request->userAgent,
                'request_id' => $request->requestId,
                'error_message' => $error === null ? null : substr($error, 0, 500),
            ], $user === null ? null : (int) $user['id']);
        } catch (\Throwable $exception) {
            // Logging must never break the request it is describing; fall back
            // to the file log so the event is still recoverable.
            $this->logger->error('Activity log write failed', [
                'reason' => $exception->getMessage(),
                'endpoint' => $request->path,
                'request_id' => $request->requestId,
            ], 'activity');
        }
    }

    private function moduleFromPath(string $path): string
    {
        // /api/v1/auth/login -> auth
        $segments = array_values(array_filter(explode('/', $path)));

        return $segments[2] ?? 'root';
    }
}
