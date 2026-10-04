<?php

declare(strict_types=1);

/**
 * Static consistency audit.
 *
 *   php bin/audit_consistency.php
 *
 * Runs the structural checks that no unit test can: things that are only wrong
 * when two files disagree with each other, or when the schema breaks a MySQL
 * rule that is not obvious from reading one table.
 *
 * Every check here exists because it caught a real bug. Four of them were found
 * the hard way during Phase 4 — a migration that would not apply, and writes
 * that were silently discarded — which is exactly the kind of failure that
 * survives code review and then wastes an afternoon.
 *
 * Needs no database and no web server. Run it before every commit.
 *
 * Exit code 0 = clean, 1 = at least one error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

$projectRoot = dirname(APP_ROOT);
$backend = APP_ROOT;
$migrationDir = $projectRoot . '/database/migrations';
$rollbackDir = $projectRoot . '/database/rollback';

$errors = [];
$warnings = [];
$notes = [];

/** @return array<int, string> */
function phpFiles(string $directory): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/**
 * Whether a route's middleware argument authenticates the caller.
 *
 * Handles both a literal list and a group variable, resolving the variable
 * against its definition rather than against a hardcoded list of names.
 *
 * @param array<string, string> $groups
 */
function isAuthenticatedMiddleware(string $middleware, array $groups): bool
{
    if (str_contains($middleware, "'auth'") || str_contains($middleware, "'auth.optional'")) {
        return true;
    }

    foreach ($groups as $variable => $definition) {
        if (str_contains($middleware, $variable)
            && (str_contains($definition, "'auth'") || str_contains($definition, "'auth.optional'"))) {
            return true;
        }
    }

    return false;
}

function relative(string $path): string
{
    return str_replace(APP_ROOT . '/', '', $path);
}

$sourceFiles = phpFiles($backend . '/app');
$allFiles = array_merge($sourceFiles, phpFiles($backend . '/routes'), phpFiles($backend . '/bootstrap'));

$migrationSql = '';
$migrationFiles = glob($migrationDir . '/*.sql') ?: [];
sort($migrationFiles);

foreach ($migrationFiles as $file) {
    $migrationSql .= file_get_contents($file) . "\n";
}

echo "Consistency audit\n=================\n\n";

// ---------------------------------------------------------------------------
// 1. Repeated named placeholders in a single statement.
//
// PDO with ATTR_EMULATE_PREPARES => false rejects the same named parameter used
// twice in one statement (HY093). It looks harmless and fails only at runtime.
// ---------------------------------------------------------------------------
foreach ($allFiles as $file) {
    $contents = file_get_contents($file);

    // Both quote styles must be scanned. Queries containing a literal such as
    // 'active' are written with double quotes, and those are the majority here —
    // scanning only single-quoted strings leaves most of the SQL unchecked.
    $literals = [];

    foreach (["/'((?:[^'\\\\]|\\\\.)*)'/s", '/"((?:[^"\\\\]|\\\\.)*)"/s'] as $pattern) {
        if (preg_match_all($pattern, $contents, $found, PREG_OFFSET_CAPTURE)) {
            $literals = array_merge($literals, $found[1]);
        }
    }

    {
        foreach ($literals as $match) {
            $sql = $match[0];

            if (!preg_match('/\b(SELECT|UPDATE|INSERT INTO|DELETE FROM)\b/', $sql)) {
                continue;
            }

            preg_match_all('/:([a-z_][a-z0-9_]*)/', $sql, $names);
            $counts = array_count_values($names[1]);
            $repeated = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));

            if ($repeated !== []) {
                $line = substr_count(substr($contents, 0, $match[1]), "\n") + 1;
                $errors[] = sprintf(
                    'Repeated SQL placeholder %s in %s:%d — PDO rejects this when prepare emulation is off.',
                    implode(', ', $repeated),
                    relative($file),
                    $line
                );
            }
        }
    }
}

echo "1. Repeated SQL placeholders ... checked\n";

