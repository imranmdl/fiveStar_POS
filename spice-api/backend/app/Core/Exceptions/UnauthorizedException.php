<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message, 401);
    }
}
