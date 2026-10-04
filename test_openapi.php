<?php

declare(strict_types=1);

/**
 * Structural validation of the generated OpenAPI specification.
 *
 *   php bin/test_openapi.php
 *
 * No database, no server, no network.
 *
 * A specification that parses but references a schema that does not exist, or
 * omits half the routes, is worse than none: client generators produce broken
 * code from it and nobody looks at the source to find out why.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        ++$passed;
        printf("  PASS  %s\n", $label);

        return;
    }

    ++$failed;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : ' -> ' . $detail);
}

function checkSame(string $label, mixed $expected, mixed $actual): void
{
    check($label, $expected === $actual, sprintf('expected %s, got %s',
        var_export($expected, true), var_export($actual, true)));
}

$specPath = dirname(APP_ROOT) . '/docs/openapi.json';

if (!is_file($specPath)) {
    fwrite(STDERR, "No specification found. Run: php bin/generate_openapi.php\n");
    exit(1);
}

$spec = json_decode((string) file_get_contents($specPath), true);

echo "Document structure\n";
echo "------------------\n";

check('the specification is valid JSON', is_array($spec));
checkSame('it declares OpenAPI 3.1.0', '3.1.0', $spec['openapi'] ?? null);
check('it has a title and version',
    ($spec['info']['title'] ?? '') !== '' && ($spec['info']['version'] ?? '') !== '');
check('it states that it is generated',
    str_contains((string) ($spec['info']['description'] ?? ''), 'generated from the code'),
    'a reader must know not to hand-edit it');
check('it declares a server', ($spec['servers'] ?? []) !== []);
check('it declares paths', count($spec['paths'] ?? []) > 100, (string) count($spec['paths'] ?? []));

echo "\nEvery route is present\n";
echo "----------------------\n";

$routeSource = (string) file_get_contents(APP_ROOT . '/routes/api_v1.php');

preg_match_all(
    '/\$router->(get|post|put|patch|delete)\(\s*\'([^\']+)\'/',
    $routeSource,
    $routes,
    PREG_SET_ORDER
);

$missing = [];
$operationCount = 0;

foreach ($routes as $route) {
    $path = '/api/v1' . $route[2];
    $verb = $route[1];

    if (!isset($spec['paths'][$path][$verb])) {
        $missing[] = strtoupper($verb) . ' ' . $path;
    }
}

foreach ($spec['paths'] as $operations) {
    $operationCount += count($operations);
}

checkSame('no route is missing from the specification', [], $missing);
checkSame('...and the operation count matches the route table', count($routes), $operationCount);

echo "\nOperations are well-formed\n";
echo "--------------------------\n";

$noSummary = [];
$noResponses = [];
$noOperationId = [];
$operationIds = [];
$duplicateIds = [];

foreach ($spec['paths'] as $path => $operations) {
    foreach ($operations as $verb => $operation) {
        $label = strtoupper($verb) . ' ' . $path;

        if (($operation['summary'] ?? '') === '') {
            $noSummary[] = $label;
        }

        if (($operation['responses'] ?? []) === []) {
            $noResponses[] = $label;
        }

        $id = $operation['operationId'] ?? '';

        if ($id === '') {
            $noOperationId[] = $label;
        } elseif (isset($operationIds[$id])) {
            $duplicateIds[] = $id;
        } else {
            $operationIds[$id] = true;
        }
    }
}

checkSame('every operation has a summary', [], $noSummary);
checkSame('every operation has responses', [], $noResponses);
checkSame('every operation has an operationId', [], $noOperationId);
checkSame('operationIds are unique', [], $duplicateIds,
    'client generators name methods after these, so a duplicate breaks the build');

echo "\nEvery reference resolves\n";
echo "------------------------\n";

/** Collects every $ref in the document. */
function collectRefs(mixed $node, array &$refs): void
{
    if (!is_array($node)) {
        return;
    }

    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;

            continue;
        }

        collectRefs($value, $refs);
    }
}

$refs = [];
collectRefs($spec, $refs);
$refs = array_unique($refs);

$broken = [];