// ---------------------------------------------------------------------------
// 1b. Placeholders with no matching binding.
//
// The same failure class as a repeated placeholder — PDO throws HY093 "Invalid
// parameter number" only when the statement actually runs, which for an
// INSERT on a rarely-used path can be a long time after the typo.
//
// Only checked where the bindings are a literal array in the same call. Where
// they are built up in a variable the association cannot be made statically,
// and guessing would produce false positives.
// ---------------------------------------------------------------------------
$bindingChecks = 0;

foreach ($allFiles as $file) {
    $contents = file_get_contents($file);

    if (!preg_match_all(
        '/->(?:insert|execute|select|selectOne|scalar)\(\s*(["\'])(.*?)\1\s*,\s*\[(.*?)\n(\s*)\]\s*\)/s',
        $contents,
        $calls,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        continue;
    }

    foreach ($calls as $call) {
        $sql = $call[2][0];
        $bindingBlock = $call[3][0];

        if (!preg_match('/\b(SELECT|UPDATE|INSERT INTO|DELETE FROM)\b/', $sql)) {
            continue;
        }

        ++$bindingChecks;

        preg_match_all('/:([a-z_][a-z0-9_]*)/', $sql, $placeholders);
        preg_match_all("/'(\w+)'\s*=>/", $bindingBlock, $bound);

        $missing = array_unique(array_diff($placeholders[1], $bound[1]));

        foreach ($missing as $name) {
            $line = substr_count(substr($contents, 0, $call[0][1]), "\n") + 1;
            $errors[] = sprintf(
                'SQL placeholder :%s in %s:%d has no matching binding — PDO will reject this with HY093.',
                $name,
                relative($file),
                $line
            );
        }
    }
}

printf("1b. Placeholders without bindings ... %d statement(s) checked\n", $bindingChecks);

// ---------------------------------------------------------------------------
// 1d. Validation rules must exist.
//
// Validator::make() throws on an unknown rule, but only when that endpoint is
// actually exercised — so `integer` instead of `int` in one admin controller
// sails past every other test and 500s the first time someone uses the feature.
// ---------------------------------------------------------------------------
$knownRules = [];

if (preg_match_all("/case '([a-z_]+)':/", file_get_contents($backend . '/app/Core/Validator.php'), $ruleCases)) {
    $knownRules = $ruleCases[1];
}

$ruleChecks = 0;

if ($knownRules !== []) {
    foreach ($allFiles as $file) {
        $contents = file_get_contents($file);

        if (!preg_match_all("/'((?:required|nullable|sometimes)\\|[a-z_0-9:,|\\-]+)'/", $contents, $rulesets, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($rulesets[1] as $ruleset) {
            ++$ruleChecks;

            foreach (explode('|', $ruleset[0]) as $rule) {
                $name = explode(':', $rule)[0];

                if ($name === '' || in_array($name, ['required', 'nullable', 'sometimes'], true)) {
                    continue;
                }

                if (!in_array($name, $knownRules, true)) {
                    $line = substr_count(substr($contents, 0, $ruleset[1]), "\n") + 1;
                    $errors[] = sprintf(
                        'Unknown validation rule "%s" in %s:%d — Validator will throw when this endpoint is used.',
                        $name,
                        relative($file),
                        $line
                    );
                }
            }
        }
    }
}

printf("1d. Validation rules ... %d ruleset(s) checked against %d known rules\n", $ruleChecks, count($knownRules));

// ---------------------------------------------------------------------------
// 1c. Every file must actually parse.
//
// A class that is only constructed under one configuration — an alternative
// payment gateway or courier adapter — is never loaded by tests running under
// another, so a syntax error in it survives every green test run and appears
// for the first time in production on the day the driver is switched.
// ---------------------------------------------------------------------------
$lintErrors = 0;

foreach ($allFiles as $file) {
    $output = [];
    $exitCode = 0;
    exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exitCode);

    if ($exitCode !== 0) {
        ++$lintErrors;
        $errors[] = sprintf('%s does not parse: %s', relative($file), trim($output[0] ?? 'unknown error'));
    }
}

printf("1c. Syntax ... %d file(s) parsed, %d failed\n", count($allFiles), $lintErrors);

// ---------------------------------------------------------------------------
// 2. Every App\ class referenced must exist.
// ---------------------------------------------------------------------------
$defined = [];

foreach ($sourceFiles as $file) {
    $contents = file_get_contents($file);

    if (preg_match('/^namespace\s+([^;]+);/m', $contents, $ns)
        && preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $contents, $cl)) {
        $defined[trim($ns[1]) . '\\' . $cl[1]] = true;
    }
}

foreach ($allFiles as $file) {
    $contents = file_get_contents($file);

    preg_match_all('/^use\s+(App\\\\[^;\s]+)\s*;/m', $contents, $imports);
    preg_match_all('/\\\\(App\\\\[A-Za-z0-9_\\\\]+)(?:::|\()/', $contents, $qualified);

    foreach (array_merge($imports[1], $qualified[1]) as $class) {
        if (!isset($defined[$class])) {
            $errors[] = sprintf('Unresolved class %s referenced in %s.', $class, relative($file));
        }
    }
}

printf("2. Class references ... %d classes defined\n", count($defined));

// ---------------------------------------------------------------------------
// 3. Every table and view used in PHP must exist in a migration.
// ---------------------------------------------------------------------------
preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`(\w+)`/', $migrationSql, $tableMatches);
preg_match_all('/CREATE OR REPLACE VIEW `(\w+)`/', $migrationSql, $viewMatches);

$tables = array_unique($tableMatches[1]);
$views = array_unique($viewMatches[1]);

// `orders` arrives in Phase 5; CouponService probes for it deliberately.
$known = array_merge($tables, $views, ['information_schema', 'tables', 'orders']);
$referenced = [];

foreach ($allFiles as $file) {
    $contents = file_get_contents($file);
    // `ON DUPLICATE KEY UPDATE `col` = ...` is not a table reference, so UPDATE
    // is only matched when it starts a statement rather than following KEY.
    preg_match_all('/(?<!KEY )(?:FROM|JOIN|UPDATE|INSERT INTO)\s+`(\w+)`/', $contents, $uses);
    $referenced = array_merge($referenced, $uses[1]);
}

foreach (array_unique(array_diff($referenced, $known)) as $missing) {
    $errors[] = sprintf('Table or view `%s` is used in PHP but never created by a migration.', $missing);
}

printf("3. Schema objects ... %d tables, %d views\n", count($tables), count($views));

// ---------------------------------------------------------------------------
// 4. Repository table() targets must exist.
// ---------------------------------------------------------------------------
$repositories = [];

foreach (glob($backend . '/app/Repositories/*.php') ?: [] as $file) {
    $contents = file_get_contents($file);

    if (!preg_match("/function table\(\): string\s*\{\s*return '(\w+)';/", $contents, $target)) {
        continue;
    }

    $fillable = [];

    if (preg_match('/function fillable\(\): array\s*\{\s*return \[(.*?)\];/s', $contents, $block)) {
        preg_match_all("/'(\w+)'/", $block[1], $columns);
        $fillable = $columns[1];
    }

    $repositories[$target[1]] = ['file' => basename($file), 'fillable' => $fillable];

    if (!in_array($target[1], $tables, true)) {
        $errors[] = sprintf('%s targets table `%s`, which no migration creates.', basename($file), $target[1]);
    }
}

printf("4. Repository targets ... %d repositories\n", count($repositories));

// ---------------------------------------------------------------------------
// 5. MASS-ASSIGNMENT GAPS.
//
// A column added by a later ALTER TABLE but missing from the owning
// repository's fillable() is silently discarded on every write, with no error
// anywhere. This is how coupon application and wallet redemption failed in
// Phase 4: the columns existed, the code set them, and nothing was stored.
// ---------------------------------------------------------------------------
preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $migrationSql, $alters, PREG_SET_ORDER);
$alterChecked = 0;

