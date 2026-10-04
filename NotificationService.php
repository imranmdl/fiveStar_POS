<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Uuid;
use App\Repositories\SettingRepository;
use App\Services\Notifications\NotificationChannelInterface;
use App\Services\Notifications\NotificationPolicy;

/**
 * Queueing and dispatching customer messages.
 *
 * NOTHING IS SENT INLINE. `queue()` writes a row and returns; a worker sends it
 * later. That separation is the whole point: an SMS gateway taking four seconds
 * must not add four seconds to a customer's checkout, and a gateway that is
 * down must not fail an order that has already been paid for. The customer's
 * money is the thing that matters; the message can wait thirty seconds.
 *
 * Queueing is idempotent through a mandatory dedupe key. A retried payment
 * webhook, a double-clicked button and a re-run scheduler all try to announce
 * the same event, and the customer should hear about it once.
 */
final class NotificationService
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels;

    /** @param array<int, NotificationChannelInterface> $channels */
    public function __construct(
        array $channels,
        private readonly NotificationPolicy $policy,
        private readonly SettingRepository $settings,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
        $this->channels = [];

        foreach ($channels as $channel) {
            $this->channels[$channel->name()] = $channel;
        }
    }

    /**
     * Queues a message.
     *
     * Never throws for an ordinary problem — a missing template or an opted-out
     * customer is recorded and skipped. A notification failing must not roll
     * back the business event that triggered it.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $options   user_id, recipient, reference_type, reference_id, dedupe_key
     *
     * @return array{queued:bool, reason:?string, uuid:?string}
     */
    public function queue(string $templateCode, string $channel, array $variables, array $options = []): array
    {
        if (!$this->settings->boolValue('notifications_enabled', true)) {
            return ['queued' => false, 'reason' => 'Notifications are switched off.', 'uuid' => null];
        }

        $template = $this->db->selectOne(
            'SELECT * FROM `notification_templates`
              WHERE `code` = :code AND `channel` = :channel
                AND `is_active` = 1 AND `is_deleted` = 0
              LIMIT 1',
            ['code' => $templateCode, 'channel' => $channel]
        );

        if ($template === null) {
            // Logged loudly: a missing template is a configuration gap, and the
            // customer silently hears nothing until someone notices.
            $this->logger->warning('No notification template found', [
                'template_code' => $templateCode,
                'channel' => $channel,
            ], 'notifications');

            return ['queued' => false, 'reason' => 'No active template for this code and channel.', 'uuid' => null];
        }

        $userId = $options['user_id'] ?? null;
        $recipient = (string) ($options['recipient'] ?? '');

        if ($recipient === '' && $userId !== null) {
            $recipient = $this->resolveRecipient((int) $userId, $channel);
        }

        if ($recipient === '') {
            return ['queued' => false, 'reason' => 'No address for this channel.', 'uuid' => null];
        }

        $driver = $this->channels[$channel] ?? null;

        if ($driver !== null && !$driver->supports($recipient)) {
            return ['queued' => false, 'reason' => 'The address is not valid for this channel.', 'uuid' => null];
        }

        $required = is_string($template['required_variables'])
            ? (json_decode($template['required_variables'], true) ?? [])
            : [];

        $rendered = $this->policy->render((string) $template['body'], $variables, $required);

        if ($rendered['missing'] !== []) {
            // Refused rather than sent with gaps. A message reading "Order
            // {{order_number}} has shipped" looks broken and says nothing.
            $this->logger->error('Notification template variables missing', [
                'template_code' => $templateCode,
                'missing' => $rendered['missing'],
            ], 'notifications');

            return [
                'queued' => false,
                'reason' => 'Missing variables: ' . implode(', ', $rendered['missing']),
                'uuid' => null,
            ];
        }

        $category = (string) $template['category'];
        $decision = $this->policy->evaluate(
            category: $category,
            channelEnabled: true,
            optedOut: $userId !== null && $this->hasOptedOut((int) $userId, $channel),
            onDndRegister: $this->isOnDndRegister($recipient),
            quietStart: (string) ($this->settings->value('promotional_quiet_start') ?? '21:00'),
            quietEnd: (string) ($this->settings->value('promotional_quiet_end') ?? '09:00'),
        );

        $subject = $template['subject'] === null
            ? null
            : $this->policy->render((string) $template['subject'], $variables)['body'];

        $dedupeKey = (string) ($options['dedupe_key']
            ?? sprintf('%s:%s:%s:%s', $templateCode, $channel, $recipient, date('Y-m-d H:i')));

        try {
            $id = $this->db->insert(
                'INSERT INTO `notification_queue`
                     (`uuid`, `template_id`, `template_code`, `channel`, `category`, `user_id`,
                      `recipient`, `subject`, `body`, `variables`, `status`, `scheduled_for`,
                      `suppression_reason`, `reference_type`, `reference_id`, `dedupe_key`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :template_id, :template_code, :channel, :category, :user_id,
                      :recipient, :subject, :body, :variables, :status, :scheduled_for,
                      :suppression_reason, :reference_type, :reference_id, :dedupe_key,
                      :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'template_id' => (int) $template['id'],
                    'template_code' => $templateCode,
                    'channel' => $channel,
                    'category' => $category,
                    'user_id' => $userId,
                    'recipient' => $recipient,
                    'subject' => $subject,
                    'body' => $rendered['body'],
                    'variables' => json_encode($variables),
                    'status' => $decision['decision'] === NotificationPolicy::SUPPRESS ? 'suppressed' : 'pending',
                    'scheduled_for' => $decision['send_after'] ?? date('Y-m-d H:i:s'),
                    'suppression_reason' => $decision['decision'] === NotificationPolicy::SUPPRESS
                        ? $decision['reason']
                        : null,
                    'reference_type' => $options['reference_type'] ?? null,
                    'reference_id' => $options['reference_id'] ?? null,
                    'dedupe_key' => $dedupeKey,
                    'created_by' => $options['actor_id'] ?? null,
                ]
            );
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                // Already queued for this event. Exactly what the key is for.
                return ['queued' => false, 'reason' => 'Already queued.', 'uuid' => null];
            }

            throw $exception;
        }

        $row = $this->db->selectOne('SELECT `uuid` FROM `notification_queue` WHERE `id` = :id', ['id' => $id]);

        return [
            'queued' => $decision['decision'] !== NotificationPolicy::SUPPRESS,
            'reason' => $decision['reason'],
            'uuid' => (string) ($row['uuid'] ?? ''),
        ];
    }

    /**
     * Sends what is due. Called by the scheduler.
     *
     * @return array<string, mixed>
     */
    public function dispatchQueue(?int $limit = null): array
    {
        $limit ??= max(1, $this->settings->intValue('notification_batch_size', 50));
        $retryMinutes = max(1, $this->settings->intValue('notification_retry_minutes', 15));

        $due = $this->db->select(
            sprintf(
                "SELECT * FROM `notification_queue`
                  WHERE `status` = 'pending'
                    AND `scheduled_for` <= NOW()
                    AND `attempts` < `max_attempts`
                    AND `is_deleted` = 0
                  ORDER BY `scheduled_for` ASC
                  LIMIT %d",
                min($limit, 500)
            )
        );

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($due as $message) {
            // Claim the row first. Two workers running at once would otherwise
            // both send it, and the customer gets the message twice.
            $claimed = $this->db->execute(
                "UPDATE `notification_queue`
                    SET `status` = 'sending', `attempts` = `attempts` + 1,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id AND `status` = 'pending'",
                ['id' => (int) $message['id']]
            );

            if ($claimed === 0) {
                ++$skipped;

                continue;
            }

            $driver = $this->channels[$message['channel']] ?? null;

            if ($driver === null) {
                $this->markFailed(
                    (int) $message['id'],
                    'No driver configured for channel ' . $message['channel'],
                    $retryMinutes,
                    (int) $message['attempts'] + 1,
                    (int) $message['max_attempts']
                );
                ++$failed;

                continue;
            }

            $result = $driver->send((string) $message['recipient'], [
                'body' => $message['body'],
                'subject' => $message['subject'],
                'variables' => json_decode((string) $message['variables'], true) ?? [],
            ]);

            if ($result['success']) {
                $this->db->execute(
                    "UPDATE `notification_queue`
                        SET `status` = 'sent', `sent_date` = NOW(),
                            `provider_message_id` = :provider_id, `provider_response` = :response,
                            `last_error` = NULL, `updated_date` = NOW(), `version` = `version` + 1
                      WHERE `id` = :id",
                    [
                        'provider_id' => $result['provider_message_id'],
                        'response' => json_encode($result['raw']),
                        'id' => (int) $message['id'],
                    ]
                );

                ++$sent;

                continue;
            }

            $this->markFailed(
                (int) $message['id'],
                (string) ($result['error'] ?? 'Unknown delivery error'),
                $retryMinutes,
                (int) $message['attempts'] + 1,
                (int) $message['max_attempts']
            );
            ++$failed;
        }

        return [
            'considered' => count($due),
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Customer's own preferences.
     *
     * @return array<string, mixed>
     */
    public function preferencesFor(Request $request): array
    {
        $userId = (int) $request->authUserId();

        $rows = $this->db->select(
            'SELECT `channel`, `is_enabled` FROM `notification_preferences`
              WHERE `user_id` = :user_id AND `is_deleted` = 0',
            ['user_id' => $userId]
        );

        $preferences = [];

        foreach (['sms', 'email', 'whatsapp', 'push'] as $channel) {
            $preferences[$channel] = true;
        }

        foreach ($rows as $row) {
            $preferences[$row['channel']] = (bool) $row['is_enabled'];
        }

        return [
            'promotional' => $preferences,
            // Stated plainly, because customers ask and support has to answer.
            'note' => 'These settings apply to offers and reminders only. '
                . 'Order confirmations, payment receipts, dispatch notices and OTPs are always sent.',
        ];
    }

    /**
     * @param array<string, bool> $channels
     *
     * @return array<string, mixed>
     */
    public function updatePreferences(Request $request, array $channels): array
    {
        $userId = (int) $request->authUserId();

        foreach ($channels as $channel => $enabled) {
            if (!in_array($channel, ['sms', 'email', 'whatsapp', 'push'], true)) {
                continue;
            }

            $this->db->execute(
                'INSERT INTO `notification_preferences`
                     (`uuid`, `user_id`, `channel`, `is_enabled`, `opted_out_date`, `opt_out_source`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :user_id, :channel, :enabled, :opted_out, :source,
                         :created_by, NOW(), 1, 0, 1)
                 ON DUPLICATE KEY UPDATE
                     `is_enabled` = VALUES(`is_enabled`),
                     `opted_out_date` = VALUES(`opted_out_date`),
                     `opt_out_source` = VALUES(`opt_out_source`),
                     `updated_date` = NOW(),
                     `version` = `notification_preferences`.`version` + 1',
                [
                    'uuid' => Uuid::v4(),
                    'user_id' => $userId,
                    'channel' => $channel,
                    'enabled' => $enabled ? 1 : 0,
                    'opted_out' => $enabled ? null : date('Y-m-d H:i:s'),
                    'source' => 'settings',
                    'created_by' => $userId,
                ]
            );
        }

        return $this->preferencesFor($request);
    }

    /** @return array<int, array<string, mixed>> */
    public function historyFor(Request $request, int $limit = 50): array
    {
        return $this->db->select(
            sprintf(
                'SELECT `template_code`, `channel`, `category`, `subject`, `status`,
                        `sent_date`, `created_date`
                   FROM `notification_queue`
                  WHERE `user_id` = :user_id AND `is_deleted` = 0
                  ORDER BY `created_date` DESC LIMIT %d',
                max(1, min($limit, 200))
            ),
            ['user_id' => (int) $request->authUserId()]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function health(): array
    {
        return $this->db->select('SELECT * FROM `vw_notification_health` ORDER BY `channel`, `category`');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function markFailed(int $id, string $error, int $retryMinutes, int $attempts, int $maxAttempts): void
    {
        $exhausted = $attempts >= $maxAttempts;

        $this->db->execute(
            sprintf(
                "UPDATE `notification_queue`
                    SET `status` = %s,
                        `last_error` = :error,
                        `failed_date` = %s,
                        `scheduled_for` = DATE_ADD(NOW(), INTERVAL :retry MINUTE),
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id",
                $exhausted ? "'failed'" : "'pending'",
                $exhausted ? 'NOW()' : '`failed_date`'
            ),
            ['error' => substr($error, 0, 500), 'retry' => $retryMinutes, 'id' => $id]
        );

        if ($exhausted) {
            $this->logger->error('Notification gave up after repeated failures', [
                'queue_id' => $id,
                'attempts' => $attempts,
                'error' => $error,
            ], 'notifications');
        }
    }

    private function resolveRecipient(int $userId, string $channel): string
    {
        $user = $this->db->selectOne(
            'SELECT `mobile`, `email` FROM `users` WHERE `id` = :id LIMIT 1',
            ['id' => $userId]
        );

        if ($user === null) {
            return '';
        }

        return match ($channel) {
            'email' => (string) ($user['email'] ?? ''),
            default => (string) ($user['mobile'] ?? ''),
        };
    }

    private function hasOptedOut(int $userId, string $channel): bool
    {
        $row = $this->db->selectOne(
            'SELECT `is_enabled` FROM `notification_preferences`
              WHERE `user_id` = :user_id AND `channel` = :channel AND `is_deleted` = 0
              LIMIT 1',
            ['user_id' => $userId, 'channel' => $channel]
        );

        return $row !== null && (int) $row['is_enabled'] === 0;
    }

    /**
     * Whether a number is on the national Do Not Disturb register.
     *
     * A stub returning false, and deliberately marked as one. Real DND status
     * comes from the SMS provider's DLT platform at send time — providers reject
     * promotional messages to registered numbers themselves. This hook exists so
     * that a merchant maintaining their own suppression list has one place to
     * put it, rather than scattering the check through the queueing code.
     */
    private function isOnDndRegister(string $recipient): bool
    {
        return false;
    }
}
