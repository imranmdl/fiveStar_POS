<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * A delivery channel.
 *
 * Implementations must not throw for an ordinary delivery failure: they return
 * a failed result so the queue can record the reason and retry. Throwing is
 * reserved for misconfiguration, which retrying cannot fix.
 */
interface NotificationChannelInterface
{
    /** 'sms', 'email', 'whatsapp' or 'push'. */
    public function name(): string;

    /**
     * @param array<string, mixed> $message Rendered body, subject, variables
     *
     * @return array{success:bool, provider_message_id:?string, error:?string, raw:array<string, mixed>}
     */
    public function send(string $recipient, array $message): array;

    /** Whether a recipient value is usable on this channel. */
    public function supports(string $recipient): bool;
}
