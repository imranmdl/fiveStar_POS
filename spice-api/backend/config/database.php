<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'spice_commerce'),
    'username' => Env::get('DB_USERNAME', 'spice_app'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'time_zone' => Env::get('DB_TIME_ZONE', '+05:30'),
];
