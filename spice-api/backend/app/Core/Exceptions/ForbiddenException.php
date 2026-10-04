<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'You do not have permission to perform this action')
    {
        parent::__construct($message, 403);
    }
}
