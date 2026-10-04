<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'sms' => [
        // 'log' writes to storage/logs (development), 'http' calls the provider.
        'driver' => Env::get('SMS_DRIVER', 'log'),
        'endpoint' => Env::get('SMS_ENDPOINT', ''),
        'api_key' => Env::get('SMS_API_KEY', ''),
        'auth_header' => Env::get('SMS_AUTH_HEADER', 'Authorization'),
        'sender_id' => Env::get('SMS_SENDER_ID', 'SPICEC'),
        'template_id' => Env::get('SMS_DLT_TEMPLATE_ID', ''),
        'country_code' => Env::get('SMS_COUNTRY_CODE', '91'),
        'timeout_seconds' => Env::int('SMS_TIMEOUT_SECONDS', 10),
        'field_map' => [
            'mobile' => Env::get('SMS_FIELD_MOBILE', 'mobile'),
            'message' => Env::get('SMS_FIELD_MESSAGE', 'message'),
            'sender' => Env::get('SMS_FIELD_SENDER', 'sender'),
            'template' => Env::get('SMS_FIELD_TEMPLATE', 'template_id'),
        ],
    ],
];
