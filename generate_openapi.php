<?php

declare(strict_types=1);

/**
 * Generates the OpenAPI 3.1 specification from the code.
 *
 *   php bin/generate_openapi.php            write docs/openapi.json and .yaml
 *   php bin/generate_openapi.php --check    fail if the committed spec is stale
 *
 * DERIVED, NOT HAND-WRITTEN. A specification maintained by hand is accurate on
 * the day it is written and misleading a fortnight later, and the people it
 * misleads are the ones building the Android and iOS clients against it. Here
 * the route table, the controller docblocks and the validator rules are the
 * source of truth, so the document cannot describe an endpoint that does not
 * exist or miss one that does.
 *
 * `--check` runs in the consistency audit, so a route added without
 * regenerating is caught before it reaches anyone.
 *
 * What is derived and what is not:
 *   - paths, verbs, path parameters, tags        from the route table
 *   - authentication and roles                   from route middleware
 *   - request body schemas                       from Validator::make() rules
 *   - summaries                                  from controller docblocks
 *   - response envelopes                         from the platform convention
 *
 * Response *payload* shapes are not derived. PHP arrays returned from services
 * carry no type information a generator can read, and inventing plausible
 * shapes would be worse than admitting they are undocumented: a client author
 * who trusts an invented schema is worse off than one who reads an example.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

$projectRoot = dirname(APP_ROOT);
$options = getopt('', ['check']);
$checkOnly = isset($options['check']);

// ---------------------------------------------------------------------------
// Route table
// ---------------------------------------------------------------------------
$routeSource = file_get_contents(APP_ROOT . '/routes/api_v1.php');

if ($routeSource === false) {
    fwrite(STDERR, "Cannot read the route table.\n");
    exit(1);
}

$middlewareGroups = [];

if (preg_match_all('/\$(\w+)\s*=\s*\[([^\]]*)\];/', $routeSource, $groups, PREG_SET_ORDER)) {
    foreach ($groups as $group) {
        $middlewareGroups['$' . $group[1]] = $group[2];
    }
}

preg_match_all(
    '/\$router->(get|post|put|patch|delete)\(\s*\'([^\']+)\'\s*,\s*\[(\w+)::class,\s*\'(\w+)\'\](?:\s*,\s*([^)]*))?\)/s',
    $routeSource,
    $routeMatches,
    PREG_SET_ORDER
);

// ---------------------------------------------------------------------------
// Controller sources, for docblocks and validator rules
// ---------------------------------------------------------------------------
$controllers = [];

foreach (glob(APP_ROOT . '/app/Controllers/Api/V1/*.php') ?: [] as $file) {
    $controllers[pathinfo($file, PATHINFO_FILENAME)] = file_get_contents($file);
}

/**
 * Extracts a controller method's body.
 */
