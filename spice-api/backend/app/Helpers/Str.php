<?php

declare(strict_types=1);

namespace App\Helpers;

final class Str
{
    private const REFERRAL_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Numeric OTP. Uses random_int so the value is not predictable from
     * previously issued codes.
     */
    public static function numericCode(int $length): string
    {
        $code = '';

        for ($i = 0; $i < $length; ++$i) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    /**
     * Referral codes avoid visually ambiguous characters (0/O, 1/I) because
     * customers read them aloud over the phone.
     */
    public static function referralCode(string $seed, int $length = 6): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $seed) ?: 'SPICE', 0, 3));
        $prefix = str_pad($prefix, 3, 'X');
        $random = '';
        $alphabetMax = strlen(self::REFERRAL_ALPHABET) - 1;

        for ($i = 0; $i < $length; ++$i) {
            $random .= self::REFERRAL_ALPHABET[random_int(0, $alphabetMax)];
        }

        return $prefix . $random;
    }

    /**
     * URL slug. Transliterates what it can, strips the rest, collapses
     * separators. Uniqueness is the caller's job (see ProductService).
     */
    public static function slug(string $value, int $maxLength = 160): string
    {
        $slug = $value;

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

            if ($converted !== false) {
                $slug = $converted;
            }
        }

        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, $maxLength);

        return trim($slug, '-') === '' ? 'item-' . bin2hex(random_bytes(4)) : trim($slug, '-');
    }

    public static function maskMobile(string $mobile): string
    {
        return strlen($mobile) <= 4
            ? $mobile
            : substr($mobile, 0, 2) . str_repeat('X', strlen($mobile) - 4) . substr($mobile, -2);
    }

    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, 1);

        return $visible . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
    }
}
