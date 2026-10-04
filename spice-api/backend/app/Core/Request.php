<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers,
        private readonly array $query,
        private readonly array $body,
        public readonly array $files,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $requestId,
        // The exact bytes received. Webhook signatures are HMACs over the raw
        // body, and json_encode(json_decode($body)) is not byte-identical to it —
        // key order, whitespace and escaping all differ — so verification would
        // fail for every genuine webhook if it were rebuilt from the array.
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;

        if ($method === 'POST' && $override !== null) {
            $method = strtoupper($override);
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');

        // STRIP THE SUBDIRECTORY THE APPLICATION IS MOUNTED UNDER.
        //
        // At the document root, REQUEST_URI is already the route: /api/v1/health.
        // Installed under htdocs/spice-api/public — which is what XAMPP and most
        // shared hosting encourage — it arrives as
        // /spice-api/public/api/v1/health, and the route table has no such entry,
        // so every endpoint 404s.
        //
        // SCRIPT_NAME is the path to index.php as the server resolved it, so its
        // directory is exactly the prefix to remove. Derived rather than
        // configured: a base path that has to be set by hand is one more thing
        // to get wrong on deployment, and it is wrong silently.
        $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));

        if ($scriptDirectory !== '' && $scriptDirectory !== '/' && $scriptDirectory !== '.') {
            $prefix = '/' . trim($scriptDirectory, '/');

            if (str_starts_with($path, $prefix)) {
                $path = '/' . trim(substr($path, strlen($prefix)), '/');
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = $_POST;
        $contentType = $headers['content-type'] ?? '';
        $raw = '';

        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');

            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                $body = is_array($decoded) ? $decoded : [];
            }
        } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'], true) && $body === []) {
            $raw = (string) file_get_contents('php://input');
            parse_str($raw, $parsed);
            $body = $parsed;
        }

        return new self(
            method: $method,
            path: $path,
            headers: $headers,
            query: $_GET,
            body: $body,
            files: $_FILES,
            ip: self::resolveIp(),
            userAgent: substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            requestId: bin2hex(random_bytes(8)),
            rawBody: $raw,
        );
    }

    private static function resolveIp(): string
    {
        // Only trusted proxy headers should be honoured; the load balancer is
        // expected to overwrite X-Forwarded-For rather than append to it.
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $first = trim(explode(',', (string) $candidate)[0]);

            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return '0.0.0.0';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('authorization');

        if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Whether the key was present in the request at all.
     *
     * Distinct from input() returning null: a PATCH that explicitly clears a
     * field sends null, and one that leaves it alone sends nothing. Treating
     * those the same wipes fields the caller never mentioned.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function routeParams(): array
    {
        return $this->attributes['route_params'] ?? [];
    }

    public function routeParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams()[$key] ?? $default;
    }

    public function authUserId(): ?int
    {
        $user = $this->attribute('auth_user');

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * The authenticated caller's role code, or null for an anonymous request.
     *
     * The role already sits in the auth context; exposing it here keeps callers
     * from reaching into the array themselves, which is how the key ends up
     * mistyped at one of three call sites and silently returns null.
     */
    public function authRole(): ?string
    {
        $user = $this->attribute('auth_user');

        return $user === null ? null : (string) ($user['role_code'] ?? '');
    }
}