foreach ($alters as $alter) {
    $table = $alter[1];
    preg_match_all('/ADD COLUMN `(\w+)`/', $alter[2], $columns);

    if ($columns[1] === []) {
        continue;
    }

    if (!isset($repositories[$table])) {
        $notes[] = sprintf(
            'No repository owns `%s`; its added columns (%s) are unchecked.',
            $table,
            implode(', ', $columns[1])
        );

        continue;
    }

    ++$alterChecked;
    $missing = array_diff($columns[1], $repositories[$table]['fillable']);

    foreach ($missing as $column) {
        $errors[] = sprintf(
            'MASS-ASSIGNMENT GAP: `%s`.`%s` was added by a migration but is not in %s::fillable(). '
            . 'Writes to it will be silently discarded.',
            $table,
            $column,
            $repositories[$table]['file']
        );
    }
}

printf("5. Mass-assignment gaps ... %d altered table(s) checked\n", $alterChecked);

// ---------------------------------------------------------------------------
// 6. MySQL rule: a column in a CHECK constraint cannot be modified by a
//    referential action (error 3823).
//
// 7. MySQL rule: a foreign key on the base column of a STORED generated column
//    cannot use CASCADE, SET NULL or SET DEFAULT.
//
// Both make CREATE TABLE fail outright, so the migration never applies. Cheaper
// to find here than at the start of a deployment.
// ---------------------------------------------------------------------------
preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $migrationSql, $definitions, PREG_SET_ORDER);
$checkedTables = 0;

