<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php bin/migrate.php              Apply all pending migrations, then seeds.
 *   php bin/migrate.php --status     Show applied / pending migrations.
 *   php bin/migrate.php --seed-only  Re-run seeds (they are idempotent).
 *   php bin/migrate.php --rollback   Roll back the most recent migration.
 *
 * Statements are split on semicolons at end of line, which is compatible with
 * the migration style used in /database (no stored routines with embedded
 * semicolons; those must be applied with the mysql client directly).
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

/** @var \App\Core\Container $container */
$container = require APP_ROOT . '/bootstrap/container.php';
/** @var Database $db */
$db = $container->get(Database::class);
/** @var Config $config */
$config = $container->get(Config::class);

$projectRoot = dirname(APP_ROOT);
$migrationDir = $projectRoot . '/database/migrations';
$rollbackDir = $projectRoot . '/database/rollback';
$seedDir = $projectRoot . '/database/seeds';

$options = array_slice($argv, 1);

/**
 * Finds the next delimiter that is genuinely between statements.
 *
 * Skips anything inside '...', "..." or `...`, honouring backslash escapes and
 * SQL's doubled-quote escaping ('' inside a single-quoted string). Returns null
 * when the buffer holds no complete statement yet.
 */
function findDelimiter(string $buffer, string $delimiter, int $from = 0): ?int
{
    $length = strlen($buffer);
    $delimiterLength = strlen($delimiter);
    $quote = null;

    for ($i = $from; $i < $length; ++$i) {
        $char = $buffer[$i];

        if ($quote !== null) {
            if ($char === '\\') {
                ++$i;

                continue;
            }

            if ($char === $quote) {
                // A doubled quote is an escaped quote, not the end of the string.
                if ($i + 1 < $length && $buffer[$i + 1] === $quote) {
                    ++$i;

                    continue;
                }

                $quote = null;
            }

            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;

            continue;
        }

        if ($char === $delimiter[0] && substr($buffer, $i, $delimiterLength) === $delimiter) {
            return $i;
        }
    }

    return null;
}

function runSqlFile(Database $db, string $path): int
{
    $sql = file_get_contents($path);

    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }

    // Strip full-line comments so the splitter is not confused by them.
    $lines = array_filter(
        explode("\n", $sql),
        static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
    );

    // DELIMITER-aware, quote-aware splitting.
    //
    // Two things make naive splitting wrong, and the second is easy to miss:
    //
    //   1. A trigger or routine body contains semicolons of its own, so a
    //      statement split on ";" is cut in half. `DELIMITER $$` is how every
    //      SQL client signals this, and honouring it lets a migration use
    //      BEGIN ... END instead of contorting a guard onto one line.
    //
    //   2. Semicolons appear INSIDE string literals. This schema has
    //      COMMENT '1-6 digits; longest match wins', and splitting there
    //      produces two fragments that are both syntax errors. So the scanner
    //      tracks quoting and only treats a delimiter as a boundary when it is
    //      genuinely between statements.
    $statements = [];
    $buffer = '';
    $delimiter = ';';

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^DELIMITER\\s+(\\S+)$/i', $trimmed, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException(
                    'DELIMITER changed while a statement was still open in ' . basename($path)
                );
            }

            $delimiter = $match[1];

            continue;
        }

        $buffer .= $line . "\n";

        $offset = 0;

        while (($boundary = findDelimiter($buffer, $delimiter, $offset)) !== null) {
            $statement = trim(substr($buffer, 0, $boundary));
            $buffer = substr($buffer, $boundary + strlen($delimiter));
            $offset = 0;

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    $executed = 0;

    foreach ($statements as $statement) {
        $db->pdo()->exec(rtrim($statement, ";\n\r\t "));
        ++$executed;
    }

    return $executed;
}

function appliedMigrations(Database $db): array
{
    try {
        $rows = $db->select('SELECT migration FROM schema_migrations ORDER BY id');
    } catch (Throwable) {
        return [];
    }

    return array_column($rows, 'migration');
}

try {
    $db->pdo();
} catch (Throwable $exception) {
    fwrite(STDERR, "Database connection failed: {$exception->getMessage()}\n");
    fwrite(STDERR, "Run  php bin/setup.php  first. It checks the PHP extensions, creates .env,\ngenerates the secrets and names the exact cause when the database refuses.\n");
    exit(1);
}

$migrationFiles = glob($migrationDir . '/*.sql') ?: [];
sort($migrationFiles);

if (in_array('--status', $options, true)) {
    $applied = appliedMigrations($db);

    echo "Migration status\n----------------\n";

    foreach ($migrationFiles as $file) {
        $name = basename($file, '.sql');
        printf("[%s] %s\n", in_array($name, $applied, true) ? 'x' : ' ', $name);
    }

    exit(0);
}

if (in_array('--rollback', $options, true)) {
    $applied = appliedMigrations($db);

    if ($applied === []) {
        echo "Nothing to roll back.\n";
        exit(0);
    }

    $last = (string) end($applied);
    $file = $rollbackDir . '/' . $last . '_rollback.sql';

    if (!is_file($file)) {
        fwrite(STDERR, "No rollback script found for {$last} (expected {$file}).\n");
        exit(1);
    }

    printf("Rolling back %s ...\n", $last);
    runSqlFile($db, $file);
    printf("Rolled back %s.\n", $last);
    exit(0);
}

if (!in_array('--seed-only', $options, true)) {
    $applied = appliedMigrations($db);
    $pending = array_values(array_filter(
        $migrationFiles,
        static fn (string $file): bool => !in_array(basename($file, '.sql'), $applied, true)
    ));

    if ($pending === []) {
        echo "No pending migrations.\n";
    }

    foreach ($pending as $file) {
        $name = basename($file, '.sql');
        printf("Applying %s ... ", $name);
        $count = runSqlFile($db, $file);
        printf("%d statement(s) OK\n", $count);
    }
}

$seedFiles = glob($seedDir . '/*.sql') ?: [];
sort($seedFiles);

foreach ($seedFiles as $file) {
    printf("Seeding %s ... ", basename($file, '.sql'));
    $count = runSqlFile($db, $file);
    printf("%d statement(s) OK\n", $count);
}

printf("\nDone. Environment: %s\n", (string) $config->get('app.env'));
