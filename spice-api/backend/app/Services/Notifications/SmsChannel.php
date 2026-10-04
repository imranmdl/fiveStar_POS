<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * SMS, delegating to the gateway configured since Phase 1.
 *
 * A thin adapter on purpose: OtpService already sends through
 * SmsGatewayInterface, and giving notifications a second, parallel way to send
 * an SMS would mean two sets of credentials, two sender IDs and two places to
 * change when the provider is swapped.
 */
final class SmsChannel implements NotificationChannelInterface
{
    public function __construct(private readonly SmsGatewayInterface $gateway)
    {
    }

    public function name(): string
    {
        return 'sms';
    }

    public function supports(string $recipient): bool
    {
        $digits = preg_replace('/\D/', '', $recipient) ?? '';

        return preg_match('/^[6-9]\d{9}$/', $digits) === 1
            || preg_match('/^91[6-9]\d{9}$/', $digits) === 1;
    }

    public function send(string $recipient, array $message): array
    {
        try {
            $result = $this->gateway->send(
                $recipient,
                (string) $message['body'],
                is_array($message['variables'] ?? null) ? $message['variables'] : []
            );

            return [
                'success' => (bool) ($result['success'] ?? true),
                'provider_message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
                'raw' => $result,
            ];
        } catch (\Throwable $exception) {
            // Returned, not thrown: a gateway timeout is a retry, not a crash.
            return [
                'success' => false,
                'provider_message_id' => null,
                'error' => $exception->getMessage(),
                'raw' => [],
            ];
        }
    }
}
