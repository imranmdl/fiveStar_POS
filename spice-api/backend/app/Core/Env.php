<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env reader. Values are cached in a static array; the process
 * environment is never mutated, so nothing leaks into shell_exec children.
 *
 * A key missing from .env falls back to the real process environment. On
 * shared hosting the .env file is the only source and nothing changes; on a
 * container platform (Railway, Docker) there is no .env at all and every
 * setting arrives as an environment variable. A value present in .env always
 * wins, so an existing server behaves exactly as before.
 */
final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) > 1 && (
                ($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $fromProcess = getenv($key);

        if ($fromProcess !== false) {
            return $fromProcess;
        }

        return $_ENV[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }

        return $value;
    }
}
