<?php

declare(strict_types=1);

/**
 * Single entry point for the REST API.
 *
 * Apache rewrites every request here (see public/.htaccess). No other PHP file
 * is web-reachable, which is why the application code lives outside public/.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\TooManyRequestsException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Env::load(APP_ROOT . '/.env');

$appEnv = Env::get('APP_ENV', 'production');
$debug = Env::bool('APP_DEBUG', false) && $appEnv !== 'production';

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Kolkata'));
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** @var \App\Core\Container $container */
$container = require APP_ROOT . '/bootstrap/container.php';

/** @var Logger $logger */
$logger = $container->get(Logger::class);
/** @var Config $config */
$config = $container->get(Config::class);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$request = Request::fromGlobals();

// ---------------------------------------------------------------------------
// CORS. Origins are whitelisted explicitly; the Flutter app sends no Origin
// header and is unaffected.
// ---------------------------------------------------------------------------
$origin = $request->header('origin');
$allowedOrigins = (array) $config->get('app.cors.allowed_origins', []);

if ($origin !== null && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: ' . implode(', ', (array) $config->get('app.cors.allowed_methods', [])));
    header('Access-Control-Allow-Headers: ' . implode(', ', (array) $config->get('app.cors.allowed_headers', [])));
    header('Access-Control-Max-Age: ' . (string) $config->get('app.cors.max_age', 3600));
}

if ($request->method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('X-Request-Id: ' . $request->requestId);

try {
    $router = new Router($container);
    $registerRoutes = require APP_ROOT . '/routes/api_v1.php';
    $registerRoutes($router);

    $router->dispatch($request)
        ->withHeader('X-Request-Id', $request->requestId)
        ->send();
} catch (TooManyRequestsException $exception) {
    Response::error($exception->getMessage(), 429)
        ->withHeader('Retry-After', (string) $exception->retryAfterSeconds)
        ->withHeader('X-Request-Id', $request->requestId)
        ->send();
} catch (HttpException $exception) {
    // Expected, business-level failures: no stack trace, no error log noise.
    Response::error($exception->getMessage(), $exception->statusCode(), $exception->errors())
        ->withHeader('X-Request-Id', $request->requestId)
        ->send();
} catch (Throwable $exception) {
    $logger->exception($exception, [
        'request_id' => $request->requestId,
        'method' => $request->method,
        'path' => $request->path,
        'ip' => $request->ip,
    ]);

    // Internal details are never leaked to clients in production; the request
    // id is the key support uses to find the entry in storage/logs.
    $errors = $debug
        ? [
            'exception' => [$exception::class],
            'message' => [$exception->getMessage()],
            'location' => [$exception->getFile() . ':' . $exception->getLine()],
        ]
        : [];

    Response::error(
        'An unexpected error occurred. Please quote request id ' . $request->requestId . ' when contacting support.',
        500,
        $errors
    )->withHeader('X-Request-Id', $request->requestId)->send();
}
