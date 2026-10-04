<?php

declare(strict_types=1);

/**
 * Scheduler entry point.
 *
 *   * * * * * cd /path/to/backend && php bin/scheduler.php >> storage/logs/scheduler.log 2>&1
 *
 * ONE crontab line, called every minute. Which tasks are actually due is
 * decided in the database, so a schedule change is a settings change rather
 * than a server change, and every run is recorded.
 *
 * Safe to run on several application servers at once: each task is claimed with
 * a conditional UPDATE, so exactly one runner executes it.
 *
 *   php bin/scheduler.php                       run everything due
 *   php bin/scheduler.php --task=orders.expire_unpaid   run one task now
 *   php bin/scheduler.php --list                show the schedule
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Env;
use App\Core\Request;
use App\Services\SchedulerService;

// The environment must be loaded BEFORE the container is built. Several
// services refuse to construct outside local or testing — the sandbox payment
// gateway among them — and without .env the environment reads as "production",
// so the scheduler dies at boot with a message about going live.
Env::load(APP_ROOT . '/.env');

$container = require APP_ROOT . '/bootstrap/container.php';

/** @var SchedulerService $scheduler */
$scheduler = $container->get(SchedulerService::class);

$options = getopt('', ['task::', 'list']);

if (isset($options['list'])) {
    printf("%-30s %-9s %-20s %-9s %s\n", 'TASK', 'EVERY', 'NEXT RUN', 'LAST', 'SUMMARY');

    foreach ($scheduler->tasks() as $task) {
        printf(
            "%-30s %-9s %-20s %-9s %s\n",
            $task['code'],
            $task['interval_minutes'] . 'm',
            (string) ($task['next_run_date'] ?? '-'),
            (string) ($task['last_run_status'] ?? '-'),
            substr((string) ($task['last_run_summary'] ?? ''), 0, 60)
        );
    }

    exit(0);
}

// A synthetic request: scheduled work has no HTTP caller, and the services it
// drives expect one for audit attribution. A null actor is correct here — the
// system did it, not a person.
$request = new Request(
    method: 'CLI',
    path: '/scheduler',
    headers: [],
    query: [],
    body: [],
    files: [],
    ip: '127.0.0.1',
    userAgent: 'scheduler',
    requestId: bin2hex(random_bytes(8)),
);

$only = isset($options['task']) && $options['task'] !== false ? (string) $options['task'] : null;

try {
    $result = $scheduler->run($request, $only);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[%s] Scheduler failed: %s\n", date('c'), $exception->getMessage()));

    exit(1);
}

foreach ($result['results'] as $task) {
    printf(
        "[%s] %-30s %-8s %s\n",
        date('c'),
        $task['code'],
        $task['status'],
        $task['summary']
    );
}

if ($result['results'] === []) {
    printf("[%s] Nothing due.\n", date('c'));
}

$failures = count(array_filter(
    $result['results'],
    static fn (array $r): bool => $r['status'] === 'failed'
));

// A non-zero exit lets cron mail the operator, which is the only alerting a
// small merchant is likely to have on day one.
exit($failures > 0 ? 1 : 0);
