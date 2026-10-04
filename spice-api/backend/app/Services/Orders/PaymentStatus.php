<?php

declare(strict_types=1);

namespace App\Services\Orders;

/**
 * Payment states, kept separate from order status on purpose.
 *
 * An order that is `confirmed` and `paid` is one thing; an order that is
 * `confirmed` while payment is `refunded` is a different thing entirely, and
 * collapsing the two into a single field is how refund bugs hide.
 */
final class PaymentStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const LABELS = [
        self::PENDING => 'Payment pending',
        self::PROCESSING => 'Payment in progress',
        self::PAID => 'Paid',
        self::FAILED => 'Payment failed',
        self::REFUNDED => 'Refunded',
        self::PARTIALLY_REFUNDED => 'Partially refunded',
    ];

    /**
     * Payment states that satisfy BR-005.
     *
     * A partial refund still counts: goods were paid for and may legitimately be
     * mid-fulfilment when a partial refund is issued. A full refund does not.
     *
     * @var array<int, string>
     */
    public const SETTLED = [self::PAID, self::PARTIALLY_REFUNDED];

    public static function isSettled(string $status): bool
    {
        return in_array($status, self::SETTLED, true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function exists(string $status): bool
    {
        return array_key_exists($status, self::LABELS);
    }
}
