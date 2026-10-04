<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingRepository extends BaseRepository
{
    /** @var array<string, string|null> */
    private array $cache = [];

    protected function table(): string
    {
        return 'settings';
    }

    protected function fillable(): array
    {
        return ['group_code', 'setting_key', 'setting_value', 'data_type', 'description', 'is_public'];
    }

    public function value(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?? $default;
        }

        $value = $this->db->scalar(
            'SELECT setting_value FROM settings WHERE setting_key = :key AND is_deleted = 0 LIMIT 1',
            ['key' => $key]
        );

        $this->cache[$key] = $value === null ? null : (string) $value;

        return $this->cache[$key] ?? $default;
    }

    public function intValue(string $key, int $default): int
    {
        $value = $this->value($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function boolValue(string $key, bool $default): bool
    {
        $value = $this->value($key);

        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function put(string $key, string $value, ?int $actorId = null): void
    {
        $existing = $this->findOneBy('setting_key', $key);

        if ($existing === null) {
            $this->create([
                'group_code' => 'general',
                'setting_key' => $key,
                'setting_value' => $value,
                'data_type' => 'string',
                'is_public' => 0,
            ], $actorId);
        } else {
            $this->update((int) $existing['id'], ['setting_value' => $value], $actorId);
        }

        unset($this->cache[$key]);
    }

    /** @return array<int, array<string, mixed>> */
    public function publicSettings(): array
    {
        return $this->db->select(
            'SELECT setting_key, setting_value, data_type
               FROM settings
              WHERE is_public = 1 AND is_deleted = 0 AND is_active = 1
              ORDER BY group_code, setting_key'
        );
    }
}
