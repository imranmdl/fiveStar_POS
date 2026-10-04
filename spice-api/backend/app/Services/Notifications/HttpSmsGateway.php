<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Logger;

/**
 * Generic HTTP SMS gateway driver.
 *
 * Most Indian transactional SMS providers (MSG91, Textlocal, Kaleyra, Gupshup)
 * expose a POST endpoint taking an API key, a sender/DLT template id and the
 * destination number. The endpoint and field names are configured rather than
 * hard-coded, so switching provider is a .env change.
 */
final class HttpSmsGateway implements SmsGatewayInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
    ) {
    }

    public function send(string $mobile, string $message, array $variables = []): array
    {
        $payload = [
            $this->config['field_map']['mobile'] => $this->config['country_code'] . $mobile,
            $this->config['field_map']['message'] => $message,
            $this->config['field_map']['sender'] => $this->config['sender_id'],
        ];

        if (($this->config['template_id'] ?? '') !== '') {
            $payload[$this->config['field_map']['template']] = $this->config['template_id'];
            $payload['variables'] = $variables;
        }

        $handle = curl_init($this->config['endpoint']);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                $this->config['auth_header'] . ': ' . $this->config['api_key'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config['timeout_seconds'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        $accepted = $body !== false && $status >= 200 && $status < 300;
        $decoded = is_string($body) ? json_decode($body, true) : null;

        $this->logger->info('Outbound SMS', [
            'mobile' => \App\Helpers\Str::maskMobile($mobile),
            'http_status' => $status,
            'accepted' => $accepted,
            'curl_error' => $error === '' ? null : $error,
        ], 'sms');

        return [
            'accepted' => $accepted,
            'provider_reference' => is_array($decoded)
                ? (string) ($decoded['message_id'] ?? $decoded['request_id'] ?? '')
                : null,
            'detail' => $accepted ? null : ($error !== '' ? $error : 'HTTP ' . $status),
        ];
    }
}