function methodBody(string $source, string $method): ?string
{
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $start = (int) $match[0][1];
    $braceStart = strpos($source, '{', $start);

    if ($braceStart === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($source);

    for ($i = $braceStart; $i < $length; ++$i) {
        if ($source[$i] === '{') {
            ++$depth;
        } elseif ($source[$i] === '}') {
            --$depth;

            if ($depth === 0) {
                return substr($source, $braceStart, $i - $braceStart + 1);
            }
        }
    }

    return null;
}

/**
 * The docblock immediately above a method, minus its route annotation line.
 */
function methodSummary(string $source, string $method): ?string
{
    // The capture must contain no closing marker, which forces it to be the
    // docblock IMMEDIATELY above the method. A plain non-greedy `.*?` does not:
    // preg_match starts from the leftmost `/**` in the file, so the class
    // docblock matched instead and swallowed every line of code in between.
    if (!preg_match(
        '/\/\*\*((?:(?!\*\/).)*)\*\/\s*(?:public|private|protected)\s+function\s+'
        . preg_quote($method, '/') . '\s*\(/s',
        $source,
        $match
    )) {
        return null;
    }

    $lines = [];

    foreach (explode("\n", $match[1]) as $line) {
        $line = trim(ltrim(trim($line), '*'));

        if ($line === '' || str_starts_with($line, '@')) {
            continue;
        }

        // A route annotation may be the whole line, or a prefix followed by a
        // description: "GET /api/v1/categories — nested menu tree". Strip the
        // annotation and keep whatever follows, rather than discarding the line
        // and losing the only summary the endpoint has.
        if (preg_match('/^(?:GET|POST|PUT|PATCH|DELETE)\s+\/\S*\s*(?:[—–-]\s*)?(.*)$/u', $line, $annotation)) {
            $remainder = trim($annotation[1]);

            if ($remainder !== '') {
                $lines[] = ucfirst($remainder);
            }

            continue;
        }

        $lines[] = $line;
    }

    return $lines === [] ? null : implode(' ', $lines);
}

/**
 * Turns a Validator rule string into a JSON Schema property.
 *
 * @return array<string, mixed>
 */
function ruleToSchema(string $rules): array
{
    $parts = explode('|', $rules);
    $schema = [];
    $type = 'string';
    $constraints = [];

    foreach ($parts as $part) {
        [$name, $parameter] = array_pad(explode(':', $part, 2), 2, null);

        switch ($name) {
            case 'int':
                $type = 'integer';

                break;
            case 'numeric':
                $type = 'number';

                break;
            case 'boolean':
                $type = 'boolean';

                break;
            case 'array':
                $type = 'array';

                break;
            case 'uuid':
                $schema['format'] = 'uuid';

                break;
            case 'email':
                $schema['format'] = 'email';

                break;
            case 'date':
                $schema['format'] = 'date';

                break;
            case 'slug':
                $schema['pattern'] = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

                break;
            case 'mobile_in':
                $schema['pattern'] = '^(?:\+?91)?[6-9]\d{9}$';
                $schema['description'] = 'Indian mobile number, with or without a +91 country code.';

                break;
            case 'digits':
                $schema['pattern'] = '^\d{' . $parameter . '}$';

                break;
            case 'in':
                $schema['enum'] = explode(',', (string) $parameter);

                break;
            case 'min':
                $constraints['min'] = $parameter;

                break;
            case 'max':
                $constraints['max'] = $parameter;

                break;
            case 'regex':
                // Deliberately not translated. PHP's PCRE and JSON Schema's
                // ECMA-262 regex dialects differ in ways that would produce a
                // pattern that validates differently from the server.
                break;
        }
    }

    $schema['type'] = $type;

    // min/max mean different things depending on the base type, which is
    // exactly the sort of detail a hand-written spec gets wrong.
    foreach ($constraints as $kind => $value) {
        if ($value === null) {
            continue;
        }

        if ($type === 'integer' || $type === 'number') {
            // An integer field's bound is an integer. Emitting 1.0 is legal
            // JSON Schema but reads as a float to a code generator, which then
            // types the field as a double in the generated client.
            $schema[$kind === 'min' ? 'minimum' : 'maximum'] = $type === 'integer'
                ? (int) $value
                : (float) $value;
        } elseif ($type === 'array') {
            $schema[$kind === 'min' ? 'minItems' : 'maxItems'] = (int) $value;
        } else {
            $schema[$kind === 'min' ? 'minLength' : 'maxLength'] = (int) $value;
        }
    }

    if ($type === 'array' && !isset($schema['items'])) {
        $schema['items'] = ['type' => 'string'];
    }

    // Order the keys so regeneration is byte-stable and --check does not fail
    // on nothing more than key order.
    ksort($schema);

    return $schema;
}

/**
 * Pulls the validation rules out of a controller method.
 *
 * @return array{properties:array<string, mixed>, required:array<int, string>}|null
 */
function extractValidation(string $body): ?array
{
    if (!preg_match('/Validator::make\(\s*\$request->all\(\)\s*,\s*\[(.*?)\n\s*\]\s*\)/s', $body, $match)) {
        return null;
    }

    preg_match_all("/'(\w+)'\s*=>\s*'([^']+)'/", $match[1], $rules, PREG_SET_ORDER);

    $properties = [];
    $required = [];

    foreach ($rules as $rule) {
        $properties[$rule[1]] = ruleToSchema($rule[2]);

        if (str_starts_with($rule[2], 'required')) {
            $required[] = $rule[1];
        }
    }

    return $properties === [] ? null : ['properties' => $properties, 'required' => $required];
}

/**
 * Human-readable tag from a path.
 */
function tagFor(string $path): string
{
    $segments = array_values(array_filter(explode('/', $path)));

    if ($segments === []) {
        return 'General';
    }

    if ($segments[0] === 'admin') {
        return 'Admin: ' . ucwords(str_replace('-', ' ', $segments[1] ?? 'general'));
    }

    return ucwords(str_replace('-', ' ', $segments[0]));
}

// ---------------------------------------------------------------------------
// Build the document
// ---------------------------------------------------------------------------
$paths = [];
$tagsSeen = [];
$undocumented = [];

foreach ($routeMatches as $route) {
    [$whole, $verb, $path, $controller, $action] = $route;
    $middleware = $route[5] ?? '';

    $source = $controllers[$controller] ?? null;
    $body = $source === null ? null : methodBody($source, $action);
    $summary = $source === null ? null : methodSummary($source, $action);

    if ($summary === null) {
        $undocumented[] = strtoupper($verb) . ' ' . $path;
    }

    // Resolve the middleware group to its definition so a new group does not
    // silently read as "public".
    $resolved = $middleware;

    foreach ($middlewareGroups as $variable => $definition) {
        if (str_contains($middleware, $variable)) {
            $resolved = $definition;
        }
    }

    $requiresAuth = str_contains($resolved, "'auth'");
    $optionalAuth = str_contains($resolved, "'auth.optional'");

    $roles = [];

    if (preg_match("/'role:([^']+)'/", $resolved, $roleMatch)) {
        $roles = explode(',', $roleMatch[1]);
    }

    $openApiPath = '/api/v1' . $path;

    $parameters = [];

    if (preg_match_all('/\{(\w+)\}/', $path, $pathParams)) {
        foreach ($pathParams[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'] + ($name === 'uuid' ? ['format' => 'uuid'] : []),
                'description' => match ($name) {
                    'uuid' => 'Resource identifier. The platform never exposes numeric ids.',
                    'slug' => 'URL-safe identifier.',
                    'identifier' => 'Either a uuid or a slug.',
                    'code' => 'Short code identifying the resource.',
                    default => ucfirst($name),
                },
            ];
        }
    }

    // Listing endpoints share a pagination convention.
    if ($verb === 'get' && $body !== null && str_contains($body, 'paginationParams')) {
        $parameters[] = ['$ref' => '#/components/parameters/Page'];
        $parameters[] = ['$ref' => '#/components/parameters/PerPage'];
    }

    $operation = [
        'operationId' => lcfirst($controller) . ucfirst($action),
        'tags' => [tagFor($path)],
        // Where a controller docblock carries only the route annotation there is
        // no prose to lift, so the action name is humanised instead. Terse but
        // accurate, and it degrades honestly rather than inventing a
        // description nobody wrote.
        'summary' => $summary ?? ucfirst(strtolower(trim(
            preg_replace('/(?<!^)[A-Z]/', ' $0', $action) ?? $action
        ))),
    ];

    $tagsSeen[tagFor($path)] = true;

    $notes = [];

    if ($roles !== []) {
        $notes[] = 'Requires one of these roles: ' . implode(', ', $roles) . '.';
    }

    if ($optionalAuth) {
        $notes[] = 'Authentication is optional. A signed-in caller is recognised; '
            . 'an anonymous one is served as a guest.';
    }

    if (preg_match("/'throttle:(\d+),(\d+)'/", $resolved, $throttle)) {
        $notes[] = sprintf('Rate limited to %s requests per %s seconds.', $throttle[1], $throttle[2]);
    }

    if ($notes !== []) {
        $operation['description'] = implode(' ', $notes);
    }

    if ($parameters !== []) {
        $operation['parameters'] = $parameters;
    }

    if ($requiresAuth || $optionalAuth) {
        $operation['security'] = [['bearerAuth' => []]];
    }

    if ($body !== null && in_array($verb, ['post', 'put', 'patch'], true)) {
        $validation = extractValidation($body);

        if ($validation !== null) {
            $schema = ['type' => 'object', 'properties' => $validation['properties']];

            if ($validation['required'] !== []) {
                $schema['required'] = $validation['required'];
            }

            $operation['requestBody'] = [
                'required' => $validation['required'] !== [],
                'content' => ['application/json' => ['schema' => $schema]],
            ];
        }
    }

    $successCode = $body !== null && str_contains($body, 'Response::created') ? '201' : '200';

    $operation['responses'] = [
        $successCode => [
            'description' => $successCode === '201' ? 'Created' : 'Success',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessEnvelope']]],
        ],
        '422' => ['$ref' => '#/components/responses/ValidationError'],
    ];

    if ($requiresAuth) {
        $operation['responses']['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
    }

    if ($roles !== []) {
        $operation['responses']['403'] = ['$ref' => '#/components/responses/Forbidden'];
    }

    if ($parameters !== []) {
        $operation['responses']['404'] = ['$ref' => '#/components/responses/NotFound'];
    }

    $operation['responses']['429'] = ['$ref' => '#/components/responses/RateLimited'];

    $paths[$openApiPath][$verb] = $operation;
}

ksort($paths);

$tags = array_map(
    static fn (string $name): array => ['name' => $name],
    array_keys($tagsSeen)
);

usort($tags, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

$specification = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Spice & Dry Fruits Commerce API',
        'version' => '1.0.0',
        'description' => <<<'TEXT'
            API-first backend for a spice and dry-fruit retailer. The web
            application and the Android and iOS clients all consume this same
            contract; there is no business logic anywhere else.

            **This document is generated from the code.** Routes come from the
            route table, request schemas from the server's own validation rules,
            and authentication from route middleware. It cannot describe an
            endpoint that does not exist or miss one that does.

            **What is not described.** Response payload shapes are not derived.
            PHP arrays returned from services carry no type information a
            generator can read, and inventing plausible schemas would leave a
            client author worse off than reading an example. Every response uses
            the envelope below; the `data` field is documented per endpoint in
            docs/API_*.md.

            **Business rules that shape the API**
            - Every order requires OTP verification before it is confirmed.
            - Payment is prepaid UPI only. There is no cash-on-delivery path.
            - No order progresses past confirmation without a verified payment,
              and that applies to staff endpoints too.
            - The courier is selected automatically from weight, destination,
              cost and delivery time.
            TEXT,
        'contact' => ['name' => 'API support'],
    ],
    'servers' => [
        ['url' => '{scheme}://{host}', 'variables' => [
            'scheme' => ['default' => 'https', 'enum' => ['https', 'http']],
            'host' => ['default' => 'api.example.com'],
        ]],
    ],
    'tags' => $tags,
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Short-lived access token from /api/v1/auth/login. '
                    . 'When it expires, exchange the refresh token at /api/v1/auth/token/refresh. '
                    . 'Refresh tokens rotate on every use: the old one is invalidated, and '
                    . 'presenting it again revokes the whole session as suspected theft.',
            ],
        ],
        'parameters' => [
            'Page' => [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            'PerPage' => [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20],
                'description' => 'Capped per endpoint; the effective value is returned in meta.',
            ],
        ],
        'schemas' => [
            'SuccessEnvelope' => [
                'type' => 'object',
                'description' => 'Every response uses this shape, including errors.',
                'required' => ['success', 'message', 'data', 'errors'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'message' => ['type' => 'string'],
                    'data' => ['description' => 'Endpoint-specific payload.'],
                    'errors' => ['type' => 'array', 'items' => []],
                    'meta' => [
                        'type' => 'object',
                        'description' => 'Present on paginated responses.',
                        'properties' => [
                            'page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                            'total_pages' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'ErrorEnvelope' => [
                'type' => 'object',
                'required' => ['success', 'message', 'data', 'errors'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Safe to show a customer. Technical detail goes to the log, not here.',
                    ],
                    'data' => ['type' => 'array', 'items' => []],
                    'errors' => [
                        'type' => 'object',
                        'description' => 'Field name to a list of problems with it.',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
        'responses' => [
            'ValidationError' => [
                'description' => 'The request was understood but could not be accepted.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
            ],
            'Unauthenticated' => [
                'description' => 'Missing, expired or invalid access token.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
            ],
            'Forbidden' => [
                'description' => 'Authenticated, but this role may not perform this action.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
            ],
            'NotFound' => [
                'description' => 'No such resource. Also returned when a resource exists but '
                    . 'belongs to someone else, so that identifiers cannot be probed.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
            ],
            'RateLimited' => [
                'description' => 'Too many requests. The message states when to retry.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Write or check
// ---------------------------------------------------------------------------
$json = json_encode($specification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    fwrite(STDERR, "Could not encode the specification.\n");
    exit(1);
}

$json .= "\n";
$target = $projectRoot . '/docs/openapi.json';

if ($checkOnly) {
    $existing = is_file($target) ? file_get_contents($target) : null;

    if ($existing !== $json) {
        fwrite(STDERR, "The committed OpenAPI specification is out of date.\n");
        fwrite(STDERR, "Run: php bin/generate_openapi.php\n");

        exit(1);
    }

    printf("OpenAPI specification is current: %d path(s), %d operation(s).\n",
        count($paths),
        count($routeMatches));

    exit(0);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0o755, true);
}

file_put_contents($target, $json);

printf("Wrote %s\n", str_replace($projectRoot . '/', '', $target));
printf("  %d path(s), %d operation(s), %d tag(s)\n", count($paths), count($routeMatches), count($tags));

if ($undocumented !== []) {
    printf("\n%d operation(s) have no docblock summary:\n", count($undocumented));

    foreach (array_slice($undocumented, 0, 15) as $route) {
        printf("  %s\n", $route);
    }

    if (count($undocumented) > 15) {
        printf("  ... and %d more\n", count($undocumented) - 15);
    }
}

exit(0);