foreach ($refs as $ref) {
    if (!str_starts_with($ref, '#/')) {
        $broken[] = $ref . ' (only local references are used)';

        continue;
    }

    $node = $spec;

    foreach (explode('/', substr($ref, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

        if (!is_array($node) || !array_key_exists($segment, $node)) {
            $broken[] = $ref;

            continue 2;
        }

        $node = $node[$segment];
    }
}

check('the document uses references', count($refs) > 0, (string) count($refs));
checkSame('every reference resolves', [], $broken);

echo "\nSecurity is declared where it applies\n";
echo "-------------------------------------\n";

// Cross-check a sample of routes against their middleware.
$authenticatedSamples = [
    '/api/v1/orders' => 'get',
    '/api/v1/checkout/place' => 'post',
    '/api/v1/wallet' => 'get',
];

$missingSecurity = [];

foreach ($authenticatedSamples as $path => $verb) {
    if (!isset($spec['paths'][$path][$verb])) {
        continue;
    }

    if (($spec['paths'][$path][$verb]['security'] ?? []) === []) {
        $missingSecurity[] = strtoupper($verb) . ' ' . $path;
    }
}

checkSame('authenticated endpoints declare security', [], $missingSecurity);

check('a public endpoint declares none',
    ($spec['paths']['/api/v1/health']['get']['security'] ?? null) === null);

check('the bearer scheme is defined',
    ($spec['components']['securitySchemes']['bearerAuth']['scheme'] ?? '') === 'bearer');
check('...and explains token rotation',
    str_contains((string) ($spec['components']['securitySchemes']['bearerAuth']['description'] ?? ''), 'rotate'),
    'the rotation rule is the thing most likely to catch a client out');

$roleDocumented = str_contains(
    (string) ($spec['paths']['/api/v1/admin/orders']['get']['description'] ?? ''),
    'roles'
);
check('role requirements are documented', $roleDocumented);

echo "\nRequest schemas are derived from the validators\n";
echo "-----------------------------------------------\n";

$place = $spec['paths']['/api/v1/checkout/place']['post']['requestBody']['content']['application/json']['schema'] ?? null;
check('order placement declares a request body', $place !== null);
check('...requiring the address', in_array('address_uuid', $place['required'] ?? [], true));
checkSame('...typed as a uuid', 'uuid', $place['properties']['address_uuid']['format'] ?? null);
checkSame('...with a numeric expected total', 'number', $place['properties']['expected_grand_total']['type'] ?? null);

$ship = $spec['paths']['/api/v1/admin/orders/{uuid}/ship']['post']['requestBody']['content']['application/json']['schema'] ?? null;
check('an enum rule becomes an enum',
    ($ship['properties']['strategy']['enum'] ?? []) === ['cheapest', 'fastest', 'balanced', 'reliable'],
    json_encode($ship['properties']['strategy'] ?? []));

$review = $spec['paths']['/api/v1/products/{identifier}/reviews']['post']['requestBody']['content']['application/json']['schema'] ?? null;
checkSame('an int rule becomes an integer', 'integer', $review['properties']['rating']['type'] ?? null);
checkSame('...with an integer minimum, not a float', 1, $review['properties']['rating']['minimum'] ?? null);
checkSame('...and an integer maximum', 5, $review['properties']['rating']['maximum'] ?? null);

$registration = $spec['paths']['/api/v1/auth/register']['post']['requestBody']['content']['application/json']['schema'] ?? null;
check('a string rule carries a length limit',
    isset($registration['properties']['full_name']['maxLength']),
    json_encode($registration['properties']['full_name'] ?? []));
check('a mobile rule carries a pattern',
    isset($registration['properties']['mobile']['pattern']));

echo "\nPath parameters are declared\n";
echo "----------------------------\n";

$undeclared = [];

foreach ($spec['paths'] as $path => $operations) {
    preg_match_all('/\{(\w+)\}/', $path, $inPath);

    foreach ($operations as $verb => $operation) {
        $declared = [];

        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (($parameter['in'] ?? '') === 'path') {
                $declared[] = $parameter['name'];
            }
        }

        foreach ($inPath[1] as $name) {
            if (!in_array($name, $declared, true)) {
                $undeclared[] = strtoupper($verb) . ' ' . $path . ' :' . $name;
            }
        }
    }
}

checkSame('every path parameter is declared', [], $undeclared,
    'an undeclared path parameter makes generated clients uncompilable');

echo "\nTags\n";
echo "----\n";

$declaredTags = array_column($spec['tags'] ?? [], 'name');
$usedTags = [];

foreach ($spec['paths'] as $operations) {
    foreach ($operations as $operation) {
        foreach ($operation['tags'] ?? [] as $tag) {
            $usedTags[$tag] = true;
        }
    }
}

checkSame('every tag used is declared', [], array_values(array_diff(array_keys($usedTags), $declaredTags)));
check('tags group the API sensibly', count($declaredTags) >= 10, (string) count($declaredTags));

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
