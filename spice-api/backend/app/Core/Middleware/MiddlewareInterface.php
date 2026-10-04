<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     * @param array<int, string>          $arguments Parameters from the route
     *                                               definition, e.g. `role:admin,supervisor`.
     */
    public function handle(Request $request, callable $next, array $arguments = []): Response;
}