foreach ($definitions as $definition) {
    [$whole, $table, $body] = $definition;
    ++$checkedTables;

    $checkColumns = [];

    if (preg_match_all('/CONSTRAINT `\w+`\s*\n?\s*CHECK \((.*?)\),?\n/s', $body, $checks)) {
        foreach ($checks[1] as $expression) {
            preg_match_all('/`(\w+)`/', $expression, $columns);
            $checkColumns = array_merge($checkColumns, $columns[1]);
        }
    }

    $generatedBase = [];

    if (preg_match_all('/GENERATED ALWAYS AS \((.*?)\) STORED/s', $body, $generated)) {
        foreach ($generated[1] as $expression) {
            preg_match_all('/`(\w+)`/', $expression, $columns);
            $generatedBase = array_merge($generatedBase, $columns[1]);
        }
    }

    if ($checkColumns === [] && $generatedBase === []) {
        continue;
    }

    preg_match_all(
        '/CONSTRAINT `(\w+)`\s*\n\s*FOREIGN KEY \(`(\w+)`\) REFERENCES[^\n]*\n\s*'
        . '((?:ON (?:UPDATE|DELETE) (?:CASCADE|RESTRICT|NO ACTION|SET NULL|SET DEFAULT)\s*)+)/',
        $body,
        $foreignKeys,
        PREG_SET_ORDER
    );

    foreach ($foreignKeys as $fk) {
        [$all, $name, $column, $actions] = $fk;
        $actions = trim(preg_replace('/\s+/', ' ', $actions) ?? '');

        $modifiesOnUpdate = str_contains($actions, 'ON UPDATE CASCADE')
            || str_contains($actions, 'ON UPDATE SET NULL');
        $modifiesOnDelete = str_contains($actions, 'ON DELETE SET NULL');

        if (in_array($column, $checkColumns, true) && ($modifiesOnUpdate || $modifiesOnDelete)) {
            $errors[] = sprintf(
                'MySQL 3823: `%s`.`%s` is used in a CHECK constraint but %s applies "%s". '
                . 'Use RESTRICT — the CREATE TABLE will be rejected.',
                $table,
                $column,
                $name,
                $actions
            );
        }

        $forbiddenForGenerated = str_contains($actions, 'CASCADE')
            || str_contains($actions, 'SET NULL')
            || str_contains($actions, 'SET DEFAULT');

        if (in_array($column, $generatedBase, true) && $forbiddenForGenerated) {
            $errors[] = sprintf(
                'MySQL: `%s`.`%s` is the base of a STORED generated column, but %s applies "%s". '
                . 'CASCADE, SET NULL and SET DEFAULT are all forbidden there.',
                $table,
                $column,
                $name,
                $actions
            );
        }
    }
}

