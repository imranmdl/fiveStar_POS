<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Logger;

/**
 * Writes messages to the log instead of sending them.
 *
 * The default in local and testing environments, and what makes the whole
 * notification path exercisable without a gateway account. It reports success,
 * so queue transitions, retries and dedupe all behave exactly as they would in
 * production.
 */
final class LogChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly Logger $logger,
        private readonly string $channelName,
    ) {
    }

    public function name(): string
    {
        return $this->channelName;
    }

    public function supports(string $recipient): bool
    {
        return trim($recipient) !== '';
    }

    public function send(string $recipient, array $message): array
    {
        $this->logger->info('Notification (log channel)', [
            'channel' => $this->channelName,
            'recipient' => $recipient,
            'subject' => $message['subject'] ?? null,
            'body' => $message['body'] ?? '',
        ], 'notifications');

        return [
            'success' => true,
            'provider_message_id' => 'log_' . bin2hex(random_bytes(6)),
            'error' => null,
            'raw' => ['channel' => $this->channelName, 'logged' => true],
        ];
    }
}
