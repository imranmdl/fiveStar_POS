<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface SmsGatewayInterface
{
    /**
     * @param array<string, string> $variables Template placeholders.
     *
     * @return array{accepted:bool, provider_reference:?string, detail:?string}
     */
    public function send(string $mobile, string $message, array $variables = []): array;
}