printf("6/7. MySQL schema rules ... %d table definition(s) checked\n", $checkedTables);

// ---------------------------------------------------------------------------
// 8. Every route action must exist.
// ---------------------------------------------------------------------------
$routeFile = $backend . '/routes/api_v1.php';
$routeSource = file_get_contents($routeFile);

preg_match_all(
    '/\$router->(get|post|put|patch|delete)\(\s*\'([^\']+)\'\s*,\s*\[(\w+)::class,\s*\'(\w+)\'\](?:\s*,\s*([^)]*))?\)/s',
    $routeSource,
    $routes,
    PREG_SET_ORDER
);

$controllers = [];

foreach (phpFiles($backend . '/app/Controllers') as $file) {
    $controllers[pathinfo($file, PATHINFO_FILENAME)] = file_get_contents($file);
}

foreach ($routes as $route) {
    [$all, $verb, $path, $controller, $action] = $route;

    if (!isset($controllers[$controller])) {
        $errors[] = sprintf('Route %s %s references unknown controller %s.', strtoupper($verb), $path, $controller);

        continue;
    }

    if (!str_contains($controllers[$controller], 'function ' . $action . '(')) {
        $errors[] = sprintf('Route %s %s references missing action %s::%s().', strtoupper($verb), $path, $controller, $action);
    }
}

printf("8. Route actions ... %d routes\n", count($routes));

// ---------------------------------------------------------------------------
// 9. AUTH CONTEXT ON PUBLIC ROUTES.
//
// A route with no authentication middleware never populates the auth context,
// so Request::authUserId() returns null even when the caller sent a valid
// token. In Phase 4 this silently downgraded every signed-in customer to a
// guest on the cart routes: coupons were refused and wallet credit vanished.
//
// Detected with a real call graph rather than "does this file mention auth":
// methods that read authUserId() are marked, then the marking propagates
// backwards through $this->method() and $this->service->method() calls until it
// stabilises. A route is flagged only when its own action transitively depends
// on knowing who the caller is.
//
// Audit and logging services are excluded from propagation on purpose. They
// read authUserId() purely to attribute a record, and a null there is correct
// for a genuine guest — it changes nothing about behaviour. Including them
// would flag every public route in the application.
// ---------------------------------------------------------------------------
$attributionOnly = ['AuditService', 'ActivityLogMiddleware', 'Logger'];

// Routes that are anonymous ON PURPOSE, with the reason recorded.
//
// An allowlist rather than silence: adding a route here is a decision someone
// made and wrote down, which is the difference between an accepted risk and an
// overlooked one. Anything NOT listed still raises a warning.
$intentionallyAnonymous = [
    'POST /webhooks/tracking' =>
        'A courier carries no bearer token. The signature is the authentication. '
        . 'Closing an assignment and accruing commission are system actions here, '
        . 'so a null actor is correct — the work is attributed to the assignment, '
        . 'not to the caller.',
    'POST /webhooks/payment' =>
        'A payment gateway carries no bearer token. The webhook signature is the '
        . 'authentication, and authUserId() being null is correct — the payment is '
        . 'attributed to the order, not to the caller.',
];

$classMethods = [];
$propertyTypes = [];
$marked = [];

