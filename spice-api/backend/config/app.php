<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', '5 Star Spices & Dry Fruits Commerce Platform'),
    'brand_name' => Env::get('APP_BRAND_NAME', '5 Star Spices & Dry Fruits'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'locale' => Env::get('APP_LOCALE', 'en_IN'),
    'currency' => Env::get('APP_CURRENCY', 'INR'),

    'cors' => [
        // Comma-separated exact origins. '*' is rejected in production so a
        // browser can never be tricked into sending credentials cross-origin.
        //
        // The Android and iOS apps (Capacitor) load the shop from inside the
        // app, so their requests come from https://localhost (Android) and
        // capacitor://localhost (iOS). Those are always allowed — without
        // them the app opens but every API call is blocked and nothing loads.
        // Auth is a Bearer token, not a cookie, so these origins cannot ride
        // on a logged-in browser session.
        'allowed_origins' => array_values(array_unique(array_filter(array_merge(
            ['https://localhost', 'capacitor://localhost'],
            array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', '')))
        )))),
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'X-Request-Id', 'X-Cart-Token'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'max_age' => 3600,
    ],
];
