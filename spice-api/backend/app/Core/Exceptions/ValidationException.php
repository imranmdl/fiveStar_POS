<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422, $errors);
    }
}
