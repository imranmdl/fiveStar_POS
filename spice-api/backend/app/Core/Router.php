<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Middleware\MiddlewareInterface;

final class Router
{
    /** @var array<int, array{method:string,pattern:string,params:array<int,string>,action:array{0:class-string,1:string},middleware:array<int,string>}> */
    private array $routes = [];

    /** @var array<string, class-string<MiddlewareInterface>> */
    private array $middlewareAliases = [];

    /** @var array<int, string> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function __construct(private readonly Container $container)
    {
    }

    /** @param array<string, class-string<MiddlewareInterface>> $aliases */
    public function registerMiddleware(array $aliases): void
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
    }

    /** @param array<int, string> $middleware */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    public function get(string $path, array $action, array $middleware = []): void
    {
        $this->add('GET', $path, $action, $middleware);
    }

    public function post(string $path, array $action, array $middleware = []): void
    {
        $this->add('POST', $path, $action, $middleware);
    }

    public function put(string $path, array $action, array $middleware = []): void
    {
        $this->add('PUT', $path, $action, $middleware);
    }

    public function patch(string $path, array $action, array $middleware = []): void
    {
        $this->add('PATCH', $path, $action, $middleware);
    }

    public function delete(string $path, array $action, array $middleware = []): void
    {
        $this->add('DELETE', $path, $action, $middleware);
    }

    private function add(string $method, string $path, array $action, array $middleware): void
    {
        $fullPath = rtrim($this->groupPrefix . $path, '/');
        $fullPath = $fullPath === '' ? '/' : $fullPath;

        $params = [];
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$params): string {
                $params[] = $matches[1];

                return '([^\/]+)';
            },
            $fullPath
        );

        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'params' => $params,
            'action' => $action,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $request->path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            array_shift($matches);
            $request->setAttribute('route_params', array_combine($route['params'], $matches));
            $request->setAttribute('route_name', $route['method'] . ' ' . $request->path);

            return $this->runPipeline($request, $route);
        }

        if ($pathMatched) {
            throw new HttpException('HTTP method ' . $request->method . ' is not allowed for this endpoint', 405);
        }

        throw new NotFoundException('The requested endpoint does not exist');
    }

    private function runPipeline(Request $request, array $route): Response
    {
        $handler = function (Request $request) use ($route): Response {
            [$class, $method] = $route['action'];
            $controller = $this->container->get($class);

            return $controller->{$method}($request);
        };

        foreach (array_reverse($route['middleware']) as $definition) {
            $handler = function (Request $request) use ($definition, $handler): Response {
                [$alias, $argumentString] = array_pad(explode(':', $definition, 2), 2, null);

                if (!isset($this->middlewareAliases[$alias])) {
                    throw new \RuntimeException("Unregistered middleware alias: {$alias}");
                }

                $middleware = $this->container->get($this->middlewareAliases[$alias]);
                $arguments = $argumentString === null ? [] : explode(',', $argumentString);

                return $middleware->handle($request, $handler, $arguments);
            };
        }

        return $handler($request);
    }
}
