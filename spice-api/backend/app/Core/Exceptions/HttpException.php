<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class HttpException extends \RuntimeException
{
    /** @param array<string, array<int, string>>|array<int, string> $errors */
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, array<int, string>>|array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
