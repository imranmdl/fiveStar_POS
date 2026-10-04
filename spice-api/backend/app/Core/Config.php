<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dot-notation access to the files in /config.
 */
final class Config
{
    private array $items = [];

    public function __construct(private readonly string $configPath)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if (!array_key_exists($file, $this->items)) {
            $path = $this->configPath . '/' . $file . '.php';
            $this->items[$file] = is_file($path) ? require $path : [];
        }

        $value = $this->items[$file];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
