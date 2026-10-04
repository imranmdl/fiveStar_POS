<?php

declare(strict_types=1);

/**
 * Clears rate-limit and login-attempt counters.
 *
 *   php bin/reset_rate_limits.php
 *
 * Why this exists: the smoke tests register throwaway customers, and
 * registration is throttled per IP address. Running two suites back to back from
 * the same machine legitimately trips the limiter, and the second suite then
 * fails for a reason that has nothing to do with the code under test.
 *
 * This is a development convenience only. It refuses to run outside a local or
 * testing environment, because clearing these counters in production would hand
 * an attacker a fresh allowance mid-attack.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$environment = (string) Env::get('APP_ENV', 'production');

if (!in_array($environment, ['local', 'testing'], true)) {
    fwrite(STDERR, sprintf(
        "Refusing to run: APP_ENV is '%s'.\n" .
        "Clearing rate limits in production would reset an attacker's allowance.\n",
        $environment
    ));
    exit(1);
}

// Database takes the resolved database config array, not the Config object.
$config = new Config(APP_ROOT . '/config');
$database = new Database((array) $config->get('database'));

$rateLimits = $database->execute('DELETE FROM `rate_limits`');
$loginAttempts = $database->execute('DELETE FROM `login_attempts`');

printf(
    "Cleared %d rate-limit row(s) and %d login-attempt row(s). Environment: %s\n",
    $rateLimits,
    $loginAttempts,
    $environment
);
