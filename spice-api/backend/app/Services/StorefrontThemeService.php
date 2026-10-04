<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\SettingRepository;

/**
 * Storefront look-and-feel the administrator controls from the console:
 * header colours, accent, page background, announcement bar, banner rotation
 * speed and whether Deals of the Day shows. Stored as `storefront_*` rows in
 * `settings` (migration 050) and read publicly by the web and Android apps.
 */
final class StorefrontThemeService
{
    /** field => [setting key, type, default] */
    private const FIELDS = [
        'header_bg' => ['storefront_header_bg', 'color', '#c62d1f'],
        'header_text' => ['storefront_header_text', 'color', '#ffffff'],
        'accent' => ['storefront_accent', 'color', '#ffd23f'],
        'primary' => ['storefront_primary', 'color', '#c62d1f'],
        'page_bg' => ['storefront_page_bg', 'color', '#f1f0ee'],
        'announcement' => ['storefront_announcement', 'text', 'Dispatched within 24 hours · All prices include GST'],
        'announcement_bg' => ['storefront_announcement_bg', 'color', '#2a2829'],
        'announcement_text' => ['storefront_announcement_text', 'color', '#ffffff'],
        'tagline' => ['storefront_tagline', 'text', 'Spices & Dry Fruits'],
        'banner_seconds' => ['storefront_banner_seconds', 'int', '5'],
        'show_deals' => ['storefront_show_deals', 'bool', '1'],
    ];

    private const TEXT_LIMITS = ['announcement' => 160, 'tagline' => 60];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function theme(): array
    {
        $theme = [];

        foreach (self::FIELDS as $field => [$key, $type, $default]) {
            $value = $this->settings->value($key, $default) ?? $default;
            $theme[$field] = match ($type) {
                'int' => (int) $value,
                'bool' => in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
                'color' => self::isColor((string) $value) ? strtolower((string) $value) : $default,
                default => (string) $value,
            };
        }

        return $theme;
    }

    /**
     * @param array<string, mixed> $input Any subset of the theme fields.
     *
     * @return array<string, mixed> The full theme after the change.
     */
    public function update(array $input, Request $request): array
    {
        $errors = [];
        $changes = [];

        foreach ($input as $field => $value) {
            if (!array_key_exists($field, self::FIELDS)) {
                continue;
            }

            [, $type] = self::FIELDS[$field];

            switch ($type) {
                case 'color':
                    if (!is_string($value) || !self::isColor($value)) {
                        $errors[$field][] = 'Use a colour like #c62d1f.';
                        break;
                    }
                    $changes[$field] = strtolower($value);
                    break;

                case 'text':
                    $text = trim((string) $value);
                    if (mb_strlen($text) > self::TEXT_LIMITS[$field]) {
                        $errors[$field][] = 'Keep it under ' . self::TEXT_LIMITS[$field] . ' characters.';
                        break;
                    }
                    $changes[$field] = $text;
                    break;

                case 'int':
                    $seconds = filter_var($value, FILTER_VALIDATE_INT);
                    if ($seconds === false || $seconds < 3 || $seconds > 20) {
                        $errors[$field][] = 'Choose between 3 and 20 seconds.';
                        break;
                    }
                    $changes[$field] = (string) $seconds;
                    break;

                case 'bool':
                    $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($flag === null) {
                        $errors[$field][] = 'Must be true or false.';
                        break;
                    }
                    $changes[$field] = $flag ? '1' : '0';
                    break;
            }
        }

        if ($errors !== []) {
            throw new HttpException('Some storefront settings are not valid.', 422, $errors);
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $before = $this->theme();

        foreach ($changes as $field => $value) {
            $this->settings->put(self::FIELDS[$field][0], $value, $request->authUserId());
        }

        $after = $this->theme();

        $this->audit->log(
            entityName: 'settings',
            entityId: 0,
            action: 'update_storefront_theme',
            oldValues: array_intersect_key($before, $changes),
            newValues: array_intersect_key($after, $changes),
            request: $request,
        );

        return $after;
    }

    private static function isColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
