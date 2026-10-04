<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Decides whether a message may be sent, and if not now, when.
 *
 * Pure, and separated from everything else because these are legal rules rather
 * than product preferences. Under TRAI regulation a promotional message sent to
 * a DND-registered Indian number is an offence, and promotional messages may
 * not be delivered between 9pm and 9am. Transactional messages — an OTP, a
 * payment receipt, a dispatch notice — are exempt from both, which is exactly
 * why the two categories cannot be collapsed into one idea of "notification".
 *
 * Getting this wrong in either direction is costly: spamming people illegally
 * on one side, withholding delivery updates customers actually want on the
 * other.
 */
final class NotificationPolicy
{
    public const CATEGORY_TRANSACTIONAL = 'transactional';
    public const CATEGORY_PROMOTIONAL = 'promotional';

    public const ALLOW = 'allow';
    public const DEFER = 'defer';
    public const SUPPRESS = 'suppress';

    /**
     * @param bool $optedOut Customer has opted out of this channel
     * @param bool $onDndRegister Number is on the national Do Not Disturb list
     *
     * @return array{decision:string, reason:?string, send_after:?string}
     */
    public function evaluate(
        string $category,
        bool $channelEnabled,
        bool $optedOut,
        bool $onDndRegister,
        string $quietStart,
        string $quietEnd,
        ?int $now = null,
    ): array {
        $now ??= time();

        if (!$channelEnabled) {
            return [
                'decision' => self::SUPPRESS,
                'reason' => 'This channel is switched off.',
                'send_after' => null,
            ];
        }

        // Transactional messages bypass opt-out, DND and quiet hours. A customer
        // cannot unsubscribe from their own OTP, and withholding a dispatch
        // notice at 10pm helps nobody.
        if ($category === self::CATEGORY_TRANSACTIONAL) {
            return ['decision' => self::ALLOW, 'reason' => null, 'send_after' => null];
        }

        if ($onDndRegister) {
            return [
                'decision' => self::SUPPRESS,
                'reason' => 'The number is on the Do Not Disturb register, so promotional messages are not permitted.',
                'send_after' => null,
            ];
        }

        if ($optedOut) {
            return [
                'decision' => self::SUPPRESS,
                'reason' => 'The customer has opted out of promotional messages on this channel.',
                'send_after' => null,
            ];
        }

        if ($this->isQuietHour($now, $quietStart, $quietEnd)) {
            // Deferred rather than dropped. A promotional message queued at 11pm
            // is still worth sending at 9am; discarding it silently loses the
            // campaign and leaves nobody able to explain why.
            return [
                'decision' => self::DEFER,
                'reason' => sprintf('Promotional messages are not sent between %s and %s.', $quietStart, $quietEnd),
                'send_after' => $this->nextSendableTime($now, $quietEnd),
            ];
        }

        return ['decision' => self::ALLOW, 'reason' => null, 'send_after' => null];
    }

    /**
     * Whether a moment falls inside the quiet window.
     *
     * The window normally wraps midnight (21:00 to 09:00), so a naive
     * "between start and end" comparison is wrong for every hour of it.
     */
    public function isQuietHour(int $timestamp, string $quietStart, string $quietEnd): bool
    {
        $minutes = ((int) date('H', $timestamp) * 60) + (int) date('i', $timestamp);
        $start = $this->toMinutes($quietStart);
        $end = $this->toMinutes($quietEnd);

        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            // A same-day window, e.g. 01:00 to 06:00.
            return $minutes >= $start && $minutes < $end;
        }

        // Wrapping midnight: quiet if after the start OR before the end.
        return $minutes >= $start || $minutes < $end;
    }

    /** The next moment a promotional message may go out. */
    public function nextSendableTime(int $timestamp, string $quietEnd): string
    {
        $end = $this->toMinutes($quietEnd);
        $minutes = ((int) date('H', $timestamp) * 60) + (int) date('i', $timestamp);

        $target = strtotime(date('Y-m-d', $timestamp) . ' ' . sprintf('%02d:%02d', intdiv($end, 60), $end % 60));

        if ($target === false) {
            return date('Y-m-d H:i:s', $timestamp + 3600);
        }

        // Already past this morning's opening: wait for tomorrow's.
        if ($minutes >= $end) {
            $target += 86400;
        }

        return date('Y-m-d H:i:s', $target);
    }

    /**
     * Renders a template body, and refuses to send one with gaps left in it.
     *
     * A message that reaches a customer reading "Order {{order_number}} has
     * shipped" is worse than no message at all: it looks broken and it tells
     * them nothing.
     *
     * @param array<string, mixed> $variables
     * @param array<int, string>   $required
     *
     * @return array{body:string, missing:array<int, string>}
     */
    public function render(string $template, array $variables, array $required = []): array
    {
        $missing = [];

        foreach ($required as $name) {
            if (!array_key_exists($name, $variables)
                || $variables[$name] === null
                || $variables[$name] === '') {
                $missing[] = $name;
            }
        }

        $body = $template;

        foreach ($variables as $name => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $body = str_replace('{{' . $name . '}}', (string) $value, $body);
        }

        // Anything still unreplaced is a gap the caller did not declare.
        if (preg_match_all('/\{\{(\w+)\}\}/', $body, $leftovers)) {
            foreach ($leftovers[1] as $name) {
                if (!in_array($name, $missing, true)) {
                    $missing[] = $name;
                }
            }
        }

        return ['body' => $body, 'missing' => $missing];
    }

    /**
     * Segment count for an SMS.
     *
     * Indian gateways bill per segment, and a template that creeps to 161
     * characters doubles the cost of every message the business sends. Unicode
     * content (any Indic script) drops the segment to 70 characters, which is a
     * nasty surprise the first time a customer name is transliterated.
     */
    public function smsSegments(string $body): int
    {
        $isUnicode = preg_match('/[^\x00-\x7F]/', $body) === 1;
        $length = mb_strlen($body);

        if ($isUnicode) {
            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':', trim($time));

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
