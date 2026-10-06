<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * AI writing help in the admin console (product descriptions).
 *
 * Uses the Claude API. Without ANTHROPIC_API_KEY the "Write with AI" button
 * still works but fills a plain template from the product's own details.
 */
return [
    'anthropic' => [
        'api_key' => Env::get('ANTHROPIC_API_KEY', ''),
        'model' => Env::get('AI_MODEL', 'claude-haiku-4-5-20251001'),
        // Only a local test stack may point at a stand-in server.
        'base_url' => Env::get('APP_ENV', 'production') === 'local'
            ? Env::get('AI_BASE_URL', 'https://api.anthropic.com')
            : 'https://api.anthropic.com',
        'timeout_seconds' => Env::int('AI_TIMEOUT_SECONDS', 30),
        'max_tokens' => Env::int('AI_MAX_TOKENS', 900),
    ],
];
