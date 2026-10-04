<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class TooManyRequestsException extends HttpException
{
    public function __construct(
        string $message = 'Too many requests. Please try again later.',
        public readonly int $retryAfterSeconds = 60,
    ) {
        parent::__construct($message, 429);
    }
}
