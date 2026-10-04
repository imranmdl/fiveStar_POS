<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RateLimitRepository;

final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repository)
    {
    }

    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public function hit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $result = $this->repository->hit(hash('sha256', $key), $windowSeconds);
        $retryAfter = max(1, strtotime($result['window_expires_date']) - time());

        return [
            'allowed' => $result['hits'] <= $maxAttempts,
            'remaining' => max(0, $maxAttempts - $result['hits']),
            'retry_after' => $retryAfter,
        ];
    }
}