foreach ($sourceFiles as $file) {
    $shortName = pathinfo($file, PATHINFO_FILENAME);

    if (in_array($shortName, $attributionOnly, true)) {
        continue;
    }

    $contents = file_get_contents($file);

    preg_match_all('/private readonly (\w+)\s+\$(\w+)/', $contents, $properties, PREG_SET_ORDER);

    foreach ($properties as $property) {
        $propertyTypes[$shortName][$property[2]] = $property[1];
    }

    // Split on method signatures, then take each body up to the next one.
    preg_match_all(
        '/(?:public|private|protected)(?: static)? function (\w+)\s*\(/',
        $contents,
        $signatures,
        PREG_OFFSET_CAPTURE
    );

    $count = count($signatures[1]);

    for ($i = 0; $i < $count; ++$i) {
        $name = $signatures[1][$i][0];
        $from = $signatures[0][$i][1];
        $to = $i + 1 < $count ? $signatures[0][$i + 1][1] : strlen($contents);
        $body = substr($contents, $from, $to - $from);

        $classMethods[$shortName][$name] = $body;

        if (str_contains($body, 'authUserId()')) {
            $marked[$shortName][$name] = true;
        }
    }
}

// Propagate backwards to a fixed point.
for ($pass = 0; $pass < 12; ++$pass) {
    $changed = false;

    foreach ($classMethods as $class => $methods) {
        foreach ($methods as $method => $body) {
            if (isset($marked[$class][$method])) {
                continue;
            }

            preg_match_all('/\$this->(\w+)\(/', $body, $ownCalls);

            foreach ($ownCalls[1] as $called) {
                if (isset($marked[$class][$called])) {
                    $marked[$class][$method] = true;
                    $changed = true;

                    continue 2;
                }
            }

            preg_match_all('/\$this->(\w+)->(\w+)\(/', $body, $serviceCalls, PREG_SET_ORDER);

            foreach ($serviceCalls as $call) {
                $type = $propertyTypes[$class][$call[1]] ?? null;

                if ($type !== null && isset($marked[$type][$call[2]])) {
                    $marked[$class][$method] = true;
                    $changed = true;

                    continue 2;
                }
            }
        }
    }

    if (!$changed) {
        break;
    }
}

// Middleware group variables, resolved from their own definitions.
$middlewareGroups = [];

if (preg_match_all('/\$(\w+)\s*=\s*\[([^\]]*)\];/', $routeSource, $groupDefinitions, PREG_SET_ORDER)) {
    foreach ($groupDefinitions as $definition) {
        $middlewareGroups['$' . $definition[1]] = $definition[2];
    }
}

$publicRoutesChecked = 0;

foreach ($routes as $route) {
    [$all, $verb, $path, $controller, $action] = $route;
    $middleware = $route[5] ?? '';

    if (isAuthenticatedMiddleware($middleware, $middlewareGroups)) {
        continue;
    }

    ++$publicRoutesChecked;

    $routeKey = strtoupper($verb) . ' ' . $path;

    if (isset($intentionallyAnonymous[$routeKey])) {
        $notes[] = sprintf('%s is intentionally anonymous: %s', $routeKey, $intentionallyAnonymous[$routeKey]);

        continue;
    }

    if (isset($marked[$controller][$action])) {
        $warnings[] = sprintf(
            '%s %s has no auth middleware, but %s::%s() depends on authUserId(). '
            . "A signed-in caller will be treated as anonymous — add 'auth.optional'.",
            strtoupper($verb),
            $path,
            $controller,
            $action
        );
    }
}

printf("9. Auth context on public routes ... %d public route(s) checked\n", $publicRoutesChecked);

// ---------------------------------------------------------------------------
// 10. Every migration needs a rollback that undoes what it creates.
// ---------------------------------------------------------------------------
foreach ($migrationFiles as $file) {
    $name = pathinfo($file, PATHINFO_FILENAME);
    $rollbackFile = $rollbackDir . '/' . $name . '_rollback.sql';

    if (!is_file($rollbackFile)) {
        $errors[] = sprintf('Migration %s has no rollback script.', $name);

        continue;
    }

    $migration = file_get_contents($file);
    $rollback = file_get_contents($rollbackFile);

    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`(\w+)`/', $migration, $created);
    preg_match_all('/DROP TABLE IF EXISTS `(\w+)`/', $rollback, $dropped);

    foreach (array_diff($created[1], $dropped[1], ['schema_migrations']) as $table) {
        $errors[] = sprintf('%s creates table `%s` but its rollback never drops it.', $name, $table);
    }

    preg_match_all('/CREATE TRIGGER `(\w+)`/', $migration, $triggers);
    preg_match_all('/DROP TRIGGER IF EXISTS `(\w+)`/', $rollback, $droppedTriggers);

    foreach (array_diff($triggers[1], $droppedTriggers[1]) as $trigger) {
        $errors[] = sprintf('%s creates trigger `%s` but its rollback never drops it.', $name, $trigger);
    }
}

printf("10. Migration/rollback symmetry ... %d migration(s)\n", count($migrationFiles));

// ---------------------------------------------------------------------------
// 11. The OpenAPI specification must match the code.
//
// The spec is generated, so it cannot describe an endpoint that does not exist
// — but the COMMITTED copy can still fall behind. Regenerating and comparing
// means a route added without regenerating is caught here rather than by an
// Android developer building against a document that is a fortnight old.
// ---------------------------------------------------------------------------
$specOutput = [];
$specExit = 0;
exec(
    sprintf('%s %s --check 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($backend . '/bin/generate_openapi.php')),
    $specOutput,
    $specExit
);

if ($specExit !== 0) {
    $errors[] = 'The committed OpenAPI specification is out of date. Run: php bin/generate_openapi.php';
}

printf("11. OpenAPI specification ... %s\n", $specExit === 0 ? 'current' : 'STALE');

// ---------------------------------------------------------------------------
// 12. Deployment rules in .htaccess.
//
// Every one of these was found by a user deploying to XAMPP, not by any test
// here — the suite always ran against a document root, so the subdirectory path
// was never exercised. They fail as 403 or 404 on EVERY endpoint, which looks
// like a broken application rather than a configuration detail.
// ---------------------------------------------------------------------------
/** Strips comment lines, so an explanation of a rule is not read as the rule. */
$activeDirectives = static function (string $path): string {
    $lines = explode("\n", (string) @file_get_contents($path));

    return implode("\n", array_filter(
        $lines,
        static fn (string $line): bool => !str_starts_with(ltrim($line), '#')
    ));
};

$publicHtaccess = $activeDirectives($backend . '/public/.htaccess');
$parentHtaccess = $activeDirectives($backend . '/.htaccess');

if (str_contains($publicHtaccess, 'RewriteBase')) {
    $errors[] = 'public/.htaccess sets RewriteBase. A hardcoded base breaks every route '
        . 'when the application is installed in a subdirectory. Remove it and let '
        . 'mod_rewrite resolve relative to the .htaccess location.';
}

if (str_contains($parentHtaccess, 'Require all denied')
    && !str_contains($publicHtaccess, 'Require all granted')) {
    $errors[] = 'backend/.htaccess denies everything but public/.htaccess does not grant '
        . 'itself back. Apache applies parent rules to child directories, so the API '
        . 'will return 403 for every request.';
}

if (!str_contains($parentHtaccess, 'Require all denied')) {
    $warnings[] = 'backend/.htaccess no longer denies access. If the document root is ever '
        . 'pointed at backend/ instead of backend/public, .env becomes downloadable.';
}

$requestSource = (string) @file_get_contents($backend . '/app/Core/Request.php');

if (!str_contains($requestSource, 'SCRIPT_NAME')) {
    $errors[] = 'Request::fromGlobals() no longer strips the mount prefix from the path. '
        . 'Routes will not match when the application is installed in a subdirectory.';
}

printf("12. Deployment rules (.htaccess, mount prefix) ... checked\n");

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
echo "\n";

foreach ($notes as $note) {
    echo "  NOTE     " . $note . "\n";
}

foreach ($warnings as $warning) {
    echo "  WARNING  " . $warning . "\n";
}

foreach ($errors as $error) {
    echo "  ERROR    " . $error . "\n";
}

printf(
    "\n%d error(s), %d warning(s), %d note(s).\n",
    count($errors),
    count($warnings),
    count($notes)
);

exit($errors === [] ? 0 : 1);
